<?php

namespace Tests\Feature;

use App\Models\Agencia;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CORRECCIÓN DE AUDITORÍA (PR 6) — consistencia grupo/local en taquillas.
 *
 * Al crear o actualizar una taquilla, el local (agencia_id) debe pertenecer
 * al grupo indicado (o al grupo actual de la taquilla). Un local de otro
 * grupo es un estado inconsistente de la jerarquía → 422
 * 'El local no pertenece al grupo indicado.'
 */
class TaquillaGrupoAgenciaConsistenciaTest extends TestCase
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

    /**
     * Jerarquía con dos grupos (misma banca) y un local en cada uno.
     *
     * @return array{0: Grupo, 1: Agencia, 2: Grupo, 3: Agencia}
     */
    private function jerarquia(): array
    {
        $banca = Banca::factory()->create(['active' => true]);
        $grupoX = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $localX = Agencia::factory()->create(['grupo_id' => $grupoX->id, 'active' => true]);

        $grupoY = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $localY = Agencia::factory()->create(['grupo_id' => $grupoY->id, 'active' => true]);

        return [$grupoX, $localX, $grupoY, $localY];
    }

    public function test_store_rechaza_taquilla_con_grupo_y_local_de_grupos_distintos()
    {
        [$grupoX, , $grupoY, $localY] = $this->jerarquia();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', [
                'name' => 'Taquilla Inconsistente',
                'code' => 'TINC01',
                'grupo_id' => $grupoX->id,
                'agencia_id' => $localY->id,
                'user_name' => 'Usuario Inconsistente',
                'user_email' => 'inconsistente@test.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'El local no pertenece al grupo indicado.');

        $this->assertDatabaseMissing('taquillas', ['code' => 'TINC01']);
    }

    public function test_update_rechaza_asignar_local_de_otro_grupo_sin_cambiar_grupo()
    {
        [$grupoX, $localX, , $localY] = $this->jerarquia();
        $taquilla = Taquilla::factory()->create([
            'grupo_id' => $grupoX->id,
            'agencia_id' => $localX->id,
            'active' => true,
        ]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson('/api/v1/taquillas/'.$taquilla->id, ['agencia_id' => $localY->id]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'El local no pertenece al grupo indicado.');

        $this->assertSame($localX->id, $taquilla->fresh()->agencia_id);
    }

    public function test_update_rechaza_mover_taquilla_a_grupo_que_no_contiene_al_local()
    {
        [$grupoX, $localX, $grupoY] = $this->jerarquia();
        $taquilla = Taquilla::factory()->create([
            'grupo_id' => $grupoX->id,
            'agencia_id' => $localX->id,
            'active' => true,
        ]);

        // Cambiar grupo a Y pero dejar el local de X: inconsistente
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson('/api/v1/taquillas/'.$taquilla->id, [
                'grupo_id' => $grupoY->id,
                'agencia_id' => $localX->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'El local no pertenece al grupo indicado.');

        $this->assertSame($grupoX->id, $taquilla->fresh()->grupo_id);
    }

    // ==================================================
    // TRIANGULACIÓN: pares consistentes siguen funcionando
    // ==================================================

    public function test_store_acepta_grupo_y_local_consistentes()
    {
        [$grupoX, $localX] = $this->jerarquia();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', [
                'name' => 'Taquilla Consistente',
                'code' => 'TCONS01',
                'grupo_id' => $grupoX->id,
                'agencia_id' => $localX->id,
                'user_name' => 'Usuario Consistente',
                'user_email' => 'consistente@test.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('taquilla.agencia_id', $localX->id);
    }

    public function test_update_acepta_cambiar_grupo_y_local_en_pareja()
    {
        [$grupoX, $localX, $grupoY, $localY] = $this->jerarquia();
        $taquilla = Taquilla::factory()->create([
            'grupo_id' => $grupoX->id,
            'agencia_id' => $localX->id,
            'active' => true,
        ]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson('/api/v1/taquillas/'.$taquilla->id, [
                'grupo_id' => $grupoY->id,
                'agencia_id' => $localY->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('grupo_id', $grupoY->id)
            ->assertJsonPath('agencia_id', $localY->id);
    }
}
