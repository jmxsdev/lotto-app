<?php

namespace Tests\Feature;

use App\Models\Banca;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * WU-2 — escrituras de rol vía Spatie en la API de usuarios.
 *
 * El rol solo se persiste en `model_has_roles` (syncRoles): `users.role` deja
 * de escribirse; `index` filtra con el scope Spatie `role()`; el login sigue
 * exponiendo `role`/`user.role` desde el accessor calculado.
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

    public function test_store_asigna_rol_spatie_sin_escribir_columna(): void
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

        // La columna legacy (si existe) no recibe el rol nuevo: queda en su
        // default de esquema (taquilla o null).
        if (Schema::hasColumn('users', 'role')) {
            $this->assertNotSame('master', DB::table('users')->where('id', $created->id)->value('role'));
        }
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

        if (Schema::hasColumn('users', 'role')) {
            $this->assertNotSame('banca', DB::table('users')->where('id', $created->id)->value('role'));
        }
    }

    public function test_update_cambia_rol_spatie_en_transaccion(): void
    {
        $banca = Banca::factory()->create();
        $target = User::factory()->withRole('banca')->create(['banca_id' => $banca->id]);

        // Sentinela: si el update escribiera `users.role`, lo pisaría.
        if (Schema::hasColumn('users', 'role')) {
            DB::table('users')->where('id', $target->id)->update(['role' => 'banca']);
        }

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, ['role' => 'master']);

        $response->assertStatus(200)
            ->assertJsonPath('role', 'master');

        $target->refresh();

        $this->assertTrue($target->hasRole('master'));
        $this->assertFalse($target->hasRole('banca'));
        $this->assertSame(['master'], $target->getRoleNames()->all());

        if (Schema::hasColumn('users', 'role')) {
            $this->assertSame('banca', DB::table('users')->where('id', $target->id)->value('role'));
        }
    }

    public function test_index_filtra_por_rol_spatie(): void
    {
        $super = $this->superUser();

        $master = User::factory()->withRole('master')->create(['email' => 'master-spatie@test.com']);
        $taquilla = User::factory()->withRole('taquilla')->create(['email' => 'taquilla-spatie@test.com']);

        // Divergencia: columna `master`, Spatie `taquilla` → no debe aparecer.
        $divergente = User::factory()->withRole('taquilla')->create(['email' => 'divergente@test.com']);
        if (Schema::hasColumn('users', 'role')) {
            DB::table('users')->where('id', $divergente->id)->update(['role' => 'master']);
        }

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/users?role=master');

        $response->assertStatus(200);

        $emails = collect($response->json())->pluck('email');

        $this->assertTrue($emails->contains($master->email));
        $this->assertFalse($emails->contains($taquilla->email));
        $this->assertFalse($emails->contains($divergente->email));
    }

    public function test_login_panel_devuelve_role_de_spatie(): void
    {
        $user = User::factory()->withRole('master')->create([
            'email' => 'master-login@test.com',
            'password' => bcrypt('password'),
        ]);

        // Divergencia de columna: el login debe seguir resolviendo Spatie.
        if (Schema::hasColumn('users', 'role')) {
            DB::table('users')->where('id', $user->id)->update(['role' => 'taquilla']);
        }

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
