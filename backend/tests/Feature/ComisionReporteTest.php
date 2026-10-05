<?php

namespace Tests\Feature;

use App\Models\Agencia;
use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\ExchangeRate;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Models\Pago;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S5 (slice 4a) — columna de comisión en reportes de ventas (D9).
 *
 * Cada fila del reporte expone `Comision` (bs-equivalente del período):
 * - niveles recipient (banca/grupo/taquilla): monto settleable del rango,
 *   calculado a la tasa liquidable (D11: tope acumulado por ancestros).
 *   La banca no tiene ancestros con tasa ⇒ su monto es su propia tasa.
 * - agencia: rollup informativo Σgrupo + Σtaquilla del subárbol (el local
 *   no cobra comisión propia; es passthrough).
 * - La agrupación existente (nivel agencia/taquilla/grupo/banca) y todas
 *   las columnas previas quedan intactas (cambio aditivo).
 *
 * CAVEAT conocido (finding F4): una celda filtrada por `tipo_juego` NO es
 * idéntica a la fila del ledger sin filtrar. El ledger (`liquidar`) congela
 * el monto sobre TODOS los juegos del rango; la celda del reporte refleja
 * la vista filtrada actual (solo ese juego), por lo que puede diferir.
 */
class ComisionReporteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function superUser(): User
    {
        $super = User::where('email', 'super@lotto.com')->first();
        $super->assignRole('super_master');

        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $super->id,
            'is_active' => true,
        ]);

        return $super;
    }

    /**
     * @return array{0: Juego, 1: Banca, 2: Grupo, 3: Taquilla}
     */
    private function jerarquia(string $sufijo): array
    {
        $codigo = strtoupper(substr($sufijo, 0, 4)).uniqid();

        $banca = Banca::create([
            'name' => "Banca {$sufijo}",
            'code' => 'B'.$codigo,
            'active' => true,
        ]);

        $grupo = Grupo::create([
            'name' => "Grupo {$sufijo}",
            'code' => 'G'.$codigo,
            'banca_id' => $banca->id,
            'active' => true,
        ]);

        $taquilla = Taquilla::create([
            'name' => "Taquilla {$sufijo}",
            'code' => 'T'.$codigo,
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);

        $juego = Juego::create([
            'name' => "Juego {$sufijo}",
            'slug' => 'juego-'.strtolower($sufijo).'-'.uniqid(),
            'type' => 'terminales',
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

    private function apuesta(int $juegoId, int $taquillaId, float $amountBs, string $fecha = '2026-09-15 10:00:00'): void
    {
        Apuesta::create([
            'taquilla_id' => $taquillaId,
            'juego_id' => $juegoId,
            'amount_bs' => $amountBs,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => $amountBs,
            'estado' => 'pendiente',
            'fecha_hora' => $fecha,
        ]);
    }

    // ==================================================
    // 5.1 — ventasTotales: columna por nivel (S18, S19)
    // ==================================================

    /**
     * D9 + D11 — nivel taquilla: la fila muestra su monto settleable a la
     * tasa liquidable (banca 10 propia + taquilla 100 ⇒ taquilla liquida 90).
     */
    public function test_ventas_totales_nivel_taquilla_muestra_comision_liquidable_d11()
    {
        $super = $this->superUser();
        [$juego, $banca, $grupo, $taquilla] = $this->jerarquia('Uno');

        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 100);

        $this->apuesta($juego->id, $taquilla->id, 100.0);

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/reportes/ventas-totales?nivel=taquilla&fecha_desde=2026-09-01&fecha_hasta=2026-09-30');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data, 'La agrupación por taquilla debe conservar 1 fila');
        $fila = $data[0];
        $this->assertSame('Taquilla Uno', $fila['Entidad']);
        $this->assertEquals(100.0, $fila['Venta'], 'Venta intacta');
        $this->assertEquals(1, $fila['Total'], 'Total intacto');
        $this->assertArrayHasKey('Comision', $fila, 'Cada fila debe exponer la columna Comision');
        $this->assertEquals(90.0, $fila['Comision'], 'Comisión = 100 × tasa liquidable 90%');
    }

    /**
     * Tope por tipo — un `animalitos` con config legacy 40% liquida al 16%
     * en el reporte (clamp en lectura, la fila no se migra).
     */
    public function test_ventas_totales_animalitos_legacy_liquida_al_tope_16()
    {
        $super = $this->superUser();
        [, $banca, $grupo, $taquilla] = $this->jerarquia('Tope');

        $juegoAnimalitos = Juego::create([
            'name' => 'Juego Animalitos Tope',
            'slug' => 'juego-animalitos-tope-'.uniqid(),
            'type' => 'animalitos',
            'active' => true,
        ]);

        $this->limite($juegoAnimalitos->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 40);
        $this->apuesta($juegoAnimalitos->id, $taquilla->id, 100.0);

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/reportes/ventas-totales?nivel=taquilla&fecha_desde=2026-09-01&fecha_hasta=2026-09-30');

        $response->assertStatus(200);
        $this->assertEquals(16.0, $response->json('data.0.Comision'), 'Animalitos legacy 40% liquida 16%');
    }

    /**
     * D9 — nivel grupo: la fila del grupo muestra SU monto settleable
     * (tasa liquidable), no la tasa de la banca (retención).
     */
    public function test_ventas_totales_nivel_grupo_muestra_su_monto_liquidable()
    {
        $super = $this->superUser();
        [$juego, $banca, $grupo, $taquilla] = $this->jerarquia('Dos');

        // banca 10 propia + grupo 20 propio: grupo liquida min(20, 100−10) = 20
        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 20);

        $this->apuesta($juego->id, $taquilla->id, 100.0);

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/reportes/ventas-totales?nivel=grupo&fecha_desde=2026-09-01&fecha_hasta=2026-09-30');

        $response->assertStatus(200);
        $fila = $response->json('data.0');

        $this->assertArrayHasKey('Comision', $fila);
        $this->assertEquals(20.0, $fila['Comision'], 'Grupo liquida su tasa 20% (tope 100−10 de la banca)');
        $this->assertNotEquals(10.0, $fila['Comision'], 'La tasa propia de la banca es retención, no pago del grupo');
        $this->assertEquals(100.0, $fila['Venta'], 'Venta intacta');
    }

    /**
     * D9 — nivel banca: la fila muestra SU monto settleable propio (tasa
     * liquidable sin ancestros), ya no un rollup del subárbol.
     */
    public function test_ventas_totales_nivel_banca_muestra_su_monto_liquidable()
    {
        $super = $this->superUser();
        [$juego, $banca, $grupo, $taquilla] = $this->jerarquia('Tres');

        // banca 10 (propia), grupo 20, taquilla 40: la banca liquida 10
        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 20);
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 40);

        $this->apuesta($juego->id, $taquilla->id, 100.0);

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/reportes/ventas-totales?fecha_desde=2026-09-01&fecha_hasta=2026-09-30');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data, 'Agrupación por banca intacta');
        $fila = $data[0];
        $this->assertSame('Banca Tres', $fila['Entidad']);
        $this->assertEquals(100.0, $fila['Venta'], 'Venta intacta');
        $this->assertArrayHasKey('Comision', $fila);
        $this->assertEquals(10.0, $fila['Comision'], 'Banca = 100 × su tasa liquidable 10%');
        $this->assertNotEquals(60.0, $fila['Comision'], 'Ya no es el rollup del subárbol (grupo 20 + taquilla 40)');
    }

    /**
     * D9 — nivel agencia: agrupación por LOCAL intacta; cada local muestra
     * el rollup Σgrupo + Σtaquilla de su subárbol.
     */
    public function test_ventas_totales_nivel_agencia_muestra_rollup_por_local()
    {
        $super = $this->superUser();
        [$juego, $banca] = $this->jerarquia('Locales');

        $grupoA = Grupo::create(['name' => 'Grupo Local A', 'code' => 'GLA'.uniqid(), 'banca_id' => $banca->id, 'active' => true]);
        $localA = Agencia::factory()->create(['name' => 'Local A', 'grupo_id' => $grupoA->id, 'active' => true]);
        $taquillaA = Taquilla::create(['name' => 'T-Local-A', 'code' => 'TLA'.uniqid(), 'grupo_id' => $grupoA->id, 'agencia_id' => $localA->id, 'active' => true]);

        $grupoB = Grupo::create(['name' => 'Grupo Local B', 'code' => 'GLB'.uniqid(), 'banca_id' => $banca->id, 'active' => true]);
        $localB = Agencia::factory()->create(['name' => 'Local B', 'grupo_id' => $grupoB->id, 'active' => true]);
        $taquillaB = Taquilla::create(['name' => 'T-Local-B', 'code' => 'TLB'.uniqid(), 'grupo_id' => $grupoB->id, 'agencia_id' => $localB->id, 'active' => true]);

        // banca 10; grupoA 20 + taquillaA 40; grupoB 20 + taquillaB 30
        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);
        $this->limite($juego->id, $banca->id, $grupoA->id, null, 'bs', 20);
        $this->limite($juego->id, $banca->id, $grupoA->id, $taquillaA->id, 'bs', 40);
        $this->limite($juego->id, $banca->id, $grupoB->id, null, 'bs', 20);
        $this->limite($juego->id, $banca->id, $grupoB->id, $taquillaB->id, 'bs', 30);

        $this->apuesta($juego->id, $taquillaA->id, 100.0);
        $this->apuesta($juego->id, $taquillaB->id, 100.0);

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/reportes/ventas-totales?nivel=agencia&fecha_desde=2026-09-01&fecha_hasta=2026-09-30');

        $response->assertStatus(200);
        $porLocal = collect($response->json('data'))->keyBy('Entidad');

        $this->assertCount(2, $porLocal, 'Agrupación por LOCAL intacta (2 locales, no 2 máquinas)');
        $this->assertTrue($porLocal->has('Local A') && $porLocal->has('Local B'));

        $this->assertEquals(100.0, $porLocal['Local A']['Venta']);
        $this->assertEquals(1, $porLocal['Local A']['Total']);
        $this->assertEquals(60.0, $porLocal['Local A']['Comision'], 'Local A = grupo 20 + taquilla 40');

        $this->assertEquals(100.0, $porLocal['Local B']['Venta']);
        $this->assertEquals(1, $porLocal['Local B']['Total']);
        $this->assertEquals(50.0, $porLocal['Local B']['Comision'], 'Local B = grupo 20 + taquilla 30');
    }

    /**
     * D9 + F4 — filtro tipo_juego: la celda refleja SOLO ese juego y por
     * tanto NO es idéntica a la fila del ledger sin filtrar (caveat F4).
     */
    public function test_ventas_totales_filtro_tipo_juego_acota_la_comision_y_documenta_f4()
    {
        $super = $this->superUser();
        [$juegoA, $banca, $grupo, $taquilla] = $this->jerarquia('Filtro');

        $juegoB = Juego::create([
            'name' => 'Juego Filtro B',
            'slug' => 'juego-filtro-b-'.uniqid(),
            'type' => 'terminales',
            'active' => true,
        ]);

        // taquilla 50% en ambos juegos
        $this->limite($juegoA->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);
        $this->limite($juegoB->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);

        $this->apuesta($juegoA->id, $taquilla->id, 100.0);
        $this->apuesta($juegoB->id, $taquilla->id, 200.0);

        $rango = '&fecha_desde=2026-09-01&fecha_hasta=2026-09-30';

        // Sin filtro: 100×50% + 200×50% = 150.00 (lo que liquidaría el ledger)
        $sinFiltro = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/reportes/ventas-totales?nivel=taquilla'.$rango);
        $filaSinFiltro = $sinFiltro->json('data.0');
        $this->assertEquals(150.0, $filaSinFiltro['Comision']);

        // Con filtro tipo_juego: solo el juego A ⇒ 50.00
        $conFiltro = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/reportes/ventas-totales?nivel=taquilla&tipo_juego='.$juegoA->slug.$rango);
        $filaConFiltro = $conFiltro->json('data.0');

        $this->assertEquals(50.0, $filaConFiltro['Comision'], 'Celda filtrada = solo ventas del juego A');
        $this->assertNotEquals(
            $filaSinFiltro['Comision'],
            $filaConFiltro['Comision'],
            'F4: la celda filtrada por tipo_juego NO es idéntica a la fila del ledger sin filtrar'
        );
    }

    // ==================================================
    // 5.1 — cuadreCaja: columna por entidad sin tocar columnas previas
    // ==================================================

    /**
     * S19 — cuadre-caja: la fila incluye Comision (liquidable propio de la
     * banca) y las columnas existentes (Venta, Pagados, Devoluciones,
     * Vencidos, Efectivo, PesoVenta, Participacion) y la barra de totales
     * quedan intactas.
     */
    public function test_cuadre_caja_incluye_comision_sin_alterar_columnas_existentes()
    {
        $super = $this->superUser();
        [$juego, $banca, $grupo, $taquilla] = $this->jerarquia('Cuadre');

        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 20);
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 40);

        $this->apuesta($juego->id, $taquilla->id, 100.0);

        $pago = Pago::create([
            'taquilla_id' => $taquilla->id,
            'amount_bs' => 100.0,
            'amount_usd' => 0.0,
            'exchange_rate_applied' => 36.50,
            'tipo' => 'egreso',
            'moneda' => 'bs',
            'concepto' => 'Pago de premio',
            'created_by' => $super->id,
        ]);
        // El egreso pertenece al rango del reporte (evita dependencia de now())
        $pago->forceFill(['created_at' => '2026-09-15 10:00:00'])->save();

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/reportes/cuadre-caja?fecha_desde=2026-09-01&fecha_hasta=2026-09-30');

        $response->assertStatus(200);
        $fila = $response->json('data.0');

        $this->assertArrayHasKey('Comision', $fila, 'La fila del cuadre debe exponer Comision');
        $this->assertEquals(100.0, $fila['Venta'], 'Venta intacta');
        $this->assertEquals(100.0, $fila['Pagados'], 'Pagados intacto');
        $this->assertEquals(0.0, $fila['Devoluciones'], 'Devoluciones intacto');
        $this->assertEquals(0.0, $fila['Vencidos'], 'Vencidos intacto');
        $this->assertEquals(0.0, $fila['Efectivo'], 'Efectivo intacto (100 − 100)');
        $this->assertEquals(10.0, $fila['Comision'], 'Banca = 100 × su tasa liquidable 10% (ya no rollup)');

        $totales = $response->json('totales');
        $this->assertEquals(10.0, $totales['Comision'], 'Total de comisión = suma de filas');
        $this->assertEquals(100.0, $totales['Venta'], 'Total de venta intacto');
        $this->assertEquals(0.0, $totales['Efectivo'], 'Total de efectivo intacto');
    }
}
