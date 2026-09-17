<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\CierreCaja;
use App\Models\ExchangeRate;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Pago;
use App\Models\Taquilla;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PR 6 — cierre-caja backend.
 *
 * POST /api/v1/cierre crea el cierre con totales agregados del período
 * [último cierre | primera apuesta, ahora) y exige tasa de cambio activa.
 * GET /api/v1/cierre y GET /api/v1/cierre/{id} respetan el alcance jerárquico.
 */
class CierreCajaTest extends TestCase
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

        return $super;
    }

    private function masterUser(): User
    {
        $master = User::where('email', 'master@lotto.com')->first();
        $master->assignRole('master');
        // F2: el master administra la banca sembrada (ya no es global)
        Banca::where('code', 'BT001')->update(['master_id' => $master->id]);

        return $master;
    }

    private function bancaUser(): User
    {
        $user = User::where('email', 'banca@lotto.com')->first();
        $user->assignRole('banca');

        return $user;
    }

    private function grupoUser(): User
    {
        $user = User::where('email', 'grupo@lotto.com')->first();
        $user->assignRole('grupo');

        return $user;
    }

    private function taquillaUser(): User
    {
        $user = User::where('email', 'taquilla@lotto.com')->first();
        $user->assignRole('taquilla');

        return $user;
    }

    private function grupoSeeded(): Grupo
    {
        return Grupo::where('code', 'GT001')->first();
    }

    private function taquillaSeeded(): Taquilla
    {
        return Taquilla::where('code', 'TT001')->first();
    }

    private function crearTaquilla(string $code, int $grupoId): Taquilla
    {
        return Taquilla::create([
            'name' => 'Agencia '.$code,
            'code' => $code,
            'grupo_id' => $grupoId,
            'activation_code' => $code.'-CODE',
            'active' => true,
            'created_by' => $this->superUser()->id,
        ]);
    }

    private function crearApuesta(Taquilla $taquilla, array $overrides = []): Apuesta
    {
        $juego = Juego::where('slug', 'lotto-activo')->first();

        return Apuesta::create(array_merge([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'combinacion' => json_encode(['animal' => 'Perro']),
            'amount_bs' => 0,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 0,
            'estado' => 'pendiente',
            'fecha_hora' => now(),
            'sorteo_hora' => now()->addHour(),
        ], $overrides));
    }

    private function crearPago(Taquilla $taquilla, array $overrides = []): Pago
    {
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $pago = new Pago(array_merge([
            'taquilla_id' => $taquilla->id,
            'amount_bs' => 0,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'tipo' => 'egreso',
            'moneda' => 'bs',
            'concepto' => 'Pago de premio',
            'created_by' => $this->superUser()->id,
        ], $overrides));

        if ($createdAt) {
            $pago->forceFill(['created_at' => $createdAt]);
        }

        $pago->save();

        return $pago;
    }

    private function crearCierre(Taquilla $taquilla, array $overrides = []): CierreCaja
    {
        return CierreCaja::create(array_merge([
            'taquilla_id' => $taquilla->id,
            'fecha_inicio' => now()->subHours(4),
            'fecha_fin' => now()->subHour(),
            'total_ventas_bs' => 100,
            'total_ventas_usd' => 0,
            'total_ventas_bs_equivalent' => 100,
            'total_egresos_bs' => 0,
            'total_egresos_usd' => 0,
            'total_efectivo_bs' => 100,
            'total_efectivo_usd' => 0,
            'exchange_rate_cierre' => 36.50,
            'created_by' => $this->superUser()->id,
        ], $overrides));
    }

    /**
     * Laravel serializa las fechas en ISO-8601 UTC; comparar en la zona de la app.
     */
    private function fechaJson(?string $fecha): Carbon
    {
        return Carbon::parse($fecha)->setTimezone(config('app.timezone'));
    }

    // ==================================================
    // POST /api/v1/cierre — creación y agregados
    // ==================================================

    public function test_taquilla_puede_crear_cierre_de_su_propia_agencia()
    {
        $taquilla = $this->taquillaSeeded();
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF', 'active' => true]);
        $taquillaUser = $this->taquillaUser();

        $this->crearApuesta($taquilla, ['amount_bs' => 200, 'total_bs_equivalent' => 200, 'fecha_hora' => now()->subHour()]);
        $this->crearApuesta($taquilla, ['amount_usd' => 5, 'total_bs_equivalent' => 182.5, 'fecha_hora' => now()->subMinutes(30)]);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/v1/cierre');

        $response->assertStatus(201)
            ->assertJsonPath('taquilla_id', $taquilla->id)
            ->assertJsonPath('created_by', $taquillaUser->id);

        $cierre = $response->json();

        $this->assertEquals(200.0, (float) $cierre['total_ventas_bs']);
        $this->assertEquals(5.0, (float) $cierre['total_ventas_usd']);
        $this->assertEquals(382.5, (float) $cierre['total_ventas_bs_equivalent']);
        $this->assertEquals(36.5, (float) $cierre['exchange_rate_cierre']);

        $this->assertDatabaseHas('cierres_caja', [
            'taquilla_id' => $taquilla->id,
            'created_by' => $taquillaUser->id,
        ]);
    }

    public function test_cierre_agrega_apuestas_y_egresos_del_periodo()
    {
        $taquilla = $this->crearTaquilla('TTC01', $this->grupoSeeded()->id);

        // Apuestas del período: 3 válidas + 1 anulada (excluida)
        $this->crearApuesta($taquilla, ['amount_bs' => 100, 'total_bs_equivalent' => 100, 'fecha_hora' => now()->subHour()]);
        $this->crearApuesta($taquilla, ['amount_usd' => 10, 'total_bs_equivalent' => 365, 'fecha_hora' => now()->subHour()]);
        $this->crearApuesta($taquilla, ['amount_bs' => 50, 'amount_usd' => 5, 'total_bs_equivalent' => 232.5, 'fecha_hora' => now()->subMinutes(30)]);
        $this->crearApuesta($taquilla, ['amount_bs' => 1000, 'total_bs_equivalent' => 1000, 'estado' => 'anulada', 'fecha_hora' => now()->subMinutes(20)]);

        // Pagos del período: egreso + devolución suman; ingreso no cuenta
        $this->crearPago($taquilla, ['tipo' => 'egreso', 'amount_bs' => 30, 'amount_usd' => 2, 'created_at' => now()->subMinutes(5)]);
        $this->crearPago($taquilla, ['tipo' => 'devolucion', 'amount_bs' => 10, 'amount_usd' => 1, 'created_at' => now()->subMinutes(4)]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'amount_bs' => 999, 'created_at' => now()->subMinutes(3)]);

        // Pago anterior al inicio del período (primera apuesta): excluido
        $this->crearPago($taquilla, ['tipo' => 'egreso', 'amount_bs' => 700, 'created_at' => now()->subHours(10)]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);

        $response->assertStatus(201);

        $cierre = $response->json();

        // Ventas: 100+50 bs, 10+5 usd, equivalentes 100+365+232.5
        $this->assertEquals(150.0, (float) $cierre['total_ventas_bs']);
        $this->assertEquals(15.0, (float) $cierre['total_ventas_usd']);
        $this->assertEquals(697.5, (float) $cierre['total_ventas_bs_equivalent']);

        // Egresos: egreso 30+devolución 10 bs, 2+1 usd (ingreso y fuera de período excluidos)
        $this->assertEquals(40.0, (float) $cierre['total_egresos_bs']);
        $this->assertEquals(3.0, (float) $cierre['total_egresos_usd']);

        // Efectivo = ventas - egresos
        $this->assertEquals(110.0, (float) $cierre['total_efectivo_bs']);
        $this->assertEquals(12.0, (float) $cierre['total_efectivo_usd']);

        $this->assertEquals(36.5, (float) $cierre['exchange_rate_cierre']);

        // El período inicia en la primera apuesta
        $this->assertEquals(
            now()->subHour()->format('Y-m-d H:i'),
            $this->fechaJson($cierre['fecha_inicio'])->format('Y-m-d H:i')
        );
    }

    public function test_fecha_inicio_continua_desde_el_cierre_anterior()
    {
        $taquilla = $this->crearTaquilla('TTC02', $this->grupoSeeded()->id);

        $this->crearCierre($taquilla, [
            'fecha_inicio' => now()->subDay(),
            'fecha_fin' => now()->subHours(2),
        ]);

        $this->crearApuesta($taquilla, ['amount_bs' => 300, 'total_bs_equivalent' => 300, 'fecha_hora' => now()->subHour()]);

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);

        $response->assertStatus(201);

        $cierre = $response->json();

        $this->assertEquals(
            now()->subHours(2)->format('Y-m-d H:i'),
            $this->fechaJson($cierre['fecha_inicio'])->format('Y-m-d H:i')
        );

        // Solo cuenta la apuesta posterior al cierre anterior
        $this->assertEquals(300.0, (float) $cierre['total_ventas_bs']);
    }

    public function test_cierre_sin_tasa_activa_usa_fallback_historica()
    {
        // La tasa sembrada deja de estar activa pero queda como histórica:
        // el cierre usa el fallback (última por reference_date) en vez de 422.
        ExchangeRate::query()->update(['is_active' => false]);

        $taquilla = $this->taquillaSeeded();

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);

        $response->assertStatus(201);

        $this->assertEquals(36.5, (float) $response->json('exchange_rate_cierre'));

        $this->assertDatabaseHas('cierres_caja', [
            'taquilla_id' => $taquilla->id,
            'exchange_rate_cierre' => 36.5,
        ]);
    }

    public function test_fallback_usa_ultima_tasa_historica()
    {
        ExchangeRate::query()->delete();
        $super = $this->superUser();

        ExchangeRate::create([
            'rate' => 35.00,
            'base_currency' => 'USD',
            'reference_date' => now()->subDays(2),
            'set_by' => $super->id,
            'is_active' => false,
        ]);
        ExchangeRate::create([
            'rate' => 38.00,
            'base_currency' => 'USD',
            'reference_date' => now()->subDay(),
            'set_by' => $super->id,
            'is_active' => false,
        ]);

        $taquilla = $this->taquillaSeeded();

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);

        $response->assertStatus(201);

        $this->assertEquals(38.0, (float) $response->json('exchange_rate_cierre'));
    }

    public function test_sin_tasa_alguna_responde_422()
    {
        ExchangeRate::query()->delete();

        $taquilla = $this->taquillaSeeded();

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'No hay tasa de cambio activa para realizar el cierre.');

        $this->assertDatabaseMissing('cierres_caja', ['taquilla_id' => $taquilla->id]);
    }

    // ==================================================
    // POST /api/v1/cierre — arqueo físico y diferencia
    // ==================================================

    public function test_cierre_con_arqueo_persiste_contado_y_diferencia()
    {
        $taquilla = $this->crearTaquilla('TTC08', $this->grupoSeeded()->id);

        $ap1 = $this->crearApuesta($taquilla, ['amount_bs' => 1000, 'total_bs_equivalent' => 1000, 'fecha_hora' => now()->subHour()]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $ap1->id, 'amount_bs' => 1000, 'metodo_pago' => 'efectivo']);
        $ap2 = $this->crearApuesta($taquilla, ['amount_bs' => 300, 'total_bs_equivalent' => 300, 'fecha_hora' => now()->subHour()]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $ap2->id, 'amount_bs' => 300, 'metodo_pago' => 'transferencia']);
        $ap3 = $this->crearApuesta($taquilla, ['amount_bs' => 150, 'total_bs_equivalent' => 150, 'fecha_hora' => now()->subHour()]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $ap3->id, 'amount_bs' => 150, 'metodo_pago' => 'pago_movil']);
        $ap4 = $this->crearApuesta($taquilla, ['amount_bs' => 50, 'total_bs_equivalent' => 50, 'fecha_hora' => now()->subHour()]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $ap4->id, 'amount_bs' => 50, 'metodo_pago' => 'punto_venta']);
        $ap5 = $this->crearApuesta($taquilla, ['amount_usd' => 30, 'total_bs_equivalent' => 1095, 'fecha_hora' => now()->subHour()]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $ap5->id, 'amount_usd' => 30, 'metodo_pago' => 'efectivo']);

        $this->crearPago($taquilla, ['tipo' => 'egreso', 'amount_bs' => 30, 'metodo_pago' => 'transferencia', 'created_at' => now()->subMinutes(5)]);
        $this->crearPago($taquilla, ['tipo' => 'egreso', 'amount_bs' => 10, 'metodo_pago' => 'efectivo', 'created_at' => now()->subMinutes(4)]);
        $this->crearPago($taquilla, ['tipo' => 'egreso', 'amount_usd' => 3, 'metodo_pago' => 'efectivo', 'created_at' => now()->subMinutes(3)]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/cierre', [
                'taquilla_id' => $taquilla->id,
                'arqueo_efectivo_bs' => 1450,
                'arqueo_efectivo_usd' => 30,
            ]);

        $response->assertStatus(201);

        $cierre = $response->json();

        $this->assertEquals(1500.0, (float) $cierre['total_ventas_bs']);
        $this->assertEquals(30.0, (float) $cierre['total_ventas_usd']);
        $this->assertEquals(2595.0, (float) $cierre['total_ventas_bs_equivalent']);
        $this->assertEquals(40.0, (float) $cierre['total_egresos_bs']);
        $this->assertEquals(3.0, (float) $cierre['total_egresos_usd']);
        $this->assertEquals(1460.0, (float) $cierre['total_efectivo_bs']);
        $this->assertEquals(27.0, (float) $cierre['total_efectivo_usd']);

        $this->assertEquals(1450.0, (float) $cierre['arqueo_efectivo_bs']);
        $this->assertEquals(30.0, (float) $cierre['arqueo_efectivo_usd']);
        $this->assertEquals(-10.0, (float) $cierre['faltante_sobrante_bs']);
        $this->assertEquals(3.0, (float) $cierre['faltante_sobrante_usd']);

        $this->assertDatabaseHas('cierres_caja', [
            'taquilla_id' => $taquilla->id,
            'arqueo_efectivo_bs' => 1450.00,
            'arqueo_efectivo_usd' => 30.00,
            'faltante_sobrante_bs' => -10.00,
            'faltante_sobrante_usd' => 3.00,
        ]);
    }

    public function test_faltante_es_negativo()
    {
        $taquilla = $this->crearTaquilla('TTC09', $this->grupoSeeded()->id);
        $ap = $this->crearApuesta($taquilla, ['amount_bs' => 1000, 'total_bs_equivalent' => 1000, 'fecha_hora' => now()->subHour()]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $ap->id, 'amount_bs' => 1000, 'metodo_pago' => 'efectivo']);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/cierre', [
                'taquilla_id' => $taquilla->id,
                'arqueo_efectivo_bs' => 900,
            ]);

        $response->assertStatus(201);

        $this->assertEquals(1000.0, (float) $response->json('total_efectivo_bs'));
        $this->assertEquals(-100.0, (float) $response->json('faltante_sobrante_bs'));

        $this->assertDatabaseHas('cierres_caja', [
            'taquilla_id' => $taquilla->id,
            'faltante_sobrante_bs' => -100.00,
        ]);
    }

    public function test_sobrante_es_positivo()
    {
        $taquilla = $this->crearTaquilla('TTC10', $this->grupoSeeded()->id);
        $ap = $this->crearApuesta($taquilla, ['amount_bs' => 1000, 'total_bs_equivalent' => 1000, 'fecha_hora' => now()->subHour()]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $ap->id, 'amount_bs' => 1000, 'metodo_pago' => 'efectivo']);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/cierre', [
                'taquilla_id' => $taquilla->id,
                'arqueo_efectivo_bs' => 1100,
            ]);

        $response->assertStatus(201);

        $this->assertEquals(1000.0, (float) $response->json('total_efectivo_bs'));
        $this->assertEquals(100.0, (float) $response->json('faltante_sobrante_bs'));

        $this->assertDatabaseHas('cierres_caja', [
            'taquilla_id' => $taquilla->id,
            'faltante_sobrante_bs' => 100.00,
        ]);
    }

    public function test_cierre_sin_arqueo_deja_campos_nulos()
    {
        $taquilla = $this->crearTaquilla('TTC11', $this->grupoSeeded()->id);
        $ap = $this->crearApuesta($taquilla, ['amount_bs' => 500, 'total_bs_equivalent' => 500, 'fecha_hora' => now()->subHour()]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $ap->id, 'amount_bs' => 500, 'metodo_pago' => 'efectivo']);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);

        $response->assertStatus(201)
            ->assertJsonPath('arqueo_efectivo_bs', null)
            ->assertJsonPath('arqueo_efectivo_usd', null)
            ->assertJsonPath('faltante_sobrante_bs', null)
            ->assertJsonPath('faltante_sobrante_usd', null);

        $this->assertDatabaseHas('cierres_caja', [
            'taquilla_id' => $taquilla->id,
            'arqueo_efectivo_bs' => null,
            'arqueo_efectivo_usd' => null,
            'faltante_sobrante_bs' => null,
            'faltante_sobrante_usd' => null,
        ]);
    }

    public function test_arqueo_negativo_responde_422()
    {
        $taquilla = $this->taquillaSeeded();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/cierre', [
                'taquilla_id' => $taquilla->id,
                'arqueo_efectivo_bs' => -50,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('arqueo_efectivo_bs');

        $this->assertDatabaseMissing('cierres_caja', ['taquilla_id' => $taquilla->id]);
    }

    // ==================================================
    // POST /api/v1/cierre — desglose por método de pago
    // ==================================================

    public function test_desglose_por_metodo_suma_el_total_bs()
    {
        $taquilla = $this->crearTaquilla('TTC12', $this->grupoSeeded()->id);

        $ap1 = $this->crearApuesta($taquilla, ['amount_bs' => 1000, 'total_bs_equivalent' => 1000, 'fecha_hora' => now()->subHour()]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $ap1->id, 'amount_bs' => 1000, 'metodo_pago' => 'efectivo']);
        $ap2 = $this->crearApuesta($taquilla, ['amount_bs' => 300, 'total_bs_equivalent' => 300, 'fecha_hora' => now()->subHour()]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $ap2->id, 'amount_bs' => 300, 'metodo_pago' => 'transferencia']);
        $ap3 = $this->crearApuesta($taquilla, ['amount_bs' => 150, 'total_bs_equivalent' => 150, 'fecha_hora' => now()->subHour()]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $ap3->id, 'amount_bs' => 150, 'metodo_pago' => 'pago_movil']);
        $ap4 = $this->crearApuesta($taquilla, ['amount_bs' => 50, 'total_bs_equivalent' => 50, 'fecha_hora' => now()->subHour()]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $ap4->id, 'amount_bs' => 50, 'metodo_pago' => 'punto_venta']);

        $this->crearPago($taquilla, ['tipo' => 'egreso', 'amount_bs' => 30, 'metodo_pago' => 'transferencia', 'created_at' => now()->subMinutes(5)]);
        $this->crearPago($taquilla, ['tipo' => 'egreso', 'amount_bs' => 10, 'metodo_pago' => 'efectivo', 'created_at' => now()->subMinutes(4)]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);

        $response->assertStatus(201);

        $desglose = $response->json('desglose_metodos');

        $this->assertEquals(1000.0, (float) $desglose['bs']['efectivo']['ventas']);
        $this->assertEquals(300.0, (float) $desglose['bs']['transferencia']['ventas']);
        $this->assertEquals(150.0, (float) $desglose['bs']['pago_movil']['ventas']);
        $this->assertEquals(50.0, (float) $desglose['bs']['punto_venta']['ventas']);

        $this->assertEquals(10.0, (float) $desglose['bs']['efectivo']['egresos']);
        $this->assertEquals(30.0, (float) $desglose['bs']['transferencia']['egresos']);

        $this->assertEquals(990.0, (float) $desglose['bs']['efectivo']['efectivo']);
        $this->assertEquals(270.0, (float) $desglose['bs']['transferencia']['efectivo']);

        // Consistencia: la suma de métodos iguala el total de la moneda
        $sumaVentas = array_sum(array_map(fn ($m) => (float) $m['ventas'], $desglose['bs']));
        $sumaEfectivo = array_sum(array_map(fn ($m) => (float) $m['efectivo'], $desglose['bs']));

        $this->assertEquals(1500.0, $sumaVentas);
        $this->assertEquals(1500.0, (float) $response->json('total_ventas_bs'));
        $this->assertEquals(1460.0, $sumaEfectivo);
        $this->assertEquals(1460.0, (float) $response->json('total_efectivo_bs'));
    }

    public function test_usd_se_contabiliza_integro_en_efectivo()
    {
        $taquilla = $this->crearTaquilla('TTC13', $this->grupoSeeded()->id);

        // Venta mixta: el componente bs conserva su método
        $ap1 = $this->crearApuesta($taquilla, ['amount_bs' => 100, 'total_bs_equivalent' => 100, 'fecha_hora' => now()->subHour()]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $ap1->id, 'amount_bs' => 100, 'metodo_pago' => 'transferencia']);

        // Venta USD cuyo ingreso se registró como pago_movil: el desglose normaliza a efectivo
        $ap2 = $this->crearApuesta($taquilla, ['amount_usd' => 30, 'total_bs_equivalent' => 1095, 'fecha_hora' => now()->subHour()]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $ap2->id, 'amount_usd' => 30, 'metodo_pago' => 'pago_movil']);

        // Egreso USD registrado como transferencia: normaliza a efectivo
        $this->crearPago($taquilla, ['tipo' => 'egreso', 'amount_usd' => 3, 'metodo_pago' => 'transferencia', 'created_at' => now()->subMinutes(5)]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);

        $response->assertStatus(201);

        $desglose = $response->json('desglose_metodos');

        $this->assertEquals(30.0, (float) $desglose['usd']['efectivo']['ventas']);
        $this->assertEquals(3.0, (float) $desglose['usd']['efectivo']['egresos']);
        $this->assertEquals(27.0, (float) $desglose['usd']['efectivo']['efectivo']);

        // Ningún otro método recibe USD
        foreach (['transferencia', 'pago_movil', 'punto_venta'] as $metodo) {
            $this->assertEquals(0.0, (float) $desglose['usd'][$metodo]['ventas']);
            $this->assertEquals(0.0, (float) $desglose['usd'][$metodo]['egresos']);
        }

        // El componente bs de la venta mixta sí conserva su método
        $this->assertEquals(100.0, (float) $desglose['bs']['transferencia']['ventas']);

        // Consistencia con los totales USD
        $this->assertEquals(30.0, (float) $response->json('total_ventas_usd'));
        $this->assertEquals(3.0, (float) $response->json('total_egresos_usd'));
        $this->assertEquals(27.0, (float) $response->json('total_efectivo_usd'));
    }

    public function test_desglose_excluye_apuesta_anulada()
    {
        $taquilla = $this->crearTaquilla('TTC14', $this->grupoSeeded()->id);

        $apValida = $this->crearApuesta($taquilla, ['amount_bs' => 500, 'total_bs_equivalent' => 500, 'fecha_hora' => now()->subHour()]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $apValida->id, 'amount_bs' => 500, 'metodo_pago' => 'transferencia']);

        // Anulada por estado: su ingreso (huérfano) no debe aparecer en el desglose
        $apAnulada = $this->crearApuesta($taquilla, ['amount_bs' => 700, 'total_bs_equivalent' => 700, 'estado' => 'anulada', 'fecha_hora' => now()->subMinutes(30)]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $apAnulada->id, 'amount_bs' => 700, 'metodo_pago' => 'pago_movil']);

        // Anulada por soft delete: tampoco
        $apBorrada = $this->crearApuesta($taquilla, ['amount_bs' => 300, 'total_bs_equivalent' => 300, 'fecha_hora' => now()->subMinutes(20)]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $apBorrada->id, 'amount_bs' => 300, 'metodo_pago' => 'punto_venta']);
        $apBorrada->delete();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);

        $response->assertStatus(201);

        $desglose = $response->json('desglose_metodos');

        $this->assertEquals(500.0, (float) $response->json('total_ventas_bs'));
        $this->assertEquals(500.0, (float) $desglose['bs']['transferencia']['ventas']);
        $this->assertEquals(0.0, (float) $desglose['bs']['pago_movil']['ventas']);
        $this->assertEquals(0.0, (float) $desglose['bs']['punto_venta']['ventas']);
    }

    // ==================================================
    // POST /api/v1/cierre — no bloquea la venta (D3)
    // ==================================================

    public function test_venta_posterior_al_cierre_se_registra_normalmente()
    {
        $this->seed(JuegoAnimalitosSeeder::class);

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = Taquilla::factory()->create();
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF', 'active' => true]);

        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');

        $cierreResponse = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/v1/cierre');

        $cierreResponse->assertStatus(201);

        $ventaResponse = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/v1/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'perro', 'numero' => 5],
                'amount_bs' => 1800,
                'amount_usd' => 0,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ]);

        $ventaResponse->assertStatus(201);

        $this->assertDatabaseHas('apuestas', [
            'taquilla_id' => $taquilla->id,
            'amount_bs' => 1800,
        ]);

        $this->assertDatabaseHas('pagos', [
            'taquilla_id' => $taquilla->id,
            'tipo' => 'ingreso',
            'metodo_pago' => 'efectivo',
        ]);
    }

    // ==================================================
    // POST /api/v1/cierre — jerarquía
    // ==================================================

    public function test_super_master_puede_crear_cierre_para_cualquier_taquilla()
    {
        $taquilla = $this->taquillaSeeded();
        $this->crearApuesta($taquilla, ['amount_bs' => 100, 'total_bs_equivalent' => 100]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);

        $response->assertStatus(201)
            ->assertJsonPath('taquilla_id', $taquilla->id);
    }

    public function test_cierre_requiere_taquilla_id_para_roles_administrativos()
    {
        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/v1/cierre');

        $response->assertStatus(422)
            ->assertJsonValidationErrors('taquilla_id');
    }

    public function test_banca_puede_crear_cierre_para_taquillas_de_su_banca()
    {
        $taquilla = $this->taquillaSeeded();
        $this->crearApuesta($taquilla, ['amount_bs' => 50, 'total_bs_equivalent' => 50]);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);

        $response->assertStatus(201)
            ->assertJsonPath('taquilla_id', $taquilla->id);
    }

    public function test_banca_no_puede_crear_cierre_de_otra_banca()
    {
        $otraBanca = Banca::create(['name' => 'Banca Ajena C', 'code' => 'BALC01', 'created_by' => $this->superUser()->id]);
        $otroGrupo = Grupo::create(['name' => 'Grupo Ajeno C', 'code' => 'OGAC01', 'banca_id' => $otraBanca->id, 'created_by' => $this->superUser()->id]);
        $taquilla = $this->crearTaquilla('TTC03', $otroGrupo->id);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);

        $response->assertStatus(403);

        $this->assertDatabaseMissing('cierres_caja', ['taquilla_id' => $taquilla->id]);
    }

    // ==================================================
    // GET /api/v1/cierre — alcance jerárquico
    // ==================================================

    public function test_taquilla_solo_ve_sus_propios_cierres_en_index()
    {
        $taquilla = $this->taquillaSeeded();
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF', 'active' => true]);
        $taquillaUser = $this->taquillaUser();

        $otraTaquilla = $this->crearTaquilla('TTC04', $this->grupoSeeded()->id);

        $this->crearCierre($taquilla);
        $this->crearCierre($otraTaquilla);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->getJson('/api/v1/cierre');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('taquilla_id')->all();

        $this->assertEquals([$taquilla->id], $ids);
        $this->assertCount(1, $ids);
    }

    public function test_grupo_ve_cierres_de_sus_taquillas()
    {
        $grupo = $this->grupoSeeded();
        $taquilla = $this->taquillaSeeded();

        $otroGrupo = Grupo::create(['name' => 'Grupo Ajeno I', 'code' => 'OGAI01', 'banca_id' => $grupo->banca_id, 'created_by' => $this->superUser()->id]);
        $otraTaquilla = $this->crearTaquilla('TTC05', $otroGrupo->id);

        $this->crearCierre($taquilla);
        $this->crearCierre($otraTaquilla);

        $response = $this->actingAs($this->grupoUser(), 'sanctum')
            ->getJson('/api/v1/cierre');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('taquilla_id')->all();

        $this->assertEquals([$taquilla->id], $ids);
        $this->assertCount(1, $ids);
    }

    public function test_master_ve_cierres_de_sus_bancas_en_index()
    {
        $taquilla = $this->taquillaSeeded();
        $otraTaquilla = $this->crearTaquilla('TTC06', $this->grupoSeeded()->id);

        $this->crearCierre($taquilla);
        $this->crearCierre($otraTaquilla);

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/v1/cierre');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('taquilla_id')->sort()->values()->all();

        // Ambas taquillas cuelgan de la banca del master (BT001)
        $this->assertEquals([$taquilla->id, $otraTaquilla->id], $ids);
    }

    // ==================================================
    // GET /api/v1/cierre/{id} — detalle
    // ==================================================

    public function test_show_cierre_dentro_del_alcance_del_rol()
    {
        $taquilla = $this->taquillaSeeded();
        $cierre = $this->crearCierre($taquilla);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->getJson('/api/v1/cierre/'.$cierre->id);

        $response->assertStatus(200)
            ->assertJsonPath('id', $cierre->id)
            ->assertJsonPath('taquilla.id', $taquilla->id);
    }

    public function test_show_cierre_fuera_del_alcance_responde_403()
    {
        $otraBanca = Banca::create(['name' => 'Banca Ajena S', 'code' => 'BALS01', 'created_by' => $this->superUser()->id]);
        $otroGrupo = Grupo::create(['name' => 'Grupo Ajeno S', 'code' => 'OGAS01', 'banca_id' => $otraBanca->id, 'created_by' => $this->superUser()->id]);
        $taquilla = $this->crearTaquilla('TTC07', $otroGrupo->id);
        $cierre = $this->crearCierre($taquilla);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->getJson('/api/v1/cierre/'.$cierre->id);

        $response->assertStatus(403);
    }

    public function test_show_cierre_inexistente_responde_404()
    {
        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/v1/cierre/999999');

        $response->assertStatus(404);
    }

    // ==================================================
    // GET /api/v1/cierre/semanal — rollup semanal (AD-5)
    // ==================================================

    /**
     * Desglose diario mínimo reutilizable: un solo método con actividad.
     */
    private function desgloseDiaEfectivo(float $ventasBs, float $egresosBs, float $ventasUsd): array
    {
        return [
            'bs' => [
                'efectivo' => ['ventas' => $ventasBs, 'egresos' => $egresosBs, 'efectivo' => $ventasBs - $egresosBs],
                'transferencia' => ['ventas' => 0, 'egresos' => 0, 'efectivo' => 0],
                'pago_movil' => ['ventas' => 0, 'egresos' => 0, 'efectivo' => 0],
                'punto_venta' => ['ventas' => 0, 'egresos' => 0, 'efectivo' => 0],
            ],
            'usd' => [
                'efectivo' => ['ventas' => $ventasUsd, 'egresos' => 0, 'efectivo' => $ventasUsd],
                'transferencia' => ['ventas' => 0, 'egresos' => 0, 'efectivo' => 0],
                'pago_movil' => ['ventas' => 0, 'egresos' => 0, 'efectivo' => 0],
                'punto_venta' => ['ventas' => 0, 'egresos' => 0, 'efectivo' => 0],
            ],
        ];
    }

    public function test_semanal_rollup_semana_completa()
    {
        $taquilla = $this->taquillaSeeded();

        // 7 diarios: lunes 10 → domingo 16 de agosto 2026 (fecha=2026-08-12 ancla miércoles)
        for ($i = 0; $i < 7; $i++) {
            $fechaFin = Carbon::create(2026, 8, 10)->addDays($i)->setTime(20, 0, 0);
            $this->crearCierre($taquilla, [
                'fecha_inicio' => $fechaFin->copy()->subDay(),
                'fecha_fin' => $fechaFin,
                'total_ventas_bs' => 100,
                'total_ventas_usd' => 10,
                'total_ventas_bs_equivalent' => 465,
                'total_egresos_bs' => 10,
                'total_egresos_usd' => 0,
                'total_efectivo_bs' => 90,
                'total_efectivo_usd' => 10,
                'desglose_metodos' => $this->desgloseDiaEfectivo(100, 10, 10),
            ]);
        }

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/cierre/semanal?fecha=2026-08-12');

        $response->assertStatus(200)
            ->assertJsonPath('fecha_desde', '2026-08-10')
            ->assertJsonPath('fecha_hasta', '2026-08-16')
            ->assertJsonPath('ventana_cubierta.cierres_incluidos', 7);

        $this->assertEquals(700.0, (float) $response->json('total_ventas_bs'));
        $this->assertEquals(70.0, (float) $response->json('total_ventas_usd'));
        $this->assertEquals(3255.0, (float) $response->json('total_ventas_bs_equivalent'));
        $this->assertEquals(70.0, (float) $response->json('total_egresos_bs'));
        $this->assertEquals(0.0, (float) $response->json('total_egresos_usd'));
        $this->assertEquals(630.0, (float) $response->json('total_efectivo_bs'));
        $this->assertEquals(70.0, (float) $response->json('total_efectivo_usd'));

        // Desglose fusionado de los 7 diarios
        $this->assertEquals(700.0, (float) $response->json('desglose_metodos.bs.efectivo.ventas'));
        $this->assertEquals(70.0, (float) $response->json('desglose_metodos.bs.efectivo.egresos'));
        $this->assertEquals(630.0, (float) $response->json('desglose_metodos.bs.efectivo.efectivo'));
        $this->assertEquals(70.0, (float) $response->json('desglose_metodos.usd.efectivo.ventas'));
        $this->assertEquals(70.0, (float) $response->json('desglose_metodos.usd.efectivo.efectivo'));

        // Ventana cubierta: min/max fecha_fin de los diarios incluidos
        $ventana = $response->json('ventana_cubierta');
        $this->assertEquals('2026-08-10 20:00', $this->fechaJson($ventana['desde'])->format('Y-m-d H:i'));
        $this->assertEquals('2026-08-16 20:00', $this->fechaJson($ventana['hasta'])->format('Y-m-d H:i'));

        // cierres[] ordenados por fecha_fin asc
        $cierres = $response->json('cierres');
        $this->assertCount(7, $cierres);
        $this->assertEquals('2026-08-10 20:00', $this->fechaJson($cierres[0]['fecha_fin'])->format('Y-m-d H:i'));
        $this->assertEquals('2026-08-16 20:00', $this->fechaJson($cierres[6]['fecha_fin'])->format('Y-m-d H:i'));
        $this->assertEquals($taquilla->id, $cierres[0]['taquilla_id']);
        $this->assertEquals(100.0, (float) $cierres[0]['total_ventas_bs']);
    }

    public function test_semanal_semana_vacia_devuelve_ceros()
    {
        $taquilla = $this->taquillaSeeded();

        // Un cierre FUERA de la semana consultada: la semana queda vacía
        $this->crearCierre($taquilla, [
            'fecha_fin' => Carbon::create(2026, 8, 20, 12, 0, 0),
        ]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/cierre/semanal?fecha=2026-08-12');

        $response->assertStatus(200)
            ->assertJsonPath('fecha_desde', '2026-08-10')
            ->assertJsonPath('fecha_hasta', '2026-08-16')
            ->assertJsonPath('ventana_cubierta.desde', null)
            ->assertJsonPath('ventana_cubierta.hasta', null)
            ->assertJsonPath('ventana_cubierta.cierres_incluidos', 0)
            ->assertJsonPath('arqueo_efectivo_bs', null)
            ->assertJsonPath('arqueo_efectivo_usd', null)
            ->assertJsonPath('faltante_sobrante_bs', null)
            ->assertJsonPath('faltante_sobrante_usd', null);

        $this->assertEquals(0.0, (float) $response->json('total_ventas_bs'));
        $this->assertEquals(0.0, (float) $response->json('total_ventas_usd'));
        $this->assertEquals(0.0, (float) $response->json('total_ventas_bs_equivalent'));
        $this->assertEquals(0.0, (float) $response->json('total_egresos_bs'));
        $this->assertEquals(0.0, (float) $response->json('total_egresos_usd'));
        $this->assertEquals(0.0, (float) $response->json('total_efectivo_bs'));
        $this->assertEquals(0.0, (float) $response->json('total_efectivo_usd'));

        $this->assertEquals([], $response->json('cierres'));
    }

    public function test_semanal_semana_incompleta_expone_ventana_real()
    {
        $taquilla = $this->taquillaSeeded();

        // Solo lunes y miércoles tienen diario: ventana cubierta = esos 2 días
        $this->crearCierre($taquilla, [
            'fecha_fin' => Carbon::create(2026, 8, 10, 20, 0, 0),
            'total_ventas_bs' => 150,
            'total_efectivo_bs' => 150,
        ]);
        $this->crearCierre($taquilla, [
            'fecha_fin' => Carbon::create(2026, 8, 12, 20, 0, 0),
            'total_ventas_bs' => 250,
            'total_efectivo_bs' => 250,
        ]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/cierre/semanal?fecha=2026-08-12');

        $response->assertStatus(200)
            ->assertJsonPath('ventana_cubierta.cierres_incluidos', 2);

        $ventana = $response->json('ventana_cubierta');
        $this->assertEquals('2026-08-10 20:00', $this->fechaJson($ventana['desde'])->format('Y-m-d H:i'));
        $this->assertEquals('2026-08-12 20:00', $this->fechaJson($ventana['hasta'])->format('Y-m-d H:i'));

        $this->assertEquals(400.0, (float) $response->json('total_ventas_bs'));
        $this->assertEquals(400.0, (float) $response->json('total_efectivo_bs'));
        $this->assertCount(2, $response->json('cierres'));
    }

    public function test_semanal_alcance_taquilla_y_403()
    {
        $taquilla = $this->taquillaSeeded();
        $otraTaquilla = $this->crearTaquilla('TTC20', $this->grupoSeeded()->id);

        $this->crearCierre($taquilla, [
            'fecha_fin' => Carbon::create(2026, 8, 11, 20, 0, 0),
            'total_ventas_bs' => 100,
        ]);
        $this->crearCierre($otraTaquilla, [
            'fecha_fin' => Carbon::create(2026, 8, 12, 20, 0, 0),
            'total_ventas_bs' => 999,
        ]);

        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF', 'active' => true]);
        $taquillaUser = $this->taquillaUser();

        // Rol taquilla sin taquilla_id: solo agrega su propia taquilla
        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->getJson('/api/v1/cierre/semanal?fecha=2026-08-12');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('ventana_cubierta.cierres_incluidos'));
        $this->assertEquals(100.0, (float) $response->json('total_ventas_bs'));

        // Rol taquilla pidiendo OTRA taquilla → 403
        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->getJson('/api/v1/cierre/semanal?fecha=2026-08-12&taquilla_id='.$otraTaquilla->id);

        $response->assertStatus(403);

        // Admin (banca) con taquilla de otra banca → 403
        $otraBanca = Banca::create(['name' => 'Banca Ajena W', 'code' => 'BAW01', 'created_by' => $this->superUser()->id]);
        $otroGrupo = Grupo::create(['name' => 'Grupo Ajeno W', 'code' => 'OGAW01', 'banca_id' => $otraBanca->id, 'created_by' => $this->superUser()->id]);
        $taquillaFuera = $this->crearTaquilla('TTC21', $otroGrupo->id);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->getJson('/api/v1/cierre/semanal?fecha=2026-08-12&taquilla_id='.$taquillaFuera->id);

        $response->assertStatus(403);
    }

    public function test_semanal_respeta_fecha_desde_hasta()
    {
        $taquilla = $this->taquillaSeeded();

        $this->crearCierre($taquilla, [
            'fecha_fin' => Carbon::create(2026, 8, 10, 20, 0, 0),
            'total_ventas_bs' => 100,
        ]);
        $this->crearCierre($taquilla, [
            'fecha_fin' => Carbon::create(2026, 8, 12, 9, 0, 0),
            'total_ventas_bs' => 50,
        ]);
        // En 2026-08-13 00:00 (fecha_hasta + 1 día): excluido del rango interno [desde, hasta+1d)
        $this->crearCierre($taquilla, [
            'fecha_fin' => Carbon::create(2026, 8, 13, 0, 0, 0),
            'total_ventas_bs' => 777,
        ]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/cierre/semanal?fecha_desde=2026-08-10&fecha_hasta=2026-08-12');

        $response->assertStatus(200)
            ->assertJsonPath('fecha_desde', '2026-08-10')
            ->assertJsonPath('fecha_hasta', '2026-08-12')
            ->assertJsonPath('ventana_cubierta.cierres_incluidos', 2);

        $this->assertEquals(150.0, (float) $response->json('total_ventas_bs'));
    }

    public function test_semanal_fecha_y_rango_son_mutuamente_excluyentes()
    {
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/cierre/semanal?fecha=2026-08-12&fecha_desde=2026-08-10&fecha_hasta=2026-08-12');

        $response->assertStatus(422);

        // Solo un extremo del rango también es inválido (XOR)
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/cierre/semanal?fecha_desde=2026-08-10');

        $response->assertStatus(422);
    }

    // ==================================================
    // GET /api/v1/cierre/actual — preview read-only (AD-8)
    // ==================================================

    public function test_actual_devuelve_preview_sin_persistir()
    {
        $taquilla = $this->crearTaquilla('TTC22', $this->grupoSeeded()->id);

        $this->crearApuesta($taquilla, ['amount_bs' => 500, 'total_bs_equivalent' => 500, 'fecha_hora' => now()->subHour()]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => Apuesta::where('taquilla_id', $taquilla->id)->first()->id, 'amount_bs' => 500, 'metodo_pago' => 'efectivo']);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/cierre/actual?taquilla_id='.$taquilla->id);

        $response->assertStatus(200)
            ->assertJsonPath('taquilla_id', $taquilla->id)
            ->assertJsonPath('exchange_rate', 36.5);

        $this->assertEquals(500.0, (float) $response->json('total_ventas_bs'));
        $this->assertEquals(500.0, (float) $response->json('total_efectivo_bs'));
        $this->assertEquals(500.0, (float) $response->json('desglose_metodos.bs.efectivo.ventas'));

        // Preview sin persistencia: no existe ningún cierre
        $this->assertDatabaseMissing('cierres_caja', ['taquilla_id' => $taquilla->id]);
    }

    public function test_actual_preview_respeta_alcance_y_autorizacion()
    {
        $taquilla = $this->taquillaSeeded();
        $otraTaquilla = $this->crearTaquilla('TTC23', $this->grupoSeeded()->id);

        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF', 'active' => true]);
        $taquillaUser = $this->taquillaUser();

        // Rol taquilla: preview de su propia taquilla (200)
        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->getJson('/api/v1/cierre/actual');

        $response->assertStatus(200)
            ->assertJsonPath('taquilla_id', $taquilla->id);

        // Rol taquilla pidiendo otra taquilla → 403
        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->getJson('/api/v1/cierre/actual?taquilla_id='.$otraTaquilla->id);

        $response->assertStatus(403);

        // Admin sin taquilla_id → 422 (misma validación que store)
        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/v1/cierre/actual');

        $response->assertStatus(422)
            ->assertJsonValidationErrors('taquilla_id');
    }

    // ==================================================
    // Orden de rutas: actual y semanal ANTES de {cierre} (AD-11)
    // ==================================================

    public function test_rutas_actual_y_semanal_no_caen_en_binding_de_cierre()
    {
        $taquilla = $this->taquillaSeeded();

        $ap = $this->crearApuesta($taquilla, ['amount_bs' => 500, 'total_bs_equivalent' => 500, 'fecha_hora' => now()->subHour()]);
        $this->crearPago($taquilla, ['tipo' => 'ingreso', 'apuesta_id' => $ap->id, 'amount_bs' => 500, 'metodo_pago' => 'efectivo']);

        // Semanal responde el reporte (200), no un 404 del binding {cierre}
        $semanal = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/cierre/semanal?fecha=2026-08-12');
        $semanal->assertStatus(200)
            ->assertJsonPath('fecha_desde', '2026-08-10');

        // Actual responde el preview (200), no un 404 del binding {cierre}
        $actual = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/cierre/actual?taquilla_id='.$taquilla->id);
        $actual->assertStatus(200)
            ->assertJsonPath('taquilla_id', $taquilla->id);
        $this->assertEquals(500.0, (float) $actual->json('total_ventas_bs'));

        // Orden de registro en el router: actual y semanal antes de {cierre}
        $uris = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri())->values()->all();

        $posActual = array_search('api/v1/cierre/actual', $uris);
        $posSemanal = array_search('api/v1/cierre/semanal', $uris);
        $posCierre = array_search('api/v1/cierre/{cierre}', $uris);

        $this->assertNotFalse($posActual, 'Ruta actual no registrada');
        $this->assertNotFalse($posSemanal, 'Ruta semanal no registrada');
        $this->assertNotFalse($posCierre, 'Ruta {cierre} no registrada');
        $this->assertLessThan($posCierre, $posActual);
        $this->assertLessThan($posCierre, $posSemanal);
    }
}
