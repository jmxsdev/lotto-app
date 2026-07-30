<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
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
}
