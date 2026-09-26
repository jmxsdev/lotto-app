<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos;
use App\Services\JuegoPluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adaptador de FORMA del plugin Animalitos (D1/C, design §3.3) y activación
 * del dinero vía PremiosEngine a través de JuegoPluginManager (F1b).
 *
 * H13/N10 (REQ2): la comparación normaliza acentos en AMBOS lados
 * ("Delfín" ≡ "Delfin"); N2: la validación acepta labels acentuados del
 * zoológico del juego. El plugin expone la clave canónica; el multiplicador
 * lo decide el engine desde config.premios (REQ1), nunca el plugin.
 */
class AnimalitosPluginTest extends TestCase
{
    use RefreshDatabase;

    protected Animalitos $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plugin = new Animalitos;
    }

    private function resultado(array $numeros): array
    {
        return ['numeros_ganadores' => $numeros];
    }

    // ---------------- evaluarAcierto(): acentos (H13/N10) ----------------

    public function test_evaluar_acierto_coincide_apuesta_acentuada_contra_resultado_sin_acento()
    {
        $apuesta = ['combinacion' => ['animal' => 'Delfín'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto = $this->plugin->evaluarAcierto($apuesta, $this->resultado(['nombre_animal' => 'Delfin']));

        $this->assertTrue($acierto['coincide']);
        $this->assertSame('base', $acierto['clave']);
    }

    public function test_evaluar_acierto_coincide_apuesta_sin_acento_contra_resultado_acentuado()
    {
        $apuesta = ['combinacion' => ['animal' => 'Delfin'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto = $this->plugin->evaluarAcierto($apuesta, $this->resultado(['nombre_animal' => 'Delfín']));

        $this->assertTrue($acierto['coincide']);
    }

    public function test_evaluar_acierto_no_coincide_con_animal_distinto()
    {
        $apuesta = ['combinacion' => ['animal' => 'perro'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto = $this->plugin->evaluarAcierto($apuesta, $this->resultado(['nombre_animal' => 'gato']));

        $this->assertFalse($acierto['coincide']);
    }

    public function test_evaluar_acierto_sin_animal_en_apuesta_o_resultado_no_coincide()
    {
        $sinAnimal = $this->plugin->evaluarAcierto(['combinacion' => []], $this->resultado(['nombre_animal' => 'perro']));
        $sinResultado = $this->plugin->evaluarAcierto(
            ['combinacion' => ['animal' => 'perro']],
            $this->resultado([])
        );

        $this->assertFalse($sinAnimal['coincide']);
        $this->assertFalse($sinResultado['coincide']);
    }

    // ---------------- evaluarAcierto(): señales de comodines (REQ6) ----------------

    public function test_evaluar_acierto_emite_comodin_mega_cuando_resultado_trae_flag()
    {
        $apuesta = ['combinacion' => ['animal' => 'aguila'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto = $this->plugin->evaluarAcierto($apuesta, $this->resultado(['nombre_animal' => 'Águila', 'comodin' => true]));

        $this->assertTrue($acierto['coincide']);
        $this->assertContains('mega', $acierto['meta']['comodines']);
    }

    public function test_evaluar_acierto_emite_comodin_de_letra_selva()
    {
        $apuesta = ['combinacion' => ['animal' => 'perro'], 'amount_bs' => 10, 'amount_usd' => 0];

        $aciertoB = $this->plugin->evaluarAcierto($apuesta, $this->resultado(['nombre_animal' => 'Perro', 'comodin' => 'B']));
        $aciertoA = $this->plugin->evaluarAcierto($apuesta, $this->resultado(['nombre_animal' => 'Perro', 'comodin' => 'A']));

        $this->assertContains('comodin-b', $aciertoB['meta']['comodines']);
        $this->assertContains('comodin-a', $aciertoA['meta']['comodines']);
    }

    public function test_evaluar_acierto_emite_comodines_de_figura_75_y_99()
    {
        $apuesta75 = ['combinacion' => ['animal' => 'guacharo'], 'amount_bs' => 10, 'amount_usd' => 0];
        $apuesta99 = ['combinacion' => ['animal' => 'guacharito'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto75 = $this->plugin->evaluarAcierto($apuesta75, $this->resultado(['nombre_animal' => 'Guacharo', 'numero' => 75]));
        $acierto99 = $this->plugin->evaluarAcierto($apuesta99, $this->resultado(['nombre_animal' => 'Guacharito', 'numero' => 99]));

        // El plugin no conoce el juego: emite el superset de claves de figura;
        // el engine filtra por las comodines configuradas del juego (D1/C).
        $this->assertContains('patronus-75', $acierto75['meta']['comodines']);
        $this->assertContains('guacharo-75', $acierto75['meta']['comodines']);
        $this->assertContains('guacharito-99', $acierto99['meta']['comodines']);
    }

    public function test_evaluar_acierto_emite_palabra_patronus_cuando_resultado_trae_flag()
    {
        $apuesta = ['combinacion' => ['animal' => 'perro'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto = $this->plugin->evaluarAcierto($apuesta, $this->resultado(['nombre_animal' => 'Perro', 'patronus' => true]));

        $this->assertTrue($acierto['coincide']);
        $this->assertContains('patronus-palabra', $acierto['meta']['comodines']);
    }

    public function test_evaluar_acierto_sin_senales_no_emite_comodines()
    {
        $apuesta = ['combinacion' => ['animal' => 'perro'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto = $this->plugin->evaluarAcierto($apuesta, $this->resultado(['nombre_animal' => 'Perro', 'numero' => 27]));

        $this->assertSame([], $acierto['meta']['comodines']);
    }

    // ---------------- modalidadDe() ----------------

    public function test_modalidad_de_siempre_base_para_animalitos()
    {
        $this->assertSame('base', $this->plugin->modalidadDe(['animal' => 'perro']));
        $this->assertSame('base', $this->plugin->modalidadDe(['animal' => 'gato', 'numero' => 11]));
    }

    public function test_modalidad_de_tripleta_con_tres_selecciones_del_mismo_sorteo()
    {
        // F2/D8 §3.4: 3 animales del mismo sorteo → tripleta (Cazalotón 200×,
        // Loto Chaima 50×). La Dupleta (2 animales, 2 sorteos) queda fuera.
        $this->assertSame('tripleta', $this->plugin->modalidadDe([
            'selecciones' => [
                ['animal' => 'perro'],
                ['animal' => 'gato'],
                ['animal' => 'leon'],
            ],
        ]));
    }

    public function test_modalidad_de_base_con_menos_de_tres_selecciones()
    {
        // 2 selecciones no es tripleta ni dupleta soportada: cae a base como
        // shape no reconocido (D8: la dupleta no se modela).
        $this->assertSame('base', $this->plugin->modalidadDe([
            'selecciones' => [['animal' => 'perro'], ['animal' => 'gato']],
        ]));
    }

    // ---------------- validarApuesta(): acentos en opciones (N2) ----------------

    public function test_validar_apuesta_acepta_label_acentuado_de_las_opciones()
    {
        $opciones = [
            ['label' => 'Delfín', 'value' => 'delfin', 'numero' => 0],
            ['label' => 'Caimán', 'value' => 'caiman', 'numero' => 30],
        ];

        $this->assertTrue($this->plugin->validarApuesta(['combinacion' => ['animal' => 'delfín']], $opciones));
        $this->assertTrue($this->plugin->validarApuesta(['combinacion' => ['animal' => 'CAIMAN']], $opciones));
    }

    public function test_validar_apuesta_sin_opciones_usa_mapa_canonico_normalizado()
    {
        $this->assertTrue($this->plugin->validarApuesta(['combinacion' => ['animal' => 'Delfín']]));
        $this->assertFalse($this->plugin->validarApuesta(['combinacion' => ['animal' => 'dragon']]));
    }

    // ---------------- Dinero: el multiplicador lo decide el engine (REQ1) ----------------

    public function test_calcular_premio_via_manager_engine_paga_base_con_acentos()
    {
        $juego = Juego::create([
            'name' => 'Lotto Activo',
            'slug' => 'lotto-activo',
            'type' => 'animalitos',
            'config' => ['premios' => ['base' => 30, 'modalidades' => [], 'comodines' => []]],
            'requires_scraper' => true,
            'active' => true,
        ]);
        PluginJuego::create([
            'juego_id' => $juego->id,
            'class_namespace' => Animalitos::class,
            'version' => '1.0.0',
            'active' => true,
        ]);

        $manager = app(JuegoPluginManager::class);
        $premio = $manager->calcularPremio(
            $juego,
            ['combinacion' => ['animal' => 'Delfín'], 'amount_bs' => 100, 'amount_usd' => 0],
            $this->resultado(['nombre_animal' => 'Delfin'])
        );

        $this->assertEquals(['premio_bs' => 3000.0, 'premio_usd' => 0.0], $premio);
    }

    public function test_calcular_premio_via_manager_engine_aplica_comodin_mega()
    {
        $juego = Juego::create([
            'name' => 'Mega Animal 40',
            'slug' => 'mega-animal-40',
            'type' => 'animalitos',
            'config' => [
                'premios' => [
                    'base' => 30,
                    'modalidades' => [],
                    'comodines' => ['mega' => ['tipo' => 'flag', 'premio_multiplo' => 40, 'nombre' => 'MEGA']],
                ],
            ],
            'requires_scraper' => true,
            'active' => true,
        ]);
        PluginJuego::create([
            'juego_id' => $juego->id,
            'class_namespace' => Animalitos::class,
            'version' => '1.0.0',
            'active' => true,
        ]);

        $premio = app(JuegoPluginManager::class)->calcularPremio(
            $juego,
            ['combinacion' => ['animal' => 'Águila'], 'amount_bs' => 10, 'amount_usd' => 0],
            $this->resultado(['nombre_animal' => 'Aguila', 'comodin' => true])
        );

        $this->assertEquals(['premio_bs' => 400.0, 'premio_usd' => 0.0], $premio);
    }
}
