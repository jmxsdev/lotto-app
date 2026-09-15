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
use App\Plugins\Scrapers\TripleFacilScraper;
use Database\Seeders\TripleFacilSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripleFacilResultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El seeder registra el límite default a nivel banca solo si existe una banca.
        Banca::create([
            'name' => 'Banca Demo',
            'code' => 'BANCA-DEMO-TF',
        ]);

        $this->seed(TripleFacilSeeder::class);
    }

    public function test_seeder_registra_juego_con_scraper_class_y_config(): void
    {
        $juego = Juego::where('slug', 'triple-facil')->first();

        $this->assertNotNull($juego);
        $this->assertEquals('Triple Fácil', $juego->name);
        $this->assertEquals('tripletas', $juego->type);
        $this->assertTrue($juego->requires_scraper);
        $this->assertEquals('https://api.lotterly.co/v1/results/triple-facil/', $juego->scraper_url);
        $this->assertEquals(TripleFacilScraper::class, $juego->scraper_class);

        // Premios INFORMATIVOS documentados (el sitio oficial no publica cifras):
        // triple completo 700×, terminal 60×, aproximación (terminal ±1) 10×.
        $this->assertEquals(700, $juego->config['premio_multiplo']);
        $this->assertEquals(60, $juego->config['modalidades']['terminal']);
        $this->assertEquals(10, $juego->config['modalidades']['aproximacion']);
        $this->assertEquals(['triple_a'], $juego->config['modalidades_permitidas']);
    }

    public function test_seeder_registra_limite_default_y_plugin(): void
    {
        $juego = Juego::where('slug', 'triple-facil')->first();

        $limite = JuegoLimite::where('juego_id', $juego->id)->where('moneda', 'bs')->first();
        $this->assertNotNull($limite);
        $this->assertEquals(3600, (int) $limite->limite_minimo);

        $plugin = PluginJuego::where('juego_id', $juego->id)->where('active', true)->first();
        $this->assertNotNull($plugin);
        $this->assertEquals(Tripletas::class, $plugin->class_namespace);
    }

    public function test_seeder_registra_los_doce_horarios_de_sorteo(): void
    {
        $juego = Juego::where('slug', 'triple-facil')->first();

        // La columna es TIME (MySQL devuelve H:i:s); el scheduler consume substr(hora, 0, 5).
        $horas = JuegoHorario::where('juego_id', $juego->id)
            ->where('active', true)
            ->orderBy('hora')
            ->get()
            ->map(fn ($horario) => substr($horario->hora, 0, 5))
            ->all();

        // 08:00–19:00 (:00 cada hora, 12 sorteos al día — confirmado por la API oficial)
        $this->assertEquals(
            ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00'],
            $horas
        );
    }

    public function test_seeder_registra_las_100_opciones_de_terminal(): void
    {
        $juego = Juego::where('slug', 'triple-facil')->first();

        $opciones = JuegoOpcion::where('juego_id', $juego->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(100, $opciones);

        // Modalidad Terminal REAL (00-99): label con padding, value sin padding,
        // numero 0..99 (mismo patrón que el plugin Terminales).
        $this->assertEquals('00', $opciones[0]->label);
        $this->assertEquals('0', $opciones[0]->value);
        $this->assertEquals(0, (int) $opciones[0]->numero);

        $this->assertEquals('99', $opciones[99]->label);
        $this->assertEquals('99', $opciones[99]->value);
        $this->assertEquals(99, (int) $opciones[99]->numero);

        // Triangulación: un valor intermedio (el terminal de "489" es 89)
        $this->assertEquals('89', $opciones[89]->label);
        $this->assertEquals('89', $opciones[89]->value);
        $this->assertEquals(89, (int) $opciones[89]->numero);
    }

    public function test_seed_y_scrape_persisten_resultados(): void
    {
        $this->assertEquals(0, Resultado::count());

        $juego = Juego::where('slug', 'triple-facil')->first();
        $scraper = new TripleFacilScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/triplefacil_results.json'));

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
        $this->assertEquals('489', $persistido->numeros_ganadores['triple_a']);
        $this->assertEquals('VE', $persistido->numeros_ganadores['pais']);
    }

    public function test_dedupe_al_rescrapear_el_mismo_dia(): void
    {
        $juego = Juego::where('slug', 'triple-facil')->first();
        $scraper = new TripleFacilScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/triplefacil_results.json'));

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
        $juego = Juego::where('slug', 'triple-facil')->first();

        $job = new ScrapeResultsJob($juego->id, '2026-09-11');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('resolveScraper');
        $method->setAccessible(true);

        $this->assertEquals(TripleFacilScraper::class, $method->invoke($job, $juego));
    }
}
