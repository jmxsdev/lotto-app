<?php

namespace Tests\Feature;

use App\Models\Banca;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CORRECCIÓN DE AUDITORÍA (PR 6) — validación de rol del master en bancas.
 *
 * BancaController aceptaba cualquier master_id (usuario sin rol master).
 * Ahora master_id debe apuntar a un usuario con rol master → 422 si no.
 * Aplica en store y update.
 */
class BancaMasterIdTest extends TestCase
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

    private function noMasterUser(): User
    {
        $user = User::where('email', 'banca@lotto.com')->first();
        $user->assignRole('banca');

        return $user;
    }

    public function test_store_rechaza_master_id_de_usuario_sin_rol_master()
    {
        $noMaster = $this->noMasterUser();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/bancas', [
                'name' => 'Banca Sin Master Valido',
                'code' => 'BMNV01',
                'master_id' => $noMaster->id,
                'user_name' => 'Usuario Banca',
                'user_email' => 'banca-mnv@test.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('master_id');

        $this->assertDatabaseMissing('bancas', ['code' => 'BMNV01']);
    }

    public function test_store_acepta_master_id_de_usuario_con_rol_master()
    {
        $master = $this->masterUser();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/bancas', [
                'name' => 'Banca Master Valido',
                'code' => 'BMV01',
                'master_id' => $master->id,
                'user_name' => 'Usuario Banca',
                'user_email' => 'banca-mv@test.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('banca.master_id', $master->id);

        $this->assertDatabaseHas('bancas', ['code' => 'BMV01', 'master_id' => $master->id]);
    }

    public function test_update_rechaza_master_id_de_usuario_sin_rol_master()
    {
        $super = $this->superUser();
        $noMaster = $this->noMasterUser();
        $banca = Banca::factory()->create(['active' => true]);

        $response = $this->actingAs($super, 'sanctum')
            ->putJson('/api/v1/bancas/'.$banca->id, ['master_id' => $noMaster->id]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('master_id');

        $this->assertNull($banca->fresh()->master_id);
    }

    public function test_update_acepta_master_id_de_usuario_con_rol_master()
    {
        $super = $this->superUser();
        $master = $this->masterUser();
        $banca = Banca::factory()->create(['active' => true]);

        $response = $this->actingAs($super, 'sanctum')
            ->putJson('/api/v1/bancas/'.$banca->id, ['master_id' => $master->id]);

        $response->assertStatus(200)
            ->assertJsonPath('master_id', $master->id);
    }
}
