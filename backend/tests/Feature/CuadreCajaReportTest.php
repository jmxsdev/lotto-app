<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\ExchangeRate;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Pago;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PR 1 — GET /api/reportes/cuadre-caja.
 *
 * Agregación del cuadre de caja por entidad: ventas, pagados, devoluciones,
 * vencidos, efectivo, peso de venta y participación, con alcance jerárquico
 * por rol y rango de fechas.
 */
class CuadreCajaReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);
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

    private function crearJerarquia(string $banca, string $grupo, string $taquilla): Taquilla
    {
        $banca = Banca::create(['name' => $banca, 'code' => strtoupper(substr($banca, 0, 6)), 'active' => true]);
        $grupo = Grupo::create(['name' => $grupo, 'code' => strtoupper(substr($grupo, 0, 6)), 'banca_id' => $banca->id, 'active' => true]);

        return Taquilla::create(['name' => $taquilla, 'code' => strtoupper(substr($taquilla, 0, 6)), 'grupo_id' => $grupo->id, 'active' => true]);
    }

    private function crearApuesta(Taquilla $taquilla, Juego $juego, float $monto, string $estado): Apuesta
    {
        return Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'amount_bs' => $monto,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => $monto,
            'estado' => $estado,
            'fecha_hora' => now()->subDays(1),
        ]);
    }

    private function crearPago(Taquilla $taquilla, User $user, string $tipo, float $monto): Pago
    {
        return Pago::create([
            'taquilla_id' => $taquilla->id,
            'amount_bs' => $monto,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'tipo' => $tipo,
            'moneda' => 'bs',
            'concepto' => $tipo === 'egreso' ? 'Pago de premio' : 'Devolución de dinero',
            'created_by' => $user->id,
        ]);
    }

    /**
     * 1.4.1 — test_cuadre_agrega_ventas_pagados_devoluciones_vencidos:
     * Apuestas normales y vencidas junto con pagos de egreso y devolución
     * deben agregarse por entidad con todas las columnas del cuadre.
     */
    public function test_cuadre_agrega_ventas_pagados_devoluciones_vencidos()
    {
        $super = $this->superUser();
        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = $this->crearJerarquia('Banca Alpha', 'Grupo Alpha', 'T-Alpha-1');

        // 1 apuesta normal + 1 vencida
        $this->crearApuesta($taquilla, $juego, 1000, 'pendiente');
        $this->crearApuesta($taquilla, $juego, 100, 'vencido');

        // 1 pago egreso + 1 pago devolucion
        $this->crearPago($taquilla, $super, 'egreso', 200);
        $this->crearPago($taquilla, $super, 'devolucion', 100);

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/reportes/cuadre-caja');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data, 'Debe haber 1 fila por banca');

        $fila = $data[0];
        $this->assertEquals('Banca Alpha', $fila['Entidad']);
        $this->assertEquals(1100, $fila['Venta'], 'Venta = apuestas no anuladas (1000 + 100)');
        $this->assertEquals(200, $fila['Pagados'], 'Pagados = pagos tipo egreso');
        $this->assertEquals(100, $fila['Devoluciones'], 'Devoluciones = pagos tipo devolucion');
        $this->assertEquals(100, $fila['Vencidos'], 'Vencidos = apuestas estado vencido');
        $this->assertEquals(700, $fila['Efectivo'], 'Efectivo = Venta - Pagados - Devoluciones - Vencidos');
        $this->assertEquals(100.0, $fila['PesoVenta'], 'PesoVenta = 100% con una sola entidad');
        $this->assertEquals(100.0, $fila['Participacion'], 'Participacion = mismo peso que PesoVenta');

        // Barra de totales: suma de cada columna
        $totales = $response->json('totales');
        $this->assertEquals(1100, $totales['Venta']);
        $this->assertEquals(200, $totales['Pagados']);
        $this->assertEquals(100, $totales['Devoluciones']);
        $this->assertEquals(100, $totales['Vencidos']);
        $this->assertEquals(700, $totales['Efectivo']);
        $this->assertEquals(100.0, $totales['PesoVenta']);
        $this->assertEquals(100.0, $totales['Participacion']);
    }

    /**
     * 1.4.2 — test_efectivo_formula_correcta:
     * Efectivo = Venta − Pagados − Devoluciones − Vencidos (600 = 1000 − 200 − 100 − 100).
     */
    public function test_efectivo_formula_correcta()
    {
        $super = $this->superUser();
        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = $this->crearJerarquia('Banca Efectivo', 'Grupo Efectivo', 'T-Efectivo');

        // Venta total 1000 (900 pendiente + 100 vencida)
        $this->crearApuesta($taquilla, $juego, 900, 'pendiente');
        $this->crearApuesta($taquilla, $juego, 100, 'vencido');

        $this->crearPago($taquilla, $super, 'egreso', 200);
        $this->crearPago($taquilla, $super, 'devolucion', 100);

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/reportes/cuadre-caja');

        $response->assertStatus(200);

        $fila = $response->json('data.0');
        $this->assertEquals(1000, $fila['Venta']);
        $this->assertEquals(200, $fila['Pagados']);
        $this->assertEquals(100, $fila['Devoluciones']);
        $this->assertEquals(100, $fila['Vencidos']);
        $this->assertEquals(600, $fila['Efectivo'], 'Efectivo debe ser 1000 - 200 - 100 - 100 = 600');
    }

    /**
     * 1.4.3 — test_cuadre_jerarquia_banca_solo_sus_grupos:
     * Un usuario con rol banca solo ve las entidades de su propia banca,
     * tanto en ventas como en pagos.
     */
    public function test_cuadre_jerarquia_banca_solo_sus_grupos()
    {
        $super = $this->superUser();
        $juego = Juego::where('slug', 'lotto-activo')->first();

        // Banca ajena al usuario (no debe aparecer)
        $taquillaAjena = $this->crearJerarquia('Banca Ajena', 'Grupo Ajena', 'T-Ajena');
        $this->crearApuesta($taquillaAjena, $juego, 5000, 'pendiente');
        $this->crearPago($taquillaAjena, $super, 'egreso', 300);

        // Banca propia del usuario banca@lotto.com (Banca Test del seed)
        $grupoPropio = Grupo::where('code', 'GT001')->first();
        $taquillaPropia = Taquilla::create([
            'name' => 'T-Banca-Propia',
            'code' => 'TBPP',
            'grupo_id' => $grupoPropio->id,
            'active' => true,
        ]);
        $this->crearApuesta($taquillaPropia, $juego, 500, 'pendiente');
        $this->crearPago($taquillaPropia, $super, 'egreso', 100);

        $bancaUser = User::where('email', 'banca@lotto.com')->first();
        $bancaUser->assignRole('banca');

        $response = $this->actingAs($bancaUser, 'sanctum')
            ->getJson('/api/reportes/cuadre-caja');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data, 'La banca solo debe ver su propia entidad');
        $this->assertEquals('Banca Test', $data[0]['Entidad']);
        $this->assertEquals(500, $data[0]['Venta'], 'Solo ventas de su propia banca');
        $this->assertEquals(100, $data[0]['Pagados'], 'Solo pagos de su propia banca');
        $this->assertEquals(400, $data[0]['Efectivo'], 'Efectivo de su propia banca');
    }

    /**
     * 1.4.4 — test_rango_vacio_retorna_200:
     * Un rango de fechas sin datos debe responder 200 con data vacía y totales en cero.
     */
    public function test_rango_vacio_retorna_200()
    {
        $super = $this->superUser();
        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = $this->crearJerarquia('Banca Empty', 'Grupo Empty', 'T-Empty');

        // Apuesta fuera del rango de consulta
        Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'amount_bs' => 1000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 1000,
            'estado' => 'pendiente',
            'fecha_hora' => '2025-01-15',
        ]);

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/reportes/cuadre-caja?fecha_desde=2026-01-01&fecha_hasta=2026-01-31');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertEmpty($data, 'El arreglo debe estar vacío para un rango sin datos');

        $totales = $response->json('totales');
        foreach (['Venta', 'Pagados', 'Devoluciones', 'Vencidos', 'Efectivo', 'PesoVenta', 'Participacion'] as $columna) {
            $this->assertEquals(0, $totales[$columna], "Total de {$columna} debe ser 0 sin datos");
        }
    }
}
