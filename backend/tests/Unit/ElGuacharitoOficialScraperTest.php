<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\ElGuacharitoOficialScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fixtures REALES del API oficial de lotterly.co para El Guacharito Millonario
 * (capturados el 14-sep-2026):
 *
 *   GET https://api.lotterly.co/v1/results/el-guacharito-millonario/?exact_date=YYYY-MM-DD
 *
 * - `elguacharito_oficial_20260912.json`: día COMPLETO 2026-09-12 → 12 sorteos
 *   (08:30–19:30, cada hora `:30`).
 * - `elguacharito_oficial_parcial.json`: 2026-09-14 al momento de la captura →
 *   3 sorteos ocurridos (08:30–10:30), parcial correcto del día.
 *
 * Contrato del API (misma plataforma que Loto Chaima / Selva Plus):
 * - `time` en 24h `HH:MM:SS` → `normalizeHora` a "H:i".
 * - `result` STRING con padding de 2 dígitos ("64", "03", "77"...), salvo el
 *   cero sin padding ("0" → Delfin). El sitio resuelve la figura con el string
 *   tal cual ("0"→Delfin, "00"→Ballena, "64"→Gavilan).
 * - Zoológico PROPIO de **101 figuras (00 Ballena + 0 Delfin + 01..99 Guacharito)**
 *   extraído del bundle oficial del sitio (index-EQw1Zdrz.js). Claves tal como
 *   viajan en el API: "00"→Ballena, "0"→Delfin, "01".."99" con padding.
 * - PREMIOS OFICIALES del bundle: animalito regular **70×** (1→70, 10→700,
 *   100→7.000) y la figura especial **Guacharito (99) = 150×** ("el número de
 *   la casa"; 1→150, 10→1.500, 100→15.000). 12 sorteos diarios 08:30–19:30.
 * - Sin ID externo por sorteo → `sorteo_id_externo` null y dedupe por
 *   juego+fecha+hora en `saveResults` heredado.
 * - Respuesta vacía (string o array `[]`), JSON inválido o estructura
 *   inesperada → RuntimeException (fail-fast).
 */
class ElGuacharitoOficialScraperTest extends TestCase
{
    use RefreshDatabase;

    protected ElGuacharitoOficialScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        Juego::create([
            'name' => 'El Guacharito Millonario',
            'slug' => 'el-guacharito',
            'type' => 'animalitos',
            'config' => ['premio_multiplo' => 70],
            'requires_scraper' => true,
            'scraper_url' => 'https://api.lotterly.co/v1/results/el-guacharito-millonario/',
            'active' => true,
        ]);

        $this->scraper = new ElGuacharitoOficialScraper(
            Juego::where('slug', 'el-guacharito')->first()
        );
    }

    protected function invocar(string $metodo, ...$args): mixed
    {
        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invoke($this->scraper, ...$args);
    }

    protected function fixture(string $nombre = 'elguacharito_oficial_20260912.json'): string
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

        $this->assertEquals(
            ['08:30', '09:30', '10:30', '11:30', '12:30', '13:30', '14:30', '15:30', '16:30', '17:30', '18:30', '19:30'],
            array_column($resultados, 'hora_sorteo')
        );
    }

    public function test_numero_y_animal_mapeados_al_zoo_propio_de_101(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // 08:30 "64" → Gavilan; 09:30 "03" → Ciempies; 10:30 "77" → Pinguino
        $numeros = array_column(array_column($resultados, 'numeros_ganadores'), 'numero');
        $this->assertEquals([64, 3, 77, 12, 78, 20, 91, 15, 43, 23, 68, 57], $numeros);

        $animales = array_column(array_column($resultados, 'numeros_ganadores'), 'nombre_animal');
        $this->assertEquals('Gavilan', $animales[0]);
        $this->assertEquals('Ciempies', $animales[1]);
        $this->assertEquals('Pinguino', $animales[2]);

        // 19:30 "57" → Pato (zoo propio)
        $this->assertEquals('Pato', $animales[11]);
        $this->assertEquals('VE', $resultados[0]['numeros_ganadores']['pais']);
    }

    public function test_lookup_del_cero_sin_padding_devuelve_delfin(): void
    {
        $json = '[{"date":"2026-09-11","time":"17:00:00","result":"0"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals(0, $resultados[0]['numeros_ganadores']['numero']);
        $this->assertEquals('Delfin', $resultados[0]['numeros_ganadores']['nombre_animal']);
    }

    public function test_lookup_doble_cero_devuelve_ballena(): void
    {
        $json = '[{"date":"2026-09-11","time":"08:30:00","result":"00"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals(0, $resultados[0]['numeros_ganadores']['numero']);
        $this->assertEquals('Ballena', $resultados[0]['numeros_ganadores']['nombre_animal']);
    }

    public function test_lookup_con_fallback_de_padding(): void
    {
        // Sin padding ("4") no está en el mapa tal cual → se paddea a "04" → Alacran
        $json = '[{"date":"2026-09-11","time":"09:30:00","result":"4"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals(4, $resultados[0]['numeros_ganadores']['numero']);
        $this->assertEquals('Alacran', $resultados[0]['numeros_ganadores']['nombre_animal']);
    }

    public function test_el_99_resuelve_a_la_figura_especial_guacharito(): void
    {
        $json = '[{"date":"2026-09-11","time":"19:30:00","result":"99"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals(99, $resultados[0]['numeros_ganadores']['numero']);
        $this->assertEquals('Guacharito', $resultados[0]['numeros_ganadores']['nombre_animal']);
    }

    public function test_parsea_el_parcial_del_dia(): void
    {
        $resultados = $this->invocar('parse', $this->fixture('elguacharito_oficial_parcial.json'));

        $this->assertCount(3, $resultados);
        $this->assertEquals(['08:30', '09:30', '10:30'], array_column($resultados, 'hora_sorteo'));

        // 09:30 "91" → Morrocoy
        $this->assertEquals('Morrocoy', $resultados[1]['numeros_ganadores']['nombre_animal']);
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

        $juego = Juego::where('slug', 'el-guacharito')->first();
        $this->assertEquals($juego->id, $primero['juego_id']);
    }

    public function test_entradas_sin_resultado_se_ignoran(): void
    {
        $json = '[{"date":"2026-09-14","time":"08:30:00","result":null},{"date":"2026-09-14","time":"09:30:00","result":"91"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals('09:30', $resultados[0]['hora_sorteo']);
        $this->assertEquals('Morrocoy', $resultados[0]['numeros_ganadores']['nombre_animal']);
    }

    public function test_fail_fast_sin_juego_registrado_no_crea_filas(): void
    {
        $scraper = new ElGuacharitoOficialScraper;

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
        $this->expectException(\RuntimeException::class);

        $this->invocar('parse', '[]');
    }

    public function test_maneja_respuesta_sin_estructura_esperada(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->invocar('parse', '{}');
    }
}
