<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Tripletas;
use App\Services\JuegoPluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
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

    /**
     * Catálogo mínimo de premios por juego (forma de `config.premios`).
     *
     * @param  array<string, int>  $modalidades
     * @param  array<int, string>  $posicionTriples
     * @return array<string, mixed>
     */
    private function premios(array $modalidades, array $posicionTriples = []): array
    {
        return [
            'base' => 600,
            'modalidades' => $modalidades,
            'posicion_triples' => $posicionTriples,
            'comodines' => [],
        ];
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

    // ---------------- evaluarAcierto(): posicional atada al triple vendido (WU-2) ----------------

    public function test_evaluar_acierto_terminal_paga_solo_en_el_triple_vendido()
    {
        $resultado = $this->resultado([
            'triple_a' => '962', 'triple_b' => '123', 'triple_c' => '408', 'signo' => 'ARI',
        ]);

        // 62 es la terminal del triple A (962): paga.
        $acierto = $this->plugin->evaluarAcierto(
            ['combinacion' => ['tipo' => 'terminal', 'numero' => '62', 'triple' => 'a']],
            $resultado
        );
        $this->assertTrue($acierto['coincide']);
        $this->assertSame('terminal', $acierto['clave']);

        // 23 es la terminal del triple B (123): no paga en el slot A.
        $acierto = $this->plugin->evaluarAcierto(
            ['combinacion' => ['tipo' => 'terminal', 'numero' => '23', 'triple' => 'a']],
            $resultado
        );
        $this->assertFalse($acierto['coincide']);
    }

    public function test_evaluar_acierto_posicional_sin_triple_no_paga()
    {
        $acierto = $this->plugin->evaluarAcierto(
            ['combinacion' => ['tipo' => 'terminal', 'numero' => '62']],
            $this->resultado(['triple_a' => '962', 'triple_b' => '123', 'triple_c' => '408'])
        );

        $this->assertFalse($acierto['coincide'], 'Sin `triple` no hay default: no paga.');
        $this->assertSame('terminal', $acierto['clave']);
    }

    public function test_evaluar_acierto_una_paga_solo_en_el_triple_vendido()
    {
        // Zamorano (uña: última cifra de A o C). A=962 → uña 2; C=538 → uña 8.
        $resultado = $this->resultado(['triple_a' => '962', 'triple_c' => '538', 'signo' => 'ARI']);

        $acierto = $this->plugin->evaluarAcierto(
            ['combinacion' => ['tipo' => 'uña', 'numero' => '2', 'triple' => 'a']],
            $resultado
        );
        $this->assertTrue($acierto['coincide']);

        $acierto = $this->plugin->evaluarAcierto(
            ['combinacion' => ['tipo' => 'uña', 'numero' => '2', 'triple' => 'c']],
            $resultado
        );
        $this->assertFalse($acierto['coincide'], '2 es la uña del triple A, no del C.');
    }

    public function test_evaluar_acierto_punta_paga_solo_en_el_triple_vendido()
    {
        // Chance (punta: 2 primeras de A o B). A=962 → 96; B=123 → 12.
        $resultado = $this->resultado(['triple_a' => '962', 'triple_b' => '123', 'triple_c' => '408']);

        $acierto = $this->plugin->evaluarAcierto(
            ['combinacion' => ['tipo' => 'punta', 'numero' => '12', 'triple' => 'b']],
            $resultado
        );
        $this->assertTrue($acierto['coincide']);

        $acierto = $this->plugin->evaluarAcierto(
            ['combinacion' => ['tipo' => 'punta', 'numero' => '12', 'triple' => 'a']],
            $resultado
        );
        $this->assertFalse($acierto['coincide'], '12 es la punta del triple B, no la del A.');
    }

    public function test_evaluar_acierto_signo_terminal_sigue_liquidando_contra_triple_c()
    {
        // El 59 es la terminal del triple A Y del C en el primer resultado:
        // paga. En el segundo solo existe en A (C=408 → terminal 08): no paga,
        // prueba de que la referencia es `triple_c` y no cualquiera de A/B/C.
        $acierto = $this->plugin->evaluarAcierto(
            ['combinacion' => ['tipo' => 'signo_terminal', 'numero' => '59', 'signo' => 'LEO']],
            $this->resultado(['triple_a' => '159', 'triple_b' => '222', 'triple_c' => '259', 'signo' => 'LEO'])
        );
        $this->assertTrue($acierto['coincide']);

        $acierto = $this->plugin->evaluarAcierto(
            ['combinacion' => ['tipo' => 'signo_terminal', 'numero' => '59', 'signo' => 'LEO']],
            $this->resultado(['triple_a' => '159', 'triple_b' => '222', 'triple_c' => '408', 'signo' => 'LEO'])
        );
        $this->assertFalse($acierto['coincide'], 'signo_terminal compara contra triple_c, no contra triple_a.');
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

    // ---------------- validarApuesta(): whitelist total (spec motor-premios, WU-1) ----------------

    public function test_validar_apuesta_acepta_triple_a_y_triple_b_ausentes_de_modalidades()
    {
        // El producto base (triple_a/triple_b) no vive en `premios.modalidades`
        // de casi ningún juego: el whitelist debe aceptarlo siempre.
        $premios = $this->premios(['terminal' => 60, 'signo_triple' => 6000, 'signo_terminal' => 600]);

        $this->assertTrue($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'triple_a', 'numero' => '452'],
        ], null, $premios));

        $this->assertTrue($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'triple_b', 'numero' => '731'],
        ], null, $premios));
    }

    public function test_validar_apuesta_rechaza_la_clave_base_y_los_tipos_desconocidos()
    {
        $premios = $this->premios(['terminal' => 60]);

        $this->assertFalse($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'base', 'numero' => '452'],
        ], null, $premios));

        $this->assertFalse($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'dupleta', 'numero' => '452'],
        ], null, $premios));

        // Triangulación: un tipo REAL pero ausente del catálogo del juego
        // tampoco se vende (uña no está configurada en este juego).
        $this->assertFalse($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'uña', 'numero' => '2'],
        ], null, $premios));
    }

    public function test_validar_apuesta_rechaza_modalidad_declarada_ausente_del_catalogo()
    {
        $premios = $this->premios(['cruzado' => 3000, 'cruzado_10' => 10]);
        $selecciones = [
            ['tipo' => 'punta', 'numero' => '75'],
            ['tipo' => 'punta', 'numero' => '14'],
        ];

        $this->assertTrue($this->plugin->validarApuesta([
            'combinacion' => ['modalidad' => 'cruzado', 'selecciones' => $selecciones],
        ], null, $premios));

        $this->assertFalse($this->plugin->validarApuesta([
            'combinacion' => ['modalidad' => 'dupleta', 'selecciones' => $selecciones],
        ], null, $premios));
    }

    public function test_validar_apuesta_normaliza_una_a_una_contra_el_catalogo()
    {
        $zamorano = $this->premios(['terminal' => 60, 'uña' => 5], ['a', 'c']);

        $this->assertTrue($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'una', 'numero' => '2', 'triple' => 'a'],
        ], null, $zamorano));

        // Triangulación: `una` se normaliza a `uña`, que este juego no vende.
        $zulia = $this->premios(['terminal' => 60, 'signo_triple' => 6000], ['a', 'b', 'c']);

        $this->assertFalse($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'una', 'numero' => '2', 'triple' => 'a'],
        ], null, $zulia));
    }

    public function test_validar_apuesta_posicional_exige_triple_y_slot_habilitado()
    {
        $trioActivo = $this->premios(['terminal' => 60, 'punta' => 60], ['a']);

        $this->assertTrue($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'terminal', 'numero' => '52', 'triple' => 'a'],
        ], null, $trioActivo));

        // Sin `triple` no hay default: la venta se rechaza.
        $this->assertFalse($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'terminal', 'numero' => '52'],
        ], null, $trioActivo));

        // Slot no habilitado (trio-activo solo vende el A).
        $this->assertFalse($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'terminal', 'numero' => '52', 'triple' => 'b'],
        ], null, $trioActivo));

        // Valor fuera del vocabulario a/b/c.
        $this->assertFalse($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'terminal', 'numero' => '52', 'triple' => 'z'],
        ], null, $trioActivo));

        // Juego sin posicional (Táchira): los slots vacíos rechazan la venta.
        $tachira = $this->premios(['signo_triple' => 5000, 'signo_terminal' => 500]);
        $this->assertFalse($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'terminal', 'numero' => '62', 'triple' => 'a'],
        ], null, $tachira));

        // signo_terminal NO exige `triple` (va contra triple_c).
        $this->assertTrue($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'signo_terminal', 'numero' => '59', 'signo' => 'LEO'],
        ], null, $tachira));
    }

    public function test_validar_apuesta_acepta_signo_terminal_y_signo_una_sin_triple()
    {
        $premios = $this->premios(['signo_terminal' => 600, 'signo_uña' => 60]);

        $this->assertTrue($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'signo_terminal', 'numero' => '59', 'signo' => 'LEO'],
        ], null, $premios));

        $this->assertTrue($this->plugin->validarApuesta([
            'combinacion' => ['tipo' => 'signo_uña', 'numero' => '9', 'signo' => 'LEO'],
        ], null, $premios));
    }

    public function test_las_reglas_del_request_admiten_el_tipo_una()
    {
        // Si `una` no está en Rule::in, el request rechaza antes de llegar al
        // plugin y la normalización a `uña` nunca corre. La posicional exige
        // `triple` (D3), así que el payload lo incluye.
        $validator = Validator::make(
            ['combinacion' => ['tipo' => 'una', 'numero' => '2', 'triple' => 'a']],
            $this->plugin->getValidationRules()
        );

        $this->assertTrue($validator->passes(), (string) $validator->errors()->first('combinacion.tipo'));
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
