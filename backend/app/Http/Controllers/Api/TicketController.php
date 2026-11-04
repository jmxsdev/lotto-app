<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TicketAnulacionException;
use App\Http\Controllers\Controller;
use App\Models\Apuesta;
use App\Models\Pago;
use App\Models\Resultado;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ApuestaService;
use App\Services\JuegoPluginManager;
use App\Services\PremiosEngine;
use App\Services\TicketAnulacionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    protected ApuestaService $apuestaService;

    protected TicketAnulacionService $ticketAnulacionService;

    public function __construct(
        ApuestaService $apuestaService,
        TicketAnulacionService $ticketAnulacionService
    ) {
        $this->apuestaService = $apuestaService;
        $this->ticketAnulacionService = $ticketAnulacionService;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $this->autorizarScope($user);

        $query = Ticket::withTrashed()
            ->where(function ($q) {
                // fix/taquilla-fixes: los anulados se soft-deletean con
                // estado='anulada' y deben seguir VISIBLES en el historial
                // (badge ANULADA, filtro ?estado=anulada); cualquier otro
                // soft-delete del ticket permanece oculto.
                $q->whereNull('deleted_at')->orWhere('estado', 'anulada');
            })
            ->with(['apuestas.juego', 'apuestas.detalles', 'taquilla'])
            ->withCount(['apuestas as ganadoras_count' => function ($q) {
                $q->whereHas('detalles', function ($q2) {
                    $q2->whereNotNull('premio_ganado')
                        ->orWhereNotNull('premio_ganado_usd');
                });
            }])
            ->latest();

        if ($user->hasRole('taquilla')) {
            $query->where('taquilla_id', $user->taquilla_id);
        } elseif ($user->hasRole('grupo')) {
            $query->whereHas('taquilla.grupo', function ($q) use ($user) {
                $q->where('grupo_id', $user->grupo_id);
            });
        } elseif ($user->hasRole('banca')) {
            $query->whereHas('taquilla.grupo.banca', function ($q) use ($user) {
                $q->where('banca_id', $user->banca_id);
            });
        } elseif ($user->hasRole('master')) {
            // Caso 4 (#84): master solo sus bancas; sin bancas → vacío.
            $user->masterBancaChainScope()($query);
        }

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->has('ticket_code')) {
            $query->where('ticket_code', 'like', '%'.$request->ticket_code.'%');
        }
        if ($request->has('fecha_desde')) {
            $query->whereDate('created_at', '>=', $request->fecha_desde);
        }
        if ($request->has('fecha_hasta')) {
            $query->whereDate('created_at', '<=', $request->fecha_hasta);
        }

        $tickets = $query->paginate($request->input('per_page', 25));

        $tickets->getCollection()->transform(function ($ticket) {
            $ticket->tiene_ganadores = $ticket->ganadoras_count > 0;
            // S2 taquilla-operativa: ventana de anulación EFECTIVA (cascada
            // taquilla → grupo → banca → 5); el front la usa tal cual, sin
            // hardcodear 5.
            $ticket->tiempo_eliminacion_efectivo = $this->apuestaService->getEffectiveTiempoEliminacion($ticket->taquilla_id);

            return $ticket;
        });

        return response()->json(['data' => $tickets]);
    }

    public function show(Request $request, Ticket $ticket)
    {
        $user = $request->user();
        $this->autorizarScope($user);

        if ($user->hasRole('taquilla') && $ticket->taquilla_id !== $user->taquilla_id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        } elseif ($user->hasRole('grupo') && $ticket->taquilla?->grupo_id !== $user->grupo_id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        } elseif ($user->hasRole('banca') && $ticket->taquilla?->grupo?->banca_id !== $user->banca_id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        } elseif ($user->hasRole('master')) {
            // Caso 4 (#84): master solo sus bancas; ticket ajeno → 403.
            $bancaId = $ticket->taquilla?->grupo?->banca_id;

            if ($bancaId === null || ! $user->masterCanAccessBanca((int) $bancaId)) {
                return response()->json(['message' => 'No autorizado.'], 403);
            }
        }

        $ticket->load(['apuestas.juego', 'apuestas.detalles', 'taquilla']);
        $ticket->load(['apuestas.juego', 'apuestas.detalles', 'taquilla']);
        $ticket->loadCount(['apuestas as ganadoras_count' => function ($q) {
            $q->whereHas('detalles', function ($q2) {
                $q2->whereNotNull('premio_ganado')
                    ->orWhereNotNull('premio_ganado_usd');
            });
        }]);
        $ticket->tiene_ganadores = $ticket->ganadoras_count > 0;
        // S2 taquilla-operativa: ventana de anulación efectiva en show también.
        $ticket->tiempo_eliminacion_efectivo = $this->apuestaService->getEffectiveTiempoEliminacion($ticket->taquilla_id);

        return response()->json(['data' => $ticket]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (! $user->taquilla_id) {
            return response()->json([
                'message' => 'Solo las taquillas pueden crear tickets.',
            ], 403);
        }

        $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.juego_id' => 'required|integer|exists:juegos,id',
            'lines.*.amount_bs' => 'numeric|min:0',
            'lines.*.amount_usd' => 'numeric|min:0',
            'lines.*.combinacion' => 'nullable|array',
            'metodo_pago' => ['nullable', Rule::in(Pago::METODOS_PAGO)],
        ]);

        // Validar monedas del ticket completo antes de entrar a la transacción
        $hasBs = false;
        $hasUsd = false;
        foreach ($request->lines as $line) {
            $lineBs = (float) ($line['amount_bs'] ?? 0);
            $lineUsd = (float) ($line['amount_usd'] ?? 0);
            if ($lineBs > 0) {
                $hasBs = true;
            }
            if ($lineUsd > 0) {
                $hasUsd = true;
            }
            if ($lineBs > 0 && $lineUsd > 0) {
                $hasBs = true;
                $hasUsd = true;
                break;
            }
        }

        if ($hasBs || $hasUsd) {
            $monedas = $this->apuestaService->getEffectiveMonedas($user->taquilla_id);
            if ($hasUsd && ! $monedas['usd']) {
                return response()->json([
                    'message' => 'Moneda USD no permitida para esta taquilla.',
                ], 422);
            }
            if ($hasBs && ! $monedas['bs']) {
                return response()->json([
                    'message' => 'Moneda BS no permitida para esta taquilla.',
                ], 422);
            }
            if ($hasBs && $hasUsd && (! $monedas['bs'] || ! $monedas['usd'])) {
                return response()->json([
                    'message' => 'Ambas monedas deben estar habilitadas para tickets mixtos.',
                ], 422);
            }
        }

        try {
            $ticket = DB::transaction(function () use ($request, $user) {
                $ticket = Ticket::create([
                    'taquilla_id' => $user->taquilla_id,
                    'total_bs' => 0,
                    'total_usd' => 0,
                    'estado' => 'pendiente',
                ]);

                $totalBs = 0;
                $totalUsd = 0;

                foreach ($request->lines as $line) {
                    $line['metodo_pago'] = $request->input('metodo_pago');

                    $apuesta = $this->apuestaService->createApuesta(
                        $line,
                        $user->taquilla_id,
                        $user->id,
                        $ticket->id
                    );

                    $totalBs += (float) ($line['amount_bs'] ?? 0);
                    $totalUsd += (float) ($line['amount_usd'] ?? 0);
                }

                $ticket->update([
                    'total_bs' => $totalBs,
                    'total_usd' => $totalUsd,
                ]);

                return $ticket;
            });

            return response()->json([
                'message' => 'Ticket creado exitosamente.',
                'data' => $ticket->load(['apuestas.juego', 'taquilla']),
            ], 201);

        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error interno al procesar el ticket.',
            ], 500);
        }
    }

    /**
     * Anular un ticket pre-sorteo y registrar su devolución (WU3-A).
     *
     * Toda la regla de negocio (estado, ventana, ownership por línea) y la
     * transacción viven en `TicketAnulacionService`; el controller solo valida
     * el body opcional y mapea la excepción de dominio a HTTP.
     */
    public function destroy(Request $request, Ticket $ticket)
    {
        $request->validate([
            'metodo_pago' => ['nullable', Rule::in(Pago::METODOS_PAGO)],
            'referencia' => 'nullable|string|max:255',
            'motivo' => 'nullable|string|max:255',
        ]);

        try {
            $resultado = $this->ticketAnulacionService->anularTicket(
                $ticket,
                $request->user(),
                [
                    'metodo_pago' => $request->input('metodo_pago'),
                    'referencia' => $request->input('referencia'),
                    'motivo' => $request->input('motivo'),
                    'ip' => $request->ip(),
                    'user_agent' => $request->header('User-Agent'),
                ]
            );
        } catch (TicketAnulacionException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Ticket anulado correctamente.',
            'data' => [
                'ticket_id' => $resultado['ticket_id'],
                'apuestas' => $resultado['apuestas_count'],
                'devoluciones' => $resultado['devoluciones'],
                'total_bs' => $resultado['total_bs'],
                'total_usd' => $resultado['total_usd'],
            ],
        ]);
    }

    public function ganadores(Request $request)
    {
        $request->validate(['fecha' => 'required|date_format:Y-m-d']);

        $user = $request->user();
        $this->autorizarScope($user);
        $fecha = $request->fecha;

        $resultados = Resultado::with('juego')
            ->whereDate('fecha_sorteo', $fecha)
            ->get();

        if ($resultados->isEmpty()) {
            return response()->json(['data' => [], 'message' => 'No hay resultados para esta fecha.']);
        }

        $ganadores = [];
        $pluginManager = app(JuegoPluginManager::class);

        foreach ($resultados as $resultado) {
            $horaSorteo = $resultado->hora_sorteo;
            if ($horaSorteo && preg_match('/AM|PM/i', $horaSorteo)) {
                $horaSorteo = Carbon::createFromFormat('h:i A', trim($horaSorteo))->format('H:i:s');
            }

            $apuestasQuery = Apuesta::with(['ticket', 'juego'])
                ->where('juego_id', $resultado->juego_id)
                ->whereIn('estado', ['pendiente', 'ganadora'])
                ->whereDate('sorteo_hora', $fecha)
                ->whereTime('sorteo_hora', $horaSorteo);

            if ($user->hasRole('taquilla')) {
                $apuestasQuery->where('taquilla_id', $user->taquilla_id);
            } elseif ($user->hasRole('grupo')) {
                $apuestasQuery->whereHas('taquilla.grupo', fn ($q) => $q->where('grupo_id', $user->grupo_id));
            } elseif ($user->hasRole('banca')) {
                $apuestasQuery->whereHas('taquilla.grupo.banca', fn ($q) => $q->where('banca_id', $user->banca_id));
            } elseif ($user->hasRole('master')) {
                $user->masterBancaChainScope()($apuestasQuery);
            }

            $apuestas = $apuestasQuery->get();

            foreach ($apuestas as $apuesta) {
                $combinacion = $apuesta->combinacion;
                if (is_string($combinacion)) {
                    $combinacion = json_decode($combinacion, true);
                }

                // N7/REQ1: el premio lo decide el MOTOR (manager → PremiosEngine),
                // nunca el plugin directo (no normaliza acentos ni aplica comodines).
                $premio = $pluginManager->calcularPremio(
                    $resultado->juego,
                    ['combinacion' => $combinacion ?? [], 'amount_bs' => (float) $apuesta->amount_bs, 'amount_usd' => (float) $apuesta->amount_usd],
                    ['numeros_ganadores' => $resultado->numeros_ganadores]
                );

                if (($premio['premio_bs'] ?? 0) > 0 || ($premio['premio_usd'] ?? 0) > 0) {
                    $ticketCode = $apuesta->ticket?->ticket_code ?? $apuesta->ticket_code;
                    $ticketId = $apuesta->ticket_id;

                    if (! isset($ganadores[$ticketCode])) {
                        $ganadores[$ticketCode] = [
                            'ticket_code' => $ticketCode,
                            'ticket_id' => $ticketId,
                            'juego' => $apuesta->juego?->name,
                            'fecha_sorteo' => $fecha,
                            'jugadas_ganadoras' => 0,
                            'premio_total_bs' => 0,
                            'premio_total_usd' => 0,
                            'jugadas' => [],
                        ];
                    }

                    $ganadores[$ticketCode]['jugadas_ganadoras']++;
                    $ganadores[$ticketCode]['premio_total_bs'] += $premio['premio_bs'] ?? 0;
                    $ganadores[$ticketCode]['premio_total_usd'] += $premio['premio_usd'] ?? 0;
                    $ganadores[$ticketCode]['jugadas'][] = [
                        'apuesta_id' => $apuesta->id,
                        'combinacion' => $combinacion,
                        'amount_bs' => (float) $apuesta->amount_bs,
                        'amount_usd' => (float) $apuesta->amount_usd,
                        'total_bs_equivalent' => (float) $apuesta->total_bs_equivalent,
                        'sorteo_hora' => $apuesta->sorteo_hora?->format('Y-m-d H:i:s'),
                        'premio_bs' => $premio['premio_bs'] ?? 0,
                        'premio_usd' => $premio['premio_usd'] ?? 0,
                        'multiplicador' => $pluginManager->getMultiplicador($resultado->juego),
                        'estado' => $apuesta->estado,
                    ];
                }
            }
        }

        // Tripleta multi-sorteo: no pertenece a un único `sorteo_hora`. Se
        // agrega con el premio ya persistido al liquidar (evita recalcular y
        // duplicar). La evidencia vive en `resultado_ids`.
        $multiQuery = Apuesta::with(['ticket', 'juego', 'detalles'])
            ->where('estado', 'ganadora')
            ->whereDate('sorteo_hora', $fecha)
            ->whereNotNull('resultado_ids');

        if ($user->hasRole('taquilla')) {
            $multiQuery->where('taquilla_id', $user->taquilla_id);
        } elseif ($user->hasRole('grupo')) {
            $multiQuery->whereHas('taquilla.grupo', fn ($q) => $q->where('grupo_id', $user->grupo_id));
        } elseif ($user->hasRole('banca')) {
            $multiQuery->whereHas('taquilla.grupo.banca', fn ($q) => $q->where('banca_id', $user->banca_id));
        } elseif ($user->hasRole('master')) {
            $user->masterBancaChainScope()($multiQuery);
        }

        foreach ($multiQuery->get() as $apuesta) {
            $combinacion = $apuesta->combinacion;
            if (is_string($combinacion)) {
                $combinacion = json_decode($combinacion, true);
            }

            if (! $this->esMultiSorteo($combinacion ?? [])) {
                continue;
            }

            $detalle = $apuesta->detalles->first();
            $premioBs = (float) ($detalle?->premio_ganado ?? 0);
            $premioUsd = (float) ($detalle?->premio_ganado_usd ?? 0);

            if ($premioBs <= 0 && $premioUsd <= 0) {
                continue;
            }

            $ticketCode = $apuesta->ticket?->ticket_code ?? $apuesta->ticket_code;
            $ticketId = $apuesta->ticket_id;

            if (! isset($ganadores[$ticketCode])) {
                $ganadores[$ticketCode] = [
                    'ticket_code' => $ticketCode,
                    'ticket_id' => $ticketId,
                    'juego' => $apuesta->juego?->name,
                    'fecha_sorteo' => $fecha,
                    'jugadas_ganadoras' => 0,
                    'premio_total_bs' => 0,
                    'premio_total_usd' => 0,
                    'jugadas' => [],
                ];
            }

            $ganadores[$ticketCode]['jugadas_ganadoras']++;
            $ganadores[$ticketCode]['premio_total_bs'] += $premioBs;
            $ganadores[$ticketCode]['premio_total_usd'] += $premioUsd;
            $ganadores[$ticketCode]['jugadas'][] = [
                'apuesta_id' => $apuesta->id,
                'combinacion' => $combinacion,
                'amount_bs' => (float) $apuesta->amount_bs,
                'amount_usd' => (float) $apuesta->amount_usd,
                'total_bs_equivalent' => (float) $apuesta->total_bs_equivalent,
                'sorteo_hora' => $apuesta->sorteo_hora?->format('Y-m-d H:i:s'),
                'premio_bs' => $premioBs,
                'premio_usd' => $premioUsd,
                'multiplicador' => (new PremiosEngine)->multiplicadorPara($apuesta->juego, 'tripleta'),
                'estado' => $apuesta->estado,
            ];
        }

        return response()->json(['data' => array_values($ganadores)]);
    }

    /**
     * Fail-closed del scoping por rol (REQ-4): un usuario sin rol Spatie, o
     * con un rol fuera de catálogo, no tiene scope válido y se deniega.
     *
     * `master` ya tiene rama de scoping (#84, caso 4); `agencia` no está en el
     * middleware de `/tickets` y permanece fuera del contrato.
     */
    private function autorizarScope(User $user): void
    {
        if (! $user->hasAnyRole(['super_master', 'master', 'agencia', 'grupo', 'banca', 'taquilla'])) {
            abort(403, 'No autorizado.');
        }
    }

    /**
     * @param  array<string, mixed>  $combinacion
     */
    private function esMultiSorteo(array $combinacion): bool
    {
        $selecciones = $combinacion['selecciones'] ?? [];

        return count($selecciones) >= 3
            && (($combinacion['modalidad'] ?? 'tripleta') === 'tripleta');
    }
}
