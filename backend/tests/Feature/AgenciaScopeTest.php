<?php

namespace Tests\Feature;

use App\Models\Agencia;
use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\CierreCaja;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PR 2 (F1) — alcance del rol agencia (local físico).
 *
 * La agencia (local) ve solo las apuestas/taquillas/usuarios/cierres/límites
 * de SUS taquillas; se rechaza todo lo de otro local. Cubre login X-Panel,
 * cadena de activación con local, policy de apuestas, creación de taquillas
 * y usuarios scoped, cierres, límites GET y reportes.
 */
class AgenciaScopeTest extends TestCase
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

        return $super;
    }

    private function agenciaUser(): User
    {
        $user = User::where('email', 'agencia@lotto.com')->first();
        $user->assignRole('agencia');

        return $user;
    }

    /**
     * Jerarquía aislada: banca → grupo → local (agencia) con 2 taquillas
     * propias y 1 local ajeno (misma banca, otro grupo).
     *
     * @return array{0: User, 1: Agencia, 2: Taquilla, 3: Taquilla, 4: Agencia, 5: Taquilla}
     */
    private function jerarquiaLocal(): array
    {
        $banca = Banca::factory()->create(['active' => true]);
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $agencia = Agencia::factory()->create(['grupo_id' => $grupo->id, 'active' => true]);
        $taquilla1 = Taquilla::factory()->create([
            'grupo_id' => $grupo->id, 'agencia_id' => $agencia->id, 'active' => true,
        ]);
        $taquilla2 = Taquilla::factory()->create([
            'grupo_id' => $grupo->id, 'agencia_id' => $agencia->id, 'active' => true,
        ]);

        $otroGrupo = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $otraAgencia = Agencia::factory()->create(['grupo_id' => $otroGrupo->id, 'active' => true]);
        $taquillaAjena = Taquilla::factory()->create([
            'grupo_id' => $otroGrupo->id, 'agencia_id' => $otraAgencia->id, 'active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'agencia',
            'agencia_id' => $agencia->id,
            'grupo_id' => $grupo->id,
            'banca_id' => $banca->id,
        ]);
        $user->assignRole('agencia');

        return [$user, $agencia, $taquilla1, $taquilla2, $otraAgencia, $taquillaAjena];
    }

    private function juegoLotto(): Juego
    {
        return Juego::where('slug', 'lotto-activo')->first();
    }

    private function crearApuesta(Taquilla $taquilla, array $overrides = []): Apuesta
    {
        return Apuesta::create(array_merge([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $this->juegoLotto()->id,
            'amount_bs' => 1000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 1000,
            'estado' => 'pendiente',
            'fecha_hora' => now()->subDay(),
        ], $overrides));
    }

    // ==================================================
    // LOGIN X-PANEL (rol agencia)
    // ==================================================

    public function test_login_agencia_panel_accede_y_expone_agencia_id()
    {
        $local = Agencia::where('code', 'LT001')->first();

        $response = $this->withHeaders(['X-Panel' => 'true'])
            ->postJson('/api/v1/login', [
                'email' => 'agencia@lotto.com',
                'password' => 'password',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('role', 'agencia')
            ->assertJsonPath('user.agencia_id', $local->id)
            ->assertJsonPath('user.agencia.id', $local->id);
    }

    public function test_login_agencia_local_inactivo_mensaje_cadena()
    {
        $banca = Banca::factory()->create(['active' => true]);
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $agencia = Agencia::factory()->create(['grupo_id' => $grupo->id, 'active' => false]);
        $user = User::factory()->create([
            'role' => 'agencia',
            'email' => 'agencia-inactiva@test.com',
            'password' => bcrypt('password'),
            'agencia_id' => $agencia->id,
            'grupo_id' => $grupo->id,
            'banca_id' => $banca->id,
        ]);
        $user->assignRole('agencia');

        $response = $this->withHeaders(['X-Panel' => 'true'])
            ->postJson('/api/v1/login', [
                'email' => 'agencia-inactiva@test.com',
                'password' => 'password',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Tu cuenta está pausada porque su local está desactivado.');
    }

    public function test_login_agencia_grupo_inactivo_mensaje_cadena()
    {
        $banca = Banca::factory()->create(['active' => true]);
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => false]);
        $agencia = Agencia::factory()->create(['grupo_id' => $grupo->id, 'active' => true]);
        $user = User::factory()->create([
            'role' => 'agencia',
            'email' => 'agencia-grupo-inactivo@test.com',
            'password' => bcrypt('password'),
            'agencia_id' => $agencia->id,
            'grupo_id' => $grupo->id,
            'banca_id' => $banca->id,
        ]);
        $user->assignRole('agencia');

        $response = $this->withHeaders(['X-Panel' => 'true'])
            ->postJson('/api/v1/login', [
                'email' => 'agencia-grupo-inactivo@test.com',
                'password' => 'password',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Tu cuenta está pausada porque su grupo está desactivado.');
    }

    public function test_login_taquilla_panel_rechazado_mensaje_taquilla()
    {
        $response = $this->withHeaders([
            'X-Panel' => 'true',
            'X-Device-Fingerprint' => 'demo-device-001',
        ])->postJson('/api/v1/login', [
            'email' => 'demo@lotto.com',
            'password' => 'password',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Las taquillas deben usar la app de escritorio.');
    }

    // ==================================================
    // CADENA DE ACTIVACIÓN (local en la cadena)
    // ==================================================

    public function test_verify_mac_bloquea_taquilla_de_local_inactivo()
    {
        [$user] = $this->jerarquiaLocal();
        $taquilla = Taquilla::factory()->create([
            'grupo_id' => $user->agencia->grupo_id,
            'agencia_id' => $user->agencia_id,
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'fp-001',
        ]);
        $user->agencia->update(['active' => false]);

        $taquillaUser = User::factory()->create([
            'role' => 'taquilla',
            'taquilla_id' => $taquilla->id,
            'agencia_id' => $taquilla->agencia_id,
        ]);
        $taquillaUser->assignRole('taquilla');

        $response = $this->withHeaders([
            'X-Device-MAC' => 'AA:BB:CC:DD:EE:FF',
            'X-Device-Fingerprint' => 'fp-001',
        ])->actingAs($taquillaUser, 'sanctum')->getJson('/api/v1/apuestas');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'La taquilla está pausada porque su local está desactivado.');

        // Sin cascada: el flag propio de la taquilla no se tocó
        $this->assertDatabaseHas('taquillas', ['id' => $taquilla->id, 'active' => true]);
    }

    public function test_login_taquilla_bloqueada_por_local_inactivo()
    {
        $banca = Banca::factory()->create(['active' => true]);
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $agencia = Agencia::factory()->create(['grupo_id' => $grupo->id, 'active' => false]);
        $taquilla = Taquilla::factory()->create([
            'grupo_id' => $grupo->id, 'agencia_id' => $agencia->id, 'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'fp-001',
        ]);
        $user = User::factory()->create([
            'role' => 'taquilla',
            'email' => 'taq-local-inactivo@test.com',
            'password' => bcrypt('password'),
            'taquilla_id' => $taquilla->id,
            'grupo_id' => $grupo->id,
            'banca_id' => $banca->id,
            'agencia_id' => $agencia->id,
        ]);
        $user->assignRole('taquilla');

        $response = $this->withHeaders(['X-Device-Fingerprint' => 'fp-001'])
            ->postJson('/api/v1/login', [
                'email' => 'taq-local-inactivo@test.com',
                'password' => 'password',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Tu cuenta está pausada porque su local está desactivado.');
    }

    // ==================================================
    // APUESTAS (policy + listado)
    // ==================================================

    public function test_agencia_ve_solo_apuestas_de_sus_taquillas()
    {
        [$user, , $taquilla1, $taquilla2, , $taquillaAjena] = $this->jerarquiaLocal();
        $this->crearApuesta($taquilla1, ['estado' => 'pendiente']);
        $this->crearApuesta($taquilla2, ['estado' => 'pendiente']);
        $this->crearApuesta($taquillaAjena, ['estado' => 'pendiente']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/apuestas');

        $response->assertStatus(200);

        $taquillaIds = collect($response->json('data.data'))->pluck('taquilla_id')->all();
        $this->assertCount(2, $taquillaIds);
        $this->assertContains($taquilla1->id, $taquillaIds);
        $this->assertContains($taquilla2->id, $taquillaIds);
        $this->assertNotContains($taquillaAjena->id, $taquillaIds);
    }

    public function test_agencia_ve_apuesta_de_su_local()
    {
        [$user, , $taquilla1] = $this->jerarquiaLocal();
        $apuesta = $this->crearApuesta($taquilla1);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/apuestas/'.$apuesta->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $apuesta->id);
    }

    public function test_agencia_no_ve_apuesta_de_otro_local()
    {
        [$user, , , , , $taquillaAjena] = $this->jerarquiaLocal();
        $apuesta = $this->crearApuesta($taquillaAjena);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/apuestas/'.$apuesta->id);

        $response->assertStatus(403);
    }

    public function test_agencia_elimina_apuesta_de_su_taquilla()
    {
        [$user, , $taquilla1] = $this->jerarquiaLocal();
        $apuesta = $this->crearApuesta($taquilla1);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/apuestas/'.$apuesta->id, ['motivo' => 'test']);

        $response->assertStatus(200);
        $this->assertSoftDeleted('apuestas', ['id' => $apuesta->id]);
    }

    public function test_agencia_no_elimina_apuesta_de_otro_local()
    {
        [$user, , , , , $taquillaAjena] = $this->jerarquiaLocal();
        $apuesta = $this->crearApuesta($taquillaAjena);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/apuestas/'.$apuesta->id);

        $response->assertStatus(403);
        $this->assertDatabaseHas('apuestas', ['id' => $apuesta->id]);
    }

    // ==================================================
    // TAQUILLAS (listado + creación scoped)
    // ==================================================

    public function test_agencia_lista_solo_sus_taquillas()
    {
        [$user, , $taquilla1, $taquilla2, , $taquillaAjena] = $this->jerarquiaLocal();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/taquillas');

        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($taquilla1->id, $ids);
        $this->assertContains($taquilla2->id, $ids);
        $this->assertNotContains($taquillaAjena->id, $ids);
    }

    public function test_agencia_crea_taquilla_en_su_local()
    {
        [$user, $agencia] = $this->jerarquiaLocal();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/taquillas', [
                'name' => 'Taquilla Nueva',
                'code' => 'TNF01',
                'user_name' => 'Usuario Taquilla Nueva',
                'user_email' => 'taquilla-nueva@test.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(201);

        // agencia_id y grupo_id derivados de la agencia (no editables)
        $taquillaId = $response->json('taquilla.id');
        $this->assertDatabaseHas('taquillas', [
            'id' => $taquillaId,
            'agencia_id' => $agencia->id,
            'grupo_id' => $agencia->grupo_id,
        ]);

        // El usuario taquilla creado incluye agencia_id
        $this->assertDatabaseHas('users', [
            'email' => 'taquilla-nueva@test.com',
            'agencia_id' => $agencia->id,
            'taquilla_id' => $taquillaId,
        ]);
    }

    public function test_agencia_no_crea_taquilla_en_otro_local()
    {
        [$user] = $this->jerarquiaLocal();
        $otraAgencia = Agencia::factory()->create(['active' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/taquillas', [
                'name' => 'Taquilla Ajena',
                'code' => 'TAJ01',
                'agencia_id' => $otraAgencia->id,
                'user_name' => 'Usuario Ajena',
                'user_email' => 'taquilla-ajena@test.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(403);
    }

    public function test_agencia_no_accede_taquilla_de_otro_local()
    {
        [$user, , , , , $taquillaAjena] = $this->jerarquiaLocal();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/taquillas/'.$taquillaAjena->id);

        $response->assertStatus(403);
    }

    // ==================================================
    // USUARIOS (alcance + creación scoped)
    // ==================================================

    public function test_agencia_ve_solo_usuarios_de_su_local()
    {
        [$user, $agencia, $taquilla1] = $this->jerarquiaLocal();
        User::factory()->create([
            'role' => 'taquilla',
            'taquilla_id' => $taquilla1->id,
            'agencia_id' => $agencia->id,
        ])->assignRole('taquilla');
        $otroLocal = Agencia::factory()->create(['active' => true]);
        $otraTaquilla = Taquilla::factory()->create([
            'grupo_id' => $otroLocal->grupo_id, 'agencia_id' => $otroLocal->id, 'active' => true,
        ]);
        $usuarioAjeno = User::factory()->create([
            'role' => 'taquilla',
            'taquilla_id' => $otraTaquilla->id,
            'agencia_id' => $otroLocal->id,
        ]);
        $usuarioAjeno->assignRole('taquilla');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users');

        $response->assertStatus(200);

        $emails = collect($response->json())->pluck('email')->all();
        $this->assertContains($user->email, $emails);
        $this->assertNotContains($usuarioAjeno->email, $emails);
    }

    public function test_agencia_crea_usuario_taquilla_en_su_local()
    {
        [$user, $agencia, $taquilla1] = $this->jerarquiaLocal();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/users', [
                'user_name' => 'Usuario Local',
                'user_email' => 'usuario-local@test.com',
                'user_password' => 'password123',
                'role' => 'taquilla',
                'taquilla_id' => $taquilla1->id,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'usuario-local@test.com',
            'agencia_id' => $agencia->id,
            'taquilla_id' => $taquilla1->id,
        ]);
    }

    public function test_agencia_no_crea_usuario_fuera_de_su_local()
    {
        [$user, , , , , $taquillaAjena] = $this->jerarquiaLocal();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/users', [
                'user_name' => 'Usuario Ajeno',
                'user_email' => 'usuario-ajeno@test.com',
                'user_password' => 'password123',
                'role' => 'taquilla',
                'taquilla_id' => $taquillaAjena->id,
            ]);

        $response->assertStatus(403);
    }

    // ==================================================
    // CIERRES (alcance agencia)
    // ==================================================

    public function test_agencia_lista_cierres_de_sus_taquillas()
    {
        [$user, , $taquilla1, , , $taquillaAjena] = $this->jerarquiaLocal();
        CierreCaja::create([
            'taquilla_id' => $taquilla1->id,
            'fecha_inicio' => now()->subDay(),
            'fecha_fin' => now(),
            'total_ventas_bs' => 100,
            'total_ventas_usd' => 0,
            'total_ventas_bs_equivalent' => 100,
            'total_egresos_bs' => 0,
            'total_egresos_usd' => 0,
            'total_efectivo_bs' => 100,
            'total_efectivo_usd' => 0,
            'exchange_rate_cierre' => 36.50,
            'created_by' => $user->id,
        ]);
        CierreCaja::create([
            'taquilla_id' => $taquillaAjena->id,
            'fecha_inicio' => now()->subDay(),
            'fecha_fin' => now(),
            'total_ventas_bs' => 50,
            'total_ventas_usd' => 0,
            'total_ventas_bs_equivalent' => 50,
            'total_egresos_bs' => 0,
            'total_egresos_usd' => 0,
            'total_efectivo_bs' => 50,
            'total_efectivo_usd' => 0,
            'exchange_rate_cierre' => 36.50,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/cierre');

        $response->assertStatus(200);

        $taquillaIds = collect($response->json('data'))->pluck('taquilla_id')->all();
        $this->assertContains($taquilla1->id, $taquillaIds);
        $this->assertNotContains($taquillaAjena->id, $taquillaIds);
    }

    public function test_agencia_no_ve_cierre_de_otro_local()
    {
        [$user, , , , , $taquillaAjena] = $this->jerarquiaLocal();
        $cierre = CierreCaja::create([
            'taquilla_id' => $taquillaAjena->id,
            'fecha_inicio' => now()->subDay(),
            'fecha_fin' => now(),
            'total_ventas_bs' => 50,
            'total_ventas_usd' => 0,
            'total_ventas_bs_equivalent' => 50,
            'total_egresos_bs' => 0,
            'total_egresos_usd' => 0,
            'total_efectivo_bs' => 50,
            'total_efectivo_usd' => 0,
            'exchange_rate_cierre' => 36.50,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/cierre/'.$cierre->id);

        $response->assertStatus(403);
    }

    // ==================================================
    // LÍMITES GET (rama agencia)
    // ==================================================

    public function test_agencia_ve_limites_de_su_cadena()
    {
        [$user, , $taquilla1] = $this->jerarquiaLocal();
        $juego = $this->juegoLotto();

        JuegoLimite::create([
            'juego_id' => $juego->id, 'banca_id' => $user->banca_id,
            'grupo_id' => null, 'taquilla_id' => null, 'moneda' => 'bs',
            'limite_maximo' => 900,
        ]);
        JuegoLimite::create([
            'juego_id' => $juego->id, 'banca_id' => $user->banca_id,
            'grupo_id' => $user->grupo_id, 'taquilla_id' => null, 'moneda' => 'bs',
            'limite_maximo' => 500,
        ]);
        JuegoLimite::create([
            'juego_id' => $juego->id, 'banca_id' => $user->banca_id,
            'grupo_id' => $user->grupo_id, 'taquilla_id' => $taquilla1->id, 'moneda' => 'bs',
            'limite_maximo' => 200,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/limites/'.$juego->id);

        $response->assertStatus(200);

        // decimal:2 en el modelo → se compara como float
        $maximos = collect($response->json())->map(fn ($fila) => (float) $fila['limite_maximo'])->all();
        $this->assertContains(900.0, $maximos);
        $this->assertContains(500.0, $maximos);
        $this->assertContains(200.0, $maximos);
    }

    public function test_agencia_no_ve_limites_de_otro_local()
    {
        [$user, , , , , $taquillaAjena] = $this->jerarquiaLocal();
        $juego = $this->juegoLotto();

        JuegoLimite::create([
            'juego_id' => $juego->id, 'banca_id' => $user->banca_id,
            'grupo_id' => $taquillaAjena->grupo_id, 'taquilla_id' => $taquillaAjena->id, 'moneda' => 'bs',
            'limite_maximo' => 400,
        ]);

        // Filtro de intersección: taquilla ajena fuera del alcance → vacío
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/limites/'.$juego->id.'?taquilla_id='.$taquillaAjena->id);

        $response->assertStatus(200);
        $this->assertEmpty($response->json());
    }

    // ==================================================
    // REPORTES (alcance agencia en buildApuestaQuery)
    // ==================================================

    public function test_agencia_reporte_ventas_solo_sus_taquillas()
    {
        [$user, , $taquilla1, , , $taquillaAjena] = $this->jerarquiaLocal();
        $this->crearApuesta($taquilla1, ['estado' => 'pendiente']);
        $this->crearApuesta($taquillaAjena, ['estado' => 'pendiente']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reportes/ventas-totales?nivel=taquilla');

        $response->assertStatus(200);

        $nombres = collect($response->json('data'))->pluck('Entidad')->all();
        $this->assertContains($taquilla1->name, $nombres);
        $this->assertNotContains($taquillaAjena->name, $nombres);
    }
}
