<?php

namespace Tests\Unit;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Rol de fuente única: el accessor `User::role` sale siempre de Spatie
 * (`getRoleNames()`); la columna legacy `users.role` ya no existe (WU-7).
 */
class UserRoleAccessorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_accessor_devuelve_rol_spatie(): void
    {
        $user = User::factory()->withRole('agencia')->create();

        $this->assertSame('agencia', $user->role);
        $this->assertSame('agencia', $user->primaryRole());
    }

    public function test_accessor_devuelve_null_sin_rol(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->primaryRole());
        $this->assertNull($user->role);
        $this->assertNull($user->toArray()['role']);
    }

    public function test_primary_role_respeta_prioridad(): void
    {
        Role::firstOrCreate(['name' => 'auditor', 'guard_name' => 'api']);
        Role::firstOrCreate(['name' => 'zebra', 'guard_name' => 'api']);

        $user = User::factory()->create();

        $user->syncRoles(['taquilla', 'agencia', 'grupo', 'banca', 'master', 'super_master']);
        $this->assertSame('super_master', $user->primaryRole());

        $user->syncRoles(['taquilla', 'agencia', 'grupo', 'banca']);
        $this->assertSame('banca', $user->primaryRole());

        $user->syncRoles(['taquilla', 'agencia', 'grupo']);
        $this->assertSame('grupo', $user->primaryRole());

        $user->syncRoles(['taquilla', 'agencia']);
        $this->assertSame('agencia', $user->primaryRole());

        $user->syncRoles(['taquilla', 'master']);
        $this->assertSame('master', $user->primaryRole());

        $user->syncRoles(['taquilla']);
        $this->assertSame('taquilla', $user->primaryRole());

        // Fuera de catálogo: primer nombre ordenado, sin inventar un rol.
        $user->syncRoles(['zebra', 'auditor']);
        $this->assertSame('auditor', $user->primaryRole());
    }

    public function test_role_se_serializa_en_to_array(): void
    {
        $user = User::factory()->withRole('banca')->create();

        $array = $user->toArray();

        $this->assertArrayHasKey('role', $array);
        $this->assertSame('banca', $array['role']);
    }

    public function test_factory_sin_rol_por_defecto(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], $user->getRoleNames()->all());
        $this->assertSame(0, $user->roles()->count());
    }

    public function test_factory_with_role_asigna_un_solo_rol(): void
    {
        $user = User::factory()->withRole('master')->create();

        $this->assertSame(['master'], $user->getRoleNames()->all());
        $this->assertSame(1, $user->roles()->count());

        // `syncRoles` reemplaza: dos withRole no acumulan roles.
        $user = User::factory()->withRole('taquilla')->withRole('grupo')->create();

        $this->assertSame(['grupo'], $user->getRoleNames()->all());
    }
}
