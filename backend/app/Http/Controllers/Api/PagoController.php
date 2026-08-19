<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apuesta;
use App\Models\Log;
use App\Models\Pago;
use App\Models\Resultado;
use App\Models\Ticket;
use App\Services\JuegoPluginManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PagoController extends Controller
{
    /**
     * Registrar un pago de premio
     */
    public function store(Request $request)
    {
        // Validar input
        $validator = Validator::make($request->all(), [
            'apuesta_id' => 'required|exists:apuestas,id',
            'amount_bs' => 'nullable|numeric|min:0',
            'amount_usd' => 'nullable|numeric|min:0',
            'tipo' => 'required|in:ingreso,egreso,devolucion',
            'moneda' => 'required|in:bs,usd,mixto',
            'referencia' => 'nullable|string|max:255',
            'concepto' => 'nullable|string|max:255',
        ], [
            'apuesta_id.required' => 'El ID de la apuesta es obligatorio.',
            'apuesta_id.exists' => 'La apuesta no existe.',
            'tipo.required' => 'Debes especificar el tipo de pago.',
            'tipo.in' => 'El tipo debe ser ingreso, egreso o devolucion.',
            'moneda.required' => 'Debes especificar la moneda del pago.',
            'moneda.in' => 'La moneda debe ser bs, usd o mixto.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $apuestaId = $request->input('apuesta_id');

        // Buscar apuesta
        $apuesta = Apuesta::with(['juego', 'resultado'])->find($apuestaId);

        if (! $apuesta) {
            return response()->json([
                'success' => false,
                'message' => 'Apuesta no encontrada.',
            ], 404);
        }

        // Verificar ownership
        if ($user->role === 'taquilla' && $apuesta->taquilla_id !== $user->taquilla_id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        // Verificar que la apuesta esté pendiente
        if ($apuesta->estado !== 'pendiente') {
            return response()->json([
                'success' => false,
                'message' => "No se puede pagar una apuesta {$apuesta->estado}.",
            ], 422);
        }

        // Si es egreso, validar que la apuesta tenga resultado
        if ($request->tipo === 'egreso') {
            if (! $apuesta->resultado_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede pagar un premio sin un resultado asignado a la apuesta.',
                ], 422);
            }

            $resultado = Resultado::find($apuesta->resultado_id);
            $premio = $this->calcularPremio($apuesta, $resultado);

            if ($premio['premio_bs'] == 0 && $premio['premio_usd'] == 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'La apuesta no resultó ganadora. No puede pagarse un premio.',
                ], 422);
            }

            $amountBsRequest = (float) ($request->amount_bs ?? 0);
            $amountUsdRequest = (float) ($request->amount_usd ?? 0);

            $diffBs = abs($amountBsRequest - $premio['premio_bs']);
            $diffUsd = abs($amountUsdRequest - $premio['premio_usd']);

            if ($diffBs > 0.01 || $diffUsd > 0.01) {
                return response()->json([
                    'success' => false,
                    'message' => 'El monto del premio no coincide con lo calculado.',
                    'premio_esperado_bs' => $premio['premio_bs'],
                    'premio_esperado_usd' => $premio['premio_usd'],
                    'monto_enviado_bs' => $amountBsRequest,
                    'monto_enviado_usd' => $amountUsdRequest,
                ], 422);
            }
        }

        // Guardar pago
        $pago = Pago::create([
            'taquilla_id' => $apuesta->taquilla_id,
            'apuesta_id' => $apuestaId,
            'amount_bs' => $request->amount_bs ?? 0,
            'amount_usd' => $request->amount_usd ?? 0,
            'exchange_rate_applied' => $apuesta->exchange_rate_applied,
            'tipo' => $request->tipo,
            'moneda' => $request->moneda,
            'concepto' => $request->concepto ?? 'Pago de premio',
            'referencia' => $request->referencia,
            'created_by' => $user->id,
        ]);

        // Actualizar estado de la apuesta
        $apuesta->update(['estado' => 'pagada']);

        // Actualizar premio_ganado en detalle_apuestas
        $detalle = $apuesta->detalles()->first();
        if ($detalle) {
            $detalle->update([
                'premio_ganado' => $request->amount_bs ?? 0,
                'premio_ganado_usd' => $request->amount_usd ?? 0,
            ]);
        }

        // Cascada al ticket: si todas las jugadas estan resueltas, marcar pagada
        if ($apuesta->ticket_id) {
            $ticket = Ticket::with('apuestas')->find($apuesta->ticket_id);
            if ($ticket && $ticket->estado !== 'pagada') {
                $todasResueltas = $ticket->apuestas->every(function ($a) {
                    return $a->estado === 'pagada' || $a->estado === 'anulada' || $a->estado === 'perdida' || $a->trashed();
                });
                if ($todasResueltas) {
                    $ticket->update(['estado' => 'pagada']);
                }
            }
        }

        // Registrar log de auditoría
        Log::create([
            'user_id' => $user->id,
            'action' => 'pago_premio',
            'details' => json_encode([
                'apuesta_id' => $apuestaId,
                'ticket_code' => $apuesta->ticket_code,
                'animal' => $apuesta->combinacion['animal'] ?? 'N/A',
                'premio_bs' => $request->amount_bs ?? 0,
                'premio_usd' => $request->amount_usd ?? 0,
                'tipo' => $request->tipo,
            ]),
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pago registrado exitosamente.',
            'data' => $pago->load(['apuesta', 'creador']),
        ], 201);
    }

    /**
     * Mostrar pagos de una apuesta específica
     */
    public function showByApuesta(Apuesta $apuesta)
    {
        $this->authorize('view', $apuesta);

        $pagos = Pago::where('apuesta_id', $apuesta->id)->get();

        return response()->json([
            'success' => true,
            'data' => $pagos,
        ]);
    }

    /**
     * Calcular premio usando el plugin del juego
     */
    private function calcularPremio(Apuesta $apuesta, ?Resultado $resultado): array
    {
        $plugin = app(JuegoPluginManager::class)->getPlugin($apuesta->juego);

        if (! $plugin) {
            return ['premio_bs' => 0, 'premio_usd' => 0];
        }

        $combinacion = is_string($apuesta->combinacion)
            ? json_decode($apuesta->combinacion, true)
            : $apuesta->combinacion;

        $resultados = $resultado ? $resultado->toArray() : [];

        return $plugin->calcularPremio(
            [
                'combinacion' => $combinacion,
                'total_bs_equivalent' => $apuesta->total_bs_equivalent,
                'monto' => $apuesta->total_bs_equivalent,
                'amount_bs' => $apuesta->amount_bs,
                'amount_usd' => $apuesta->amount_usd,
            ],
            $resultados
        );
    }
}
