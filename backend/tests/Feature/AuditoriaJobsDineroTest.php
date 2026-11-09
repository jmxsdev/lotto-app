<?php

namespace Tests\Feature;

use App\Exceptions\AuditWriteException;
use App\Jobs\ExpireUnclaimedPrizesJob;
use App\Jobs\MarcarApuestasVencidasJob;
use App\Jobs\ScrapeExchangeRateJob;
use App\Models\Apuesta;
use App\Models\AuditEvent;
use App\Models\Banca;
use App\Models\ExchangeRate;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Taquilla;
use App\Models\Ticket;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Doble de ScrapeExchangeRateJob que corta SOLO el fetch de red.
 */
class AuditoriaScrapeExchangeRateJobFake extends ScrapeExchangeRateJob
{
    public function __construct(
        private readonly string $html = '',
    ) {}

    protected function fetchHtml(): string
    {
        return $this->html;
    }
}

/**
 * WU-8a — auditoría de jobs de dinero/sistema (P0).
 *
 * `exchange_rate.scraped`, `ticket.expired` y `bet.expired` son P0
 * fail-closed con actor `system`. Ningún evento expone IP/UA de request,
 * secretos ni timestamps técnicos.
 */
class AuditoriaJobsDineroTest extends TestCase
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

    private function juegoSinScraper(): Juego
    {
        return Juego::create([
            'name' => 'Juego Vencimiento Audit',
            'slug' => 'juego-vencimiento-audit',
            'type' => 'animalitos',
            'config' => ['premio_multiplo' => 30, 'premios' => ['base' => 30, 'modalidades' => [], 'comodines' => []]],
            'requires_scraper' => false,
            'active' => true,
        ]);
    }

    /**
     * @return array{0: Ticket, 1: Collection<int, Apuesta>, 2: Banca, 3: Grupo, 4: Taquilla}
     */
    private function ticketGanadorExpirado(int $dias = 35): array
    {
        [$banca, $grupo, $taquilla] = $this->jerarquia(30);
        $juego = Juego::where('slug', 'lotto-activo')->firstOrFail();

        $ticket = Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 1000,
            'total_usd' => 0,
            'estado' => 'ganador',
            'ticket_code' => 'AUD-EXP-001',
        ]);

        DB::table('tickets')->where('id', $ticket->id)
            ->update(['created_at' => now()->subDays($dias)]);

        $apuestas = collect();

        foreach ([1, 2] as $linea) {
            $apuestas->push(Apuesta::create([
                'taquilla_id' => $taquilla->id,
                'ticket_id' => $ticket->id,
                'juego_id' => $juego->id,
                'amount_bs' => 100 * $linea,
                'amount_usd' => 0,
                'exchange_rate_applied' => 36.50,
                'total_bs_equivalent' => 100 * $linea,
                'estado' => 'pendiente',
                'fecha_hora' => now()->subDays($dias),
            ]));
        }

        return [$ticket, $apuestas, $banca, $grupo, $taquilla];
    }

    private function apuestaVieja(Juego $juego, Taquilla $taquilla, int $dias = 2): Apuesta
    {
        $fecha = now()->subDays($dias);

        return Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'combinacion' => json_encode(['animal' => 'delfin', 'numero' => 0]),
            'amount_bs' => 10,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 10,
            'estado' => 'pendiente',
            'fecha_hora' => $fecha,
            'sorteo_hora' => $fecha,
        ]);
    }

    private function expectAuditWriteFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Se esperaba AuditWriteException.');
        } catch (AuditWriteException) {
            // Expected: P0 fail-closed.
        }
    }

    private function assertGlobalScope(AuditEvent $event): void
    {
        $this->assertNull($event->banca_id);
        $this->assertNull($event->grupo_id);
        $this->assertNull($event->agencia_id);
        $this->assertNull($event->taquilla_id);
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
    // exchange_rate.scraped (P0)
    // ------------------------------------------------------------------

    public function test_scrape_exchange_rate_registra_evento_p0_system(): void
    {
        $anterior = ExchangeRate::where('is_active', true)->firstOrFail();
        $anterior->update(['rate' => 35.00]);

        $job = new AuditoriaScrapeExchangeRateJobFake(
            file_get_contents(base_path('tests/Fixtures/bcv_dolar.html'))
        );
        $job->job = new class
        {
            public function getJobId(): string
            {
                return 'job-audit-1';
            }

            public function getQueue(): string
            {
                return 'money';
            }
        };
        $job->handle();

        $nueva = ExchangeRate::where('is_active', true)->where('rate', 36.50)->firstOrFail();
        $event = $this->lastEvent('exchange_rate.scraped');

        $this->assertSystemRequestless($event);
        $this->assertSame('job:ScrapeExchangeRateJob', $event->actor_label);
        $this->assertSame('job-audit-1', $event->job_id);
        $this->assertSame('money', $event->context['queue']);
        $this->assertSame('exchange_rate', $event->entity_type);
        $this->assertSame($nueva->id, $event->entity_id);
        $this->assertEquals(35.0, (float) $event->before['rate']);
        $this->assertEquals(36.5, (float) $event->after['rate']);
        $this->assertSame('USD', $event->after['base_currency']);
        $this->assertTrue($event->after['is_active']);
        $this->assertSame('job', $event->context['source']);
        $this->assertSame($anterior->id, $event->context['previous_active_id']);
        $this->assertGlobalScope($event);
        $this->assertNoSecretsOrTimestamps($event);
    }

    public function test_scrape_exchange_rate_hace_rollback_si_falla_la_auditoria(): void
    {
        $anterior = ExchangeRate::where('is_active', true)->firstOrFail();
        $anterior->update(['rate' => 35.00]);
        $totalAntes = ExchangeRate::count();

        self::$failAudit = true;

        $job = new AuditoriaScrapeExchangeRateJobFake(
            file_get_contents(base_path('tests/Fixtures/bcv_dolar.html'))
        );

        $this->expectAuditWriteFailure(fn () => $job->handle());

        $this->assertSame($totalAntes, ExchangeRate::count());
        $this->assertFalse(ExchangeRate::where('rate', 36.50)->exists());
        $this->assertSame(0, AuditEvent::where('action', '!=', 'permission.catalog_synced')->count());

        $anterior->refresh();
        $this->assertTrue($anterior->is_active);
        $this->assertEquals(35.0, (float) $anterior->rate);
    }

    // ------------------------------------------------------------------
    // ticket.expired (P0)
    // ------------------------------------------------------------------

    public function test_ticket_expirado_registra_evento_p0_con_before_after(): void
    {
        [$ticket, $apuestas, $banca, $grupo, $taquilla] = $this->ticketGanadorExpirado();

        (new ExpireUnclaimedPrizesJob)->handle();

        $ticket->refresh();
        $this->assertSame('vencido', $ticket->estado);
        foreach ($apuestas as $apuesta) {
            $this->assertSame('vencido', $apuesta->fresh()->estado);
        }

        $event = $this->lastEvent('ticket.expired');

        $this->assertSystemRequestless($event);
        $this->assertSame('job:ExpireUnclaimedPrizesJob', $event->actor_label);
        $this->assertSame('ticket', $event->entity_type);
        $this->assertSame($ticket->id, $event->entity_id);
        $this->assertSame(['estado' => 'ganador'], $event->before);
        $this->assertSame(['estado' => 'vencido'], $event->after);
        $this->assertSame($banca->id, $event->banca_id);
        $this->assertSame($grupo->id, $event->grupo_id);
        $this->assertSame($taquilla->id, $event->taquilla_id);
        $this->assertSame('job', $event->context['source']);
        $this->assertSame($apuestas->count(), $event->context['apuestas_count']);
        $this->assertSame(
            $apuestas->pluck('id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all(),
            collect($event->context['apuestas'])->sort()->values()->all(),
        );
        $this->assertNoSecretsOrTimestamps($event);
    }

    public function test_ticket_expirado_hace_rollback_si_falla_la_auditoria(): void
    {
        [$ticket, $apuestas] = $this->ticketGanadorExpirado();

        self::$failAudit = true;

        $this->expectAuditWriteFailure(fn () => (new ExpireUnclaimedPrizesJob)->handle());

        $this->assertSame('ganador', $ticket->fresh()->estado);
        foreach ($apuestas as $apuesta) {
            $this->assertSame('pendiente', $apuesta->fresh()->estado);
        }
        $this->assertSame(0, AuditEvent::where('action', '!=', 'permission.catalog_synced')->count());
    }

    // ------------------------------------------------------------------
    // bet.expired (P0)
    // ------------------------------------------------------------------

    public function test_apuestas_vencidas_registran_un_evento_por_apuesta(): void
    {
        [$banca, $grupo, $taquilla] = $this->jerarquia();
        $juego = $this->juegoSinScraper();
        $apuestaA = $this->apuestaVieja($juego, $taquilla);
        $apuestaB = $this->apuestaVieja($juego, $taquilla);

        (new MarcarApuestasVencidasJob)->handle();

        $this->assertSame('vencido', $apuestaA->fresh()->estado);
        $this->assertSame('vencido', $apuestaB->fresh()->estado);
        $this->assertSame(2, AuditEvent::where('action', 'bet.expired')->count());

        foreach ([$apuestaA, $apuestaB] as $apuesta) {
            $event = AuditEvent::where('action', 'bet.expired')
                ->where('entity_id', $apuesta->id)
                ->firstOrFail();

            $this->assertSystemRequestless($event);
            $this->assertSame('job:MarcarApuestasVencidasJob', $event->actor_label);
            $this->assertSame('apuesta', $event->entity_type);
            $this->assertSame($apuesta->id, $event->entity_id);
            $this->assertSame(['estado' => 'pendiente'], $event->before);
            $this->assertSame(['estado' => 'vencido'], $event->after);
            $this->assertSame($banca->id, $event->banca_id);
            $this->assertSame($grupo->id, $event->grupo_id);
            $this->assertSame($taquilla->id, $event->taquilla_id);
            $this->assertSame($juego->id, $event->context['juego_id']);
            $this->assertSame('job', $event->context['source']);
            $this->assertNoSecretsOrTimestamps($event);
        }
    }

    public function test_apuestas_vencidas_hacen_rollback_si_falla_la_auditoria(): void
    {
        [$banca, $grupo, $taquilla] = $this->jerarquia();
        $juego = $this->juegoSinScraper();
        $apuestaA = $this->apuestaVieja($juego, $taquilla);
        $apuestaB = $this->apuestaVieja($juego, $taquilla);

        self::$failAudit = true;

        $this->expectAuditWriteFailure(fn () => (new MarcarApuestasVencidasJob)->handle());

        $this->assertSame('pendiente', $apuestaA->fresh()->estado);
        $this->assertSame('pendiente', $apuestaB->fresh()->estado);
        $this->assertSame(0, AuditEvent::where('action', '!=', 'permission.catalog_synced')->count());
    }
}
