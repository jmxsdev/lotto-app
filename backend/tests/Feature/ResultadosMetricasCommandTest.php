<?php

namespace Tests\Feature;

use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\Resultado;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Textfile de Prometheus con las métricas de sorteos esperados/persistidos.
 *
 * El comando `resultados:metricas` escribe `resultados.prom` (formato textfile
 * de node-exporter) con métricas por juego (label `juego`=slug):
 * expected_today, persisted_today, missing, pending_seconds, daily_incomplete
 * y el timestamp global de la última escritura. La escritura es atómica
 * (tmp+rename) y falla si el directorio textfile no existe.
 */
class ResultadosMetricasCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/metricas-'.uniqid());
        File::makeDirectory($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function crearJuego(string $slug, bool $requiresScraper = true): Juego
    {
        return Juego::create([
            'name' => $slug,
            'slug' => $slug,
            'type' => 'animalitos',
            'config' => ['premio_multiplo' => 30],
            'requires_scraper' => $requiresScraper,
            'scraper_url' => 'https://www.lottoactivo.com/resultados/animalitos/',
            'active' => true,
        ]);
    }

    private function crearHorario(Juego $juego, string $hora): void
    {
        JuegoHorario::create([
            'juego_id' => $juego->id,
            'hora' => $hora,
            'active' => true,
        ]);
    }

    public function test_escribe_resultados_prom_con_las_metricas_por_juego(): void
    {
        Carbon::setTestNow('2026-09-21 14:00:00');

        $juego = $this->crearJuego('lotto-activo');
        $this->crearHorario($juego, '08:00:00');
        $this->crearHorario($juego, '12:00:00');
        $this->crearHorario($juego, '20:00:00');

        // 08:00 persistido; 12:00 vencido y faltante; 20:00 futuro (no cuenta como faltante).
        Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => Carbon::parse('2026-09-21 08:00:00'),
            'hora_sorteo' => '08:00',
            'numeros_ganadores' => ['numero' => 42, 'nombre_animal' => 'perro', 'pais' => 'VE'],
        ]);

        // Juego sin scraper: no debe aparecer en las métricas.
        $manual = $this->crearJuego('manual', false);
        $this->crearHorario($manual, '08:00:00');

        $this->artisan('resultados:metricas', ['--path' => $this->dir])
            ->assertExitCode(0);

        $this->assertFileExists($this->dir.'/resultados.prom');
        $contenido = File::get($this->dir.'/resultados.prom');

        $this->assertStringContainsString('lotto_draws_expected_today{juego="lotto-activo"} 3', $contenido);
        $this->assertStringContainsString('lotto_draws_persisted_today{juego="lotto-activo"} 1', $contenido);
        $this->assertStringContainsString('lotto_draws_missing{juego="lotto-activo"} 1', $contenido);
        $this->assertStringContainsString('lotto_draws_pending_seconds{juego="lotto-activo"} 7200', $contenido);
        // Antes de las 23:45 la referencia del conteo diario es AYER (0 persistidos vs 3 esperados).
        $this->assertStringContainsString('lotto_daily_incomplete{juego="lotto-activo"} 1', $contenido);

        $timestamp = Carbon::parse('2026-09-21 14:00:00')->timestamp;
        $this->assertStringContainsString('lotto_metrics_timestamp '.$timestamp, $contenido);

        // El juego sin scraper no genera métricas.
        $this->assertStringNotContainsString('juego="manual"', $contenido);
    }

    public function test_la_escritura_es_atomica_y_no_deja_archivos_temporales(): void
    {
        Carbon::setTestNow('2026-09-21 14:00:00');

        $juego = $this->crearJuego('lotto-activo');
        $this->crearHorario($juego, '08:00:00');

        $this->artisan('resultados:metricas', ['--path' => $this->dir])
            ->assertExitCode(0);

        $archivos = array_map('basename', glob($this->dir.'/*'));

        $this->assertSame(['resultados.prom'], $archivos, 'Solo debe quedar el textfile final, sin temporales (tmp+rename).');
        $this->assertFileDoesNotExist($this->dir.'/resultados.prom.tmp');
    }

    public function test_falla_si_el_directorio_textfile_no_existe(): void
    {
        Carbon::setTestNow('2026-09-21 14:00:00');

        $juego = $this->crearJuego('lotto-activo');
        $this->crearHorario($juego, '08:00:00');

        $pathInexistente = $this->dir.'/no-existe';

        $this->artisan('resultados:metricas', ['--path' => $pathInexistente])
            ->assertExitCode(1)
            ->expectsOutputToContain('no existe');

        $this->assertFileDoesNotExist($pathInexistente.'/resultados.prom');
    }

    public function test_dia_completo_despues_de_las_23_45_no_genera_incompletos(): void
    {
        Carbon::setTestNow('2026-09-22 23:50:00');

        $juego = $this->crearJuego('lotto-activo');
        $this->crearHorario($juego, '08:00:00');

        Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => Carbon::parse('2026-09-22 08:00:00'),
            'hora_sorteo' => '08:00',
            'numeros_ganadores' => ['numero' => 7, 'nombre_animal' => 'gato', 'pais' => 'VE'],
        ]);

        $this->artisan('resultados:metricas', ['--path' => $this->dir])
            ->assertExitCode(0);

        $contenido = File::get($this->dir.'/resultados.prom');

        // Tras las 23:45 la referencia del conteo diario es HOY y el día está completo.
        $this->assertStringContainsString('lotto_daily_incomplete{juego="lotto-activo"} 0', $contenido);
        $this->assertStringContainsString('lotto_draws_missing{juego="lotto-activo"} 0', $contenido);
        $this->assertStringContainsString('lotto_draws_pending_seconds{juego="lotto-activo"} 0', $contenido);
    }

    public function test_pending_seconds_usa_el_sorteo_faltante_mas_temprano(): void
    {
        Carbon::setTestNow('2026-09-21 14:00:00');

        $juego = $this->crearJuego('lotto-activo');
        $this->crearHorario($juego, '08:00:00');
        $this->crearHorario($juego, '09:00:00');
        $this->crearHorario($juego, '10:00:00');

        $this->artisan('resultados:metricas', ['--path' => $this->dir])
            ->assertExitCode(0);

        $contenido = File::get($this->dir.'/resultados.prom');

        // 3 vencidos sin persistir; el más temprano (08:00) lleva 6 horas de pendiente.
        $this->assertStringContainsString('lotto_draws_missing{juego="lotto-activo"} 3', $contenido);
        $this->assertStringContainsString('lotto_draws_pending_seconds{juego="lotto-activo"} 21600', $contenido);
    }
}
