<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\SelvaPlusScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fixtures REALES del API oficial de lotterly.co para Selva Plus (capturados
 * el 12-sep-2026):
 *   GET https://api.lotterly.co/v1/results/selva-plus/?exact_date=YYYY-MM-DD
 *
 * - `selvaplus_results.json`: día COMPLETO 2026-09-11 → 13 sorteos
 *   (08:15–20:15, cada hora `:15`). El juego lanzó el 2026-09-07; fechas
 *   anteriores devuelven `[]` (estado válido del proveedor).
 * - `selvaplus_parcial.json`: día 2026-09-12 al momento de la captura → 7
 *   sorteos ocurridos (08:15–14:15), parcial correcto del día.
 * - `selvaplus_vacio.json`: `[]` (día sin sorteos — antes del lanzamiento).
 *
 * Contrato del API (misma plataforma que Loto Chaima):
 * - `time` en 24h `HH:MM:SS` (p. ej. "08:15:00") → `normalizeHora` a "H:i".
 * - `result` es un STRING numérico 00-99 con padding de 2 dígitos salvo el
 *   cero ("0", "04", "87"...). El sitio resuelve la figura con el string tal
 *   cual ("0"→Delfín, "00"→Ballena); por robustez el scraper maneja además el
 *   fallback de padding ("8"→"08"→Ratón).
 * - El zoológico es PROPIO de 101 figuras (0–99, Ballena/Delfín comparten 0),
 *   distinto al canónico del plugin Animalitos. El mapa es la fuente única
 *   compartida scraper/seeder (const `ZOOLOGICO`).
 * - COMODINES (2): "Leoncito" (A, 160x) y "Selva Plus" (B, 200x). Su
 *   representación en `result` NO se ha observado aún (65 sorteos del 07-11
 *   sep, todos numéricos) → parser DEFENSIVO: si `result` no es numérico se
 *   guarda el valor crudo en `numeros_ganadores` + log de advertencia; NO se
 *   inventan mapeos.
 */
class SelvaPlusScraperTest extends TestCase
{
    use RefreshDatabase;

    protected SelvaPlusScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        Juego::create([
            'name' => 'Selva Plus',
            'slug' => 'selva-plus',
            'type' => 'animalitos',
            'config' => [
                'premio_multiplo' => 80,
                'comodines' => [
                    'comodin-a' => ['nombre' => 'Leoncito', 'premio_multiplo' => 160],
                    'comodin-b' => ['nombre' => 'Selva Plus', 'premio_multiplo' => 200],
                ],
            ],
            'requires_scraper' => true,
            'scraper_url' => 'https://api.lotterly.co/v1/results/selva-plus/',
            'active' => true,
        ]);

        $this->scraper = new SelvaPlusScraper(
            Juego::where('slug', 'selva-plus')->first()
        );
    }

    protected function invocar(string $metodo, ...$args): mixed
    {
        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invoke($this->scraper, ...$args);
    }

    protected function fixture(string $nombre = 'selvaplus_results.json'): string
    {
        return file_get_contents(base_path('tests/Fixtures/'.$nombre));
    }

    public function test_parsea_el_dia_completo_del_fixture(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        $this->assertIsArray($resultados);
        $this->assertCount(13, $resultados);
    }

    public function test_hora_normalizada_de_hh_mm_ss_a_h_mi(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // "08:15:00".."20:15:00" → 08:15..20:15 (13 horas, cada hora `:15`)
        $this->assertEquals(
            ['08:15', '09:15', '10:15', '11:15', '12:15', '13:15', '14:15', '15:15', '16:15', '17:15', '18:15', '19:15', '20:15'],
            array_column($resultados, 'hora_sorteo')
        );
    }

    public function test_numero_y_figura_mapeados_al_esquema_animalitos(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // Primer sorteo 08:15 → "27" → Perro (zoológico propio de 101 figuras)
        $numeros = $resultados[0]['numeros_ganadores'];
        $this->assertEquals(27, $numeros['numero']);
        $this->assertEquals('Perro', $numeros['nombre_animal']);
        $this->assertEquals('VE', $numeros['pais']);

        // Triangulación: último sorteo 20:15 → "96" → Caballito de Mar
        $ultimo = $resultados[12]['numeros_ganadores'];
        $this->assertEquals(96, $ultimo['numero']);
        $this->assertEquals('Caballito de Mar', $ultimo['nombre_animal']);
    }

    public function test_lookup_del_cero_sin_padding_devuelve_delfin(): void
    {
        // El API devuelve "0" sin padding: el sitio resuelve Delfín
        $json = '[{"date":"2026-09-12","time":"08:15:00","result":"0"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals(0, $resultados[0]['numeros_ganadores']['numero']);
        $this->assertEquals('Delfín', $resultados[0]['numeros_ganadores']['nombre_animal']);
    }

    public function test_lookup_doble_cero_devuelve_ballena(): void
    {
        // Robustez: el string "00" se resuelve a Ballena (comparten numero 0)
        $json = '[{"date":"2026-09-11","time":"08:15:00","result":"00"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals(0, $resultados[0]['numeros_ganadores']['numero']);
        $this->assertEquals('Ballena', $resultados[0]['numeros_ganadores']['nombre_animal']);
    }

    public function test_normalizacion_de_un_digito_a_dos(): void
    {
        // Sin padding ("8") no está en el mapa tal cual → se paddea a "08" → Ratón
        $json = '[{"date":"2026-09-11","time":"11:15:00","result":"8"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals(8, $resultados[0]['numeros_ganadores']['numero']);
        $this->assertEquals('Ratón', $resultados[0]['numeros_ganadores']['nombre_animal']);

        // Con padding ya incluido ("08") resuelve igual (triangulación)
        $json08 = '[{"date":"2026-09-11","time":"11:15:00","result":"08"}]';
        $resultados08 = $this->invocar('parse', $json08);
        $this->assertEquals('Ratón', $resultados08[0]['numeros_ganadores']['nombre_animal']);
    }

    public function test_parsea_el_parcial_del_dia(): void
    {
        $resultados = $this->invocar('parse', $this->fixture('selvaplus_parcial.json'));

        $this->assertCount(7, $resultados);
        $this->assertEquals(
            ['08:15', '09:15', '10:15', '11:15', '12:15', '13:15', '14:15'],
            array_column($resultados, 'hora_sorteo')
        );

        // 08:15 "87" → Cabra y 09:15 "60" → Camaleón (zoológico propio)
        $this->assertEquals('Cabra', $resultados[0]['numeros_ganadores']['nombre_animal']);
        $this->assertEquals('Camaleón', $resultados[1]['numeros_ganadores']['nombre_animal']);
    }

    public function test_defensivo_result_no_numerico_guarda_valor_crudo(): void
    {
        // Comodín (representación NO observada aún): sin mapeo inventado, se
        // guarda el valor crudo y se continúa con los sorteos numéricos.
        $json = '[{"date":"2026-09-12","time":"08:15:00","result":"COMODIN_A"},{"date":"2026-09-12","time":"09:15:00","result":"27"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(2, $resultados);

        $comodin = $resultados[0]['numeros_ganadores'];
        $this->assertArrayNotHasKey('numero', $comodin);
        $this->assertArrayNotHasKey('nombre_animal', $comodin);
        $this->assertEquals('COMODIN_A', $comodin['resultado_crudo']);
        $this->assertEquals('VE', $comodin['pais']);

        // El sorteo numérico siguiente sigue mapeándose normal
        $this->assertEquals(27, $resultados[1]['numeros_ganadores']['numero']);
        $this->assertEquals('Perro', $resultados[1]['numeros_ganadores']['nombre_animal']);
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

        $juego = Juego::where('slug', 'selva-plus')->first();
        $this->assertEquals($juego->id, $primero['juego_id']);
    }

    public function test_entradas_sin_resultado_se_ignoran(): void
    {
        // Entradas con `result` null (defensivo; el API solo devuelve ocurridos)
        $json = '[{"date":"2026-09-12","time":"08:15:00","result":null},{"date":"2026-09-12","time":"09:15:00","result":"87"}]';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals('09:15', $resultados[0]['hora_sorteo']);
        $this->assertEquals('Cabra', $resultados[0]['numeros_ganadores']['nombre_animal']);
    }

    public function test_fail_fast_sin_juego_registrado_no_crea_filas(): void
    {
        $scraper = new SelvaPlusScraper;

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
        // (p. ej. antes del lanzamiento 2026-09-07) — fixture `selvaplus_vacio.json`
        $this->expectException(\RuntimeException::class);

        $this->invocar('parse', $this->fixture('selvaplus_vacio.json'));
    }

    public function test_maneja_respuesta_sin_estructura_esperada(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->invocar('parse', '{}');
    }
}
