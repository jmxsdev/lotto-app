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
 * PR 2 (F1) — CRUD de agencias (locales) con alcance por rol.
 *
 * Super Master y Master gestionan todas; banca solo agencias de su banca;
 * grupo solo de su grupo; la agencia solo se ve a sí misma y no gestiona.
 */
class AgenciaApiTest extends TestCase
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

    private function bancaUser(): User
    {
        $user = User::where('email', 'banca@lotto.com')->first();
        $user->assignRole('banca');

        return $user;
    }

    private function grupoUser(): User
    {
        $user = User::where('email', 'grupo@lotto.com')->first();
        $user->assignRole('grupo');

        return $user;
    }

    private function agenciaUser(): User
    {
        $user = User::where('email', 'agencia@lotto.com')->first();
        $user->assignRole('agencia');

        return $user;
    }

    private function grupoSeeded(): Grupo
    {
        return Grupo::where('code', 'GT001')->first();
    }

    private function localSeeded(): Agencia
    {
        return Agencia::where('code', 'LT001')->first();
    }

    public function test_super_master_crea_agencia()
    {
        $grupo = $this->grupoSeeded();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/agencias', [
                'name' => 'Local Norte',
                'code' => 'LN001',
                'grupo_id' => $grupo->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Local Norte');

        $this->assertDatabaseHas('agencias', [
            'name' => 'Local Norte',
            'code' => 'LN001',
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);
    }

    public function test_code_duplicado_422()
    {
        $local = $this->localSeeded();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/agencias', [
                'name' => 'Local Duplicado',
                'code' => $local->code,
                'grupo_id' => $local->grupo_id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_grupo_inexistente_422()
    {
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/agencias', [
                'name' => 'Local Fantasma',
                'code' => 'LF001',
                'grupo_id' => 999999,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('grupo_id');
    }

    public function test_super_master_lista_todas_las_agencias()
    {
        $local = $this->localSeeded();
        $otroLocal = Agencia::factory()->create(['active' => true]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/agencias');

        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($local->id, $ids);
        $this->assertContains($otroLocal->id, $ids);
    }

    public function test_banca_solo_agencias_de_su_banca()
    {
        $local = $this->localSeeded();

        // Local de OTRA banca: fuera del alcance de la banca sembrada
        $otraBanca = Banca::factory()->create(['active' => true]);
        $otroGrupo = Grupo::factory()->create(['banca_id' => $otraBanca->id, 'active' => true]);
        $localAjeno = Agencia::factory()->create(['grupo_id' => $otroGrupo->id, 'active' => true]);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->getJson('/api/v1/agencias');

        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($local->id, $ids);
        $this->assertNotContains($localAjeno->id, $ids);
    }

    public function test_grupo_solo_agencias_de_su_grupo()
    {
        $local = $this->localSeeded();

        $otroGrupo = Grupo::factory()->create(['active' => true]);
        $localAjeno = Agencia::factory()->create(['grupo_id' => $otroGrupo->id, 'active' => true]);

        $response = $this->actingAs($this->grupoUser(), 'sanctum')
            ->getJson('/api/v1/agencias');

        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($local->id, $ids);
        $this->assertNotContains($localAjeno->id, $ids);
    }

    public function test_agencia_solo_se_ve_a_si_misma()
    {
        $local = $this->localSeeded();
        $otroLocal = Agencia::factory()->create(['active' => true]);

        $response = $this->actingAs($this->agenciaUser(), 'sanctum')
            ->getJson('/api/v1/agencias');

        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertEquals([$local->id], $ids);
        $this->assertNotContains($otroLocal->id, $ids);
    }

    public function test_toggle_invierte_active()
    {
        $local = $this->localSeeded();

        $this->actingAs($this->superUser(), 'sanctum')
            ->patchJson("/api/v1/agencias/{$local->id}/toggle")
            ->assertStatus(200)
            ->assertJsonPath('active', false);

        $this->assertDatabaseHas('agencias', ['id' => $local->id, 'active' => false]);
    }

    public function test_destroy_soft_delete_y_set_null_en_taquillas_y_usuarios()
    {
        $super = $this->superUser();
        $local = Agencia::factory()->create(['active' => true]);
        $taquilla = Taquilla::factory()->create([
            'grupo_id' => $local->grupo_id, 'agencia_id' => $local->id, 'active' => true,
        ]);
        $user = User::factory()->create([
            'role' => 'taquilla',
            'taquilla_id' => $taquilla->id,
            'agencia_id' => $local->id,
        ]);
        $user->assignRole('taquilla');

        $response = $this->actingAs($super, 'sanctum')
            ->deleteJson("/api/v1/agencias/{$local->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('agencias', ['id' => $local->id]);
        // FK set null: taquillas y usuarios conservan la fila sin agencia
        $this->assertDatabaseHas('taquillas', ['id' => $taquilla->id, 'agencia_id' => null]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'agencia_id' => null]);
    }

    public function test_banca_no_crea_agencia_en_grupo_de_otra_banca()
    {
        $otraBanca = Banca::factory()->create(['active' => true]);
        $otroGrupo = Grupo::factory()->create(['banca_id' => $otraBanca->id, 'active' => true]);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->postJson('/api/v1/agencias', [
                'name' => 'Local Ajeno',
                'code' => 'LAJ01',
                'grupo_id' => $otroGrupo->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_agencia_no_puede_crear_agencia()
    {
        $grupo = $this->grupoSeeded();

        $response = $this->actingAs($this->agenciaUser(), 'sanctum')
            ->postJson('/api/v1/agencias', [
                'name' => 'Local Clon',
                'code' => 'LCL01',
                'grupo_id' => $grupo->id,
            ]);

        $response->assertStatus(403);
    }
}
