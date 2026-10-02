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
use Illuminate\Validation\Rule;

class PagoController extends Controller
{
    /**
     * Registrar un pago de premio.
     *
     * Backend AUTORITATIVO (WU pago-premio): en `egreso` los montos son
     * OPCIONALES — sin montos se aplica el premio calculado por el motor
     * (`config.premios`); con montos se mantiene la validacion +-0.01.
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
            'metodo_pago' => ['nullable', Rule::in(Pago::METODOS_PAGO)],
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
        $apuesta = Apuesta::with(['juego', 'resultado', 'detalles'])->find($apuestaId);

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

        // Verificar estado pagable (D5/N11): `ganadora` (nuevo estado) o
        // `pendiente` legacy con resultado_id ya liquidada. El egreso sin
        // resultado se rechaza más abajo; la devolución de una `pendiente`
        // sin resultado sigue permitida.
        if (! in_array($apuesta->estado, ['pendiente', 'ganadora'], true)) {
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

            // Backend AUTORITATIVO (WU pago-premio): los montos son OPCIONALES.
            // - Sin monto -> se aplica el premio calculado por el MOTOR (la
            //   taquilla no calcula ni confirma nada).
            // - Con monto -> se mantiene la validacion +-0.01 (compatibilidad).
            $amountBsRequest = $request->filled('amount_bs') ? (float) $request->amount_bs : null;
            $amountUsdRequest = $request->filled('amount_usd') ? (float) $request->amount_usd : null;

            $diffBs = $amountBsRequest === null ? 0.0 : abs($amountBsRequest - $premio['premio_bs']);
            $diffUsd = $amountUsdRequest === null ? 0.0 : abs($amountUsdRequest - $premio['premio_usd']);

            if ($diffBs > 0.01 || $diffUsd > 0.01) {
                return response()->json([
                    'success' => false,
                    'message' => 'El monto del premio no coincide con lo calculado.',
                    'premio_esperado_bs' => $premio['premio_bs'],
                    'premio_esperado_usd' => $premio['premio_usd'],
                    'monto_enviado_bs' => $amountBsRequest,
                    'monto_enviado_usd' => $amountUsdRequest,
                    'sugerencia' => 'Omite amount_bs/amount_usd: el backend aplica el premio calculado.',
                ], 422);
            }

            // Fija los montos aplicados (los del request o los del motor).
            $request->merge([
                'amount_bs' => $amountBsRequest ?? $premio['premio_bs'],
                'amount_usd' => $amountUsdRequest ?? $premio['premio_usd'],
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
            'moneda' => $request->moneda,
            'metodo_pago' => Pago::resolverMetodoPago(
                $request->input('metodo_pago'),
                $request->moneda,
                (float) ($request->amount_usd ?? 0)
            ),
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
                // D5: la cascada suma `vencido` a "resuelta"; `ganadora`
                // (impaga) NO resuelve el ticket.
                $todasResueltas = $ticket->apuestas->every(function ($a) {
                    return $a->estado === 'pagada' || $a->estado === 'anulada'
                        || $a->estado === 'perdida' || $a->estado === 'vencido'
                        || $a->trashed();
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

        $response = [
            'success' => true,
            'message' => 'Pago registrado exitosamente.',
            'data' => $pago->load(['apuesta', 'creador']),
        ];

        // WU pago-premio: devuelve el premio aplicado (request o motor).
        if ($request->tipo === 'egreso') {
            $response['premio'] = [
                'premio_bs' => (float) $request->amount_bs,
                'premio_usd' => (float) $request->amount_usd,
            ];
        }

        return response()->json($response, 201);
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
     * Calcular premio con el MOTOR corregido (REQ10/N11): config-driven,
     * acentos, terminales y comodines. El manager delega en PremiosEngine;
     * nunca se usa el plugin directo (que no normaliza ni lee config).
     * S2/D4: con snapshot persistido al vender, se valida contra él
     * (override); null → config.premios actual (fallback legacy).
     */
    private function calcularPremio(Apuesta $apuesta, ?Resultado $resultado): array
    {
        $combinacion = is_string($apuesta->combinacion)
            ? json_decode($apuesta->combinacion, true)
            : $apuesta->combinacion;

        $resultados = $resultado ? $resultado->toArray() : [];

        // Guard null-safe (verify SUGGESTION#1): la invariante vigente es UN
        // detalle por apuesta (createApuesta). Sin detalle — o con el detalle
        // sin snapshot — la resolucion cae al config.premios actual (fallback
        // legacy) y el flujo de pago sigue. Multi-detalle futuro: se usa el
        // primero, documentado aqui para cuando exista multi-seleccion.
        $detalle = $apuesta->detalles->first();
        $premiosSnapshot = $detalle?->premios_snapshot;

        return app(JuegoPluginManager::class)->calcularPremio(
            $apuesta->juego,
            [
                'combinacion' => $combinacion,
                'amount_bs' => (float) $apuesta->amount_bs,
                'amount_usd' => (float) $apuesta->amount_usd,
            ],
            $resultados,
            $premiosSnapshot
        );
    }
}
