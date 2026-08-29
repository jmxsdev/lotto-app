<?php

namespace Tests\Feature;

use App\Models\Banca;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PANEL MASTERS (work unit del PR 6, rama feat/jerarquia-agencias-f5).
 *
 * El panel necesita una página /masters donde el super_master lista a los
 * usuarios rol master (super bancas), crea masters y desvincula sus bancas.
 *
 * Contrato:
 * - GET /users?role=master filtra SOLO usuarios con rol master (intersección
 *   con el alcance jerárquico, nunca amplía).
 * - Cada master expone la relación `bancas` (bancas cuyo master_id = su id).
 * - Un master NO puede crear a otro master (el rol master solo lo asigna el
 *   super_master).
 * - Un master no ve a otros masters en el listado (no tiene banca_id).
 * - El filtro role con un valor inválido → 422.
 * - El super puede desvincular una banca de un master (PUT /bancas/{id} con
 *   master_id null), flujo usado por la página.
 */
class MastersPanelTest extends TestCase
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

    private function masterUser(): User
    {
        $master = User::where('email', 'master@lotto.com')->first();
        $master->assignRole('master');

        return $master;
    }

    private function createMaster(string $email): User
    {
        $master = User::factory()->create(['role' => 'master', 'email' => $email, 'active' => true]);
        $master->assignRole('master');

        return $master;
    }

    private function payloadMaster(string $email): array
    {
        return [
            'user_name' => 'Master Nuevo',
            'user_email' => $email,
            'user_password' => 'password123',
            'role' => 'master',
            'active' => true,
        ];
    }

    public function test_super_master_lista_solo_usuarios_con_rol_master()
    {
        $this->createMaster('master-uno@test.com');
        $this->createMaster('master-dos@test.com');
        // Un usuario banca NO debe aparecer en el listado de masters
        Banca::factory()->create(['active' => true]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/users?role=master');

        $response->assertStatus(200);

        $emails = collect($response->json())->pluck('email');
        // Todos los masters (los 2 creados + master@lotto.com del seeder) están
        $this->assertTrue($emails->contains('master-uno@test.com'));
        $this->assertTrue($emails->contains('master-dos@test.com'));
        $this->assertTrue($emails->contains('master@lotto.com'));
        // Y ningún usuario de otro rol se filtra (no hay fuga)
        $this->assertFalse($emails->contains('banca@lotto.com'));
        $this->assertFalse($emails->contains('super@lotto.com'));
        $this->assertFalse($emails->contains('grupo@lotto.com'));
        $this->assertFalse($emails->contains('taquilla@lotto.com'));
    }

    public function test_super_master_crea_un_master_sin_banca()
    {
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/users', $this->payloadMaster('master-creado@test.com'));

        $response->assertStatus(201)
            ->assertJsonPath('role', 'master');

        $this->assertDatabaseHas('users', [
            'email' => 'master-creado@test.com',
            'role' => 'master',
            'banca_id' => null,
        ]);
    }

    public function test_master_sin_bancas_expone_bancas_vacias()
    {
        $this->createMaster('master-solo@test.com');

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/users?role=master');

        $response->assertStatus(200);

        $master = collect($response->json())->firstWhere('email', 'master-solo@test.com');
        $this->assertNotNull($master);
        $this->assertArrayHasKey('bancas', $master);
        $this->assertCount(0, $master['bancas']);
    }

    public function test_master_con_banca_muestra_sus_bancas()
    {
        $master = $this->createMaster('master-con-banca@test.com');
        Banca::factory()->create(['name' => 'Banca del Master', 'master_id' => $master->id, 'active' => true]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/users?role=master');

        $response->assertStatus(200);

        $masterJson = collect($response->json())->firstWhere('email', 'master-con-banca@test.com');
        $this->assertNotNull($masterJson);
        $this->assertCount(1, $masterJson['bancas']);
        $this->assertSame('Banca del Master', $masterJson['bancas'][0]['name']);
    }

    public function test_un_master_no_puede_crear_otro_master()
    {
        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/v1/users', $this->payloadMaster('master-hijo@test.com'));

        $response->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'master-hijo@test.com']);
    }

    public function test_un_master_no_ve_a_otros_masters_en_el_listado()
    {
        $this->createMaster('master-ajeno@test.com');

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/v1/users?role=master');

        $response->assertStatus(200);

        $emails = collect($response->json())->pluck('email');
        $this->assertFalse($emails->contains('master-ajeno@test.com'));
        // El master autenticado (sin banca_id) tampoco se ve a sí mismo en este filtro
        $this->assertFalse($emails->contains('master@lotto.com'));
    }

    public function test_filtro_role_invalido_devuelve_422()
    {
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/users?role=rolinexistente');

        $response->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_super_desvincula_banca_de_un_master()
    {
        $master = $this->createMaster('master-desvincula@test.com');
        $banca = Banca::factory()->create(['master_id' => $master->id, 'active' => true]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson('/api/v1/bancas/'.$banca->id, ['master_id' => null]);

        $response->assertStatus(200);
        $this->assertNull($banca->fresh()->master_id);

        // El master ya no muestra esa banca
        $listado = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/users?role=master');
        $masterJson = collect($listado->json())->firstWhere('email', 'master-desvincula@test.com');
        $this->assertNotNull($masterJson);
        $this->assertCount(0, $masterJson['bancas']);
    }
}
