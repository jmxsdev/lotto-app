<?php

namespace Tests\Feature;

use App\Jobs\ScrapeSourceJob;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\Log;
use App\Models\Resultado;
use App\Plugins\Scrapers\AnimalitosScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Doble de AnimalitosScraper que corta SOLO el fetch de red: parsea el
 * fixture real y conserva el saveResults/upsert reales. Cuenta los fetches
 * para probar "un fetch por fuente".
 */
class AnimalitosScraperFake extends AnimalitosScraper
{
    public int $fetches = 0;

    public function execute(?string $fecha = null): array
    {
        $this->fetches++;

        $raw = file_get_contents(base_path('tests/Fixtures/lottoactivo_animalitos_response.json'));

        return $this->parse($raw);
    }
}

class ScrapeSourceJobTest extends TestCase
{
    use RefreshDatabase;

    protected function crearJuego(string $slug, string $name, string $url): Juego
    {
        $juego = Juego::create([
            'slug' => $slug,
            'name' => $name,
            'type' => 'animalitos',
            'requires_scraper' => true,
            'scraper_url' => $url,
            'active' => true,
        ]);

        JuegoHorario::create([
            'juego_id' => $juego->id,
            'hora' => '08:00:00',
            'active' => true,
        ]);

        return $juego;
    }

    /**
     * @return array{0: Juego, 1: Juego, 2: Juego, 3: Juego}
     */
    protected function crearFamilia(): array
    {
        $lotto = $this->crearJuego('lotto-activo', 'Lotto Activo', 'https://www.lottoactivo.com/resultados/animalitos/');
        $rd = $this->crearJuego('lotto-activo-rd', 'Lotto Activo RD Internacional', 'https://www.lottoactivo.com/resultados/animalitos/');
        $repDom = $this->crearJuego('lotto-activo-rep-dom', 'Lotto Activo República Dominicana', 'https://www.lottoactivo.com/resultados/animalitos/');
        $monje = $this->crearJuego('monje-millonario', 'Monje Millonario', 'https://www.lottoactivo.com/resultados/animalitos/');

        return [$lotto, $rd, $repDom, $monje];
    }

    protected function fakeScraper(): AnimalitosScraperFake
    {
        $stub = new AnimalitosScraperFake('animalitos');

        // bind (no instance): make() con parámetros (['slug' => ...]) ignora
        // las instancias del contenedor, pero sí invoca el closure del binding.
        $this->app->bind(AnimalitosScraper::class, fn () => $stub);

        return $stub;
    }

    public function test_un_fetch_persiste_toda_la_familia_bajo_su_propio_juego_id(): void
    {
        [$lotto, $rd, $repDom, $monje] = $this->crearFamilia();
        $stub = $this->fakeScraper();

        $job = new ScrapeSourceJob('lottoactivo-animalitos', '2026-09-12', '08:00');
        $job->handle();

        $this->assertSame(1, $stub->fetches, 'Un solo fetch debe alimentar a los 4 juegos de la familia.');
        $this->assertSame(3, Resultado::where('juego_id', $lotto->id)->count());
        $this->assertSame(2, Resultado::where('juego_id', $rd->id)->count());
        $this->assertSame(3, Resultado::where('juego_id', $repDom->id)->count());
        $this->assertSame(3, Resultado::where('juego_id', $monje->id)->count());
        $this->assertSame(11, Resultado::count(), 'El feed completo debe persistirse en una sola corrida.');
    }

    public function test_guardados_se_desglosan_por_juego_en_el_log(): void
    {
        [$lotto, $rd, $repDom, $monje] = $this->crearFamilia();
        $this->fakeScraper();

        $job = new ScrapeSourceJob('lottoactivo-animalitos', '2026-09-12', '08:00');
        $job->handle();

        $log = Log::where('action', 'scrape_resultados')->latest()->first();

        $this->assertNotNull($log, 'El job debe dejar bitácora en la tabla de logs.');
        $this->assertSame('lottoactivo-animalitos', $log->details['fuente']);
        $this->assertSame(11, $log->details['total']);
        $this->assertSame(3, $log->details['por_juego'][$lotto->id]);
        $this->assertSame(2, $log->details['por_juego'][$rd->id]);
        $this->assertSame(3, $log->details['por_juego'][$repDom->id]);
        $this->assertSame(3, $log->details['por_juego'][$monje->id]);
    }

    public function test_reejecucion_no_duplica_filas(): void
    {
        $this->crearFamilia();
        $stub = $this->fakeScraper();

        $job = new ScrapeSourceJob('lottoactivo-animalitos', '2026-09-12', '08:00');
        $job->handle();
        $this->assertSame(11, Resultado::count());

        $job->handle();

        $this->assertSame(11, Resultado::count(), 'Re-ejecutar el job no debe duplicar filas (upsert).');
        $this->assertSame(2, $stub->fetches, 'Sigue habiendo miembros sin fila en la hora objetivo → vuelve a ejecutar.');
    }

    public function test_omite_fetch_si_todos_los_miembros_tienen_fila_en_hora_objetivo(): void
    {
        [$lotto, $rd, $repDom, $monje] = $this->crearFamilia();
        $stub = $this->fakeScraper();

        foreach ([$lotto, $rd, $repDom, $monje] as $juego) {
            Resultado::create([
                'juego_id' => $juego->id,
                'fecha_sorteo' => '2026-09-12',
                'hora_sorteo' => '08:00',
                'numeros_ganadores' => ['numero' => 1],
            ]);
        }

        $job = new ScrapeSourceJob('lottoactivo-animalitos', '2026-09-12', '08:00');
        $job->handle();

        $this->assertSame(0, $stub->fetches, 'Con todos los miembros cubiertos la fuente debe omitirse.');
        $this->assertSame(4, Resultado::count(), 'No debe crearse ninguna fila nueva.');
    }

    public function test_sin_miembros_programados_en_hora_objetivo_no_hay_fetch(): void
    {
        $juego = $this->crearJuego('lotto-activo', 'Lotto Activo', 'https://www.lottoactivo.com/resultados/animalitos/');
        JuegoHorario::where('juego_id', $juego->id)->update(['hora' => '10:00:00']);
        $stub = $this->fakeScraper();

        $job = new ScrapeSourceJob('lottoactivo-animalitos', '2026-09-12', '08:00');
        $job->handle();

        $this->assertSame(0, $stub->fetches, 'Si ningún miembro tiene sorteo a la hora objetivo, no hay nada que recuperar.');
        $this->assertSame(0, Resultado::count());
    }

    public function test_sin_hora_objetivo_siempre_ejecuta_despacho_manual(): void
    {
        $this->crearFamilia();
        $stub = $this->fakeScraper();

        $job = new ScrapeSourceJob('lottoactivo-animalitos', '2026-09-12', null);
        $job->handle();

        $this->assertSame(1, $stub->fetches, 'El disparo manual (horaObjetivo null) no aplica la guarda.');
        $this->assertSame(11, Resultado::count());
    }

    public function test_fuente_inexistente_es_no_op_sin_fetch(): void
    {
        $stub = $this->fakeScraper();

        $job = new ScrapeSourceJob('fuente-inexistente', '2026-09-12', '08:00');
        $job->handle();

        $this->assertSame(0, $stub->fetches);
    }
}
