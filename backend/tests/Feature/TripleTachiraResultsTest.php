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
use App\Plugins\Scrapers\TripleTachiraScraper;
use Database\Seeders\TripleTachiraSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripleTachiraResultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El seeder registra el límite default a nivel banca solo si existe una banca.
        Banca::create([
            'name' => 'Banca Demo',
            'code' => 'BANCA-DEMO-TT',
        ]);

        $this->seed(TripleTachiraSeeder::class);
    }

    public function test_seeder_registra_juego_con_scraper_class(): void
    {
        $juego = Juego::where('slug', 'triple-tachira')->first();

        $this->assertNotNull($juego);
        $this->assertEquals('Triple Táchira', $juego->name);
        $this->assertEquals('tripletas', $juego->type);
        $this->assertTrue($juego->requires_scraper);
        $this->assertEquals('https://tripletachira.com/pruebah.php', $juego->scraper_url);
        $this->assertEquals(TripleTachiraScraper::class, $juego->scraper_class);

        // Premios OFICIALES del reglamento (G-20004065-3, Lotería del Táchira):
        // A/B 500x, Terminal (cola) 50x, Triple+Zodiacal 5.000x. La informativa
        // (resultadosvenezuela.com) declara 600/60/6.000 — desajuste H9 documentado.
        $this->assertEquals(500, $juego->config['premio_multiplo']);
        $this->assertEquals(50, $juego->config['modalidades']['cola']);
        $this->assertEquals(5000, $juego->config['modalidades']['zodiacal']);
    }

    public function test_seeder_registra_limite_default_y_plugin(): void
    {
        $juego = Juego::where('slug', 'triple-tachira')->first();

        $limite = JuegoLimite::where('juego_id', $juego->id)->where('moneda', 'bs')->first();
        $this->assertNotNull($limite);
        $this->assertEquals(3600, (int) $limite->limite_minimo);

        $plugin = PluginJuego::where('juego_id', $juego->id)->where('active', true)->first();
        $this->assertNotNull($plugin);
        $this->assertEquals(Tripletas::class, $plugin->class_namespace);
    }

    public function test_seeder_registra_los_tres_horarios_de_sorteo(): void
    {
        $juego = Juego::where('slug', 'triple-tachira')->first();

        // La columna es TIME (MySQL devuelve H:i:s); el scheduler consume substr(hora, 0, 5).
        $horas = JuegoHorario::where('juego_id', $juego->id)
            ->where('active', true)
            ->orderBy('hora')
            ->get()
            ->map(fn ($horario) => substr($horario->hora, 0, 5))
            ->all();

        // Oficiales: 1:15 / 4:45 / 10:10 PM → 13:15, 16:45, 22:10 (3 sorteos diarios)
        $this->assertEquals(
            ['13:15', '16:45', '22:10'],
            $horas
        );
    }

    public function test_seeder_registra_los_doce_signos_como_opciones(): void
    {
        $juego = Juego::where('slug', 'triple-tachira')->first();

        $opciones = JuegoOpcion::where('juego_id', $juego->id)
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(12, $opciones);

        $valores = $opciones->pluck('value')->all();
        $this->assertEquals(
            ['ARI', 'TAU', 'GEM', 'CAN', 'LEO', 'VIR', 'LIB', 'ESC', 'SAG', 'CAP', 'ACU', 'PIS'],
            $valores
        );
        $this->assertContains('Piscis', $opciones->pluck('label')->all());
    }

    public function test_seed_y_scrape_persisten_resultados(): void
    {
        $this->assertEquals(0, Resultado::count());

        $juego = Juego::where('slug', 'triple-tachira')->first();
        $scraper = new TripleTachiraScraper($juego);
        $html = file_get_contents(base_path('tests/Fixtures/tripletachira_semana.html'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $html, '2026-09-11');

        $guardados = $scraper->saveResults($resultados, '2026-09-11');

        $this->assertEquals(3, count($resultados));
        $this->assertEquals(3, $guardados);
        $this->assertEquals(3, Resultado::count());

        $persistido = Resultado::where('juego_id', $juego->id)
            ->whereDate('fecha_sorteo', '2026-09-11')
            ->where('hora_sorteo', '13:15')
            ->first();

        $this->assertNotNull($persistido);
        $this->assertEquals('245', $persistido->numeros_ganadores['triple_a']);
        $this->assertEquals('998', $persistido->numeros_ganadores['triple_b']);
        $this->assertEquals('160', $persistido->numeros_ganadores['triple_c']);
        $this->assertEquals('PIS', $persistido->numeros_ganadores['signo']);
        $this->assertEquals('VE', $persistido->numeros_ganadores['pais']);
    }

    public function test_dedupe_al_rescrapear_el_mismo_dia(): void
    {
        $juego = Juego::where('slug', 'triple-tachira')->first();
        $scraper = new TripleTachiraScraper($juego);
        $html = file_get_contents(base_path('tests/Fixtures/tripletachira_semana.html'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $html, '2026-09-11');

        $scraper->saveResults($resultados, '2026-09-11');
        $this->assertEquals(3, Resultado::count());

        $scraper->saveResults($resultados, '2026-09-11');
        $this->assertEquals(3, Resultado::count(), 'No debe duplicarse el resultado del mismo sorteo');
    }

    public function test_resolve_scraper_usa_scraper_class_registrado(): void
    {
        $juego = Juego::where('slug', 'triple-tachira')->first();

        $job = new ScrapeResultsJob($juego->id, '2026-09-11');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('resolveScraper');
        $method->setAccessible(true);

        $this->assertEquals(TripleTachiraScraper::class, $method->invoke($job, $juego));
    }
}
