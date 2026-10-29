<?php

namespace Tests\Feature;

use App\Models\Juego;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ejecución real de las migraciones de backfill de tripletas (issue #64).
 *
 * Los tests de catálogo cubren el contrato de datos, pero no la escritura de
 * `juegos.config`: con la DB recién migrada las migraciones no encuentran
 * filas y hacen `continue`. Estos casos siembran una fila previa y ejercitan
 * `up()`, la idempotencia y `down()`.
 */
class BackfillTripletasMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migracion(string $archivo): object
    {
        return require database_path("migrations/{$archivo}");
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function juego(string $slug, array $config): Juego
    {
        return Juego::create([
            'name' => $slug,
            'slug' => $slug,
            'type' => 'tripletas',
            'config' => $config,
            'requires_scraper' => true,
            'active' => true,
        ]);
    }

    public function test_backfill_de_posicion_triples_escribe_idempotente_y_revierte(): void
    {
        $juego = $this->juego('triple-zulia', [
            'premios' => [
                'base' => 600,
                'modalidades' => ['terminal' => 60],
                'comodines' => [],
            ],
        ]);

        $migracion = $this->migracion('2026_10_29_000001_backfill_posicion_triples.php');

        $migracion->up();
        $juego->refresh();
        $this->assertSame(['a', 'b', 'c'], $juego->config['premios']['posicion_triples']);

        $migracion->up();
        $juego->refresh();
        $this->assertSame(['a', 'b', 'c'], $juego->config['premios']['posicion_triples'], 'Idempotente.');

        $migracion->down();
        $juego->refresh();
        $this->assertArrayNotHasKey('posicion_triples', $juego->config['premios']);
    }

    public function test_backfill_de_tachira_retira_terminal_escribe_espejos_y_revierte(): void
    {
        $juego = $this->juego('triple-tachira', [
            'premios' => [
                'base' => 500,
                'modalidades' => ['terminal' => 50, 'signo_triple' => 5000],
                'posicion_triples' => [],
                'comodines' => [],
            ],
            'premio_multiplo' => 500,
            'modalidades' => ['cola' => 50, 'zodiacal' => 5000],
            'comodines' => [],
        ]);

        $migracion = $this->migracion('2026_10_29_000002_backfill_premios_tachira.php');

        $migracion->up();
        $juego->refresh();

        $modalidades = $juego->config['premios']['modalidades'];
        $this->assertArrayNotHasKey('terminal', $modalidades);
        $this->assertSame(500, $modalidades['signo_terminal']);
        $this->assertSame(10, $modalidades['aproximacion']);
        $this->assertSame(200000, $modalidades['triple_a_b']);
        $this->assertSame(0, $modalidades['solo_a_b']);

        $this->assertArrayNotHasKey('cola', $juego->config['modalidades']);
        $this->assertSame(500, $juego->config['modalidades']['terminal_zodiacal']);

        $migracion->up();
        $juego->refresh();
        $this->assertSame(0, $juego->config['premios']['modalidades']['solo_a_b'], 'Idempotente.');

        $migracion->down();
        $juego->refresh();
        $this->assertSame(50, $juego->config['premios']['modalidades']['terminal']);
        $this->assertArrayNotHasKey('signo_terminal', $juego->config['premios']['modalidades']);
        $this->assertSame(50, $juego->config['modalidades']['cola']);
        $this->assertSame(5000, $juego->config['modalidades']['zodiacal']);
    }
}
