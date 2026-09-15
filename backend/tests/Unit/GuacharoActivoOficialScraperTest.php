<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\GuacharoActivoOficialScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fixtures REALES del API oficial de lotterly.co para Guácharo Activo
 * (capturados el 14-sep-2026):
 *
 *   GET https://api.lotterly.co/v1/results/guacharo-activo/?exact_date=YYYY-MM-DD
 *
 * - `guacharoactivo_oficial_20260912.json`: día COMPLETO 2026-09-12 → 12 sorteos
 *   (08:00–19:00, cada hora `:00`).
 * - `guacharoactivo_oficial_parcial.json`: 2026-09-14 al momento de la captura →
 *   3 sorteos ocurridos (08:00–10:00), parcial correcto del día.
 *
 * Contrato del API (misma plataforma que Loto Chaima / Selva Plus):
 * - `time` en 24h `HH:MM:SS` → `normalizeHora` a "H:i".
 * - `result` STRING con padding de 2 dígitos ("24", "07", "33"...), salvo el
 *   cero sin padding ("0" → Delfín). El sitio resuelve la figura con el string
 *   tal cual ("0"→Delfín, "00"→Ballena, "24"→Iguana).
 * - Zoológico PROPIO de **77 figuras (00 Ballena + 0 Delfín + 01..75 Guacharo)**
 *   extraído del bundle oficial del sitio (index-Dv-KFMIs.js). Claves tal como
 *   viajan en el API: "00"→Ballena, "0"→Delfín, "01".."75" con padding.
 * - PREMIOS OFICIALES del bundle: animalito regular **60×** (1→60, 10→600,
 *   100→6.000) y el comodín especial **Guácharo (75) = 120×** ("El Guácharo es
 *   el comodín especial... ¡duplicas tu premio!": 1→120, 10→1.200, 100→12.000).
 *   12 sorteos diarios 08:00–19:00.
 * - Sin ID externo por sorteo → `sorteo_id_externo` null y dedupe por
 *   juego+fecha+hora en `saveResults` heredado.
 * - Respuesta vacía (string o array `[]`), JSON inválido o estructura
 *   inesperada → RuntimeException (fail-fast).
 */
class GuacharoActivoOficialScraperTest extends TestCase
{
    use RefreshDatabase;

    protected GuacharoActivoOficialScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        Juego::create([
            'name' => 'Guacharo Activo',
            'slug' => 'guacharo-activo',
            'type' => 'animalitos',
            'config' => ['premio_multiplo' => 60],
            'requires_scraper' => true,
            'scraper_url' => 'https://api.lotterly.co/v1/results/guacharo-activo/',
            'active' => true,
        ]);

        $this->scraper = new GuacharoActivoOficialScraper(
            Juego::where('slug', 'guacharo-activo')->first()
        );
    }

    protected function invocar(string $metodo, ...$args): mixed
    {
        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invoke($this->scraper, ...$args);
    }

    protected function fixture(string $nombre = 'guacharoactivo_oficial_20260912.json'): string
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
            ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00'],
            array_column($resultados, 'hora_sorteo')
        );
    }

    public function test_numero_y_animal_mapeados_al_zoo_propio_de_77(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // 08:00 "24" → Iguana; 09:00 "68" → Jaguar; 10:00 "40" → Avispa
        $numeros = array_column(array_column($resultados, 'numeros_ganadores'), 'numero');
        $this->assertEquals([24, 68, 40, 7, 53, 8, 63, 66, 14, 13, 65, 33], $numeros);

        $animales = array_column(array_column($resultados, 'numeros_ganadores'), 'nombre_animal');
        $this->assertEquals('Iguana', $animales[0]);
        $this->assertEquals('Jaguar', $animales[1]);
        $this->assertEquals('Avispa', $animales[2]);
        $this->assertEquals('Pescado', $animales[11]);
        $this->assertEquals('VE', $resultados[0]['numeros_ganadores']['pais']);
    }

    public function test_acentos_del_zoo_propio_conservados(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // 13:00 "08" → Ratón (ó); 18:00 "65" → Araña (ñ)
        $this->assertEquals('Ratón', $resultados[5]['numeros_ganadores']['nombre_animal']);
        $this->assertEquals('Araña', $resultados[10]['numeros_ganadores']['nombre_animal']);
    }

    public function test_lookup_del_cero_sin_padding_devuelve_delfin(): void
    {
        $json = '[{"date":"2026-09-11","time":"14:00:00","result":"0"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals(0, $resultados[0]['numeros_ganadores']['numero']);
        $this->assertEquals('Delfín', $resultados[0]['numeros_ganadores']['nombre_animal']);
    }

    public function test_lookup_doble_cero_devuelve_ballena(): void
    {
        $json = '[{"date":"2026-09-11","time":"08:00:00","result":"00"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals(0, $resultados[0]['numeros_ganadores']['numero']);
        $this->assertEquals('Ballena', $resultados[0]['numeros_ganadores']['nombre_animal']);
    }

    public function test_lookup_con_fallback_de_padding(): void
    {
        // Sin padding ("7") no está en el mapa tal cual → se paddea a "07" → Perico
        $json = '[{"date":"2026-09-11","time":"11:00:00","result":"7"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals(7, $resultados[0]['numeros_ganadores']['numero']);
        $this->assertEquals('Perico', $resultados[0]['numeros_ganadores']['nombre_animal']);
    }

    public function test_el_75_resuelve_al_comodin_guacharo(): void
    {
        $json = '[{"date":"2026-09-11","time":"19:00:00","result":"75"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals(75, $resultados[0]['numeros_ganadores']['numero']);
        $this->assertEquals('Guacharo', $resultados[0]['numeros_ganadores']['nombre_animal']);
    }

    public function test_parsea_el_parcial_del_dia(): void
    {
        $resultados = $this->invocar('parse', $this->fixture('guacharoactivo_oficial_parcial.json'));

        $this->assertCount(3, $resultados);
        $this->assertEquals(['08:00', '09:00', '10:00'], array_column($resultados, 'hora_sorteo'));

        // 09:00 "38" → Búfalo
        $this->assertEquals('Búfalo', $resultados[1]['numeros_ganadores']['nombre_animal']);
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

        $juego = Juego::where('slug', 'guacharo-activo')->first();
        $this->assertEquals($juego->id, $primero['juego_id']);
    }

    public function test_entradas_sin_resultado_se_ignoran(): void
    {
        $json = '[{"date":"2026-09-14","time":"08:00:00","result":null},{"date":"2026-09-14","time":"09:00:00","result":"38"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals('09:00', $resultados[0]['hora_sorteo']);
        $this->assertEquals('Búfalo', $resultados[0]['numeros_ganadores']['nombre_animal']);
    }

    public function test_fail_fast_sin_juego_registrado_no_crea_filas(): void
    {
        $scraper = new GuacharoActivoOficialScraper;

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
