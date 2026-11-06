<?php

namespace App\Services;

use App\Audit\AuditAction;
use App\Audit\AuditEventData;
use App\Audit\AuditReason;
use App\Audit\AuditRecorder;
use App\Audit\AuditScopeResolver;
use App\Exceptions\AuditValidationException;
use App\Exceptions\TicketAnulacionException;
use App\Models\Apuesta;
use App\Models\Pago;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\ApuestaPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Anulación pre-sorteo con devolución (D9/AD-1).
 *
 * Es el ÚNICO escritor de `pagos.tipo = devolucion`. La unidad de anulación es
 * el ticket: valida la policy en todas las líneas vivas antes de escribir,
 * registra una devolución por línea espejando la venta, marca apuestas y
 * ticket `anulada`, los soft-deletea y escribe la auditoría, todo dentro de
 * una única transacción con locks de fila.
 *
 * La regla de negocio (ventana/estado/ownership) vive en `ApuestaPolicy`; este
 * servicio solo la consume y traduce sus códigos a excepciones.
 */
final class TicketAnulacionService
{
    public function __construct(
        private readonly ApuestaService $apuestaService,
        private readonly ApuestaPolicy $apuestaPolicy,
        private readonly AuditRecorder $recorder,
        private readonly AuditScopeResolver $scopeResolver,
    ) {}

    /**
     * Anular un ticket completo y registrar una devolución por línea viva.
     *
     * @param  array{metodo_pago?: ?string, referencia?: ?string, motivo?: ?string, concepto?: ?string, ip?: ?string, user_agent?: ?string}  $opciones
     * @return array{
     *     ticket_id: int,
     *     apuestas: array<int, int>,
     *     apuestas_count: int,
     *     devoluciones: array<int, array<string, mixed>>,
     *     devoluciones_count: int,
     *     total_bs: float,
     *     total_usd: float
     * }
     *
     * @throws TicketAnulacionException
     */
    public function anularTicket(Ticket $ticket, User $user, array $opciones = []): array
    {
        return DB::transaction(function () use ($ticket, $user, $opciones): array {
            $motivo = $this->motivoRequerido($opciones);

            // Sin `withTrashed`: un ticket ya anulado no puede re-ejecutar la
            // transición, aunque el modelo venga de una instancia vieja.
            $bloqueado = Ticket::whereKey($ticket->id)->lockForUpdate()->first();

            if (! $bloqueado) {
                throw TicketAnulacionException::noEncontrado('Ticket no encontrado.');
            }

            if ($bloqueado->estado !== 'pendiente') {
                throw TicketAnulacionException::rechazado(
                    'Solo se pueden anular tickets pendientes.',
                    ApuestaPolicy::MOTIVO_ESTADO
                );
            }

            // El scope de SoftDeletes deja solo las líneas vivas.
            $apuestas = Apuesta::where('ticket_id', $bloqueado->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($apuestas->isEmpty()) {
                throw TicketAnulacionException::rechazado('El ticket no tiene jugadas activas para anular.');
            }

            $minutos = $this->apuestaService
                ->getEffectiveTiempoEliminacion($bloqueado->taquilla_id);

            // All-or-nothing: se valida toda la colección antes de la primera
            // escritura; cualquier falla aborta la transacción completa.
            $this->assertAnulable($apuestas, $user, $minutos);

            $ventas = $this->metodosVenta($apuestas);

            $devoluciones = [];
            $totalBs = 0.0;
            $totalUsd = 0.0;
            $ticketAntes = $this->ticketSnapshot($bloqueado);

            foreach ($apuestas as $apuesta) {
                $venta = $ventas[$apuesta->id] ?? null;
                $apuestaAntes = $this->apuestaSnapshot($apuesta);
                $pago = $this->registrarDevolucion($apuesta, $user, $venta, $opciones);

                $devolucion = $this->detalleDevolucion($pago, $apuesta, $venta);
                $devoluciones[] = $devolucion;
                $totalBs += (float) $apuesta->amount_bs;
                $totalUsd += (float) $apuesta->amount_usd;

                $this->marcarAnulada($apuesta);

                $this->recorder->critical(
                    AuditAction::BetVoided,
                    (new AuditEventData(
                        action: AuditAction::BetVoided,
                        entityType: 'apuesta',
                        entityId: (int) $apuesta->getKey(),
                        scope: $this->scopeResolver->forModel($apuesta),
                        before: $apuestaAntes,
                        after: $this->apuestaSnapshot($apuesta),
                        context: [
                            'ticket_id' => (int) $bloqueado->id,
                            'ticket_code' => $bloqueado->ticket_code,
                            'devolucion' => $devolucion,
                            'total_bs' => round((float) $apuesta->amount_bs, 2),
                            'total_usd' => round((float) $apuesta->amount_usd, 2),
                        ],
                    ))->withReason(AuditReason::Other, $motivo)
                );
            }

            $this->marcarAnulada($bloqueado);

            // P0 fail-closed dentro de la MISMA transacción: si el INSERT de
            // auditoría falla, se revierten devoluciones, estados y soft-delete.
            $this->recorder->critical(
                AuditAction::TicketVoided,
                (new AuditEventData(
                    action: AuditAction::TicketVoided,
                    entityType: 'ticket',
                    entityId: (int) $bloqueado->getKey(),
                    scope: $this->scopeResolver->forModel($bloqueado),
                    before: $ticketAntes,
                    after: $this->ticketSnapshot($bloqueado),
                    context: [
                        'apuestas' => $apuestas->pluck('id')->all(),
                        'devoluciones_count' => count($devoluciones),
                        'total_bs' => round($totalBs, 2),
                        'total_usd' => round($totalUsd, 2),
                    ],
                ))->withReason(AuditReason::Other, $motivo)
            );

            return [
                'ticket_id' => $bloqueado->id,
                'apuestas' => $apuestas->pluck('id')->all(),
                'apuestas_count' => $apuestas->count(),
                'devoluciones' => $devoluciones,
                'devoluciones_count' => count($devoluciones),
                'total_bs' => round($totalBs, 2),
                'total_usd' => round($totalUsd, 2),
            ];
        });
    }

    /**
     * Anular una apuesta sin ticket (venta suelta) y registrar su devolución.
     *
     * La usa `DELETE /apuestas/{id}` en WU3-B. La autorización de árbol se
     * re-ejecuta dentro de la transacción vía policy.
     *
     * @param  array{metodo_pago?: ?string, referencia?: ?string, motivo?: ?string, concepto?: ?string, ip?: ?string, user_agent?: ?string}  $opciones
     *
     * @throws TicketAnulacionException
     */
    public function anularApuestaSuelta(Apuesta $apuesta, User $user, array $opciones = []): Pago
    {
        return DB::transaction(function () use ($apuesta, $user, $opciones): Pago {
            $motivo = $this->motivoRequerido($opciones);

            $bloqueada = Apuesta::whereKey($apuesta->id)->lockForUpdate()->first();

            if (! $bloqueada) {
                throw TicketAnulacionException::noEncontrado('Apuesta no encontrada.');
            }

            if ($bloqueada->ticket_id !== null) {
                throw TicketAnulacionException::rechazado(
                    "La anulación se realiza a nivel de ticket: use DELETE /api/v1/tickets/{$bloqueada->ticket_id}."
                );
            }

            $minutos = $this->apuestaService
                ->getEffectiveTiempoEliminacion($bloqueada->taquilla_id);

            $motivoPolicy = $this->apuestaPolicy->motivoNoAnulable($user, $bloqueada, $minutos);

            if ($motivoPolicy !== null) {
                if ($motivoPolicy === ApuestaPolicy::MOTIVO_SIN_OWNERSHIP) {
                    throw TicketAnulacionException::noAutorizado('No autorizado.', $motivoPolicy);
                }

                throw TicketAnulacionException::rechazado(
                    $this->mensajeRechazo($motivoPolicy, $minutos),
                    $motivoPolicy
                );
            }

            $venta = $this->metodosVenta(new Collection([$bloqueada]))[$bloqueada->id] ?? null;
            $apuestaAntes = $this->apuestaSnapshot($bloqueada);
            $pago = $this->registrarDevolucion($bloqueada, $user, $venta, $opciones);

            $this->marcarAnulada($bloqueada);

            // P0 fail-closed: la devolución y la evidencia se confirman juntas.
            $this->recorder->critical(
                AuditAction::BetVoided,
                (new AuditEventData(
                    action: AuditAction::BetVoided,
                    entityType: 'apuesta',
                    entityId: (int) $bloqueada->getKey(),
                    scope: $this->scopeResolver->forModel($bloqueada),
                    before: $apuestaAntes,
                    after: $this->apuestaSnapshot($bloqueada),
                    context: [
                        'ticket_code' => $bloqueada->ticket_code,
                        'devolucion' => $this->detalleDevolucion($pago, $bloqueada, $venta),
                        'total_bs' => round((float) $bloqueada->amount_bs, 2),
                        'total_usd' => round((float) $bloqueada->amount_usd, 2),
                    ],
                ))->withReason(AuditReason::Other, $motivo)
            );

            return $pago;
        });
    }

    /**
     * Valida la policy en todas las líneas antes de cualquier escritura.
     *
     * @param  Collection<int, Apuesta>  $apuestas
     *
     * @throws TicketAnulacionException
     */
    private function assertAnulable(Collection $apuestas, User $user, int $minutos): void
    {
        foreach ($apuestas as $apuesta) {
            $motivo = $this->apuestaPolicy->motivoNoAnulable($user, $apuesta, $minutos);

            if ($motivo === null) {
                continue;
            }

            if ($motivo === ApuestaPolicy::MOTIVO_SIN_OWNERSHIP) {
                throw TicketAnulacionException::noAutorizado('No autorizado.', $motivo);
            }

            throw TicketAnulacionException::rechazado(
                $this->mensajeRechazo($motivo, $minutos),
                $motivo
            );
        }
    }

    /**
     * Venta (`ingreso`) de cada apuesta, en una sola consulta.
     *
     * Si existieran duplicados legacy (finding #8), gana el `pagos.id` menor.
     *
     * @param  Collection<int, Apuesta>  $apuestas
     * @return array<int, array{pago_id: int, metodo_pago: ?string}>
     */
    private function metodosVenta(Collection $apuestas): array
    {
        $ids = $apuestas->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $ingresos = Pago::query()
            ->whereIn('apuesta_id', $ids)
            ->where('tipo', 'ingreso')
            ->orderBy('id')
            ->get(['id', 'apuesta_id', 'metodo_pago']);

        $ventas = [];

        foreach ($ingresos as $ingreso) {
            $apuestaId = (int) $ingreso->apuesta_id;

            if (array_key_exists($apuestaId, $ventas)) {
                continue;
            }

            $ventas[$apuestaId] = [
                'pago_id' => (int) $ingreso->id,
                'metodo_pago' => $ingreso->metodo_pago,
            ];
        }

        return $ventas;
    }

    /**
     * Registra la devolución de una línea espejando la venta.
     *
     * Orden del método: override explícito → `ingreso` de la venta → efectivo;
     * luego `Pago::resolverMetodoPago` fuerza efectivo cuando hay USD.
     *
     * @param  array{pago_id: int, metodo_pago: ?string}|null  $venta
     * @param  array<string, mixed>  $opciones
     */
    private function registrarDevolucion(Apuesta $apuesta, User $user, ?array $venta, array $opciones): Pago
    {
        $amountBs = (float) $apuesta->amount_bs;
        $amountUsd = (float) $apuesta->amount_usd;

        $moneda = $amountBs > 0 && $amountUsd > 0
            ? 'mixto'
            : ($amountUsd > 0 ? 'usd' : 'bs');

        $metodo = $opciones['metodo_pago'] ?? $venta['metodo_pago'] ?? 'efectivo';

        return Pago::create([
            'taquilla_id' => $apuesta->taquilla_id,
            'apuesta_id' => $apuesta->id,
            'amount_bs' => $amountBs,
            'amount_usd' => $amountUsd,
            'exchange_rate_applied' => $apuesta->exchange_rate_applied,
            'tipo' => 'devolucion',
            'moneda' => $moneda,
            'metodo_pago' => Pago::resolverMetodoPago($metodo, $moneda, $amountUsd),
            'concepto' => $opciones['concepto'] ?? 'Devolución de ticket',
            'referencia' => $opciones['referencia'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Detalle de auditoría de una devolución (mismo shape en el evento y response).
     *
     * @param  array{pago_id: int, metodo_pago: ?string}|null  $venta
     * @return array<string, mixed>
     */
    private function detalleDevolucion(Pago $pago, Apuesta $apuesta, ?array $venta): array
    {
        return [
            'id' => $pago->id,
            'pago_id' => $pago->id,
            'apuesta_id' => $apuesta->id,
            'sale_pago_id' => $venta['pago_id'] ?? null,
            'amount_bs' => (string) $pago->amount_bs,
            'amount_usd' => (string) $pago->amount_usd,
            'exchange_rate_applied' => (string) $pago->exchange_rate_applied,
            'metodo_pago' => $pago->metodo_pago,
            'moneda' => $pago->moneda,
        ];
    }

    /**
     * Transición `anulada` + soft delete (misma semántica para línea y ticket).
     */
    private function marcarAnulada(Apuesta|Ticket $modelo): void
    {
        $modelo->update(['estado' => 'anulada']);
        $modelo->delete();
    }

    /**
     * Único mapeo código de motivo → mensaje de anulación. Los textos son
     * byte a byte los históricos: no agregar acentos ni cambiarlos.
     */
    private function mensajeRechazo(string $motivo, int $minutos): string
    {
        return match ($motivo) {
            ApuestaPolicy::MOTIVO_FUERA_DE_VENTANA => "El ticket excedió los {$minutos} minutos para ser anulado.",
            ApuestaPolicy::MOTIVO_SORTEO_OCURRIDO => 'El sorteo de una o mas jugadas ya ocurrio, no se puede anular.',
            ApuestaPolicy::MOTIVO_SIN_OWNERSHIP => 'No autorizado.',
            default => 'No se puede anular el ticket.',
        };
    }

    /**
     * La anulación es una acción destructiva: el motivo es obligatorio y nunca
     * se rellena por defecto. Si falta o está vacío lanza 422 antes de escribir.
     *
     * @param  array<string, mixed>  $opciones
     */
    private function motivoRequerido(array $opciones): string
    {
        $motivo = trim((string) ($opciones['motivo'] ?? ''));

        if ($motivo === '') {
            throw AuditValidationException::forField('motivo', 'El motivo es requerido para anular.');
        }

        return $motivo;
    }

    /**
     * Safe `before`/`after` snapshot for a ticket. Only business fields are
     * captured; the surrogate id, timestamps and relations are excluded.
     *
     * @return array<string, mixed>
     */
    private function ticketSnapshot(Ticket $ticket): array
    {
        return [
            'estado' => $ticket->estado,
            'ticket_code' => $ticket->ticket_code,
            'taquilla_id' => $ticket->taquilla_id === null ? null : (int) $ticket->taquilla_id,
            'total_bs' => $ticket->getAttribute('total_bs'),
            'total_usd' => $ticket->getAttribute('total_usd'),
            'deleted_at' => $ticket->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * Safe `before`/`after` snapshot for a bet line. Same rules as the ticket
     * snapshot: business columns only, no secrets and no Eloquent timestamps.
     *
     * @return array<string, mixed>
     */
    private function apuestaSnapshot(Apuesta $apuesta): array
    {
        return [
            'estado' => $apuesta->estado,
            'ticket_code' => $apuesta->ticket_code,
            'ticket_id' => $apuesta->ticket_id === null ? null : (int) $apuesta->ticket_id,
            'taquilla_id' => $apuesta->taquilla_id === null ? null : (int) $apuesta->taquilla_id,
            'juego_id' => $apuesta->juego_id === null ? null : (int) $apuesta->juego_id,
            'amount_bs' => $apuesta->getAttribute('amount_bs'),
            'amount_usd' => $apuesta->getAttribute('amount_usd'),
            'exchange_rate_applied' => $apuesta->getAttribute('exchange_rate_applied'),
            'total_bs_equivalent' => $apuesta->getAttribute('total_bs_equivalent'),
            'deleted_at' => $apuesta->deleted_at?->toIso8601String(),
        ];
    }
}
