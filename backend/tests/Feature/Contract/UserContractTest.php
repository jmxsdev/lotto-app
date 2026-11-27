<?php

namespace Tests\Feature\Contract;

use App\Models\Banca;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contrato HTTP de usuarios.
 *
 * Congela la creación de un operador con vínculo y el filtro por rol del
 * listado. No prueba la inmutabilidad futura del rol ni el system_admin:
 * eso llega en fases posteriores.
 */
final class UserContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_store_banca_user_devuelve_201_y_vinculo(): void
    {
        $super = User::factory()->withRole('super_master')->create();
        $banca = Banca::factory()->create();

        $response = $this->actingAs($super, 'sanctum')->postJson('/api/v1/users', [
            'user_name' => 'Banca Contrato',
            'user_email' => 'banca-contrato@test.com',
            'user_password' => 'password123',
            'role' => 'banca',
            'banca_id' => $banca->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('role', 'banca')
            ->assertJsonPath('banca_id', $banca->id)
            ->assertJsonPath('email', 'banca-contrato@test.com');

        $this->assertDatabaseHas('users', [
            'email' => 'banca-contrato@test.com',
            'banca_id' => $banca->id,
        ]);
    }

    public function test_index_filtra_por_rol_y_no_amplia_el_alcance(): void
    {
        $super = User::factory()->withRole('super_master')->create();
        $master = User::factory()->withRole('master')->create(['email' => 'master-contrato@test.com']);
        $taquilla = User::factory()->withRole('taquilla')->create(['email' => 'taquilla-contrato@test.com']);

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/users?role=master');

        $response->assertOk();

        $emails = collect($response->json())->pluck('email');

        $this->assertTrue($emails->contains($master->email));
        $this->assertFalse($emails->contains($taquilla->email));
    }
}
