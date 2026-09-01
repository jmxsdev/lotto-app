<?php

namespace Tests\Feature;

use App\Jobs\ScrapeResultsJob;
use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\PluginJuego;
use App\Models\Resultado;
use App\Plugins\Juegos\Animalitos;
use App\Plugins\Scrapers\LoteriaDeHoyScraper;
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
        $this->assertEquals('https://loteriadehoy.com/animalito/guacharoactivo/resultados/', $juego->scraper_url);
        $this->assertEquals(LoteriaDeHoyScraper::class, $juego->scraper_class);
        $this->assertEquals(30, $juego->config['premio_multiplo']);
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

    public function test_seed_y_scrape_persisten_resultados(): void
    {
        $this->assertEquals(0, Resultado::count());

        $juego = Juego::where('slug', 'guacharo-activo')->first();
        $scraper = new LoteriaDeHoyScraper($juego);
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_guacharo.html'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $html);

        $guardados = $scraper->saveResults($resultados, '2026-09-01');

        $this->assertEquals(5, count($resultados));
        $this->assertEquals(5, $guardados);
        $this->assertEquals(5, Resultado::count());

        $persistido = Resultado::where('juego_id', $juego->id)
            ->whereDate('fecha_sorteo', '2026-09-01')
            ->where('hora_sorteo', '08:00')
            ->first();

        $this->assertNotNull($persistido);
        $this->assertEquals(62, $persistido->numeros_ganadores['numero']);
        $this->assertEquals('Cachicamo', $persistido->numeros_ganadores['nombre_animal']);
        $this->assertEquals('VE', $persistido->numeros_ganadores['pais']);
    }

    public function test_dedupe_al_rescrapear_el_mismo_dia(): void
    {
        $juego = Juego::where('slug', 'guacharo-activo')->first();
        $scraper = new LoteriaDeHoyScraper($juego);
        $html = file_get_contents(base_path('tests/Fixtures/loteriadehoy_guacharo.html'));

        $reflection = new \ReflectionClass($scraper);
        $parse = $reflection->getMethod('parse');
        $parse->setAccessible(true);
        $resultados = $parse->invoke($scraper, $html);

        $scraper->saveResults($resultados, '2026-09-01');
        $this->assertEquals(5, Resultado::count());

        $scraper->saveResults($resultados, '2026-09-01');
        $this->assertEquals(5, Resultado::count(), 'No debe duplicarse el resultado del mismo sorteo');
    }

    public function test_resolve_scraper_usa_scraper_class_registrado(): void
    {
        $juego = Juego::where('slug', 'guacharo-activo')->first();

        $job = new ScrapeResultsJob($juego->id, '2026-09-01');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('resolveScraper');
        $method->setAccessible(true);

        $this->assertEquals(LoteriaDeHoyScraper::class, $method->invoke($job, $juego));
    }
}
