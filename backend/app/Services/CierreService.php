<?php

namespace App\Services;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\CierreCaja;
use App\Models\ExchangeRate;
use App\Models\Pago;
use App\Models\Taquilla;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CierreService
{
    /**
     * Ejecutar el cierre de caja de una taquilla (máquina).
     *
     * El período abarca [fecha_inicio, fecha_fin):
     *  - fecha_fin = now()
     *  - fecha_inicio = fecha_fin del último cierre de la taquilla, o la
     *    fecha_hora de su primera apuesta, o now() si no tiene actividad.
     *
     * Ventas: apuestas con estado != 'anulada' por fecha_hora.
     * Egresos: pagos tipo 'egreso' y 'devolucion' por created_at.
     * Efectivo por moneda = ventas - egresos.
     *
     * Arqueo opcional: si se indica el efectivo contado, se persiste y se
     * calcula la diferencia por moneda (faltante_sobrante_X = arqueo_X -
     * total_efectivo_X); si se omite, arqueo y diferencia quedan nulos.
     *
     * Un cierre por día calendario (AD-2/AD-3): si ya existe el cierre de hoy
     * (fecha_fin ∈ [startOfDay, +1d) en America/Caracas), el llamado es un
     * RE-CIERRE: exige clave_cierre validada contra la cadena de la taquilla
     * y actualiza la misma fila (extiende fecha_fin a now(), recalcula desde
     * el fecha_inicio ORIGINAL, refresca arqueo/desglose y registra
     * reclosed_by/reclosed_at; fecha_inicio y created_by quedan intactos).
     * La clave nunca se persiste ni se expone.
     *
     * @return array{cierre: CierreCaja, reclosed: bool} la fila y si fue un
     *                                                   re-cierre (true) o la creación del día (false)
     *
     * @throws \RuntimeException si no existe tasa, falta la clave en un
     *                           re-cierre o la clave no valida la cadena
     */
    public function crearCierre(int $taquillaId, int $userId, ?float $arqueoBs = null, ?float $arqueoUsd = null, ?string $clave = null): array
    {
        return DB::transaction(function () use ($taquillaId, $userId, $arqueoBs, $arqueoUsd, $clave) {
            $tasa = $this->resolverTasa();
            $fechaFin = now();

            $cierreHoy = $this->resolveCierreHoy($taquillaId);

            // Re-cierre del día (AD-3): actualiza la fila dentro de la misma
            // transacción; la clave se valida ANTES de tocar la fila.
            if ($cierreHoy) {
                if ($clave === null || $clave === '') {
                    throw new \RuntimeException('La clave de cierre es obligatoria para re-cerrar el día.');
                }

                $this->validarClaveCierre($taquillaId, $clave);

                $totales = $this->calcularTotales($taquillaId, $cierreHoy->fecha_inicio, $fechaFin);

                $cierreHoy->update($this->atributosTotales($totales, $tasa, $arqueoBs, $arqueoUsd) + [
                    'fecha_fin' => $fechaFin,
                    'reclosed_by' => $userId,
                    'reclosed_at' => $fechaFin,
                ]);

                return ['cierre' => $cierreHoy->refresh(), 'reclosed' => true];
            }

            // Primer cierre del día: crea la fila
            $fechaInicio = $this->resolveFechaInicio($taquillaId, $fechaFin);
            $totales = $this->calcularTotales($taquillaId, $fechaInicio, $fechaFin);

            $cierre = CierreCaja::create($this->atributosTotales($totales, $tasa, $arqueoBs, $arqueoUsd) + [
                'taquilla_id' => $taquillaId,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'created_by' => $userId,
            ]);

            return ['cierre' => $cierre, 'reclosed' => false];
        });
    }

    /**
     * Atributos comunes de totales/arqueo/desglose/tasa para crear o
     * actualizar una fila de cierre (AD-3): mismos cálculos en ambas ramas.
     *
     * @return array<string, mixed>
     */
    private function atributosTotales(array $totales, ExchangeRate $tasa, ?float $arqueoBs, ?float $arqueoUsd): array
    {
        return [
            'total_ventas_bs' => $totales['total_ventas_bs'],
            'total_ventas_usd' => $totales['total_ventas_usd'],
            'total_ventas_bs_equivalent' => $totales['total_ventas_bs_equivalent'],
            'total_egresos_bs' => $totales['total_egresos_bs'],
            'total_egresos_usd' => $totales['total_egresos_usd'],
            'total_efectivo_bs' => $totales['total_efectivo_bs'],
            'total_efectivo_usd' => $totales['total_efectivo_usd'],
            'arqueo_efectivo_bs' => $arqueoBs,
            'arqueo_efectivo_usd' => $arqueoUsd,
            'faltante_sobrante_bs' => $arqueoBs !== null
                ? round($arqueoBs - $totales['total_efectivo_bs'], 2)
                : null,
            'faltante_sobrante_usd' => $arqueoUsd !== null
                ? round($arqueoUsd - $totales['total_efectivo_usd'], 2)
                : null,
            'desglose_metodos' => $totales['desglose_metodos'],
            'exchange_rate_cierre' => $tasa->rate,
        ];
    }

    /**
     * Detectar el cierre del día calendario de la taquilla (AD-2): rango
     * explícito [startOfDay, +1d) sobre fecha_fin en America/Caracas
     * (no `whereDate`, que compara el valor crudo UTC y desplaza el día).
     * Con varias filas demo el mismo día se toma la última por fecha_fin
     * (y por id como desempate).
     */
    public function resolveCierreHoy(int $taquillaId): ?CierreCaja
    {
        $inicio = now()->startOfDay();
        $fin = $inicio->copy()->addDay();

        return CierreCaja::where('taquilla_id', $taquillaId)
            ->where('fecha_fin', '>=', $inicio)
            ->where('fecha_fin', '<', $fin)
            ->orderByDesc('fecha_fin')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Validar la clave de cierre contra la cadena jerárquica de la taquilla
     * (AD-7): (1) usuarios role=banca con banca_id = banca del grupo de la
     * taquilla; (2) el usuario role=master con id = Banca.master_id de esa
     * banca; (3) todos los role=super_master. Solo cuentan los candidatos
     * con clave configurada (whereNotNull) y la clave se verifica con
     * Hash::check en OR. La clave nunca se persiste ni se expone.
     *
     * @throws \RuntimeException si la taquilla no existe, ningún candidato
     *                           tiene clave configurada o ninguna coincide
     */
    public function validarClaveCierre(int $taquillaId, string $clave): void
    {
        $taquilla = Taquilla::find($taquillaId);

        if (! $taquilla) {
            throw new \RuntimeException('Taquilla no encontrada.');
        }

        $bancaId = $taquilla->grupo?->banca_id;
        $masterId = $bancaId !== null ? Banca::find($bancaId)?->master_id : null;

        $candidatos = User::query()
            ->where(function ($query) use ($bancaId, $masterId) {
                if ($bancaId !== null) {
                    $query->orWhere(fn ($q) => $q->where('role', 'banca')->where('banca_id', $bancaId));
                }

                if ($masterId !== null) {
                    $query->orWhere(fn ($q) => $q->where('id', $masterId)->where('role', 'master'));
                }

                $query->orWhere('role', 'super_master');
            })
            ->whereNotNull('clave_cierre')
            ->get();

        if ($candidatos->isEmpty()) {
            throw new \RuntimeException('No hay una clave de cierre configurada para esta taquilla.');
        }

        foreach ($candidatos as $candidato) {
            if (Hash::check($clave, $candidato->clave_cierre)) {
                return;
            }
        }

        throw new \RuntimeException('Clave de cierre incorrecta.');
    }

    /**
     * Resolver la tasa de cambio del cierre (AD-6):
     * activa primero, si no hay activa se usa la última por reference_date
     * (fallback), y solo se lanza excepción si no existe ninguna tasa.
     *
     * @throws \RuntimeException si no hay tasa alguna (activa ni histórica)
     */
    private function resolverTasa(): ExchangeRate
    {
        $activa = ExchangeRate::where('is_active', true)->first();

        if ($activa) {
            return $activa;
        }

        $ultima = ExchangeRate::orderByDesc('reference_date')->orderByDesc('id')->first();

        if (! $ultima) {
            throw new \RuntimeException('No hay tasa de cambio activa para realizar el cierre.');
        }

        return $ultima;
    }

    /**
     * Calcular totales y desglose por método de pago del período.
     *
     * - Ventas: apuestas no anuladas (sin soft-delete) por fecha_hora;
     *   el desglose de ventas sale de los `pagos` ingreso de esas apuestas
     *   (JOIN por apuesta_id) agrupado por metodo_pago (AD-4).
     * - Egresos: pagos tipo egreso/devolucion por created_at, con desglose
     *   directo por metodo_pago.
     * - El USD se contabiliza íntegro bajo `efectivo` en el desglose.
     * - Neto por moneda = ventas - egresos.
     *
     * @return array<string, float|array>
     */
    private function calcularTotales(int $taquillaId, $fechaInicio, $fechaFin): array
    {
        $ventas = Apuesta::where('taquilla_id', $taquillaId)
            ->where('estado', '!=', 'anulada')
            ->where('fecha_hora', '>=', $fechaInicio)
            ->where('fecha_hora', '<', $fechaFin)
            ->selectRaw('SUM(amount_bs) as bs, SUM(amount_usd) as usd, SUM(total_bs_equivalent) as equivalente')
            ->first();

        $totalVentasBs = (float) ($ventas->bs ?? 0);
        $totalVentasUsd = (float) ($ventas->usd ?? 0);
        $totalVentasEquiv = (float) ($ventas->equivalente ?? 0);

        $ventasDesglose = Apuesta::where('apuestas.taquilla_id', $taquillaId)
            ->where('apuestas.estado', '!=', 'anulada')
            ->where('apuestas.fecha_hora', '>=', $fechaInicio)
            ->where('apuestas.fecha_hora', '<', $fechaFin)
            ->join('pagos', 'pagos.apuesta_id', '=', 'apuestas.id')
            ->where('pagos.tipo', 'ingreso')
            ->groupBy('pagos.metodo_pago')
            ->selectRaw('pagos.metodo_pago as metodo_pago, SUM(pagos.amount_bs) as bs, SUM(pagos.amount_usd) as usd')
            ->get();

        // Los ingresos (cobro de apuestas) no forman parte de los egresos
        $egresosQuery = Pago::where('taquilla_id', $taquillaId)
            ->whereIn('tipo', ['egreso', 'devolucion'])
            ->where('created_at', '>=', $fechaInicio)
            ->where('created_at', '<', $fechaFin);

        $egresos = (clone $egresosQuery)
            ->selectRaw('SUM(amount_bs) as bs, SUM(amount_usd) as usd')
            ->first();

        $egresosDesglose = (clone $egresosQuery)
            ->groupBy('metodo_pago')
            ->selectRaw('metodo_pago, SUM(amount_bs) as bs, SUM(amount_usd) as usd')
            ->get();

        $totalEgresosBs = (float) ($egresos->bs ?? 0);
        $totalEgresosUsd = (float) ($egresos->usd ?? 0);

        $desglose = $this->armarDesglose($ventasDesglose, $egresosDesglose);

        return [
            'total_ventas_bs' => round($totalVentasBs, 2),
            'total_ventas_usd' => round($totalVentasUsd, 2),
            'total_ventas_bs_equivalent' => round($totalVentasEquiv, 2),
            'total_egresos_bs' => round($totalEgresosBs, 2),
            'total_egresos_usd' => round($totalEgresosUsd, 2),
            'total_efectivo_bs' => round($totalVentasBs - $totalEgresosBs, 2),
            'total_efectivo_usd' => round($totalVentasUsd - $totalEgresosUsd, 2),
            'desglose_metodos' => $desglose,
        ];
    }

    /**
     * Armar el desglose {bs|usd: {metodo: {ventas, egresos, efectivo}}}.
     *
     * Shape fijo (AD-3): los 4 métodos siempre presentes por moneda con
     * ceros. El USD se normaliza íntegro bajo `efectivo` (AD-4): aunque un
     * pago USD se haya registrado con otro método, su monto cae en efectivo.
     */
    private function armarDesglose($ventasDesglose, $egresosDesglose): array
    {
        $desglose = [];
        foreach (['bs', 'usd'] as $moneda) {
            $desglose[$moneda] = [];
            foreach (Pago::METODOS_PAGO as $metodo) {
                $desglose[$moneda][$metodo] = ['ventas' => 0, 'egresos' => 0, 'efectivo' => 0];
            }
        }

        foreach ($ventasDesglose as $row) {
            $metodo = $row->metodo_pago ?? 'efectivo';
            $desglose['bs'][$metodo]['ventas'] += (float) $row->bs;
            $desglose['usd']['efectivo']['ventas'] += (float) $row->usd;
        }

        foreach ($egresosDesglose as $row) {
            $metodo = $row->metodo_pago ?? 'efectivo';
            $desglose['bs'][$metodo]['egresos'] += (float) $row->bs;
            $desglose['usd']['efectivo']['egresos'] += (float) $row->usd;
        }

        foreach (['bs', 'usd'] as $moneda) {
            foreach (Pago::METODOS_PAGO as $metodo) {
                $desglose[$moneda][$metodo]['efectivo'] = round(
                    $desglose[$moneda][$metodo]['ventas'] - $desglose[$moneda][$metodo]['egresos'],
                    2
                );
            }
        }

        return $desglose;
    }

    /**
     * Listar cierres de una consulta pre-escalada por jerarquía (controller).
     */
    public function listarCierres($query, int $perPage = 20)
    {
        return $query->with(['taquilla.grupo.banca', 'creador'])
            ->orderByDesc('fecha_fin')
            ->paginate($perPage);
    }

    /**
     * Rollup semanal de los cierres diarios persistidos (AD-5, solo lectura).
     *
     * La consulta llega pre-escalada por jerarquía (controller); aquí solo se
     * acota al rango [desde, hasta), donde `hasta` ya es exclusivo
     * (fecha_hasta + 1 día), y se agregan totales por moneda, desglose
     * fusionado, ventana cubierta (min/max fecha_fin) y los diarios incluidos
     * ordenados por fecha_fin asc.
     *
     * Semana sin diarios: totales en 0, arqueo/faltante null, ventana cubierta
     * {null, null, 0} y cierres [].
     *
     * @return array<string, mixed>
     */
    public function reporteSemanal($query, $desde, $hasta): array
    {
        $cierres = (clone $query)
            ->where('fecha_fin', '>=', $desde)
            ->where('fecha_fin', '<', $hasta)
            ->orderBy('fecha_fin')
            ->get();

        $totales = [
            'total_ventas_bs' => 0.0,
            'total_ventas_usd' => 0.0,
            'total_ventas_bs_equivalent' => 0.0,
            'total_egresos_bs' => 0.0,
            'total_egresos_usd' => 0.0,
            'total_efectivo_bs' => 0.0,
            'total_efectivo_usd' => 0.0,
            'arqueo_efectivo_bs' => null,
            'arqueo_efectivo_usd' => null,
            'faltante_sobrante_bs' => null,
            'faltante_sobrante_usd' => null,
        ];

        // Shape fijo (AD-3) con ceros: mismo esqueleto que armarDesglose([], [])
        $desglose = $this->armarDesglose([], []);

        foreach ($cierres as $cierre) {
            $totales['total_ventas_bs'] += (float) $cierre->total_ventas_bs;
            $totales['total_ventas_usd'] += (float) $cierre->total_ventas_usd;
            $totales['total_ventas_bs_equivalent'] += (float) $cierre->total_ventas_bs_equivalent;
            $totales['total_egresos_bs'] += (float) $cierre->total_egresos_bs;
            $totales['total_egresos_usd'] += (float) $cierre->total_egresos_usd;
            $totales['total_efectivo_bs'] += (float) $cierre->total_efectivo_bs;
            $totales['total_efectivo_usd'] += (float) $cierre->total_efectivo_usd;

            foreach (['arqueo_efectivo_bs', 'arqueo_efectivo_usd', 'faltante_sobrante_bs', 'faltante_sobrante_usd'] as $campo) {
                if ($cierre->{$campo} !== null) {
                    $totales[$campo] = ($totales[$campo] ?? 0) + (float) $cierre->{$campo};
                }
            }

            $desgloseCierre = $cierre->desglose_metodos ?? [];
            foreach (['bs', 'usd'] as $moneda) {
                foreach (Pago::METODOS_PAGO as $metodo) {
                    foreach (['ventas', 'egresos', 'efectivo'] as $clave) {
                        $desglose[$moneda][$metodo][$clave] += (float) ($desgloseCierre[$moneda][$metodo][$clave] ?? 0);
                    }
                }
            }
        }

        return [
            'ventana_cubierta' => [
                'desde' => $cierres->min('fecha_fin'),
                'hasta' => $cierres->max('fecha_fin'),
                'cierres_incluidos' => $cierres->count(),
            ],
            'total_ventas_bs' => round($totales['total_ventas_bs'], 2),
            'total_ventas_usd' => round($totales['total_ventas_usd'], 2),
            'total_ventas_bs_equivalent' => round($totales['total_ventas_bs_equivalent'], 2),
            'total_egresos_bs' => round($totales['total_egresos_bs'], 2),
            'total_egresos_usd' => round($totales['total_egresos_usd'], 2),
            'total_efectivo_bs' => round($totales['total_efectivo_bs'], 2),
            'total_efectivo_usd' => round($totales['total_efectivo_usd'], 2),
            'arqueo_efectivo_bs' => $totales['arqueo_efectivo_bs'] !== null ? round($totales['arqueo_efectivo_bs'], 2) : null,
            'arqueo_efectivo_usd' => $totales['arqueo_efectivo_usd'] !== null ? round($totales['arqueo_efectivo_usd'], 2) : null,
            'faltante_sobrante_bs' => $totales['faltante_sobrante_bs'] !== null ? round($totales['faltante_sobrante_bs'], 2) : null,
            'faltante_sobrante_usd' => $totales['faltante_sobrante_usd'] !== null ? round($totales['faltante_sobrante_usd'], 2) : null,
            'desglose_metodos' => $desglose,
            'cierres' => $cierres->map(fn (CierreCaja $cierre) => [
                'id' => $cierre->id,
                'taquilla_id' => $cierre->taquilla_id,
                'fecha_inicio' => $cierre->fecha_inicio,
                'fecha_fin' => $cierre->fecha_fin,
                'total_ventas_bs' => $cierre->total_ventas_bs,
                'total_efectivo_bs' => $cierre->total_efectivo_bs,
            ])->all(),
        ];
    }

    /**
     * Preview read-only del período actual (AD-8): mismos cálculos que
     * crearCierre pero sin persistir. Usado por GET /cierre/actual.
     *
     * Campo aditivo `cierre_hoy` (AD-11): el cierre del día calendario
     * {id, fecha_inicio, fecha_fin} o null si no existe, para que la UI
     * decida el copy del confirm y solicite la clave en re-cierre.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException si no existe ninguna tasa de cambio
     */
    public function previsualizar(int $taquillaId): array
    {
        $tasa = $this->resolverTasa();
        $fechaFin = now();
        $fechaInicio = $this->resolveFechaInicio($taquillaId, $fechaFin);
        $totales = $this->calcularTotales($taquillaId, $fechaInicio, $fechaFin);

        $cierreHoy = $this->resolveCierreHoy($taquillaId);

        return [
            'taquilla_id' => $taquillaId,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'exchange_rate' => $tasa->rate,
            'cierre_hoy' => $cierreHoy !== null ? [
                'id' => $cierreHoy->id,
                'fecha_inicio' => $cierreHoy->fecha_inicio,
                'fecha_fin' => $cierreHoy->fecha_fin,
            ] : null,
        ] + $totales;
    }

    /**
     * Resolver el inicio del período: último cierre → primera apuesta → ahora.
     */
    private function resolveFechaInicio(int $taquillaId, $fechaFin)
    {
        $ultimoCierre = CierreCaja::where('taquilla_id', $taquillaId)
            ->orderByDesc('fecha_fin')
            ->first();

        if ($ultimoCierre?->fecha_fin) {
            return $ultimoCierre->fecha_fin;
        }

        $primeraApuesta = Apuesta::where('taquilla_id', $taquillaId)
            ->orderBy('fecha_hora')
            ->value('fecha_hora');

        if ($primeraApuesta) {
            return $primeraApuesta;
        }

        return $fechaFin;
    }
}
