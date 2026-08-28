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
 * PR 5 (F4) — asignación del local (agencia) a una taquilla existente.
 *
 * El detalle de taquilla del panel permite elegir/asignar el local
 * (agencia_id). El backend debe aceptar agencia_id en PUT /taquillas/{id}
 * con la misma autorización jerárquica que el store (F1): el rol agencia
 * solo puede asignar SU local; los roles superiores asignan dentro de su
 * alcance (authorizeAgenciaAccess).
 */
class TaquillaAgenciaIdTest extends TestCase
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
     * Jerarquía aislada: banca → grupo → local con 1 taquilla sin local
     * asignado y otro local ajeno (misma banca, otro grupo).
     *
     * @return array{0: Banca, 1: Agencia, 2: Taquilla, 3: Agencia, 4: User}
     */
    private function jerarquia(): array
    {
        $banca = Banca::factory()->create(['active' => true]);
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $local = Agencia::factory()->create(['grupo_id' => $grupo->id, 'active' => true]);
        $taquilla = Taquilla::factory()->create([
            'grupo_id' => $grupo->id,
            'agencia_id' => null,
            'active' => true,
        ]);

        $otroGrupo = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $otroLocal = Agencia::factory()->create(['grupo_id' => $otroGrupo->id, 'active' => true]);

        $agenciaUser = User::factory()->create([
            'role' => 'agencia',
            'agencia_id' => $local->id,
            'grupo_id' => $grupo->id,
            'banca_id' => $banca->id,
        ]);
        $agenciaUser->assignRole('agencia');

        return [$banca, $local, $taquilla, $otroLocal, $agenciaUser];
    }

    public function test_super_master_asigna_local_a_taquilla_existente()
    {
        [$banca, $local, $taquilla] = $this->jerarquia();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson('/api/v1/taquillas/'.$taquilla->id, ['agencia_id' => $local->id]);

        $response->assertStatus(200)
            ->assertJsonPath('agencia_id', $local->id);

        $this->assertSame($local->id, $taquilla->fresh()->agencia_id);
    }

    public function test_agencia_asigna_su_taquilla_a_su_propio_local()
    {
        [$banca, $local, $taquilla, , $agenciaUser] = $this->jerarquia();
        $taquilla->update(['agencia_id' => $local->id]);

        $response = $this->actingAs($agenciaUser, 'sanctum')
            ->putJson('/api/v1/taquillas/'.$taquilla->id, ['agencia_id' => $local->id]);

        $response->assertStatus(200)
            ->assertJsonPath('agencia_id', $local->id);
    }

    public function test_agencia_no_puede_mover_su_taquilla_a_otro_local()
    {
        [$banca, $local, $taquilla, $otroLocal, $agenciaUser] = $this->jerarquia();
        $taquilla->update(['agencia_id' => $local->id]);

        $response = $this->actingAs($agenciaUser, 'sanctum')
            ->putJson('/api/v1/taquillas/'.$taquilla->id, ['agencia_id' => $otroLocal->id]);

        $response->assertStatus(403);

        $this->assertSame($local->id, $taquilla->fresh()->agencia_id);
    }
}
