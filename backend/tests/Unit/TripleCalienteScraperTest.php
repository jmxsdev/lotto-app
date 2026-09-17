<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\LoteriaDeHoyScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripleCalienteScraperTest extends TestCase
{
    use RefreshDatabase;

    protected LoteriaDeHoyScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        Juego::create([
            'name' => 'Triple Caliente',
            'slug' => 'triple-caliente',
            'type' => 'tripletas',
            'requires_scraper' => true,
            'scraper_url' => 'https://loteriadehoy.com/loteria/triplecaliente/resultados/',
            'active' => true,
        ]);

        $this->scraper = new LoteriaDeHoyScraper(
            Juego::where('slug', 'triple-caliente')->first()
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

    public function test_parsea_los_tres_sorteos_del_fixture(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_triplecaliente.html'));

        $resultados = $this->invocar('parse', $html);

        $this->assertIsArray($resultados);
        $this->assertCount(3, $resultados);
    }

    public function test_normaliza_horas_12h_a_formato_24h(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_triplecaliente.html'));

        $resultados = $this->invocar('parse', $html);

        $this->assertEquals(['13:00', '16:30', '19:10'], array_column($resultados, 'hora_sorteo'));
    }

    public function test_mapa_numeros_a_b_c_con_cero_inicial(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_triplecaliente.html'));

        $resultados = $this->invocar('parse', $html);

        $numeros = $resultados[0]['numeros_ganadores'];
        $this->assertEquals('465', $numeros['triple_a']);
        $this->assertEquals('764', $numeros['triple_b']);
        $this->assertEquals('946', $numeros['triple_c']);

        // Triangulación: la segunda fila conserva el cero inicial de "062"
        $numeros2 = $resultados[1]['numeros_ganadores'];
        $this->assertEquals('514', $numeros2['triple_a']);
        $this->assertEquals('634', $numeros2['triple_b']);
        $this->assertEquals('062', $numeros2['triple_c']);
        $this->assertMatchesRegularExpression('/^\d{3}$/', $numeros2['triple_c']);
    }

    public function test_mapa_signo_a_codigo_mayusculas(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_triplecaliente.html'));

        $resultados = $this->invocar('parse', $html);

        // La página muestra "Vir"/"Ari"/"Leo"; el modelo y el plugin Tripletas
        // usan códigos de 3 letras en mayúsculas (VIR, ARI, LEO).
        $this->assertEquals('VIR', $resultados[0]['numeros_ganadores']['signo']);
        $this->assertEquals('ARI', $resultados[1]['numeros_ganadores']['signo']);
        $this->assertEquals('LEO', $resultados[2]['numeros_ganadores']['signo']);
    }

    public function test_estructura_de_resultado_completa(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_triplecaliente.html'));

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

        $juego = Juego::where('slug', 'triple-caliente')->first();
        $this->assertEquals($juego->id, $primero['juego_id']);
    }

    public function test_fail_fast_sin_juego_registrado_no_crea_filas(): void
    {
        $scraper = new LoteriaDeHoyScraper;
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_triplecaliente.html'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no registrado');

        $this->parsearCon($scraper, $html);

        $this->fail('Debería lanzar RuntimeException');
    }

    public function test_handles_html_sin_resultados(): void
    {
        $html = '<html><body><table class="resultados"><tbody></tbody></table></body></html>';

        $resultados = $this->invocar('parse', $html);

        $this->assertIsArray($resultados);
        $this->assertCount(0, $resultados);
    }

    public function test_ignora_filas_malformadas_sin_cinco_celdas(): void
    {
        $html = '<html><body><table class="resultados"><tbody>'
            .'<tr><td>01:00 PM</td><td>A : 465</td><td>B : 764</td><td>C : 946</td><td>Vir</td></tr>'
            .'<tr><td>solo</td><td>una</td></tr>'
            .'</tbody></table></body></html>';

        $resultados = $this->invocar('parse', $html);

        $this->assertCount(1, $resultados);
        $this->assertEquals('13:00', $resultados[0]['hora_sorteo']);
    }
}
