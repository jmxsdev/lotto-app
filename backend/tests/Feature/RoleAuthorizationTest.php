<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Banca;
use App\Models\Grupo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_taquilla_cannot_list_users_but_master_can()
    {
        $taquillaUser = User::factory()->create(['role' => 'taquilla']);
        $taquillaUser->assignRole('taquilla');

        $response = $this->actingAs($taquillaUser, 'sanctum')
                         ->getJson('/api/users');

        $response->assertStatus(403);

        $master = User::where('email', 'master@lotto.com')->first();
        $master->assignRole('master');

        $response = $this->actingAs($master, 'sanctum')
                         ->getJson('/api/users');

        $response->assertStatus(200);
    }

    public function test_banca_can_only_create_groups_in_its_own_banca()
    {
        $banca1 = Banca::factory()->create(['name' => 'Banca 1']);
        $banca2 = Banca::factory()->create(['name' => 'Banca 2']);

        $userBanca1 = User::factory()->create([
            'role' => 'banca',
            'banca_id' => $banca1->id,
        ]);
        $userBanca1->assignRole('banca');

        // Intentar crear grupo en banca 2 (debe fallar)
        $response = $this->actingAs($userBanca1, 'sanctum')
                         ->postJson('/api/grupos', [
                             'name' => 'Grupo Test',
                             'code' => 'GT001',
                             'banca_id' => $banca2->id,
                         ]);

        $response->assertStatus(403);

        // Intentar crear grupo en banca 1 (debe funcionar)
        $response = $this->actingAs($userBanca1, 'sanctum')
                         ->postJson('/api/grupos', [
                             'name' => 'Grupo Test',
                             'code' => 'GT001',
                             'banca_id' => $banca1->id,
                         ]);

        $response->assertStatus(201);
    }

    public function test_grupo_can_only_create_taquillas_in_its_own_grupo()
    {
        $banca = Banca::factory()->create();
        $grupo1 = Grupo::factory()->create(['name' => 'Grupo 1', 'banca_id' => $banca->id]);
        $grupo2 = Grupo::factory()->create(['name' => 'Grupo 2', 'banca_id' => $banca->id]);

        $userGrupo1 = User::factory()->create([
            'role' => 'grupo',
            'grupo_id' => $grupo1->id,
        ]);
        $userGrupo1->assignRole('grupo');

        // Intentar crear taquilla en grupo 2 (debe fallar)
        $response = $this->actingAs($userGrupo1, 'sanctum')
                         ->postJson('/api/taquillas', [
                             'name' => 'Taquilla Test',
                             'code' => 'TT001',
                             'grupo_id' => $grupo2->id,
                         ]);

        $response->assertStatus(403);

        // Intentar crear taquilla en grupo 1 (debe funcionar)
        $response = $this->actingAs($userGrupo1, 'sanctum')
                         ->postJson('/api/taquillas', [
                             'name' => 'Taquilla Test',
                             'code' => 'TT001',
                             'grupo_id' => $grupo1->id,
                         ]);

        $response->assertStatus(201);
    }
}
