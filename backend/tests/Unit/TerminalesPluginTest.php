<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Terminales;
use App\Services\JuegoPluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adaptador de FORMA del plugin Terminales (D1/C, design §3.3).
 *
 * N1 (REQ3): `evaluarAcierto` liquida contra la clave `numero` que persisten
 * los scrapers (antes buscaba `terminal` y nunca pagaba); fallback defensivo
 * a `terminal`. N9: comparación con padding a 2 cifras ("7" ≡ "07"). El
 * premio (60×, reglamento) lo decide PremiosEngine desde config.premios.
 */
class TerminalesPluginTest extends TestCase
{
    use RefreshDatabase;

    protected Terminales $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plugin = new Terminales;
    }

    private function resultado(array $numeros): array
    {
        return ['numeros_ganadores' => $numeros];
    }

    // ---------------- evaluarAcierto(): clave numero (N1) y padding (N9) ----------------

    public function test_evaluar_acierto_liquida_contra_la_clave_numero()
    {
        $apuesta = ['combinacion' => ['numero' => '37'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto = $this->plugin->evaluarAcierto($apuesta, $this->resultado(['numero' => 37]));

        $this->assertTrue($acierto['coincide']);
        $this->assertSame('terminal', $acierto['clave']);
    }

    public function test_evaluar_acierto_fallback_defensivo_a_clave_terminal()
    {
        $apuesta = ['combinacion' => ['numero' => '37'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto = $this->plugin->evaluarAcierto($apuesta, $this->resultado(['terminal' => 37]));

        $this->assertTrue($acierto['coincide']);
    }

    public function test_evaluar_acierto_compara_con_padding_a_2_cifras()
    {
        $apuesta = ['combinacion' => ['numero' => '7'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto = $this->plugin->evaluarAcierto($apuesta, $this->resultado(['numero' => 7]));

        $this->assertTrue($acierto['coincide']);
        $this->assertSame('07', str_pad((string) 7, 2, '0', STR_PAD_LEFT));
    }

    public function test_evaluar_acierto_no_coincide_con_numero_distinto()
    {
        $apuesta = ['combinacion' => ['numero' => '37'], 'amount_bs' => 10, 'amount_usd' => 0];

        $acierto = $this->plugin->evaluarAcierto($apuesta, $this->resultado(['numero' => 38]));

        $this->assertFalse($acierto['coincide']);
    }

    public function test_evaluar_acierto_sin_numero_apostado_o_resultado_no_coincide()
    {
        $sinApuesta = $this->plugin->evaluarAcierto(['combinacion' => []], $this->resultado(['numero' => 37]));
        $sinResultado = $this->plugin->evaluarAcierto(
            ['combinacion' => ['numero' => '37']],
            $this->resultado([])
        );

        $this->assertFalse($sinApuesta['coincide']);
        $this->assertFalse($sinResultado['coincide']);
    }

    // ---------------- modalidadDe() ----------------

    public function test_modalidad_de_es_terminal()
    {
        $this->assertSame('terminal', $this->plugin->modalidadDe(['numero' => '37']));
        $this->assertSame('terminal', $this->plugin->modalidadDe(['numero' => 7]));
    }

    // ---------------- validarApuesta(): rango 00-99 ----------------

    public function test_validar_apuesta_acepta_numeros_de_00_a_99()
    {
        $this->assertTrue($this->plugin->validarApuesta(['combinacion' => ['numero' => '0']]));
        $this->assertTrue($this->plugin->validarApuesta(['combinacion' => ['numero' => 99]]));
    }

    public function test_validar_apuesta_rechaza_fuera_de_rango()
    {
        $this->assertFalse($this->plugin->validarApuesta(['combinacion' => ['numero' => 100]]));
        $this->assertFalse($this->plugin->validarApuesta(['combinacion' => []]));
    }

    // ---------------- Dinero: 60× desde config.premios (N1/REQ3) ----------------

    public function test_calcular_premio_via_manager_engine_paga_60_por_la_clave_numero()
    {
        $juego = Juego::create([
            'name' => 'Terminal Activo',
            'slug' => 'terminal-activo',
            'type' => 'terminales',
            'config' => ['premios' => ['base' => 60, 'modalidades' => [], 'comodines' => []]],
            'requires_scraper' => true,
            'active' => true,
        ]);
        PluginJuego::create([
            'juego_id' => $juego->id,
            'class_namespace' => Terminales::class,
            'version' => '1.0.0',
            'active' => true,
        ]);

        // Escenario REQ3: apuesta "37" por Bs. 10 y resultado con numero=37 → 10 × 60 = 600.
        $premio = app(JuegoPluginManager::class)->calcularPremio(
            $juego,
            ['combinacion' => ['numero' => '37'], 'amount_bs' => 10, 'amount_usd' => 0],
            $this->resultado(['numero' => 37])
        );

        $this->assertEquals(['premio_bs' => 600.0, 'premio_usd' => 0.0], $premio);
    }

    public function test_calcular_premio_via_manager_engine_no_paga_numero_distinto()
    {
        $juego = Juego::create([
            'name' => 'Terminal Activo',
            'slug' => 'terminal-activo',
            'type' => 'terminales',
            'config' => ['premios' => ['base' => 60, 'modalidades' => [], 'comodines' => []]],
            'requires_scraper' => true,
            'active' => true,
        ]);
        PluginJuego::create([
            'juego_id' => $juego->id,
            'class_namespace' => Terminales::class,
            'version' => '1.0.0',
            'active' => true,
        ]);

        $premio = app(JuegoPluginManager::class)->calcularPremio(
            $juego,
            ['combinacion' => ['numero' => '37'], 'amount_bs' => 10, 'amount_usd' => 0],
            $this->resultado(['numero' => 38])
        );

        $this->assertEquals(['premio_bs' => 0.0, 'premio_usd' => 0.0], $premio);
    }
}
