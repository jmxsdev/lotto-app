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
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
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
            'name' => 'Agencia ' . $code,
            'code' => $code,
            'grupo_id' => $grupoId,
            'activation_code' => $code . '-CODE',
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

    public function test_cierre_sin_tasa_activa_responde_422()
    {
        ExchangeRate::query()->update(['is_active' => false]);

        $taquilla = $this->taquillaSeeded();

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'No hay tasa de cambio activa para realizar el cierre.');

        $this->assertDatabaseMissing('cierres_caja', ['taquilla_id' => $taquilla->id]);
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

    public function test_master_ve_todos_los_cierres_en_index()
    {
        $taquilla = $this->taquillaSeeded();
        $otraTaquilla = $this->crearTaquilla('TTC06', $this->grupoSeeded()->id);

        $this->crearCierre($taquilla);
        $this->crearCierre($otraTaquilla);

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/v1/cierre');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('taquilla_id')->sort()->values()->all();

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
            ->getJson('/api/v1/cierre/' . $cierre->id);

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
            ->getJson('/api/v1/cierre/' . $cierre->id);

        $response->assertStatus(403);
    }

    public function test_show_cierre_inexistente_responde_404()
    {
        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/v1/cierre/999999');

        $response->assertStatus(404);
    }
}
