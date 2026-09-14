<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\MegaAnimal40Scraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scraper del PROVEEDOR agregador resultadosvenezuela.com — CLASE DURMIENTE
 * desde el WU f27 (fuente migrada al sitio oficial megaanimal40.com vía
 * `MegaAnimal40OficialScraper`). Se conserva por documentación del parse
 * legacy y por rollback; NO se usa en producción (el seeder apunta al oficial).
 *
 * Fixtures REALES del proveedor resultadosvenezuela.com (capturados el 12-sep-2026):
 *
 *   GET https://resultadosvenezuela.com/lottery/mega-animal-40            → día en curso (parcial)
 *   GET https://resultadosvenezuela.com/lottery/mega-animal-40?date=YYYY-MM-DD → fecha pasada (completo)
 *
 * - `megaanimal40_results.html`: día COMPLETO 2026-09-11 → 12 cards (08:00 PM – 09:00 AM,
 *   orden DESCENDENTE en el HTML). Incluye el zoológico canónico de 38 animalitos
 *   (Delfín/Ballena 0, Carnero 1 ... Culebra 36).
 * - `megaanimal40_parcial.html`: día 2026-09-12 al momento de la captura → 4 cards
 *   (12:00 PM – 09:00 AM), parcial correcto del día: el sitio SOLO renderiza horas ya sorteadas.
 * - `megaanimal40_sin_cards.html`: fecha 2026-09-13 (futura) → página completa SIN cards
 *   ("No se encontraron sorteos para este periodo"): un día sin sorteos ocurridos es un
 *   estado VÁLIDO (devuelve []) — solo el cuerpo vacío/inexistente es un error.
 *
 * Contrato del markup (verificado en las capturas):
 * - Cada card: `<div class="result-card">` con `<div class="card-time">08:00 PM</div>`,
 *   `<div class="card-number">16</div>`, `<div class="card-name">Oso</div>` y
 *   `<div class="card-date">11/09/2026</div>`; la imagen lleva alt "Animalito Oso número 16 - ...".
 * - `card-time` en 12h ("08:00 PM") → `normalizeHora` a "H:i" (20:00).
 * - Los juegos de TRIPLES del mismo proveedor renderizan cards "Pendiente" (🕒, sin
 *   `card-number`) para el próximo sorteo; por robustez el scraper SALTA cualquier card
 *   sin número (defensivo ante ese markup y ante cambios leves).
 * - El comodín "MEGA" (40x) NO aparece como marcador en las cards (~11 fechas escaneadas:
 *   11, 10, 9, 8, 7, 6, 5, 4, 3-sep + 12-sep parcial + 13-sep): las ocurrencias de "MEGA"
 *   en el HTML son el nombre del juego y la ruta de las imágenes (`mega-animal-40/`).
 *   Hallazgo documentado; NO se implementa detección de comodín.
 */
class MegaAnimal40ScraperTest extends TestCase
{
    use RefreshDatabase;

    protected MegaAnimal40Scraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        Juego::create([
            'name' => 'Mega Animal 40',
            'slug' => 'mega-animal-40',
            'type' => 'animalitos',
            'config' => ['premio_multiplo' => 30],
            'requires_scraper' => true,
            'scraper_url' => 'https://resultadosvenezuela.com/lottery/mega-animal-40',
            'active' => true,
        ]);

        $this->scraper = new MegaAnimal40Scraper(
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

    protected function fixture(string $nombre = 'megaanimal40_results.html'): string
    {
        return file_get_contents(base_path('tests/Fixtures/'.$nombre));
    }

    public function test_parsea_el_dia_completo_del_fixture(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        $this->assertIsArray($resultados);
        $this->assertCount(12, $resultados);
    }

    public function test_hora_normalizada_de_12h_a_h_mi_en_orden_del_documento(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // El HTML lista las cards en orden DESCENDENTE: 08:00 PM..09:00 AM → 20:00..09:00
        $this->assertEquals(
            ['20:00', '19:00', '18:00', '17:00', '16:00', '15:00', '14:00', '13:00', '12:00', '11:00', '10:00', '09:00'],
            array_column($resultados, 'hora_sorteo')
        );
    }

    public function test_numero_y_animal_mapeados_al_esquema_animalitos(): void
    {
        $resultados = $this->invocar('parse', $this->fixture());

        // Primer card 08:00 PM → "16" → Oso (zoológico canónico)
        $numeros = $resultados[0]['numeros_ganadores'];
        $this->assertEquals(16, $numeros['numero']);
        $this->assertEquals('Oso', $numeros['nombre_animal']);
        $this->assertEquals('VE', $numeros['pais']);

        // Triangulación: última card 09:00 AM → "29" → Elefante
        $ultimo = $resultados[11]['numeros_ganadores'];
        $this->assertEquals(29, $ultimo['numero']);
        $this->assertEquals('Elefante', $ultimo['nombre_animal']);
    }

    public function test_parsea_el_parcial_del_dia(): void
    {
        $resultados = $this->invocar('parse', $this->fixture('megaanimal40_parcial.html'));

        $this->assertCount(4, $resultados);
        $this->assertEquals(
            ['12:00', '11:00', '10:00', '09:00'],
            array_column($resultados, 'hora_sorteo')
        );

        // 12:00 PM → 30 Caimán y 09:00 AM → 18 Burro (zoológico canónico)
        $this->assertEquals('Caimán', $resultados[0]['numeros_ganadores']['nombre_animal']);
        $this->assertEquals(30, $resultados[0]['numeros_ganadores']['numero']);
        $this->assertEquals('Burro', $resultados[3]['numeros_ganadores']['nombre_animal']);
        $this->assertEquals(18, $resultados[3]['numeros_ganadores']['numero']);
    }

    public function test_dia_sin_sorteos_ocurridos_devuelve_array_vacio(): void
    {
        // Fecha futura: la página renderiza "No se encontraron sorteos" SIN cards.
        // Es un estado VÁLIDO (madrugada/día sin sorteos aún), no un error.
        $resultados = $this->invocar('parse', $this->fixture('megaanimal40_sin_cards.html'));

        $this->assertSame([], $resultados);
    }

    public function test_cards_pendientes_sin_numero_se_saltan(): void
    {
        // Los juegos de triples del proveedor renderizan el próximo sorteo como card
        // "Pendiente" (🕒, SIN .card-number). Defensivo: una card sin número se salta.
        $html = '<div class="result-card" style="opacity: 0.5;">
                    <div class="card-time">01:00 PM</div>
                    <div class="card-image-wrapper"><span>🕒</span></div>
                    <div class="card-name">Pendiente</div>
                 </div>
                 <div class="result-card">
                    <div class="card-time">09:00 AM</div>
                    <div class="card-number">29</div>
                    <div class="card-name">Elefante</div>
                 </div>';

        $resultados = $this->invocar('parse', $html);

        $this->assertCount(1, $resultados);
        $this->assertEquals('09:00', $resultados[0]['hora_sorteo']);
        $this->assertEquals('Elefante', $resultados[0]['numeros_ganadores']['nombre_animal']);
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

        $juego = Juego::where('slug', 'mega-animal-40')->first();
        $this->assertEquals($juego->id, $primero['juego_id']);
    }

    public function test_fail_fast_sin_juego_registrado_no_crea_filas(): void
    {
        $scraper = new MegaAnimal40Scraper;

        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no registrado');

        $method->invoke($scraper, $this->fixture());

        $this->fail('Debería lanzar RuntimeException');
    }

    public function test_maneja_respuesta_vacia(): void
    {
        // Cuerpo vacío: NO es un día sin sorteos, es una respuesta inválida → error.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Respuesta vacía');

        $this->invocar('parse', '');
    }

    public function test_maneja_respuesta_solo_espacios_en_blanco(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Respuesta vacía');

        $this->invocar('parse', "  \n\t  ");
    }
}
