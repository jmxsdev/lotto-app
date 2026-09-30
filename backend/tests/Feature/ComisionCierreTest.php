<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\CierreCaja;
use App\Models\ExchangeRate;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Models\Taquilla;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * S5 (slice 4a) — desglose de comisión en el cierre/cuadre (D10, S20/S21).
 *
 * El cierre de una taquilla persiste y expone `comision_bs_equivalent`
 * (bs-equivalente del período, calculado con la capability comisiones a la
 * tasa liquidable de la taquilla) en:
 * - POST /api/v1/cierre (snapshot persistido en cierres_caja),
 * - GET /api/v1/cierre/actual (previsualización),
 * - GET /api/v1/cierre/semanal (rollup de los diarios).
 *
 * El desglose es ADITIVO: `total_efectivo_*`, arqueo y faltante/sobrante
 * permanecen intactos.
 */
class ComisionCierreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Carbon::setTestNow(Carbon::create(2026, 8, 12, 12, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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
            'name' => "Banca Cierre {$sufijo}",
            'code' => 'BC'.$codigo,
            'active' => true,
        ]);

        $grupo = Grupo::create([
            'name' => "Grupo Cierre {$sufijo}",
            'code' => 'GC'.$codigo,
            'banca_id' => $banca->id,
            'active' => true,
        ]);

        $taquilla = Taquilla::create([
            'name' => "Taquilla Cierre {$sufijo}",
            'code' => 'TC'.$codigo,
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);

        $juego = Juego::create([
            'name' => "Juego Cierre {$sufijo}",
            'slug' => 'juego-cierre-'.strtolower($sufijo).'-'.uniqid(),
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

    private function apuesta(int $juegoId, int $taquillaId, float $amountBs, string $estado = 'pendiente'): void
    {
        Apuesta::create([
            'taquilla_id' => $taquillaId,
            'juego_id' => $juegoId,
            'amount_bs' => $amountBs,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => $amountBs,
            'estado' => $estado,
            'fecha_hora' => '2026-08-12 10:00:00',
        ]);
    }

    private function crearCierre(User $user, int $taquillaId, array $payload = []): TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquillaId] + $payload);
    }

    // ==================================================
    // 5.3 — Desglose en el cierre (S20, D10)
    // ==================================================

    /**
     * S20 — el cierre persiste y expone la comisión del período a la tasa
     * liquidable de la taquilla.
     */
    public function test_cierre_persiste_comision_bs_equivalent()
    {
        $super = $this->superUser();
        [$juego, $banca, $grupo, $taquilla] = $this->jerarquia('Uno');

        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);
        $this->apuesta($juego->id, $taquilla->id, 100.0);

        $response = $this->crearCierre($super, $taquilla->id);

        $response->assertStatus(201);
        $this->assertSame($taquilla->id, $response->json('taquilla_id'));
        $this->assertEquals(100.0, (float) $response->json('total_ventas_bs'), 'Ventas intactas');
        $this->assertEquals(50.0, (float) $response->json('comision_bs_equivalent'), 'Comisión = 100 × tasa 50%');

        $this->assertDatabaseHas('cierres_caja', [
            'taquilla_id' => $taquilla->id,
            'total_ventas_bs' => '100.00',
            'comision_bs_equivalent' => '50.00',
        ]);
    }

    /**
     * D11 — el cierre respeta el tope acumulado (banca 10 + taquilla 100
     * ⇒ la taquilla liquida 90).
     */
    public function test_cierre_aplica_tope_acumulado_d11()
    {
        $super = $this->superUser();
        [$juego, $banca, $grupo, $taquilla] = $this->jerarquia('Dos');

        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 100);
        $this->apuesta($juego->id, $taquilla->id, 100.0);

        $response = $this->crearCierre($super, $taquilla->id);

        $response->assertStatus(201);
        $this->assertEquals(90.0, (float) $response->json('comision_bs_equivalent'), 'D11: taquilla liquida 90%');
    }

    /**
     * S20 — las apuestas anuladas no contribuyen a la comisión ni a las
     * ventas del cierre.
     */
    public function test_cierre_excluye_anuladas_de_la_comision()
    {
        $super = $this->superUser();
        [$juego, $banca, $grupo, $taquilla] = $this->jerarquia('Tres');

        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);
        $this->apuesta($juego->id, $taquilla->id, 100.0, 'anulada');

        $response = $this->crearCierre($super, $taquilla->id);

        $response->assertStatus(201);
        $this->assertEquals(0.0, (float) $response->json('total_ventas_bs'), 'Venta sin anuladas');
        $this->assertEquals(0.0, (float) $response->json('comision_bs_equivalent'), 'Comisión sin anuladas');
    }

    /**
     * S20 — sin tasas configuradas la comisión es 0.00 (fallback global
     * sin definir), sin romper el cierre.
     */
    public function test_cierre_sin_tasas_comision_0_00()
    {
        $super = $this->superUser();
        [$juego, $banca, $grupo, $taquilla] = $this->jerarquia('Cuatro');

        // Sin filas de juego_limites: tasa efectiva cae al default global NULL ⇒ 0.00
        $this->apuesta($juego->id, $taquilla->id, 100.0);

        $response = $this->crearCierre($super, $taquilla->id);

        $response->assertStatus(201);
        $this->assertEquals(100.0, (float) $response->json('total_ventas_bs'), 'Ventas intactas');
        $this->assertEquals(0.0, (float) $response->json('comision_bs_equivalent'));
    }

    /**
     * S20 — la previsualización (GET /cierre/actual) expone el desglose
     * sin persistir nada.
     */
    public function test_previsualizar_incluye_comision_bs_equivalent()
    {
        $super = $this->superUser();
        [$juego, $banca, $grupo, $taquilla] = $this->jerarquia('Cinco');

        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);
        $this->apuesta($juego->id, $taquilla->id, 100.0);

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/cierre/actual?taquilla_id='.$taquilla->id);

        $response->assertStatus(200);
        $this->assertSame($taquilla->id, $response->json('taquilla_id'));
        $this->assertEquals(100.0, (float) $response->json('total_ventas_bs'));
        $this->assertEquals(50.0, (float) $response->json('comision_bs_equivalent'));

        $this->assertSame(0, CierreCaja::count(), 'La previsualización no persiste nada');
    }

    /**
     * S21 — arqueo y faltante/sobrante intactos con el desglose aditivo.
     */
    public function test_arqueo_y_faltante_sobrante_intactos()
    {
        $super = $this->superUser();
        [$juego, $banca, $grupo, $taquilla] = $this->jerarquia('Seis');

        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);
        $this->apuesta($juego->id, $taquilla->id, 100.0);

        $response = $this->crearCierre($super, $taquilla->id, [
            'arqueo_efectivo_bs' => 150,
            'arqueo_efectivo_usd' => 20,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(100.0, (float) $response->json('total_efectivo_bs'), 'Efectivo = ventas − egresos');
        $this->assertEquals(150.0, (float) $response->json('arqueo_efectivo_bs'), 'Arqueo intacto');
        $this->assertEquals(50.0, (float) $response->json('faltante_sobrante_bs'), 'Faltante/sobrante = arqueo − efectivo');
        $this->assertEquals(20.0, (float) $response->json('arqueo_efectivo_usd'), 'Arqueo USD intacto');
        $this->assertEquals(50.0, (float) $response->json('comision_bs_equivalent'), 'Desglose aditivo presente');
    }

    /**
     * S20 — el rollup semanal agrega la comisión de los diarios y conserva
     * los totales existentes.
     */
    public function test_reporte_semanal_agrega_comision_de_los_diarios()
    {
        $super = $this->superUser();
        [$juego, $banca, $grupo, $taquilla] = $this->jerarquia('Siete');

        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);
        $this->apuesta($juego->id, $taquilla->id, 100.0);

        $this->crearCierre($super, $taquilla->id)->assertStatus(201);

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/cierre/semanal?fecha=2026-08-12');

        $response->assertStatus(200);
        $this->assertEquals(100.0, (float) $response->json('total_efectivo_bs'), 'Total efectivo intacto');
        $this->assertEquals(50.0, (float) $response->json('comision_bs_equivalent'), 'Semanal suma la comisión del diario');
        $this->assertSame(1, $response->json('ventana_cubierta.cierres_incluidos'));

        $diario = $response->json('cierres.0');
        $this->assertArrayHasKey('comision_bs_equivalent', $diario, 'El diario expone su comisión');
        $this->assertEquals(50.0, (float) $diario['comision_bs_equivalent']);
        $this->assertEquals(100.0, (float) $diario['total_efectivo_bs'], 'Totales del diario intactos');
    }
}
