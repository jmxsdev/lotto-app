<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Contracts\JuegoInterface;
use App\Services\JuegoPluginManager;
use App\Services\PremiosEngine;
use Mockery;
use Tests\TestCase;

class PremiosEngineTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Juego en memoria (sin BD): solo se fijan slug/config/active.
     */
    private function juego(string $slug, array $premios = [], bool $active = true, ?float $premioMultiploLegacy = null): Juego
    {
        $config = [];
        if ($premios !== []) {
            $config['premios'] = $premios;
        }
        if ($premioMultiploLegacy !== null) {
            $config['premio_multiplo'] = $premioMultiploLegacy;
        }

        return new Juego(['slug' => $slug, 'config' => $config, 'active' => $active]);
    }

    /**
     * Plugin stub con `evaluarAcierto` (y `modalidadDe`), como los
     * adaptadores que implementan los plugins desde F1b (design §3.3).
     */
    private function pluginConAcierto(array $acierto, string $modalidadDe = 'base'): JuegoInterface
    {
        return new class($acierto, $modalidadDe) implements JuegoInterface
        {
            public function __construct(
                private array $acierto,
                private string $modalidadDe
            ) {}

            public function validarApuesta(array $data, ?array $opciones = null): bool
            {
                return true;
            }

            public function calcularPremio(array $apuesta, array $resultados): array
            {
                return ['premio_bs' => 0, 'premio_usd' => 0];
            }

            public function obtenerReglas(): array
            {
                return [];
            }

            public function obtenerOpciones(): array
            {
                return [];
            }

            public function obtenerHorarios(): array
            {
                return [];
            }

            public function obtenerModalidades(): array
            {
                return [];
            }

            public function obtenerMultiplicador(): float
            {
                return 1.0;
            }

            public function getValidationRules(): array
            {
                return [];
            }

            public function getValidationMessages(): array
            {
                return [];
            }

            public function evaluarAcierto(array $apuesta, array $resultados): array
            {
                return $this->acierto;
            }

            public function modalidadDe(array $combinacion): string
            {
                return $this->modalidadDe;
            }
        };
    }

    private function motorCon(?JuegoInterface $plugin): PremiosEngine
    {
        $manager = Mockery::mock(JuegoPluginManager::class);
        $manager->shouldReceive('getPlugin')->andReturn($plugin);

        return new PremiosEngine($manager);
    }

    // ---------------- calcular(): base y modalidades ----------------

    public function test_calcular_premio_base_desde_config()
    {
        $juego = $this->juego('monje-millonario', ['base' => 50, 'modalidades' => [], 'comodines' => []]);
        $engine = $this->motorCon($this->pluginConAcierto(['coincide' => true, 'clave' => 'base', 'meta' => []]));

        $premio = $engine->calcular($juego, ['amount_bs' => 10, 'amount_usd' => 0], []);

        $this->assertEquals(['premio_bs' => 500.0, 'premio_usd' => 0.0], $premio);
    }

    public function test_calcular_premio_en_usd()
    {
        $juego = $this->juego('monje-millonario', ['base' => 50, 'modalidades' => [], 'comodines' => []]);
        $engine = $this->motorCon($this->pluginConAcierto(['coincide' => true, 'clave' => 'base', 'meta' => []]));

        $premio = $engine->calcular($juego, ['amount_bs' => 0, 'amount_usd' => 10], []);

        $this->assertEquals(['premio_bs' => 0.0, 'premio_usd' => 500.0], $premio);
    }

    public function test_calcular_modalidad_especifica_gana_a_base()
    {
        // Triple Chance: base 600, pero solo A/B paga 150 (H23 reglamento).
        $juego = $this->juego('triple-chance', [
            'base' => 600,
            'modalidades' => ['solo_a_b' => 150],
            'comodines' => [],
        ]);
        $engine = $this->motorCon($this->pluginConAcierto(['coincide' => true, 'clave' => 'solo_a_b', 'meta' => []]));

        $premio = $engine->calcular($juego, ['amount_bs' => 10, 'amount_usd' => 0], []);

        $this->assertEquals(['premio_bs' => 1500.0, 'premio_usd' => 0.0], $premio);
    }

    public function test_calcular_fallback_legacy_premio_multiplo_solo_para_base()
    {
        // Transicional (D2): sin config.premios aún, el base cae a premio_multiplo.
        $juego = $this->juego('lotto-activo', [], true, 30.0);
        $engine = $this->motorCon($this->pluginConAcierto(['coincide' => true, 'clave' => 'base', 'meta' => []]));

        $premio = $engine->calcular($juego, ['amount_bs' => 10, 'amount_usd' => 0], []);

        $this->assertEquals(['premio_bs' => 300.0, 'premio_usd' => 0.0], $premio);
    }

    public function test_calcular_no_coincide_devuelve_cero()
    {
        $juego = $this->juego('monje-millonario', ['base' => 50, 'modalidades' => [], 'comodines' => []]);
        $engine = $this->motorCon($this->pluginConAcierto(['coincide' => false, 'clave' => 'base', 'meta' => []]));

        $premio = $engine->calcular($juego, ['amount_bs' => 10, 'amount_usd' => 0], []);

        $this->assertEquals(['premio_bs' => 0.0, 'premio_usd' => 0.0], $premio);
    }

    // ---------------- calcular(): redondeo y estados ----------------

    public function test_calcular_redondea_premio_a_2_decimales()
    {
        // REQ8: premio calculado en 1.23456 se persiste como 1.23.
        $juego = $this->juego('terminal-activo', ['base' => 1, 'modalidades' => [], 'comodines' => []]);
        $engine = $this->motorCon($this->pluginConAcierto(['coincide' => true, 'clave' => 'base', 'meta' => []]));

        $premio = $engine->calcular($juego, ['amount_bs' => 1.23456, 'amount_usd' => 0], []);

        $this->assertEquals(1.23, $premio['premio_bs']);
    }

    public function test_juego_inactivo_no_liquida()
    {
        // REQ7: la-ricachona active=false no se liquida aunque coincida.
        $juego = $this->juego('la-ricachona', [], false);
        $engine = $this->motorCon($this->pluginConAcierto(['coincide' => true, 'clave' => 'base', 'meta' => []]));

        $premio = $engine->calcular($juego, ['amount_bs' => 10, 'amount_usd' => 0], []);

        $this->assertEquals(['premio_bs' => 0.0, 'premio_usd' => 0.0], $premio);
    }

    public function test_sin_plugin_no_liquida()
    {
        $juego = $this->juego('juego-sin-plugin', ['base' => 50, 'modalidades' => [], 'comodines' => []]);
        $engine = $this->motorCon(null);

        $premio = $engine->calcular($juego, ['amount_bs' => 10, 'amount_usd' => 0], []);

        $this->assertEquals(['premio_bs' => 0.0, 'premio_usd' => 0.0], $premio);
    }

    public function test_motor_activa_el_adaptador_evaluar_acierto_del_plugin()
    {
        // F1b: el guard transicional method_exists desaparece; el motor usa el
        // adaptador del plugin directo (JuegoInterface lo garantiza, D1/C).
        $juego = $this->juego('lotto-activo', ['base' => 30, 'modalidades' => [], 'comodines' => []]);
        $engine = $this->motorCon($this->pluginConAcierto(['coincide' => true, 'clave' => 'base', 'meta' => []]));

        $premio = $engine->calcular($juego, ['amount_bs' => 10, 'amount_usd' => 0], []);

        $this->assertEquals(['premio_bs' => 300.0, 'premio_usd' => 0.0], $premio);
    }

    // ---------------- calcular(): comodines ----------------

    public function test_comodin_flag_mega_reemplaza_base()
    {
        // REQ6 MEGA: base 30× → 40× con comodin=true.
        $juego = $this->juego('mega-animal-40', [
            'base' => 30,
            'modalidades' => [],
            'comodines' => ['mega' => ['tipo' => 'flag', 'premio_multiplo' => 40, 'nombre' => 'MEGA']],
        ]);
        $engine = $this->motorCon($this->pluginConAcierto(['coincide' => true, 'clave' => 'base', 'meta' => ['comodines' => ['mega']]]));

        $premio = $engine->calcular($juego, ['amount_bs' => 10, 'amount_usd' => 0], []);

        $this->assertEquals(['premio_bs' => 400.0, 'premio_usd' => 0.0], $premio);
    }

    public function test_comodin_letra_selva_a_y_b_reemplaza_base()
    {
        // REQ6 Selva: comodín A 160×, B 200× sobre base 80×.
        $juego = $this->juego('selva-plus', [
            'base' => 80,
            'modalidades' => [],
            'comodines' => [
                'comodin-a' => ['tipo' => 'letra', 'premio_multiplo' => 160, 'valor' => 'A', 'nombre' => 'Leoncito'],
                'comodin-b' => ['tipo' => 'letra', 'premio_multiplo' => 200, 'valor' => 'B', 'nombre' => 'Selva Plus'],
            ],
        ]);

        $premioA = $this->motorCon($this->pluginConAcierto(['coincide' => true, 'clave' => 'base', 'meta' => ['comodines' => ['comodin-a']]]))
            ->calcular($juego, ['amount_bs' => 10, 'amount_usd' => 0], []);
        $this->assertEquals(['premio_bs' => 1600.0, 'premio_usd' => 0.0], $premioA);

        $premioB = $this->motorCon($this->pluginConAcierto(['coincide' => true, 'clave' => 'base', 'meta' => ['comodines' => ['comodin-b']]]))
            ->calcular($juego, ['amount_bs' => 10, 'amount_usd' => 0], []);
        $this->assertEquals(['premio_bs' => 2000.0, 'premio_usd' => 0.0], $premioB);
    }

    public function test_comodin_numero_guacharito_99_reemplaza_base()
    {
        // REQ6 Guacharito: figura 99 → 150× sobre base 70×.
        $juego = $this->juego('el-guacharito', [
            'base' => 70,
            'modalidades' => [],
            'comodines' => ['guacharito-99' => ['tipo' => 'numero', 'premio_multiplo' => 150, 'numero' => 99, 'nombre' => 'Guacharito']],
        ]);
        $engine = $this->motorCon($this->pluginConAcierto(['coincide' => true, 'clave' => 'base', 'meta' => ['comodines' => ['guacharito-99']]]));

        $premio = $engine->calcular($juego, ['amount_bs' => 10, 'amount_usd' => 0], []);

        $this->assertEquals(['premio_bs' => 1500.0, 'premio_usd' => 0.0], $premio);
    }

    public function test_comodin_numero_guacharo_75_reemplaza_base()
    {
        $juego = $this->juego('guacharo-activo', [
            'base' => 60,
            'modalidades' => [],
            'comodines' => ['guacharo-75' => ['tipo' => 'numero', 'premio_multiplo' => 120, 'numero' => 75, 'nombre' => 'Guácharo']],
        ]);
        $engine = $this->motorCon($this->pluginConAcierto(['coincide' => true, 'clave' => 'base', 'meta' => ['comodines' => ['guacharo-75']]]));

        $premio = $engine->calcular($juego, ['amount_bs' => 10, 'amount_usd' => 0], []);

        $this->assertEquals(['premio_bs' => 1200.0, 'premio_usd' => 0.0], $premio);
    }

    public function test_palabra_patronus_acumula_sobre_figura_normal()
    {
        // REQ6: figura normal 50× + palabra PATRONUS = 70× → 10 × 70 = Bs. 700.
        $juego = $this->juego('monje-millonario', [
            'base' => 50,
            'modalidades' => [],
            'comodines' => ['patronus-palabra' => ['tipo' => 'palabra', 'premio_multiplo' => 20, 'acumulativo' => true, 'nombre' => 'PATRONUS']],
        ]);
        $engine = $this->motorCon($this->pluginConAcierto(['coincide' => true, 'clave' => 'base', 'meta' => ['comodines' => ['patronus-palabra']]]));

        $premio = $engine->calcular($juego, ['amount_bs' => 10, 'amount_usd' => 0], []);

        $this->assertEquals(['premio_bs' => 700.0, 'premio_usd' => 0.0], $premio);
    }

    public function test_patronus_75_con_palabra_acumula_140()
    {
        // REQ6 (escenario del spec): Patronus 75 (120×) + palabra (+20×) = 140×
        // → 10 × 140 = Bs. 1.400.
        $juego = $this->juego('monje-millonario', [
            'base' => 50,
            'modalidades' => [],
            'comodines' => [
                'patronus-75' => ['tipo' => 'numero', 'premio_multiplo' => 120, 'numero' => 75, 'nombre' => 'Patronus'],
                'patronus-palabra' => ['tipo' => 'palabra', 'premio_multiplo' => 20, 'acumulativo' => true, 'nombre' => 'PATRONUS'],
            ],
        ]);
        $engine = $this->motorCon($this->pluginConAcierto([
            'coincide' => true,
            'clave' => 'base',
            'meta' => ['comodines' => ['patronus-75', 'patronus-palabra']],
        ]));

        $premio = $engine->calcular($juego, ['amount_bs' => 10, 'amount_usd' => 0], []);

        $this->assertEquals(['premio_bs' => 1400.0, 'premio_usd' => 0.0], $premio);
    }

    // ---------------- premioPosible() ----------------

    public function test_premio_posible_usa_base_desde_config()
    {
        // REQ12: monje 10 Bs × 50 = 500 Bs al crear la apuesta.
        $juego = $this->juego('monje-millonario', ['base' => 50, 'modalidades' => [], 'comodines' => []]);
        $engine = $this->motorCon($this->pluginConAcierto(['coincide' => true, 'clave' => 'base', 'meta' => []]));

        $premio = $engine->premioPosible($juego, ['animal' => 'perro'], 10.0, 0.0);

        $this->assertEquals(['premio_bs' => 500.0, 'premio_usd' => 0.0], $premio);
    }

    public function test_premio_posible_usa_modalidad_de_combinacion()
    {
        // Cazalotón: base 30×, tripleta 200× → la modalidad declarada gana.
        $juego = $this->juego('cazaloton', ['base' => 30, 'modalidades' => ['tripleta' => 200], 'comodines' => []]);
        $engine = $this->motorCon($this->pluginConAcierto(['coincide' => true, 'clave' => 'base', 'meta' => []]));

        $premio = $engine->premioPosible($juego, ['modalidad' => 'tripleta'], 10.0, 0.0);

        $this->assertEquals(['premio_bs' => 2000.0, 'premio_usd' => 0.0], $premio);
    }

    public function test_premio_posible_deriva_modalidad_del_plugin()
    {
        // D9: sin clave modalidad en combinacion, el plugin la deriva.
        $juego = $this->juego('cazaloton', ['base' => 30, 'modalidades' => ['tripleta' => 200], 'comodines' => []]);
        $engine = $this->motorCon($this->pluginConAcierto(['coincide' => true, 'clave' => 'base', 'meta' => []], 'tripleta'));

        $premio = $engine->premioPosible($juego, ['animal' => 'perro'], 10.0, 0.0);

        $this->assertEquals(['premio_bs' => 2000.0, 'premio_usd' => 0.0], $premio);
    }

    public function test_premio_posible_juego_inactivo_devuelve_cero()
    {
        $juego = $this->juego('la-ricachona', [], false);
        $engine = $this->motorCon($this->pluginConAcierto(['coincide' => true, 'clave' => 'base', 'meta' => []]));

        $premio = $engine->premioPosible($juego, ['animal' => 'perro'], 10.0, 0.0);

        $this->assertEquals(['premio_bs' => 0.0, 'premio_usd' => 0.0], $premio);
    }

    // ---------------- reglas() ----------------

    public function test_reglas_devuelve_estructura_premios_del_juego()
    {
        $premios = ['base' => 600, 'modalidades' => ['terminal' => 60], 'comodines' => []];
        $juego = $this->juego('trio-activo', $premios);
        $engine = $this->motorCon(null);

        $this->assertSame($premios, $engine->reglas($juego));
    }

    public function test_reglas_vacio_cuando_el_juego_no_tiene_premios()
    {
        $juego = $this->juego('la-ricachona', []);
        $engine = $this->motorCon(null);

        $this->assertSame([], $engine->reglas($juego));
    }
}
