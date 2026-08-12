<?php

namespace App\Services;

use App\Models\Apuesta;
use App\Models\DetalleApuesta;
use App\Models\ExchangeRate;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\Resultado;
use App\Models\Ticket;
use Carbon\Carbon;

class ApuestaService
{
    /**
     * Calcular total_bs_equivalent usando tasa activa del momento
     */
    public function calcularTotal(float $amountBs, float $amountUsd): float
    {
        $tasaActiva = ExchangeRate::where('is_active', true)->first();
        
        if (!$tasaActiva) {
            throw new \RuntimeException('No hay tasa activa configurada');
        }
        
        return $amountBs + ($amountUsd * $tasaActiva->rate);
    }

    /**
     * Obtener la tasa activa actual (null si no existe)
     */
    public function getTasaActiva(): ?float
    {
        $tasa = ExchangeRate::where('is_active', true)->first();
        return $tasa ? $tasa->rate : null;
    }

    /**
     * Validar que el monto cubre el costo mínimo del juego.
     * Lee de juego_limites (nivel banca, moneda='bs') en lugar de juegos.costo_minimo.
     */
    public function validarCostoMinimo(float $totalBsEquivalent, int $juegoId, int $taquillaId): array
    {
        $juego = Juego::find($juegoId);

        if (!$juego) {
            return [
                'valid' => false,
                'message' => 'Juego no encontrado.',
            ];
        }

        // Usar el límite efectivo de la taquilla (herencia jerárquica)
        $limite = $this->getEffectiveLimit($taquillaId, $juegoId, 'bs');

        $costoMinimo = $limite ? $limite->limite_minimo : null;

        if ($costoMinimo === null) {
            return [
                'valid' => true,
                'limite_minimo' => null,
            ];
        }

        if ($totalBsEquivalent < $costoMinimo) {
            return [
                'valid' => false,
                'message' => "El monto no cubre el costo mínimo del juego.",
                'required_min' => (float) $costoMinimo,
                'current_total' => $totalBsEquivalent,
            ];
        }

        return [
            'valid' => true,
            'limite_minimo' => (float) $costoMinimo,
        ];
    }

    /**
     * Convertir BS a USD equivalente (para mostrar al usuario)
     */
    public function bsToUsd(float $amountBs): float
    {
        $tasaActiva = ExchangeRate::where('is_active', true)->first();
        return $tasaActiva ? $amountBs / $tasaActiva->rate : 0;
    }

    /**
     * Convertir USD a BS equivalente (para cálculo)
     */
    public function usdToBs(float $amountUsd): float
    {
        $tasaActiva = ExchangeRate::where('is_active', true)->first();
        return $tasaActiva ? $amountUsd * $tasaActiva->rate : 0;
    }

    /**
     * Resolver monedas efectivas para una taquilla.
     * Join taquilla → grupo → banca y calcula la intersección.
     * NULL en cualquier nivel = ambas monedas habilitadas (sin restricción).
     *
     * @return array ['bs' => bool, 'usd' => bool]
     */
    public function getEffectiveMonedas(int $taquillaId): array
    {
        $taquilla = \App\Models\Taquilla::with('grupo.banca')->find($taquillaId);

        if (!$taquilla) {
            return ['bs' => true, 'usd' => true];
        }

        $bancaMonedas = $taquilla->grupo?->banca?->monedas_permitidas;
        $grupoMonedas = $taquilla->grupo?->monedas_permitidas;

        return $this->intersectarMonedas($bancaMonedas, $grupoMonedas);
    }

    /**
     * Calcular intersección de monedas entre dos niveles jerárquicos.
     * NULL = ambas habilitadas.
     */
    private function intersectarMonedas(?array $parent, ?array $child): array
    {
        $parentBs = $parent['bs'] ?? true;   // NULL interpretado como true
        $parentUsd = $parent['usd'] ?? true;
        $childBs = $child['bs'] ?? true;
        $childUsd = $child['usd'] ?? true;

        return [
            'bs' => $parentBs && $childBs,
            'usd' => $parentUsd && $childUsd,
        ];
    }

    /**
     * Resolver límite efectivo para un (taquilla, juego, moneda).
     * Cascada: taquilla → grupo → banca → null.
     * Single query usando COALESCE en orden de precedencia.
     */
    public function getEffectiveLimit(int $taquillaId, int $juegoId, string $moneda): ?\App\Models\JuegoLimite
    {
        $taquilla = \App\Models\Taquilla::find($taquillaId);

        if (!$taquilla) {
            return null;
        }

        $grupoId = $taquilla->grupo_id;
        $bancaId = $taquilla->grupo?->banca_id;

        if (!$bancaId) {
            return null;
        }

        // Single query: prefiere taquilla > grupo > banca, mismo juego + moneda
        return \App\Models\JuegoLimite::where('juego_id', $juegoId)
            ->where('moneda', $moneda)
            ->where(function ($q) use ($taquillaId, $grupoId, $bancaId) {
                $q->where('taquilla_id', $taquillaId)
                  ->orWhere(function ($q2) use ($grupoId) {
                      $q2->whereNull('taquilla_id')->where('grupo_id', $grupoId);
                  })
                  ->orWhere(function ($q2) use ($bancaId) {
                      $q2->whereNull('taquilla_id')->whereNull('grupo_id')->where('banca_id', $bancaId);
                  });
            })
            ->orderByRaw('taquilla_id IS NOT NULL DESC, grupo_id IS NOT NULL DESC')
            ->first();
    }

    /**
     * Resolver vigencia efectiva de premios para una taquilla.
     * Cascada: taquilla.vigencia_premios → grupo.vigencia_premios → banca.vigencia_premios → null.
     *
     * @return int|null Días de vigencia. null = nunca expira.
     */
    public function getEffectiveVigencia(int $taquillaId): ?int
    {
        $taquilla = \App\Models\Taquilla::with('grupo.banca')
            ->find($taquillaId);

        if (!$taquilla) {
            return null;
        }

        // COALESCE: taquilla → grupo → banca
        return $taquilla->vigencia_premios
            ?? $taquilla->grupo?->vigencia_premios
            ?? $taquilla->grupo?->banca?->vigencia_premios
            ?? null;
    }

    /**
     * Validación unificada de moneda y límites para una apuesta.
     * Llamado dentro de createApuesta() antes de validarCostoMinimo.
     *
     * @return array ['valid' => bool, 'message' => string]
     */
    public function validarMonedaYLimites(int $taquillaId, int $juegoId, float $amountBs, float $amountUsd): array
    {
        // 1. Validar moneda permitida
        $monedas = $this->getEffectiveMonedas($taquillaId);
        $usaBs = $amountBs > 0;
        $usaUsd = $amountUsd > 0;
        $esMixto = $usaBs && $usaUsd;

        if ($esMixto && (!$monedas['bs'] || !$monedas['usd'])) {
            return [
                'valid' => false,
                'message' => 'Ambas monedas deben estar habilitadas para apuestas mixtas.',
            ];
        }

        if ($usaUsd && !$monedas['usd']) {
            return [
                'valid' => false,
                'message' => 'Moneda USD no permitida para esta taquilla.',
            ];
        }

        if ($usaBs && !$monedas['bs']) {
            return [
                'valid' => false,
                'message' => 'Moneda BS no permitida para esta taquilla.',
            ];
        }

        // 2. Validar límites por cada moneda usada
        if ($usaBs) {
            $limiteBs = $this->getEffectiveLimit($taquillaId, $juegoId, 'bs');
            $result = $this->validarContraLimite($limiteBs, $amountBs, 'BS');
            if (!$result['valid']) {
                return $result;
            }
        }

        if ($usaUsd) {
            $limiteUsd = $this->getEffectiveLimit($taquillaId, $juegoId, 'usd');
            $result = $this->validarContraLimite($limiteUsd, $amountUsd, 'USD');
            if (!$result['valid']) {
                return $result;
            }
        }

        return ['valid' => true, 'message' => ''];
    }

    /**
     * Validar un monto contra los límites mínimo y máximo de un JuegoLimite.
     */
    private function validarContraLimite(?\App\Models\JuegoLimite $limite, float $monto, string $monedaLabel): array
    {
        if (!$limite) {
            return ['valid' => true, 'message' => ''];
        }

        if ($limite->limite_maximo !== null && $monto > $limite->limite_maximo) {
            return [
                'valid' => false,
                'message' => "El monto excede el límite máximo de {$limite->limite_maximo} {$monedaLabel}.",
            ];
        }

        if ($limite->limite_minimo !== null && $monto < $limite->limite_minimo) {
            return [
                'valid' => false,
                'message' => "El monto está por debajo del límite mínimo de {$limite->limite_minimo} {$monedaLabel}.",
            ];
        }

        return ['valid' => true, 'message' => ''];
    }

    /**
     * Obtener el siguiente sorteo según horarios del juego
     */
    public function getNextDrawTime(int $juegoId): string
    {
        $horarios = JuegoHorario::where('juego_id', $juegoId)
            ->where('active', true)
            ->orderBy('hora')
            ->pluck('hora');

        if ($horarios->isEmpty()) {
            return now()->addHours(2)->format('Y-m-d H:i:s');
        }

        $now = now();
        foreach ($horarios as $hora) {
            $drawTime = $now->copy()->setTimeFromTimeString($hora);
            if ($drawTime->isFuture()) {
                return $drawTime->format('Y-m-d H:i:s');
            }
        }

        return $now->copy()->addDay()->setTimeFromTimeString($horarios->first())->format('Y-m-d H:i:s');
    }

    /**
     * Obtener resumen estadístico de apuestas
     */
    public function obtenerResumen($query): array
    {
        $totalBs = $query->sum('amount_bs') ?: 0;
        $totalUsd = $query->sum('amount_usd') ?: 0;
        $totalBetAmount = $query->sum('total_bs_equivalent') ?: 0;
        
        $pendingCount = (clone $query)->where('estado', 'pendiente')->count();
        $pagadaCount = (clone $query)->where('estado', 'pagada')->count();
        $anuladaCount = (clone $query)->where('estado', 'anulada')->count();
        
        return [
            'total_bs' => round($totalBs, 2),
            'total_usd' => round($totalUsd, 2),
            'total_bet_amount_bs' => round($totalBetAmount, 2),
            'pending_count' => $pendingCount,
            'pagada_count' => $pagadaCount,
            'anulada_count' => $anuladaCount,
        ];
    }

    /**
     * Crear una apuesta individual (reutilizable desde ApuestaController y TicketController)
     */
    public function createApuesta(array $data, int $taquillaId, int $userId, ?int $ticketId = null): \App\Models\Apuesta
    {
        $tasaActiva = ExchangeRate::where('is_active', true)->first();

        if (!$tasaActiva) {
            throw new \RuntimeException('No hay tasa de cambio activa configurada. Contacte al administrador.');
        }

        $amountBs = (float) ($data['amount_bs'] ?? 0);
        $amountUsd = (float) ($data['amount_usd'] ?? 0);
        $totalBsEquivalent = $amountBs + ($amountUsd * $tasaActiva->rate);

        // Validar moneda permitida y límites efectivos antes de costo mínimo
        $validacionMoneda = $this->validarMonedaYLimites($taquillaId, $data['juego_id'], $amountBs, $amountUsd);

        if (!$validacionMoneda['valid']) {
            throw new \RuntimeException($validacionMoneda['message']);
        }

        $validacion = $this->validarCostoMinimo($totalBsEquivalent, $data['juego_id'], $taquillaId);

        if (!$validacion['valid']) {
            throw new \RuntimeException($validacion['message'] . ' (Monto actual: ' . round($totalBsEquivalent, 2) . ' Bs)');
        }

        $combinacion = $data['combinacion'] ?? [];

        // Validar la combinación contra las opciones específicas del juego
        $juego = \App\Models\Juego::find($data['juego_id']);
        if ($juego) {
            $plugin = app(\App\Services\JuegoPluginManager::class)->getPlugin($juego);
            if ($plugin) {
                $opciones = \App\Models\JuegoOpcion::where('juego_id', $juego->id)
                    ->orderBy('numero')
                    ->get()
                    ->toArray();

                if (empty($opciones)) {
                    $opciones = $plugin->obtenerOpciones();
                }

                if (!$plugin->validarApuesta($data, $opciones)) {
                    $labels = array_column($opciones, 'label');
                    throw new \RuntimeException(
                        'Animal no válido para este juego. Animales permitidos: ' . implode(', ', $labels)
                    );
                }
            }
        }

        $sorteoHora = $data['sorteo_hora'] ?? null;
        if ($sorteoHora) {
            $sorteoHora = Carbon::parse($sorteoHora);
            if ($sorteoHora->isPast()) {
                $sorteoHora = Carbon::parse($this->getNextDrawTime($data['juego_id']));
            }
        } else {
            $sorteoHora = Carbon::parse($this->getNextDrawTime($data['juego_id']));
        }

        $apuestaData = [
            'taquilla_id' => $taquillaId,
            'juego_id' => $data['juego_id'],
            'combinacion' => json_encode($combinacion),
            'amount_bs' => $amountBs,
            'amount_usd' => $amountUsd,
            'exchange_rate_applied' => $tasaActiva->rate,
            'total_bs_equivalent' => $totalBsEquivalent,
            'estado' => 'pendiente',
            'fecha_hora' => now(),
            'sorteo_hora' => $sorteoHora->format('Y-m-d H:i:s'),
        ];

        if ($ticketId) {
            $apuestaData['ticket_id'] = $ticketId;
        }

        $apuesta = \App\Models\Apuesta::create($apuestaData);

        // Generar detalles con plugin
        $juego = \App\Models\Juego::find($data['juego_id']);
        if ($juego) {
            $plugin = app(\App\Services\JuegoPluginManager::class)->getPlugin($juego);
            $premio = $plugin
                ? $plugin->calcularPremio(
                    ['combinacion' => $combinacion, 'total_bs_equivalent' => $totalBsEquivalent, 'amount_bs' => $amountBs, 'amount_usd' => $amountUsd],
                    []
                )
                : ['premio_bs' => $totalBsEquivalent, 'premio_usd' => 0];

            \App\Models\DetalleApuesta::create([
                'apuesta_id' => $apuesta->id,
                'combinacion' => json_encode($combinacion),
                'monto' => $totalBsEquivalent,
                'premio_posible' => $premio['premio_bs'],
                'premio_posible_usd' => $premio['premio_usd'],
                'premio_ganado' => null,
                'premio_ganado_usd' => null,
            ]);
        }

        // Crear pago
        $moneda = $amountBs > 0 && $amountUsd > 0 ? 'mixto' : ($amountUsd > 0 ? 'usd' : 'bs');

        \App\Models\Pago::create([
            'taquilla_id' => $taquillaId,
            'apuesta_id' => $apuesta->id,
            'amount_bs' => $amountBs,
            'amount_usd' => $amountUsd,
            'exchange_rate_applied' => $tasaActiva->rate,
            'tipo' => 'ingreso',
            'moneda' => $moneda,
            'concepto' => 'Compra de ticket',
            'created_by' => $userId,
        ]);

        return $apuesta;
    }

    // ============================================
    // Phase 4: Report Aggregation Methods
    // ============================================

    /**
     * Ventas totales agrupadas por banca.
     * Recibe query pre-escalado por jerarquía y filtros desde el controlador.
     *
     * Columnas: Banca, Venta, Premio, Porcentaje, Utilidad, Participación, Total
     */
    public function ventasTotales($query, array $filters): array
    {
        // Determinar nivel de agrupación: banca (default), grupo, taquilla
        $nivel = $filters['nivel'] ?? 'banca';

        // Clonar para no afectar el query original
        $base = (clone $query)
            ->join('taquillas', 'apuestas.taquilla_id', '=', 'taquillas.id')
            ->join('grupos', 'taquillas.grupo_id', '=', 'grupos.id')
            ->join('bancas', 'grupos.banca_id', '=', 'bancas.id')
            ->leftJoin('detalle_apuestas', 'apuestas.id', '=', 'detalle_apuestas.apuesta_id')
            ->where('apuestas.estado', '!=', 'anulada');

        // Filtro por tipo de juego (slug)
        if (!empty($filters['tipo_juego'])) {
            $base->join('juegos', 'apuestas.juego_id', '=', 'juegos.id')
                 ->where('juegos.slug', $filters['tipo_juego']);
        }

        // Filtro por moneda
        if (!empty($filters['moneda'])) {
            $base->where(function ($q) use ($filters) {
                match ($filters['moneda']) {
                    'bs' => $q->where('apuestas.amount_bs', '>', 0)->where('apuestas.amount_usd', 0),
                    'usd' => $q->where('apuestas.amount_usd', '>', 0)->where('apuestas.amount_bs', 0),
                    'mixto' => $q->where('apuestas.amount_bs', '>', 0)->where('apuestas.amount_usd', '>', 0),
                    default => null,
                };
            });
        }

        // Configurar groupBy y label según nivel
        $groupCols = match ($nivel) {
            'taquilla' => ['taquillas.id', 'taquillas.name'],
            'grupo' => ['grupos.id', 'grupos.name'],
            default => ['bancas.id', 'bancas.name'], // 'banca' o cualquier otro
        };

        $labelCol = match ($nivel) {
            'taquilla' => 'taquillas.name',
            'grupo' => 'grupos.name',
            default => 'bancas.name',
        };

        $filas = $base
            ->groupBy(...$groupCols)
            ->selectRaw("
                {$labelCol} as Entidad,
                SUM(apuestas.total_bs_equivalent) as Venta,
                COALESCE(SUM(detalle_apuestas.premio_ganado), 0) as Premio,
                COUNT(DISTINCT apuestas.id) as Total
            ")
            ->get();

        $totalVenta = $filas->sum('Venta');

        return $filas->map(function ($fila) use ($totalVenta) {
            $venta = (float) $fila->Venta;
            $premio = (float) $fila->Premio;
            $porcentaje = $venta > 0 ? round(($premio / $venta) * 100, 2) : 0;
            $utilidad = $venta - $premio;
            $participacion = $totalVenta > 0 ? round(($venta / $totalVenta) * 100, 2) : 0;

            return [
                'Entidad' => $fila->Entidad,
                'Venta' => $venta,
                'Premio' => $premio,
                'Porcentaje' => $porcentaje,
                'Utilidad' => $utilidad,
                'Participación' => $participacion,
                'Total' => (int) $fila->Total,
            ];
        })->values()->toArray();
    }

    /**
     * Relación de tickets con columnas computadas.
     * Recibe query de tickets pre-escalado desde el controlador.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function relacionTickets($query, array $filters, int $perPage = 50)
    {
        $tickets = (clone $query)
            ->with(['taquilla', 'apuestas'])
            ->withCount('apuestas as jugadas_count')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Enriquecer cada ticket con columnas computadas
        $tickets->through(function ($ticket) {
            $apuestas = $ticket->apuestas;

            // Sorteos: conteo DISTINCT de sorteo_hora entre las apuestas del ticket
            $sorteosDistintos = $apuestas->pluck('sorteo_hora')
                ->map(fn($h) => $h instanceof \Carbon\Carbon ? $h->toDateString() : (string) $h)
                ->unique()
                ->count();

            // Tipo de moneda
            $tieneBs = $apuestas->sum('amount_bs') > 0;
            $tieneUsd = $apuestas->sum('amount_usd') > 0;
            $tipo = $tieneBs && $tieneUsd ? 'Mixto' : ($tieneUsd ? 'USD' : 'BS');

            // Usuario: obtener del pago de la primera apuesta
            $usuario = null;
            $primeraApuesta = $apuestas->first();
            if ($primeraApuesta) {
                $pago = \App\Models\Pago::where('apuesta_id', $primeraApuesta->id)->first();
                if ($pago && $pago->created_by) {
                    $usuario = \App\Models\User::find($pago->created_by)?->name ?? null;
                }
            }

            $ticket->Ticket_N = $ticket->ticket_code;
            $ticket->Taquilla = $ticket->taquilla?->name;
            $ticket->Usuario = $usuario;
            $ticket->Fecha = $ticket->created_at?->format('Y-m-d');
            $ticket->Monto = (float) ($ticket->total_bs + $ticket->total_usd);
            $ticket->Premio = (float) ($ticket->premio_total_bs + $ticket->premio_total_usd);
            $ticket->Sorteos = $sorteosDistintos;
            $ticket->Jugadas = (int) $ticket->jugadas_count;
            $ticket->Tipo = $tipo;

            return $ticket;
        });

        return $tickets;
    }

    /**
     * Rendimiento por taquilla.
     * Recibe query de apuestas pre-escalado desde el controlador.
     *
     * Columnas: Taquilla, Venta, Anulado, Premio, Ganancia, % Peso Venta, % Peso Ganancia, Estado
     */
    public function rendimientoTaquillas($query, array $filters): array
    {
        // Obtener la taquilla_id de las apuestas en el query para contexto de jerarquía
        $taquillaIds = (clone $query)->distinct()->pluck('taquilla_id');

        $taquillas = \App\Models\Taquilla::whereIn('id', $taquillaIds)
            ->with('grupo')
            ->get()
            ->keyBy('id');

        $ventasPorTaquilla = (clone $query)
            ->leftJoin('detalle_apuestas', 'apuestas.id', '=', 'detalle_apuestas.apuesta_id')
            ->groupBy('apuestas.taquilla_id')
            ->selectRaw("
                apuestas.taquilla_id,
                SUM(CASE WHEN apuestas.estado != 'anulada' THEN apuestas.total_bs_equivalent ELSE 0 END) as Venta,
                SUM(CASE WHEN apuestas.estado = 'anulada' THEN 1 ELSE 0 END) as Anulado,
                COALESCE(SUM(detalle_apuestas.premio_ganado), 0) as Premio
            ")
            ->get()
            ->keyBy('taquilla_id');

        $totalVenta = $ventasPorTaquilla->sum('Venta');
        
        // Ganancia total = Venta total - Premio total
        $totalPremio = $ventasPorTaquilla->sum('Premio');
        $totalGanancia = $totalVenta - $totalPremio;

        $resultados = [];
        foreach ($ventasPorTaquilla as $taquillaId => $row) {
            $taquilla = $taquillas->get($taquillaId);
            $venta = (float) $row->Venta;
            $anulado = (int) $row->Anulado;
            $premio = (float) $row->Premio;
            $ganancia = $venta - $premio;

            $pesoVenta = $totalVenta > 0 ? round(($venta / $totalVenta) * 100, 2) : 0;
            $pesoGanancia = $totalGanancia > 0 ? round(($ganancia / $totalGanancia) * 100, 2) : 0;

            $resultados[] = [
                'Taquilla' => $taquilla?->name ?? "Taquilla #{$taquillaId}",
                'Venta' => $venta,
                'Anulado' => $anulado,
                'Premio' => $premio,
                'Ganancia' => $ganancia,
                '% Peso Venta' => $pesoVenta,
                '% Peso Ganancia' => $pesoGanancia,
                'Estado' => $taquilla?->active ? 'Activa' : 'Inactiva',
            ];
        }

        return $resultados;
    }

    /**
     * Verificar apuestas pendientes contra un resultado recién guardado
     */
    public function verificarGanadores(Resultado $resultado): int
    {
        $pluginManager = app(\App\Services\JuegoPluginManager::class);
        $plugin = $pluginManager->getPlugin($resultado->juego);

        if (!$plugin) {
            return 0;
        }

        $horaSorteo = $resultado->hora_sorteo;
        if ($horaSorteo && preg_match('/AM|PM/i', $horaSorteo)) {
            $horaSorteo = Carbon::createFromFormat('h:i A', trim($horaSorteo))->format('H:i:s');
        }

        $apuestas = Apuesta::with('ticket')
            ->where('juego_id', $resultado->juego_id)
            ->where('estado', 'pendiente')
            ->whereDate('sorteo_hora', $resultado->fecha_sorteo->toDateString())
            ->whereTime('sorteo_hora', $horaSorteo)
            ->get();

        $ganadoras = 0;
        $ticketPremios = [];
        $ticketsGanadores = [];

        foreach ($apuestas as $apuesta) {
            $combinacion = $apuesta->combinacion;
            if (is_string($combinacion)) {
                $combinacion = json_decode($combinacion, true);
            }

            $premio = $plugin->calcularPremio(
                [
                    'combinacion' => $combinacion ?? [],
                    'amount_bs' => (float) $apuesta->amount_bs,
                    'amount_usd' => (float) $apuesta->amount_usd,
                ],
                ['numeros_ganadores' => $resultado->numeros_ganadores]
            );

            $premioBs = $premio['premio_bs'] ?? 0;
            $premioUsd = $premio['premio_usd'] ?? 0;

            $apuesta->resultado_id = $resultado->id;
            $apuesta->save();

            DetalleApuesta::where('apuesta_id', $apuesta->id)->update([
                'premio_ganado' => $premioBs > 0 ? $premioBs : null,
                'premio_ganado_usd' => $premioUsd > 0 ? $premioUsd : null,
            ]);

            if ($premioBs > 0 || $premioUsd > 0) {
                $ganadoras++;

                $ticketId = $apuesta->ticket_id;
                if ($ticketId) {
                    if (!isset($ticketPremios[$ticketId])) {
                        $ticketPremios[$ticketId] = ['bs' => 0, 'usd' => 0];
                    }
                    $ticketPremios[$ticketId]['bs'] += $premioBs;
                    $ticketPremios[$ticketId]['usd'] += $premioUsd;
                    $ticketsGanadores[$ticketId] = true;
                }
            } else {
                $apuesta->estado = 'perdida';
                $apuesta->save();
            }
        }

        foreach ($ticketPremios as $ticketId => $premios) {
            Ticket::where('id', $ticketId)->update([
                'premio_total_bs' => $premios['bs'],
                'premio_total_usd' => $premios['usd'],
            ]);
        }

        if (!empty($ticketsGanadores)) {
            Ticket::whereIn('id', array_keys($ticketsGanadores))
                ->where('estado', 'pendiente')
                ->update(['estado' => 'ganador']);
        }

        return $ganadoras;
    }
}
