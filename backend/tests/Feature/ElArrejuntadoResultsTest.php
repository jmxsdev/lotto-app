<?php

namespace Tests\Feature;

use App\Jobs\ScrapeResultsJob;
use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\PluginJuego;
use App\Models\Resultado;
use App\Plugins\Juegos\Tripletas;
use App\Plugins\Scrapers\ElArrejuntaoScraper;
use Database\Seeders\ElArrejuntadoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ElArrejuntadoResultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El seeder registra el límite default a nivel banca solo si existe una banca.
        Banca::create([
            'name' => 'Banca Demo',
            'code' => 'BANCA-DEMO-EAR',
        ]);

        $this->seed(ElArrejuntadoSeeder::class);
    }

    public function test_seeder_registra_juego_con_scraper_class(): void
    {
        $juego = Juego::where('slug', 'el-arrejuntado')->first();

        $this->assertNotNull($juego);
        $this->assertEquals('El Arrejuntado', $juego->name);
        $this->assertEquals('tripletas', $juego->type);
        $this->assertTrue($juego->requires_scraper);
        $this->assertEquals('https://backend.serviciosintegradostriple7.com/api/v1/products/el-arrejuntao/results/', $juego->scraper_url);
        $this->assertEquals(ElArrejuntaoScraper::class, $juego->scraper_class);
        $this->assertEquals(30, $juego->config['premio_multiplo']);
    }

    public function test_seeder_registra_limite_default_y_plugin_tripletas(): void
    {
        $juego = Juego::where('slug', 'el-arrejuntado')->first();

        $limite = JuegoLimite::where('juego_id', $juego->id)->where('moneda', 'bs')->first();
        $this->assertNotNull($limite);
        $this->assertEquals(3600, (int) $limite->limite_minimo);

        $plugin = PluginJuego::where('juego_id', $juego->id)->where('active', true)->first();
        $this->assertNotNull($plugin);
        $this->assertEquals(Tripletas::class, $plugin->class_namespace);
    }

    public function test_seeder_registra_los_cinco_horarios_de_sorteo(): void
    {
        $juego = Juego::where('slug', 'el-arrejuntado')->first();

        $horas = JuegoHorario::where('juego_id', $juego->id)
            ->where('active', true)
            ->orderBy('hora')
            ->get()
            ->map(fn ($horario) => substr($horario->hora, 0, 5))
            ->all();

        $this->assertEquals(
            ['10:00', '13:00', '16:00', '19:00', '23:00'],
            $horas
        );
    }

    public function test_seed_y_scrape_persisten_resultados(): void
    {
        $this->assertEquals(0, Resultado::count());

        $juego = Juego::where('slug', 'el-arrejuntado')->first();
        $scraper = new ElArrejuntaoScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/elarrejuntao_results.json'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $json);

        $guardados = $scraper->saveResults($resultados, '2026-09-01');

        $this->assertEquals(1, count($resultados));
        $this->assertEquals(1, $guardados);
        $this->assertEquals(1, Resultado::count());

        $persistido = Resultado::where('juego_id', $juego->id)
            ->whereDate('fecha_sorteo', '2026-09-01')
            ->where('hora_sorteo', '10:00')
            ->first();

        $this->assertNotNull($persistido);
        $numeros = $persistido->numeros_ganadores;
        $this->assertEquals('73', $numeros['animalito']);
        $this->assertEquals('1825', $numeros['arrimao']);
        $this->assertEquals('10503', $numeros['pegadito']);
        $this->assertEquals('894', $numeros['triple_a']);
        $this->assertEquals('082', $numeros['triple_b']);
        $this->assertEquals('259', $numeros['triple_c']);
        $this->assertEquals('LEO', $numeros['signo']);
        $this->assertEquals('VE', $numeros['pais']);
    }

    public function test_dedupe_al_rescrapear_el_mismo_dia(): void
    {
        $juego = Juego::where('slug', 'el-arrejuntado')->first();
        $scraper = new ElArrejuntaoScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/elarrejuntao_results.json'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $json);

        $scraper->saveResults($resultados, '2026-09-01');
        $this->assertEquals(1, Resultado::count());

        $scraper->saveResults($resultados, '2026-09-01');
        $this->assertEquals(1, Resultado::count(), 'No debe duplicarse el resultado del mismo sorteo');
    }

    public function test_resolve_scraper_usa_scraper_class_registrado(): void
    {
        $juego = Juego::where('slug', 'el-arrejuntado')->first();

        $job = new ScrapeResultsJob($juego->id, '2026-09-01');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('resolveScraper');
        $method->setAccessible(true);

        $this->assertEquals(ElArrejuntaoScraper::class, $method->invoke($job, $juego));
    }
}
