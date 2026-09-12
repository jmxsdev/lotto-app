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
use App\Plugins\Scrapers\LotoChaimaScraper;
use Database\Seeders\LotoChaimaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LotoChaimaResultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El seeder registra el límite default a nivel banca solo si existe una banca.
        Banca::create([
            'name' => 'Banca Demo',
            'code' => 'BANCA-DEMO-LC',
        ]);

        $this->seed(LotoChaimaSeeder::class);
    }

    public function test_seeder_registra_juego_con_scraper_class(): void
    {
        $juego = Juego::where('slug', 'loto-chaima')->first();

        $this->assertNotNull($juego);
        $this->assertEquals('Loto Chaima', $juego->name);
        $this->assertEquals('animalitos', $juego->type);
        $this->assertTrue($juego->requires_scraper);
        $this->assertEquals('https://api.lotterly.co/v1/results/loto-chaima/', $juego->scraper_url);
        $this->assertEquals(LotoChaimaScraper::class, $juego->scraper_class);
        $this->assertEquals(30, $juego->config['premio_multiplo']);
    }

    public function test_seeder_registra_limite_default_y_plugin(): void
    {
        $juego = Juego::where('slug', 'loto-chaima')->first();

        $limite = JuegoLimite::where('juego_id', $juego->id)->where('moneda', 'bs')->first();
        $this->assertNotNull($limite);
        $this->assertEquals(3600, (int) $limite->limite_minimo);

        $plugin = PluginJuego::where('juego_id', $juego->id)->where('active', true)->first();
        $this->assertNotNull($plugin);
        $this->assertEquals(Animalitos::class, $plugin->class_namespace);
    }

    public function test_seeder_registra_los_doce_horarios_de_sorteo(): void
    {
        $juego = Juego::where('slug', 'loto-chaima')->first();

        // La columna es TIME (MySQL devuelve H:i:s); el scheduler consume substr(hora, 0, 5).
        $horas = JuegoHorario::where('juego_id', $juego->id)
            ->where('active', true)
            ->orderBy('hora')
            ->get()
            ->map(fn ($horario) => substr($horario->hora, 0, 5))
            ->all();

        // 08:00–19:00 (:00 cada hora, 12 sorteos al día)
        $this->assertEquals(
            ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00'],
            $horas
        );
    }

    public function test_seeder_registra_las_57_opciones_del_zoo_propio(): void
    {
        $juego = Juego::where('slug', 'loto-chaima')->first();

        $opciones = JuegoOpcion::where('juego_id', $juego->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(57, $opciones);

        // Ballena y Delfín comparten numero 0; labels con acentos; value sin acentos
        $this->assertEquals('Ballena', $opciones[0]->label);
        $this->assertEquals(0, (int) $opciones[0]->numero);
        $this->assertEquals('ballena', $opciones[0]->value);

        $this->assertEquals('Delfín', $opciones[1]->label);
        $this->assertEquals(0, (int) $opciones[1]->numero);
        $this->assertEquals('delfin', $opciones[1]->value);

        // Ciempiés con acento, value slug sin acento; último: Oso Hormiguero 55
        $this->assertEquals('Ciempiés', $opciones[4]->label);
        $this->assertEquals(3, (int) $opciones[4]->numero);
        $this->assertEquals('ciempies', $opciones[4]->value);

        $this->assertEquals('Oso Hormiguero', $opciones[56]->label);
        $this->assertEquals(55, (int) $opciones[56]->numero);
        $this->assertEquals('oso-hormiguero', $opciones[56]->value);
    }

    public function test_seed_y_scrape_persisten_resultados(): void
    {
        $this->assertEquals(0, Resultado::count());

        $juego = Juego::where('slug', 'loto-chaima')->first();
        $scraper = new LotoChaimaScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/lotochaima_results.json'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $json);

        $guardados = $scraper->saveResults($resultados, '2026-09-11');

        $this->assertEquals(12, count($resultados));
        $this->assertEquals(12, $guardados);
        $this->assertEquals(12, Resultado::count());

        $persistido = Resultado::where('juego_id', $juego->id)
            ->whereDate('fecha_sorteo', '2026-09-11')
            ->where('hora_sorteo', '08:00')
            ->first();

        $this->assertNotNull($persistido);
        $this->assertEquals(37, $persistido->numeros_ganadores['numero']);
        $this->assertEquals('Tortuga', $persistido->numeros_ganadores['nombre_animal']);
        $this->assertEquals('VE', $persistido->numeros_ganadores['pais']);
    }

    public function test_dedupe_al_rescrapear_el_mismo_dia(): void
    {
        $juego = Juego::where('slug', 'loto-chaima')->first();
        $scraper = new LotoChaimaScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/lotochaima_results.json'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $json);

        $scraper->saveResults($resultados, '2026-09-11');
        $this->assertEquals(12, Resultado::count());

        $scraper->saveResults($resultados, '2026-09-11');
        $this->assertEquals(12, Resultado::count(), 'No debe duplicarse el resultado del mismo sorteo');
    }

    public function test_resolve_scraper_usa_scraper_class_registrado(): void
    {
        $juego = Juego::where('slug', 'loto-chaima')->first();

        $job = new ScrapeResultsJob($juego->id, '2026-09-11');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('resolveScraper');
        $method->setAccessible(true);

        $this->assertEquals(LotoChaimaScraper::class, $method->invoke($job, $juego));
    }
}
