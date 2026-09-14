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
use App\Plugins\Scrapers\GuacharoActivoOficialScraper;
use Database\Seeders\GuacharoActivoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuacharoResultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El seeder registra el límite default a nivel banca solo si existe una banca.
        Banca::create([
            'name' => 'Banca Demo',
            'code' => 'BANCA-DEMO-GA',
        ]);

        $this->seed(GuacharoActivoSeeder::class);
    }

    public function test_seeder_registra_juego_con_scraper_class(): void
    {
        $juego = Juego::where('slug', 'guacharo-activo')->first();

        $this->assertNotNull($juego);
        $this->assertEquals('Guacharo Activo', $juego->name);
        $this->assertEquals('animalitos', $juego->type);
        $this->assertTrue($juego->requires_scraper);
        $this->assertEquals('https://api.lotterly.co/v1/results/guacharo-activo/', $juego->scraper_url);
        $this->assertEquals(GuacharoActivoOficialScraper::class, $juego->scraper_class);
        $this->assertEquals(60, $juego->config['premio_multiplo']);
    }

    public function test_seeder_registra_el_comodin_guacharo_75(): void
    {
        $juego = Juego::where('slug', 'guacharo-activo')->first();

        // Comodín oficial del bundle: Guácharo (75) duplica el premio
        // (60x → 120x).
        $this->assertArrayHasKey('comodines', $juego->config);
        $this->assertEquals(120, $juego->config['comodines']['guacharo-75']['premio_multiplo']);
        $this->assertEquals(75, $juego->config['comodines']['guacharo-75']['numero']);
        $this->assertEquals('Guácharo', $juego->config['comodines']['guacharo-75']['nombre']);
    }

    public function test_seeder_registra_limite_default_y_plugin_animalitos(): void
    {
        $juego = Juego::where('slug', 'guacharo-activo')->first();

        $limite = JuegoLimite::where('juego_id', $juego->id)->where('moneda', 'bs')->first();
        $this->assertNotNull($limite);
        $this->assertEquals(3600, (int) $limite->limite_minimo);

        $plugin = PluginJuego::where('juego_id', $juego->id)->where('active', true)->first();
        $this->assertNotNull($plugin);
        $this->assertEquals(Animalitos::class, $plugin->class_namespace);
    }

    public function test_seeder_registra_los_doce_horarios_de_sorteo(): void
    {
        $juego = Juego::where('slug', 'guacharo-activo')->first();

        // La columna es TIME (MySQL devuelve H:i:s); el scheduler consume substr(hora, 0, 5).
        $horas = JuegoHorario::where('juego_id', $juego->id)
            ->where('active', true)
            ->orderBy('hora')
            ->get()
            ->map(fn ($horario) => substr($horario->hora, 0, 5))
            ->all();

        $this->assertEquals(
            ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00'],
            $horas
        );
    }

    public function test_seeder_registra_las_77_opciones_del_zoo_propio(): void
    {
        $juego = Juego::where('slug', 'guacharo-activo')->first();

        $opciones = JuegoOpcion::where('juego_id', $juego->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(77, $opciones);

        // Ballena y Delfín comparten numero 0; labels CON acentos (bundle oficial)
        $this->assertEquals('Ballena', $opciones[0]->label);
        $this->assertEquals(0, (int) $opciones[0]->numero);
        $this->assertEquals('ballena', $opciones[0]->value);

        $this->assertEquals('Delfín', $opciones[1]->label);
        $this->assertEquals(0, (int) $opciones[1]->numero);
        $this->assertEquals('delfin', $opciones[1]->value);

        // El comodín 75 = Guacharo
        $this->assertEquals('Guacharo', $opciones[76]->label);
        $this->assertEquals(75, (int) $opciones[76]->numero);
        $this->assertEquals('guacharo', $opciones[76]->value);
    }

    public function test_seed_y_scrape_persisten_resultados(): void
    {
        $this->assertEquals(0, Resultado::count());

        $juego = Juego::where('slug', 'guacharo-activo')->first();
        $scraper = new GuacharoActivoOficialScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/guacharoactivo_oficial_20260912.json'));

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
            ->where('hora_sorteo', '08:00')
            ->first();

        $this->assertNotNull($persistido);
        $this->assertEquals(24, $persistido->numeros_ganadores['numero']);
        $this->assertEquals('Iguana', $persistido->numeros_ganadores['nombre_animal']);
        $this->assertEquals('VE', $persistido->numeros_ganadores['pais']);
    }

    public function test_dedupe_al_rescrapear_el_mismo_dia(): void
    {
        $juego = Juego::where('slug', 'guacharo-activo')->first();
        $scraper = new GuacharoActivoOficialScraper($juego);
        $json = file_get_contents(base_path('tests/Fixtures/guacharoactivo_oficial_20260912.json'));

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
        $juego = Juego::where('slug', 'guacharo-activo')->first();

        $job = new ScrapeResultsJob($juego->id, '2026-09-12');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('resolveScraper');
        $method->setAccessible(true);

        $this->assertEquals(GuacharoActivoOficialScraper::class, $method->invoke($job, $juego));
    }
}
