<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\AnimalitosScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnimalitosScraperTest extends TestCase
{
    use RefreshDatabase;

    protected AnimalitosScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        // Registro explícito de los juegos del feed (fail-fast: el scraper ya no crea juegos)
        Juego::create([
            'name' => 'Lotto Activo',
            'slug' => 'lotto-activo',
            'type' => 'animalitos',
            'requires_scraper' => true,
            'active' => true,
        ]);
        Juego::create([
            'name' => 'Lotto Activo RD',
            'slug' => 'lotto-activo-rd',
            'type' => 'animalitos',
            'requires_scraper' => true,
            'active' => true,
        ]);
        Juego::create([
            'name' => 'Lotto Activo República Dominicana',
            'slug' => 'lotto-activo-rep-dom',
            'type' => 'animalitos',
            'requires_scraper' => true,
            'active' => true,
        ]);
        Juego::create([
            'name' => 'Monje Millonario',
            'slug' => 'monje-millonario',
            'type' => 'animalitos',
            'requires_scraper' => true,
            'active' => true,
        ]);
        Juego::create([
            'name' => 'Terminal Activo',
            'slug' => 'terminal-activo',
            'type' => 'terminales',
            'requires_scraper' => true,
            'active' => true,
        ]);
        Juego::create([
            'name' => 'Trío Activo',
            'slug' => 'trio-activo',
            'type' => 'tripletas',
            'requires_scraper' => true,
            'active' => true,
        ]);

        $this->scraper = new AnimalitosScraper;
    }

    public function test_extracts_token_from_html()
    {
        $html = file_get_contents(base_path('tests/Fixtures/animalitos_page.html'));

        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod('extractToken');
        $method->setAccessible(true);

        $token = $method->invoke($this->scraper, $html);

        $this->assertNotNull($token);
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function test_parses_json_response()
    {
        $json = file_get_contents(base_path('tests/Fixtures/animalitos_response.json'));

        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        $resultados = $method->invoke($this->scraper, $json);

        $this->assertIsArray($resultados);
        $this->assertNotEmpty($resultados);
        $this->assertCount(6, $resultados);
    }

    public function test_maps_to_resultado_structure()
    {
        $json = file_get_contents(base_path('tests/Fixtures/animalitos_response.json'));

        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        $resultados = $method->invoke($this->scraper, $json);

        $first = $resultados[0];

        $this->assertArrayHasKey('juego_id', $first);
        $this->assertArrayHasKey('fecha_sorteo', $first);
        $this->assertArrayHasKey('hora_sorteo', $first);
        $this->assertArrayHasKey('numeros_ganadores', $first);

        $numeros = $first['numeros_ganadores'];
        $this->assertArrayHasKey('nombre_animal', $numeros);
        $this->assertArrayHasKey('imagen_animal', $numeros);
        $this->assertArrayHasKey('color_animal', $numeros);
        $this->assertArrayHasKey('pais', $numeros);

        $this->assertEquals('Delfin', $numeros['nombre_animal']);
        $this->assertEquals('10:00 AM', $first['hora_sorteo']);
        $this->assertEquals('Venezuela', $numeros['pais']);
    }

    public function test_handles_empty_results()
    {
        $json = json_encode(['datos' => []]);

        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        $resultados = $method->invoke($this->scraper, $json);

        $this->assertIsArray($resultados);
        $this->assertEmpty($resultados);
    }

    public function test_handles_invalid_json()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Error al decodificar JSON');

        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        $method->invoke($this->scraper, 'invalid json');
    }

    public function test_extracts_token_from_terminal_activo_page()
    {
        $html = file_get_contents(base_path('tests/Fixtures/lottoactivo_terminal_activo_page.html'));
        $this->assertNotFalse($html, 'Fixture lottoactivo_terminal_activo_page.html requerido');

        $scraper = new AnimalitosScraper('terminal_activo');

        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('extractToken');
        $method->setAccessible(true);

        $token = $method->invoke($scraper, $html);

        $this->assertNotNull($token);
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function test_extracts_token_from_trio_activo_page()
    {
        $html = file_get_contents(base_path('tests/Fixtures/lottoactivo_trio_activo_page.html'));
        $this->assertNotFalse($html, 'Fixture lottoactivo_trio_activo_page.html requerido');

        $scraper = new AnimalitosScraper('trio_activo');

        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('extractToken');
        $method->setAccessible(true);

        $token = $method->invoke($scraper, $html);

        $this->assertNotNull($token);
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function test_parses_terminal_activo_flat_response()
    {
        $json = file_get_contents(base_path('tests/Fixtures/lottoactivo_terminal_activo_response.json'));
        $this->assertNotFalse($json, 'Fixture lottoactivo_terminal_activo_response.json requerido');

        $scraper = new AnimalitosScraper('terminal_activo');

        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        $resultados = $method->invoke($scraper, $json);

        $this->assertCount(3, $resultados);

        $terminal = Juego::where('slug', 'terminal-activo')->first();
        $this->assertNotNull($terminal);

        foreach ($resultados as $resultado) {
            $this->assertEquals($terminal->id, $resultado['juego_id']);
            $this->assertArrayHasKey('numero', $resultado['numeros_ganadores']);
            $this->assertArrayNotHasKey('nombre_animal', $resultado['numeros_ganadores']);
        }

        // Primer sorteo real del 2026-09-12: 41 a las 08:00 AM (id 5)
        $this->assertSame(41, $resultados[0]['numeros_ganadores']['numero']);
        $this->assertSame('08:00 AM', $resultados[0]['hora_sorteo']);
        $this->assertSame('5', $resultados[0]['sorteo_id_externo']);
    }

    public function test_parses_trio_activo_flat_response_as_tripletas()
    {
        $json = file_get_contents(base_path('tests/Fixtures/lottoactivo_trio_activo_response.json'));
        $this->assertNotFalse($json, 'Fixture lottoactivo_trio_activo_response.json requerido');

        $scraper = new AnimalitosScraper('trio_activo');

        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        $resultados = $method->invoke($scraper, $json);

        $this->assertCount(3, $resultados);

        $trio = Juego::where('slug', 'trio-activo')->first();
        $this->assertNotNull($trio);

        foreach ($resultados as $resultado) {
            $this->assertEquals($trio->id, $resultado['juego_id']);
            $this->assertArrayHasKey('triple_a', $resultado['numeros_ganadores']);
            $this->assertArrayNotHasKey('nombre_animal', $resultado['numeros_ganadores']);
        }

        // Primer sorteo real del 2026-09-12: 941 a las 08:00 AM (id 4)
        $this->assertSame('941', $resultados[0]['numeros_ganadores']['triple_a']);
        $this->assertSame('08:00 AM', $resultados[0]['hora_sorteo']);
        $this->assertSame('4', $resultados[0]['sorteo_id_externo']);
    }

    public function test_parses_real_nested_feed_with_monje_and_rd_games()
    {
        $json = file_get_contents(base_path('tests/Fixtures/lottoactivo_animalitos_response.json'));
        $this->assertNotFalse($json, 'Fixture lottoactivo_animalitos_response.json requerido');

        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        $resultados = $method->invoke($this->scraper, $json);

        // Feed real 2026-09-12: Lotto Activo (3) + RD Internacional (2) + RD (3) + Monje (3)
        $this->assertCount(11, $resultados);

        $lottoActivo = Juego::where('slug', 'lotto-activo')->first()->id;
        $rdInternacional = Juego::where('slug', 'lotto-activo-rd')->first()->id;
        $repDom = Juego::where('slug', 'lotto-activo-rep-dom')->first()->id;
        $monje = Juego::where('slug', 'monje-millonario')->first()->id;

        $ids = array_map(fn ($r) => $r['juego_id'], $resultados);

        $this->assertCount(3, array_filter($ids, fn ($id) => $id === $lottoActivo));
        $this->assertCount(2, array_filter($ids, fn ($id) => $id === $rdInternacional));
        $this->assertCount(3, array_filter($ids, fn ($id) => $id === $repDom));
        $this->assertCount(3, array_filter($ids, fn ($id) => $id === $monje));

        // Orden del feed: [Lotto Activo ×3, RD Internacional ×2, República Dominicana ×3, Monje ×3]
        $this->assertEquals($monje, $resultados[8]['juego_id']);
        $this->assertSame('Tiburon', $resultados[8]['numeros_ganadores']['nombre_animal']);
        $this->assertSame('República Dominicana', $resultados[5]['numeros_ganadores']['pais']);
        $this->assertSame('Venezuela', $resultados[0]['numeros_ganadores']['pais']);
    }
}
