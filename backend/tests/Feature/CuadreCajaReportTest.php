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
 * PR 1–2 — GET /api/reportes/cuadre-caja.
 *
 * Agregación del cuadre de caja por entidad: ventas, pagados, devoluciones,
 * vencidos, efectivo, peso de venta y participación, con alcance jerárquico
 * por rol y rango de fechas. PR 2: niveles grupo/agencia, barra de totales,
 * filtro de moneda (bs/usd/mixto), alcance del rol grupo y POST /api/cierre
 * intacto.
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

    private function crearApuesta(Taquilla $taquilla, Juego $juego, float $monto, string $estado, float $amountUsd = 0): Apuesta
    {
        return Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'amount_bs' => $monto,
            'amount_usd' => $amountUsd,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => $monto + ($amountUsd * 36.50),
            'estado' => $estado,
            'fecha_hora' => now()->subDays(1),
        ]);
    }

    private function crearPago(Taquilla $taquilla, User $user, string $tipo, float $monto, float $amountUsd = 0, string $moneda = 'bs'): Pago
    {
        return Pago::create([
            'taquilla_id' => $taquilla->id,
            'amount_bs' => $monto,
            'amount_usd' => $amountUsd,
            'exchange_rate_applied' => 36.50,
            'tipo' => $tipo,
            'moneda' => $moneda,
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

    // ==================================================
    // PR 2 (1.5) — nivel grupo/agencia, totales, moneda, cierre intacto, alcance grupo
    // ==================================================

    /**
     * 1.5.1 — test_cuadre_nivel_grupo_agrupa_por_grupos:
     * Dos grupos bajo una misma banca deben aparecer como filas separadas
     * con nivel=grupo, incluyendo pagos agrupados por grupo.
     */
    public function test_cuadre_nivel_grupo_agrupa_por_grupos()
    {
        $super = $this->superUser();
        $juego = Juego::where('slug', 'lotto-activo')->first();

        $banca = Banca::create(['name' => 'Banca Multi', 'code' => 'BAMUL', 'active' => true]);
        $grupoUno = Grupo::create(['name' => 'Grupo Uno', 'code' => 'GRPUN', 'banca_id' => $banca->id, 'active' => true]);
        $grupoDos = Grupo::create(['name' => 'Grupo Dos', 'code' => 'GRPDO', 'banca_id' => $banca->id, 'active' => true]);

        $taquillaUno = Taquilla::create(['name' => 'T-Uno', 'code' => 'TUNO1', 'grupo_id' => $grupoUno->id, 'active' => true]);
        $taquillaDos = Taquilla::create(['name' => 'T-Dos', 'code' => 'TDOS2', 'grupo_id' => $grupoDos->id, 'active' => true]);

        $this->crearApuesta($taquillaUno, $juego, 600, 'pendiente');
        $this->crearApuesta($taquillaDos, $juego, 400, 'pendiente');
        $this->crearPago($taquillaUno, $super, 'egreso', 100);

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/reportes/cuadre-caja?nivel=grupo');

        $response->assertStatus(200);
        $porEntidad = collect($response->json('data'))->keyBy('Entidad');

        $this->assertCount(2, $porEntidad, 'Debe haber 2 filas, una por grupo');
        $this->assertTrue($porEntidad->has('Grupo Uno') && $porEntidad->has('Grupo Dos'), 'Ambos grupos deben estar presentes');

        $this->assertEquals(600, $porEntidad['Grupo Uno']['Venta']);
        $this->assertEquals(100, $porEntidad['Grupo Uno']['Pagados'], 'Pagos agrupados por grupo');
        $this->assertEquals(500, $porEntidad['Grupo Uno']['Efectivo']);
        $this->assertEquals(60.0, $porEntidad['Grupo Uno']['PesoVenta']);

        $this->assertEquals(400, $porEntidad['Grupo Dos']['Venta']);
        $this->assertEquals(0, $porEntidad['Grupo Dos']['Pagados']);
        $this->assertEquals(400, $porEntidad['Grupo Dos']['Efectivo']);
        $this->assertEquals(40.0, $porEntidad['Grupo Dos']['PesoVenta']);

        $this->assertEquals(1000, $response->json('totales.Venta'));
        $this->assertEquals(100.0, $response->json('totales.PesoVenta'));
    }

    /**
     * 1.5.2 — test_cuadre_totales_suman_columnas:
     * La barra de totales debe ser la suma exacta de cada columna
     * sobre todas las filas visibles.
     */
    public function test_cuadre_totales_suman_columnas()
    {
        $super = $this->superUser();
        $juego = Juego::where('slug', 'lotto-activo')->first();

        // Jerarquías con códigos explícitos (crearJerarquía colisionaría en 'BANCA ')
        $bancaA = Banca::create(['name' => 'Banca Total A', 'code' => 'BTOTA', 'active' => true]);
        $grupoA = Grupo::create(['name' => 'Grupo Total A', 'code' => 'GRTA1', 'banca_id' => $bancaA->id, 'active' => true]);
        $taquillaA = Taquilla::create(['name' => 'T-Total-A', 'code' => 'TTOTA', 'grupo_id' => $grupoA->id, 'active' => true]);

        $bancaB = Banca::create(['name' => 'Banca Total B', 'code' => 'BTOTB', 'active' => true]);
        $grupoB = Grupo::create(['name' => 'Grupo Total B', 'code' => 'GRTB1', 'banca_id' => $bancaB->id, 'active' => true]);
        $taquillaB = Taquilla::create(['name' => 'T-Total-B', 'code' => 'TTOTB', 'grupo_id' => $grupoB->id, 'active' => true]);

        // Banca A: 600 pendiente + 150 vencida; egreso 200; devolución 50
        $this->crearApuesta($taquillaA, $juego, 600, 'pendiente');
        $this->crearApuesta($taquillaA, $juego, 150, 'vencido');
        $this->crearPago($taquillaA, $super, 'egreso', 200);
        $this->crearPago($taquillaA, $super, 'devolucion', 50);

        // Banca B: 500 pendiente
        $this->crearApuesta($taquillaB, $juego, 500, 'pendiente');

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/reportes/cuadre-caja');

        $response->assertStatus(200);
        $data = $response->json('data');
        $totales = $response->json('totales');

        $this->assertCount(2, $data, 'Debe haber 2 filas, una por banca');

        // Cada total debe ser la suma de las filas visibles
        foreach (['Venta', 'Pagados', 'Devoluciones', 'Vencidos', 'Efectivo', 'PesoVenta', 'Participacion'] as $columna) {
            $suma = array_sum(array_column($data, $columna));
            $this->assertEqualsWithDelta($suma, $totales[$columna], 0.001, "Total de {$columna} debe sumar las filas visibles");
        }

        // Valores concretos
        $this->assertEquals(1250, $totales['Venta']);
        $this->assertEquals(200, $totales['Pagados']);
        $this->assertEquals(50, $totales['Devoluciones']);
        $this->assertEquals(150, $totales['Vencidos']);
        $this->assertEquals(850, $totales['Efectivo']);
        $this->assertEquals(100.0, $totales['PesoVenta']);
        $this->assertEquals(100.0, $totales['Participacion']);

        $porEntidad = collect($data)->keyBy('Entidad');
        $this->assertEquals(750, $porEntidad['Banca Total A']['Venta']);
        $this->assertEquals(350, $porEntidad['Banca Total A']['Efectivo']);
        $this->assertEquals(60.0, $porEntidad['Banca Total A']['PesoVenta']);
        $this->assertEquals(500, $porEntidad['Banca Total B']['Venta']);
        $this->assertEquals(500, $porEntidad['Banca Total B']['Efectivo']);
        $this->assertEquals(40.0, $porEntidad['Banca Total B']['PesoVenta']);
    }

    /**
     * 1.5.3 — test_cuadre_filtro_moneda_bs:
     * Con moneda=bs solo cuentan las apuestas y pagos en BS;
     * los montos USD quedan excluidos.
     */
    public function test_cuadre_filtro_moneda_bs()
    {
        $super = $this->superUser();
        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = $this->crearJerarquia('Banca Moneda BS', 'Grupo Moneda BS', 'T-Moneda-BS');

        $this->crearApuesta($taquilla, $juego, 1000, 'pendiente');       // BS: cuenta
        $this->crearApuesta($taquilla, $juego, 0, 'pendiente', 100);     // USD: excluida
        $this->crearPago($taquilla, $super, 'egreso', 200);              // BS
        $this->crearPago($taquilla, $super, 'egreso', 0, 50, 'usd');     // USD: no suma en BS

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/reportes/cuadre-caja?moneda=bs');

        $response->assertStatus(200);
        $fila = $response->json('data.0');

        $this->assertEquals(1000, $fila['Venta'], 'Solo la apuesta BS debe contar');
        $this->assertEquals(200, $fila['Pagados'], 'Solo el pago BS debe contar');
        $this->assertEquals(0, $fila['Devoluciones']);
        $this->assertEquals(0, $fila['Vencidos']);
        $this->assertEquals(800, $fila['Efectivo']);
    }

    /**
     * 1.5.4 — test_cuadre_filtro_moneda_usd:
     * Con moneda=usd solo cuentan las apuestas y pagos en USD;
     * los montos BS quedan excluidos.
     */
    public function test_cuadre_filtro_moneda_usd()
    {
        $super = $this->superUser();
        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = $this->crearJerarquia('Banca Moneda USD', 'Grupo Moneda USD', 'T-Moneda-USD');

        $this->crearApuesta($taquilla, $juego, 1000, 'pendiente');       // BS: excluida
        $this->crearApuesta($taquilla, $juego, 0, 'pendiente', 100);     // USD: cuenta
        $this->crearPago($taquilla, $super, 'egreso', 200);              // BS: no suma en USD
        $this->crearPago($taquilla, $super, 'egreso', 0, 50, 'usd');     // USD

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/reportes/cuadre-caja?moneda=usd');

        $response->assertStatus(200);
        $fila = $response->json('data.0');

        $this->assertEquals(100, $fila['Venta'], 'Solo la apuesta USD debe contar');
        $this->assertEquals(50, $fila['Pagados'], 'Solo el pago USD debe contar');
        $this->assertEquals(0, $fila['Devoluciones']);
        $this->assertEquals(0, $fila['Vencidos']);
        $this->assertEquals(50, $fila['Efectivo']);
    }

    /**
     * 1.5.5 — test_cuadre_filtro_moneda_mixto:
     * Con moneda=mixto solo cuentan las apuestas con ambas monedas,
     * expresadas en su equivalente en BS (tasa 36.50).
     */
    public function test_cuadre_filtro_moneda_mixto()
    {
        $super = $this->superUser();
        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = $this->crearJerarquia('Banca Moneda Mixto', 'Grupo Moneda Mixto', 'T-Moneda-Mixto');

        $this->crearApuesta($taquilla, $juego, 1000, 'pendiente', 100);  // mixta: cuenta (1000 + 100×36.50 = 4650)
        $this->crearApuesta($taquilla, $juego, 500, 'pendiente');        // solo BS: excluida
        $this->crearPago($taquilla, $super, 'egreso', 200, 10, 'mixto'); // 200 + 10×36.50 = 565

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/reportes/cuadre-caja?moneda=mixto');

        $response->assertStatus(200);
        $fila = $response->json('data.0');

        $this->assertEquals(4650, $fila['Venta'], 'Venta = equivalente en BS de la apuesta mixta');
        $this->assertEquals(565, $fila['Pagados'], 'Pago mixto convertido con exchange_rate_applied');
        $this->assertEquals(4085, $fila['Efectivo']);
    }

    /**
     * 1.5.6 — test_cuadre_nivel_agencia_agrupa_por_taquillas:
     * Con nivel=agencia las filas se agrupan por taquilla (agencia),
     * incluyendo pagos por taquilla.
     */
    public function test_cuadre_nivel_agencia_agrupa_por_taquillas()
    {
        $super = $this->superUser();
        $juego = Juego::where('slug', 'lotto-activo')->first();

        $banca = Banca::create(['name' => 'Banca Agencias', 'code' => 'BAAGE', 'active' => true]);
        $grupo = Grupo::create(['name' => 'Grupo Agencias', 'code' => 'GRAGE', 'banca_id' => $banca->id, 'active' => true]);

        $taquillaUno = Taquilla::create(['name' => 'T-Agencia-1', 'code' => 'TAGE1', 'grupo_id' => $grupo->id, 'active' => true]);
        $taquillaDos = Taquilla::create(['name' => 'T-Agencia-2', 'code' => 'TAGE2', 'grupo_id' => $grupo->id, 'active' => true]);

        $this->crearApuesta($taquillaUno, $juego, 600, 'pendiente');
        $this->crearApuesta($taquillaDos, $juego, 400, 'pendiente');
        $this->crearPago($taquillaUno, $super, 'devolucion', 50);

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/reportes/cuadre-caja?nivel=agencia');

        $response->assertStatus(200);
        $porEntidad = collect($response->json('data'))->keyBy('Entidad');

        $this->assertCount(2, $porEntidad, 'Debe haber 2 filas, una por taquilla');
        $this->assertTrue($porEntidad->has('T-Agencia-1') && $porEntidad->has('T-Agencia-2'), 'Ambas taquillas deben estar presentes');

        $this->assertEquals(600, $porEntidad['T-Agencia-1']['Venta']);
        $this->assertEquals(50, $porEntidad['T-Agencia-1']['Devoluciones'], 'Pagos agrupados por taquilla');
        $this->assertEquals(550, $porEntidad['T-Agencia-1']['Efectivo']);

        $this->assertEquals(400, $porEntidad['T-Agencia-2']['Venta']);
        $this->assertEquals(400, $porEntidad['T-Agencia-2']['Efectivo']);

        $this->assertEquals(1000, $response->json('totales.Venta'));
        $this->assertEquals(100.0, $response->json('totales.PesoVenta'));
    }

    /**
     * 1.5.7 — test_post_cierre_sigue_intacto:
     * El endpoint durmiente POST /api/cierre debe seguir disponible y
     * funcional: validación 422 sin taquilla_id y creación 201 con una
     * taquilla válida (nunca 404).
     */
    public function test_post_cierre_sigue_intacto()
    {
        $super = $this->superUser();
        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = $this->crearJerarquia('Banca Cierre', 'Grupo Cierre', 'T-Cierre');
        $this->crearApuesta($taquilla, $juego, 500, 'pendiente');

        // Sin taquilla_id: validación del controlador, no 404
        $this->actingAs($super, 'sanctum')
            ->postJson('/api/cierre')
            ->assertStatus(422)
            ->assertJsonValidationErrors('taquilla_id');

        // Con taquilla válida: el cierre se crea con sus totales (comportamiento previo)
        $response = $this->actingAs($super, 'sanctum')
            ->postJson('/api/cierre', ['taquilla_id' => $taquilla->id]);

        $response->assertStatus(201)
            ->assertJsonPath('taquilla_id', $taquilla->id);
        $this->assertEquals(500.0, (float) $response->json('total_ventas_bs'));
        $this->assertDatabaseHas('cierres_caja', ['taquilla_id' => $taquilla->id]);
    }

    /**
     * 1.5.8 — test_cuadre_usuario_grupo_solo_sus_agencias:
     * Un usuario con rol grupo solo ve las taquillas de su propio grupo
     * en el cuadre con nivel=agencia; las agencias ajenas quedan fuera.
     */
    public function test_cuadre_usuario_grupo_solo_sus_agencias()
    {
        $super = $this->superUser();
        $juego = Juego::where('slug', 'lotto-activo')->first();

        // Agencia propia: dentro del Grupo Test (GT001) del usuario grupo@lotto.com
        $grupoPropio = Grupo::where('code', 'GT001')->first();
        $taquillaPropia = Taquilla::create([
            'name' => 'T-Grupo-Propia',
            'code' => 'TGRPP',
            'grupo_id' => $grupoPropio->id,
            'active' => true,
        ]);
        $this->crearApuesta($taquillaPropia, $juego, 500, 'pendiente');

        // Agencia ajena: otra banca, no debe aparecer
        $taquillaAjena = $this->crearJerarquia('Banca Ajena G', 'Grupo Ajena G', 'T-Ajena-G');
        $this->crearApuesta($taquillaAjena, $juego, 5000, 'pendiente');

        $grupoUser = User::where('email', 'grupo@lotto.com')->first();
        $grupoUser->assignRole('grupo');

        $response = $this->actingAs($grupoUser, 'sanctum')
            ->getJson('/api/reportes/cuadre-caja?nivel=agencia');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data, 'El grupo solo debe ver las agencias de su propio grupo');
        $this->assertEquals('T-Grupo-Propia', $data[0]['Entidad']);
        $this->assertEquals(500, $data[0]['Venta'], 'Solo ventas de sus propias agencias');
        $this->assertEquals(500, $data[0]['Efectivo']);
    }
}
