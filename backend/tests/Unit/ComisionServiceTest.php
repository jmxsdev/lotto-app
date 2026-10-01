<?php

namespace Tests\Unit;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\Comision;
use App\Models\ComisionDefault;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Models\Taquilla;
use App\Services\ComisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * S2 — lectores de tasa (D2, D11).
 *
 * - tasaPropia: fila propia del nivel; NULL/ausente = 0.
 * - tasaEfectiva: cascada taquilla > grupo > banca > default global;
 *   fila NULL cede; sin definir en cadena Y default global ⇒ 0.00.
 * - tasaLiquidable: tope acumulado D11 `min(tasaEfectiva, max(0, 100 − Σ
 *   tasas propias de ancestros))`; banca ⇒ sin ancestros (liquidable =
 *   su tasa); grupo ⇒ Σ{banca}; taquilla ⇒ Σ{grupo, banca}; por moneda;
 *   piso 0. Banca, Grupo y Taquilla cobran su propia comisión (suma cero:
 *   la suma de las tasas liquidables de la cadena ≤ 100% de las ventas).
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

    private function apuesta(
        int $juegoId,
        int $taquillaId,
        float $amountBs,
        float $amountUsd,
        float $tasaCambio,
        string $estado = 'pendiente',
        ?string $fechaHora = null
    ): Apuesta {
        return Apuesta::create([
            'taquilla_id' => $taquillaId,
            'juego_id' => $juegoId,
            'amount_bs' => $amountBs,
            'amount_usd' => $amountUsd,
            'exchange_rate_applied' => $tasaCambio,
            'total_bs_equivalent' => round($amountBs + ($amountUsd * $tasaCambio), 2),
            'estado' => $estado,
            'fecha_hora' => $fechaHora ?? now(),
        ]);
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

    public function test_banca_liquida_su_tasa_propia_sin_ancestros()
    {
        [$juego, $banca] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);

        $service = new ComisionService;

        // La banca no tiene ancestros con tasa ⇒ liquidable = su propia tasa (≤ 100)
        $this->assertSame(10.0, $service->tasaLiquidable('banca', $banca->id, $juego->id, 'bs'));
    }

    public function test_suma_cero_banca_grupo_taquilla_10_20_100()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 20);
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 100);

        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, 'pendiente', '2026-09-15 10:00:00');

        $service = new ComisionService;
        $desde = Carbon::parse('2026-09-01');
        $hasta = Carbon::parse('2026-09-30');

        // Suma cero: 10 + 20 + 70 = 100% de la venta
        $this->assertSame(10.0, $service->tasaLiquidable('banca', $banca->id, $juego->id, 'bs'));
        $this->assertSame(20.0, $service->tasaLiquidable('grupo', $grupo->id, $juego->id, 'bs'));
        $this->assertSame(70.0, $service->tasaLiquidable('taquilla', $taquilla->id, $juego->id, 'bs'));

        $this->assertSame(10.0, $service->comisionEntidad('banca', $banca->id, $desde, $hasta));
        $this->assertSame(20.0, $service->comisionEntidad('grupo', $grupo->id, $desde, $hasta));
        $this->assertSame(70.0, $service->comisionEntidad('taquilla', $taquilla->id, $desde, $hasta));
    }

    public function test_comision_nivel_banca_suma_ventas_de_su_subarbol()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();

        $taquilla2 = Taquilla::create([
            'name' => 'Taquilla Comisiones 3',
            'code' => 'TCOM3',
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);

        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);

        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, 'pendiente', '2026-09-15 10:00:00');
        $this->apuesta($juego->id, $taquilla2->id, 200.0, 0.0, 36.5, 'pendiente', '2026-09-15 11:00:00');

        $service = new ComisionService;
        $comision = $service->comisionEntidad(
            'banca',
            $banca->id,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30')
        );

        // SUM 300 (subárbol completo) × 10% = 30.00
        $this->assertSame(30.0, $comision);
    }

    // ==================================================
    // 3.1 Cálculo de comisión (S3, D6)
    // ==================================================

    public function test_comision_entidad_suma_por_tasa_liquidable_redondeada()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 100);

        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, 'pendiente', '2026-09-15 10:00:00');
        $this->apuesta($juego->id, $taquilla->id, 50.0, 0.0, 36.5, 'pendiente', '2026-09-15 11:00:00');

        $service = new ComisionService;
        $comision = $service->comisionEntidad(
            'taquilla',
            $taquilla->id,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30')
        );

        // SUM 150 × liquidable 90% (banca 10 + taquilla 100) = 135.00
        $this->assertSame(135.0, $comision);
    }

    public function test_comision_redondea_a_dos_decimales()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 12.5);

        $this->apuesta($juego->id, $taquilla->id, 11.11, 0.0, 36.5, 'pendiente', '2026-09-15 10:00:00');

        $service = new ComisionService;
        $comision = $service->comisionEntidad(
            'taquilla',
            $taquilla->id,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30')
        );

        // 11.11 × 12.5% = 1.38875 → 1.39
        $this->assertSame(1.39, $comision);
    }

    public function test_comision_excluye_anuladas()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 10);

        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, 'pendiente', '2026-09-15 10:00:00');
        $this->apuesta($juego->id, $taquilla->id, 200.0, 0.0, 36.5, 'anulada', '2026-09-15 11:00:00');

        $service = new ComisionService;
        $comision = $service->comisionEntidad(
            'taquilla',
            $taquilla->id,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30')
        );

        // Solo la venta no anulada (100) × 10% = 10.00
        $this->assertSame(10.0, $comision);
    }

    public function test_comision_rango_inclusivo()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 10);

        // Venta exactamente en el inicio (00:00:00) y otra en el fin (23:59:59)
        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, 'pendiente', '2026-09-01 00:00:00');
        $this->apuesta($juego->id, $taquilla->id, 50.0, 0.0, 36.5, 'pendiente', '2026-09-30 23:59:59');

        // Fuera del rango
        $this->apuesta($juego->id, $taquilla->id, 999.0, 0.0, 36.5, 'pendiente', '2026-08-31 23:59:59');
        $this->apuesta($juego->id, $taquilla->id, 999.0, 0.0, 36.5, 'pendiente', '2026-10-01 00:00:00');

        $service = new ComisionService;
        $comision = $service->comisionEntidad(
            'taquilla',
            $taquilla->id,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30')
        );

        // 150 × 10% = 15.00 (las de fuera no cuentan)
        $this->assertSame(15.0, $comision);
    }

    public function test_comision_rango_vacio_es_cero()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 10);

        $service = new ComisionService;
        $comision = $service->comisionEntidad(
            'taquilla',
            $taquilla->id,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30')
        );

        $this->assertSame(0.0, $comision);
    }

    public function test_comision_buckets_por_moneda_mixto()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 10);
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'usd', 5);

        // Venta mixta: contribuye a ambos buckets
        $this->apuesta($juego->id, $taquilla->id, 100.0, 50.0, 36.5, 'pendiente', '2026-09-15 10:00:00');

        $service = new ComisionService;
        $comision = $service->comisionEntidad(
            'taquilla',
            $taquilla->id,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30')
        );

        // bs: 100 × 10% = 10.00; usd: (50 × 36.5) × 5% = 91.25 → total 101.25
        $this->assertSame(101.25, $comision);
    }

    public function test_comision_moneda_especifica_solo_usa_ese_bucket()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 10);
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'usd', 5);

        $this->apuesta($juego->id, $taquilla->id, 100.0, 50.0, 36.5, 'pendiente', '2026-09-15 10:00:00');

        $service = new ComisionService;
        $desde = Carbon::parse('2026-09-01');
        $hasta = Carbon::parse('2026-09-30');

        $this->assertSame(10.0, $service->comisionEntidad('taquilla', $taquilla->id, $desde, $hasta, null, 'bs'));
        $this->assertSame(91.25, $service->comisionEntidad('taquilla', $taquilla->id, $desde, $hasta, null, 'usd'));
    }

    public function test_comision_agrega_todos_los_juegos_con_su_propia_tasa()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();

        $juego2 = Juego::create([
            'name' => 'Juego Comisiones 2',
            'slug' => 'juego-comisiones-2-'.uniqid(),
            'type' => 'animalitos',
            'active' => true,
        ]);

        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 10);
        $this->limite($juego2->id, $banca->id, $grupo->id, null, 'bs', 20);

        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, 'pendiente', '2026-09-15 10:00:00');
        $this->apuesta($juego2->id, $taquilla->id, 200.0, 0.0, 36.5, 'pendiente', '2026-09-15 11:00:00');

        $service = new ComisionService;
        $desde = Carbon::parse('2026-09-01');
        $hasta = Carbon::parse('2026-09-30');

        // Todos los juegos: 100×10% + 200×20% = 50.00
        $this->assertSame(50.0, $service->comisionEntidad('taquilla', $taquilla->id, $desde, $hasta));

        // Solo juego1: 100×10% = 10.00
        $this->assertSame(10.0, $service->comisionEntidad('taquilla', $taquilla->id, $desde, $hasta, $juego->id));
    }

    public function test_comision_nivel_grupo_suma_ventas_de_sus_taquillas()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();

        $taquilla2 = Taquilla::create([
            'name' => 'Taquilla Comisiones 2',
            'code' => 'TCOM2',
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);

        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 15);

        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, 'pendiente', '2026-09-15 10:00:00');
        $this->apuesta($juego->id, $taquilla2->id, 200.0, 0.0, 36.5, 'pendiente', '2026-09-15 11:00:00');

        $service = new ComisionService;
        $comision = $service->comisionEntidad(
            'grupo',
            $grupo->id,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30')
        );

        // SUM 300 (ambas taquillas) × 15% = 45.00
        $this->assertSame(45.0, $comision);
    }

    // ==================================================
    // 3.3 Reporte bulk y previsualización (S3, D7)
    // ==================================================

    public function test_comisiones_reporte_devuelve_montos_por_entidad()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();

        $grupo2 = Grupo::create([
            'name' => 'Grupo Comisiones 2',
            'code' => 'GCOM2',
            'banca_id' => $banca->id,
            'active' => true,
        ]);

        $taquilla2 = Taquilla::create([
            'name' => 'Taquilla Comisiones 2',
            'code' => 'TCOM2',
            'grupo_id' => $grupo2->id,
            'active' => true,
        ]);

        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 10);
        $this->limite($juego->id, $banca->id, $grupo2->id, $taquilla2->id, 'bs', 20);

        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, 'pendiente', '2026-09-15 10:00:00');
        $this->apuesta($juego->id, $taquilla2->id, 100.0, 0.0, 36.5, 'pendiente', '2026-09-15 11:00:00');

        $service = new ComisionService;
        $montos = $service->comisionesReporte(
            'taquilla',
            [$taquilla->id, $taquilla2->id],
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30')
        );

        $this->assertSame([
            $taquilla->id => 10.0,
            $taquilla2->id => 20.0,
        ], $montos);
    }

    public function test_previsualizar_devuelve_rows_de_banca_grupo_y_taquilla()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();

        $grupoVacio = Grupo::create([
            'name' => 'Grupo Vacio',
            'code' => 'GVAC1',
            'banca_id' => $banca->id,
            'active' => true,
        ]);

        Taquilla::create([
            'name' => 'Taquilla Vacia',
            'code' => 'TVAC1',
            'grupo_id' => $grupoVacio->id,
            'active' => true,
        ]);

        // Suma cero 3 niveles: banca 10 + grupo 20 + taquilla 100 ⇒ 10 / 20 / 70
        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 20);
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 100);

        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, 'pendiente', '2026-09-15 10:00:00');

        $service = new ComisionService;
        $resultado = $service->previsualizar(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $rows = $resultado['rows'];
        $conflictos = $resultado['conflictos'];

        // Sin filas de comisiones previas ⇒ sin conflictos
        $this->assertSame([], $conflictos);

        // Banca, grupo y taquilla con ventas (sin entidades con base 0)
        $this->assertCount(3, $rows);

        $filaBanca = collect($rows)->firstWhere('nivel', 'banca');
        $this->assertNotNull($filaBanca);
        $this->assertSame($banca->id, $filaBanca['entidad_id']);
        $this->assertSame('Banca Comisiones', $filaBanca['entidad']);
        $this->assertSame(10.0, $filaBanca['monto_comision']);

        $filaGrupo = collect($rows)->firstWhere('nivel', 'grupo');
        $this->assertNotNull($filaGrupo);
        $this->assertSame($grupo->id, $filaGrupo['entidad_id']);
        $this->assertSame('Grupo Comisiones', $filaGrupo['entidad']);
        $this->assertSame(20.0, $filaGrupo['monto_comision']);

        $filaTaquilla = collect($rows)->firstWhere('nivel', 'taquilla');
        $this->assertNotNull($filaTaquilla);
        $this->assertSame($taquilla->id, $filaTaquilla['entidad_id']);
        $this->assertSame('Taquilla Comisiones', $filaTaquilla['entidad']);
        $this->assertSame(70.0, $filaTaquilla['monto_comision']);

        // Entidades sin ventas no generan fila (ids por tabla pueden colisionar;
        // se busca por nivel + entidad_id)
        $this->assertNull(collect($rows)->firstWhere(
            fn ($fila) => $fila['nivel'] === 'grupo' && $fila['entidad_id'] === $grupoVacio->id
        ));
        $this->assertNull(collect($rows)->firstWhere(
            fn ($fila) => $fila['nivel'] === 'taquilla' && $fila['entidad_id'] === 999999
        ));
    }

    public function test_previsualizar_detecta_conflictos_por_rango_solapado()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();

        // Fila existente de la BANCA que se solapa con [2026-09-10, 2026-09-20]
        Comision::create([
            'banca_id' => $banca->id,
            'periodo' => '2026-09-05..2026-09-10',
            'monto_comision' => 30.00,
            'estado' => 'pendiente',
            'fecha_inicio' => '2026-09-05',
            'fecha_fin' => '2026-09-10',
        ]);

        // Fila existente del grupo que se solapa con [2026-09-10, 2026-09-20]
        Comision::create([
            'grupo_id' => $grupo->id,
            'periodo' => '2026-09-01..2026-09-15',
            'monto_comision' => 50.00,
            'estado' => 'pendiente',
            'fecha_inicio' => '2026-09-01',
            'fecha_fin' => '2026-09-15',
        ]);

        // Fila fuera del rango (después) — no debe conflictuar
        Comision::create([
            'taquilla_id' => $taquilla->id,
            'periodo' => '2026-10-01..2026-10-15',
            'monto_comision' => 20.00,
            'estado' => 'pendiente',
            'fecha_inicio' => '2026-10-01',
            'fecha_fin' => '2026-10-15',
        ]);

        $service = new ComisionService;
        $resultado = $service->previsualizar(Carbon::parse('2026-09-10'), Carbon::parse('2026-09-20'));

        $conflictos = $resultado['conflictos'];

        $this->assertCount(2, $conflictos);
        $this->assertContains(['nivel' => 'banca', 'entidad_id' => $banca->id], $conflictos);
        $this->assertContains(['nivel' => 'grupo', 'entidad_id' => $grupo->id], $conflictos);
    }

    public function test_previsualizar_filtra_por_banca_ids()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearCadena();

        $banca2 = Banca::create([
            'name' => 'Banca Comisiones 2',
            'code' => 'BCOM2',
            'active' => true,
        ]);

        $grupo2 = Grupo::create([
            'name' => 'Grupo Comisiones 2',
            'code' => 'GCOM3',
            'banca_id' => $banca2->id,
            'active' => true,
        ]);

        $taquilla2 = Taquilla::create([
            'name' => 'Taquilla Comisiones 3',
            'code' => 'TCOM3',
            'grupo_id' => $grupo2->id,
            'active' => true,
        ]);

        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 10);
        $this->limite($juego->id, $banca2->id, $grupo2->id, null, 'bs', 25);

        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, 'pendiente', '2026-09-15 10:00:00');
        $this->apuesta($juego->id, $taquilla2->id, 100.0, 0.0, 36.5, 'pendiente', '2026-09-15 11:00:00');

        $service = new ComisionService;
        $resultado = $service->previsualizar(
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30'),
            [$banca->id]
        );

        $rows = $resultado['rows'];

        // Solo la jerarquía de la banca 1 (ids por tabla pueden colisionar;
        // se busca por nivel + entidad_id): banca + grupo + taquilla
        $this->assertCount(3, $rows);
        $this->assertNotNull(collect($rows)->firstWhere(
            fn ($fila) => $fila['nivel'] === 'banca' && $fila['entidad_id'] === $banca->id
        ));
        $this->assertNotNull(collect($rows)->firstWhere(
            fn ($fila) => $fila['nivel'] === 'grupo' && $fila['entidad_id'] === $grupo->id
        ));
        $this->assertNotNull(collect($rows)->firstWhere(
            fn ($fila) => $fila['nivel'] === 'taquilla' && $fila['entidad_id'] === $taquilla->id
        ));
        $this->assertNull(collect($rows)->firstWhere(
            fn ($fila) => $fila['nivel'] === 'banca' && $fila['entidad_id'] === $banca2->id
        ));
        $this->assertNull(collect($rows)->firstWhere(
            fn ($fila) => $fila['nivel'] === 'grupo' && $fila['entidad_id'] === $grupo2->id
        ));
        $this->assertNull(collect($rows)->firstWhere(
            fn ($fila) => $fila['nivel'] === 'taquilla' && $fila['entidad_id'] === $taquilla2->id
        ));
    }
}
