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
use App\Plugins\Scrapers\MegaAnimal40Scraper;
use Database\Seeders\MegaAnimal40Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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

    public function test_seeder_registra_juego_con_scraper_class(): void
    {
        $juego = Juego::where('slug', 'mega-animal-40')->first();

        $this->assertNotNull($juego);
        $this->assertEquals('Mega Animal 40', $juego->name);
        $this->assertEquals('animalitos', $juego->type);
        $this->assertTrue($juego->requires_scraper);
        $this->assertEquals('https://resultadosvenezuela.com/lottery/mega-animal-40', $juego->scraper_url);
        $this->assertEquals(MegaAnimal40Scraper::class, $juego->scraper_class);
        $this->assertEquals(30, $juego->config['premio_multiplo']);
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

        // 09:00–20:00 (:00 cada hora, 12 sorteos al día)
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

    public function test_seed_y_scrape_persisten_resultados(): void
    {
        $this->assertEquals(0, Resultado::count());

        $juego = Juego::where('slug', 'mega-animal-40')->first();
        $scraper = new MegaAnimal40Scraper($juego);
        $html = file_get_contents(base_path('tests/Fixtures/megaanimal40_results.html'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $html);

        $guardados = $scraper->saveResults($resultados, '2026-09-11');

        $this->assertEquals(12, count($resultados));
        $this->assertEquals(12, $guardados);
        $this->assertEquals(12, Resultado::count());

        $persistido = Resultado::where('juego_id', $juego->id)
            ->whereDate('fecha_sorteo', '2026-09-11')
            ->where('hora_sorteo', '20:00')
            ->first();

        $this->assertNotNull($persistido);
        $this->assertEquals(16, $persistido->numeros_ganadores['numero']);
        $this->assertEquals('Oso', $persistido->numeros_ganadores['nombre_animal']);
        $this->assertEquals('VE', $persistido->numeros_ganadores['pais']);
    }

    public function test_dedupe_al_rescrapear_el_mismo_dia(): void
    {
        $juego = Juego::where('slug', 'mega-animal-40')->first();
        $scraper = new MegaAnimal40Scraper($juego);
        $html = file_get_contents(base_path('tests/Fixtures/megaanimal40_results.html'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $html);

        $scraper->saveResults($resultados, '2026-09-11');
        $this->assertEquals(12, Resultado::count());

        $scraper->saveResults($resultados, '2026-09-11');
        $this->assertEquals(12, Resultado::count(), 'No debe duplicarse el resultado del mismo sorteo');
    }

    public function test_resolve_scraper_usa_scraper_class_registrado(): void
    {
        $juego = Juego::where('slug', 'mega-animal-40')->first();

        $job = new ScrapeResultsJob($juego->id, '2026-09-11');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('resolveScraper');
        $method->setAccessible(true);

        $this->assertEquals(MegaAnimal40Scraper::class, $method->invoke($job, $juego));
    }
}
