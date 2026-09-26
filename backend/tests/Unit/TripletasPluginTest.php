<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Tripletas;
use App\Services\JuegoPluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adaptador de FORMA del plugin Tripletas (D1/C, design §3.3).
 *
 * REQ5/N3: la tripleta paga SOLO si el número acertado está en el tipo
 * apostado (triple_a contra triple_a, nunca contra triple_b). REQ4/N2: el
 * signo se acepta como LABEL ("Escorpio") o SIGLA ("ESC"), normalizado en
 * validación y liquidación. N9: padding a 3 cifras ("52" ≡ "052"). El
 * multiplicador lo decide PremiosEngine desde config.premios (REQ1).
 */
class TripletasPluginTest extends TestCase
{
    use RefreshDatabase;

    protected Tripletas $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plugin = new Tripletas;
    }

    private function resultado(array $numeros): array
    {
        return ['numeros_ganadores' => $numeros];
    }

    // ---------------- evaluarAcierto(): tipo estricto (REQ5/N3) ----------------

    public function test_evaluar_acierto_no_paga_numero_en_tipo_distinto_al_apostado()
    {
        $apuesta = ['combinacion' => ['tipo' => 'triple_a', 'numero' => '452'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto = $this->plugin->evaluarAcierto($apuesta, $this->resultado([
            'triple_a' => '111', 'triple_b' => '452', 'triple_c' => '333', 'signo' => 'ARI',
        ]));

        $this->assertFalse($acierto['coincide']);
    }

    public function test_evaluar_acierto_coincide_en_el_tipo_apostado()
    {
        $apuesta = ['combinacion' => ['tipo' => 'triple_b', 'numero' => '452'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto = $this->plugin->evaluarAcierto($apuesta, $this->resultado([
            'triple_a' => '111', 'triple_b' => '452', 'triple_c' => '333', 'signo' => 'ARI',
        ]));

        $this->assertTrue($acierto['coincide']);
        // F2: el triple seco emite la clave del TIPO apostado (el-arrejuntado
        // `triple_b` 600×, §3.2); en el resto de juegos el engine cae al base.
        $this->assertSame('triple_b', $acierto['clave']);
    }

    public function test_evaluar_acierto_compara_con_padding_a_3_cifras()
    {
        $apuesta = ['combinacion' => ['tipo' => 'triple_a', 'numero' => '52'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto = $this->plugin->evaluarAcierto($apuesta, $this->resultado([
            'triple_a' => '052', 'triple_b' => '222', 'triple_c' => '333', 'signo' => 'ARI',
        ]));

        $this->assertTrue($acierto['coincide']);
    }

    // ---------------- evaluarAcierto(): signo label/sigla (REQ4/N2) ----------------

    public function test_evaluar_acierto_triple_c_acepta_signo_label_contra_sigla_del_resultado()
    {
        $apuesta = ['combinacion' => ['tipo' => 'triple_c', 'numero' => '452', 'signo' => 'Escorpio'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto = $this->plugin->evaluarAcierto($apuesta, $this->resultado([
            'triple_a' => '111', 'triple_b' => '222', 'triple_c' => '452', 'signo' => 'ESC',
        ]));

        $this->assertTrue($acierto['coincide']);
        $this->assertSame('signo_triple', $acierto['clave']);
    }

    public function test_evaluar_acierto_triple_c_acepta_signo_sigla_contra_label_del_resultado()
    {
        $apuesta = ['combinacion' => ['tipo' => 'triple_c', 'numero' => '452', 'signo' => 'GEM'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto = $this->plugin->evaluarAcierto($apuesta, $this->resultado([
            'triple_a' => '111', 'triple_b' => '222', 'triple_c' => '452', 'signo' => 'Géminis',
        ]));

        $this->assertTrue($acierto['coincide']);
    }

    public function test_evaluar_acierto_triple_c_no_coincide_si_el_signo_no_es_el_apostado()
    {
        $apuesta = ['combinacion' => ['tipo' => 'triple_c', 'numero' => '452', 'signo' => 'Aries'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto = $this->plugin->evaluarAcierto($apuesta, $this->resultado([
            'triple_a' => '111', 'triple_b' => '222', 'triple_c' => '452', 'signo' => 'ESC',
        ]));

        $this->assertFalse($acierto['coincide']);
    }

    public function test_evaluar_acierto_no_coincide_sin_tipo_valido()
    {
        $apuesta = ['combinacion' => ['tipo' => 'dupleta', 'numero' => '452'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto = $this->plugin->evaluarAcierto($apuesta, $this->resultado([
            'triple_a' => '111', 'triple_b' => '222', 'triple_c' => '452', 'signo' => 'ESC',
        ]));

        $this->assertFalse($acierto['coincide']);
    }

    // ---------------- modalidadDe(): vocabulario canónico §3.1 ----------------

    public function test_modalidad_de_deriva_las_claves_de_los_triples()
    {
        // F2: el triple seco deriva la clave del TIPO apostado (no 'base'):
        // el-arrejuntado configura `triple_a`/`triple_b` a 600× (§3.2); en los
        // demás juegos el motor cae al base por fallback (`?? base`).
        $this->assertSame('triple_a', $this->plugin->modalidadDe(['tipo' => 'triple_a', 'numero' => '452']));
        $this->assertSame('triple_b', $this->plugin->modalidadDe(['tipo' => 'triple_b', 'numero' => '452']));
        $this->assertSame('signo_triple', $this->plugin->modalidadDe(['tipo' => 'triple_c', 'numero' => '452', 'signo' => 'ESC']));
    }

    public function test_modalidad_de_deriva_las_posiciones_del_triple()
    {
        // §3.1: terminal (2 últimas), punta (2 primeras), uña (última) y
        // aproximación (terminal ±1).
        $this->assertSame('terminal', $this->plugin->modalidadDe(['tipo' => 'terminal', 'numero' => '52']));
        $this->assertSame('punta', $this->plugin->modalidadDe(['tipo' => 'punta', 'numero' => '45']));
        $this->assertSame('uña', $this->plugin->modalidadDe(['tipo' => 'uña', 'numero' => '2']));
        $this->assertSame('aproximacion', $this->plugin->modalidadDe(['tipo' => 'aproximacion', 'numero' => '51']));
    }

    public function test_modalidad_de_deriva_las_claves_con_signo()
    {
        // §3.1: signo_terminal (terminal + signo), signo_uña (uña + signo) y
        // signo_solo (signo sin número, 6× en Triple Chance).
        $this->assertSame('signo_terminal', $this->plugin->modalidadDe(['tipo' => 'signo_terminal', 'numero' => '59', 'signo' => 'LEO']));
        $this->assertSame('signo_uña', $this->plugin->modalidadDe(['tipo' => 'signo_uña', 'numero' => '9', 'signo' => 'LEO']));
        $this->assertSame('signo_solo', $this->plugin->modalidadDe(['tipo' => 'signo_solo', 'signo' => 'LEO']));
    }

    public function test_modalidad_de_deriva_arrimao_y_pegadito_del_arrejuntado()
    {
        // §3.1: número exacto de 4 cifras (arrimao 6.000×) y 5 cifras
        // (pegadito 60.000×) — claves que persiste el scraper (§3.2).
        $this->assertSame('arrimao', $this->plugin->modalidadDe(['tipo' => 'arrimao', 'numero' => '1825']));
        $this->assertSame('pegadito', $this->plugin->modalidadDe(['tipo' => 'pegadito', 'numero' => '10503']));
    }

    public function test_modalidad_de_deriva_multi_seleccion_same_draw()
    {
        // §3.4/D8: multi-selección del MISMO sorteo dentro de `selecciones[]`:
        // Par Millonario A+B (dos triples) y Cruzado (dos puntas).
        $this->assertSame('triple_a_b', $this->plugin->modalidadDe([
            'selecciones' => [
                ['tipo' => 'triple_a', 'numero' => '452'],
                ['tipo' => 'triple_b', 'numero' => '310'],
            ],
        ]));
        $this->assertSame('cruzado', $this->plugin->modalidadDe([
            'selecciones' => [
                ['tipo' => 'punta', 'numero' => '45'],
                ['tipo' => 'punta', 'numero' => '14'],
            ],
        ]));
    }

    public function test_modalidad_de_nunca_deriva_dupleta()
    {
        // D8: la Dupleta queda FUERA de alcance; el plugin jamás emite esa
        // clave (el rechazo lo fija el motor en premioPosible y el 0 en
        // evaluarAcierto).
        $this->assertNotSame('dupleta', $this->plugin->modalidadDe(['tipo' => 'dupleta', 'numero' => '452']));
        $this->assertNotSame('dupleta', $this->plugin->modalidadDe([
            'selecciones' => [['animal' => 'perro'], ['animal' => 'gato']],
        ]));
    }

    // ---------------- validarApuesta(): signo label o sigla (REQ4/N2) ----------------

    public function test_validar_apuesta_acepta_signo_label_y_sigla()
    {
        $this->assertTrue($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'triple_c', 'numero' => '452', 'signo' => 'Escorpio'],
        ]));
        $this->assertTrue($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'triple_c', 'numero' => '452', 'signo' => 'GEM'],
        ]));
        $this->assertTrue($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'triple_c', 'numero' => '452', 'signo' => 'géminis'],
        ]));
    }

    public function test_validar_apuesta_rechaza_signo_invalido()
    {
        $this->assertFalse($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'triple_c', 'numero' => '452', 'signo' => 'X'],
        ]));
        $this->assertFalse($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'triple_c', 'numero' => '45', 'signo' => 'ESC'],
        ]));
    }

    // ---------------- Dinero: signo_triple 6000× desde config (REQ4) ----------------

    public function test_calcular_premio_via_manager_engine_paga_signo_triple_con_label()
    {
        $juego = Juego::create([
            'name' => 'Triple Zulia',
            'slug' => 'triple-zulia',
            'type' => 'tripletas',
            'config' => [
                'premios' => [
                    'base' => 600,
                    'modalidades' => ['terminal' => 60, 'signo_triple' => 6000, 'signo_terminal' => 600],
                    'comodines' => [],
                ],
            ],
            'requires_scraper' => true,
            'active' => true,
        ]);
        PluginJuego::create([
            'juego_id' => $juego->id,
            'class_namespace' => Tripletas::class,
            'version' => '1.0.0',
            'active' => true,
        ]);

        $premio = app(JuegoPluginManager::class)->calcularPremio(
            $juego,
            ['combinacion' => ['tipo' => 'triple_c', 'numero' => '452', 'signo' => 'Escorpio'], 'amount_bs' => 10, 'amount_usd' => 0],
            $this->resultado(['triple_a' => '111', 'triple_b' => '222', 'triple_c' => '452', 'signo' => 'ESC'])
        );

        $this->assertEquals(['premio_bs' => 60000.0, 'premio_usd' => 0.0], $premio);
    }

    public function test_calcular_premio_via_manager_engine_no_paga_tipo_distinto()
    {
        $juego = Juego::create([
            'name' => 'Triple Zulia',
            'slug' => 'triple-zulia',
            'type' => 'tripletas',
            'config' => [
                'premios' => [
                    'base' => 600,
                    'modalidades' => ['terminal' => 60, 'signo_triple' => 6000, 'signo_terminal' => 600],
                    'comodines' => [],
                ],
            ],
            'requires_scraper' => true,
            'active' => true,
        ]);
        PluginJuego::create([
            'juego_id' => $juego->id,
            'class_namespace' => Tripletas::class,
            'version' => '1.0.0',
            'active' => true,
        ]);

        // REQ5: apuesta triple_a "452" y el resultado trae "452" en triple_b → perdedora.
        $premio = app(JuegoPluginManager::class)->calcularPremio(
            $juego,
            ['combinacion' => ['tipo' => 'triple_a', 'numero' => '452'], 'amount_bs' => 10, 'amount_usd' => 0],
            $this->resultado(['triple_a' => '111', 'triple_b' => '452', 'triple_c' => '333', 'signo' => 'ARI'])
        );

        $this->assertEquals(['premio_bs' => 0.0, 'premio_usd' => 0.0], $premio);
    }
}
