<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\MegaAnimal40OficialScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scraper OFICIAL de Mega Animal 40 (migración WU f27): el agregador
 * resultadosvenezuela.com queda como clase durmiente (MegaAnimal40Scraper,
 * NO borrada — ver su test y backend/docs/juegos.md).
 *
 * Endpoint oficial (sin auth ni anti-bot):
 *
 *   POST https://megaanimal40.com/core/process.php
 *   form-data: option=<token de resultados>
 *
 * Respuesta JSON: {"msg":"Datos recopilados","status":true,"datos":[{...,"resultados":[
 *   {"date_result":"2026-09-14","number_animal":"09","animalito":"Águila",
 *    "color":"danger","time_s":"03:00 PM","mega":"1"}, ...]}]}
 *
 * Fixtures:
 * - `megaanimal40_oficial.json`: snapshot LITERAL real del endpoint, capturado
 *   el 14-sep-2026 (8 sorteos del día 09:00 AM–04:00 PM, todos mega:"1"; el
 *   comodín MEGA NO ha salido hoy).
 * - `megaanimal40_comodin.json`: caso SINTÉTICO de test del campo documentado
 *   `mega:"2"` (SALIÓ EL COMODÍN MEGA, texto del JS oficial del sitio:
 *   `if (b.mega == "2") { ...muestra la palabra MEGA... }`). NO es una captura
 *   real: el primer comodín real se capturará cuando salga y reemplazará la
 *   cobertura sintética.
 *
 * Limitación documentada: el endpoint IGNORA los parámetros de fecha (probados
 * fecha/date/dia → siempre devuelve el DÍA ACTUAL) y el sitio no expone
 * histórico funcional → el scraper solo sirve el día actual; `execute` filtra
 * por la fecha pedida y una fecha distinta a la devuelta produce `[]` (los
 * históricos previos en BD eran del proveedor y quedan).
 */
class MegaAnimal40OficialScraperTest extends TestCase
{
    use RefreshDatabase;

    protected MegaAnimal40OficialScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        Juego::create([
            'name' => 'Mega Animal 40',
            'slug' => 'mega-animal-40',
            'type' => 'animalitos',
            'config' => [
                'premio_multiplo' => 30,
                'comodines' => [
                    'mega' => ['nombre' => 'MEGA', 'premio_multiplo' => 40],
                ],
            ],
            'requires_scraper' => true,
            'scraper_url' => 'https://megaanimal40.com/',
            'active' => true,
        ]);

        $this->scraper = new MegaAnimal40OficialScraper(
            Juego::where('slug', 'mega-animal-40')->first()
        );
    }

    protected function invocar(string $metodo, ...$args): mixed
    {
        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invoke($this->scraper, ...$args);
    }

    protected function fixture(string $nombre = 'megaanimal40_oficial.json'): string
    {
        return file_get_contents(base_path('tests/Fixtures/'.$nombre));
    }

    public function test_parsea_los_ocho_sorteos_del_fixture_real(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        $this->assertIsArray($resultados);
        $this->assertCount(8, $resultados);
    }

    public function test_hora_normalizada_de_12h_a_h_mi(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // El endpoint lista los sorteos de más reciente a más antiguo:
        // 04:00 PM → 16:00 ... 09:00 AM → 09:00
        $this->assertEquals(
            ['16:00', '15:00', '14:00', '13:00', '12:00', '11:00', '10:00', '09:00'],
            array_column($resultados, 'hora_sorteo')
        );
    }

    public function test_numero_int_y_nombre_animal_con_acento_conservado(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // "09" → int 9, "Águila" con acento (03:00 PM)
        $numeros = $resultados[1]['numeros_ganadores'];
        $this->assertSame(9, $numeros['numero']);
        $this->assertSame('Águila', $numeros['nombre_animal']);
        $this->assertSame('VE', $numeros['pais']);

        // Triangulación: "08" → 8 "Ratón" (09:00 AM) y "13" → 13 "Mono" (04:00 PM)
        $ultimo = $resultados[7]['numeros_ganadores'];
        $this->assertSame(8, $ultimo['numero']);
        $this->assertSame('Ratón', $ultimo['nombre_animal']);

        $primero = $resultados[0]['numeros_ganadores'];
        $this->assertSame(13, $primero['numero']);
        $this->assertSame('Mono', $primero['nombre_animal']);
    }

    public function test_comodin_false_cuando_mega_es_1(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // Hoy el comodín NO ha salido: todos los sorteos traen mega:"1" y el
        // campo `comodin` debe estar presente y ser false en TODOS.
        $this->assertCount(8, $resultados);

        foreach ($resultados as $resultado) {
            $this->assertArrayHasKey('comodin', $resultado['numeros_ganadores']);
            $this->assertFalse(
                $resultado['numeros_ganadores']['comodin'],
                "El sorteo {$resultado['hora_sorteo']} no debe marcar comodín con mega=1."
            );
        }
    }

    public function test_comodin_true_cuando_mega_es_2_fixture_sintetico(): void
    {
        // Fixture SINTÉTICO (ver docblock): documenta el campo `mega:"2"` del
        // JS oficial (muestra la palabra MEGA). El primer comodín real llegará
        // con la captura real.
        $resultados = $this->invocar('parse', $this->fixture('megaanimal40_comodin.json'));

        $this->assertCount(3, $resultados);

        $aguila = collect($resultados)->firstWhere('hora_sorteo', '15:00');
        $this->assertNotNull($aguila, 'Debe existir el sorteo 15:00 con el comodín.');
        $this->assertTrue($aguila['numeros_ganadores']['comodin']);
        $this->assertSame('Águila', $aguila['numeros_ganadores']['nombre_animal']);

        // Triangulación: el resto del fixture sintético sigue sin comodín
        $gato = collect($resultados)->firstWhere('hora_sorteo', '14:00');
        $this->assertFalse($gato['numeros_ganadores']['comodin']);
        $raton = collect($resultados)->firstWhere('hora_sorteo', '09:00');
        $this->assertFalse($raton['numeros_ganadores']['comodin']);
    }

    public function test_fecha_sorteo_se_toma_de_date_result(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        $this->assertEquals(
            array_fill(0, 8, '2026-09-14'),
            array_column($resultados, 'fecha_sorteo')
        );
    }

    public function test_filtra_por_la_fecha_pedida_y_devuelve_vacio_si_el_endpoint_trae_otra_fecha(): void
    {
        $todos = $this->invocar('parse', $this->fixture());

        // El endpoint solo sirve el día actual: la fecha devuelta coincide
        $deHoy = $this->invocar('filtrarPorFecha', $todos, '2026-09-14');
        $this->assertCount(8, $deHoy);

        // Triangulación: pedir una fecha distinta a la devuelta → sin
        // coincidencias ([] legítimo; el sitio no expone histórico)
        $deAyer = $this->invocar('filtrarPorFecha', $todos, '2026-09-13');
        $this->assertSame([], $deAyer);

        $deManana = $this->invocar('filtrarPorFecha', $todos, '2026-09-15');
        $this->assertSame([], $deManana);
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
        $this->assertNull($primero['sorteo_id_externo']);
        $this->assertNull($primero['premios_detalle']);

        $juego = Juego::where('slug', 'mega-animal-40')->first();
        $this->assertEquals($juego->id, $primero['juego_id']);
    }

    public function test_fail_fast_sin_juego_registrado_no_crea_filas(): void
    {
        $scraper = new MegaAnimal40OficialScraper;

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

    public function test_status_false_lanza_excepcion(): void
    {
        $json = json_encode(['msg' => 'Token inválido', 'status' => false, 'datos' => []]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Respuesta inválida');

        $this->invocar('parse', $json);
    }

    public function test_sin_datos_devuelve_array_vacio(): void
    {
        // Respuesta VÁLIDA del endpoint sin sorteos (p. ej. antes del primer
        // sorteo del día): estado legítimo, no un error.
        $json = json_encode(['msg' => 'Datos recopilados', 'status' => true, 'datos' => []]);

        $resultados = $this->invocar('parse', $json);

        $this->assertSame([], $resultados);
    }

    public function test_respuesta_con_producto_sin_resultados_devuelve_vacio(): void
    {
        $json = json_encode([
            'msg' => 'Datos recopilados',
            'status' => true,
            'datos' => [
                ['id' => '1', 'name' => 'Mega Animal 40', 'link' => 'mega_animal40', 'resultados' => []],
            ],
        ]);

        $resultados = $this->invocar('parse', $json);

        $this->assertSame([], $resultados);
    }
}
