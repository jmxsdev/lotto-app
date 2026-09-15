<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\TripleChanceOficialScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fixtures REALES del API oficial de Triple Chance (tuchance.com.ve "Chance en
 * línea" → api.scalalot.com, capturados el 14-sep-2026):
 *
 *   GET https://api.scalalot.com/servicelotteryresults/ServicioResultados.svc/
 *       ServicioResultados/ConsultarResultadoSorteo/Q0hBTkNF/{timestamp}
 *
 * (Q0hBTkNF = base64("CHANCE"), timestamp = epoch del día en America/Caracas).
 *
 * - `tuchance_triplechance_20260912.json`: día COMPLETO 2026-09-12 → 11 horarios
 *   (09:00–19:00), cada horario con 3 modalidades: CHANCE ANIMALITO (2 animalitos),
 *   CHANCE AYB (Triple A + Triple B) y CHANCE ASTRAL (Triple C + signo zodiacal).
 * - `tuchance_triplechance_parcial.json`: 2026-09-14 al momento de la captura →
 *   2 horarios (09:00 y 10:00), parcial correcto del día.
 * - `tuchance_triplechance_vacio.json`: fecha futura → mensaje codigo 012
 *   ("No hay información para los criterios seleccionados"), sin `datos`.
 *
 * Contrato del API:
 * - `datos[]` trae UN registro por modalidad y horario; los campos numéricos
 *   (`numA`, `numB`) vienen en base64 de "N   " (con padding); `simA`/`simB`
 *   en base64 de "SIGNO" o "NUM ANIMAL".
 * - El juego `triple-chance` (type tripletas) consume SOLO las modalidades
 *   CHANCE AYB (→ triple_a + triple_b) y CHANCE ASTRAL (→ triple_c + signo);
 *   CHANCE ANIMALITO pertenece a otro juego (chance-animalitos) y se ignora.
 * - `horSorteo` en 12h ("09:00 AM") → `normalizeHora` a "H:i".
 * - Signo en nombre completo ("SAGITARIO") → sigla de 3 letras (SAG), el mismo
 *   formato que guardan los demás triples con signo (patrón Caliente/Zulia).
 * - Sin ID externo por sorteo → `sorteo_id_externo` null; dedupe por
 *   juego+fecha+hora en `saveResults` heredado.
 * - Respuesta sin `datos` (mensaje 012) → RuntimeException (fail-fast).
 */
class TripleChanceOficialScraperTest extends TestCase
{
    use RefreshDatabase;

    protected TripleChanceOficialScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        Juego::create([
            'name' => 'Triple Chance',
            'slug' => 'triple-chance',
            'type' => 'tripletas',
            'config' => ['premio_multiplo' => 600],
            'requires_scraper' => true,
            'scraper_url' => 'https://api.scalalot.com/servicelotteryresults/ServicioResultados.svc/ServicioResultados/ConsultarResultadoSorteo/Q0hBTkNF/',
            'active' => true,
        ]);

        $this->scraper = new TripleChanceOficialScraper(
            Juego::where('slug', 'triple-chance')->first()
        );
    }

    protected function invocar(string $metodo, ...$args): mixed
    {
        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invoke($this->scraper, ...$args);
    }

    protected function fixture(string $nombre = 'tuchance_triplechance_20260912.json'): string
    {
        return file_get_contents(base_path('tests/Fixtures/'.$nombre));
    }

    public function test_parsea_el_dia_completo_con_un_resultado_por_horario(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // 11 horarios 09:00–19:00 → 11 resultados (uno por horario, AYB+ASTRAL)
        $this->assertIsArray($resultados);
        $this->assertCount(11, $resultados);
    }

    public function test_hora_normalizada_de_12h_a_h_mi(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        $this->assertEquals(
            ['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00'],
            array_column($resultados, 'hora_sorteo')
        );
    }

    public function test_ayb_mapea_triple_a_y_triple_b_desde_base64(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // 09:00 AM → AYB numA="756" (base64 "NzU2ICAg") + numB="146"
        $primero = $resultados[0]['numeros_ganadores'];
        $this->assertEquals('756', $primero['triple_a']);
        $this->assertEquals('146', $primero['triple_b']);
        $this->assertEquals('VE', $primero['pais']);
    }

    public function test_astral_mapea_triple_c_y_signo_en_sigla(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // 09:00 AM → ASTRAL numA="682" + simA="SAGITARIO" → triple_c + signo SAG
        $primero = $resultados[0]['numeros_ganadores'];
        $this->assertEquals('682', $primero['triple_c']);
        $this->assertEquals('SAG', $primero['signo']);
    }

    public function test_mapea_todos_los_signos_a_siglas(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // 12 signos observados en el fixture (SAGITARIO, VIRGO, ARIES...)
        $signos = array_column(array_column($resultados, 'numeros_ganadores'), 'signo');
        $this->assertContains('SAG', $signos);
        $this->assertContains('VIR', $signos);
        $this->assertContains('ARI', $signos);
        $this->assertContains('LEO', $signos);
        $this->assertContains('PIS', $signos);
    }

    public function test_ignora_la_modalidad_animalito(): void
    {
        // El fixture trae 33 registros (3 modalidades × 11 horarios); el parse
        // descarta CHANCE ANIMALITO y conserva 1 resultado por horario (11).
        $resultados = $this->invocar('parse', $this->fixture());

        foreach ($resultados as $r) {
            $ng = $r['numeros_ganadores'];
            $this->assertArrayHasKey('triple_a', $ng);
            $this->assertArrayHasKey('triple_b', $ng);
            $this->assertArrayHasKey('triple_c', $ng);
            $this->assertArrayHasKey('signo', $ng);
            $this->assertArrayNotHasKey('animalito', $ng);
        }
    }

    public function test_estructura_de_resultado_completa_sin_id_externo(): void
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

        $juego = Juego::where('slug', 'triple-chance')->first();
        $this->assertEquals($juego->id, $primero['juego_id']);
    }

    public function test_parsea_el_parcial_del_dia(): void
    {
        $resultados = $this->invocar('parse', $this->fixture('tuchance_triplechance_parcial.json'));

        $this->assertCount(2, $resultados);
        $this->assertEquals(['09:00', '10:00'], array_column($resultados, 'hora_sorteo'));
    }

    public function test_fail_fast_sin_juego_registrado_no_crea_filas(): void
    {
        $scraper = new TripleChanceOficialScraper;

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

    public function test_maneja_fecha_sin_datos_mensaje_012(): void
    {
        // Fecha futura → {"mensaje":{"codigo":"012",...}} sin `datos`
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sin la estructura esperada');

        $this->invocar('parse', $this->fixture('tuchance_triplechance_vacio.json'));
    }

    public function test_maneja_respuesta_sin_estructura_esperada(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->invocar('parse', '{}');
    }

    public function test_signo_desconocido_se_conserva_defensivo(): void
    {
        // Defensivo: un signo que no esté en el mapa oficial se conserva tal cual
        // (sin mapeo inventado), siguiendo el patrón SelvaPlus.
        $json = '{"datos":['
            .'{"codProducto":"CHANCE","desSorteo":"CHANCE AYB 09:00 AM",'
            .'"horSorteo":"09:00 AM","numA":"'.base64_encode('123   ').'","numB":"'.base64_encode('456   ').'","numC":"",'
            .'"simA":"","simB":"","simC":""},'
            .'{"codProducto":"CHANCE","desSorteo":"CHANCE ASTRAL 09:00 AM",'
            .'"horSorteo":"09:00 AM","numA":"'.base64_encode('789   ').'","numB":"",'
            .'"simA":"'.base64_encode('SIGNO-X').'","simB":"","simC":""}],'
            .'"mensaje":{"codigo":"000","descripcion":"Respuesta Exitosa."}}';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(1, $resultados);
        $this->assertEquals('SIGNO-X', $resultados[0]['numeros_ganadores']['signo']);
    }

    public function test_horario_sin_ayb_no_genera_resultado(): void
    {
        // Horario con solo ASTRAL (defensivo: faltaría el triple A/B) → se omite
        $json = '{"datos":[{"codProducto":"CHANCE","desSorteo":"CHANCE ASTRAL 11:00 AM",'
            .'"horSorteo":"11:00 AM","numA":"'.base64_encode('123   ').'","numB":"",'
            .'"simA":"'.base64_encode('VIRGO').'","simB":"","simC":""}],'
            .'"mensaje":{"codigo":"000","descripcion":"Respuesta Exitosa."}}';

        $resultados = $this->invocar('parse', $json);

        $this->assertCount(0, $resultados);
    }
}
