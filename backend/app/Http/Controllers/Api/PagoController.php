<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apuesta;
use App\Models\Log;
use App\Models\Pago;
use App\Models\Ticket;
use App\Services\PremiosLiquidador;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PagoController extends Controller
{
    public function __construct(
        private readonly PremiosLiquidador $premios,
    ) {}

    /**
     * Registrar el pago de un premio (egreso).
     *
     * `POST /api/v1/pagos` es EGRESO-ONLY (D8): `ingreso` se registra
     * automaticamente al vender y `devolucion` se registra anulando el ticket,
     * por lo que ambos se rechazan con 422 ANTES de cargar la apuesta.
     *
     * El egreso corre en UNA transaccion con lock de fila (`for update`):
     * dentro del lock se re-verifican ownership, estado, resultado, premio del
     * MOTOR y tolerancia ±0.01. El backend es AUTORITATIVO (AD-3): los montos
     * persistidos en `pagos` y en `detalle_apuestas.premio_ganado(_usd)` son
     * los del motor; los montos del request solo alimentan la tolerancia.
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

        // AD-2: rechazo de tipos no-egreso inmediatamente despues de validar
        // el input. Deterministico: no depende del estado ni del ownership de
        // la apuesta, y no hay load, transaccion ni escritura.
        $tipo = (string) $request->input('tipo');

        if ($tipo !== 'egreso') {
            $mensaje = $tipo === 'ingreso'
                ? 'El ingreso se registra automáticamente al vender; no se admite por este endpoint.'
                : 'Las devoluciones se registran anulando el ticket: use DELETE /api/v1/tickets/{id}.';

            return response()->json([
                'success' => false,
                'message' => $mensaje,
            ], 422);
        }

        $user = $request->user();
        $apuestaId = $request->input('apuesta_id');

        $resultado = DB::transaction(function () use ($request, $user, $apuestaId) {
            // Lock ANTES de cualquier check dependiente del estado: no existe
            // ventana read-then-write para un doble egreso concurrente.
            $apuesta = Apuesta::with(['juego', 'resultado', 'detalles'])
                ->lockForUpdate()
                ->find($apuestaId);

            if (! $apuesta) {
                return $this->rechazo(404, [
                    'success' => false,
                    'message' => 'Apuesta no encontrada.',
                ]);
            }

            // Autorización jerárquica re-chequeada DENTRO del lock y ANTES del
            // estado: taquilla/grupo/banca/master solo su árbol; super_master
            // global. Un caller ajeno recibe 403 y no hay ventana de mutación.
            // Se normaliza el body al contrato JSON de la API ("No autorizado.").
            if (Gate::denies('view', $apuesta)) {
                return $this->rechazo(403, ['message' => 'No autorizado.']);
            }

            // Verificar estado pagable (D5/N11): `ganadora` (nuevo estado) o
            // `pendiente` legacy con resultado_id ya liquidada.
            if (! in_array($apuesta->estado, ['pendiente', 'ganadora'], true)) {
                return $this->rechazo(422, [
                    'success' => false,
                    'message' => "No se puede pagar una apuesta {$apuesta->estado}.",
                ]);
            }

            if (! $apuesta->resultado_id) {
                return $this->rechazo(422, [
                    'success' => false,
                    'message' => 'No se puede pagar un premio sin un resultado asignado a la apuesta.',
                ]);
            }

            $premio = $this->premios->calcular($apuesta, $apuesta->resultado);

            if ($premio['premio_bs'] == 0 && $premio['premio_usd'] == 0) {
                return $this->rechazo(422, [
                    'success' => false,
                    'message' => 'La apuesta no resultó ganadora. No puede pagarse un premio.',
                ]);
            }

            // Backend AUTORITATIVO (AD-3): los montos del request son
            // OPCIONALES y solo validan tolerancia ±0.01; lo que se persiste
            // es siempre el premio del MOTOR.
            $amountBsRequest = $request->filled('amount_bs') ? (float) $request->amount_bs : null;
            $amountUsdRequest = $request->filled('amount_usd') ? (float) $request->amount_usd : null;

            // Se redondea el delta a centavos para que el borde exacto ±0.01
            // no se rompa por representacion binaria (p.ej. 300.01 - 300.0).
            $diffBs = $amountBsRequest === null ? 0.0 : round(abs($amountBsRequest - $premio['premio_bs']), 2);
            $diffUsd = $amountUsdRequest === null ? 0.0 : round(abs($amountUsdRequest - $premio['premio_usd']), 2);

            if ($diffBs > 0.01 || $diffUsd > 0.01) {
                return $this->rechazo(422, [
                    'success' => false,
                    'message' => 'El monto del premio no coincide con lo calculado.',
                    'premio_esperado_bs' => $premio['premio_bs'],
                    'premio_esperado_usd' => $premio['premio_usd'],
                    'monto_enviado_bs' => $amountBsRequest,
                    'monto_enviado_usd' => $amountUsdRequest,
                    'sugerencia' => 'Omite amount_bs/amount_usd: el backend aplica el premio calculado.',
                ]);
            }

            // Guardar pago con los montos del motor (nunca los del request).
            $pago = Pago::create([
                'taquilla_id' => $apuesta->taquilla_id,
                'apuesta_id' => $apuesta->id,
                'amount_bs' => $premio['premio_bs'],
                'amount_usd' => $premio['premio_usd'],
                'exchange_rate_applied' => $apuesta->exchange_rate_applied,
                'tipo' => 'egreso',
                'moneda' => $request->moneda,
                'metodo_pago' => Pago::resolverMetodoPago(
                    $request->input('metodo_pago'),
                    $request->moneda,
                    $premio['premio_usd']
                ),
                'concepto' => $request->concepto ?? 'Pago de premio',
                'referencia' => $request->referencia,
                'created_by' => $user->id,
            ]);

            // Actualizar estado de la apuesta
            $apuesta->update(['estado' => 'pagada']);

            // Re-afirmar la evidencia del premio con los valores del motor.
            $detalle = $apuesta->detalles->first();
            if ($detalle) {
                $detalle->update([
                    'premio_ganado' => $premio['premio_bs'],
                    'premio_ganado_usd' => $premio['premio_usd'],
                ]);
            }

            // Cascada al ticket: si TODAS las lineas vivas estan resueltas,
            // marcar pagada. Se ignoran tickets pagados/anulados y trashed, y
            // una coleccion de lineas vivas vacia nunca cascadea (every() es
            // true sobre vacio).
            if ($apuesta->ticket_id) {
                $ticket = Ticket::with('apuestas')->find($apuesta->ticket_id);

                if ($ticket && ! in_array($ticket->estado, ['pagada', 'anulada'], true)) {
                    // La relacion `apuestas` aplica el scope de SoftDeletes:
                    // solo se evaluan lineas vivas.
                    $lineasVivas = $ticket->apuestas;

                    // D5: la cascada suma `vencido` a "resuelta"; `ganadora`
                    // (impaga) NO resuelve el ticket.
                    $todasResueltas = $lineasVivas->isNotEmpty()
                        && $lineasVivas->every(function (Apuesta $linea) {
                            return in_array($linea->estado, ['pagada', 'anulada', 'perdida', 'vencido'], true);
                        });

                    if ($todasResueltas) {
                        $ticket->update(['estado' => 'pagada']);
                    }
                }
            }

            // Registrar log de auditoría (solo egreso)
            Log::create([
                'user_id' => $user->id,
                'action' => 'pago_premio',
                'details' => [
                    'apuesta_id' => $apuesta->id,
                    'ticket_code' => $apuesta->ticket_code,
                    'animal' => $apuesta->combinacion['animal'] ?? 'N/A',
                    'premio_bs' => $premio['premio_bs'],
                    'premio_usd' => $premio['premio_usd'],
                    'tipo' => 'egreso',
                    'pago_id' => $pago->id,
                    'premio_fuente' => 'motor',
                ],
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
            ]);

            return ['pago' => $pago, 'premio' => $premio];
        });

        if (isset($resultado['error'])) {
            return response()->json($resultado['error']['body'], $resultado['error']['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pago registrado exitosamente.',
            'data' => $resultado['pago']->load(['apuesta', 'creador']),
            // WU pago-premio: devuelve el premio aplicado (siempre el del motor).
            'premio' => [
                'premio_bs' => (float) $resultado['premio']['premio_bs'],
                'premio_usd' => (float) $resultado['premio']['premio_usd'],
            ],
        ], 201);
    }

    /**
     * Envuelve una respuesta de error para abortar la transaccion sin escribir.
     *
     * @param  array<string, mixed>  $body
     * @return array{error: array{status: int, body: array<string, mixed>}}
     */
    private function rechazo(int $status, array $body): array
    {
        return ['error' => ['status' => $status, 'body' => $body]];
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
}
