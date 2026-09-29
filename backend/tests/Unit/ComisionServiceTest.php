<?php

namespace Tests\Unit;

use App\Models\Banca;
use App\Models\ComisionDefault;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Models\Taquilla;
use App\Services\ComisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S2 — lectores de tasa (D2, D11).
 *
 * - tasaPropia: fila propia del nivel; NULL/ausente = 0.
 * - tasaEfectiva: cascada taquilla > grupo > banca > default global;
 *   fila NULL cede; sin definir en cadena Y default global ⇒ 0.00.
 * - tasaLiquidable: tope acumulado D11 `min(tasaEfectiva, max(0, 100 − Σ
 *   tasas propias de ancestros))`; grupo ⇒ Σ{banca}; taquilla ⇒
 *   Σ{grupo, banca}; por moneda; piso 0.
 */
class ComisionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function crearCadena(): array
    {
        $banca = Banca::create([
            'name' => 'Banca Comisiones',
            'code' => 'BCOM1',
            'active' => true,
        ]);

        $grupo = Grupo::create([
            'name' => 'Grupo Comisiones',
            'code' => 'GCOM1',
            'banca_id' => $banca->id,
            'active' => true,
        ]);

        $taquilla = Taquilla::create([
            'name' => 'Taquilla Comisiones',
            'code' => 'TCOM1',
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);

        $juego = Juego::create([
            'name' => 'Juego Comisiones',
            'slug' => 'juego-comisiones-'.uniqid(),
            'type' => 'animalitos',
            'active' => true,
        ]);

        return [$juego, $banca, $grupo, $taquilla];
    }

    private function limite(int $juegoId, int $bancaId, ?int $grupoId, ?int $taquillaId, string $moneda, $porcentajePago): void
    {
        JuegoLimite::create([
            'juego_id' => $juegoId,
            'banca_id' => $bancaId,
            'grupo_id' => $grupoId,
            'taquilla_id' => $taquillaId,
            'moneda' => $moneda,
            'porcentaje_pago' => $porcentajePago,
        ]);
    }

    private function defaultGlobal(string $moneda, $porcentajePago): void
    {
        ComisionDefault::where('moneda', $moneda)
            ->update(['porcentaje_pago' => $porcentajePago]);
    }

    // ==================================================
    // 2.1 Cascada (tasa efectiva)
    // ==================================================

    public function test_override_de_taquilla_gana()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 30);
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 20);
        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);

        $service = new ComisionService;
        $tasa = $service->tasaEfectiva('taquilla', $taquilla->id, $juego->id, 'bs');

        $this->assertSame(30.0, $tasa);
    }

    public function test_null_cede_al_siguiente_nivel()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', null);
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 20);

        $service = new ComisionService;
        $tasa = $service->tasaEfectiva('taquilla', $taquilla->id, $juego->id, 'bs');

        $this->assertSame(20.0, $tasa);
    }

    public function test_fallback_al_default_global_cuando_ningun_nivel_define()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->defaultGlobal('bs', 12.5);

        $service = new ComisionService;
        $tasa = $service->tasaEfectiva('taquilla', $taquilla->id, $juego->id, 'bs');

        $this->assertSame(12.5, $tasa);
    }

    public function test_sin_definir_en_cadena_y_sin_default_global_es_cero()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->defaultGlobal('bs', null);

        $service = new ComisionService;
        $tasa = $service->tasaEfectiva('taquilla', $taquilla->id, $juego->id, 'bs');

        $this->assertSame(0.0, $tasa);
    }

    public function test_hijo_mayor_que_padre_permitido_sin_guarda()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 100);

        $service = new ComisionService;
        $tasa = $service->tasaEfectiva('taquilla', $taquilla->id, $juego->id, 'bs');

        $this->assertSame(100.0, $tasa);
    }

    // ==================================================
    // 2.2 tasaPropia
    // ==================================================

    public function test_tasa_propia_usa_solo_la_fila_del_nivel()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 30);
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 20);
        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);

        $service = new ComisionService;

        $this->assertSame(30.0, $service->tasaPropia('taquilla', $taquilla->id, $juego->id, 'bs'));
        $this->assertSame(20.0, $service->tasaPropia('grupo', $grupo->id, $juego->id, 'bs'));
        $this->assertSame(10.0, $service->tasaPropia('banca', $banca->id, $juego->id, 'bs'));
    }

    public function test_tasa_propia_null_o_ausente_es_cero()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', null);

        $service = new ComisionService;

        $this->assertSame(0.0, $service->tasaPropia('taquilla', $taquilla->id, $juego->id, 'bs'));
        $this->assertSame(0.0, $service->tasaPropia('grupo', $grupo->id, $juego->id, 'bs'));
        $this->assertSame(0.0, $service->tasaPropia('banca', $banca->id, $juego->id, 'bs'));
    }

    // ==================================================
    // 2.4 Tope acumulado (tasa liquidable, D11)
    // ==================================================

    public function test_banca_10_taquilla_100_liquida_90()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 100);

        $service = new ComisionService;
        $tasa = $service->tasaLiquidable('taquilla', $taquilla->id, $juego->id, 'bs');

        $this->assertSame(90.0, $tasa);
    }

    public function test_suma_acumulada_menor_igual_100_conserva_la_tasa()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, null, null, 'bs', 20);
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 40);

        $service = new ComisionService;
        $tasa = $service->tasaLiquidable('taquilla', $taquilla->id, $juego->id, 'bs');

        $this->assertSame(40.0, $tasa);
    }

    public function test_tope_independiente_por_moneda()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();

        // bs: banca 10 + taquilla 100 ⇒ 90 (tope aplica)
        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 100);

        // usd: banca 20 + taquilla 40 ⇒ 40 (suma 60 ≤ 100, sin tope)
        $this->limite($juego->id, $banca->id, null, null, 'usd', 20);
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'usd', 40);

        $service = new ComisionService;

        $this->assertSame(90.0, $service->tasaLiquidable('taquilla', $taquilla->id, $juego->id, 'bs'));
        $this->assertSame(40.0, $service->tasaLiquidable('taquilla', $taquilla->id, $juego->id, 'usd'));
    }

    public function test_null_herencia_banca_60_resuelve_60_y_liquida_40()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, null, null, 'bs', 60);
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', null);
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', null);

        $service = new ComisionService;

        $this->assertSame(60.0, $service->tasaEfectiva('taquilla', $taquilla->id, $juego->id, 'bs'));
        $this->assertSame(40.0, $service->tasaLiquidable('taquilla', $taquilla->id, $juego->id, 'bs'));
    }

    public function test_tope_aplica_a_nivel_grupo_con_banca_como_ancestro()
    {
        [$juego, $banca, $grupo] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, null, null, 'bs', 30);
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 100);

        $service = new ComisionService;
        $tasa = $service->tasaLiquidable('grupo', $grupo->id, $juego->id, 'bs');

        $this->assertSame(70.0, $tasa);
    }
}
