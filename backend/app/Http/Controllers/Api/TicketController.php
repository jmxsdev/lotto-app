<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\Apuesta;
use App\Services\ApuestaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TicketController extends Controller
{
    protected ApuestaService $apuestaService;

    public function __construct(ApuestaService $apuestaService)
    {
        $this->apuestaService = $apuestaService;
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Ticket::with(['apuestas.juego', 'apuestas.detalles', 'taquilla'])
            ->withCount(['apuestas as ganadoras_count' => function ($q) {
                $q->whereHas('detalles', function ($q2) {
                    $q2->whereNotNull('premio_ganado')
                       ->orWhereNotNull('premio_ganado_usd');
                });
            }])
            ->latest();

        if ($user->role === 'taquilla') {
            $query->where('taquilla_id', $user->taquilla_id);
        } elseif ($user->role === 'grupo') {
            $query->whereHas('taquilla.grupo', function ($q) use ($user) {
                $q->where('grupo_id', $user->grupo_id);
            });
        } elseif ($user->role === 'banca') {
            $query->whereHas('taquilla.grupo.banca', function ($q) use ($user) {
                $q->where('banca_id', $user->banca_id);
            });
        }

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->has('ticket_code')) {
            $query->where('ticket_code', 'like', '%' . $request->ticket_code . '%');
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
            return $ticket;
        });

        return response()->json(['data' => $tickets]);
    }

    public function show(Request $request, Ticket $ticket)
    {
        $user = $request->user();

        if ($user->role === 'taquilla' && $ticket->taquilla_id !== $user->taquilla_id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        } elseif ($user->role === 'grupo' && $ticket->taquilla?->grupo_id !== $user->grupo_id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        } elseif ($user->role === 'banca' && $ticket->taquilla?->grupo?->banca_id !== $user->banca_id) {
            return response()->json(['message' => 'No autorizado.'], 403);
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

        return response()->json(['data' => $ticket]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user->taquilla_id) {
            return response()->json([
                'message' => 'Solo las agencias pueden crear tickets.',
            ], 403);
        }

        $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.juego_id' => 'required|integer|exists:juegos,id',
            'lines.*.amount_bs' => 'numeric|min:0',
            'lines.*.amount_usd' => 'numeric|min:0',
            'lines.*.combinacion' => 'nullable|array',
        ]);

        // Validar monedas del ticket completo antes de entrar a la transacción
        $hasBs = false;
        $hasUsd = false;
        foreach ($request->lines as $line) {
            $lineBs = (float) ($line['amount_bs'] ?? 0);
            $lineUsd = (float) ($line['amount_usd'] ?? 0);
            if ($lineBs > 0) $hasBs = true;
            if ($lineUsd > 0) $hasUsd = true;
            if ($lineBs > 0 && $lineUsd > 0) {
                $hasBs = true;
                $hasUsd = true;
                break;
            }
        }

        if ($hasBs || $hasUsd) {
            $monedas = $this->apuestaService->getEffectiveMonedas($user->taquilla_id);
            if ($hasUsd && !$monedas['usd']) {
                return response()->json([
                    'message' => 'Moneda USD no permitida para esta agencia.',
                ], 422);
            }
            if ($hasBs && !$monedas['bs']) {
                return response()->json([
                    'message' => 'Moneda BS no permitida para esta agencia.',
                ], 422);
            }
            if ($hasBs && $hasUsd && (!$monedas['bs'] || !$monedas['usd'])) {
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

    public function destroy(Request $request, Ticket $ticket)
    {
        $user = $request->user();

        if ($ticket->estado !== 'pendiente') {
            return response()->json(['message' => 'Solo se pueden anular tickets pendientes.'], 422);
        }

        $tiempoEliminacion = $this->apuestaService
            ->getEffectiveTiempoEliminacion($ticket->taquilla_id);

        $rechazada = null;
        foreach ($ticket->apuestas as $apuesta) {
            if (Gate::denies('delete', $apuesta)) {
                if ($apuesta->created_at->diffInMinutes(now()) >= $tiempoEliminacion) {
                    $rechazada = "El ticket excedió los {$tiempoEliminacion} minutos para ser anulado.";
                } elseif ($apuesta->sorteo_hora && $apuesta->sorteo_hora->isPast()) {
                    $rechazada = 'El sorteo de una o mas jugadas ya ocurrio, no se puede anular.';
                } else {
                    $rechazada = 'No se puede anular el ticket.';
                }
                break;
            }
        }

        if ($rechazada) {
            return response()->json(['message' => $rechazada], 422);
        }

        DB::transaction(function () use ($ticket, $user) {
            foreach ($ticket->apuestas as $apuesta) {
                $apuesta->delete();
            }
            $ticket->update(['estado' => 'anulada']);
            $ticket->delete();
        });

        return response()->json(['message' => 'Ticket anulado correctamente.']);
    }

    public function ganadores(Request $request)
    {
        $request->validate(['fecha' => 'required|date_format:Y-m-d']);

        $user = $request->user();
        $fecha = $request->fecha;

        $resultados = \App\Models\Resultado::with('juego')
            ->whereDate('fecha_sorteo', $fecha)
            ->get();

        if ($resultados->isEmpty()) {
            return response()->json(['data' => [], 'message' => 'No hay resultados para esta fecha.']);
        }

        $ganadores = [];
        $pluginManager = app(\App\Services\JuegoPluginManager::class);

        foreach ($resultados as $resultado) {
            $apuestasQuery = Apuesta::with(['ticket', 'juego'])
                ->where('juego_id', $resultado->juego_id)
                ->where('estado', 'pendiente')
                ->whereDate('sorteo_hora', $fecha);

            if ($user->role === 'taquilla') {
                $apuestasQuery->where('taquilla_id', $user->taquilla_id);
            } elseif ($user->role === 'grupo') {
                $apuestasQuery->whereHas('taquilla.grupo', fn($q) => $q->where('grupo_id', $user->grupo_id));
            } elseif ($user->role === 'banca') {
                $apuestasQuery->whereHas('taquilla.grupo.banca', fn($q) => $q->where('banca_id', $user->banca_id));
            }

            $apuestas = $apuestasQuery->get();

            $plugin = $pluginManager->getPlugin($resultado->juego);

            foreach ($apuestas as $apuesta) {
                $combinacion = $apuesta->combinacion;
                if (is_string($combinacion)) {
                    $combinacion = json_decode($combinacion, true);
                }

                $premio = $plugin
                    ? $plugin->calcularPremio(
                        ['combinacion' => $combinacion ?? [], 'amount_bs' => (float) $apuesta->amount_bs, 'amount_usd' => (float) $apuesta->amount_usd],
                        ['numeros_ganadores' => $resultado->numeros_ganadores]
                    )
                    : ['premio_bs' => 0, 'premio_usd' => 0];

                if (($premio['premio_bs'] ?? 0) > 0 || ($premio['premio_usd'] ?? 0) > 0) {
                    $ticketCode = $apuesta->ticket?->ticket_code ?? $apuesta->ticket_code;
                    $ticketId = $apuesta->ticket_id;

                    if (!isset($ganadores[$ticketCode])) {
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
                        'multiplicador' => $plugin ? $plugin->obtenerMultiplicador() : 0,
                        'estado' => $apuesta->estado,
                    ];
                }
            }
        }

        return response()->json(['data' => array_values($ganadores)]);
    }
}
