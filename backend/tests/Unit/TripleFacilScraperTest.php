<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\TripleFacilScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fixtures REALES del API oficial de lotterly.co (capturados el 12-sep-2026):
 *   GET https://api.lotterly.co/v1/results/triple-facil/?exact_date=YYYY-MM-DD
 *
 * - `triplefacil_results.json`: día COMPLETO 2026-09-11 → 12 sorteos
 *   (08:00–19:00, cada hora `:00`).
 * - `triplefacil_parcial.json`: día 2026-09-12 al momento de la captura → 9
 *   sorteos ocurridos (08:00–16:00). Incluye el caso especial `"073"` (10:00):
 *   el resultado viaja como STRING de 3 cifras CON ceros a la izquierda.
 * - `triplefacil_vacio.json`: `[]` (fecha sin sorteos, p. ej. 2026-09-13).
 *
 * Contrato del API:
 * - `time` en 24h `HH:MM:SS` (p. ej. "08:00:00") → `normalizeHora` a "H:i".
 * - `result` es un STRING de 3 cifras con ceros a la izquierda ("073"); el
 *   scraper normaliza con padding a 3 dígitos por si viniera corto ("73"→"073").
 * - El juego NO tiene signos ni animalitos: `numeros_ganadores` guarda
 *   `{"pais":"VE","triple_a":"346"}` (patrón Trio Activo / La Ricachona).
 * - Los "3 resultados" de la web (prev/main/next) NO son independientes: main
 *   es el TRIPLE (3 cifras) y prev/next son terminales DERIVADAS (los 2 últimos
 *   dígitos ±1, calculados en el front). Probados los slugs
 *   `triple-facil-terminal`, `terminal-facil`, etc. en lotterly → 400
 *   "product_slug does not exist". No existe producto terminal aparte.
 */
class TripleFacilScraperTest extends TestCase
{
    use RefreshDatabase;

    protected TripleFacilScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        Juego::create([
            'name' => 'Triple Fácil',
            'slug' => 'triple-facil',
            'type' => 'tripletas',
            'config' => ['premio_multiplo' => 700],
            'requires_scraper' => true,
            'scraper_url' => 'https://api.lotterly.co/v1/results/triple-facil/',
            'active' => true,
        ]);

        $this->scraper = new TripleFacilScraper(
            Juego::where('slug', 'triple-facil')->first()
        );
    }

    protected function invocar(string $metodo, ...$args): mixed
    {
        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invoke($this->scraper, ...$args);
    }

    protected function fixture(string $nombre = 'triplefacil_results.json'): string
    {
        return file_get_contents(base_path('tests/Fixtures/'.$nombre));
    }

    public function test_parsea_el_dia_completo_del_fixture(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        $this->assertIsArray($resultados);
        $this->assertCount(12, $resultados);
    }

    public function test_hora_normalizada_de_hh_mm_ss_a_h_mi(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // "08:00:00".."19:00:00" → 08:00..19:00 (12 horas)
        $this->assertEquals(
            ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00'],
            array_column($resultados, 'hora_sorteo')
        );
    }

    public function test_triple_a_es_string_de_3_cifras_con_padding(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // Primer sorteo 08:00 → "489" (string, no int)
        $numeros = $resultados[0]['numeros_ganadores'];
        $this->assertSame('489', $numeros['triple_a']);
        $this->assertEquals('VE', $numeros['pais']);

        // Triangulación: último sorteo 19:00 → "939"
        $ultimo = $resultados[11]['numeros_ganadores'];
        $this->assertSame('939', $ultimo['triple_a']);
    }

    public function test_padding_cuando_el_resultado_viene_corto(): void
    {
        // Defensivo: si el API devolviera "73" (sin cero inicial), se paddea a 3
        $json = '[{"date":"2026-09-11","time":"08:00:00","result":"73"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertSame('073', $resultados[0]['numeros_ganadores']['triple_a']);

        // Triangulación: "7" → "007" (padding completo a 3 dígitos)
        $json7 = '[{"date":"2026-09-11","time":"09:00:00","result":"7"}]';
        $resultados7 = $this->invocar('parse', $json7);
        $this->assertSame('007', $resultados7[0]['numeros_ganadores']['triple_a']);
    }

    public function test_parsea_el_parcial_del_dia_con_ceros_a_la_izquierda(): void
    {
        $resultados = $this->invocar('parse', $this->fixture('triplefacil_parcial.json'));

        $this->assertCount(9, $resultados);
        $this->assertEquals(
            ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00'],
            array_column($resultados, 'hora_sorteo')
        );

        // 10:00 → "073" conserva el cero a la izquierda (string)
        $this->assertSame('073', $resultados[2]['numeros_ganadores']['triple_a']);
    }

    public function test_sin_id_externo_y_estructura_de_resultado_completa(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        $primero = $resultados[0];
        $this->assertArrayHasKey('juego_id', $primero);
        $this->assertArrayHasKey('hora_sorteo', $primero);
        $this->assertArrayHasKey('numeros_ganadores', $primero);
        $this->assertArrayHasKey('sorteo_id_externo', $primero);
        $this->assertArrayHasKey('premios_detalle', $primero);
        $this->assertNull($primero['sorteo_id_externo']);
        $this->assertNull($primero['premios_detalle']);

        $juego = Juego::where('slug', 'triple-facil')->first();
        $this->assertEquals($juego->id, $primero['juego_id']);
    }

    public function test_entradas_sin_resultado_se_ignoran(): void
    {
        // Entradas con `result` null (defensivo; el API solo devuelve ocurridos)
        $json = '[{"date":"2026-09-12","time":"08:00:00","result":null},{"date":"2026-09-12","time":"09:00:00","result":"570"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals('09:00', $resultados[0]['hora_sorteo']);
        $this->assertSame('570', $resultados[0]['numeros_ganadores']['triple_a']);
    }

    public function test_fail_fast_sin_juego_registrado_no_crea_filas(): void
    {
        $scraper = new TripleFacilScraper;

        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no registrado');

        $method->invoke($scraper, $this->fixture());

        $this->fail('Debería lanzar RuntimeException');
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

    public function test_maneja_array_vacio_sin_entradas(): void
    {
        // "Sin entradas": el API devuelve [] cuando no hay sorteos para la fecha
        $this->expectException(\RuntimeException::class);

        $this->invocar('parse', $this->fixture('triplefacil_vacio.json'));
    }

    public function test_maneja_respuesta_sin_estructura_esperada(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->invocar('parse', '{}');
    }
}
