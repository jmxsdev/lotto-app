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
use App\Plugins\Scrapers\ElGuacharitoOficialScraper;
use Database\Seeders\ElGuacharitoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ElGuacharitoResultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El seeder registra el límite default a nivel banca solo si existe una banca.
        Banca::create([
            'name' => 'Banca Demo',
            'code' => 'BANCA-DEMO-EG',
        ]);

        $this->seed(ElGuacharitoSeeder::class);
    }

    public function test_seeder_registra_juego_con_scraper_class(): void
    {
        $juego = Juego::where('slug', 'el-guacharito')->first();

        $this->assertNotNull($juego);
        $this->assertEquals('El Guacharito Millonario', $juego->name);
        $this->assertEquals('animalitos', $juego->type);
        $this->assertTrue($juego->requires_scraper);
        $this->assertEquals('https://api.lotterly.co/v1/results/el-guacharito-millonario/', $juego->scraper_url);
        $this->assertEquals(ElGuacharitoOficialScraper::class, $juego->scraper_class);
        $this->assertEquals(70, $juego->config['premio_multiplo']);
    }

    public function test_seeder_registra_el_premio_especial_del_guacharito_99(): void
    {
        $juego = Juego::where('slug', 'el-guacharito')->first();

        // Premio especial oficial del bundle: Guacharito (99) = 150x
        // ("el número de la casa"), más del doble del premio regular 70x.
        $this->assertArrayHasKey('comodines', $juego->config);
        $this->assertEquals(150, $juego->config['comodines']['guacharito-99']['premio_multiplo']);
        $this->assertEquals(99, $juego->config['comodines']['guacharito-99']['numero']);
        $this->assertEquals('Guacharito', $juego->config['comodines']['guacharito-99']['nombre']);
    }

    public function test_seeder_registra_limite_default_y_plugin_animalitos(): void
    {
        $juego = Juego::where('slug', 'el-guacharito')->first();

        $limite = JuegoLimite::where('juego_id', $juego->id)->where('moneda', 'bs')->first();
        $this->assertNotNull($limite);
        $this->assertEquals(3600, (int) $limite->limite_minimo);

        $plugin = PluginJuego::where('juego_id', $juego->id)->where('active', true)->first();
        $this->assertNotNull($plugin);
        $this->assertEquals(Animalitos::class, $plugin->class_namespace);
    }

    public function test_seeder_registra_los_doce_horarios_de_sorteo(): void
    {
        $juego = Juego::where('slug', 'el-guacharito')->first();

        // La columna es TIME (MySQL devuelve H:i:s); el scheduler consume substr(hora, 0, 5).
        $horas = JuegoHorario::where('juego_id', $juego->id)
            ->where('active', true)
            ->orderBy('hora')
            ->get()
            ->map(fn ($horario) => substr($horario->hora, 0, 5))
            ->all();

        $this->assertEquals(
            ['08:30', '09:30', '10:30', '11:30', '12:30', '13:30', '14:30', '15:30', '16:30', '17:30', '18:30', '19:30'],
            $horas
        );
    }

    public function test_seeder_registra_las_101_opciones_del_zoo_propio(): void
    {
        $juego = Juego::where('slug', 'el-guacharito')->first();

        $opciones = JuegoOpcion::where('juego_id', $juego->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(101, $opciones);

        // Ballena y Delfin comparten numero 0; labels SIN acentos (bundle oficial)
        $this->assertEquals('Ballena', $opciones[0]->label);
        $this->assertEquals(0, (int) $opciones[0]->numero);
        $this->assertEquals('ballena', $opciones[0]->value);

        $this->assertEquals('Delfin', $opciones[1]->label);
        $this->assertEquals(0, (int) $opciones[1]->numero);
        $this->assertEquals('delfin', $opciones[1]->value);

        // La figura especial 99 = Guacharito
        $this->assertEquals('Guacharito', $opciones[100]->label);
        $this->assertEquals(99, (int) $opciones[100]->numero);
        $this->assertEquals('guacharito', $opciones[100]->value);
    }

    public function test_seed_y_scrape_persisten_resultados(): void
    {
        $this->assertEquals(0, Resultado::count());

        $juego = Juego::where('slug', 'el-guacharito')->first();
        $scraper = new ElGuacharitoOficialScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/elguacharito_oficial_20260912.json'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $json);

        $guardados = $scraper->saveResults($resultados, '2026-09-12');

        $this->assertEquals(12, count($resultados));
        $this->assertEquals(12, $guardados);
        $this->assertEquals(12, Resultado::count());

        $persistido = Resultado::where('juego_id', $juego->id)
            ->whereDate('fecha_sorteo', '2026-09-12')
            ->where('hora_sorteo', '08:30')
            ->first();

        $this->assertNotNull($persistido);
        $this->assertEquals(64, $persistido->numeros_ganadores['numero']);
        $this->assertEquals('Gavilan', $persistido->numeros_ganadores['nombre_animal']);
        $this->assertEquals('VE', $persistido->numeros_ganadores['pais']);
    }

    public function test_dedupe_al_rescrapear_el_mismo_dia(): void
    {
        $juego = Juego::where('slug', 'el-guacharito')->first();
        $scraper = new ElGuacharitoOficialScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/elguacharito_oficial_20260912.json'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $json);

        $scraper->saveResults($resultados, '2026-09-12');
        $this->assertEquals(12, Resultado::count());

        $scraper->saveResults($resultados, '2026-09-12');
        $this->assertEquals(12, Resultado::count(), 'No debe duplicarse el resultado del mismo sorteo');
    }

    public function test_resolve_scraper_usa_scraper_class_registrado(): void
    {
        $juego = Juego::where('slug', 'el-guacharito')->first();

        $job = new ScrapeResultsJob($juego->id, '2026-09-12');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('resolveScraper');
        $method->setAccessible(true);

        $this->assertEquals(ElGuacharitoOficialScraper::class, $method->invoke($job, $juego));
    }
}
