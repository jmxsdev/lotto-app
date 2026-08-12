<?php

namespace Tests\Feature;

use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivacionEntidadesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    private function master(): User
    {
        $master = User::where('email', 'master@lotto.com')->first();
        $master->assignRole('master');

        return $master;
    }

    private function crearJerarquia(array $overrides = []): array
    {
        $banca = Banca::factory()->create($overrides['banca'] ?? []);
        $grupo = Grupo::factory()->create(array_merge(['banca_id' => $banca->id], $overrides['grupo'] ?? []));
        $taquilla = Taquilla::factory()->create(array_merge(['grupo_id' => $grupo->id], $overrides['taquilla'] ?? []));

        return [$banca, $grupo, $taquilla];
    }

    private function crearUsuarioTaquilla(Taquilla $taquilla): User
    {
        $user = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $user->assignRole('taquilla');

        return $user;
    }

    // ==================================================
    // R1 — Toggles por entidad
    // ==================================================

    public function test_toggle_banca_invierte_active()
    {
        $banca = Banca::factory()->create(['active' => true]);

        $this->actingAs($this->master(), 'sanctum')
            ->patchJson("/api/bancas/{$banca->id}/toggle")
            ->assertStatus(200)
            ->assertJsonPath('active', false);

        $this->assertDatabaseHas('bancas', ['id' => $banca->id, 'active' => false]);

        $this->actingAs($this->master(), 'sanctum')
            ->patchJson("/api/bancas/{$banca->id}/toggle")
            ->assertStatus(200)
            ->assertJsonPath('active', true);

        $this->assertDatabaseHas('bancas', ['id' => $banca->id, 'active' => true]);
    }

    public function test_toggle_grupo_invierte_active()
    {
        $grupo = Grupo::factory()->create(['active' => true]);

        $this->actingAs($this->master(), 'sanctum')
            ->patchJson("/api/grupos/{$grupo->id}/toggle")
            ->assertStatus(200)
            ->assertJsonPath('active', false);

        $this->assertDatabaseHas('grupos', ['id' => $grupo->id, 'active' => false]);
    }

    public function test_toggle_taquilla_invierte_active()
    {
        [, , $taquilla] = $this->crearJerarquia(['taquilla' => ['active' => true]]);

        $this->actingAs($this->master(), 'sanctum')
            ->patchJson("/api/taquillas/{$taquilla->id}/toggle")
            ->assertStatus(200)
            ->assertJsonPath('active', false);

        $this->assertDatabaseHas('taquillas', ['id' => $taquilla->id, 'active' => false]);
    }

    public function test_banca_user_puede_togglear_su_propio_grupo()
    {
        $bancaUser = User::where('email', 'banca@lotto.com')->first();
        $bancaUser->assignRole('banca');

        $grupo = Grupo::factory()->create(['banca_id' => $bancaUser->banca_id, 'active' => true]);

        $this->actingAs($bancaUser, 'sanctum')
            ->patchJson("/api/grupos/{$grupo->id}/toggle")
            ->assertStatus(200)
            ->assertJsonPath('active', false);
    }

    // ==================================================
    // R4 — Sin escritura en cascada
    // ==================================================

    public function test_desactivar_grupo_no_modifica_active_de_sus_taquillas()
    {
        [, $grupo, $taquilla] = $this->crearJerarquia([
            'grupo' => ['active' => true],
            'taquilla' => ['active' => true],
        ]);

        $this->actingAs($this->master(), 'sanctum')
            ->patchJson("/api/grupos/{$grupo->id}/toggle")
            ->assertStatus(200);

        // Sin cascada: el flag propio de la agencia sigue en true
        $this->assertDatabaseHas('taquillas', ['id' => $taquilla->id, 'active' => true]);
        $this->assertDatabaseHas('grupos', ['id' => $grupo->id, 'active' => false]);
    }

    public function test_desactivar_banca_no_modifica_grupos_ni_taquillas()
    {
        [$banca, , $taquilla] = $this->crearJerarquia([
            'grupo' => ['active' => true],
            'taquilla' => ['active' => true],
        ]);

        $this->actingAs($this->master(), 'sanctum')
            ->patchJson("/api/bancas/{$banca->id}/toggle")
            ->assertStatus(200);

        $this->assertDatabaseHas('bancas', ['id' => $banca->id, 'active' => false]);
        $this->assertDatabaseHas('grupos', ['id' => $taquilla->grupo_id, 'active' => true]);
        $this->assertDatabaseHas('taquillas', ['id' => $taquilla->id, 'active' => true]);
    }

    // ==================================================
    // R2 — Bloqueo en VerifyMac por ancestros inactivos
    // ==================================================

    public function test_verify_mac_bloquea_cuando_grupo_inactivo()
    {
        [, , $taquilla] = $this->crearJerarquia([
            'grupo' => ['active' => false],
            'taquilla' => [
                'active' => true,
                'mac_address' => 'AA:BB:CC:DD:EE:FF',
                'device_fingerprint' => 'fp-001',
            ],
        ]);
        $user = $this->crearUsuarioTaquilla($taquilla);

        $response = $this->withHeaders([
            'X-Device-MAC' => 'AA:BB:CC:DD:EE:FF',
            'X-Device-Fingerprint' => 'fp-001',
        ])->actingAs($user, 'sanctum')->getJson('/api/apuestas');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'La agencia está pausada porque su grupo está desactivado.');

        // El flag propio de la agencia no se tocó
        $this->assertDatabaseHas('taquillas', ['id' => $taquilla->id, 'active' => true]);
    }

    public function test_verify_mac_bloquea_cuando_banca_inactiva()
    {
        [, , $taquilla] = $this->crearJerarquia([
            'banca' => ['active' => false],
            'taquilla' => [
                'active' => true,
                'mac_address' => 'AA:BB:CC:DD:EE:FF',
                'device_fingerprint' => 'fp-001',
            ],
        ]);
        $user = $this->crearUsuarioTaquilla($taquilla);

        $response = $this->withHeaders([
            'X-Device-MAC' => 'AA:BB:CC:DD:EE:FF',
            'X-Device-Fingerprint' => 'fp-001',
        ])->actingAs($user, 'sanctum')->getJson('/api/apuestas');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'La agencia está pausada porque su banca está desactivada.');
    }

    public function test_verify_mac_bloquea_con_flag_propio_inactivo()
    {
        [, , $taquilla] = $this->crearJerarquia(['taquilla' => ['active' => false]]);
        $user = $this->crearUsuarioTaquilla($taquilla);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($user, 'sanctum')->getJson('/api/apuestas');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'La agencia está desactivada.');
    }

    // ==================================================
    // R5 — Reactivación instantánea sin re-activación MAC
    // ==================================================

    public function test_reactivar_grupo_restaura_sin_reactivacion()
    {
        [, $grupo, $taquilla] = $this->crearJerarquia([
            'grupo' => ['active' => true],
            'taquilla' => [
                'active' => true,
                'mac_address' => 'AA:BB:CC:DD:EE:FF',
                'device_fingerprint' => 'fp-001',
            ],
        ]);
        $user = $this->crearUsuarioTaquilla($taquilla);
        $headers = ['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF', 'X-Device-Fingerprint' => 'fp-001'];

        // Desactivar grupo → la agencia queda bloqueada
        $this->actingAs($this->master(), 'sanctum')
            ->patchJson("/api/grupos/{$grupo->id}/toggle")->assertStatus(200);

        $this->withHeaders($headers)->actingAs($user, 'sanctum')
            ->getJson('/api/apuestas')->assertStatus(403);

        // Reactivar grupo → opera de inmediato sin re-activación
        $this->actingAs($this->master(), 'sanctum')
            ->patchJson("/api/grupos/{$grupo->id}/toggle")->assertStatus(200);

        $this->withHeaders($headers)->actingAs($user, 'sanctum')
            ->getJson('/api/apuestas')->assertStatus(200);

        // MAC y huella permanecieron intactos
        $this->assertDatabaseHas('taquillas', [
            'id' => $taquilla->id,
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'fp-001',
        ]);
    }

    // ==================================================
    // R3 — Bloqueo en login
    // ==================================================

    public function test_login_bloqueado_para_taquilla_de_grupo_inactivo()
    {
        [, , $taquilla] = $this->crearJerarquia([
            'grupo' => ['active' => false],
            'taquilla' => [
                'active' => true,
                'mac_address' => 'AA:BB:CC:DD:EE:FF',
                'device_fingerprint' => 'fp-001',
            ],
        ]);
        $user = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
            'email' => 'taq-cadena@test.com',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole('taquilla');

        $response = $this->withHeaders(['X-Device-Fingerprint' => 'fp-001'])
            ->postJson('/api/login', [
                'email' => 'taq-cadena@test.com',
                'password' => 'password',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Tu cuenta está pausada porque su grupo está desactivado.');
    }

    public function test_login_bloqueado_para_usuario_banca_inactiva()
    {
        $banca = Banca::factory()->create(['active' => false]);
        $user = User::factory()->create([
            'banca_id' => $banca->id,
            'role' => 'banca',
            'email' => 'banca-cadena@test.com',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole('banca');

        $response = $this->postJson('/api/login', [
            'email' => 'banca-cadena@test.com',
            'password' => 'password',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Tu cuenta está pausada porque su banca está desactivada.');
    }

    public function test_login_master_no_se_ve_afectado_por_cadena()
    {
        $response = $this->withHeaders(['X-Panel' => 'true'])
            ->postJson('/api/login', [
                'email' => 'master@lotto.com',
                'password' => 'password',
            ]);

        $response->assertStatus(200)->assertJsonPath('role', 'master');
    }

    // ==================================================
    // R6 + authz — Flujo de activación intacto y permisos
    // ==================================================

    public function test_activacion_por_codigo_sigue_funcionando_con_cadena_activa()
    {
        [, , $taquilla] = $this->crearJerarquia([
            'taquilla' => [
                'active' => false,
                'activation_code' => 'CADENA01',
                'mac_address' => null,
                'device_fingerprint' => null,
            ],
        ]);

        $response = $this->postJson('/api/activar', [
            'activation_code' => 'CADENA01',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'fp-act-001',
        ]);

        $response->assertStatus(200)->assertJsonPath('success', true);
        $this->assertDatabaseHas('taquillas', ['id' => $taquilla->id, 'active' => true]);
    }

    public function test_taquilla_no_puede_togglear_banca()
    {
        [, , $taquilla] = $this->crearJerarquia([
            'taquilla' => [
                'active' => true,
                'mac_address' => 'AA:BB:CC:DD:EE:FF',
                'device_fingerprint' => 'fp-001',
            ],
        ]);
        $user = $this->crearUsuarioTaquilla($taquilla);
        $banca = $taquilla->grupo->banca;

        $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF', 'X-Device-Fingerprint' => 'fp-001'])
            ->actingAs($user, 'sanctum')
            ->patchJson("/api/bancas/{$banca->id}/toggle")
            ->assertStatus(403);
    }

    public function test_grupo_no_puede_togglear_grupos_de_otra_banca()
    {
        $grupoUser = User::where('email', 'grupo@lotto.com')->first();
        $grupoUser->assignRole('grupo');

        $otroGrupo = Grupo::factory()->create(['active' => true]);

        $this->actingAs($grupoUser, 'sanctum')
            ->patchJson("/api/grupos/{$otroGrupo->id}/toggle")
            ->assertStatus(403);
    }

    public function test_grupo_user_puede_togglear_sus_propias_taquillas()
    {
        $grupoUser = User::where('email', 'grupo@lotto.com')->first();
        $grupoUser->assignRole('grupo');

        $taquilla = Taquilla::factory()->create([
            'grupo_id' => $grupoUser->grupo_id,
            'active' => true,
        ]);

        $this->actingAs($grupoUser, 'sanctum')
            ->patchJson("/api/taquillas/{$taquilla->id}/toggle")
            ->assertStatus(200)
            ->assertJsonPath('active', false);
    }
}
