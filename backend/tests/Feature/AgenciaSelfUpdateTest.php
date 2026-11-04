<?php

namespace Tests\Feature;

use App\Models\Agencia;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WU-3 — ficha propia de la agencia.
 *
 * El rol `agencia` edita su PROPIA agencia limitado a datos de contacto y
 * fiscales; `code`, `grupo_id` y `active` son inmutables (403). Tampoco
 * crea, alterna ni elimina agencias, y sigue viendo solo su local.
 */
class AgenciaSelfUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * @return array{0: User, 1: Agencia}
     */
    private function agenciaUser(): array
    {
        $banca = Banca::factory()->create(['active' => true]);
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $agencia = Agencia::factory()->create(['grupo_id' => $grupo->id, 'active' => true]);

        $user = User::factory()->create([
            'agencia_id' => $agencia->id,
            'grupo_id' => $grupo->id,
            'banca_id' => $banca->id,
            'active' => true,
        ]);
        $user->assignRole('agencia');

        return [$user, $agencia];
    }

    // ==================================================
    // EDICIÓN DE LA FICHA PROPIA
    // ==================================================

    public function test_agencia_edita_su_propia_ficha(): void
    {
        [$user, $agencia] = $this->agenciaUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/agencias/'.$agencia->id, [
                'name' => 'Local Renombrado',
                'rif' => 'J-12345678-9',
                'email' => 'local@example.com',
                'telefono' => '0414-1234567',
                'direccion' => 'Av. Principal, Local 1',
                'estado' => 'Zulia',
                'municipio' => 'Maracaibo',
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Local Renombrado')
            ->assertJsonPath('email', 'local@example.com');

        $this->assertDatabaseHas('agencias', [
            'id' => $agencia->id,
            'name' => 'Local Renombrado',
            'rif' => 'J-12345678-9',
            'email' => 'local@example.com',
            'telefono' => '0414-1234567',
            'direccion' => 'Av. Principal, Local 1',
            'estado' => 'Zulia',
            'municipio' => 'Maracaibo',
        ]);
    }

    public function test_agencia_no_cambia_code_403(): void
    {
        [$user, $agencia] = $this->agenciaUser();
        $codeOriginal = $agencia->code;

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/agencias/'.$agencia->id, [
                'name' => 'Local Renombrado',
                'code' => 'NUEVO01',
            ])
            ->assertForbidden();

        $this->assertSame($codeOriginal, $agencia->fresh()->code);
        $this->assertNotSame('Local Renombrado', $agencia->fresh()->name);
    }

    public function test_agencia_no_reasigna_grupo_403(): void
    {
        [$user, $agencia] = $this->agenciaUser();
        $otroGrupo = Grupo::factory()->create(['active' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/agencias/'.$agencia->id, [
                'grupo_id' => $otroGrupo->id,
            ])
            ->assertForbidden();

        $this->assertSame($agencia->grupo_id, $agencia->fresh()->grupo_id);
    }

    public function test_agencia_no_cambia_active_403(): void
    {
        [$user, $agencia] = $this->agenciaUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/agencias/'.$agencia->id, [
                'active' => false,
            ])
            ->assertForbidden();

        $this->assertTrue($agencia->fresh()->active);
    }

    public function test_agencia_no_edita_agencia_ajena_403(): void
    {
        [$user] = $this->agenciaUser();
        [, $otraAgencia] = $this->agenciaUser();
        $nombreOriginal = $otraAgencia->name;

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/agencias/'.$otraAgencia->id, [
                'name' => 'Intruso',
            ])
            ->assertForbidden();

        $this->assertSame($nombreOriginal, $otraAgencia->fresh()->name);
    }

    // ==================================================
    // GESTIÓN BLOQUEADA PARA AGENCIA
    // ==================================================

    public function test_agencia_no_crea_alterna_ni_elimina_agencias(): void
    {
        [$user, $agencia] = $this->agenciaUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/agencias', [
                'name' => 'Local Nuevo',
                'code' => 'NEWLOC01',
                'grupo_id' => $agencia->grupo_id,
            ])
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/agencias/'.$agencia->id.'/toggle')
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/agencias/'.$agencia->id)
            ->assertForbidden();

        $this->assertNotSoftDeleted('agencias', ['id' => $agencia->id]);
        $this->assertTrue($agencia->fresh()->active);
    }

    // ==================================================
    // LECTURA: SOLO SU LOCAL
    // ==================================================

    public function test_agencia_index_solo_su_local_y_show_propio(): void
    {
        [$user, $agencia] = $this->agenciaUser();
        [, $otraAgencia] = $this->agenciaUser();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/agencias');

        $response->assertOk();

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($agencia->id, $ids);
        $this->assertNotContains($otraAgencia->id, $ids);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/agencias/'.$agencia->id)
            ->assertOk()
            ->assertJsonPath('id', $agencia->id);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/agencias/'.$otraAgencia->id)
            ->assertForbidden();
    }
}
