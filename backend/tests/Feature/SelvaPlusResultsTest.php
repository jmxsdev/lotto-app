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
use App\Plugins\Scrapers\SelvaPlusScraper;
use Database\Seeders\SelvaPlusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelvaPlusResultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El seeder registra el límite default a nivel banca solo si existe una banca.
        Banca::create([
            'name' => 'Banca Demo',
            'code' => 'BANCA-DEMO-SP',
        ]);

        $this->seed(SelvaPlusSeeder::class);
    }

    public function test_seeder_registra_juego_con_scraper_class_y_config(): void
    {
        $juego = Juego::where('slug', 'selva-plus')->first();

        $this->assertNotNull($juego);
        $this->assertEquals('Selva Plus', $juego->name);
        $this->assertEquals('animalitos', $juego->type);
        $this->assertTrue($juego->requires_scraper);
        $this->assertEquals('https://api.lotterly.co/v1/results/selva-plus/', $juego->scraper_url);
        $this->assertEquals(SelvaPlusScraper::class, $juego->scraper_class);

        // Premios oficiales: base 80× + 2 comodines (Leoncito 160×, Selva Plus 200×)
        $this->assertEquals(80, $juego->config['premio_multiplo']);
        $this->assertEquals('Leoncito', $juego->config['comodines']['comodin-a']['nombre']);
        $this->assertEquals(160, $juego->config['comodines']['comodin-a']['premio_multiplo']);
        $this->assertEquals('Selva Plus', $juego->config['comodines']['comodin-b']['nombre']);
        $this->assertEquals(200, $juego->config['comodines']['comodin-b']['premio_multiplo']);
    }

    public function test_seeder_registra_limite_default_y_plugin(): void
    {
        $juego = Juego::where('slug', 'selva-plus')->first();

        $limite = JuegoLimite::where('juego_id', $juego->id)->where('moneda', 'bs')->first();
        $this->assertNotNull($limite);
        $this->assertEquals(3600, (int) $limite->limite_minimo);

        $plugin = PluginJuego::where('juego_id', $juego->id)->where('active', true)->first();
        $this->assertNotNull($plugin);
        $this->assertEquals(Animalitos::class, $plugin->class_namespace);
    }

    public function test_seeder_registra_los_trece_horarios_de_sorteo(): void
    {
        $juego = Juego::where('slug', 'selva-plus')->first();

        // La columna es TIME (MySQL devuelve H:i:s); el scheduler consume substr(hora, 0, 5).
        $horas = JuegoHorario::where('juego_id', $juego->id)
            ->where('active', true)
            ->orderBy('hora')
            ->get()
            ->map(fn ($horario) => substr($horario->hora, 0, 5))
            ->all();

        // 08:15–20:15 (:15 cada hora, 13 sorteos al día)
        $this->assertEquals(
            ['08:15', '09:15', '10:15', '11:15', '12:15', '13:15', '14:15', '15:15', '16:15', '17:15', '18:15', '19:15', '20:15'],
            $horas
        );
    }

    public function test_seeder_registra_las_103_opciones_del_zoo_propio_mas_comodines(): void
    {
        $juego = Juego::where('slug', 'selva-plus')->first();

        $opciones = JuegoOpcion::where('juego_id', $juego->id)
            ->orderBy('sort_order')
            ->get();

        // 101 figuras + 2 comodines = 103 opciones
        $this->assertCount(103, $opciones);

        // Ballena y Delfín comparten numero 0; labels con acentos; value sin acentos
        $this->assertEquals('Ballena', $opciones[0]->label);
        $this->assertEquals(0, (int) $opciones[0]->numero);
        $this->assertEquals('ballena', $opciones[0]->value);

        $this->assertEquals('Delfín', $opciones[1]->label);
        $this->assertEquals(0, (int) $opciones[1]->numero);
        $this->assertEquals('delfin', $opciones[1]->value);

        // Ciempiés con acento, value slug sin acento; última figura: Halcón 99
        $this->assertEquals('Ciempiés', $opciones[4]->label);
        $this->assertEquals(3, (int) $opciones[4]->numero);
        $this->assertEquals('ciempies', $opciones[4]->value);

        $this->assertEquals('Halcón', $opciones[100]->label);
        $this->assertEquals(99, (int) $opciones[100]->numero);
        $this->assertEquals('halcon', $opciones[100]->value);

        // Comodines al final (sort_order 101 y 102): numero null
        $this->assertEquals('Leoncito (comodín A)', $opciones[101]->label);
        $this->assertNull($opciones[101]->numero);
        $this->assertEquals('comodin-a', $opciones[101]->value);

        $this->assertEquals('Selva Plus (comodín B)', $opciones[102]->label);
        $this->assertNull($opciones[102]->numero);
        $this->assertEquals('comodin-b', $opciones[102]->value);
    }

    public function test_seed_y_scrape_persisten_resultados(): void
    {
        $this->assertEquals(0, Resultado::count());

        $juego = Juego::where('slug', 'selva-plus')->first();
        $scraper = new SelvaPlusScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/selvaplus_results.json'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $json);

        $guardados = $scraper->saveResults($resultados, '2026-09-11');

        $this->assertEquals(13, count($resultados));
        $this->assertEquals(13, $guardados);
        $this->assertEquals(13, Resultado::count());

        $persistido = Resultado::where('juego_id', $juego->id)
            ->whereDate('fecha_sorteo', '2026-09-11')
            ->where('hora_sorteo', '08:15')
            ->first();

        $this->assertNotNull($persistido);
        $this->assertEquals(27, $persistido->numeros_ganadores['numero']);
        $this->assertEquals('Perro', $persistido->numeros_ganadores['nombre_animal']);
        $this->assertEquals('VE', $persistido->numeros_ganadores['pais']);
    }

    public function test_dedupe_al_rescrapear_el_mismo_dia(): void
    {
        $juego = Juego::where('slug', 'selva-plus')->first();
        $scraper = new SelvaPlusScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/selvaplus_results.json'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $json);

        $scraper->saveResults($resultados, '2026-09-11');
        $this->assertEquals(13, Resultado::count());

        $scraper->saveResults($resultados, '2026-09-11');
        $this->assertEquals(13, Resultado::count(), 'No debe duplicarse el resultado del mismo sorteo');
    }

    public function test_resolve_scraper_usa_scraper_class_registrado(): void
    {
        $juego = Juego::where('slug', 'selva-plus')->first();

        $job = new ScrapeResultsJob($juego->id, '2026-09-11');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('resolveScraper');
        $method->setAccessible(true);

        $this->assertEquals(SelvaPlusScraper::class, $method->invoke($job, $juego));
    }
}
