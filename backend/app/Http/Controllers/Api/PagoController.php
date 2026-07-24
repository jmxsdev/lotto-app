<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apuesta;
use App\Models\Pago;
use App\Models\Resultado;
use App\Models\Log;
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

        // Calcular premio posible usando el plugin Animalitos
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
     * Calcular premio posible basado en el plugin Animalitos y resultado
     */
    private function calcularPremioPosible(Apuesta $apuesta, ?Resultado $resultado): float
    {
        // Si hay resultado y coincide con la apuesta, calcular premio
        if ($resultado && $apuesta->combinacion) {
            $combinacion = is_string($apuesta->combinacion) 
                ? json_decode($apuesta->combinacion, true) 
                : $apuesta->combinacion;

            $animalApostado = $combinacion['animal'] ?? null;
            $numeroApostado = $combinacion['numero'] ?? null;

            // Verificar si el animal apostado coincide con el resultado
            // Los resultados pueden estar en numeros_ganadores como array o string
            $resultados = is_array($resultado->numeros_ganadores) 
                ? $resultado->numeros_ganadores 
                : json_decode($resultado->numeros_ganadores, true);

            $coincidio = false;
            
            if (is_array($resultados)) {
                foreach ($resultados as $num) {
                    if ($num == $numeroApostado || $num == $animalApostado) {
                        $coincidio = true;
                        break;
                    }
                }
            } elseif (is_string($resultados)) {
                // Formato: "5" o "5,10,15"
                $nums = array_map('trim', explode(',', $resultados));
                if (in_array((string)$numeroApostado, $nums)) {
                    $coincidio = true;
                }
            }

            if ($coincidio) {
                // Multiplicador del juego (default 30x para Animalitos)
                $multiplicador = $apuesta->juego->config['premio_multiplo'] ?? 30;
                
                return $apuesta->total_bs_equivalent * $multiplicador;
            }
        }

        // Si no hay resultado o no coincidió, retornar 0
        return 0;
    }
}
