<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apuesta;
use App\Models\Pago;
use App\Models\Resultado;
use App\Models\Log;
use App\Models\ExchangeRate;
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
            'tipo' => 'required|in:bs,usd,mixto',
            'referencia' => 'nullable|string|max:255',
            'concepto' => 'nullable|string|max:255',
        ], [
            'apuesta_id.required' => 'El ID de la apuesta es obligatorio.',
            'apuesta_id.exists' => 'La apuesta no existe.',
            'tipo.required' => 'Debes especificar el tipo de pago.',
            'tipo.in' => 'El tipo debe ser bs, usd o mixto.',
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
        
        if (!$apuesta) {
            return response()->json([
                'success' => false,
                'message' => 'Apuesta no encontrada.',
            ], 404);
        }

        // Verificar que la apuesta esté pendiente
        if ($apuesta->estado !== 'pendiente') {
            return response()->json([
                'success' => false,
                'message' => "No se puede pagar una apuesta {$apuesta->estado}.",
            ], 422);
        }

        // Obtener resultado ganador para calcular premio si es necesario
        $resultado = null;
        if ($apuesta->resultado_id) {
            $resultado = Resultado::find($apuesta->resultado_id);
        }

        // Calcular premio posible usando el plugin del juego
        $premioPosible = $this->calcularPremioPosible($apuesta, $resultado);

        // Validar que el monto pagado sea razonable (no menor al premio posible)
        $montoTotalPagado = $request->amount_bs ?? 0;
        if ($request->amount_usd > 0) {
            $tasaActiva = \App\Models\ExchangeRate::where('is_active', true)->first();
            if ($tasaActiva) {
                $montoTotalPagado += ($request->amount_usd * $tasaActiva->rate);
            }
        }

        // Si el monto pagado es menor al premio posible, advertir pero permitir
        if ($montoTotalPagado < $premioPosible && $premioPosible > 0) {
            // Permitir pero registrar advertencia
            Log::create([
                'user_id' => $user->id,
                'action' => 'pago_premio_advertencia',
                'details' => json_encode([
                    'apuesta_id' => $apuestaId,
                    'premio_posible' => $premioPosible,
                    'monto_pagado' => $montoTotalPagado,
                    'usuario' => $user->email,
                ]),
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
            ]);
        }

        // Guardar pago
        $pago = Pago::create([
            'taquilla_id' => $apuesta->taquilla_id,
            'apuesta_id' => $apuestaId,
            'amount_bs' => $request->amount_bs ?? 0,
            'amount_usd' => $request->amount_usd ?? 0,
            'exchange_rate_applied' => $apuesta->exchange_rate_applied,
            'tipo' => $request->tipo,
            'concepto' => $request->concepto ?? 'Pago de premio',
            'referencia' => $request->referencia,
            'created_by' => $user->id,
        ]);

        // Actualizar estado de la apuesta
        $apuesta->update(['estado' => 'pagada']);

        // Registrar log de auditoría
        Log::create([
            'user_id' => $user->id,
            'action' => 'pago_premio',
            'details' => json_encode([
                'apuesta_id' => $apuestaId,
                'ticket_code' => $apuesta->ticket_code,
                'animal' => $apuesta->combinacion['animal'] ?? 'N/A',
                'premio_posible' => $premioPosible,
                'monto_bs' => $request->amount_bs ?? 0,
                'monto_usd' => $request->amount_usd ?? 0,
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
        $pagos = Pago::where('apuesta_id', $apuesta->id)->get();

        return response()->json([
            'success' => true,
            'data' => $pagos,
        ]);
    }

    /**
     * Calcular premio posible usando el plugin del juego
     */
    private function calcularPremioPosible(Apuesta $apuesta, ?Resultado $resultado): float
    {
        $plugin = app(JuegoPluginManager::class)->getPlugin($apuesta->juego);

        if (!$plugin) {
            return 0;
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
            ],
            $resultados
        );
    }
}
