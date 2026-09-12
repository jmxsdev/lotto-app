<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\LaRicachonaScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fixtures REALES del portal laricachona.com (HTML server-rendered por fecha,
 * capturados el 12-sep-2026):
 *
 *   GET https://laricachona.com/?date=YYYY-MM-DD   (fechas pasadas)
 *   GET https://laricachona.com/                   (hoy, sin parámetro)
 *
 * - `laricachona_results.html`: día COMPLETO 2026-09-11 → 12 artículos
 *   `tripleResultArticle` con hora `<h1>` y 3 `<p>` (08:05 AM – 07:05 PM).
 * - `laricachona_parcial.html`: día 2026-09-12 al momento de la captura → 4
 *   sorteos ocurridos (08:05–11:05) + 8 artículos con `--`/`---` (no ocurridos,
 *   se deben saltar).
 *
 * El `<p>` del MEDIO del artículo es el número de 3 dígitos del sorteo
 * ("030", con el cero inicial conservado como STRING); los laterales son
 * decorativos (derivados -1/+1 del último par) y NO se guardan.
 */
class LaRicachonaScraperTest extends TestCase
{
    use RefreshDatabase;

    protected LaRicachonaScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        Juego::create([
            'name' => 'La Ricachona',
            'slug' => 'la-ricachona',
            'type' => 'tripletas',
            'config' => ['premio_multiplo' => 30, 'modalidades_permitidas' => ['triple_a']],
            'requires_scraper' => true,
            'scraper_url' => 'https://laricachona.com/',
            'active' => true,
        ]);

        $this->scraper = new LaRicachonaScraper(
            Juego::where('slug', 'la-ricachona')->first()
        );
    }

    protected function invocar(string $metodo, ...$args): mixed
    {
        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invoke($this->scraper, ...$args);
    }

    protected function fixture(string $nombre = 'laricachona_results.html'): string
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

        // "08:05 AM".."07:05 PM" → 08:05..19:05 (12 horas, cada hora :05)
        $this->assertEquals(
            ['08:05', '09:05', '10:05', '11:05', '12:05', '13:05', '14:05', '15:05', '16:05', '17:05', '18:05', '19:05'],
            array_column($resultados, 'hora_sorteo')
        );
    }

    public function test_numero_del_medio_con_ceros_a_la_izquierda(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // Primer sorteo 08:05 AM → "030" (string, cero inicial conservado);
        // los laterales "29"/"31" son decorativos y NO se guardan.
        $this->assertEquals(['triple_a' => '030'], $resultados[0]['numeros_ganadores']);

        // Triangulación: 04:05 PM → "399" y 07:05 PM → "898"
        $this->assertEquals('399', $resultados[8]['numeros_ganadores']['triple_a']);
        $this->assertEquals('898', $resultados[11]['numeros_ganadores']['triple_a']);
    }

    public function test_salta_sorteos_no_ocurridos_con_guiones(): void
    {
        // Parcial del 12-sep: 4 sorteos ocurridos + 8 con "--"/"---"
        $resultados = $this->invocar('parse', $this->fixture('laricachona_parcial.html'));

        $this->assertCount(4, $resultados);
        $this->assertEquals(['08:05', '09:05', '10:05', '11:05'], array_column($resultados, 'hora_sorteo'));

        // Triangulación sobre el mismo fixture: 900 / 962 / 204 / 370
        $this->assertEquals('900', $resultados[0]['numeros_ganadores']['triple_a']);
        $this->assertEquals('370', $resultados[3]['numeros_ganadores']['triple_a']);
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
        $this->assertNull($primero['sorteo_id_externo']);
        $this->assertNull($primero['premios_detalle']);

        $juego = Juego::where('slug', 'la-ricachona')->first();
        $this->assertEquals($juego->id, $primero['juego_id']);
    }

    public function test_fail_fast_sin_juego_registrado_no_crea_filas(): void
    {
        $scraper = new LaRicachonaScraper;

        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no registrado');

        $method->invoke($scraper, $this->fixture());

        $this->fail('Debería lanzar RuntimeException');
    }

    public function test_maneja_html_sin_articulos_de_triples(): void
    {
        // HTML con solo la sección animalitos del portal (fuera de alcance)
        $htmlAnimalitos = <<<'HTML'
        <html><body>
            <section class="animalsModeSection">
                <article class='animalsResultArticle'>
                    <h1>08:10 AM</h1>
                    <img src="https://laricachona.com/images/animals/ricachona_animalitos/26_VACA-01.png" alt="Resultado 26">
                </article>
            </section>
        </body></html>
        HTML;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tripleResultArticle');

        $this->invocar('parse', $htmlAnimalitos);
    }

    public function test_maneja_html_de_error(): void
    {
        // HTML de error del servidor (p. ej. 404/500) sin sorteos
        $this->expectException(\RuntimeException::class);

        $this->invocar('parse', '<html><body><h1>Error 500</h1><p>Internal Server Error</p></body></html>');
    }

    public function test_maneja_respuesta_vacia(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->invocar('parse', '');
    }
}
