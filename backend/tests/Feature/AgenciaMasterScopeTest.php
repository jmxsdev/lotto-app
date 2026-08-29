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
 * CORRECCIÓN DE AUDITORÍA (PR 6) — alcance del master en el CRUD de agencias.
 *
 * AgenciaController era el ÚNICO controlador de la jerarquía sin acotar:
 * el master veía TODAS las agencias y podía crear/editar/borrar agencias
 * ajenas. Ahora el master aplica el mismo alcance que en el resto de
 * entidades (F2): solo locales de sus bancas; sin bancas ve NADA
 * (whereRaw('1=0'), nunca global). super_master sigue global.
 */
class AgenciaMasterScopeTest extends TestCase
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

    /**
     * Jerarquía aislada: banca (opcionalmente con master) → grupo → local.
     *
     * @return array{0: Banca, 1: Grupo, 2: Agencia}
     */
    private function crearJerarquia(string $prefijo, ?int $masterId = null): array
    {
        $banca = Banca::factory()->create([
            'name' => "Banca {$prefijo}",
            'code' => "AM-{$prefijo}",
            'master_id' => $masterId,
            'active' => true,
        ]);

        $grupo = Grupo::factory()->create([
            'name' => "Grupo {$prefijo}",
            'code' => "AGM-{$prefijo}",
            'banca_id' => $banca->id,
            'active' => true,
        ]);

        $local = Agencia::factory()->create([
            'name' => "Local {$prefijo}",
            'code' => "ALM-{$prefijo}",
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);

        return [$banca, $grupo, $local];
    }

    // ==================================================
    // LISTADO SCOPED
    // ==================================================

    public function test_master_lista_solo_agencias_de_sus_bancas()
    {
        $master = $this->masterUser();
        [, , $localPropio] = $this->crearJerarquia('Propia', $master->id);
        [, , $localAjeno] = $this->crearJerarquia('Ajena');

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/agencias');

        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($localPropio->id, $ids);
        $this->assertNotContains($localAjeno->id, $ids);
    }

    public function test_master_sin_bancas_ve_cero_agencias()
    {
        $master = $this->masterUser(); // sin master_id en ninguna banca
        $this->crearJerarquia('Ajena');

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/agencias');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json());
    }

    // ==================================================
    // ESCRITURAS ACOTADAS (403 en entidades ajenas)
    // ==================================================

    public function test_master_no_crea_agencia_en_grupo_de_otra_banca()
    {
        $master = $this->masterUser();
        $this->crearJerarquia('Propia', $master->id);
        [, $grupoAjeno] = $this->crearJerarquia('Ajena');

        $response = $this->actingAs($master, 'sanctum')
            ->postJson('/api/v1/agencias', [
                'name' => 'Local Ajeno',
                'code' => 'ALAJ01',
                'grupo_id' => $grupoAjeno->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_master_no_edita_agencia_ajena()
    {
        $master = $this->masterUser();
        $this->crearJerarquia('Propia', $master->id);
        [, , $localAjeno] = $this->crearJerarquia('Ajena');

        $response = $this->actingAs($master, 'sanctum')
            ->putJson('/api/v1/agencias/'.$localAjeno->id, ['name' => 'Local Manipulado']);

        $response->assertStatus(403);

        $this->assertSame('Local Ajena', $localAjeno->fresh()->name);
    }

    public function test_master_no_elimina_agencia_ajena()
    {
        $master = $this->masterUser();
        $this->crearJerarquia('Propia', $master->id);
        [, , $localAjeno] = $this->crearJerarquia('Ajena');

        $response = $this->actingAs($master, 'sanctum')
            ->deleteJson('/api/v1/agencias/'.$localAjeno->id);

        $response->assertStatus(403);

        $this->assertDatabaseHas('agencias', ['id' => $localAjeno->id]);
    }

    public function test_master_no_accede_al_detalle_de_agencia_ajena()
    {
        $master = $this->masterUser();
        $this->crearJerarquia('Propia', $master->id);
        [, , $localAjeno] = $this->crearJerarquia('Ajena');

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/agencias/'.$localAjeno->id);

        $response->assertStatus(403);
    }

    // ==================================================
    // TRIANGULACIÓN: master SÍ gestiona su propia descendencia
    // ==================================================

    public function test_master_crea_edita_y_elimina_agencia_de_su_banca()
    {
        $master = $this->masterUser();
        [$bancaPropia, $grupoPropio] = $this->crearJerarquia('Propia', $master->id);

        // Crear dentro de su alcance
        $response = $this->actingAs($master, 'sanctum')
            ->postJson('/api/v1/agencias', [
                'name' => 'Local Nuevo',
                'code' => 'ALN01',
                'grupo_id' => $grupoPropio->id,
            ]);

        $response->assertStatus(201);
        $localId = $response->json('id');

        // Editar dentro de su alcance
        $response = $this->actingAs($master, 'sanctum')
            ->putJson('/api/v1/agencias/'.$localId, ['name' => 'Local Nuevo Renombrado']);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Local Nuevo Renombrado');

        // Eliminar dentro de su alcance
        $response = $this->actingAs($master, 'sanctum')
            ->deleteJson('/api/v1/agencias/'.$localId);

        $response->assertStatus(200);
        $this->assertSoftDeleted('agencias', ['id' => $localId]);
    }

    // ==================================================
    // REGRESIÓN: SUPER MASTER SIGUE GLOBAL
    // ==================================================

    public function test_super_master_sigue_gestionando_cualquier_agencia()
    {
        $super = $this->superUser();
        [, $grupoAjeno] = $this->crearJerarquia('Ajena');

        $response = $this->actingAs($super, 'sanctum')
            ->postJson('/api/v1/agencias', [
                'name' => 'Local Super',
                'code' => 'ALSUP01',
                'grupo_id' => $grupoAjeno->id,
            ]);

        $response->assertStatus(201);
    }
}
