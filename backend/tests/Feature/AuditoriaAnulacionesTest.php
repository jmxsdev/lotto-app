<?php

namespace Tests\Feature;

use App\Audit\AuditAction;
use App\Audit\AuditPriority;
use App\Exceptions\AuditValidationException;
use App\Models\Apuesta;
use App\Models\AuditEvent;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Pago;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketAnulacionService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * WU-5b-2 — auditoría de anulaciones (`ticket.voided` / `bet.voided`).
 *
 * La anulación es P0 fail-closed: el evento se escribe dentro de la misma
 * transacción que crea la devolución y muta el estado/soft-delete. El motivo
 * es obligatorio y se persiste como `reason_text` con `reason_code=other`.
 * El log operativo legacy (`ticket_anulado` / `apuesta.anulada`) desaparece.
 */
class AuditoriaAnulacionesTest extends TestCase
{
    use RefreshDatabase;

    private static bool $failAudit = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);

        AuditEvent::creating(static function (): void {
            if (self::$failAudit) {
                throw new RuntimeException('forced audit insert failure');
            }
        });
    }

    protected function tearDown(): void
    {
        self::$failAudit = false;
        parent::tearDown();
    }

    private function superUser(): User
    {
        $super = User::where('email', 'super@lotto.com')->firstOrFail();
        $super->assignRole('super_master');

        return $super;
    }

    private function juego(): Juego
    {
        return Juego::where('slug', 'lotto-activo')->firstOrFail();
    }

    /**
     * @return array{0: Banca, 1: Grupo, 2: Taquilla}
     */
    private function jerarquia(): array
    {
        $banca = Banca::factory()->create(['active' => true]);
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $taquilla = Taquilla::factory()->create(['grupo_id' => $grupo->id, 'active' => true]);

        return [$banca, $grupo, $taquilla];
    }

    /**
     * @param  array<string, mixed>  $linea
     */
    private function crearLinea(
        ?Ticket $ticket,
        Taquilla $taquilla,
        Juego $juego,
        User $creador,
        array $linea
    ): Apuesta {
        $amountBs = (float) ($linea['amount_bs'] ?? 0);
        $amountUsd = (float) ($linea['amount_usd'] ?? 0);

        $apuesta = Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'ticket_id' => $ticket?->id,
            'combinacion' => ['animal' => 'perro', 'numero' => 5],
            'amount_bs' => $amountBs,
            'amount_usd' => $amountUsd,
            'exchange_rate_applied' => 36.5000,
            'total_bs_equivalent' => $amountBs + ($amountUsd * 36.50),
            'estado' => 'pendiente',
            'fecha_hora' => now(),
            'sorteo_hora' => now()->addHour(),
        ]);

        $moneda = $amountBs > 0 && $amountUsd > 0
            ? 'mixto'
            : ($amountUsd > 0 ? 'usd' : 'bs');

        Pago::create([
            'taquilla_id' => $taquilla->id,
            'apuesta_id' => $apuesta->id,
            'amount_bs' => $amountBs,
            'amount_usd' => $amountUsd,
            'exchange_rate_applied' => 36.5000,
            'tipo' => 'ingreso',
            'moneda' => $moneda,
            'metodo_pago' => Pago::resolverMetodoPago($linea['metodo_pago'] ?? null, $moneda, $amountUsd),
            'concepto' => 'Compra de ticket',
            'created_by' => $creador->id,
        ]);

        return $apuesta;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineas
     * @return array{0: Ticket, 1: array<int, Apuesta>}
     */
    private function crearTicket(Taquilla $taquilla, Juego $juego, User $creador, array $lineas): array
    {
        $ticket = Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 0,
            'total_usd' => 0,
            'estado' => 'pendiente',
        ]);

        $totalBs = 0.0;
        $totalUsd = 0.0;
        $apuestas = [];

        foreach ($lineas as $linea) {
            $apuestas[] = $this->crearLinea($ticket, $taquilla, $juego, $creador, $linea);
            $totalBs += (float) ($linea['amount_bs'] ?? 0);
            $totalUsd += (float) ($linea['amount_usd'] ?? 0);
        }

        $ticket->update([
            'total_bs' => $totalBs,
            'total_usd' => $totalUsd,
        ]);

        return [$ticket->fresh(), $apuestas];
    }

    public function test_anulacion_de_ticket_emite_ticket_voided_y_un_bet_voided_por_linea(): void
    {
        $actor = $this->superUser();
        [$banca, $grupo, $taquilla] = $this->jerarquia();
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $actor, [
            ['amount_bs' => 1000, 'metodo_pago' => 'pago_movil'],
            ['amount_bs' => 0, 'amount_usd' => 5, 'metodo_pago' => 'transferencia'],
        ]);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'Error del operador'])
            ->assertOk();

        $this->assertSame(AuditPriority::P0, AuditAction::TicketVoided->priority());
        $this->assertSame(AuditPriority::P0, AuditAction::BetVoided->priority());
        $this->assertSame(1, AuditEvent::where('action', 'ticket.voided')->count());
        $this->assertSame(2, AuditEvent::where('action', 'bet.voided')->count());

        // El log operativo legacy ya no se escribe.
        $this->assertDatabaseMissing('logs', ['action' => 'ticket_anulado']);
        $this->assertDatabaseMissing('logs', ['action' => 'apuesta.anulada']);

        $ticketEvent = AuditEvent::where('action', 'ticket.voided')->firstOrFail();

        $this->assertSame('user', $ticketEvent->actor_type);
        $this->assertSame((int) $actor->id, (int) $ticketEvent->actor_id);
        $this->assertSame('s***@lotto.com', $ticketEvent->actor_label);
        $this->assertNotSame($actor->email, $ticketEvent->actor_label);
        $this->assertSame('ticket', $ticketEvent->entity_type);
        $this->assertSame((int) $ticket->id, (int) $ticketEvent->entity_id);

        // Scope derivado de la taquilla del ticket.
        $this->assertSame((int) $banca->id, $ticketEvent->banca_id);
        $this->assertSame((int) $grupo->id, $ticketEvent->grupo_id);
        $this->assertNull($ticketEvent->agencia_id);
        $this->assertSame((int) $taquilla->id, $ticketEvent->taquilla_id);

        // Motivo y before/after seguros.
        $this->assertSame('other', $ticketEvent->reason_code);
        $this->assertSame('Error del operador', $ticketEvent->reason_text);
        $this->assertSame('pendiente', $ticketEvent->before['estado']);
        $this->assertNull($ticketEvent->before['deleted_at']);
        $this->assertSame('anulada', $ticketEvent->after['estado']);
        $this->assertNotNull($ticketEvent->after['deleted_at']);
        $this->assertSame($ticket->ticket_code, $ticketEvent->after['ticket_code']);

        $this->assertSame([$apuestas[0]->id, $apuestas[1]->id], $ticketEvent->context['apuestas']);
        $this->assertEqualsWithDelta(1000.0, (float) $ticketEvent->context['total_bs'], 0.001);
        $this->assertEqualsWithDelta(5.0, (float) $ticketEvent->context['total_usd'], 0.001);

        $betEvents = AuditEvent::where('action', 'bet.voided')->orderBy('entity_id')->get();

        foreach ($apuestas as $indice => $apuesta) {
            $betEvent = $betEvents[$indice];
            $devolucion = Pago::where('apuesta_id', $apuesta->id)
                ->where('tipo', 'devolucion')
                ->firstOrFail();

            $this->assertSame('apuesta', $betEvent->entity_type);
            $this->assertSame((int) $apuesta->id, (int) $betEvent->entity_id);
            $this->assertSame((int) $banca->id, $betEvent->banca_id);
            $this->assertSame((int) $grupo->id, $betEvent->grupo_id);
            $this->assertSame((int) $taquilla->id, $betEvent->taquilla_id);
            $this->assertSame('pendiente', $betEvent->before['estado']);
            $this->assertNull($betEvent->before['deleted_at']);
            $this->assertSame('anulada', $betEvent->after['estado']);
            $this->assertNotNull($betEvent->after['deleted_at']);
            $this->assertSame('other', $betEvent->reason_code);
            $this->assertSame('Error del operador', $betEvent->reason_text);
            $this->assertSame((int) $ticket->id, (int) $betEvent->context['ticket_id']);
            $this->assertSame($devolucion->id, $betEvent->context['devolucion']['pago_id']);
        }
    }

    public function test_anulacion_de_ticket_sin_motivo_devuelve_422_y_no_escribe_nada(): void
    {
        $actor = $this->superUser();
        [, , $taquilla] = $this->jerarquia();
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $actor, [
            ['amount_bs' => 1000],
        ]);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        $this->assertSame(0, Pago::where('tipo', 'devolucion')->count());
        $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
        $this->assertSame('pendiente', $ticket->fresh()->estado);
        $this->assertNotSoftDeleted('apuestas', ['id' => $apuestas[0]->id]);
        $this->assertSame('pendiente', $apuestas[0]->fresh()->estado);
        $this->assertSame(
            0,
            AuditEvent::whereIn('action', ['ticket.voided', 'bet.voided'])->count()
        );
    }

    public function test_servicio_rechaza_motivo_ausente_antes_de_escribir(): void
    {
        $actor = $this->superUser();
        [, , $taquilla] = $this->jerarquia();
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $actor, [
            ['amount_bs' => 1000],
        ]);

        try {
            app(TicketAnulacionService::class)->anularTicket($ticket, $actor);
            $this->fail('Se esperaba el rechazo por motivo ausente.');
        } catch (AuditValidationException $exception) {
            $this->assertArrayHasKey('motivo', $exception->errors());
        }

        $this->assertSame(0, Pago::where('tipo', 'devolucion')->count());
        $this->assertSame('pendiente', $ticket->fresh()->estado);
        $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
        $this->assertNotSoftDeleted('apuestas', ['id' => $apuestas[0]->id]);
        $this->assertSame(
            0,
            AuditEvent::whereIn('action', ['ticket.voided', 'bet.voided'])->count()
        );
    }

    public function test_anulacion_de_apuesta_suelta_emite_solo_bet_voided(): void
    {
        $actor = $this->superUser();
        [, , $taquilla] = $this->jerarquia();
        $apuesta = $this->crearLinea(null, $taquilla, $this->juego(), $actor, [
            'amount_bs' => 800,
            'metodo_pago' => 'transferencia',
        ]);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/apuestas/'.$apuesta->id, ['motivo' => 'Error del operador'])
            ->assertOk();

        $this->assertSame(1, AuditEvent::where('action', 'bet.voided')->count());
        $this->assertSame(0, AuditEvent::where('action', 'ticket.voided')->count());
        $this->assertDatabaseMissing('logs', ['action' => 'apuesta.anulada']);

        $event = AuditEvent::where('action', 'bet.voided')->firstOrFail();
        $devolucion = Pago::where('apuesta_id', $apuesta->id)
            ->where('tipo', 'devolucion')
            ->firstOrFail();

        $this->assertSame((int) $apuesta->id, (int) $event->entity_id);
        $this->assertSame('pendiente', $event->before['estado']);
        $this->assertSame('anulada', $event->after['estado']);
        $this->assertSame('other', $event->reason_code);
        $this->assertSame('Error del operador', $event->reason_text);
        $this->assertArrayNotHasKey('ticket_id', $event->context);
        $this->assertSame($devolucion->id, $event->context['devolucion']['pago_id']);
    }

    public function test_fallo_de_auditoria_revierte_ticket_apuestas_y_devoluciones(): void
    {
        $actor = $this->superUser();
        [, , $taquilla] = $this->jerarquia();
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $actor, [
            ['amount_bs' => 1000],
            ['amount_bs' => 750],
        ]);

        self::$failAudit = true;

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'Error del operador'])
            ->assertStatus(500);

        self::$failAudit = false;

        $this->assertSame(0, Pago::where('tipo', 'devolucion')->count());
        $this->assertSame(0, AuditEvent::whereIn('action', ['ticket.voided', 'bet.voided'])->count());

        $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
        $this->assertSame('pendiente', $ticket->fresh()->estado);

        foreach ($apuestas as $apuesta) {
            $this->assertNotSoftDeleted('apuestas', ['id' => $apuesta->id]);
            $this->assertSame('pendiente', $apuesta->fresh()->estado);
        }
    }

    public function test_eventos_de_anulacion_no_contienen_secretos_ni_timestamps(): void
    {
        $actor = $this->superUser();
        [, , $taquilla] = $this->jerarquia();
        [$ticket] = $this->crearTicket($taquilla, $this->juego(), $actor, [
            ['amount_bs' => 1000],
        ]);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'Error del operador'])
            ->assertOk();

        $secrets = [
            'password',
            'password_confirmation',
            'remember_token',
            'token',
            'access_token',
            'authorization',
            'clave_cierre',
            'mac_address',
            'device_fingerprint',
            'activation_code',
            'secret',
        ];

        foreach (AuditEvent::all() as $event) {
            foreach (['before', 'after', 'context'] as $column) {
                $payload = $event->{$column} ?? [];

                foreach ($secrets as $key) {
                    $this->assertArrayNotHasKey($key, $payload);
                }

                $this->assertArrayNotHasKey('created_at', $payload);
                $this->assertArrayNotHasKey('updated_at', $payload);
            }
        }

        $encoded = json_encode(
            AuditEvent::all()->map(fn (AuditEvent $event): array => $event->getAttributes())->all(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $this->assertStringNotContainsString('password', $encoded);
        $this->assertStringNotContainsString('token', $encoded);
    }
}
