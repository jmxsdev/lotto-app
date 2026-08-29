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
 * CORRECCIÓN DE AUDITORÍA (PR 6) — decisión del cliente:
 * UNA TAQUILLA NO PUEDE EXISTIR SIN UN LOCAL ASIGNADO (agencia_id).
 *
 * - store: agencia_id obligatorio (los roles superiores ya no pueden
 *   crear una taquilla "sin local"; el rol agencia lo deriva de su sesión).
 * - update: agencia_id presente no puede ser null (no se puede desasignar
 *   el local); las actualizaciones parciales sin el campo siguen válidas.
 */
class TaquillaLocalObligatorioTest extends TestCase
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
     * @return array{0: Grupo, 1: Agencia}
     */
    private function jerarquia(): array
    {
        $banca = Banca::factory()->create(['active' => true]);
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $local = Agencia::factory()->create(['grupo_id' => $grupo->id, 'active' => true]);

        return [$grupo, $local];
    }

    public function test_store_rechaza_taquilla_sin_agencia_id()
    {
        [$grupo] = $this->jerarquia();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', [
                'name' => 'Taquilla Sin Local',
                'code' => 'TSL01',
                'grupo_id' => $grupo->id,
                'user_name' => 'Usuario Sin Local',
                'user_email' => 'sin-local@test.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('agencia_id');

        $this->assertDatabaseMissing('taquillas', ['code' => 'TSL01']);
    }

    public function test_update_rechaza_desasignar_el_local_con_null()
    {
        [$grupo, $local] = $this->jerarquia();
        $taquilla = Taquilla::factory()->create([
            'grupo_id' => $grupo->id,
            'agencia_id' => $local->id,
            'active' => true,
        ]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson('/api/v1/taquillas/'.$taquilla->id, ['agencia_id' => null]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('agencia_id');

        $this->assertSame($local->id, $taquilla->fresh()->agencia_id);
    }

    // ==================================================
    // TRIANGULACIÓN: casos válidos siguen funcionando
    // ==================================================

    public function test_store_acepta_taquilla_con_agencia_id()
    {
        [$grupo, $local] = $this->jerarquia();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', [
                'name' => 'Taquilla Con Local',
                'code' => 'TCL01',
                'grupo_id' => $grupo->id,
                'agencia_id' => $local->id,
                'user_name' => 'Usuario Con Local',
                'user_email' => 'con-local@test.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('taquilla.agencia_id', $local->id);

        // El usuario taquilla creado también queda vinculado al local
        $this->assertDatabaseHas('users', [
            'email' => 'con-local@test.com',
            'agencia_id' => $local->id,
        ]);
    }

    public function test_update_parcial_sin_agencia_id_sigue_valido()
    {
        [$grupo, $local] = $this->jerarquia();
        $taquilla = Taquilla::factory()->create([
            'grupo_id' => $grupo->id,
            'agencia_id' => $local->id,
            'active' => true,
        ]);

        // Pestaña Monedas/Vigencia del panel: PUT parcial sin agencia_id
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson('/api/v1/taquillas/'.$taquilla->id, ['vigencia_premios' => 3]);

        $response->assertStatus(200)
            ->assertJsonPath('vigencia_premios', 3);

        $this->assertSame($local->id, $taquilla->fresh()->agencia_id);
    }
}
