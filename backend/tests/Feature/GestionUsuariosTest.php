<?php

namespace Tests\Feature;

use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PR 3 — gestion-usuarios (backend).
 *
 * El UserController acepta vínculos de entidad (banca_id/grupo_id/taquilla_id)
 * con autorización jerárquica, derivación automática de la cadena y
 * consistencia rol ↔ vínculo. El index y el update respetan el alcance.
 */
class GestionUsuariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
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

    private function bancaSeeded(): Banca
    {
        return Banca::where('code', 'BT001')->first();
    }

    private function grupoSeeded(): Grupo
    {
        return Grupo::where('code', 'GT001')->first();
    }

    private function taquillaSeeded(): Taquilla
    {
        return Taquilla::where('code', 'TT001')->first();
    }

    private function payloadUsuario(array $overrides = []): array
    {
        return array_merge([
            'user_name' => 'Usuario Nuevo',
            'user_email' => 'nuevo-' . uniqid() . '@lotto.com',
            'user_password' => 'password123',
        ], $overrides);
    }

    public function test_master_puede_crear_usuario_adicional_para_una_banca()
    {
        $banca = $this->bancaSeeded();

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/v1/users', $this->payloadUsuario([
                'role' => 'banca',
                'banca_id' => $banca->id,
            ]));

        $response->assertStatus(201)
            ->assertJsonPath('role', 'banca')
            ->assertJsonPath('banca_id', $banca->id);

        $this->assertDatabaseHas('users', [
            'email' => $response->json('email'),
            'role' => 'banca',
            'banca_id' => $banca->id,
        ]);
    }

    public function test_banca_puede_crear_usuario_para_uno_de_sus_grupos()
    {
        $grupo = $this->grupoSeeded();

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->postJson('/api/v1/users', $this->payloadUsuario([
                'role' => 'grupo',
                'grupo_id' => $grupo->id,
            ]));

        $response->assertStatus(201)
            ->assertJsonPath('role', 'grupo')
            ->assertJsonPath('grupo_id', $grupo->id);

        $this->assertDatabaseHas('users', [
            'email' => $response->json('email'),
            'role' => 'grupo',
            'grupo_id' => $grupo->id,
            'banca_id' => $grupo->banca_id,
        ]);
    }

    public function test_grupo_puede_crear_usuario_para_una_de_sus_agencias()
    {
        $taquilla = $this->taquillaSeeded();

        $response = $this->actingAs($this->grupoUser(), 'sanctum')
            ->postJson('/api/v1/users', $this->payloadUsuario([
                'role' => 'taquilla',
                'taquilla_id' => $taquilla->id,
            ]));

        $response->assertStatus(201)
            ->assertJsonPath('role', 'taquilla')
            ->assertJsonPath('taquilla_id', $taquilla->id);

        $this->assertDatabaseHas('users', [
            'email' => $response->json('email'),
            'role' => 'taquilla',
            'taquilla_id' => $taquilla->id,
            'grupo_id' => $taquilla->grupo_id,
            'banca_id' => $taquilla->grupo->banca_id,
        ]);
    }

    public function test_banca_no_puede_crear_usuario_para_otra_banca()
    {
        $otraBanca = Banca::create(['name' => 'Otra Banca', 'code' => 'OB001', 'created_by' => $this->superUser()->id]);
        $otroGrupo = Grupo::create(['name' => 'Grupo Otra Banca', 'code' => 'GOB01', 'banca_id' => $otraBanca->id, 'created_by' => $this->superUser()->id]);

        // Vínculo directo a otra banca
        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->postJson('/api/v1/users', $this->payloadUsuario([
                'role' => 'banca',
                'banca_id' => $otraBanca->id,
            ]));

        $response->assertStatus(403);

        // Vínculo a un grupo de otra banca
        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->postJson('/api/v1/users', $this->payloadUsuario([
                'role' => 'grupo',
                'grupo_id' => $otroGrupo->id,
            ]));

        $response->assertStatus(403);
    }

    public function test_grupo_no_puede_crear_usuario_para_agencia_de_otro_grupo()
    {
        $banca = $this->bancaSeeded();
        $otroGrupo = Grupo::create(['name' => 'Otro Grupo', 'code' => 'OG001', 'banca_id' => $banca->id, 'created_by' => $this->superUser()->id]);
        $otraTaquilla = Taquilla::create([
            'name' => 'Otra Agencia',
            'code' => 'OT001',
            'grupo_id' => $otroGrupo->id,
            'activation_code' => 'OTRA-CODE',
            'active' => true,
            'created_by' => $this->superUser()->id,
        ]);

        $response = $this->actingAs($this->grupoUser(), 'sanctum')
            ->postJson('/api/v1/users', $this->payloadUsuario([
                'role' => 'taquilla',
                'taquilla_id' => $otraTaquilla->id,
            ]));

        $response->assertStatus(403);
    }

    public function test_usuario_sin_vinculo_de_entidad_recibe_422()
    {
        // Rol banca sin banca_id
        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/v1/users', $this->payloadUsuario(['role' => 'banca']));

        $response->assertStatus(422)
            ->assertJsonValidationErrors('banca_id');

        // Rol grupo sin grupo_id
        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/v1/users', $this->payloadUsuario(['role' => 'grupo']));

        $response->assertStatus(422)
            ->assertJsonValidationErrors('grupo_id');

        // Rol taquilla sin taquilla_id
        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/v1/users', $this->payloadUsuario(['role' => 'taquilla']));

        $response->assertStatus(422)
            ->assertJsonValidationErrors('taquilla_id');
    }

    public function test_taquilla_id_deriva_banca_y_grupo_automaticamente()
    {
        $taquilla = $this->taquillaSeeded();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/users', $this->payloadUsuario([
                'role' => 'taquilla',
                'taquilla_id' => $taquilla->id,
            ]));

        $response->assertStatus(201)
            ->assertJsonPath('taquilla_id', $taquilla->id)
            ->assertJsonPath('grupo_id', $taquilla->grupo_id)
            ->assertJsonPath('banca_id', $taquilla->grupo->banca_id);
    }

    public function test_varios_usuarios_pueden_vincularse_a_la_misma_entidad()
    {
        $taquilla = $this->taquillaSeeded();

        $primero = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/users', $this->payloadUsuario([
                'role' => 'taquilla',
                'taquilla_id' => $taquilla->id,
            ]));

        $primero->assertStatus(201);

        $segundo = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/users', $this->payloadUsuario([
                'role' => 'taquilla',
                'taquilla_id' => $taquilla->id,
            ]));

        $segundo->assertStatus(201);

        // taquilla@lotto.com (seeder) + los dos nuevos
        $this->assertEquals(3, User::where('taquilla_id', $taquilla->id)->count());
    }

    public function test_index_banca_solo_ve_usuarios_de_su_banca()
    {
        $otraBanca = Banca::create(['name' => 'Banca Ajena', 'code' => 'BA001', 'created_by' => $this->superUser()->id]);
        $usuarioAjeno = User::factory()->create([
            'role' => 'banca',
            'banca_id' => $otraBanca->id,
        ]);
        $usuarioAjeno->assignRole('banca');

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->getJson('/api/v1/users');

        $response->assertStatus(200);

        $emails = collect($response->json())->pluck('email');

        // Usuarios de su banca (vía cadena)
        $this->assertContains('banca@lotto.com', $emails);
        $this->assertContains('grupo@lotto.com', $emails);
        $this->assertContains('taquilla@lotto.com', $emails);
        $this->assertContains('demo@lotto.com', $emails);

        // Fuera de su alcance
        $this->assertNotContains('super@lotto.com', $emails);
        $this->assertNotContains('master@lotto.com', $emails);
        $this->assertNotContains($usuarioAjeno->email, $emails);
    }

    public function test_index_grupo_solo_ve_usuarios_de_su_grupo()
    {
        $response = $this->actingAs($this->grupoUser(), 'sanctum')
            ->getJson('/api/v1/users');

        $response->assertStatus(200);

        $emails = collect($response->json())->pluck('email');

        // Usuarios de su grupo y sus agencias
        $this->assertContains('grupo@lotto.com', $emails);
        $this->assertContains('taquilla@lotto.com', $emails);
        $this->assertContains('demo@lotto.com', $emails);

        // Fuera de su alcance
        $this->assertNotContains('banca@lotto.com', $emails);
        $this->assertNotContains('super@lotto.com', $emails);
        $this->assertNotContains('master@lotto.com', $emails);
    }

    public function test_banca_no_puede_eliminar_usuarios()
    {
        $taquillaUser = User::where('email', 'taquilla@lotto.com')->first();

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->deleteJson('/api/v1/users/' . $taquillaUser->id);

        $response->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $taquillaUser->id]);
    }

    public function test_master_puede_eliminar_usuarios_de_su_alcance()
    {
        $master = $this->masterUser();
        $master->update(['banca_id' => $this->bancaSeeded()->id]);

        $taquillaUser = User::where('email', 'taquilla@lotto.com')->first();

        $response = $this->actingAs($master, 'sanctum')
            ->deleteJson('/api/v1/users/' . $taquillaUser->id);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('users', ['id' => $taquillaUser->id]);
    }

    public function test_grupo_puede_actualizar_usuarios_de_su_grupo()
    {
        $taquillaUser = User::where('email', 'taquilla@lotto.com')->first();

        $response = $this->actingAs($this->grupoUser(), 'sanctum')
            ->putJson('/api/v1/users/' . $taquillaUser->id, [
                'name' => 'Agencia Renombrada',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Agencia Renombrada');
    }

    public function test_banca_no_puede_actualizar_usuarios_de_otra_banca()
    {
        $otraBanca = Banca::create(['name' => 'Banca Ajena U', 'code' => 'BAU01', 'created_by' => $this->superUser()->id]);
        $usuarioAjeno = User::factory()->create([
            'role' => 'taquilla',
            'banca_id' => $otraBanca->id,
        ]);
        $usuarioAjeno->assignRole('taquilla');

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->putJson('/api/v1/users/' . $usuarioAjeno->id, [
                'name' => 'Intento de Cambio',
            ]);

        $response->assertStatus(403);
    }

    public function test_grupo_puede_revincular_usuario_a_otra_agencia_de_su_grupo()
    {
        $grupo = $this->grupoSeeded();
        $nuevaTaquilla = Taquilla::create([
            'name' => 'Agencia Nueva',
            'code' => 'AN001',
            'grupo_id' => $grupo->id,
            'activation_code' => 'AN001-CODE',
            'active' => true,
            'created_by' => $this->superUser()->id,
        ]);

        $taquillaUser = User::where('email', 'taquilla@lotto.com')->first();

        $response = $this->actingAs($this->grupoUser(), 'sanctum')
            ->putJson('/api/v1/users/' . $taquillaUser->id, [
                'taquilla_id' => $nuevaTaquilla->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('taquilla_id', $nuevaTaquilla->id);

        $this->assertDatabaseHas('users', [
            'id' => $taquillaUser->id,
            'taquilla_id' => $nuevaTaquilla->id,
            'grupo_id' => $grupo->id,
            'banca_id' => $grupo->banca_id,
        ]);
    }

    public function test_banca_no_puede_asignar_rol_master()
    {
        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->postJson('/api/v1/users', $this->payloadUsuario([
                'role' => 'master',
            ]));

        $response->assertStatus(403);
    }
}
