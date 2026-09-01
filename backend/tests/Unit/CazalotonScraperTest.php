<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\LoteriaDeHoyScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CazalotonScraperTest extends TestCase
{
    use RefreshDatabase;

    protected LoteriaDeHoyScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        Juego::create([
            'name' => 'Cazaloton',
            'slug' => 'cazaloton',
            'type' => 'animalitos',
            'requires_scraper' => true,
            'scraper_url' => 'https://loteriadehoy.com/animalito/cazaloton/resultados/',
            'active' => true,
        ]);

        $this->scraper = new LoteriaDeHoyScraper(
            Juego::where('slug', 'cazaloton')->first()
        );
    }

    protected function invocar(string $metodo, ...$args): mixed
    {
        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invoke($this->scraper, ...$args);
    }

    protected function parsearCon(LoteriaDeHoyScraper $scraper, string $html): mixed
    {
        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        return $method->invoke($scraper, $html);
    }

    public function test_parsea_los_bloques_presentes_del_fixture(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_cazaloton.html'));

        $resultados = $this->invocar('parse', $html);

        $this->assertIsArray($resultados);
        // La página hoy solo muestra los sorteos ya ocurridos (09:00 y 10:00),
        // NO los 11 del día: el scraper debe manejar resultados parciales.
        $this->assertCount(2, $resultados);
    }

    public function test_parsea_numero_y_animal_por_bloque(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_cazaloton.html'));

        $resultados = $this->invocar('parse', $html);

        $numeros = array_column(array_column($resultados, 'numeros_ganadores'), 'numero');
        $this->assertEquals([5, 18], $numeros);

        $animales = array_column(array_column($resultados, 'numeros_ganadores'), 'nombre_animal');
        $this->assertEquals(['Leon', 'Burro'], $animales);
    }

    public function test_normaliza_horas_12h_a_formato_24h(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_cazaloton.html'));

        $resultados = $this->invocar('parse', $html);

        $this->assertEquals(['09:00', '10:00'], array_column($resultados, 'hora_sorteo'));
    }

    public function test_estructura_de_resultado_completa_con_pais(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_cazaloton.html'));

        $resultados = $this->invocar('parse', $html);

        $primero = $resultados[0];
        $this->assertArrayHasKey('juego_id', $primero);
        $this->assertArrayHasKey('hora_sorteo', $primero);
        $this->assertArrayHasKey('numeros_ganadores', $primero);
        $this->assertArrayHasKey('sorteo_id_externo', $primero);
        $this->assertArrayHasKey('premios_detalle', $primero);

        $numeros = $primero['numeros_ganadores'];
        $this->assertArrayHasKey('numero', $numeros);
        $this->assertArrayHasKey('nombre_animal', $numeros);
        $this->assertArrayHasKey('pais', $numeros);
        $this->assertEquals('VE', $numeros['pais']);

        $juego = Juego::where('slug', 'cazaloton')->first();
        $this->assertEquals($juego->id, $primero['juego_id']);
    }

    public function test_fail_fast_sin_juego_registrado_no_crea_filas(): void
    {
        $scraper = new LoteriaDeHoyScraper;
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_cazaloton.html'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no registrado');

        $this->parsearCon($scraper, $html);

        $this->fail('Debería lanzar RuntimeException');
    }

    public function test_maneja_html_sin_bloques_de_animalitos(): void
    {
        $html = '<html><body><div class="row text-center js-con"></div></body></html>';

        $resultados = $this->invocar('parse', $html);

        $this->assertIsArray($resultados);
        $this->assertCount(0, $resultados);
    }

    public function test_ignora_bloques_malformados_sin_hora_ni_numero(): void
    {
        $html = '<html><body><div class="row text-center js-con">'
            .'<div class="col-sm-6 col-md-4 col-lg-16 mb-5">'
            .'<div class="circle-legend"><h4 class="mt-3 rojo">5 Leon</h4><h5>Cazaloton 09:00 AM</h5></div>'
            .'</div>'
            .'<div class="col-sm-6 col-md-4 col-lg-16 mb-5">'
            .'<div class="circle-legend"><h4 class="mt-3 rojo">-- Sin dato</h4><h5>Cazaloton 10:00 AM</h5></div>'
            .'</div>'
            .'</div></body></html>';

        $resultados = $this->invocar('parse', $html);

        $this->assertCount(1, $resultados);
        $this->assertEquals('09:00', $resultados[0]['hora_sorteo']);
        $this->assertEquals(5, $resultados[0]['numeros_ganadores']['numero']);
    }
}
