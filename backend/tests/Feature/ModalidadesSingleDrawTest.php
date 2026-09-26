<?php

namespace Tests\Feature;

use App\Models\Juego;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos;
use App\Plugins\Juegos\Tripletas;
use App\Services\JuegoPluginManager;
use App\Services\PremiosEngine;
use App\Support\PremiosOficiales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F2 2.2 — Modalidades de UN solo sorteo (REQ11, D8, §3.2/§3.4): el motor
 * liquida Cruzado, Par Millonario A+B, Tripleta, Arrimao, Pegadito,
 * Punta/Terminal/Uña/Aproximación y Terminal+Zodiacal como apuestas
 * individuales contra el resultado del único `sorteo_hora`. La multi-selección
 * del MISMO sorteo viaja en `combinacion.selecciones[]` (sin tablas nuevas).
 * La Dupleta queda FUERA de alcance: un test la fija como rechazada.
 *
 * Los juegos se crean desde el catálogo único (PremiosOficiales::configPara)
 * para ejercitar el camino real manager → PremiosEngine → plugin.
 */
class ModalidadesSingleDrawTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, string> slug → plugin del juego (seeders, F1b)
     */
    private const PLUGINS = [
        'trio-activo' => Tripletas::class,
        'triple-zulia' => Tripletas::class,
        'triple-chance' => Tripletas::class,
        'triple-facil' => Tripletas::class,
        'triple-zamorano' => Tripletas::class,
        'triple-tachira' => Tripletas::class,
        'el-arrejuntado' => Tripletas::class,
        'cazaloton' => Animalitos::class,
        'loto-chaima' => Animalitos::class,
    ];

    private function juegoCon(string $slug): Juego
    {
        $juego = Juego::create([
            'name' => str_replace('-', ' ', ucwords($slug, '-')),
            'slug' => $slug,
            'type' => in_array($slug, ['cazaloton', 'loto-chaima'], true) ? 'animalitos' : 'tripletas',
            'config' => PremiosOficiales::configPara($slug),
            'requires_scraper' => true,
            'active' => true,
        ]);

        PluginJuego::create([
            'juego_id' => $juego->id,
            'class_namespace' => self::PLUGINS[$slug],
            'version' => '1.0.0',
            'active' => true,
        ]);

        return $juego;
    }

    /**
     * Liquida por el camino real (manager → engine).
     *
     * @param  array<string, mixed>  $combinacion
     * @param  array<string, mixed>  $numerosGanadores
     * @return array{premio_bs: float, premio_usd: float}
     */
    private function liquidar(string $slug, array $combinacion, array $numerosGanadores, float $montoBs = 10.0): array
    {
        $juego = $this->juegoCon($slug);

        return app(JuegoPluginManager::class)->calcularPremio(
            $juego,
            ['combinacion' => $combinacion, 'amount_bs' => $montoBs, 'amount_usd' => 0],
            ['numeros_ganadores' => $numerosGanadores]
        );
    }

    private function assertPremio(string $slug, array $combinacion, array $numerosGanadores, float $multiplicador, float $montoBs = 10.0): void
    {
        $premio = $this->liquidar($slug, $combinacion, $numerosGanadores, $montoBs);
        $esperado = round($montoBs * $multiplicador, 2);

        $this->assertSame(
            $esperado,
            $premio['premio_bs'],
            "[{$slug}] 10 Bs × {$multiplicador}× = {$esperado} (motor same-draw)."
        );
        $this->assertSame(0.0, $premio['premio_usd'], "[{$slug}] sin monto USD.");
    }

    // ---------------- Cruzado Triple Chance (3.000×/10×) ----------------

    public function test_cruzado_chance_paga_3000x_con_ambas_puntas(): void
    {
        // §3.2: cruzado 3.000×. Punta A = 2 primeras de triple_a, Punta B de triple_b.
        $this->assertPremio(
            'triple-chance',
            [
                'modalidad' => 'cruzado',
                'selecciones' => [
                    ['tipo' => 'punta', 'numero' => '75'],
                    ['tipo' => 'punta', 'numero' => '14'],
                ],
            ],
            ['triple_a' => '756', 'triple_b' => '146', 'triple_c' => '682', 'signo' => 'SAG'],
            3000
        );
    }

    public function test_cruzado_chance_paga_10x_con_una_sola_punta(): void
    {
        // §3.2: cruzado_10 10× (una de las dos puntas acertada).
        $this->assertPremio(
            'triple-chance',
            [
                'modalidad' => 'cruzado',
                'selecciones' => [
                    ['tipo' => 'punta', 'numero' => '75'],
                    ['tipo' => 'punta', 'numero' => '99'],
                ],
            ],
            ['triple_a' => '756', 'triple_b' => '146', 'triple_c' => '682', 'signo' => 'SAG'],
            10
        );
    }

    // ---------------- Par Millonario A+B Triple Chance (200.000× / solo 150×) ----------------

    public function test_par_millonario_chance_paga_200000x_con_ambos_triples(): void
    {
        // §3.2: triple_a_b 200.000× (ambos triples exactos del mismo sorteo).
        $this->assertPremio(
            'triple-chance',
            [
                'modalidad' => 'triple_a_b',
                'selecciones' => [
                    ['tipo' => 'triple_a', 'numero' => '756'],
                    ['tipo' => 'triple_b', 'numero' => '146'],
                ],
            ],
            ['triple_a' => '756', 'triple_b' => '146', 'triple_c' => '682', 'signo' => 'SAG'],
            200000
        );
    }

    public function test_par_millonario_chance_paga_150x_con_un_solo_triple(): void
    {
        // §3.2: solo_a_b 150× (uno de los dos triples acertado).
        $this->assertPremio(
            'triple-chance',
            [
                'modalidad' => 'triple_a_b',
                'selecciones' => [
                    ['tipo' => 'triple_a', 'numero' => '756'],
                    ['tipo' => 'triple_b', 'numero' => '999'],
                ],
            ],
            ['triple_a' => '756', 'triple_b' => '146', 'triple_c' => '682', 'signo' => 'SAG'],
            150
        );
    }

    // ---------------- Tripleta (Cazalotón 200× / Loto Chaima 50×) ----------------

    public function test_tripleta_cazaloton_paga_200x_con_tres_figuras_del_mismo_sorteo(): void
    {
        // §3.2: cazaloton tripleta 200×; 3 selecciones de animal contra las
        // figuras del sorteo (acentos normalizados, REQ2).
        $this->assertPremio(
            'cazaloton',
            [
                'modalidad' => 'tripleta',
                'selecciones' => [
                    ['animal' => 'perro'],
                    ['animal' => 'gato'],
                    ['animal' => 'león'],
                ],
            ],
            [
                'figuras' => [
                    ['animal' => 'Perro', 'numero' => 27],
                    ['animal' => 'Gato', 'numero' => 11],
                    ['animal' => 'Leon', 'numero' => 5],
                ],
            ],
            200
        );
    }

    public function test_tripleta_loto_chaima_paga_50x(): void
    {
        $this->assertPremio(
            'loto-chaima',
            [
                'modalidad' => 'tripleta',
                'selecciones' => [
                    ['animal' => 'Delfín'],
                    ['animal' => 'Caimán'],
                    ['animal' => 'Tucán'],
                ],
            ],
            [
                'figuras' => [
                    ['animal' => 'Delfin', 'numero' => 0],
                    ['animal' => 'Caiman', 'numero' => 30],
                    ['animal' => 'Tucan', 'numero' => 42],
                ],
            ],
            50
        );
    }

    public function test_tripleta_no_paga_si_falta_una_figura_del_resultado(): void
    {
        $premio = $this->liquidar(
            'cazaloton',
            [
                'modalidad' => 'tripleta',
                'selecciones' => [
                    ['animal' => 'perro'],
                    ['animal' => 'gato'],
                    ['animal' => 'leon'],
                ],
            ],
            ['figuras' => [['animal' => 'Perro'], ['animal' => 'Gato']]]
        );

        $this->assertSame(0.0, $premio['premio_bs']);
    }

    // ---------------- El Arrimao (4 cifras, 6.000×) / El Pegadito (5 cifras, 60.000×) ----------------

    public function test_arrimao_arrejuntado_paga_6000x_con_las_4_cifras(): void
    {
        // §3.2: arrimao 6.000×; la clave del scraper es `arrimao` (4 cifras).
        $this->assertPremio(
            'el-arrejuntado',
            ['tipo' => 'arrimao', 'numero' => '1825'],
            ['triple_a' => '894', 'arrimao' => '1825', 'pegadito' => '10503'],
            6000
        );
    }

    public function test_pegadito_arrejuntado_paga_60000x_con_las_5_cifras(): void
    {
        // §3.2: pegadito 60.000×; la clave del scraper es `pegadito` (5 cifras).
        $this->assertPremio(
            'el-arrejuntado',
            ['tipo' => 'pegadito', 'numero' => '10503'],
            ['triple_a' => '894', 'arrimao' => '1825', 'pegadito' => '10503'],
            60000
        );
    }

    public function test_arrimao_no_paga_con_cifras_distintas(): void
    {
        $premio = $this->liquidar(
            'el-arrejuntado',
            ['tipo' => 'arrimao', 'numero' => '1825'],
            ['triple_a' => '894', 'arrimao' => '1999', 'pegadito' => '10503']
        );

        $this->assertSame(0.0, $premio['premio_bs']);
    }

    // ---------------- Punta / Terminal / Uña por juego ----------------

    public function test_terminal_trio_activo_paga_60x(): void
    {
        // §3.2 trio-activo: terminal 60× (2 últimas cifras del triple).
        $this->assertPremio(
            'trio-activo',
            ['tipo' => 'terminal', 'numero' => '52'],
            ['triple_a' => '452'],
            60
        );
    }

    public function test_punta_trio_activo_paga_60x(): void
    {
        // §3.2 trio-activo: punta 60× (2 primeras cifras del triple).
        $this->assertPremio(
            'trio-activo',
            ['tipo' => 'punta', 'numero' => '45'],
            ['triple_a' => '452'],
            60
        );
    }

    public function test_una_zamorano_paga_5x(): void
    {
        // §3.2 triple-zamorano: uña 5× (última cifra del triple).
        $this->assertPremio(
            'triple-zamorano',
            ['tipo' => 'uña', 'numero' => '2'],
            ['triple_a' => '452'],
            5
        );
    }

    public function test_terminal_no_paga_si_no_coincide(): void
    {
        $premio = $this->liquidar(
            'trio-activo',
            ['tipo' => 'terminal', 'numero' => '53'],
            ['triple_a' => '452']
        );

        $this->assertSame(0.0, $premio['premio_bs']);
    }

    // ---------------- Aproximación (Triple Fácil, 10×) ----------------

    public function test_aproximacion_triple_facil_paga_10x_dentro_de_mas_menos_uno(): void
    {
        // §3.2 triple-facil: aproximacion 10× (terminal ±1; terminal 52 → 51/52/53).
        $this->assertPremio(
            'triple-facil',
            ['tipo' => 'aproximacion', 'numero' => '51'],
            ['triple_a' => '452'],
            10
        );
    }

    public function test_aproximacion_no_paga_fuera_del_margen(): void
    {
        $premio = $this->liquidar(
            'triple-facil',
            ['tipo' => 'aproximacion', 'numero' => '49'],
            ['triple_a' => '452']
        );

        $this->assertSame(0.0, $premio['premio_bs']);
    }

    // ---------------- Terminal+Zodiacal (signo_terminal) ----------------

    public function test_terminal_zodiacal_zulia_paga_600x(): void
    {
        // §3.2 triple-zulia: signo_terminal 600× (2 últimas del triple con
        // signo + signo acertado).
        $this->assertPremio(
            'triple-zulia',
            ['tipo' => 'signo_terminal', 'numero' => '59', 'signo' => 'LEO'],
            ['triple_a' => '111', 'triple_b' => '222', 'triple_c' => '259', 'signo' => 'LEO'],
            600
        );
    }

    public function test_terminal_zodiacal_tachira_paga_500x_por_fallback_a_base(): void
    {
        // §3.2 triple-tachira NO configura `signo_terminal` (solo terminal 50×
        // y signo_triple 5.000×): el motor cae al base 500× del reglamento
        // (REQ11 menciona Terminal+Zodiacal Táchira; sin valor propio en el
        // catálogo, el fallback `?? base` del engine resuelve 500×).
        $this->assertPremio(
            'triple-tachira',
            ['tipo' => 'signo_terminal', 'numero' => '59', 'signo' => 'LEO'],
            ['triple_a' => '111', 'triple_b' => '222', 'triple_c' => '259', 'signo' => 'LEO'],
            500
        );
    }

    public function test_terminal_zodiacal_no_paga_con_signo_distinto(): void
    {
        $premio = $this->liquidar(
            'triple-zulia',
            ['tipo' => 'signo_terminal', 'numero' => '59', 'signo' => 'ARI'],
            ['triple_a' => '111', 'triple_b' => '222', 'triple_c' => '259', 'signo' => 'LEO']
        );

        $this->assertSame(0.0, $premio['premio_bs']);
    }

    // ---------------- Dupleta FUERA de alcance (D8/REQ11) ----------------

    public function test_dupleta_rechazada_en_premio_posible(): void
    {
        // REQ11 escenario: modelar una Dupleta (2 animalitos, 2 sorteos) con un
        // solo monto es rechazado: la modalidad no existe en el catálogo y el
        // motor devuelve 0 (el front la modela como apuestas independientes).
        $juego = $this->juegoCon('cazaloton');

        $premio = (new PremiosEngine)->premioPosible(
            $juego,
            [
                'modalidad' => 'dupleta',
                'selecciones' => [
                    ['animal' => 'perro', 'sorteo_hora' => '10:00'],
                    ['animal' => 'gato', 'sorteo_hora' => '11:00'],
                ],
            ],
            10.0,
            0.0
        );

        $this->assertSame(0.0, $premio['premio_bs']);
        $this->assertSame(0.0, $premio['premio_usd']);
    }

    public function test_dupleta_rechazada_en_liquidacion(): void
    {
        // La Dupleta tampoco se liquida: 2 selecciones no es tripleta (3) y el
        // shape sin animal simple no coincide → 0.
        $premio = $this->liquidar(
            'cazaloton',
            [
                'modalidad' => 'dupleta',
                'selecciones' => [
                    ['animal' => 'perro', 'sorteo_hora' => '10:00'],
                    ['animal' => 'gato', 'sorteo_hora' => '11:00'],
                ],
            ],
            ['figuras' => [['animal' => 'Perro'], ['animal' => 'Gato']]]
        );

        $this->assertSame(0.0, $premio['premio_bs']);
        $this->assertSame(0.0, $premio['premio_usd']);
    }
}
