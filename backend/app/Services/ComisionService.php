<?php

namespace App\Services;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\Comision;
use App\Models\ComisionDefault;
use App\Models\Grupo;
use App\Models\JuegoLimite;
use App\Models\Log;
use App\Models\Taquilla;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lectores de tasa de comisión (S2, D2/D11).
 *
 * - tasaPropia: fila propia del nivel sobre juego_limites.porcentaje_pago;
 *   NULL/ausente = 0.
 * - tasaEfectiva: cascada taquilla > grupo > banca > default global de la
 *   moneda; una fila con porcentaje_pago NULL cede al siguiente nivel;
 *   sin definir en la cadena Y sin default global ⇒ 0.00.
 * - tasaLiquidable: tasa efectiva topada por el tope acumulado (D11):
 *   `min(tasaEfectiva, max(0, 100 − Σ tasas propias de ancestros))`,
 *   calculada por (entidad, moneda). Banca ⇒ sin ancestros con tasa
 *   (liquidable = su tasa); Grupo ⇒ Σ{banca}; taquilla ⇒ Σ{grupo, banca}.
 *   Banca, Grupo y Taquilla cobran su propia comisión (suma cero: la suma
 *   de las tasas liquidables de la cadena ≤ 100% de las ventas).
 *
 * El path de venta (createApuesta/validarMonedaYLimites/getEffectiveLimit)
 * NO se toca: este servicio es de solo lectura sobre las mismas tablas.
 */
class ComisionService
{
    /**
     * Tasa propia de un nivel: su fila configurada directamente.
     * NULL o ausente ⇒ 0.0.
     */
    public function tasaPropia(string $nivel, int $entidadId, int $juegoId, string $moneda): float
    {
        $query = JuegoLimite::where('juego_id', $juegoId)
            ->where('moneda', $moneda);

        if ($nivel === 'taquilla') {
            $query->where('taquilla_id', $entidadId);
        } elseif ($nivel === 'grupo') {
            $query->where('grupo_id', $entidadId)->whereNull('taquilla_id');
        } else {
            $query->where('banca_id', $entidadId)->whereNull('grupo_id')->whereNull('taquilla_id');
        }

        $fila = $query->first();

        return $fila && $fila->porcentaje_pago !== null ? (float) $fila->porcentaje_pago : 0.0;
    }

    /**
     * Tasa efectiva por cascada: taquilla > grupo > banca > default global.
     * Fila con porcentaje_pago NULL cede al siguiente nivel (D2).
     */
    public function tasaEfectiva(string $nivel, int $entidadId, int $juegoId, string $moneda): float
    {
        [$bancaId, $grupoId, $taquillaId] = $this->cadena($nivel, $entidadId);

        if ($bancaId === null) {
            return $this->defaultGlobal($moneda);
        }

        $filas = JuegoLimite::where('juego_id', $juegoId)
            ->where('moneda', $moneda)
            ->where(function ($q) use ($bancaId, $grupoId, $taquillaId) {
                $primera = true;

                if ($taquillaId !== null) {
                    $q->where('taquilla_id', $taquillaId);
                    $primera = false;
                }

                if ($grupoId !== null) {
                    $grupo = fn ($q2) => $q2->whereNull('taquilla_id')->where('grupo_id', $grupoId);
                    $primera ? $q->where($grupo) : $q->orWhere($grupo);
                    $primera = false;
                }

                $banca = fn ($q2) => $q2->whereNull('taquilla_id')->whereNull('grupo_id')->where('banca_id', $bancaId);
                $primera ? $q->where($banca) : $q->orWhere($banca);
            })
            ->orderByRaw('taquilla_id IS NOT NULL DESC, grupo_id IS NOT NULL DESC')
            ->get();

        foreach ($filas as $fila) {
            if ($fila->porcentaje_pago !== null) {
                return (float) $fila->porcentaje_pago;
            }
        }

        return $this->defaultGlobal($moneda);
    }

    /**
     * Tasa liquidable: tasa efectiva topada por el tope acumulado (D11).
     * Σ ancestros = tasas PROPIAS de los ancestros (NULL/ausente = 0),
     * piso 0; por moneda.
     */
    public function tasaLiquidable(string $nivel, int $entidadId, int $juegoId, string $moneda): float
    {
        $efectiva = $this->tasaEfectiva($nivel, $entidadId, $juegoId, $moneda);
        $sumaAncestros = $this->sumaTasasPropiasAncestros($nivel, $entidadId, $juegoId, $moneda);

        return min($efectiva, max(0.0, 100.0 - $sumaAncestros));
    }

    /**
     * Suma de tasas propias de los ancestros del nivel.
     * Grupo ⇒ {banca}; taquilla ⇒ {grupo, banca}; banca ⇒ sin ancestros.
     */
    private function sumaTasasPropiasAncestros(string $nivel, int $entidadId, int $juegoId, string $moneda): float
    {
        if ($nivel === 'grupo') {
            $grupo = Grupo::find($entidadId);

            return $grupo ? $this->tasaPropia('banca', $grupo->banca_id, $juegoId, $moneda) : 0.0;
        }

        if ($nivel === 'taquilla') {
            $taquilla = Taquilla::with('grupo')->find($entidadId);

            if (! $taquilla || ! $taquilla->grupo) {
                return 0.0;
            }

            return $this->tasaPropia('grupo', $taquilla->grupo_id, $juegoId, $moneda)
                + $this->tasaPropia('banca', $taquilla->grupo->banca_id, $juegoId, $moneda);
        }

        return 0.0;
    }

    /**
     * Resolver la cadena (banca, grupo, taquilla) del nivel consultado.
     *
     * @return array{0: int|null, 1: int|null, 2: int|null}
     */
    private function cadena(string $nivel, int $entidadId): array
    {
        if ($nivel === 'banca') {
            return [$entidadId, null, null];
        }

        if ($nivel === 'grupo') {
            $grupo = Grupo::find($entidadId);

            return [$grupo?->banca_id, $entidadId, null];
        }

        $taquilla = Taquilla::with('grupo')->find($entidadId);

        return [
            $taquilla?->grupo?->banca_id,
            $taquilla?->grupo_id,
            $entidadId,
        ];
    }

    /**
     * Default global de la moneda (D1); NULL/ausente ⇒ 0.0.
     */
    private function defaultGlobal(string $moneda): float
    {
        $default = ComisionDefault::where('moneda', $moneda)->first();

        return $default && $default->porcentaje_pago !== null ? (float) $default->porcentaje_pago : 0.0;
    }

    // ==================================================
    // S3 — Cálculo por rango (D6) y previsualización (D7)
    // ==================================================

    /**
     * Comisión de una entidad en el rango [desde, hasta] inclusive.
     *
     * D6: cada bucket de moneda multiplica por `tasaLiquidable` de esa
     * moneda (bs→amount_bs; usd→amount_usd×exchange_rate_applied; mixto
     * participa en ambos); `moneda = null` evalúa bs y usd de forma
     * independiente; `juegoId = null` agrega todos los juegos (cada uno con
     * su propia tasa). Round único a 2 decimales. Solo ventas no anuladas.
     */
    public function comisionEntidad(
        string $nivel,
        int $entidadId,
        Carbon $desde,
        Carbon $hasta,
        ?int $juegoId = null,
        ?string $moneda = null
    ): float {
        $montos = $this->comisionesReporte($nivel, [$entidadId], $desde, $hasta, $juegoId, $moneda);

        return $montos[$entidadId] ?? 0.0;
    }

    /**
     * Comisión por entidad (bulk, sin N+1 por entidad).
     *
     * @return array<int, float> keyed por entidad_id
     */
    public function comisionesReporte(
        string $nivel,
        array $entidadIds,
        Carbon $desde,
        Carbon $hasta,
        ?int $juegoId = null,
        ?string $moneda = null
    ): array {
        if ($entidadIds === []) {
            return [];
        }

        $buckets = $this->bucketsPorEntidad($nivel, $entidadIds, $desde, $hasta, $juegoId);

        if ($buckets->isEmpty()) {
            return [];
        }

        $tasas = $this->tasasLiquidablesBulk($nivel, $entidadIds, $buckets->pluck('juego_id')->unique());

        return $this->aplicarTasas($buckets, $tasas, $moneda);
    }

    /**
     * Buckets de venta por (juego, entidad) en el rango inclusive.
     *
     * - bs: SUM(amount_bs) — ventas bs-only y mixtas.
     * - usd_equiv: SUM(amount_usd × exchange_rate_applied) — usd-only y mixtas.
     *
     * Alcance de entidad: taquilla → sus ventas; grupo → ventas de sus
     * taquillas (join taquillas); banca → ventas de sus grupos (joins).
     *
     * @return Collection<int, object> filas con juego_id, entidad_id, bs, usd_equiv
     */
    private function bucketsPorEntidad(
        string $nivel,
        array $entidadIds,
        Carbon $desde,
        Carbon $hasta,
        ?int $juegoId = null
    ): Collection {
        $desde = $desde->copy()->startOfDay();
        $hasta = $hasta->copy()->endOfDay();

        $query = Apuesta::query()
            ->where('apuestas.estado', '!=', 'anulada')
            ->where('apuestas.fecha_hora', '>=', $desde)
            ->where('apuestas.fecha_hora', '<=', $hasta);

        $columna = match ($nivel) {
            'taquilla' => 'apuestas.taquilla_id',
            'grupo' => 'taquillas.grupo_id',
            'banca' => 'grupos.banca_id',
            default => throw new \InvalidArgumentException("Nivel inválido: {$nivel}"),
        };

        if ($nivel === 'grupo') {
            $query->join('taquillas', 'apuestas.taquilla_id', '=', 'taquillas.id');
        } elseif ($nivel === 'banca') {
            $query->join('taquillas', 'apuestas.taquilla_id', '=', 'taquillas.id')
                ->join('grupos', 'taquillas.grupo_id', '=', 'grupos.id');
        }

        $query->whereIn($columna, $entidadIds);

        if ($juegoId !== null) {
            $query->where('apuestas.juego_id', $juegoId);
        }

        return $query
            ->groupBy('apuestas.juego_id', $columna)
            ->selectRaw(
                'apuestas.juego_id as juego_id, '
                .$columna.' as entidad_id, '
                .'SUM(apuestas.amount_bs) as bs, '
                .'SUM(apuestas.amount_usd * apuestas.exchange_rate_applied) as usd_equiv'
            )
            ->get();
    }

    /**
     * Multiplicar buckets por tasas liquidables y redondear una sola vez.
     *
     * @param  array<int, array<int, array<string, float>>>  $tasas  [entidad][juego][moneda]
     * @return array<int, float>
     */
    private function aplicarTasas(Collection $buckets, array $tasas, ?string $moneda): array
    {
        $montos = [];

        foreach ($buckets as $fila) {
            $entidadId = (int) $fila->entidad_id;
            $juegoId = (int) $fila->juego_id;
            $bs = (float) $fila->bs;
            $usd = (float) $fila->usd_equiv;

            $comision = 0.0;

            if ($moneda === null || $moneda === 'bs') {
                $comision += $bs * (($tasas[$entidadId][$juegoId]['bs'] ?? 0.0) / 100.0);
            }

            if ($moneda === null || $moneda === 'usd') {
                $comision += $usd * (($tasas[$entidadId][$juegoId]['usd'] ?? 0.0) / 100.0);
            }

            $montos[$entidadId] = ($montos[$entidadId] ?? 0.0) + $comision;
        }

        foreach ($montos as $entidadId => $monto) {
            $montos[$entidadId] = round($monto, 2);
        }

        return $montos;
    }

    /**
     * Tasas liquidables en bulk (D11), sin N+1 por entidad.
     *
     * Carga UNA vez los defaults globales, las cadenas (banca, grupo,
     * taquilla) de las entidades y las filas de juego_limites de los juegos
     * en alcance; luego resuelve tasa efectiva y tope acumulado en memoria
     * para cada (entidad, juego, moneda).
     *
     * @param  Collection<int, int>|array<int, int>  $juegoIds
     * @return array<int, array<int, array<string, float>>>
     */
    private function tasasLiquidablesBulk(string $nivel, array $entidadIds, $juegoIds): array
    {
        $juegoIds = $juegoIds instanceof Collection ? $juegoIds : collect($juegoIds);

        if ($juegoIds->isEmpty()) {
            return [];
        }

        $ctx = [
            'defaults' => ComisionDefault::whereIn('moneda', ['bs', 'usd'])
                ->pluck('porcentaje_pago', 'moneda')
                ->all(),
            'taquilla' => [],
            'grupo' => [],
            'banca' => [],
        ];

        $cadenas = $this->cadenasEnBulk($nivel, $entidadIds);

        foreach (JuegoLimite::whereIn('juego_id', $juegoIds)->get() as $limite) {
            $moneda = $limite->moneda;
            $juegoId = (int) $limite->juego_id;

            if ($limite->taquilla_id !== null) {
                $ctx['taquilla'][$juegoId][$moneda][(int) $limite->taquilla_id] = $limite->porcentaje_pago;
            } elseif ($limite->grupo_id !== null) {
                $ctx['grupo'][$juegoId][$moneda][(int) $limite->grupo_id] = $limite->porcentaje_pago;
            } else {
                $ctx['banca'][$juegoId][$moneda][(int) $limite->banca_id] = $limite->porcentaje_pago;
            }
        }

        $tasas = [];

        foreach ($cadenas as $entidadId => $cadena) {
            foreach ($juegoIds as $juegoId) {
                foreach (['bs', 'usd'] as $moneda) {
                    $efectiva = $this->efectivaEnMemoria($ctx, (int) $juegoId, $moneda, $cadena);
                    $sumaAncestros = $this->sumaAncestrosEnMemoria($ctx, $nivel, (int) $juegoId, $moneda, $cadena);

                    $tasas[$entidadId][(int) $juegoId][$moneda] = min($efectiva, max(0.0, 100.0 - $sumaAncestros));
                }
            }
        }

        return $tasas;
    }

    /**
     * Cadenas (banca, grupo, taquilla) de las entidades, en bulk.
     *
     * @return array<int, array{0: int|null, 1: int|null, 2: int|null}>
     */
    private function cadenasEnBulk(string $nivel, array $entidadIds): array
    {
        $cadenas = [];

        if ($nivel === 'taquilla') {
            foreach (Taquilla::with('grupo')->whereIn('id', $entidadIds)->get() as $taquilla) {
                $cadenas[$taquilla->id] = [
                    $taquilla->grupo?->banca_id,
                    $taquilla->grupo_id,
                    $taquilla->id,
                ];
            }
        } elseif ($nivel === 'grupo') {
            foreach (Grupo::whereIn('id', $entidadIds)->get() as $grupo) {
                $cadenas[$grupo->id] = [$grupo->banca_id, $grupo->id, null];
            }
        } else {
            foreach ($entidadIds as $entidadId) {
                $cadenas[$entidadId] = [(int) $entidadId, null, null];
            }
        }

        return $cadenas;
    }

    /**
     * Tasa efectiva en memoria (espejo de tasaEfectiva, D2), sobre el
     * contexto bulk cargado: taquilla > grupo > banca > default global.
     *
     * @param  array{0: int|null, 1: int|null, 2: int|null}  $cadena
     */
    private function efectivaEnMemoria(array $ctx, int $juegoId, string $moneda, array $cadena): float
    {
        [$bancaId, $grupoId, $taquillaId] = $cadena;

        if ($taquillaId !== null) {
            $valor = $ctx['taquilla'][$juegoId][$moneda][$taquillaId] ?? null;

            if ($valor !== null) {
                return (float) $valor;
            }
        }

        if ($grupoId !== null) {
            $valor = $ctx['grupo'][$juegoId][$moneda][$grupoId] ?? null;

            if ($valor !== null) {
                return (float) $valor;
            }
        }

        if ($bancaId !== null) {
            $valor = $ctx['banca'][$juegoId][$moneda][$bancaId] ?? null;

            if ($valor !== null) {
                return (float) $valor;
            }
        }

        $default = $ctx['defaults'][$moneda] ?? null;

        return $default === null ? 0.0 : (float) $default;
    }

    /**
     * Suma de tasas propias de los ancestros en memoria (D11).
     * Grupo ⇒ {banca}; taquilla ⇒ {grupo, banca}.
     *
     * @param  array{0: int|null, 1: int|null, 2: int|null}  $cadena
     */
    private function sumaAncestrosEnMemoria(array $ctx, string $nivel, int $juegoId, string $moneda, array $cadena): float
    {
        [$bancaId, $grupoId] = $cadena;

        if ($nivel === 'grupo') {
            return $this->propiaEnMemoria($ctx, $juegoId, $moneda, 'banca', $bancaId);
        }

        if ($nivel === 'taquilla') {
            return $this->propiaEnMemoria($ctx, $juegoId, $moneda, 'grupo', $grupoId)
                + $this->propiaEnMemoria($ctx, $juegoId, $moneda, 'banca', $bancaId);
        }

        return 0.0;
    }

    /**
     * Tasa propia de un nivel en memoria (espejo de tasaPropia, D2).
     */
    private function propiaEnMemoria(array $ctx, int $juegoId, string $moneda, string $nivel, ?int $entidadId): float
    {
        if ($entidadId === null) {
            return 0.0;
        }

        $mapa = match ($nivel) {
            'taquilla' => $ctx['taquilla'][$juegoId][$moneda] ?? [],
            'grupo' => $ctx['grupo'][$juegoId][$moneda] ?? [],
            'banca' => $ctx['banca'][$juegoId][$moneda] ?? [],
        };

        $valor = $mapa[$entidadId] ?? null;

        return $valor === null ? 0.0 : (float) $valor;
    }

    /**
     * Previsualización de liquidación (D7): filas candidatas (banca +
     * grupo + taquilla; sin filas para entidades con base 0) y conflictos
     * (entidades con filas `comisiones` cuyo rango se solapa con
     * [desde, hasta]).
     *
     * @return array{rows: array<int, array<string, mixed>>, conflictos: array<int, array<string, int>>}
     */
    public function previsualizar(Carbon $desde, Carbon $hasta, ?array $bancaIds = null): array
    {
        $desde = $desde->copy()->startOfDay();
        $hasta = $hasta->copy()->endOfDay();

        $filas = $this->filasLiquidables($desde, $hasta, $bancaIds);

        return [
            'rows' => $filas['rows'],
            'conflictos' => $this->conflictosPorRango(
                $filas['bancaIds'],
                $filas['grupoIds'],
                $filas['taquillaIds'],
                $desde,
                $hasta
            ),
        ];
    }

    /**
     * Liquidar comisiones del rango en el ledger (D4/D7).
     *
     * Escribe UNA fila por (nivel, entidad, rango) para Banca, Grupo y
     * Taquilla con base > 0 (todos los niveles cobran su propia comisión);
     * `estado` pendiente; rango persistido en `fecha_inicio`/`fecha_fin` y
     * etiqueta `periodo` `YYYY-MM-DD..YYYY-MM-DD`. Si alguna entidad en
     * alcance ya tiene filas con rango solapado (`fecha_inicio <= hasta AND
     * fecha_fin >= desde`), rechaza TODO el lote y devuelve los ids en
     * conflicto (el controlador responde 422); la re-liquidación idéntica
     * también es solapamiento (sin doble conteo). Todo dentro de
     * `DB::transaction` y el query de solapamiento usa `lockForUpdate`
     * para serializar liquidaciones concurrentes del mismo rango.
     *
     * @return array{rows: array<int, Comision>, conflictos: array<int, array<string, int>>}
     */
    public function liquidar(Carbon $desde, Carbon $hasta, ?array $bancaIds, int $userId): array
    {
        $desde = $desde->copy()->startOfDay();
        $hasta = $hasta->copy()->endOfDay();

        return DB::transaction(function () use ($desde, $hasta, $bancaIds, $userId) {
            $filas = $this->filasLiquidables($desde, $hasta, $bancaIds);

            $conflictos = $this->conflictosPorRango(
                $filas['bancaIds'],
                $filas['grupoIds'],
                $filas['taquillaIds'],
                $desde,
                $hasta,
                lock: true
            );

            if ($conflictos !== []) {
                return ['rows' => [], 'conflictos' => $conflictos];
            }

            $creadas = [];

            foreach ($filas['rows'] as $fila) {
                $data = [
                    'periodo' => $desde->toDateString().'..'.$hasta->toDateString(),
                    'monto_comision' => $fila['monto_comision'],
                    'estado' => 'pendiente',
                    'fecha_inicio' => $desde->toDateString(),
                    'fecha_fin' => $hasta->toDateString(),
                ];

                if ($fila['nivel'] === 'banca') {
                    $data['banca_id'] = $fila['entidad_id'];
                } elseif ($fila['nivel'] === 'grupo') {
                    $data['grupo_id'] = $fila['entidad_id'];
                } else {
                    $data['taquilla_id'] = $fila['entidad_id'];
                }

                $creadas[] = Comision::create($data);
            }

            // Auditoría de la liquidación (mismo patrón Log::create del resto
            // de escrituras; el usuario llega por contrato D7).
            Log::create([
                'user_id' => $userId,
                'action' => 'comision_liquidar',
                'details' => json_encode([
                    'desde' => $desde->toDateString(),
                    'hasta' => $hasta->toDateString(),
                    'filas' => count($creadas),
                    'total' => array_sum(array_map(fn ($c) => (float) $c->monto_comision, $creadas)),
                ]),
            ]);

            return ['rows' => $creadas, 'conflictos' => []];
        });
    }

    /**
     * Filas candidatas de liquidación (D7) + ids de las entidades en
     * alcance (para el query de solapamiento).
     *
     * Entidades con base de ventas > 0; Banca, Grupo y Taquilla generan
     * fila (todos los niveles cobran su propia comisión).
     * `desde`/`hasta` deben venir normalizados (startOfDay/endOfDay).
     *
     * @return array{rows: array<int, array<string, mixed>>, bancaIds: array<int, int>, grupoIds: array<int, int>, taquillaIds: array<int, int>}
     */
    private function filasLiquidables(Carbon $desde, Carbon $hasta, ?array $bancaIds): array
    {
        $bancaQuery = Banca::query();
        $grupoQuery = Grupo::query();
        $taquillaQuery = Taquilla::query();

        if ($bancaIds !== null) {
            $gruposDeBancas = Grupo::whereIn('banca_id', $bancaIds)->pluck('id');
            $bancaQuery->whereIn('id', $bancaIds);
            $grupoQuery->whereIn('banca_id', $bancaIds);
            $taquillaQuery->whereIn('grupo_id', $gruposDeBancas);
        }

        $entidadesPorNivel = [
            'banca' => $bancaQuery->get(),
            'grupo' => $grupoQuery->get(),
            'taquilla' => $taquillaQuery->get(),
        ];

        $bancaIdsEnAlcance = $entidadesPorNivel['banca']->pluck('id')->all();
        $grupoIds = $entidadesPorNivel['grupo']->pluck('id')->all();
        $taquillaIds = $entidadesPorNivel['taquilla']->pluck('id')->all();

        $rows = [];

        foreach ($entidadesPorNivel as $nivel => $entidades) {
            if ($entidades->isEmpty()) {
                continue;
            }

            $entidadIds = $entidades->pluck('id')->all();
            $buckets = $this->bucketsPorEntidad($nivel, $entidadIds, $desde, $hasta);

            if ($buckets->isEmpty()) {
                continue;
            }

            $tasas = $this->tasasLiquidablesBulk($nivel, $entidadIds, $buckets->pluck('juego_id')->unique());
            $montos = $this->aplicarTasas($buckets, $tasas, null);

            $bases = [];

            foreach ($buckets as $fila) {
                $bases[(int) $fila->entidad_id] = ($bases[(int) $fila->entidad_id] ?? 0.0)
                    + (float) $fila->bs
                    + (float) $fila->usd_equiv;
            }

            foreach ($entidades as $entidad) {
                if (($bases[$entidad->id] ?? 0.0) <= 0.0) {
                    continue;
                }

                $rows[] = [
                    'nivel' => $nivel,
                    'entidad_id' => $entidad->id,
                    'entidad' => $entidad->name,
                    'monto_comision' => round($montos[$entidad->id] ?? 0.0, 2),
                ];
            }
        }

        return [
            'rows' => $rows,
            'bancaIds' => $bancaIdsEnAlcance,
            'grupoIds' => $grupoIds,
            'taquillaIds' => $taquillaIds,
        ];
    }

    /**
     * Entidades con filas `comisiones` cuyo rango se solapa con [desde, hasta].
     *
     * Con `$lock = true` (liquidar) la consulta usa `lockForUpdate` para
     * serializar liquidaciones concurrentes: las lecturas con bloqueo ven
     * el último estado confirmado, así una segunda liquidación del mismo
     * rango detecta las filas recién insertadas por la primera (D4).
     *
     * @return array<int, array<string, int>>
     */
    private function conflictosPorRango(array $bancaIds, array $grupoIds, array $taquillaIds, Carbon $desde, Carbon $hasta, bool $lock = false): array
    {
        $conflictos = [];

        if ($bancaIds === [] && $grupoIds === [] && $taquillaIds === []) {
            return $conflictos;
        }

        $query = Comision::where('fecha_inicio', '<=', $hasta->toDateString())
            ->where('fecha_fin', '>=', $desde->toDateString())
            ->where(function ($q) use ($bancaIds, $grupoIds, $taquillaIds) {
                $primera = true;

                foreach ([['banca_id', $bancaIds], ['grupo_id', $grupoIds], ['taquilla_id', $taquillaIds]] as [$columna, $ids]) {
                    if ($ids === []) {
                        continue;
                    }

                    $primera ? $q->whereIn($columna, $ids) : $q->orWhereIn($columna, $ids);
                    $primera = false;
                }
            });

        if ($lock) {
            $query->lockForUpdate();
        }

        $solapadas = $query->get(['banca_id', 'grupo_id', 'taquilla_id']);

        foreach ($solapadas as $comision) {
            if ($comision->banca_id !== null) {
                $conflictos[] = ['nivel' => 'banca', 'entidad_id' => $comision->banca_id];
            }

            if ($comision->grupo_id !== null) {
                $conflictos[] = ['nivel' => 'grupo', 'entidad_id' => $comision->grupo_id];
            }

            if ($comision->taquilla_id !== null) {
                $conflictos[] = ['nivel' => 'taquilla', 'entidad_id' => $comision->taquilla_id];
            }
        }

        return array_values(array_unique($conflictos, SORT_REGULAR));
    }
}
