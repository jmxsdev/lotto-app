<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\LotoChaimaScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fixtures REALES del API oficial de lotterly.co (capturados el 12-sep-2026):
 *   GET https://api.lotterly.co/v1/results/loto-chaima/?exact_date=YYYY-MM-DD
 *
 * - `lotochaima_results.json`: día COMPLETO 2026-09-11 → 12 sorteos
 *   (08:00–19:00, cada hora `:00`). Incluye el caso especial `"0"` (13:00,
 *   Delfín): el API devuelve el string tal cual, sin padding.
 * - `lotochaima_parcial.json`: día 2026-09-12 al momento de la captura → 5
 *   sorteos ocurridos (08:00–12:00), parcial correcto del día.
 *
 * Contrato del API:
 * - `time` en 24h `HH:MM:SS` (p. ej. "08:00:00") → `normalizeHora` a "H:i".
 * - `result` es un STRING con padding de 2 dígitos salvo el cero: "0", "04",
 *   "46"... El sitio resuelve el nombre del animal con el string tal cual
 *   ("0"→Delfín, "04"→Alacrán); por robustez el scraper maneja también "00"
 *   (Ballena) y el caso sin padding ("4"→Alacrán vía padding a "04").
 * - El zoológico es PROPIO de 57 animales (0–55), distinto al canónico del
 *   plugin Animalitos (p. ej. 37→Tortuga y 38→Búfalo NO existen en el
 *   canónico; 23→Cebra en lugar de Cobra).
 */
class LotoChaimaScraperTest extends TestCase
{
    use RefreshDatabase;

    protected LotoChaimaScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        Juego::create([
            'name' => 'Loto Chaima',
            'slug' => 'loto-chaima',
            'type' => 'animalitos',
            'config' => ['premio_multiplo' => 30],
            'requires_scraper' => true,
            'scraper_url' => 'https://api.lotterly.co/v1/results/loto-chaima/',
            'active' => true,
        ]);

        $this->scraper = new LotoChaimaScraper(
            Juego::where('slug', 'loto-chaima')->first()
        );
    }

    protected function invocar(string $metodo, ...$args): mixed
    {
        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invoke($this->scraper, ...$args);
    }

    protected function fixture(string $nombre = 'lotochaima_results.json'): string
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

    public function test_numero_y_animal_mapeados_al_esquema_animalitos(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // Primer sorteo 08:00 → "37" → Tortuga (zoológico propio, NO canónico)
        $numeros = $resultados[0]['numeros_ganadores'];
        $this->assertEquals(37, $numeros['numero']);
        $this->assertEquals('Tortuga', $numeros['nombre_animal']);
        $this->assertEquals('VE', $numeros['pais']);

        // Triangulación: último sorteo 19:00 → "23" → Cebra (no "Cobra")
        $ultimo = $resultados[11]['numeros_ganadores'];
        $this->assertEquals(23, $ultimo['numero']);
        $this->assertEquals('Cebra', $ultimo['nombre_animal']);
    }

    public function test_lookup_del_cero_sin_padding_devuelve_delfin(): void
    {
        // El API devuelve "0" (13:00 del 11-sep): el sitio resuelve Delfín
        $resultados = $this->invocar('parse', $this->fixture());

        $cero = $resultados[5]['numeros_ganadores'];
        $this->assertEquals(0, $cero['numero']);
        $this->assertEquals('Delfín', $cero['nombre_animal']);
    }

    public function test_lookup_doble_cero_devuelve_ballena(): void
    {
        // Robustez: el string "00" se resuelve a Ballena (comparten numero 0)
        $json = '[{"date":"2026-09-11","time":"08:00:00","result":"00"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals(0, $resultados[0]['numeros_ganadores']['numero']);
        $this->assertEquals('Ballena', $resultados[0]['numeros_ganadores']['nombre_animal']);
    }

    public function test_lookup_con_fallback_de_padding(): void
    {
        // Sin padding ("4") no está en el mapa tal cual → se paddea a "04" → Alacrán
        $json = '[{"date":"2026-09-11","time":"11:00:00","result":"4"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals(4, $resultados[0]['numeros_ganadores']['numero']);
        $this->assertEquals('Alacrán', $resultados[0]['numeros_ganadores']['nombre_animal']);

        // Con padding ya incluido ("04") resuelve igual (triangulación)
        $json04 = '[{"date":"2026-09-11","time":"11:00:00","result":"04"}]';
        $resultados04 = $this->invocar('parse', $json04);
        $this->assertEquals('Alacrán', $resultados04[0]['numeros_ganadores']['nombre_animal']);
    }

    public function test_acentos_de_los_nombres_conservados(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // 11:00 "03" → Ciempiés (é) y 16:00 "38" → Búfalo (ú)
        $this->assertEquals('Ciempiés', $resultados[3]['numeros_ganadores']['nombre_animal']);
        $this->assertEquals('Búfalo', $resultados[8]['numeros_ganadores']['nombre_animal']);
    }

    public function test_parsea_el_parcial_del_dia(): void
    {
        $resultados = $this->invocar('parse', $this->fixture('lotochaima_parcial.json'));

        $this->assertCount(5, $resultados);
        $this->assertEquals(
            ['08:00', '09:00', '10:00', '11:00', '12:00'],
            array_column($resultados, 'hora_sorteo')
        );

        // 09:00 "46" → Puma (zoológico propio) y 11:00 "04" → Alacrán
        $this->assertEquals('Puma', $resultados[1]['numeros_ganadores']['nombre_animal']);
        $this->assertEquals('Alacrán', $resultados[3]['numeros_ganadores']['nombre_animal']);
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

        $juego = Juego::where('slug', 'loto-chaima')->first();
        $this->assertEquals($juego->id, $primero['juego_id']);
    }

    public function test_entradas_sin_resultado_se_ignoran(): void
    {
        // Entradas con `result` null (defensivo; el API solo devuelve ocurridos)
        $json = '[{"date":"2026-09-12","time":"08:00:00","result":null},{"date":"2026-09-12","time":"09:00:00","result":"46"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals('09:00', $resultados[0]['hora_sorteo']);
        $this->assertEquals('Puma', $resultados[0]['numeros_ganadores']['nombre_animal']);
    }

    public function test_fail_fast_sin_juego_registrado_no_crea_filas(): void
    {
        $scraper = new LotoChaimaScraper;

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

        $this->invocar('parse', '[]');
    }

    public function test_maneja_respuesta_sin_estructura_esperada(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->invocar('parse', '{}');
    }
}
