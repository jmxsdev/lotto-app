<?php

namespace Tests\Feature;

use App\Jobs\ScrapeResultsJob;
use App\Models\Juego;
use App\Models\Resultado;
use App\Plugins\Scrapers\AnimalitosScraper;
use App\Plugins\Scrapers\BaseScraper;
use App\Plugins\Scrapers\TripletasScraper;
use App\Services\ApuestaService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Scraper fake para el guard de dedupe (N6): devuelve un sorteo de 09:00 que
 * el job persiste junto a duplicados pre-H22 sembrados en la prueba.
 */
class FakeDedupeScraper extends BaseScraper
{
    protected string $scraperName = 'FakeDedupeScraper';

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
        $juego = $this->findJuegoOrFail([
            'slug' => $this->juego?->slug,
            'name' => $this->juego?->name,
        ]);

        return [[
            'juego_id' => $juego->id,
            'hora_sorteo' => '09:00',
            'numeros_ganadores' => ['numero' => 42, 'nombre_animal' => 'Cebra', 'pais' => 'VE'],
            'sorteo_id_externo' => null,
            'premios_detalle' => null,
        ]];
    }
}

class ScrapeResultsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Resultado::query()->delete();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_resolves_animalitos_scraper_by_convention()
    {
        $juego = Juego::where('slug', 'lotto-activo')->first();
        $this->assertNotNull($juego);

        $job = new ScrapeResultsJob($juego->id, '2026-07-23');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('resolveScraper');
        $method->setAccessible(true);

        $scraperClass = $method->invoke($job, $juego);
        $this->assertEquals(AnimalitosScraper::class, $scraperClass);
    }

    public function test_resolves_triple_zulia_scraper_by_convention()
    {
        $juego = Juego::where('slug', 'triple-zulia')->first();
        $this->assertNotNull($juego);

        $job = new ScrapeResultsJob($juego->id, '2026-07-23');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('resolveScraper');
        $method->setAccessible(true);

        $scraperClass = $method->invoke($job, $juego);
        $this->assertEquals(TripletasScraper::class, $scraperClass);
    }

    public function test_triple_zulia_scraper_parses_and_saves()
    {
        $this->assertEquals(0, Resultado::count());

        $jsonResponse = file_get_contents(base_path('tests/Fixtures/triplezulia_response.json'));

        $scraper = new TripletasScraper;
        $fecha = '2026-07-25';

        $resultados = $scraper->parse($jsonResponse);
        $guardados = $scraper->saveResults($resultados, $fecha);

        $this->assertEquals(3, count($resultados));
        $this->assertEquals(3, $guardados);
        $this->assertEquals(3, Resultado::count());

        $numeros = Resultado::first()->numeros_ganadores;
        $this->assertArrayHasKey('triple_a', $numeros);
        $this->assertArrayHasKey('signo', $numeros);
    }

    public function test_triple_zulia_scraper_avoids_duplicates()
    {
        $this->assertEquals(0, Resultado::count());

        $jsonResponse = file_get_contents(base_path('tests/Fixtures/triplezulia_response.json'));

        $scraper = new TripletasScraper;
        $fecha = '2026-07-25';

        $resultados = $scraper->parse($jsonResponse);
        $scraper->saveResults($resultados, $fecha);
        $this->assertEquals(3, Resultado::count());

        $scraper->saveResults($resultados, $fecha);
        $this->assertEquals(3, Resultado::count(), 'No deberían duplicarse los resultados');
    }

    public function test_scrape_results_job_returns_early_for_non_scraper_game()
    {
        $juego = Juego::where('requires_scraper', false)->first();

        if (! $juego) {
            $this->markTestSkipped('No hay juegos sin scraper para probar');
        }

        $job = new ScrapeResultsJob($juego->id, '2026-07-23');
        $job->handle();

        $this->assertTrue(true);
    }

    // ---------------- Guard de dedupe del día (N6, D6-b) ----------------

    public function test_dedupe_conserva_la_fila_mas_completa_por_sorteo()
    {
        $juego = Juego::create([
            'name' => 'Juego Dedupe',
            'slug' => 'juego-dedupe',
            'type' => 'animalitos',
            'requires_scraper' => true,
            'active' => true,
        ]);

        // Duplicados pre-H22 simulados con formatos legacy de hora: strings
        // distintos para el índice único, misma clave normalizada para el guard.
        $fecha = '2026-09-14';
        $pobre = Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => $fecha.' 00:00:00',
            'hora_sorteo' => '08:00',
            'numeros_ganadores' => ['numero' => 22],
            'premios_detalle' => null,
        ]);
        $completa = Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => $fecha.' 00:00:00',
            'hora_sorteo' => '08:00:00',
            'numeros_ganadores' => ['numero' => 22, 'nombre_animal' => 'Camello', 'color_animal' => 'negro', 'pais' => 'VE'],
            'premios_detalle' => null,
        ]);
        Resultado::where('id', $completa->id)->update(['updated_at' => $pobre->updated_at->copy()->addHour()]);

        $job = new ScrapeResultsJob($juego->id, $fecha);
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('dedupeResultadosDelDia');
        $method->setAccessible(true);

        $dedupe = $method->invoke($job, Resultado::all());

        $this->assertCount(1, $dedupe);
        $this->assertEquals($completa->id, $dedupe->first()->id, 'Debe conservarse la fila con más claves');
    }

    public function test_dedupe_desempata_por_updated_at_mas_reciente()
    {
        $juego = Juego::create([
            'name' => 'Juego Dedupe',
            'slug' => 'juego-dedupe',
            'type' => 'animalitos',
            'requires_scraper' => true,
            'active' => true,
        ]);

        $fecha = '2026-09-14';
        $vieja = Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => $fecha.' 00:00:00',
            'hora_sorteo' => '10:00',
            'numeros_ganadores' => ['numero' => 1, 'nombre_animal' => 'Ballena'],
            'premios_detalle' => null,
        ]);
        $nueva = Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => $fecha.' 00:00:00',
            'hora_sorteo' => '10:00:00',
            'numeros_ganadores' => ['numero' => 1, 'nombre_animal' => 'Ballena'],
            'premios_detalle' => null,
        ]);
        Resultado::where('id', $nueva->id)->update(['updated_at' => $vieja->updated_at->copy()->addHour()]);

        $job = new ScrapeResultsJob($juego->id, $fecha);
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('dedupeResultadosDelDia');
        $method->setAccessible(true);

        $dedupe = $method->invoke($job, Resultado::all());

        $this->assertCount(1, $dedupe);
        $this->assertEquals($nueva->id, $dedupe->first()->id, 'A igualdad de claves gana la updated_at más reciente');
    }

    public function test_job_evalua_una_sola_vez_cada_sorteo_aunque_haya_duplicados()
    {
        // REQ14/N6: dos filas pre-H22 del mismo sorteo (08:00 / 08:00:00) +
        // un sorteo nuevo (09:00) → verificarGanadores debe ejecutarse UNA vez
        // por sorteo.
        $juego = Juego::create([
            'name' => 'Juego Dedupe',
            'slug' => 'juego-dedupe',
            'type' => 'animalitos',
            'requires_scraper' => true,
            'scraper_url' => 'https://fake.test/resultados/',
            'scraper_class' => FakeDedupeScraper::class,
            'active' => true,
        ]);

        $fecha = '2026-09-14';
        Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => $fecha.' 00:00:00',
            'hora_sorteo' => '08:00',
            'numeros_ganadores' => ['numero' => 22],
            'premios_detalle' => null,
        ]);
        Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => $fecha.' 00:00:00',
            'hora_sorteo' => '08:00:00',
            'numeros_ganadores' => ['numero' => 22, 'nombre_animal' => 'Camello', 'color_animal' => 'negro', 'pais' => 'VE'],
            'premios_detalle' => null,
        ]);

        $llamadas = [];
        $mock = Mockery::mock(ApuestaService::class);
        $mock->shouldReceive('verificarGanadores')
            ->andReturnUsing(function (Resultado $r) use (&$llamadas) {
                $llamadas[] = substr((string) $r->hora_sorteo, 0, 5);

                return 1;
            });
        $this->app->instance(ApuestaService::class, $mock);

        (new ScrapeResultsJob($juego->id, $fecha))->handle();

        $this->assertCount(2, $llamadas, 'Cada sorteo debe evaluarse una sola vez');
        $this->assertEqualsCanonicalizing(['08:00', '09:00'], $llamadas);
    }
}
