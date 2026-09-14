<?php

namespace Tests\Feature;

use App\Jobs\ScrapeResultsJob;
use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\JuegoOpcion;
use App\Models\PluginJuego;
use App\Models\Resultado;
use App\Plugins\Juegos\Animalitos;
use App\Plugins\Scrapers\MegaAnimal40OficialScraper;
use Database\Seeders\MegaAnimal40Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature del seeder OFICIAL de Mega Animal 40 (WU f27): fuente migrada a
 * megaanimal40.com (POST /core/process.php con el token de resultados) y
 * captura del comodín MEGA (`mega:"2"` → `numeros_ganadores.comodin = true`).
 *
 * Fixtures:
 * - `megaanimal40_oficial.json`: snapshot REAL del endpoint (14-sep-2026, 8
 *   sorteos 09:00 AM–04:00 PM, comodín NO salido).
 * - `megaanimal40_comodin.json`: caso SINTÉTICO de test del campo `mega:"2"`
 *   (ver docblock del test unitario).
 *
 * El scraper del proveedor (MegaAnimal40Scraper) queda como clase durmiente;
 * su test unitario sigue cubriendo el parse legacy (documentado).
 */
class MegaAnimal40ResultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El seeder registra el límite default a nivel banca solo si existe una banca.
        Banca::create([
            'name' => 'Banca Demo',
            'code' => 'BANCA-DEMO-MA40',
        ]);

        $this->seed(MegaAnimal40Seeder::class);
    }

    public function test_seeder_registra_juego_con_scraper_class_oficial(): void
    {
        $juego = Juego::where('slug', 'mega-animal-40')->first();

        $this->assertNotNull($juego);
        $this->assertEquals('Mega Animal 40', $juego->name);
        $this->assertEquals('animalitos', $juego->type);
        $this->assertTrue($juego->requires_scraper);
        $this->assertEquals('https://megaanimal40.com/', $juego->scraper_url);
        $this->assertEquals(MegaAnimal40OficialScraper::class, $juego->scraper_class);
        $this->assertEquals(30, $juego->config['premio_multiplo']);
        $this->assertEquals(
            ['mega' => ['nombre' => 'MEGA', 'premio_multiplo' => 40]],
            $juego->config['comodines']
        );
    }

    public function test_seeder_registra_limite_default_y_plugin(): void
    {
        $juego = Juego::where('slug', 'mega-animal-40')->first();

        $limite = JuegoLimite::where('juego_id', $juego->id)->where('moneda', 'bs')->first();
        $this->assertNotNull($limite);
        $this->assertEquals(3600, (int) $limite->limite_minimo);

        $plugin = PluginJuego::where('juego_id', $juego->id)->where('active', true)->first();
        $this->assertNotNull($plugin);
        $this->assertEquals(Animalitos::class, $plugin->class_namespace);
    }

    public function test_seeder_registra_los_doce_horarios_de_sorteo(): void
    {
        $juego = Juego::where('slug', 'mega-animal-40')->first();

        // La columna es TIME (MySQL devuelve H:i:s); el scheduler consume substr(hora, 0, 5).
        $horas = JuegoHorario::where('juego_id', $juego->id)
            ->where('active', true)
            ->orderBy('hora')
            ->get()
            ->map(fn ($horario) => substr($horario->hora, 0, 5))
            ->all();

        // 09:00–20:00 (:00 cada hora, 12 sorteos al día; confirmados por el
        // sitio oficial megaanimal40.com)
        $this->assertEquals(
            ['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00'],
            $horas
        );
    }

    public function test_seeder_no_registra_opciones_propias_fallback_al_plugin(): void
    {
        // El zoológico es el canónico del plugin Animalitos (38 etiquetas): el juego NO
        // necesita filas propias en juego_opciones; el catálogo cae al plugin por fallback.
        $juego = Juego::where('slug', 'mega-animal-40')->first();

        $this->assertEquals(0, JuegoOpcion::where('juego_id', $juego->id)->count());
    }

    public function test_seed_y_scrape_persisten_resultados_oficiales(): void
    {
        $this->assertEquals(0, Resultado::count());

        $juego = Juego::where('slug', 'mega-animal-40')->first();
        $scraper = new MegaAnimal40OficialScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/megaanimal40_oficial.json'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $json);

        $guardados = $scraper->saveResults($resultados, '2026-09-14');

        $this->assertEquals(8, count($resultados));
        $this->assertEquals(8, $guardados);
        $this->assertEquals(8, Resultado::count());

        $persistido = Resultado::where('juego_id', $juego->id)
            ->whereDate('fecha_sorteo', '2026-09-14')
            ->where('hora_sorteo', '15:00')
            ->first();

        $this->assertNotNull($persistido);
        $this->assertEquals(9, $persistido->numeros_ganadores['numero']);
        $this->assertEquals('Águila', $persistido->numeros_ganadores['nombre_animal']);
        $this->assertEquals('VE', $persistido->numeros_ganadores['pais']);
        $this->assertFalse($persistido->numeros_ganadores['comodin']);
    }

    public function test_persiste_el_comodin_cuando_mega_es_2(): void
    {
        // Fixture SINTÉTICO del campo documentado mega:"2" (comodín MEGA).
        $juego = Juego::where('slug', 'mega-animal-40')->first();
        $scraper = new MegaAnimal40OficialScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/megaanimal40_comodin.json'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $json);

        $guardados = $scraper->saveResults($resultados, '2026-09-14');

        $this->assertEquals(3, $guardados);
        $this->assertEquals(3, Resultado::count());

        $conComodin = Resultado::where('juego_id', $juego->id)
            ->whereDate('fecha_sorteo', '2026-09-14')
            ->where('hora_sorteo', '15:00')
            ->first();
        $this->assertNotNull($conComodin);
        $this->assertTrue($conComodin->numeros_ganadores['comodin']);
        $this->assertEquals('Águila', $conComodin->numeros_ganadores['nombre_animal']);

        $sinComodin = Resultado::where('juego_id', $juego->id)
            ->whereDate('fecha_sorteo', '2026-09-14')
            ->where('hora_sorteo', '14:00')
            ->first();
        $this->assertNotNull($sinComodin);
        $this->assertFalse($sinComodin->numeros_ganadores['comodin']);
    }

    public function test_dedupe_al_rescrapear_el_mismo_dia(): void
    {
        $juego = Juego::where('slug', 'mega-animal-40')->first();
        $scraper = new MegaAnimal40OficialScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/megaanimal40_oficial.json'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $json);

        $scraper->saveResults($resultados, '2026-09-14');
        $this->assertEquals(8, Resultado::count());

        $scraper->saveResults($resultados, '2026-09-14');
        $this->assertEquals(8, Resultado::count(), 'No debe duplicarse el resultado del mismo sorteo');
    }

    public function test_resolve_scraper_usa_scraper_class_registrado(): void
    {
        $juego = Juego::where('slug', 'mega-animal-40')->first();

        $job = new ScrapeResultsJob($juego->id, '2026-09-14');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('resolveScraper');
        $method->setAccessible(true);

        $this->assertEquals(MegaAnimal40OficialScraper::class, $method->invoke($job, $juego));
    }
}
