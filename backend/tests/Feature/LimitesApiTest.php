<?php

namespace Tests\Feature;

use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Models\Taquilla;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PR 5 — juego-limites.
 *
 * GET /api/limites/{juego} acepta filtros banca_id/grupo_id/taquilla_id manteniendo
 * el alcance jerárquico. DELETE /api/limites/{limite} elimina con 404 para
 * inexistentes y autorización jerárquica (banca solo su banca).
 */
class LimitesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
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

    private function bancaSeeded(): Banca
    {
        return Banca::where('code', 'BT001')->first();
    }

    private function grupoSeeded(): Grupo
    {
        return Grupo::where('code', 'GT001')->first();
    }

    private function taquillaSeeded(): Taquilla
    {
        return Taquilla::where('code', 'TT001')->first();
    }

    /**
     * Juego nuevo sin límites sembrados, para aislar los filtros.
     */
    private function juegoNuevo(): Juego
    {
        return Juego::create([
            'name' => 'Juego Filtros',
            'slug' => 'juego-filtros-' . uniqid(),
            'type' => 'animalitos',
            'active' => true,
        ]);
    }

    private function crearLimite(Juego $juego, array $overrides = []): JuegoLimite
    {
        return JuegoLimite::create(array_merge([
            'juego_id' => $juego->id,
            'banca_id' => $this->bancaSeeded()->id,
            'grupo_id' => null,
            'taquilla_id' => null,
            'moneda' => 'bs',
            'limite_maximo' => 100,
        ], $overrides));
    }

    // ==================================================
    // FILTROS EN GET /limites/{juego}
    // ==================================================

    public function test_get_limites_filtra_por_grupo()
    {
        $juego = $this->juegoNuevo();
        $grupo = $this->grupoSeeded();

        // Límite a nivel banca (no debe aparecer)
        $this->crearLimite($juego);

        // Límite del grupo X
        $this->crearLimite($juego, ['grupo_id' => $grupo->id, 'limite_maximo' => 50]);

        // Límite de otro grupo de la misma banca (no debe aparecer)
        $otroGrupo = Grupo::create(['name' => 'Otro Grupo', 'code' => 'OGF01', 'banca_id' => $grupo->banca_id, 'created_by' => $this->superUser()->id]);
        $this->crearLimite($juego, ['grupo_id' => $otroGrupo->id, 'limite_maximo' => 25]);

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/limites/' . $juego->id . '?grupo_id=' . $grupo->id);

        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('grupo_id')->all();

        $this->assertEquals([$grupo->id], $ids);
    }

    public function test_get_limites_filtra_por_taquilla()
    {
        $juego = $this->juegoNuevo();
        $grupo = $this->grupoSeeded();
        $taquilla = $this->taquillaSeeded();

        // Límite a nivel grupo (no debe aparecer)
        $this->crearLimite($juego, ['grupo_id' => $grupo->id, 'limite_maximo' => 50]);

        // Límite de la taquilla Y
        $this->crearLimite($juego, [
            'grupo_id' => $grupo->id,
            'taquilla_id' => $taquilla->id,
            'limite_maximo' => 10,
        ]);

        // Límite de otra taquilla del mismo grupo (no debe aparecer)
        $otraTaquilla = Taquilla::create([
            'name' => 'Otra Agencia',
            'code' => 'OTF01',
            'grupo_id' => $grupo->id,
            'activation_code' => 'OTF01-CODE',
            'active' => true,
            'created_by' => $this->superUser()->id,
        ]);
        $this->crearLimite($juego, ['grupo_id' => $grupo->id, 'taquilla_id' => $otraTaquilla->id, 'limite_maximo' => 5]);

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/limites/' . $juego->id . '?taquilla_id=' . $taquilla->id);

        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('taquilla_id')->all();

        $this->assertEquals([$taquilla->id], $ids);
    }

    public function test_limites_filtro_banca_id_retorna_solo_esa_banca()
    {
        $juego = $this->juegoNuevo();
        $banca = $this->bancaSeeded();

        // Límite de la banca sembrada (debe aparecer)
        $this->crearLimite($juego, ['limite_maximo' => 100]);

        // Límite de otra banca (no debe aparecer)
        $otraBanca = Banca::create(['name' => 'Banca Ajena F', 'code' => 'BAF01', 'created_by' => $this->superUser()->id]);
        $this->crearLimite($juego, ['banca_id' => $otraBanca->id, 'limite_maximo' => 25]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/limites/' . $juego->id . '?banca_id=' . $banca->id);

        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('banca_id')->all();

        $this->assertEquals([$banca->id], $ids);
    }

    public function test_limites_filtro_banca_id_banca_no_ve_otra()
    {
        $juego = $this->juegoNuevo();

        // Límite de una banca ajena (la del usuario banca@lotto.com es BT001)
        $otraBanca = Banca::create(['name' => 'Banca Ajena G', 'code' => 'BAG01', 'created_by' => $this->superUser()->id]);
        $this->crearLimite($juego, ['banca_id' => $otraBanca->id, 'limite_maximo' => 25]);

        // El filtro no puede ampliar el alcance: un usuario banca no ve límites de otra banca
        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->getJson('/api/limites/' . $juego->id . '?banca_id=' . $otraBanca->id);

        $response->assertStatus(200);
        $this->assertEmpty($response->json());
    }

    public function test_limites_filtro_invalido_422()
    {
        $juego = $this->juegoNuevo();

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/limites/' . $juego->id . '?banca_id=999999');

        $response->assertStatus(422)
            ->assertJsonValidationErrors('banca_id');
    }

    public function test_get_limites_con_filtro_respeta_el_alcance_jerarquico()
    {
        $juego = $this->juegoNuevo();
        $grupo = $this->grupoSeeded();

        // Límite del grupo del usuario grupo@lotto.com
        $this->crearLimite($juego, ['grupo_id' => $grupo->id, 'limite_maximo' => 50]);

        // Otro grupo de la misma banca
        $otroGrupo = Grupo::create(['name' => 'Grupo Ajeno', 'code' => 'OGF02', 'banca_id' => $grupo->banca_id, 'created_by' => $this->superUser()->id]);
        $this->crearLimite($juego, ['grupo_id' => $otroGrupo->id, 'limite_maximo' => 25]);

        // El filtro no puede ampliar el alcance: un grupo no ve límites de otro grupo
        $response = $this->actingAs($this->grupoUser(), 'sanctum')
            ->getJson('/api/limites/' . $juego->id . '?grupo_id=' . $otroGrupo->id);

        $response->assertStatus(200);
        $this->assertEmpty($response->json());
    }

    public function test_get_limites_rechaza_filtro_con_grupo_inexistente()
    {
        $juego = $this->juegoNuevo();

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/limites/' . $juego->id . '?grupo_id=999999');

        $response->assertStatus(422)
            ->assertJsonValidationErrors('grupo_id');
    }

    // ==================================================
    // DELETE /limites/{limite}
    // ==================================================

    public function test_delete_elimina_el_limite()
    {
        $juego = $this->juegoNuevo();
        $limite = $this->crearLimite($juego, ['grupo_id' => $this->grupoSeeded()->id]);

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->deleteJson('/api/limites/' . $limite->id);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Límite eliminado correctamente.']);

        $this->assertDatabaseMissing('juego_limites', ['id' => $limite->id]);
    }

    public function test_delete_limite_inexistente_responde_404()
    {
        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->deleteJson('/api/limites/999999');

        $response->assertStatus(404);
    }

    public function test_banca_puede_eliminar_limite_de_su_banca()
    {
        $juego = $this->juegoNuevo();
        $limite = $this->crearLimite($juego);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->deleteJson('/api/limites/' . $limite->id);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('juego_limites', ['id' => $limite->id]);
    }

    public function test_banca_no_puede_eliminar_limite_de_otra_banca()
    {
        $juego = $this->juegoNuevo();
        $otraBanca = Banca::create(['name' => 'Banca Ajena', 'code' => 'BAL01', 'created_by' => $this->superUser()->id]);
        $limite = $this->crearLimite($juego, ['banca_id' => $otraBanca->id]);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->deleteJson('/api/limites/' . $limite->id);

        $response->assertStatus(403);

        $this->assertDatabaseHas('juego_limites', ['id' => $limite->id]);
    }

    public function test_grupo_no_puede_eliminar_limites_de_otro_grupo()
    {
        $juego = $this->juegoNuevo();
        $grupo = $this->grupoSeeded();
        $otroGrupo = Grupo::create(['name' => 'Grupo Ajeno D', 'code' => 'OGD01', 'banca_id' => $grupo->banca_id, 'created_by' => $this->superUser()->id]);
        $limite = $this->crearLimite($juego, ['grupo_id' => $otroGrupo->id]);

        $response = $this->actingAs($this->grupoUser(), 'sanctum')
            ->deleteJson('/api/limites/' . $limite->id);

        $response->assertStatus(403);

        $this->assertDatabaseHas('juego_limites', ['id' => $limite->id]);
    }
}
