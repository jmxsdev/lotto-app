<?php

namespace Tests\Feature;

use App\Jobs\ScrapeSourceJob;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\Resultado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Sweep de reconciliación (`resultados:reconciliar`).
 *
 * El sweep consulta `juego_horarios` (hora <= now - gracia) contra `resultados`
 * del día y despacha un `ScrapeSourceJob` SOLO para las fuentes con sorteos
 * esperados-faltantes. Día sano = 0 despachos (solo lecturas).
 *
 * Tiempo congelado en 2026-09-21 14:00 (America/Caracas) para determinismo.
 */
class ReconciliarSorteosCommandTest extends TestCase
{
    use RefreshDatabase;

    private const FECHA = '2026-09-21';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::FECHA.' 14:00:00');
        Bus::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function crearJuego(string $slug): Juego
    {
        return Juego::create([
            'name' => $slug,
            'slug' => $slug,
            // tripletas + URL fuera de lottoactivo/triplezulia → fuente 1:1 (key = slug),
            // para que cada juego del test sea su propia fuente despachable.
            'type' => 'tripletas',
            'config' => ['premio_multiplo' => 30],
            'requires_scraper' => true,
            'scraper_url' => 'https://ejemplo.com/'.$slug,
            'active' => true,
        ]);
    }

    private function crearHorario(Juego $juego, string $hora): JuegoHorario
    {
        return JuegoHorario::create([
            'juego_id' => $juego->id,
            'hora' => $hora.':00',
            'active' => true,
        ]);
    }

    private function crearResultado(Juego $juego, string $hora): Resultado
    {
        return Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => Carbon::parse(self::FECHA),
            'hora_sorteo' => $hora,
            'numeros_ganadores' => ['numero' => '1234'],
        ]);
    }

    public function test_reconciliar_despacha_solo_las_fuentes_con_sorteos_faltantes(): void
    {
        // 12:00 (hace 2 h, fuera de gracia y dentro de ventana) → falta
        $faltante = $this->crearJuego('alpha');
        $this->crearHorario($faltante, '12:00');

        // 12:00 con resultado persistido → sano
        $sano = $this->crearJuego('beta');
        $this->crearHorario($sano, '12:00');
        $this->crearResultado($sano, '12:00');

        // 13:30 (hace 30 min, dentro de la gracia de 50) → aún no es faltante
        $enGracia = $this->crearJuego('gamma');
        $this->crearHorario($enGracia, '13:30');

        $this->artisan('resultados:reconciliar', ['--force' => true])->assertSuccessful();

        Bus::assertDispatched(ScrapeSourceJob::class, 1);
        Bus::assertDispatched(ScrapeSourceJob::class, fn (ScrapeSourceJob $job) => $job->sourceKey === 'alpha'
            && $job->fecha === self::FECHA
            && $job->horaObjetivo === null);
    }

    public function test_reconciliar_respeta_la_gracia(): void
    {
        // 13:30 (hace 30 min): con gracia 50 no es faltante; con gracia 20 sí.
        $juego = $this->crearJuego('alpha');
        $this->crearHorario($juego, '13:30');

        $this->artisan('resultados:reconciliar', ['--force' => true])->assertSuccessful();
        Bus::assertNotDispatched(ScrapeSourceJob::class);

        $this->artisan('resultados:reconciliar', ['--grace' => 20, '--force' => true])->assertSuccessful();
        Bus::assertDispatched(ScrapeSourceJob::class, 1);
    }

    public function test_reconciliar_respeta_la_ventana(): void
    {
        // 09:00 (hace 5 h): fuera de la ventana 180 (+gracia 50 = 230 min) → no se rescata
        // (gap permanente; la alerta lo visibiliza). 11:00 (hace 3 h) → dentro de ventana.
        $viejo = $this->crearJuego('alpha');
        $this->crearHorario($viejo, '09:00');

        $reciente = $this->crearJuego('beta');
        $this->crearHorario($reciente, '11:00');

        $this->artisan('resultados:reconciliar', ['--force' => true])->assertSuccessful();
        Bus::assertDispatched(ScrapeSourceJob::class, 1);
        Bus::assertDispatched(ScrapeSourceJob::class, fn (ScrapeSourceJob $job) => $job->sourceKey === 'beta');

        // Con ventana ampliada, el hueco viejo también se rescata.
        Bus::fake();
        $this->artisan('resultados:reconciliar', ['--window' => 400, '--force' => true])->assertSuccessful();
        Bus::assertDispatched(ScrapeSourceJob::class, 2);
        Bus::assertDispatched(ScrapeSourceJob::class, fn (ScrapeSourceJob $job) => $job->sourceKey === 'alpha');
    }

    public function test_reconciliar_day_close_usa_gracia_cero_y_ventana_dia_completo(): void
    {
        // 13:30 (dentro de la gracia normal) y 09:00 (fuera de la ventana normal):
        // con --day-close ambos cuentan como faltantes (gracia 0 + día completo).
        $enGracia = $this->crearJuego('alpha');
        $this->crearHorario($enGracia, '13:30');

        $fueraVentana = $this->crearJuego('beta');
        $this->crearHorario($fueraVentana, '09:00');

        $this->artisan('resultados:reconciliar', ['--day-close' => true, '--force' => true])->assertSuccessful();

        Bus::assertDispatched(ScrapeSourceJob::class, 2);
        Bus::assertDispatched(ScrapeSourceJob::class, fn (ScrapeSourceJob $job) => $job->sourceKey === 'alpha');
        Bus::assertDispatched(ScrapeSourceJob::class, fn (ScrapeSourceJob $job) => $job->sourceKey === 'beta');
    }

    public function test_reconciliar_respeta_max_sources(): void
    {
        foreach (['alpha', 'beta', 'gamma'] as $slug) {
            $juego = $this->crearJuego($slug);
            $this->crearHorario($juego, '12:00');
        }

        $this->artisan('resultados:reconciliar', ['--force' => true])->assertSuccessful();
        Bus::assertDispatched(ScrapeSourceJob::class, 3);

        Bus::fake();
        $this->artisan('resultados:reconciliar', ['--max-sources' => 2, '--force' => true])->assertSuccessful();
        Bus::assertDispatched(ScrapeSourceJob::class, 2);
    }

    public function test_reconciliar_dia_sano_no_despacha(): void
    {
        $juego = $this->crearJuego('alpha');
        $this->crearHorario($juego, '12:00');
        $this->crearResultado($juego, '12:00');

        $this->artisan('resultados:reconciliar', ['--force' => true])->assertSuccessful();

        Bus::assertNotDispatched(ScrapeSourceJob::class);
    }

    public function test_reconciliar_dry_run_lista_faltantes_sin_despachar(): void
    {
        $juego = $this->crearJuego('alpha');
        $this->crearHorario($juego, '12:00');

        $this->artisan('resultados:reconciliar', ['--dry-run' => true, '--force' => true])
            ->expectsOutputToContain('alpha')
            ->assertSuccessful();

        Bus::assertNotDispatched(ScrapeSourceJob::class);
    }

    public function test_reconciliar_no_despacha_fuera_de_produccion_sin_force(): void
    {
        $juego = $this->crearJuego('alpha');
        $this->crearHorario($juego, '12:00');

        // El entorno de tests no es producción: sin --force no se despacha.
        $this->artisan('resultados:reconciliar')->assertSuccessful();
        Bus::assertNotDispatched(ScrapeSourceJob::class);

        $this->artisan('resultados:reconciliar', ['--force' => true])->assertSuccessful();
        Bus::assertDispatched(ScrapeSourceJob::class, 1);
    }
}
