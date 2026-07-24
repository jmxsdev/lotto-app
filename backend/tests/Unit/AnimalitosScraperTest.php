<?php

namespace Tests\Unit;

use App\Plugins\Scrapers\AnimalitosScraper;
use Tests\TestCase;

class AnimalitosScraperTest extends TestCase
{
    protected AnimalitosScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scraper = new AnimalitosScraper();
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
        $this->assertArrayHasKey('nombre_animal', $first);
        $this->assertArrayHasKey('imagen_animal', $first);
        $this->assertArrayHasKey('color_animal', $first);
        $this->assertArrayHasKey('pais', $first);
        
        $this->assertEquals('Delfin', $first['nombre_animal']);
        $this->assertEquals('10:00 AM', $first['hora_sorteo']);
        $this->assertEquals('Venezuela', $first['pais']);
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
}
