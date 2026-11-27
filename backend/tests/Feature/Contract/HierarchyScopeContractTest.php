<?php

namespace Tests\Feature\Contract;

use App\Models\Banca;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contrato de alcance jerárquico.
 *
 * Construye dos árboles independientes y verifica que un master solo ve su
 * propio subárbol, tanto en usuarios como en bancas. Es la red que detecta
 * cualquier regresión del alcance durante el refactor.
 */
final class HierarchyScopeContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function master(): User
    {
        return User::factory()->withRole('master')->create();
    }

    private function bancaFor(User $master, string $code): Banca
    {
        return Banca::factory()->create([
            'master_id' => $master->id,
            'code' => $code,
        ]);
    }

    private function bancaUser(Banca $banca, string $email): User
    {
        return User::factory()->withRole('banca')->create([
            'banca_id' => $banca->id,
            'email' => $email,
        ]);
    }

    public function test_master_solo_ve_sus_usuarios(): void
    {
        $masterA = $this->master();
        $bancaA = $this->bancaFor($masterA, 'BCA001');
        $userA = $this->bancaUser($bancaA, 'user-a@test.com');

        $masterB = $this->master();
        $bancaB = $this->bancaFor($masterB, 'BCB001');
        $userB = $this->bancaUser($bancaB, 'user-b@test.com');

        $response = $this->actingAs($masterA, 'sanctum')->getJson('/api/v1/users');

        $response->assertOk();

        $emails = collect($response->json())->pluck('email');

        $this->assertTrue($emails->contains($userA->email));
        $this->assertFalse($emails->contains($userB->email));
    }

    public function test_master_solo_ve_sus_bancas(): void
    {
        $masterA = $this->master();
        $bancaA = $this->bancaFor($masterA, 'BCA001');

        $masterB = $this->master();
        $bancaB = $this->bancaFor($masterB, 'BCB001');

        $response = $this->actingAs($masterA, 'sanctum')->getJson('/api/v1/bancas');

        $response->assertOk();

        $ids = collect($response->json())->pluck('id');

        $this->assertTrue($ids->contains($bancaA->id));
        $this->assertFalse($ids->contains($bancaB->id));
    }

    public function test_super_master_ve_todas_las_bancas(): void
    {
        $masterA = $this->master();
        $bancaA = $this->bancaFor($masterA, 'BCA001');

        $masterB = $this->master();
        $bancaB = $this->bancaFor($masterB, 'BCB001');

        $super = User::factory()->withRole('super_master')->create();

        $response = $this->actingAs($super, 'sanctum')->getJson('/api/v1/bancas');

        $response->assertOk();

        $ids = collect($response->json())->pluck('id');

        $this->assertTrue($ids->contains($bancaA->id));
        $this->assertTrue($ids->contains($bancaB->id));
    }
}
