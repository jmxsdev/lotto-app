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
use App\Plugins\Scrapers\TripleChanceOficialScraper;
use Database\Seeders\TripleChanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripleChanceResultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El seeder registra el límite default a nivel banca solo si existe una banca.
        Banca::create([
            'name' => 'Banca Demo',
            'code' => 'BANCA-DEMO-TCH',
        ]);

        $this->seed(TripleChanceSeeder::class);
    }

    public function test_seeder_registra_juego_con_scraper_class(): void
    {
        $juego = Juego::where('slug', 'triple-chance')->first();

        $this->assertNotNull($juego);
        $this->assertEquals('Triple Chance', $juego->name);
        $this->assertEquals('tripletas', $juego->type);
        $this->assertTrue($juego->requires_scraper);
        $this->assertEquals('https://api.scalalot.com/servicelotteryresults/ServicioResultados.svc/ServicioResultados/ConsultarResultadoSorteo/Q0hBTkNF/', $juego->scraper_url);
        $this->assertEquals(TripleChanceOficialScraper::class, $juego->scraper_class);
        $this->assertEquals(600, $juego->config['premio_multiplo']);
    }

    public function test_seeder_registra_premios_oficiales_del_afiche(): void
    {
        $juego = Juego::where('slug', 'triple-chance')->first();

        // Premios oficiales del afiche de tuchance.com.ve (2025-04):
        // TRIPLE A/B 600x, TRIPLE A+B 200.000x, SOLO A o B 100x,
        // TERMINAL 60x, TRIPLE C + SIGNO 5.000x, SIGNO solo 6x.
        $this->assertEquals(600, $juego->config['premio_multiplo']);
        $this->assertEquals(200000, $juego->config['modalidades']['triple_a_b']);
        $this->assertEquals(100, $juego->config['modalidades']['triple_a_o_b']);
        $this->assertEquals(60, $juego->config['modalidades']['terminal']);
        $this->assertEquals(5000, $juego->config['modalidades']['triple_c_signo']);
        $this->assertEquals(6, $juego->config['modalidades']['signo']);
    }

    public function test_seeder_registra_limite_default_y_plugin_tripletas(): void
    {
        $juego = Juego::where('slug', 'triple-chance')->first();

        $limite = JuegoLimite::where('juego_id', $juego->id)->where('moneda', 'bs')->first();
        $this->assertNotNull($limite);
        $this->assertEquals(3600, (int) $limite->limite_minimo);

        $plugin = PluginJuego::where('juego_id', $juego->id)->where('active', true)->first();
        $this->assertNotNull($plugin);
        $this->assertEquals(Tripletas::class, $plugin->class_namespace);
    }

    public function test_seeder_registra_los_once_horarios_de_sorteo(): void
    {
        $juego = Juego::where('slug', 'triple-chance')->first();

        // La columna es TIME (MySQL devuelve H:i:s); el scheduler consume substr(hora, 0, 5).
        $horas = JuegoHorario::where('juego_id', $juego->id)
            ->where('active', true)
            ->orderBy('hora')
            ->get()
            ->map(fn ($horario) => substr($horario->hora, 0, 5))
            ->all();

        $this->assertEquals(
            ['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00'],
            $horas
        );
    }

    public function test_seeder_registra_los_doce_signos_como_opciones(): void
    {
        $juego = Juego::where('slug', 'triple-chance')->first();

        $opciones = $juego->opciones()->orderBy('sort_order')->get();

        $this->assertCount(12, $opciones);
        $this->assertEquals('ARI', $opciones[0]->value);
        $this->assertEquals('Aries', $opciones[0]->label);
        $this->assertEquals('PIS', $opciones[11]->value);
        $this->assertEquals('Piscis', $opciones[11]->label);
    }

    public function test_seed_y_scrape_persisten_resultados(): void
    {
        $this->assertEquals(0, Resultado::count());

        $juego = Juego::where('slug', 'triple-chance')->first();
        $scraper = new TripleChanceOficialScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/tuchance_triplechance_20260912.json'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $json);

        $guardados = $scraper->saveResults($resultados, '2026-09-12');

        $this->assertEquals(11, count($resultados));
        $this->assertEquals(11, $guardados);
        $this->assertEquals(11, Resultado::count());

        $persistido = Resultado::where('juego_id', $juego->id)
            ->whereDate('fecha_sorteo', '2026-09-12')
            ->where('hora_sorteo', '09:00')
            ->first();

        $this->assertNotNull($persistido);
        $this->assertEquals('756', $persistido->numeros_ganadores['triple_a']);
        $this->assertEquals('146', $persistido->numeros_ganadores['triple_b']);
        $this->assertEquals('682', $persistido->numeros_ganadores['triple_c']);
        $this->assertEquals('SAG', $persistido->numeros_ganadores['signo']);
        $this->assertEquals('VE', $persistido->numeros_ganadores['pais']);
    }

    public function test_dedupe_al_rescrapear_el_mismo_dia(): void
    {
        $juego = Juego::where('slug', 'triple-chance')->first();
        $scraper = new TripleChanceOficialScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/tuchance_triplechance_20260912.json'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $json);

        $scraper->saveResults($resultados, '2026-09-12');
        $this->assertEquals(11, Resultado::count());

        $scraper->saveResults($resultados, '2026-09-12');
        $this->assertEquals(11, Resultado::count(), 'No debe duplicarse el resultado del mismo sorteo');
    }

    public function test_resolve_scraper_usa_scraper_class_registrado(): void
    {
        $juego = Juego::where('slug', 'triple-chance')->first();

        $job = new ScrapeResultsJob($juego->id, '2026-09-12');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('resolveScraper');
        $method->setAccessible(true);

        $this->assertEquals(TripleChanceOficialScraper::class, $method->invoke($job, $juego));
    }
}
