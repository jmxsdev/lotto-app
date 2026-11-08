<?php

namespace Tests\Feature;

use App\Audit\AuditAction;
use App\Audit\AuditPriority;
use App\Models\Apuesta;
use App\Models\AuditEvent;
use App\Models\Banca;
use App\Models\DetalleApuesta;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Pago;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * WU-5b-1 — auditoría del pago de premio (`pago.prize_paid`).
 *
 * El egreso es P0 fail-closed: el evento se escribe dentro de la misma
 * transacción que crea el pago y muta la apuesta/detalle/ticket. Si la
 * auditoría falla, todo el pago se revierte (500) y no queda evidencia
 * parcial. El evento nunca contiene secretos ni timestamps.
 */
class AuditoriaPagosTest extends TestCase
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
        return User::where('email', 'super@lotto.com')->firstOrFail();
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
        $banca = Banca::factory()->create();
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id]);
        $taquilla = Taquilla::factory()->create(['grupo_id' => $grupo->id]);

        return [$banca, $grupo, $taquilla];
    }

    private function ticket(Taquilla $taquilla): Ticket
    {
        return Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 10,
            'total_usd' => 0,
            'estado' => 'pendiente',
        ]);
    }

    private function resultadoCoincidente(Juego $juego): Resultado
    {
        return Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => now(),
            'numeros_ganadores' => ['nombre_animal' => 'Perro', 'numero' => 5],
        ]);
    }

    /**
     * Apuesta ganadora pagable con detalle sin premio.
     *
     * @return array{0: Apuesta, 1: DetalleApuesta, 2: Ticket, 3: Taquilla, 4: Banca, 5: Grupo}
     */
    private function fixtureGanadora(): array
    {
        $juego = $this->juego();
        [$banca, $grupo, $taquilla] = $this->jerarquia();
        $ticket = $this->ticket($taquilla);
        $resultado = $this->resultadoCoincidente($juego);

        $apuesta = Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'ticket_id' => $ticket->id,
            'juego_id' => $juego->id,
            'resultado_id' => $resultado->id,
            'combinacion' => ['animal' => 'Perro', 'numero' => 5],
            'amount_bs' => 10,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 10,
            'estado' => 'ganadora',
            'fecha_hora' => now(),
            'sorteo_hora' => now()->addHour(),
        ]);

        $detalle = DetalleApuesta::create([
            'apuesta_id' => $apuesta->id,
            'combinacion' => ['animal' => 'Perro', 'numero' => 5],
            'monto' => 10,
            'premio_ganado' => null,
            'premio_ganado_usd' => null,
        ]);

        return [$apuesta, $detalle, $ticket, $taquilla, $banca, $grupo];
    }

    private function pagar(Apuesta $apuesta, User $user, array $extra = [])
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/pagos', array_merge([
                'apuesta_id' => $apuesta->id,
                'tipo' => 'egreso',
                'moneda' => 'bs',
            ], $extra));
    }

    public function test_pago_premio_exitoso_emite_p0_con_actor_entidad_scope_after_y_contexto(): void
    {
        [$apuesta, , , $taquilla, $banca, $grupo] = $this->fixtureGanadora();
        $actor = $this->superUser();

        $response = $this->pagar($apuesta, $actor, ['referencia' => 'REF-PREMIO-001']);

        $response->assertStatus(201);

        $this->assertSame(AuditPriority::P0, AuditAction::PagoPrizePaid->priority());
        $this->assertSame(1, AuditEvent::where('action', 'pago.prize_paid')->count());
        $this->assertDatabaseMissing('logs', ['action' => 'pago_premio']);

        $pago = Pago::where('apuesta_id', $apuesta->id)->where('tipo', 'egreso')->firstOrFail();
        $event = AuditEvent::where('action', 'pago.prize_paid')->firstOrFail();

        // Actor, entidad y acción.
        $this->assertSame('user', $event->actor_type);
        $this->assertSame((int) $actor->id, (int) $event->actor_id);
        $this->assertSame('s***@lotto.com', $event->actor_label);
        $this->assertNotSame($actor->email, $event->actor_label);
        $this->assertSame('pago', $event->entity_type);
        $this->assertSame((int) $pago->id, (int) $event->entity_id);
        $this->assertSame('pago.prize_paid', $event->action);

        // Scope derivado de la taquilla de la apuesta.
        $this->assertSame((int) $banca->id, $event->banca_id);
        $this->assertSame((int) $grupo->id, $event->grupo_id);
        $this->assertNull($event->agencia_id);
        $this->assertSame((int) $taquilla->id, $event->taquilla_id);

        // Create-style event: no before, safe after.
        $this->assertNull($event->before);
        $this->assertSame((int) $taquilla->id, (int) $event->after['taquilla_id']);
        $this->assertSame((int) $apuesta->id, (int) $event->after['apuesta_id']);
        $this->assertEqualsWithDelta(300.0, (float) $event->after['amount_bs'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $event->after['amount_usd'], 0.001);
        $this->assertEqualsWithDelta(36.50, (float) $event->after['exchange_rate_applied'], 0.0001);
        $this->assertSame('egreso', $event->after['tipo']);
        $this->assertSame('bs', $event->after['moneda']);
        $this->assertSame('efectivo', $event->after['metodo_pago']);
        $this->assertSame('Pago de premio', $event->after['concepto']);
        $this->assertSame('REF-PREMIO-001', $event->after['referencia']);
        $this->assertSame((int) $actor->id, (int) $event->after['created_by']);
        $this->assertArrayNotHasKey('created_at', $event->after);
        $this->assertArrayNotHasKey('updated_at', $event->after);

        // Contexto del motor.
        $this->assertSame((int) $apuesta->id, (int) $event->context['apuesta_id']);
        $this->assertSame($apuesta->ticket_code, $event->context['ticket_code']);
        $this->assertEqualsWithDelta(300.0, (float) $event->context['premio_bs'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $event->context['premio_usd'], 0.001);
        $this->assertSame('motor', $event->context['premio_fuente']);
    }

    public function test_evento_de_pago_no_contiene_secretos_ni_timestamps(): void
    {
        [$apuesta] = $this->fixtureGanadora();

        $this->pagar($apuesta, $this->superUser())->assertStatus(201);

        $event = AuditEvent::where('action', 'pago.prize_paid')->firstOrFail();

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

        foreach (['before', 'after', 'context'] as $column) {
            $payload = $event->{$column} ?? [];

            foreach ($secrets as $key) {
                $this->assertArrayNotHasKey($key, $payload);
            }

            $this->assertArrayNotHasKey('created_at', $payload);
            $this->assertArrayNotHasKey('updated_at', $payload);
        }

        $encoded = json_encode($event->getAttributes(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString('password', $encoded);
        $this->assertStringNotContainsString('token', $encoded);
    }

    public function test_fallo_de_auditoria_revierte_pago_apuesta_detalle_y_cascada_al_ticket(): void
    {
        [$apuesta, $detalle, $ticket] = $this->fixtureGanadora();
        $actor = $this->superUser();

        self::$failAudit = true;

        $this->pagar($apuesta, $actor)->assertStatus(500);

        $this->assertSame(0, Pago::where('apuesta_id', $apuesta->id)->count());
        $this->assertSame('ganadora', $apuesta->fresh()->estado);
        $this->assertNull($detalle->fresh()->premio_ganado);
        $this->assertSame('pendiente', $ticket->fresh()->estado);
        $this->assertSame(0, AuditEvent::where('action', 'pago.prize_paid')->count());
    }
}
