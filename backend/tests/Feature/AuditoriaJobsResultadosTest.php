<?php

namespace Tests\Feature;

use App\Jobs\ScrapeResultsJob;
use App\Jobs\ScrapeSourceJob;
use App\Models\AuditEvent;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\Release;
use App\Models\Resultado;
use App\Plugins\Scrapers\AnimalitosScraper;
use App\Plugins\Scrapers\BaseScraper;
use App\Services\ApuestaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Doble de AnimalitosScraper que corta SOLO el fetch de red: conserva el
 * parse/saveResults reales (misma familia del feed lottoactivo).
 */
class AuditoriaAnimalitosScraperFake extends AnimalitosScraper
{
    public function execute(?string $fecha = null): array
    {
        $raw = file_get_contents(base_path('tests/Fixtures/lottoactivo_animalitos_response.json'));

        return $this->parse($raw);
    }
}

/**
 * Scraper mínimo para ScrapeResultsJob: un único sorteo por corrida.
 */
class AuditoriaResultadoScraperFake extends BaseScraper
{
    protected string $scraperName = 'AuditoriaResultadoScraperFake';

    protected ?Juego $juego;

    public function __construct(?Juego $juego = null)
    {
        parent::__construct();
        $this->juego = $juego;
    }

    public function fetch(string $fecha): string
    {
        return '{}';
    }

    public function parse(string $rawData): array
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
 * WU-8b — auditoría de ingesta de resultados y publicación de releases (P1).
 *
 * `draw.result_ingested`, `draw.settlement_completed` y `release.published`
 * son P1 best-effort con actor `system`: un fallo de auditoría no revierte la
 * ingesta/liquidación ni cambia el resultado de la release. Ningún evento
 * expone IP, user-agent, request id, secretos ni timestamps técnicos.
 */
class AuditoriaJobsResultadosTest extends TestCase
{
    use RefreshDatabase;

    private static bool $failAudit = false;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function fakeQueueJob(string $id, string $queue): object
    {
        return new class($id, $queue)
        {
            public function __construct(
                private readonly string $id,
                private readonly string $queue,
            ) {}

            public function getJobId(): string
            {
                return $this->id;
            }

            public function getQueue(): string
            {
                return $this->queue;
            }
        };
    }

    private function mockApuestaService(int $ganadoras): void
    {
        $mock = Mockery::mock(ApuestaService::class);
        $mock->shouldReceive('verificarGanadores')->andReturn($ganadoras);

        $this->app->instance(ApuestaService::class, $mock);
    }

    private function crearJuego(string $slug, string $name, string $url): Juego
    {
        $juego = Juego::create([
            'slug' => $slug,
            'name' => $name,
            'type' => 'animalitos',
            'requires_scraper' => true,
            'scraper_url' => $url,
            'active' => true,
        ]);

        JuegoHorario::create([
            'juego_id' => $juego->id,
            'hora' => '08:00:00',
            'active' => true,
        ]);

        return $juego;
    }

    /**
     * @return array{0: Juego, 1: Juego, 2: Juego, 3: Juego}
     */
    private function crearFamilia(): array
    {
        $lotto = $this->crearJuego('lotto-activo', 'Lotto Activo', 'https://www.lottoactivo.com/resultados/animalitos/');
        $rd = $this->crearJuego('lotto-activo-rd', 'Lotto Activo RD Internacional', 'https://www.lottoactivo.com/resultados/animalitos/');
        $repDom = $this->crearJuego('lotto-activo-rep-dom', 'Lotto Activo República Dominicana', 'https://www.lottoactivo.com/resultados/animalitos/');
        $monje = $this->crearJuego('monje-millonario', 'Monje Millonario', 'https://www.lottoactivo.com/resultados/animalitos/');

        return [$lotto, $rd, $repDom, $monje];
    }

    private function fakeAnimalitosScraper(): void
    {
        $stub = new AuditoriaAnimalitosScraperFake('animalitos');

        $this->app->bind(AnimalitosScraper::class, fn () => $stub);
    }

    private function juegoEscribible(): Juego
    {
        return Juego::create([
            'name' => 'Juego Resultados Audit',
            'slug' => 'juego-resultados-audit',
            'type' => 'animalitos',
            'requires_scraper' => true,
            'scraper_class' => AuditoriaResultadoScraperFake::class,
            'active' => true,
        ]);
    }

    private function assertSystemRequestless(AuditEvent $event): void
    {
        $this->assertSame('system', $event->actor_type);
        $this->assertNull($event->actor_id);
        $this->assertNull($event->ip);
        $this->assertNull($event->user_agent);
        $this->assertNull($event->request_id);
    }

    private function assertGlobalScope(AuditEvent $event): void
    {
        $this->assertNull($event->banca_id);
        $this->assertNull($event->grupo_id);
        $this->assertNull($event->agencia_id);
        $this->assertNull($event->taquilla_id);
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
    // draw.result_ingested / draw.settlement_completed — ScrapeSourceJob
    // ------------------------------------------------------------------

    public function test_scrape_source_job_emite_ingesta_y_liquidacion_p1_system(): void
    {
        [$lotto] = $this->crearFamilia();
        $this->fakeAnimalitosScraper();
        $this->mockApuestaService(1);

        $job = new ScrapeSourceJob('lottoactivo-animalitos', '2026-09-12', '08:00');
        $job->job = $this->fakeQueueJob('job-results-1', 'draws');
        $job->handle();

        $this->assertSame(11, Resultado::count());
        $this->assertSame(2, AuditEvent::whereIn('action', ['draw.result_ingested', 'draw.settlement_completed'])->count());

        $ingesta = $this->lastEvent('draw.result_ingested');
        $this->assertSystemRequestless($ingesta);
        $this->assertSame('job:ScrapeSourceJob', $ingesta->actor_label);
        $this->assertSame('job-results-1', $ingesta->job_id);
        $this->assertSame('draws', $ingesta->context['queue']);
        $this->assertSame('job', $ingesta->context['source']);
        $this->assertSame('resultado', $ingesta->entity_type);
        $this->assertSame(11, $ingesta->after['total']);
        $this->assertSame(3, $ingesta->after['por_juego'][$lotto->id]);
        $this->assertCount(11, $ingesta->after['resultados']);
        $this->assertSame('lottoactivo-animalitos', $ingesta->context['source_key']);
        $this->assertSame('2026-09-12', $ingesta->context['fecha']);
        $this->assertSame('08:00', $ingesta->context['hora_objetivo']);
        $this->assertGlobalScope($ingesta);
        $this->assertNoSecretsOrTimestamps($ingesta);

        $settlement = $this->lastEvent('draw.settlement_completed');
        $this->assertSystemRequestless($settlement);
        $this->assertSame('job:ScrapeSourceJob', $settlement->actor_label);
        $this->assertSame('resultado', $settlement->entity_type);
        $this->assertNull($settlement->entity_id);
        $this->assertSame(11, $settlement->after['ganadoras']);
        $this->assertSame(11, $settlement->context['resultados_evaluados']);
        $this->assertSame('job', $settlement->context['source']);
        $this->assertGlobalScope($settlement);
        $this->assertNoSecretsOrTimestamps($settlement);
    }

    // ------------------------------------------------------------------
    // draw.result_ingested / draw.settlement_completed — ScrapeResultsJob
    // ------------------------------------------------------------------

    public function test_scrape_results_job_emite_ingesta_y_liquidacion_p1_system(): void
    {
        $juego = $this->juegoEscribible();
        $this->mockApuestaService(2);

        $job = new ScrapeResultsJob($juego->id, '2026-09-12');
        $job->job = $this->fakeQueueJob('job-results-2', 'draws');
        $job->handle();

        $this->assertSame(1, Resultado::where('juego_id', $juego->id)->count());

        $ingesta = $this->lastEvent('draw.result_ingested');
        $this->assertSystemRequestless($ingesta);
        $this->assertSame('job:ScrapeResultsJob', $ingesta->actor_label);
        $this->assertSame('job-results-2', $ingesta->job_id);
        $this->assertSame('draws', $ingesta->context['queue']);
        $this->assertSame('resultado', $ingesta->entity_type);
        $this->assertNotNull($ingesta->entity_id);
        $this->assertSame(1, $ingesta->after['guardados']);
        $this->assertSame($juego->id, $ingesta->after['juego_id']);
        $this->assertCount(1, $ingesta->after['resultados']);
        $this->assertSame('2026-09-12', $ingesta->context['fecha']);
        $this->assertSame($juego->name, $ingesta->context['juego']);
        $this->assertGlobalScope($ingesta);
        $this->assertNoSecretsOrTimestamps($ingesta);

        $settlement = $this->lastEvent('draw.settlement_completed');
        $this->assertSystemRequestless($settlement);
        $this->assertSame('job:ScrapeResultsJob', $settlement->actor_label);
        $this->assertSame('resultado', $settlement->entity_type);
        $this->assertNull($settlement->entity_id);
        $this->assertSame(2, $settlement->after['ganadoras']);
        $this->assertSame(1, $settlement->context['resultados_evaluados']);
        $this->assertSame($juego->id, $settlement->context['juego_id']);
        $this->assertGlobalScope($settlement);
        $this->assertNoSecretsOrTimestamps($settlement);
    }

    public function test_scrape_results_job_no_revierte_ni_falla_si_la_auditoria_falla(): void
    {
        $juego = $this->juegoEscribible();
        $this->mockApuestaService(1);

        self::$failAudit = true;

        $job = new ScrapeResultsJob($juego->id, '2026-09-12');
        $job->handle();

        // P1 best-effort: la ingesta y la liquidación siguen adelante.
        $this->assertSame(1, Resultado::where('juego_id', $juego->id)->count());
        $this->assertSame(0, AuditEvent::count());
    }

    // ------------------------------------------------------------------
    // release.published — ReleasePublishCommand
    // ------------------------------------------------------------------

    private function escribirInstalador(string $filename, string $contenido = 'binario-auditoria'): string
    {
        $path = sys_get_temp_dir().'/'.$filename;
        file_put_contents($path, $contenido);

        return $path;
    }

    public function test_release_publish_emite_release_published_p1_system(): void
    {
        Storage::fake('releases');
        $path = $this->escribirInstalador('Taquilla-Setup-3.1.0.exe', 'binario-auditoria');

        $this->artisan('releases:publish', ['path' => $path, '--reason-code' => 'data_correction'])
            ->assertSuccessful();

        $release = Release::query()->current()->firstOrFail();
        $event = $this->lastEvent('release.published');

        $this->assertSystemRequestless($event);
        $this->assertSame('command:releases:publish', $event->actor_label);
        $this->assertSame('release', $event->entity_type);
        $this->assertSame($release->id, $event->entity_id);
        $this->assertSame('3.1.0', $event->after['version']);
        $this->assertSame(hash('sha256', 'binario-auditoria'), $event->after['sha256']);
        $this->assertSame('Taquilla-Setup-3.1.0.exe', $event->after['file_path']);
        $this->assertSame(strlen('binario-auditoria'), $event->after['file_size']);
        $this->assertArrayHasKey('published_at', $event->after);
        $this->assertSame('releases:publish', $event->context['command']);
        $this->assertFalse($event->context['latest_yml']);
        $this->assertSame('data_correction', $event->reason_code);
        $this->assertGlobalScope($event);
        $this->assertNoSecretsOrTimestamps($event);
    }

    public function test_release_publish_con_motivo_invalido_cae_a_business_decision(): void
    {
        Storage::fake('releases');
        $path = $this->escribirInstalador('Taquilla-Setup-3.2.0.exe');

        $this->artisan('releases:publish', ['path' => $path, '--reason-code' => 'not_a_reason'])
            ->assertSuccessful();

        $event = $this->lastEvent('release.published');

        $this->assertSame('business_decision', $event->reason_code);
    }

    public function test_release_publish_sin_auditoria_conserva_exit_code_y_fila(): void
    {
        Storage::fake('releases');
        $path = $this->escribirInstalador('Taquilla-Setup-3.3.0.exe');

        self::$failAudit = true;

        $this->artisan('releases:publish', ['path' => $path])->assertSuccessful();

        $this->assertSame(1, Release::count());
        $this->assertSame('3.3.0', Release::query()->current()->firstOrFail()->version);
        $this->assertSame(0, AuditEvent::count());
        Storage::disk('releases')->assertExists('Taquilla-Setup-3.3.0.exe');
    }
}
