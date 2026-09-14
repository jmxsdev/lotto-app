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
use App\Plugins\Juegos\Tripletas;
use App\Plugins\Scrapers\TripleZamoranoScraper;
use Database\Seeders\TripleZamoranoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripleZamoranoResultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El seeder registra el límite default a nivel banca solo si existe una banca.
        Banca::create([
            'name' => 'Banca Demo',
            'code' => 'BANCA-DEMO-TZ',
        ]);

        $this->seed(TripleZamoranoSeeder::class);
    }

    public function test_seeder_registra_juego_con_scraper_class(): void
    {
        $juego = Juego::where('slug', 'triple-zamorano')->first();

        $this->assertNotNull($juego);
        $this->assertEquals('Triple Zamorano', $juego->name);
        $this->assertEquals('tripletas', $juego->type);
        $this->assertTrue($juego->requires_scraper);
        $this->assertEquals('https://www.triplezamorano.com/api/gaming/results/product', $juego->scraper_url);
        $this->assertEquals(TripleZamoranoScraper::class, $juego->scraper_class);
        // Premios OFICIALES del reglamento (WU f26): "REGLAMENTO TP ZAMORANO
        // NOV2025" publicado en el propio triplezamorano.com (Lotería del Zulia
        // G-20007649-6, 18 págs parseable; copia en
        // docs/reglamentos/reglamento-triple-zamorano.pdf). Art. 19: TRIPLE 600×,
        // COLA 60×, UÑA 5×, ASTRO (triple+signo) 6.000×, COLA+SIGNO 600× y
        // UÑA+SIGNO 60×. Resuelve H11: la informativa (600/60/6.000/600) era
        // correcta pero sin fuente; ahora verificada con el reglamento.
        $this->assertEquals(600, $juego->config['premio_multiplo']);
        $this->assertEqualsCanonicalizing(
            ['cola' => 60, 'uña' => 5, 'zodiacal' => 6000, 'cola_signo' => 600, 'uña_signo' => 60],
            $juego->config['modalidades']
        );
        $this->assertEquals('1', $juego->config['scraper']['product_id']);
    }

    public function test_seeder_registra_limite_default_y_plugin(): void
    {
        $juego = Juego::where('slug', 'triple-zamorano')->first();

        $limite = JuegoLimite::where('juego_id', $juego->id)->where('moneda', 'bs')->first();
        $this->assertNotNull($limite);
        $this->assertEquals(3600, (int) $limite->limite_minimo);

        $plugin = PluginJuego::where('juego_id', $juego->id)->where('active', true)->first();
        $this->assertNotNull($plugin);
        $this->assertEquals(Tripletas::class, $plugin->class_namespace);
    }

    public function test_seeder_registra_los_cinco_horarios_de_sorteo(): void
    {
        $juego = Juego::where('slug', 'triple-zamorano')->first();

        // Horarios oficiales verificados con los timestamps del API (America/Caracas):
        // 5 sorteos diarios 10:00/12:00/14:00/16:00/19:00 (domingos solo 19:00).
        $horas = JuegoHorario::where('juego_id', $juego->id)
            ->where('active', true)
            ->orderBy('hora')
            ->get()
            ->map(fn ($horario) => substr($horario->hora, 0, 5))
            ->all();

        $this->assertEquals(['10:00', '12:00', '14:00', '16:00', '19:00'], $horas);
    }

    public function test_seeder_registra_las_doce_opciones_de_signos(): void
    {
        $juego = Juego::where('slug', 'triple-zamorano')->first();

        $opciones = JuegoOpcion::where('juego_id', $juego->id)->where('active', true)->get();

        $this->assertCount(12, $opciones);

        $labels = $opciones->pluck('label')->all();
        $this->assertContains('Aries', $labels);
        $this->assertContains('Piscis', $labels);
        $this->assertContains('Acuario', $labels);
        $this->assertContains('Escorpio', $labels);
    }

    public function test_seed_y_scrape_persisten_resultados(): void
    {
        $this->assertEquals(0, Resultado::count());

        $juego = Juego::where('slug', 'triple-zamorano')->first();
        $scraper = new TripleZamoranoScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/triplezamorano_results.json'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $todos = $parse->invoke($scraper, $json);

        // El fixture trae 2 días (9 sorteos); el flujo real filtra por la fecha
        // solicitada (execute → filtrarPorFecha), como hace el job.
        $filtrar = $reflection->getMethod('filtrarPorFecha');
        $filtrar->setAccessible(true);
        $resultados = $filtrar->invoke($scraper, $todos, '2026-09-11');

        $guardados = $scraper->saveResults($resultados, '2026-09-11');

        $this->assertEquals(5, count($resultados));
        $this->assertEquals(5, $guardados);
        $this->assertEquals(5, Resultado::count());

        $persistido = Resultado::where('juego_id', $juego->id)
            ->whereDate('fecha_sorteo', '2026-09-11')
            ->where('hora_sorteo', '10:00')
            ->first();

        $this->assertNotNull($persistido);
        $this->assertEquals('634', $persistido->numeros_ganadores['triple_a']);
        $this->assertEquals('778', $persistido->numeros_ganadores['triple_c']);
        $this->assertEquals('VIR', $persistido->numeros_ganadores['signo']);
        $this->assertEquals('VE', $persistido->numeros_ganadores['pais']);
        $this->assertEquals('134845', $persistido->sorteo_id_externo);
    }

    public function test_dedupe_al_rescrapear_el_mismo_dia(): void
    {
        $juego = Juego::where('slug', 'triple-zamorano')->first();
        $scraper = new TripleZamoranoScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/triplezamorano_results.json'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $todos = $parse->invoke($scraper, $json);

        $filtrar = $reflection->getMethod('filtrarPorFecha');
        $filtrar->setAccessible(true);
        $resultados = $filtrar->invoke($scraper, $todos, '2026-09-11');

        $scraper->saveResults($resultados, '2026-09-11');
        $this->assertEquals(5, Resultado::count());

        $scraper->saveResults($resultados, '2026-09-11');
        $this->assertEquals(5, Resultado::count(), 'No debe duplicarse el resultado del mismo sorteo');
    }

    public function test_resolve_scraper_usa_scraper_class_registrado(): void
    {
        $juego = Juego::where('slug', 'triple-zamorano')->first();

        $job = new ScrapeResultsJob($juego->id, '2026-09-11');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('resolveScraper');
        $method->setAccessible(true);

        $this->assertEquals(TripleZamoranoScraper::class, $method->invoke($job, $juego));
    }
}
