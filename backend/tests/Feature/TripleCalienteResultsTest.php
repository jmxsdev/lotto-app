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
use App\Plugins\Scrapers\TripleCalienteOficialScraper;
use Database\Seeders\TripleCalienteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripleCalienteResultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El seeder registra el límite default a nivel banca solo si existe una banca.
        Banca::create([
            'name' => 'Banca Demo',
            'code' => 'BANCA-DEMO-TC',
        ]);

        $this->seed(TripleCalienteSeeder::class);
    }

    public function test_seeder_registra_juego_con_scraper_class(): void
    {
        $juego = Juego::where('slug', 'triple-caliente')->first();

        $this->assertNotNull($juego);
        $this->assertEquals('Triple Caliente', $juego->name);
        $this->assertEquals('tripletas', $juego->type);
        $this->assertTrue($juego->requires_scraper);
        $this->assertEquals('https://triplecaliente.com/api/gaming/results/product', $juego->scraper_url);
        $this->assertEquals(TripleCalienteOficialScraper::class, $juego->scraper_class);
        // Premios OFICIALES del reglamento (WU f26): "Reglamento TRIPLE CALIENTE"
        // publicado en triplecaliente.com (Lotería de Cojedes G-20008572-1, 17
        // págs parseable; copia en docs/reglamentos/reglamento-triple-caliente.pdf).
        // Art. 19: TRIPLE A/B/C 600×, TERMINAL A/B/C 60×, SIGNO CALIENTE
        // (triple+signo) 6.000× y TERMINAL SIGNO 600×. Antes quedaba el default 30×.
        $this->assertEquals(600, $juego->config['premio_multiplo']);
        $this->assertEqualsCanonicalizing(
            ['cola' => 60, 'zodiacal' => 6000, 'terminal_zodiacal' => 600],
            $juego->config['modalidades']
        );
        $this->assertEquals('4', $juego->config['scraper']['product_id']);
    }

    public function test_seeder_registra_limite_default_y_plugin(): void
    {
        $juego = Juego::where('slug', 'triple-caliente')->first();

        $limite = JuegoLimite::where('juego_id', $juego->id)->where('moneda', 'bs')->first();
        $this->assertNotNull($limite);
        $this->assertEquals(3600, (int) $limite->limite_minimo);

        $plugin = PluginJuego::where('juego_id', $juego->id)->where('active', true)->first();
        $this->assertNotNull($plugin);
        $this->assertEquals(Tripletas::class, $plugin->class_namespace);
    }

    public function test_seeder_registra_los_tres_horarios_de_sorteo(): void
    {
        $juego = Juego::where('slug', 'triple-caliente')->first();

        // La columna es TIME (MySQL devuelve H:i:s); el scheduler consume substr(hora, 0, 5).
        $horas = JuegoHorario::where('juego_id', $juego->id)
            ->where('active', true)
            ->orderBy('hora')
            ->get()
            ->map(fn ($horario) => substr($horario->hora, 0, 5))
            ->all();

        $this->assertEquals(['13:00', '16:30', '19:10'], $horas);
    }

    public function test_seed_y_scrape_persisten_resultados(): void
    {
        $this->assertEquals(0, Resultado::count());

        $juego = Juego::where('slug', 'triple-caliente')->first();
        $scraper = new TripleCalienteOficialScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/triplecaliente_oficial.json'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $todos = $parse->invoke($scraper, $json);

        // El fixture trae 2 días (6 sorteos); el flujo real filtra por la fecha
        // solicitada (execute → filtrarPorFecha), como hace el job.
        $filtrar = $reflection->getMethod('filtrarPorFecha');
        $filtrar->setAccessible(true);
        $resultados = $filtrar->invoke($scraper, $todos, '2026-09-01');

        $guardados = $scraper->saveResults($resultados, '2026-09-01');

        $this->assertEquals(3, count($resultados));
        $this->assertEquals(3, $guardados);
        $this->assertEquals(3, Resultado::count());

        $persistido = Resultado::where('juego_id', $juego->id)
            ->whereDate('fecha_sorteo', '2026-09-01')
            ->where('hora_sorteo', '13:00')
            ->first();

        $this->assertNotNull($persistido);
        $this->assertEquals('758', $persistido->numeros_ganadores['triple_a']);
        $this->assertEquals('073', $persistido->numeros_ganadores['triple_b']);
        $this->assertEquals('439', $persistido->numeros_ganadores['triple_c']);
        $this->assertEquals('ACU', $persistido->numeros_ganadores['signo']);
        $this->assertEquals('VE', $persistido->numeros_ganadores['pais']);
    }

    public function test_dedupe_al_rescrapear_el_mismo_dia(): void
    {
        $juego = Juego::where('slug', 'triple-caliente')->first();
        $scraper = new TripleCalienteOficialScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/triplecaliente_oficial.json'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $todos = $parse->invoke($scraper, $json);

        $filtrar = $reflection->getMethod('filtrarPorFecha');
        $filtrar->setAccessible(true);
        $resultados = $filtrar->invoke($scraper, $todos, '2026-09-01');

        $scraper->saveResults($resultados, '2026-09-01');
        $this->assertEquals(3, Resultado::count());

        $scraper->saveResults($resultados, '2026-09-01');
        $this->assertEquals(3, Resultado::count(), 'No debe duplicarse el resultado del mismo sorteo');
    }

    public function test_resolve_scraper_usa_scraper_class_registrado(): void
    {
        $juego = Juego::where('slug', 'triple-caliente')->first();

        $job = new ScrapeResultsJob($juego->id, '2026-09-01');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('resolveScraper');
        $method->setAccessible(true);

        $this->assertEquals(TripleCalienteOficialScraper::class, $method->invoke($job, $juego));
    }
}
