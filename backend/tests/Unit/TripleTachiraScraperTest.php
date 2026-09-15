<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\TripleTachiraScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fixtures REALES del sitio oficial tripletachira.com (capturados el 12-sep-2026):
 *   GET https://tripletachira.com/pruebah.php?bt=DD/MM/YYYY&bt2=DD/MM/YYYY
 *
 * - `tripletachira_semana.html`: semana 11-17-sep-2026 (snapshot literal). La tabla
 *   trae 7 columnas (bt..bt+6) con la fecha en el header (`<th>Lunes<br>11/09/2026</th>`).
 *   Columnas: 11/09 COMPLETA (3 sorteos: 245/998/160-PIC, 572/033/981-GEM,
 *   623/539/998-ACU), 12/09 PARCIAL (1 sorteo: 203/894/094-SAG), 13/09 (domingo)
 *   SIN sorteos (todo `--------`).
 * - `tripletachira_domingo.html`: semana 01-07-sep-2026. El día realmente domingo
 *   (06/09/2026) NO tuvo sorteos de 01:15 ni 04:45 pero SÍ el de 10:10 (829/232/926-PIC)
 *   — comportamiento dominical NO uniforme (13-sep no tuvo ninguno); se documenta.
 * - `tripletachira_sin_datos.html`: fecha lejana (01-01-2020) → la tabla existe pero
 *   todas las celdas de la semana son `--------` (estado VÁLIDO → `parse` devuelve []).
 *
 * Contrato del sitio (verificado):
 * - Las horas vienen en formato 12h SIN sufijo AM/PM ("01:15", "04:45", "10:10") y
 *   TODOS los sorteos son PM (la home etiqueta "1:15PM"; el reglamento oficial lista
 *   1:15/4:45/10:10) → conversión a 24h: 01:15→13:15, 04:45→16:45, 10:10→22:10.
 * - Filas: `01:15 A`, `01:15 B`, `01:15 ZODI` (header de la fila). La celda ZODI trae
 *   `160 <br>PIC.` (triple + signo de 3 letras + punto); `--------` = sin sorteo.
 * - El nombre del día en el header es FIJO (Lunes..Domingo), NO el día real de la fecha
 *   (01-09-2026 es martes y el sitio lo etiqueta "Lunes") → la columna se localiza por
 *   su FECHA, nunca por el nombre del día.
 * - Signos: 3 letras + punto → códigos propios del esquema (PIC→PIS, resto igual).
 */
class TripleTachiraScraperTest extends TestCase
{
    use RefreshDatabase;

    protected TripleTachiraScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        Juego::create([
            'name' => 'Triple Táchira',
            'slug' => 'triple-tachira',
            'type' => 'tripletas',
            'config' => [
                'premio_multiplo' => 500,
                'modalidades' => ['cola' => 50, 'zodiacal' => 5000],
            ],
            'requires_scraper' => true,
            'scraper_url' => 'https://tripletachira.com/pruebah.php',
            'active' => true,
        ]);

        $this->scraper = new TripleTachiraScraper(
            Juego::where('slug', 'triple-tachira')->first()
        );
    }

    protected function invocar(string $metodo, ...$args): mixed
    {
        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invoke($this->scraper, ...$args);
    }

    protected function fixture(string $nombre = 'tripletachira_semana.html'): string
    {
        return file_get_contents(base_path('tests/Fixtures/'.$nombre));
    }

    public function test_parsea_el_dia_completo_por_fecha_de_columna(): void
    {
        // 11/09/2026 (columna 1 de la semana 11-17): los 3 sorteos del día
        $resultados = $this->invocar('parse', $this->fixture(), '2026-09-11');

        $this->assertIsArray($resultados);
        $this->assertCount(3, $resultados);
    }

    public function test_horas_normalizadas_a_24h_pm(): void
    {
        $resultados = $this->invocar('parse', $this->fixture(), '2026-09-11');

        // 01:15→13:15, 04:45→16:45, 10:10→22:10 (formato 12h sin AM/PM, todos PM)
        $this->assertEquals(
            ['13:15', '16:45', '22:10'],
            array_column($resultados, 'hora_sorteo')
        );
    }

    public function test_numeros_ganadores_mapeados_al_esquema_tripletas(): void
    {
        $resultados = $this->invocar('parse', $this->fixture(), '2026-09-11');

        // Primer sorteo 13:15 → A 245, B 998, C 160 PIC → PIS
        $primero = $resultados[0]['numeros_ganadores'];
        $this->assertEquals('245', $primero['triple_a']);
        $this->assertEquals('998', $primero['triple_b']);
        $this->assertEquals('160', $primero['triple_c']);
        $this->assertEquals('PIS', $primero['signo']);
        $this->assertEquals('VE', $primero['pais']);

        // Triangulación: 16:45 → 572/033/981-GEM y 22:10 → 623/539/998-ACU
        $segundo = $resultados[1]['numeros_ganadores'];
        $this->assertEquals('572', $segundo['triple_a']);
        $this->assertEquals('033', $segundo['triple_b']);
        $this->assertEquals('981', $segundo['triple_c']);
        $this->assertEquals('GEM', $segundo['signo']);

        $tercero = $resultados[2]['numeros_ganadores'];
        $this->assertEquals('623', $tercero['triple_a']);
        $this->assertEquals('539', $tercero['triple_b']);
        $this->assertEquals('998', $tercero['triple_c']);
        $this->assertEquals('ACU', $tercero['signo']);
    }

    public function test_parsea_el_parcial_del_dia(): void
    {
        // 12/09/2026 (sábado, día en curso al capturar): solo el sorteo de 13:15
        $resultados = $this->invocar('parse', $this->fixture(), '2026-09-12');

        $this->assertCount(1, $resultados);
        $this->assertEquals('13:15', $resultados[0]['hora_sorteo']);

        $numeros = $resultados[0]['numeros_ganadores'];
        $this->assertEquals('203', $numeros['triple_a']);
        $this->assertEquals('894', $numeros['triple_b']);
        $this->assertEquals('094', $numeros['triple_c']);
        $this->assertEquals('SAG', $numeros['signo']);
    }

    public function test_domingo_sin_sorteos_devuelve_vacio(): void
    {
        // 13/09/2026 (domingo): la columna está pero TODAS las celdas son `--------`
        $resultados = $this->invocar('parse', $this->fixture(), '2026-09-13');

        $this->assertSame([], $resultados);
    }

    public function test_domingo_con_solo_sorteo_de_22_10(): void
    {
        // 06/09/2026 (domingo real en la semana 01-07): sin 13:15 ni 16:45, con 22:10
        $resultados = $this->invocar('parse', $this->fixture('tripletachira_domingo.html'), '2026-09-06');

        $this->assertCount(1, $resultados);
        $this->assertEquals('22:10', $resultados[0]['hora_sorteo']);

        $numeros = $resultados[0]['numeros_ganadores'];
        $this->assertEquals('829', $numeros['triple_a']);
        $this->assertEquals('232', $numeros['triple_b']);
        $this->assertEquals('926', $numeros['triple_c']);
        $this->assertEquals('PIS', $numeros['signo']);
    }

    public function test_sin_fecha_objetivo_usa_la_primera_columna(): void
    {
        // parse sin fecha → la primera columna con fecha (la de `bt`, 11/09/2026)
        $resultados = $this->invocar('parse', $this->fixture());

        $this->assertCount(3, $resultados);
        $this->assertEquals(
            ['13:15', '16:45', '22:10'],
            array_column($resultados, 'hora_sorteo')
        );
    }

    public function test_fecha_sin_datos_devuelve_vacio(): void
    {
        // Fecha lejana (01-01-2020): la tabla existe con la semana, todo `--------`
        $resultados = $this->invocar('parse', $this->fixture('tripletachira_sin_datos.html'), '2020-01-01');

        $this->assertSame([], $resultados);
    }

    public function test_hora_tabla_a_24h_convierte_las_horas_del_sitio(): void
    {
        // Función pura: formato 12h del sitio, todos los sorteos PM
        $this->assertEquals('13:15', $this->scraper->horaTablaA24h('01:15'));
        $this->assertEquals('16:45', $this->scraper->horaTablaA24h('04:45'));
        $this->assertEquals('22:10', $this->scraper->horaTablaA24h('10:10'));
        $this->assertEquals('12:00', $this->scraper->horaTablaA24h('12:00'));
        $this->assertNull($this->scraper->horaTablaA24h('25:99'));
        $this->assertNull($this->scraper->horaTablaA24h(''));
    }

    public function test_mapear_signo_pic_a_pis_y_resto_iguales(): void
    {
        // Los 12 signos del sitio (3 letras + punto) → códigos del esquema
        $this->assertEquals('PIS', $this->scraper->mapearSigno('PIC'));
        $this->assertEquals('ARI', $this->scraper->mapearSigno('ARI'));
        $this->assertEquals('TAU', $this->scraper->mapearSigno('TAU'));
        $this->assertEquals('GEM', $this->scraper->mapearSigno('GEM'));
        $this->assertEquals('CAN', $this->scraper->mapearSigno('CAN'));
        $this->assertEquals('LEO', $this->scraper->mapearSigno('LEO'));
        $this->assertEquals('VIR', $this->scraper->mapearSigno('VIR'));
        $this->assertEquals('LIB', $this->scraper->mapearSigno('LIB'));
        $this->assertEquals('ESC', $this->scraper->mapearSigno('ESC'));
        $this->assertEquals('SAG', $this->scraper->mapearSigno('SAG'));
        $this->assertEquals('CAP', $this->scraper->mapearSigno('CAP'));
        $this->assertEquals('ACU', $this->scraper->mapearSigno('ACU'));
    }

    public function test_estructura_de_resultado_completa(): void
    {
        $resultados = $this->invocar('parse', $this->fixture(), '2026-09-11');

        $primero = $resultados[0];
        $this->assertArrayHasKey('juego_id', $primero);
        $this->assertArrayHasKey('hora_sorteo', $primero);
        $this->assertArrayHasKey('numeros_ganadores', $primero);
        $this->assertArrayHasKey('sorteo_id_externo', $primero);
        $this->assertArrayHasKey('premios_detalle', $primero);
        $this->assertNull($primero['sorteo_id_externo']);
        $this->assertNull($primero['premios_detalle']);

        $juego = Juego::where('slug', 'triple-tachira')->first();
        $this->assertEquals($juego->id, $primero['juego_id']);
    }

    public function test_fail_fast_sin_juego_registrado_no_crea_filas(): void
    {
        $scraper = new TripleTachiraScraper;

        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no registrado');

        $method->invoke($scraper, $this->fixture(), '2026-09-11');

        $this->fail('Debería lanzar RuntimeException');
    }

    public function test_maneja_respuesta_vacia(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->invocar('parse', '');
    }

    public function test_maneja_html_sin_tabla(): void
    {
        // HTML sin la tabla esperada (p. ej. página de error del servidor)
        $this->expectException(\RuntimeException::class);

        $this->invocar('parse', '<html><body><h1>Error 500</h1></body></html>');
    }

    public function test_maneja_fecha_no_presente_en_el_header(): void
    {
        // La fecha pedida no está entre las 7 columnas de la semana devuelta
        $this->expectException(\RuntimeException::class);

        $this->invocar('parse', $this->fixture(), '2026-10-01');
    }
}
