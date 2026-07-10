<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Taquilla;
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
        $banca = Banca::factory()->create();
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id]);
        $taquilla = Taquilla::factory()->create(['grupo_id' => $grupo->id]);

        $userTaquilla = User::factory()->create([
            'role' => 'taquilla',
            'banca_id' => $banca->id,
            'grupo_id' => $grupo->id,
            'taquilla_id' => $taquilla->id,
        ]);
        $userTaquilla->assignRole('taquilla');

        $userMaster = User::factory()->create([
            'role' => 'master',
            'banca_id' => $banca->id,
        ]);
        $userMaster->assignRole('master');

        $response = $this->actingAs($userTaquilla, 'sanctum')
                         ->getJson('/api/users');
        $response->assertStatus(403);

        $response = $this->actingAs($userMaster, 'sanctum')
                         ->getJson('/api/users');
        $response->assertStatus(200);
        $response->assertJsonCount(2);
    }

    public function test_banca_can_only_create_groups_in_its_own_banca()
    {
        $banca1 = Banca::factory()->create();
        $banca2 = Banca::factory()->create();

        $userBanca = User::factory()->create([
            'role' => 'banca',
            'banca_id' => $banca1->id,
        ]);
        $userBanca->assignRole('banca');

        $response = $this->actingAs($userBanca, 'sanctum')
                         ->postJson('/api/grupos', [
                             'name' => 'Grupo Prueba',
                             'code' => 'GP001',
                             'banca_id' => $banca1->id,
                             'active' => true,
                         ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('grupos', ['code' => 'GP001', 'banca_id' => $banca1->id]);

        $response = $this->actingAs($userBanca, 'sanctum')
                         ->postJson('/api/grupos', [
                             'name' => 'Grupo Ajeno',
                             'code' => 'GA001',
                             'banca_id' => $banca2->id,
                             'active' => true,
                         ]);
        $response->assertStatus(403);
        $this->assertDatabaseMissing('grupos', ['code' => 'GA001']);
    }

    public function test_grupo_can_only_create_taquillas_in_its_own_grupo()
    {
        $banca = Banca::factory()->create();
        $grupo1 = Grupo::factory()->create(['banca_id' => $banca->id]);
        $grupo2 = Grupo::factory()->create(['banca_id' => $banca->id]);

        $userGrupo = User::factory()->create([
            'role' => 'grupo',
            'banca_id' => $banca->id,
            'grupo_id' => $grupo1->id,
        ]);
        $userGrupo->assignRole('grupo');

        $response = $this->actingAs($userGrupo, 'sanctum')
                         ->postJson('/api/taquillas', [
                             'name' => 'Taquilla 01',
                             'code' => 'T001',
                             'grupo_id' => $grupo1->id,
                             'active' => true,
                         ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('taquillas', ['code' => 'T001', 'grupo_id' => $grupo1->id]);

        $response = $this->actingAs($userGrupo, 'sanctum')
                         ->postJson('/api/taquillas', [
                             'name' => 'Taquilla Ajeno',
                             'code' => 'TA001',
                             'grupo_id' => $grupo2->id,
                             'active' => true,
                         ]);
        $response->assertStatus(403);
        $this->assertDatabaseMissing('taquillas', ['code' => 'TA001']);
    }
}
