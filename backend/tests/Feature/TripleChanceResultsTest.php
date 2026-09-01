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
use App\Plugins\Scrapers\LoteriaDeHoyScraper;
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
        $this->assertEquals('https://loteriadehoy.com/loteria/triplechance/resultados/', $juego->scraper_url);
        $this->assertEquals(LoteriaDeHoyScraper::class, $juego->scraper_class);
        $this->assertEquals(30, $juego->config['premio_multiplo']);
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

    public function test_seed_y_scrape_persisten_resultados(): void
    {
        $this->assertEquals(0, Resultado::count());

        $juego = Juego::where('slug', 'triple-chance')->first();
        $scraper = new LoteriaDeHoyScraper($juego);
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_triplechance.html'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $html);

        $guardados = $scraper->saveResults($resultados, '2026-09-01');

        $this->assertEquals(2, count($resultados));
        $this->assertEquals(2, $guardados);
        $this->assertEquals(2, Resultado::count());

        $persistido = Resultado::where('juego_id', $juego->id)
            ->whereDate('fecha_sorteo', '2026-09-01')
            ->where('hora_sorteo', '09:00')
            ->first();

        $this->assertNotNull($persistido);
        $this->assertEquals('829', $persistido->numeros_ganadores['triple_a']);
        $this->assertEquals('369', $persistido->numeros_ganadores['triple_b']);
        $this->assertEquals('231', $persistido->numeros_ganadores['triple_c']);
        $this->assertEquals('VIR', $persistido->numeros_ganadores['signo']);
        $this->assertEquals('VE', $persistido->numeros_ganadores['pais']);
    }

    public function test_dedupe_al_rescrapear_el_mismo_dia(): void
    {
        $juego = Juego::where('slug', 'triple-chance')->first();
        $scraper = new LoteriaDeHoyScraper($juego);
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_triplechance.html'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $html);

        $scraper->saveResults($resultados, '2026-09-01');
        $this->assertEquals(2, Resultado::count());

        $scraper->saveResults($resultados, '2026-09-01');
        $this->assertEquals(2, Resultado::count(), 'No debe duplicarse el resultado del mismo sorteo');
    }

    public function test_resolve_scraper_usa_scraper_class_registrado(): void
    {
        $juego = Juego::where('slug', 'triple-chance')->first();

        $job = new ScrapeResultsJob($juego->id, '2026-09-01');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('resolveScraper');
        $method->setAccessible(true);

        $this->assertEquals(LoteriaDeHoyScraper::class, $method->invoke($job, $juego));
    }
}
