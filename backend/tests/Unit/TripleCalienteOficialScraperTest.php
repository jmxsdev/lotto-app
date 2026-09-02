<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\TripleCalienteOficialScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripleCalienteOficialScraperTest extends TestCase
{
    use RefreshDatabase;

    protected TripleCalienteOficialScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        Juego::create([
            'name' => 'Triple Caliente',
            'slug' => 'triple-caliente',
            'type' => 'tripletas',
            'config' => ['premio_multiplo' => 30, 'scraper' => ['product_id' => '4']],
            'requires_scraper' => true,
            'scraper_url' => 'https://triplecaliente.com/api/gaming/results/product',
            'active' => true,
        ]);

        $this->scraper = new TripleCalienteOficialScraper(
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

    protected function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/triplecaliente_oficial.json'));
    }

    public function test_parsea_los_seis_sorteos_del_fixture(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        $this->assertIsArray($resultados);
        $this->assertCount(6, $resultados);
    }

    public function test_hora_local_caracas_correcta_desde_epoch(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // 1788304200 → 2026-09-01 19:10 America/Caracas (UTC-4)
        $this->assertEquals(
            ['19:10', '16:30', '13:00', '19:10', '16:30', '13:00'],
            array_column($resultados, 'hora_sorteo')
        );
    }

    public function test_fecha_sorteo_local_caracas_desde_epoch(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // Los 3 primeros sorteos son del 2026-09-01; los 3 siguientes del 2026-08-31
        $this->assertEquals(
            ['2026-09-01', '2026-09-01', '2026-09-01', '2026-08-31', '2026-08-31', '2026-08-31'],
            array_column($resultados, 'fecha_sorteo')
        );
    }

    public function test_mapa_a_b_c_y_signo(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // Primer sorteo: A=013, B=511, C=589-ESC → triple_c=589 + signo=ESC
        $numeros = $resultados[0]['numeros_ganadores'];
        $this->assertEquals('013', $numeros['triple_a']);
        $this->assertEquals('511', $numeros['triple_b']);
        $this->assertEquals('589', $numeros['triple_c']);
        $this->assertEquals('ESC', $numeros['signo']);
        $this->assertEquals('VE', $numeros['pais']);

        // Triangulación: sorteo 2026-08-31 16:30 → C=062-ARI conserva cero inicial
        $numeros2 = $resultados[4]['numeros_ganadores'];
        $this->assertEquals('514', $numeros2['triple_a']);
        $this->assertEquals('634', $numeros2['triple_b']);
        $this->assertEquals('062', $numeros2['triple_c']);
        $this->assertEquals('ARI', $numeros2['signo']);
        $this->assertMatchesRegularExpression('/^\d{3}$/', $numeros2['triple_c']);
    }

    public function test_sorteo_id_externo_usa_el_primer_event_id(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        $this->assertEquals('132401', $resultados[0]['sorteo_id_externo']);
        $this->assertEquals('132396', $resultados[1]['sorteo_id_externo']);
    }

    public function test_estructura_de_resultado_completa(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        $primero = $resultados[0];
        $this->assertArrayHasKey('juego_id', $primero);
        $this->assertArrayHasKey('fecha_sorteo', $primero);
        $this->assertArrayHasKey('hora_sorteo', $primero);
        $this->assertArrayHasKey('numeros_ganadores', $primero);
        $this->assertArrayHasKey('sorteo_id_externo', $primero);
        $this->assertArrayHasKey('premios_detalle', $primero);
        $this->assertNull($primero['premios_detalle']);

        $juego = Juego::where('slug', 'triple-caliente')->first();
        $this->assertEquals($juego->id, $primero['juego_id']);
    }

    public function test_fail_fast_sin_juego_registrado_no_crea_filas(): void
    {
        $scraper = new TripleCalienteOficialScraper;

        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no registrado');

        $method->invoke($scraper, $this->fixture());

        $this->fail('Debería lanzar RuntimeException');
    }

    public function test_filtra_los_sorteos_de_la_fecha_solicitada(): void
    {
        // parse devuelve 6 sorteos (2 días); el filtro de execute debe quedarse con 3
        $todos = $this->invocar('parse', $this->fixture());

        $resultados = $this->invocar('filtrarPorFecha', $todos, '2026-09-01');

        $this->assertCount(3, $resultados);
        $this->assertEquals(
            ['2026-09-01', '2026-09-01', '2026-09-01'],
            array_column($resultados, 'fecha_sorteo')
        );

        // Triangulación: la otra fecha del fixture
        $del31 = $this->invocar('filtrarPorFecha', $todos, '2026-08-31');
        $this->assertCount(3, $del31);
    }

    public function test_game_product_id_se_deriva_de_la_config_del_juego(): void
    {
        $juego = Juego::where('slug', 'triple-caliente')->first();

        $reflection = new \ReflectionClass($this->scraper);
        $property = $reflection->getProperty('productId');
        $property->setAccessible(true);

        $this->assertEquals('4', $property->getValue($this->scraper));

        // Override vía config del juego
        $juego->update(['config' => ['premio_multiplo' => 30, 'scraper' => ['product_id' => '7']]]);
        $scraper = new TripleCalienteOficialScraper($juego->fresh());

        $reflection2 = new \ReflectionClass($scraper);
        $property2 = $reflection2->getProperty('productId');
        $property2->setAccessible(true);

        $this->assertEquals('7', $property2->getValue($scraper));
    }

    public function test_maneja_json_invalido(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Error al decodificar JSON');

        $this->invocar('parse', 'no-json');
    }

    public function test_maneja_respuesta_sin_sorteos(): void
    {
        $json = json_encode(['status' => 201, 'response' => []]);

        $resultados = $this->invocar('parse', $json);

        $this->assertIsArray($resultados);
        $this->assertCount(0, $resultados);
    }
}
