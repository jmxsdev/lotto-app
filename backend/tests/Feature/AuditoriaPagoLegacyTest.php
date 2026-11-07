<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\AuditEvent;
use App\Models\Banca;
use App\Models\DetalleApuesta;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\Ticket;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * WU-8a — `pago.legacy_reconciled` P1 best-effort.
 *
 * El comando `pagos:auditar-legacy` usa actor `system`, scope de taquilla y
 * before/after de la apuesta reconciliada. Si la auditoría falla, el comando
 * no se aborta y la corrección de negocio se conserva.
 */
class AuditoriaPagoLegacyTest extends TestCase
{
    use RefreshDatabase;

    private static bool $failAudit = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

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

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function lastEvent(string $action): AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->latest('id')->firstOrFail();
    }

    /**
     * @return array{0: Banca, 1: Grupo, 2: Taquilla}
     */
    private function jerarquia(int $vigencia = 30): array
    {
        $banca = Banca::create([
            'name' => 'Banca Audit Jobs',
            'code' => 'BAJ',
            'active' => true,
            'vigencia_premios' => 60,
        ]);
        $grupo = Grupo::create([
            'name' => 'Grupo Audit Jobs',
            'code' => 'GAJ',
            'banca_id' => $banca->id,
            'active' => true,
            'vigencia_premios' => $vigencia,
        ]);
        $taquilla = Taquilla::create([
            'name' => 'Taquilla Audit Jobs',
            'code' => 'TAJ',
            'grupo_id' => $grupo->id,
            'active' => true,
            'vigencia_premios' => null,
        ]);

        return [$banca, $grupo, $taquilla];
    }

    /**
     * @return array{0: Apuesta, 1: DetalleApuesta, 2: Ticket, 3: Banca, 4: Grupo, 5: Taquilla}
     */
    private function fixturePagoLegacy(): array
    {
        [$banca, $grupo, $taquilla] = $this->jerarquia();
        $juego = Juego::where('slug', 'lotto-activo')->firstOrFail();

        $resultado = Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => now(),
            'numeros_ganadores' => ['nombre_animal' => 'Perro', 'numero' => 5],
        ]);

        $ticket = Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 10,
            'total_usd' => 0,
            'premio_total_bs' => 300,
            'premio_total_usd' => 0,
            'estado' => 'pagada',
        ]);

        $apuesta = Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'ticket_id' => $ticket->id,
            'juego_id' => $juego->id,
            'combinacion' => json_encode(['animal' => 'perro', 'numero' => 5]),
            'amount_bs' => 10,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 10,
            'estado' => 'pagada',
            'resultado_id' => $resultado->id,
            'fecha_hora' => now(),
            'sorteo_hora' => now()->addHour(),
        ]);

        $detalle = DetalleApuesta::create([
            'apuesta_id' => $apuesta->id,
            'combinacion' => json_encode(['animal' => 'perro', 'numero' => 5]),
            'monto' => 10,
            'premio_ganado' => null,
            'premio_ganado_usd' => null,
        ]);

        return [$apuesta, $detalle, $ticket, $banca, $grupo, $taquilla];
    }

    private function assertSystemRequestless(AuditEvent $event): void
    {
        $this->assertSame('system', $event->actor_type);
        $this->assertNull($event->actor_id);
        $this->assertNull($event->ip);
        $this->assertNull($event->user_agent);
        $this->assertNull($event->request_id);
    }

    private function assertNoSecretsOrTimestamps(AuditEvent $event): void
    {
        $payload = mb_strtolower((string) json_encode([
            'before' => $event->before,
            'after' => $event->after,
            'context' => $event->context,
        ]));

        foreach (['password', 'clave_cierre', 'access_token', 'created_at', 'updated_at'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }
    }

    // ------------------------------------------------------------------
    // pago.legacy_reconciled (P1)
    // ------------------------------------------------------------------

    public function test_comando_legacy_registra_evento_p1_con_old_new(): void
    {
        [$apuesta, $detalle, $ticket, $banca, $grupo, $taquilla] = $this->fixturePagoLegacy();

        $this->artisan('pagos:auditar-legacy', [
            '--apply' => true,
            '--ids' => (string) $apuesta->id,
        ])->assertExitCode(0);

        $this->assertSame('ganadora', $apuesta->fresh()->estado);
        $this->assertEquals(300.0, (float) $detalle->fresh()->premio_ganado);
        $this->assertSame('ganador', $ticket->fresh()->estado);

        $event = $this->lastEvent('pago.legacy_reconciled');

        $this->assertSystemRequestless($event);
        $this->assertNull($event->job_id);
        $this->assertSame('command:pagos:auditar-legacy', $event->actor_label);
        $this->assertSame('apuesta', $event->entity_type);
        $this->assertSame($apuesta->id, $event->entity_id);
        $this->assertSame('pagada', $event->before['estado']);
        $this->assertSame('ganadora', $event->after['estado']);
        $this->assertSame('pagada', $event->before['ticket_estado']);
        $this->assertSame('ganador', $event->after['ticket_estado']);
        $this->assertEquals(300.0, (float) $event->after['premio_ganado']);
        $this->assertSame('pagos:auditar-legacy', $event->context['command']);
        $this->assertSame('motor', $event->context['premio_fuente']);
        $this->assertSame($ticket->id, $event->context['ticket_id']);
        $this->assertSame('command', $event->context['source']);
        $this->assertSame($banca->id, $event->banca_id);
        $this->assertSame($grupo->id, $event->grupo_id);
        $this->assertSame($taquilla->id, $event->taquilla_id);
        $this->assertNoSecretsOrTimestamps($event);
    }

    public function test_comando_legacy_no_aborta_si_falla_la_auditoria(): void
    {
        [$apuesta, $detalle, $ticket] = $this->fixturePagoLegacy();

        self::$failAudit = true;

        $this->artisan('pagos:auditar-legacy', [
            '--apply' => true,
            '--ids' => (string) $apuesta->id,
        ])->assertExitCode(0);

        $this->assertSame('ganadora', $apuesta->fresh()->estado);
        $this->assertEquals(300.0, (float) $detalle->fresh()->premio_ganado);
        $this->assertSame('ganador', $ticket->fresh()->estado);
        $this->assertSame(0, AuditEvent::count());
    }
}
