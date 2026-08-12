<?php

namespace Tests\Feature;

use App\Models\Log;
use App\Models\Resultado;
use App\Plugins\Scrapers\AnimalitosScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FetchResultsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Resultado::query()->delete();

        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->seed(\Database\Seeders\JuegoAnimalitosSeeder::class);
    }

    public function test_scraper_parses_and_saves_results_correctly()
    {
        $this->assertEquals(0, Resultado::count());

        $jsonResponse = file_get_contents(base_path('tests/Fixtures/animalitos_response.json'));

        $scraper = new AnimalitosScraper();
        $fecha = '2026-07-23';

        $resultados = $scraper->parse($jsonResponse);
        $guardados = $scraper->saveResults($resultados, $fecha);

        $this->assertEquals(6, count($resultados));
        $this->assertEquals(6, $guardados);
        $this->assertEquals(6, Resultado::count());

        $resultado = Resultado::all()->firstWhere(fn ($r) => ($r->numeros_ganadores['nombre_animal'] ?? null) === 'Delfin');
        $this->assertNotNull($resultado, "El resultado Delfin debería existir");
        $this->assertEquals('10:00 AM', $resultado->hora_sorteo);
        $this->assertEquals('Venezuela', $resultado->numeros_ganadores['pais']);
    }

    public function test_scraper_avoids_duplicates()
    {
        $this->assertEquals(0, Resultado::count());

        $jsonResponse = file_get_contents(base_path('tests/Fixtures/animalitos_response.json'));

        $scraper = new AnimalitosScraper();
        $fecha = '2026-07-23';

        $resultados1 = $scraper->parse($jsonResponse);
        $scraper->saveResults($resultados1, $fecha);

        $count1 = Resultado::count();
        $this->assertEquals(6, $count1);

        $resultados2 = $scraper->parse($jsonResponse);
        $scraper->saveResults($resultados2, $fecha);

        $count2 = Resultado::count();
        $this->assertEquals(6, $count2, "No deberían duplicarse los resultados");
    }

    public function test_scraper_handles_empty_response()
    {
        $this->assertEquals(0, Resultado::count());

        $emptyJson = json_encode(['datos' => []]);

        $scraper = new AnimalitosScraper();
        $fecha = '2026-07-24';

        $resultados = $scraper->parse($emptyJson);
        $guardados = $scraper->saveResults($resultados, $fecha);

        $this->assertEquals(0, count($resultados));
        $this->assertEquals(0, $guardados);
        $this->assertEquals(0, Resultado::count());
    }

    public function test_scraper_creates_log_on_success()
    {
        $this->assertEquals(0, Resultado::count());

        $jsonResponse = file_get_contents(base_path('tests/Fixtures/animalitos_response.json'));

        $scraper = new AnimalitosScraper();
        $fecha = '2026-07-25';

        $resultados = $scraper->parse($jsonResponse);
        $scraper->saveResults($resultados, $fecha);

        $this->assertEquals(6, Resultado::count());

        $resultadoDelfin = Resultado::all()->firstWhere(fn ($r) => ($r->numeros_ganadores['nombre_animal'] ?? null) === 'Delfin');
        $this->assertNotNull($resultadoDelfin);
        $this->assertEquals('10:00 AM', $resultadoDelfin->hora_sorteo);
    }
}
