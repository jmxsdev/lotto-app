<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\Apuesta;
use App\Services\ApuestaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $query = Ticket::with(['apuestas.juego', 'taquilla'])
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

        return response()->json(['data' => $tickets]);
    }

    public function show(Ticket $ticket)
    {
        return response()->json([
            'data' => $ticket->load(['apuestas.juego', 'apuestas.detalles', 'taquilla']),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user->taquilla_id) {
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
        ]);

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

        if ($user->role === 'taquilla' && $ticket->taquilla_id !== $user->taquilla_id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if ($ticket->estado !== 'pendiente') {
            return response()->json(['message' => 'Solo se pueden anular tickets pendientes.'], 422);
        }

        $oldestApuesta = $ticket->apuestas()->orderBy('created_at')->first();
        if ($oldestApuesta) {
            $diffMinutes = now()->diffInMinutes($oldestApuesta->created_at);
            if ($diffMinutes >= 5) {
                return response()->json(['message' => 'El ticket excedio los 5 minutos para ser anulado.'], 422);
            }
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
            $apuestas = Apuesta::with(['ticket', 'juego'])
                ->where('juego_id', $resultado->juego_id)
                ->where('estado', 'pendiente')
                ->whereDate('sorteo_hora', $fecha)
                ->get();

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
                        'premio_bs' => $premio['premio_bs'] ?? 0,
                        'premio_usd' => $premio['premio_usd'] ?? 0,
                        'estado' => $apuesta->estado,
                    ];
                }
            }
        }

        return response()->json(['data' => array_values($ganadores)]);
    }
}
