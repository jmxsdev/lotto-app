<?php

namespace Tests\Feature;

use App\Models\Banca;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Escrituras de rol vía Spatie en la API de usuarios.
 *
 * El rol solo se persiste en `model_has_roles` (syncRoles); la columna
 * `users.role` fue eliminada en WU-7. `index` filtra con el scope Spatie
 * `role()`; el login sigue exponiendo `role`/`user.role` desde el accessor
 * calculado.
 */
class UsuarioRolApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function superUser(): User
    {
        return User::factory()->withRole('super_master')->create();
    }

    public function test_store_asigna_rol_spatie(): void
    {
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/users', [
                'user_name' => 'Master Nuevo',
                'user_email' => 'master-nuevo@test.com',
                'user_password' => 'password123',
                'role' => 'master',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('role', 'master');

        $created = User::where('email', 'master-nuevo@test.com')->firstOrFail();

        $this->assertTrue($created->hasRole('master'));
        $this->assertSame(['master'], $created->getRoleNames()->all());

        $roleId = Role::where('name', 'master')->where('guard_name', 'api')->value('id');
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $roleId,
            'model_id' => $created->id,
            'model_type' => User::class,
        ]);
    }

    public function test_store_con_vinculo_asigna_rol_spatie(): void
    {
        $banca = Banca::factory()->create();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/users', [
                'user_name' => 'Banca Nueva',
                'user_email' => 'banca-nueva@test.com',
                'user_password' => 'password123',
                'role' => 'banca',
                'banca_id' => $banca->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('role', 'banca')
            ->assertJsonPath('banca_id', $banca->id);

        $created = User::where('email', 'banca-nueva@test.com')->firstOrFail();
        $this->assertTrue($created->hasRole('banca'));
    }

    public function test_update_cambia_rol_spatie_en_transaccion(): void
    {
        $banca = Banca::factory()->create();
        $target = User::factory()->withRole('banca')->create(['banca_id' => $banca->id]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, ['role' => 'master']);

        $response->assertStatus(200)
            ->assertJsonPath('role', 'master');

        $target->refresh();

        $this->assertTrue($target->hasRole('master'));
        $this->assertFalse($target->hasRole('banca'));
        $this->assertSame(['master'], $target->getRoleNames()->all());
    }

    public function test_index_filtra_por_rol_spatie(): void
    {
        $super = $this->superUser();

        $master = User::factory()->withRole('master')->create(['email' => 'master-spatie@test.com']);
        $taquilla = User::factory()->withRole('taquilla')->create(['email' => 'taquilla-spatie@test.com']);

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/users?role=master');

        $response->assertStatus(200);

        $emails = collect($response->json())->pluck('email');

        $this->assertTrue($emails->contains($master->email));
        $this->assertFalse($emails->contains($taquilla->email));
    }

    public function test_login_panel_devuelve_role_de_spatie(): void
    {
        $user = User::factory()->withRole('master')->create([
            'email' => 'master-login@test.com',
            'password' => bcrypt('password'),
        ]);

        $response = $this->withHeaders(['X-Panel' => 'true'])
            ->postJson('/api/v1/login', [
                'email' => 'master-login@test.com',
                'password' => 'password',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('role', 'master')
            ->assertJsonPath('user.role', 'master');
    }

    public function test_update_sin_rol_final_devuelve_403(): void
    {
        $target = User::factory()->create(); // sin rol Spatie (fail-closed)

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, ['name' => 'Sin Rol Actualizado']);

        $response->assertStatus(403);
        $this->assertNotSame('Sin Rol Actualizado', $target->fresh()->name);
    }
}
