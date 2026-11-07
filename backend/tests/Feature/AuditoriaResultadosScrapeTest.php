<?php

namespace Tests\Feature;

use App\Audit\AuditAction;
use App\Audit\AuditPriority;
use App\Jobs\ScrapeSourceJob;
use App\Models\AuditEvent;
use App\Models\Juego;
use App\Models\Resultado;
use App\Models\User;
use App\Plugins\Scrapers\BaseScraper;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Scraper mínimo para el endpoint `scrape` con `juego_id`: evita la red y
 * devuelve un único sorteo, de modo que el flujo real de ingesta siga
 * ejecutándose sin llamadas externas.
 */
class AuditoriaScrapeRequestFakeScraper extends BaseScraper
{
    protected string $scraperName = 'AuditoriaScrapeRequestFakeScraper';

    protected ?Juego $juego;

    public function __construct(?Juego $juego = null)
    {
        parent::__construct();
        $this->juego = $juego;
    }

    protected function fetch(string $fecha): string
    {
        return '{}';
    }

    protected function parse(string $rawData): array
    {
        return [[
            'juego_id' => $this->juego?->id,
            'hora_sorteo' => '10:00',
            'numeros_ganadores' => ['numero' => 7, 'nombre_animal' => 'Delfín', 'pais' => 'VE'],
            'sorteo_id_externo' => null,
            'premios_detalle' => null,
        ]];
    }
}

/**
 * WU-8 gap fix — auditoría de las solicitudes manuales de scrape.
 *
 * `draw.scrape_requested` y `draw.scrape_all_requested` son P1 best-effort:
 * se emiten tras validar que hay juegos y antes de ejecutar/despachar, con
 * actor `user` (request HTTP), `entity_type=resultado` y `context.source=api`.
 * Un fallo de auditoría nunca bloquea la respuesta ni el dispatch.
 */
class AuditoriaResultadosScrapeTest extends TestCase
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

    private function superUser(): User
    {
        $super = User::where('email', 'super@lotto.com')->firstOrFail();
        $super->assignRole('super_master');

        return $super;
    }

    private function juegoEscribible(): Juego
    {
        return Juego::create([
            'name' => 'Juego Scrape Audit',
            'slug' => 'juego-scrape-audit',
            'type' => 'animalitos',
            'requires_scraper' => true,
            'scraper_class' => AuditoriaScrapeRequestFakeScraper::class,
            'active' => true,
        ]);
    }

    private function lastEvent(string $action): AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->latest('id')->firstOrFail();
    }

    // ==================================================
    // draw.scrape_requested — POST /resultados/scrape
    // ==================================================

    public function test_scrape_con_juego_id_emite_draw_scrape_requested_p1_con_actor_user(): void
    {
        $actor = $this->superUser();
        $juego = $this->juegoEscribible();

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/resultados/scrape', [
                'juego_id' => $juego->id,
                'fecha' => '2026-09-12',
            ]);

        $response->assertOk();

        $this->assertSame(AuditPriority::P1, AuditAction::DrawScrapeRequested->priority());

        $event = $this->lastEvent('draw.scrape_requested');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('resultado', $event->entity_type);
        $this->assertNull($event->entity_id);
        $this->assertSame('api', $event->context['source']);
        $this->assertSame($juego->id, $event->context['juego_id']);
        $this->assertSame('2026-09-12', $event->context['fecha']);
        $this->assertNull($event->reason_code);
    }

    public function test_scrape_sin_juego_id_emite_draw_scrape_requested_con_juego_id_null(): void
    {
        Queue::fake();

        $actor = $this->superUser();

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/resultados/scrape', ['fecha' => '2026-09-12']);

        $response->assertOk();

        $event = $this->lastEvent('draw.scrape_requested');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('resultado', $event->entity_type);
        $this->assertNull($event->context['juego_id']);
        $this->assertSame('api', $event->context['source']);
        $this->assertSame('2026-09-12', $event->context['fecha']);
    }

    public function test_scrape_con_juego_id_inexistente_no_emite_evento(): void
    {
        $actor = $this->superUser();

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/resultados/scrape', [
                'juego_id' => 999999,
                'fecha' => '2026-09-12',
            ]);

        $response->assertNotFound();

        $this->assertSame(0, AuditEvent::where('action', 'draw.scrape_requested')->count());
    }

    // ==================================================
    // draw.scrape_all_requested — POST /resultados/scrape-all
    // ==================================================

    public function test_scrape_all_emite_draw_scrape_all_requested_p1_con_actor_user(): void
    {
        Queue::fake();

        $actor = $this->superUser();
        $juegosActivos = Juego::where('requires_scraper', true)->where('active', true)->count();

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/resultados/scrape-all', ['fecha' => '2026-09-12']);

        $response->assertOk()->assertJsonPath('total_juegos', $juegosActivos);

        $this->assertSame(AuditPriority::P1, AuditAction::DrawScrapeAllRequested->priority());

        $event = $this->lastEvent('draw.scrape_all_requested');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('resultado', $event->entity_type);
        $this->assertNull($event->entity_id);
        $this->assertSame('api', $event->context['source']);
        $this->assertSame('2026-09-12', $event->context['fecha']);
        $this->assertSame($juegosActivos, $event->context['juegos']);
        $this->assertNull($event->reason_code);
    }

    public function test_scrape_all_sin_juegos_activos_no_emite_evento(): void
    {
        Queue::fake();

        $actor = $this->superUser();
        Juego::where('requires_scraper', true)->update(['active' => false]);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/resultados/scrape-all', ['fecha' => '2026-09-12']);

        $response->assertNotFound();

        $this->assertSame(0, AuditEvent::where('action', 'draw.scrape_all_requested')->count());
        Queue::assertNothingPushed();
    }

    // ==================================================
    // P1 best-effort — un fallo de auditoría no bloquea
    // ==================================================

    public function test_scrape_con_juego_id_no_bloquea_la_ejecucion_si_la_auditoria_falla(): void
    {
        $actor = $this->superUser();
        $juego = $this->juegoEscribible();

        self::$failAudit = true;

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/resultados/scrape', [
                'juego_id' => $juego->id,
                'fecha' => '2026-09-12',
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['message', 'fecha', 'resultados']);

        $this->assertSame(1, Resultado::where('juego_id', $juego->id)->count());
        $this->assertSame(0, AuditEvent::where('action', 'draw.scrape_requested')->count());
    }

    public function test_scrape_all_no_bloquea_el_dispatch_si_la_auditoria_falla(): void
    {
        Queue::fake();

        $actor = $this->superUser();

        self::$failAudit = true;

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/resultados/scrape-all', ['fecha' => '2026-09-12']);

        $response->assertOk();
        $response->assertJsonStructure(['message', 'fecha', 'total_juegos', 'resultados']);

        Queue::assertPushed(ScrapeSourceJob::class);
        $this->assertSame(0, AuditEvent::where('action', 'draw.scrape_all_requested')->count());
    }
}
