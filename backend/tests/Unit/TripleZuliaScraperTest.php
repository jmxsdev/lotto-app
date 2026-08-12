<?php

namespace Tests\Unit;

use App\Plugins\Scrapers\TripletasScraper;
use Tests\TestCase;

class TripletasScraperTest extends TestCase
{
    protected TripletasScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scraper = new TripletasScraper();
    }

    public function test_parses_json_response()
    {
        $json = file_get_contents(base_path('tests/Fixtures/triplezulia_response.json'));

        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        $resultados = $method->invoke($this->scraper, $json);

        $this->assertIsArray($resultados);
        $this->assertNotEmpty($resultados);
        $this->assertCount(3, $resultados);
    }

    public function test_maps_to_resultado_structure()
    {
        $json = file_get_contents(base_path('tests/Fixtures/triplezulia_response.json'));

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
        $this->assertArrayHasKey('triple_a', $numeros);
        $this->assertArrayHasKey('triple_b', $numeros);
        $this->assertArrayHasKey('triple_c', $numeros);
        $this->assertArrayHasKey('signo', $numeros);
        $this->assertArrayHasKey('pais', $numeros);

        $this->assertEquals('VE', $numeros['pais']);
    }

    public function test_parses_c_format_correctly()
    {
        $json = file_get_contents(base_path('tests/Fixtures/triplezulia_response.json'));

        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        $resultados = $method->invoke($this->scraper, $json);

        foreach ($resultados as $resultado) {
            $numeros = $resultado['numeros_ganadores'];
            $this->assertMatchesRegularExpression('/^\d{3}$/', $numeros['triple_a']);
            $this->assertMatchesRegularExpression('/^\d{3}$/', $numeros['triple_b']);
            $this->assertMatchesRegularExpression('/^\d{3}$/', $numeros['triple_c']);
            $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $numeros['signo']);
        }
    }

    public function test_converts_timestamps_to_venezuela_time()
    {
        $json = file_get_contents(base_path('tests/Fixtures/triplezulia_response.json'));

        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        $resultados = $method->invoke($this->scraper, $json);

        foreach ($resultados as $resultado) {
            $this->assertArrayHasKey('hora_sorteo', $resultado);
            $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $resultado['hora_sorteo']);
        }
    }

    public function test_handles_empty_results()
    {
        $json = json_encode(['response' => [], 'status' => 201]);

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
}
