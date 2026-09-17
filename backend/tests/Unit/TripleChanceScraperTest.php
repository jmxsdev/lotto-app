<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\LoteriaDeHoyScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripleChanceScraperTest extends TestCase
{
    use RefreshDatabase;

    protected LoteriaDeHoyScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        Juego::create([
            'name' => 'Triple Chance',
            'slug' => 'triple-chance',
            'type' => 'tripletas',
            'requires_scraper' => true,
            'scraper_url' => 'https://loteriadehoy.com/loteria/triplechance/resultados/',
            'active' => true,
        ]);

        $this->scraper = new LoteriaDeHoyScraper(
            Juego::where('slug', 'triple-chance')->first()
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

    public function test_parsea_solo_los_bloques_con_resultado_del_fixture(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_triplechance.html'));

        $resultados = $this->invocar('parse', $html);

        // La página lista los 11 bloques de horario del día, pero solo los ya
        // sorteados (09:00 y 10:00) traen A/B/C; los futuros son solo hora.
        $this->assertIsArray($resultados);
        $this->assertCount(2, $resultados);
    }

    public function test_ignora_los_bloques_de_hora_sin_resultado(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_triplechance.html'));

        $resultados = $this->invocar('parse', $html);

        // Los 9 bloques futuros (11:00 AM – 07:00 PM) NO deben generar resultados.
        $this->assertEquals(['09:00', '10:00'], array_column($resultados, 'hora_sorteo'));
    }

    public function test_normaliza_horas_12h_a_formato_24h(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_triplechance.html'));

        $resultados = $this->invocar('parse', $html);

        $this->assertEquals(['09:00', '10:00'], array_column($resultados, 'hora_sorteo'));
    }

    public function test_mapa_numeros_a_b_c_con_cero_inicial(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_triplechance.html'));

        $resultados = $this->invocar('parse', $html);

        $numeros = $resultados[0]['numeros_ganadores'];
        $this->assertEquals('829', $numeros['triple_a']);
        $this->assertEquals('369', $numeros['triple_b']);
        $this->assertEquals('231', $numeros['triple_c']);
        $this->assertMatchesRegularExpression('/^\d{3}$/', $numeros['triple_a']);

        // Triangulación: la segunda fila con resultado
        $numeros2 = $resultados[1]['numeros_ganadores'];
        $this->assertEquals('550', $numeros2['triple_a']);
        $this->assertEquals('841', $numeros2['triple_b']);
        $this->assertEquals('623', $numeros2['triple_c']);
    }

    public function test_mapa_signo_a_codigo_mayusculas(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_triplechance.html'));

        $resultados = $this->invocar('parse', $html);

        $this->assertEquals('VIR', $resultados[0]['numeros_ganadores']['signo']);
        $this->assertEquals('ESC', $resultados[1]['numeros_ganadores']['signo']);
    }

    public function test_estructura_de_resultado_completa(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_triplechance.html'));

        $resultados = $this->invocar('parse', $html);

        $primero = $resultados[0];
        $this->assertArrayHasKey('juego_id', $primero);
        $this->assertArrayHasKey('hora_sorteo', $primero);
        $this->assertArrayHasKey('numeros_ganadores', $primero);
        $this->assertArrayHasKey('sorteo_id_externo', $primero);
        $this->assertArrayHasKey('premios_detalle', $primero);

        $numeros = $primero['numeros_ganadores'];
        $this->assertArrayHasKey('triple_a', $numeros);
        $this->assertArrayHasKey('triple_b', $numeros);
        $this->assertArrayHasKey('triple_c', $numeros);
        $this->assertArrayHasKey('signo', $numeros);
        $this->assertArrayHasKey('pais', $numeros);
        $this->assertEquals('VE', $numeros['pais']);

        $juego = Juego::where('slug', 'triple-chance')->first();
        $this->assertEquals($juego->id, $primero['juego_id']);
    }

    public function test_fail_fast_sin_juego_registrado_no_crea_filas(): void
    {
        $scraper = new LoteriaDeHoyScraper;
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_triplechance.html'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no registrado');

        $this->parsearCon($scraper, $html);

        $this->fail('Debería lanzar RuntimeException');
    }

    public function test_ignora_filas_malformadas_sin_los_cinco_datos(): void
    {
        $html = '<html><body><table class="resultados"><tbody>'
            .'<tr><td>09:00 AM</td><td>A : 829</td><td>B : 369</td><td>C : 231</td><td>Vir</td></tr>'
            .'<tr><td>11:00 AM</td></tr>'
            .'</tbody></table></body></html>';

        $resultados = $this->invocar('parse', $html);

        $this->assertCount(1, $resultados);
        $this->assertEquals('09:00', $resultados[0]['hora_sorteo']);
    }
}
