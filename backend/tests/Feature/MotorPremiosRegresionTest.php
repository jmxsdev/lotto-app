<?php

namespace Tests\Feature;

use App\Models\Juego;
use App\Services\JuegoPluginManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F1d 1.17 — Regresión por juego (REQ16, design §8): mínimo un caso por cada
 * uno de los 21 juegos + comodines, pasando por el camino REAL de producción
 * (manager → PremiosEngine → plugin evaluarAcierto) contra la BD sembrada
 * con `config.premios` del catálogo oficial.
 *
 * Cubre: multiplicador base del reglamento, acentos (H13/N10), terminal (N1),
 * signos label/sigla (N2), tipo estricto (REQ5), comodines MEGA/Selva/
 * Guacharito/Guácharo/Patronus (incluido 75 + palabra = 140×) y el juego
 * inactivo (la-ricachona → 0).
 */
class MotorPremiosRegresionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Liquida una apuesta por el camino real (manager → engine).
     *
     * @param  array<string, mixed>  $combinacion
     * @param  array<string, mixed>  $numerosGanadores
     * @return array{premio_bs: float, premio_usd: float}
     */
    private function liquidar(string $slug, array $combinacion, array $numerosGanadores, float $montoBs = 10.0): array
    {
        $juego = Juego::where('slug', $slug)->firstOrFail();

        return app(JuegoPluginManager::class)->calcularPremio(
            $juego,
            ['combinacion' => $combinacion, 'amount_bs' => $montoBs, 'amount_usd' => 0],
            ['numeros_ganadores' => $numerosGanadores]
        );
    }

    /**
     * @param  array<string, mixed>  $combinacion
     * @param  array<string, mixed>  $numerosGanadores
     */
    private function assertPremio(string $slug, array $combinacion, array $numerosGanadores, float $multiplicador, float $montoBs = 10.0): void
    {
        $premio = $this->liquidar($slug, $combinacion, $numerosGanadores, $montoBs);
        $esperado = round($montoBs * $multiplicador, 2);

        $this->assertSame(
            $esperado,
            $premio['premio_bs'],
            "[{$slug}] 10 Bs × {$multiplicador}× = {$esperado} (motor)."
        );
        $this->assertSame(0.0, $premio['premio_usd'], "[{$slug}] sin monto USD.");
    }

    // ---------------- Familia Lotto Activo (acentos H13/N10) ----------------

    public function test_lotto_activo_acento_delfin_30x(): void
    {
        // REQ2: apuesta "Delfín" vs resultado "Delfin" → base 30×.
        $this->assertPremio(
            'lotto-activo',
            ['animal' => 'Delfín', 'numero' => 0],
            ['numero' => 0, 'nombre_animal' => 'Delfin'],
            30
        );
    }

    public function test_lotto_activo_rd_base_30x(): void
    {
        $this->assertPremio(
            'lotto-activo-rd',
            ['animal' => 'Perro', 'numero' => 27],
            ['numero' => 27, 'nombre_animal' => 'Perro'],
            30
        );
    }

    public function test_lotto_activo_rep_dom_base_30x(): void
    {
        $this->assertPremio(
            'lotto-activo-rep-dom',
            ['animal' => 'Gato', 'numero' => 11],
            ['numero' => 11, 'nombre_animal' => 'Gato'],
            30
        );
    }

    // ---------------- Terminal (N1) ----------------

    public function test_terminal_activo_numero_37_60x(): void
    {
        // REQ3/N1: paga contra la clave `numero` del resultado, 60×.
        $this->assertPremio(
            'terminal-activo',
            ['numero' => 37],
            ['numero' => 37],
            60
        );
    }

    public function test_terminal_activo_numero_con_padding_60x(): void
    {
        // N9: "7" ≡ "07" por padding a 2 cifras.
        $this->assertPremio(
            'terminal-activo',
            ['numero' => 7],
            ['numero' => '07'],
            60
        );
    }

    // ---------------- Monje Millonario (Patronus 75 + palabra = 140×) ----------------

    public function test_monje_figura_42_base_50x(): void
    {
        $this->assertPremio(
            'monje-millonario',
            ['animal' => 'Tucán', 'numero' => 42],
            ['numero' => 42, 'nombre_animal' => 'Tucán'],
            50
        );
    }

    public function test_monje_figura_42_con_palabra_70x(): void
    {
        // REQ6: figura normal 50× + palabra PATRONUS (+20 acumulativo) = 70×.
        $this->assertPremio(
            'monje-millonario',
            ['animal' => 'Tucán', 'numero' => 42],
            ['numero' => 42, 'nombre_animal' => 'Tucán', 'patronus' => true],
            70
        );
    }

    public function test_monje_patronus_75_120x(): void
    {
        $this->assertPremio(
            'monje-millonario',
            ['animal' => 'Patronus', 'numero' => 75],
            ['numero' => 75, 'nombre_animal' => 'Patronus'],
            120
        );
    }

    public function test_monje_patronus_75_con_palabra_140x(): void
    {
        // REQ6 escenario del spec: 120 (Patronus) + 20 (palabra) = 140×.
        $this->assertPremio(
            'monje-millonario',
            ['animal' => 'Patronus', 'numero' => 75],
            ['numero' => 75, 'nombre_animal' => 'Patronus', 'patronus' => true],
            140
        );
    }

    // ---------------- Tripletas: base, tipo estricto (REQ5), signos (N2) ----------------

    public function test_trio_activo_triple_a_600x(): void
    {
        $this->assertPremio(
            'trio-activo',
            ['tipo' => 'triple_a', 'numero' => '452'],
            ['triple_a' => '452'],
            600
        );
    }

    public function test_triple_zulia_tipo_estricto_no_paga(): void
    {
        // REQ5: apuesta triple_a, el número está en triple_b → 0.
        $premio = $this->liquidar(
            'triple-zulia',
            ['tipo' => 'triple_a', 'numero' => '452'],
            ['triple_a' => '146', 'triple_b' => '452', 'triple_c' => '682']
        );
        $this->assertSame(0.0, $premio['premio_bs']);
    }

    public function test_triple_caliente_triple_c_signo_label_6000x(): void
    {
        // REQ4/N2: signo como LABEL ("Escorpio") contra sigla del resultado ("ESC").
        $this->assertPremio(
            'triple-caliente',
            ['tipo' => 'triple_c', 'numero' => '682', 'signo' => 'Escorpio'],
            ['triple_c' => '682', 'signo' => 'ESC'],
            6000
        );
    }

    public function test_triple_chance_triple_c_signo_6000x(): void
    {
        $this->assertPremio(
            'triple-chance',
            ['tipo' => 'triple_c', 'numero' => '682', 'signo' => 'SAG'],
            ['triple_c' => '682', 'signo' => 'SAG'],
            6000
        );
    }

    public function test_triple_tachira_base_500x(): void
    {
        $this->assertPremio(
            'triple-tachira',
            ['tipo' => 'triple_a', 'numero' => '123'],
            ['triple_a' => '123'],
            500
        );
    }

    public function test_triple_facil_base_700x(): void
    {
        $this->assertPremio(
            'triple-facil',
            ['tipo' => 'triple_a', 'numero' => '123'],
            ['triple_a' => '123'],
            700
        );
    }

    public function test_triple_zamorano_base_600x(): void
    {
        $this->assertPremio(
            'triple-zamorano',
            ['tipo' => 'triple_a', 'numero' => '123'],
            ['triple_a' => '123'],
            600
        );
    }

    public function test_el_arrejuntado_triple_a_base_40x(): void
    {
        // §3.2: base 40× (el plugin de el-arrejuntado es Tripletas; la clave
        // evaluable hoy es triple_a → base).
        $this->assertPremio(
            'el-arrejuntado',
            ['tipo' => 'triple_a', 'numero' => '894'],
            ['triple_a' => '894'],
            40
        );
    }

    // ---------------- Animalitos restantes ----------------

    public function test_cazaloton_base_30x(): void
    {
        $this->assertPremio(
            'cazaloton',
            ['animal' => 'Perro', 'numero' => 27],
            ['numero' => 27, 'nombre_animal' => 'Perro'],
            30
        );
    }

    public function test_loto_chaima_base_40x(): void
    {
        // Zoo propio (57 figuras): Delfín está en el zoo → 40×.
        $this->assertPremio(
            'loto-chaima',
            ['animal' => 'Delfín', 'numero' => 0],
            ['numero' => 0, 'nombre_animal' => 'Delfin'],
            40
        );
    }

    public function test_la_granjita_base_30x(): void
    {
        $this->assertPremio(
            'la-granjita',
            ['animal' => 'Perro', 'numero' => 27],
            ['numero' => 27, 'nombre_animal' => 'Perro'],
            30
        );
    }

    // ---------------- Comodines ----------------

    public function test_el_guacharito_figura_99_150x(): void
    {
        $this->assertPremio(
            'el-guacharito',
            ['animal' => 'Guacharito', 'numero' => 99],
            ['numero' => 99, 'nombre_animal' => 'Guacharito'],
            150
        );
    }

    public function test_el_guacharito_figura_normal_70x(): void
    {
        $this->assertPremio(
            'el-guacharito',
            ['animal' => 'Gavilán', 'numero' => 64],
            ['numero' => 64, 'nombre_animal' => 'Gavilán'],
            70
        );
    }

    public function test_guacharo_activo_figura_75_120x(): void
    {
        $this->assertPremio(
            'guacharo-activo',
            ['animal' => 'Guacharo', 'numero' => 75],
            ['numero' => 75, 'nombre_animal' => 'Guacharo'],
            120
        );
    }

    public function test_guacharo_activo_figura_normal_60x(): void
    {
        $this->assertPremio(
            'guacharo-activo',
            ['animal' => 'Iguana', 'numero' => 24],
            ['numero' => 24, 'nombre_animal' => 'Iguana'],
            60
        );
    }

    public function test_mega_animal_40_comodin_true_40x(): void
    {
        $this->assertPremio(
            'mega-animal-40',
            ['animal' => 'Águila', 'numero' => 9],
            ['numero' => 9, 'nombre_animal' => 'Águila', 'comodin' => true],
            40
        );
    }

    public function test_mega_animal_40_comodin_false_base_30x(): void
    {
        $this->assertPremio(
            'mega-animal-40',
            ['animal' => 'Águila', 'numero' => 9],
            ['numero' => 9, 'nombre_animal' => 'Águila', 'comodin' => false],
            30
        );
    }

    public function test_selva_plus_comodin_a_160x(): void
    {
        $this->assertPremio(
            'selva-plus',
            ['animal' => 'Cabra', 'numero' => 87],
            ['numero' => 87, 'nombre_animal' => 'Cabra', 'comodin' => 'A'],
            160
        );
    }

    public function test_selva_plus_comodin_b_200x(): void
    {
        $this->assertPremio(
            'selva-plus',
            ['animal' => 'Cabra', 'numero' => 87],
            ['numero' => 87, 'nombre_animal' => 'Cabra', 'comodin' => 'B'],
            200
        );
    }

    public function test_selva_plus_normal_80x(): void
    {
        $this->assertPremio(
            'selva-plus',
            ['animal' => 'Cabra', 'numero' => 87],
            ['numero' => 87, 'nombre_animal' => 'Cabra'],
            80
        );
    }

    // ---------------- Juego inactivo (REQ7) ----------------

    public function test_la_ricachona_inactiva_no_liquida(): void
    {
        // REQ7: active=false y sin premios → el motor no liquida (0), aunque
        // el número coincida.
        $premio = $this->liquidar(
            'la-ricachona',
            ['tipo' => 'triple_a', 'numero' => '123'],
            ['triple_a' => '123']
        );
        $this->assertSame(0.0, $premio['premio_bs']);
        $this->assertSame(0.0, $premio['premio_usd']);
    }
}
