<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\TripleZamoranoScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripleZamoranoScraperTest extends TestCase
{
    use RefreshDatabase;

    protected TripleZamoranoScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        Juego::create([
            'name' => 'Triple Zamorano',
            'slug' => 'triple-zamorano',
            'type' => 'tripletas',
            'config' => ['premio_multiplo' => 30, 'scraper' => ['product_id' => '1']],
            'requires_scraper' => true,
            'scraper_url' => 'https://www.triplezamorano.com/api/gaming/results/product',
            'active' => true,
        ]);

        $this->scraper = new TripleZamoranoScraper(
            Juego::where('slug', 'triple-zamorano')->first()
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
        return file_get_contents(base_path('tests/Fixtures/triplezamorano_results.json'));
    }

    public function test_parsea_los_nueve_sorteos_del_fixture(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        $this->assertIsArray($resultados);
        // El snapshot real se recortó a los últimos 2 días (386 → 9): 5 del
        // 2026-09-11 (día completo) + 4 del 2026-09-12 (parcial hasta 16:00).
        $this->assertCount(9, $resultados);
    }

    public function test_hora_local_caracas_correcta_desde_epoch(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // Orden del snapshot (el API NO ordena cronológicamente): 09-12 16:00,
        // 14:00, 12:00, 10:00; luego 09-11 19:00, 16:00, 14:00, 12:00, 10:00.
        $this->assertEquals(
            ['16:00', '14:00', '12:00', '10:00', '19:00', '16:00', '14:00', '12:00', '10:00'],
            array_column($resultados, 'hora_sorteo')
        );
    }

    public function test_fecha_sorteo_local_caracas_desde_epoch(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // Los 4 primeros sorteos son del 2026-09-12; los 5 siguientes del 2026-09-11
        $this->assertEquals(
            ['2026-09-12', '2026-09-12', '2026-09-12', '2026-09-12', '2026-09-11', '2026-09-11', '2026-09-11', '2026-09-11', '2026-09-11'],
            array_column($resultados, 'fecha_sorteo')
        );
    }

    public function test_mapa_a_y_c_con_signo_sin_b(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // Primer sorteo (09-12 16:00): A=683, C=452-ARI → triple_a=683,
        // triple_c=452 + signo=ARI. La API oficial SOLO trae A y C (NO hay B).
        $numeros = $resultados[0]['numeros_ganadores'];
        $this->assertEquals('683', $numeros['triple_a']);
        $this->assertEquals('452', $numeros['triple_c']);
        $this->assertEquals('ARI', $numeros['signo']);
        $this->assertEquals('VE', $numeros['pais']);
        $this->assertArrayNotHasKey('triple_b', $numeros, 'La API oficial NO expone B para Triple Zamorano');

        // Triangulación: 09-11 10:00 → A=634, C=778-VIR conserva el cero/string
        $numeros2 = $resultados[8]['numeros_ganadores'];
        $this->assertEquals('634', $numeros2['triple_a']);
        $this->assertEquals('778', $numeros2['triple_c']);
        $this->assertEquals('VIR', $numeros2['signo']);
        $this->assertMatchesRegularExpression('/^\d{3}$/', $numeros2['triple_c']);
    }

    public function test_sorteo_id_externo_usa_el_primer_event_id(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        $this->assertEquals('135083', $resultados[0]['sorteo_id_externo']);
        $this->assertEquals('134812', $resultados[4]['sorteo_id_externo']);
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

        $juego = Juego::where('slug', 'triple-zamorano')->first();
        $this->assertEquals($juego->id, $primero['juego_id']);
    }

    public function test_fail_fast_sin_juego_registrado_no_crea_filas(): void
    {
        $scraper = new TripleZamoranoScraper;

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
        // parse devuelve 9 sorteos (2 días); el filtro de execute debe quedarse
        // con los 5 del 2026-09-11 (día completo)
        $todos = $this->invocar('parse', $this->fixture());

        $resultados = $this->invocar('filtrarPorFecha', $todos, '2026-09-11');

        $this->assertCount(5, $resultados);
        $this->assertEquals(
            ['2026-09-11', '2026-09-11', '2026-09-11', '2026-09-11', '2026-09-11'],
            array_column($resultados, 'fecha_sorteo')
        );

        // Triangulación: la otra fecha del fixture (hoy, parcial 4 sorteos)
        $del12 = $this->invocar('filtrarPorFecha', $todos, '2026-09-12');
        $this->assertCount(4, $del12);
    }

    public function test_game_product_id_se_deriva_de_la_config_del_juego(): void
    {
        $juego = Juego::where('slug', 'triple-zamorano')->first();

        $reflection = new \ReflectionClass($this->scraper);
        $property = $reflection->getProperty('productId');
        $property->setAccessible(true);

        $this->assertEquals('1', $property->getValue($this->scraper));

        // Override vía config del juego
        $juego->update(['config' => ['premio_multiplo' => 30, 'scraper' => ['product_id' => '7']]]);
        $scraper = new TripleZamoranoScraper($juego->fresh());

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
