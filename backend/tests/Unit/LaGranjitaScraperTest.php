<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\LaGranjitaScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fixtures REALES del API oficial de lagranjita.com (capturados el 12-sep-2026):
 *   GET https://www.lagranjita.com/api/results.json?date=YYYY-MM-DD&productId=1
 *
 * - `lagranjita_results.json`: día COMPLETO 2026-09-11 → 12 sorteos con result_id
 *   (414469..414813, 08:00 AM – 07:00 PM).
 * - `lagranjita_parcial.json`: día 2026-09-12 al momento de la captura → 4 sorteos
 *   ocurridos (414878..414962, 08:00–11:00 AM) + 8 entradas de horarios futuros
 *   con `result_id: null` (se deben saltar).
 *
 * El zoológico del API coincide con el canónico del plugin Animalitos:
 * GALLINA=25, RATON=8, MONO=13, LAPA=31, CABALLO=12, CAIMAN=30, DELFIN=0...
 */
class LaGranjitaScraperTest extends TestCase
{
    use RefreshDatabase;

    protected LaGranjitaScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        Juego::create([
            'name' => 'La Granjita',
            'slug' => 'la-granjita',
            'type' => 'animalitos',
            'config' => ['premio_multiplo' => 30, 'scraper' => ['product_id' => '1']],
            'requires_scraper' => true,
            'scraper_url' => 'https://www.lagranjita.com/api/results.json?productId=1',
            'active' => true,
        ]);

        $this->scraper = new LaGranjitaScraper(
            Juego::where('slug', 'la-granjita')->first()
        );
    }

    protected function invocar(string $metodo, ...$args): mixed
    {
        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invoke($this->scraper, ...$args);
    }

    protected function fixture(string $nombre = 'lagranjita_results.json'): string
    {
        return file_get_contents(base_path('tests/Fixtures/'.$nombre));
    }

    public function test_parsea_el_dia_completo_del_fixture(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        $this->assertIsArray($resultados);
        $this->assertCount(12, $resultados);
    }

    public function test_hora_normalizada_de_12h_a_h_mi(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // "08:00 AM".."07:00 PM" → 08:00..19:00 (12 horas)
        $this->assertEquals(
            ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00'],
            array_column($resultados, 'hora_sorteo')
        );
    }

    public function test_numero_y_animal_mapeados_al_esquema_animalitos(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // Primer sorteo 08:00 AM → CABALLO=12
        $numeros = $resultados[0]['numeros_ganadores'];
        $this->assertEquals(12, $numeros['numero']);
        $this->assertEquals('CABALLO', $numeros['nombre_animal']);
        $this->assertEquals('VE', $numeros['pais']);

        // Triangulación: último sorteo 07:00 PM → DELFIN=0 (cero conservado)
        $ultimo = $resultados[11]['numeros_ganadores'];
        $this->assertEquals(0, $ultimo['numero']);
        $this->assertEquals('DELFIN', $ultimo['nombre_animal']);
    }

    public function test_salta_sorteos_no_ocurridos_con_result_id_null(): void
    {
        // Parcial del 12-sep: 4 sorteos ocurridos + 8 horarios futuros con nulls
        $resultados = $this->invocar('parse', $this->fixture('lagranjita_parcial.json'));

        $this->assertCount(4, $resultados);
        $this->assertEquals(['08:00', '09:00', '10:00', '11:00'], array_column($resultados, 'hora_sorteo'));

        // Triangulación sobre el mismo fixture: GALLINA=25 / RATON=8 / MONO=13 / LAPA=31
        $this->assertEquals('GALLINA', $resultados[0]['numeros_ganadores']['nombre_animal']);
        $this->assertEquals('LAPA', $resultados[3]['numeros_ganadores']['nombre_animal']);
    }

    public function test_sorteo_id_externo_usa_el_result_id(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        $this->assertEquals('414469', $resultados[0]['sorteo_id_externo']);
        $this->assertEquals('414488', $resultados[1]['sorteo_id_externo']);
    }

    public function test_estructura_de_resultado_completa(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        $primero = $resultados[0];
        $this->assertArrayHasKey('juego_id', $primero);
        $this->assertArrayHasKey('hora_sorteo', $primero);
        $this->assertArrayHasKey('numeros_ganadores', $primero);
        $this->assertArrayHasKey('sorteo_id_externo', $primero);
        $this->assertArrayHasKey('premios_detalle', $primero);
        $this->assertNull($primero['premios_detalle']);

        $juego = Juego::where('slug', 'la-granjita')->first();
        $this->assertEquals($juego->id, $primero['juego_id']);
    }

    public function test_fail_fast_sin_juego_registrado_no_crea_filas(): void
    {
        $scraper = new LaGranjitaScraper;

        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no registrado');

        $method->invoke($scraper, $this->fixture());

        $this->fail('Debería lanzar RuntimeException');
    }

    public function test_product_id_se_deriva_de_la_config_del_juego(): void
    {
        $reflection = new \ReflectionClass($this->scraper);
        $property = $reflection->getProperty('productId');
        $property->setAccessible(true);

        $this->assertEquals('1', $property->getValue($this->scraper));

        // Override vía config del juego (p. ej. otro producto del portal)
        $juego = Juego::where('slug', 'la-granjita')->first();
        $juego->update(['config' => ['premio_multiplo' => 30, 'scraper' => ['product_id' => '2']]]);
        $scraper = new LaGranjitaScraper($juego->fresh());

        $reflection2 = new \ReflectionClass($scraper);
        $property2 = $reflection2->getProperty('productId');
        $property2->setAccessible(true);

        $this->assertEquals('2', $property2->getValue($scraper));
    }

    public function test_maneja_json_invalido(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Error al decodificar JSON');

        $this->invocar('parse', 'no-json');
    }

    public function test_maneja_respuesta_vacia(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->invocar('parse', '');
    }

    public function test_maneja_respuesta_sin_estructura_esperada(): void
    {
        // Objeto vacío y objeto sin el array de sorteos como primer valor
        $this->expectException(\RuntimeException::class);

        $this->invocar('parse', '{}');
    }

    public function test_clave_distinta_en_la_respuesta_se_parsea_igual(): void
    {
        // La clave del JSON es el nombre del producto; el scraper toma el PRIMER
        // valor del objeto sin hardcodear la clave ("LA GRANJITA").
        $conClaveDistinta = json_encode(['OTRO PRODUCTO' => json_decode($this->fixture(), true)['LA GRANJITA']]);

        $resultados = $this->invocar('parse', $conClaveDistinta);

        $this->assertCount(12, $resultados);
        $this->assertEquals('414469', $resultados[0]['sorteo_id_externo']);
    }
}
