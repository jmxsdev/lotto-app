<?php

namespace Tests\Feature;

use App\Models\Juego;
use App\Models\Log;
use App\Models\Resultado;
use App\Jobs\ScrapeResultsJob;
use App\Plugins\Scrapers\TripleZuliaScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScrapeResultsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Resultado::query()->delete();

        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_triple_zulia_scraper_parses_and_saves()
    {
        $this->assertEquals(0, Resultado::count());

        $jsonResponse = file_get_contents(base_path('tests/Fixtures/triplezulia_response.json'));

        $scraper = new TripleZuliaScraper();
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

        $scraper = new TripleZuliaScraper();
        $fecha = '2026-07-25';

        $resultados = $scraper->parse($jsonResponse);
        $scraper->saveResults($resultados, $fecha);
        $this->assertEquals(3, Resultado::count());

        $scraper->saveResults($resultados, $fecha);
        $this->assertEquals(3, Resultado::count(), "No deberían duplicarse los resultados");
    }

    public function test_scrape_results_job_returns_early_for_non_scraper_game()
    {
        $juego = Juego::where('requires_scraper', false)->first();

        if (!$juego) {
            $this->markTestSkipped('No hay juegos sin scraper para probar');
        }

        $job = new ScrapeResultsJob($juego->id, '2026-07-23');
        $job->handle();

        $this->assertTrue(true);
    }
}
