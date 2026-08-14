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
 * PR 1 — GET /api/limites en modo entidad.
 *
 * Matriz completa juego×moneda de una entidad en UNA sola respuesta,
 * indexada por "juego_id:moneda", con origen heredado del nivel superior
 * y alcance de rol aplicado primero (intersección, nunca ampliación).
 */
class LimitesScopedApiTest extends TestCase
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

    private function juegoLotto(): Juego
    {
        return Juego::where('slug', 'lotto-activo')->first();
    }

    // ==================================================
    // MODO ENTIDAD — MATRIZ COMPLETA
    // ==================================================

    public function test_limites_entidad_banca_retorna_todos_los_juegos()
    {
        $banca = $this->bancaSeeded();
        $lotto = $this->juegoLotto();
        $clave = $lotto->id . ':bs';

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/limites?banca_id=' . $banca->id);

        $response->assertStatus(200);

        $data = $response->json('data');

        // 7 juegos sembrados × 2 monedas, en una sola llamada
        $this->assertCount(7, $data['juegos']);
        $this->assertCount(14, $data['limites']);
        $this->assertCount(14, $data['origen']);

        // La fila sembrada a nivel banca (lotto-activo/bs) aparece en el mapa
        $this->assertNotNull($data['limites'][$clave]);
        $this->assertEquals(3600.0, $data['limites'][$clave]['limite_minimo']);
        $this->assertNull($data['limites'][$clave]['limite_maximo']);
        $this->assertFalse($data['limites'][$clave]['fraccion']);

        // La banca no tiene padre: origen todo null
        foreach ($data['origen'] as $origen) {
            $this->assertNull($origen);
        }
    }

    public function test_limites_entidad_grupo_muestra_origen_heredado()
    {
        $banca = $this->bancaSeeded();
        $grupo = $this->grupoSeeded();
        $lotto = $this->juegoLotto();
        $clave = $lotto->id . ':bs';

        // Sin fila propia del grupo: el origen hereda de la banca
        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/limites?grupo_id=' . $grupo->id);

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertNull($data['limites'][$clave]);
        $this->assertNotNull($data['origen'][$clave]);
        $this->assertEquals('banca', $data['origen'][$clave]['nivel']);
        $this->assertEquals($banca->id, $data['origen'][$clave]['entidad_id']);
        $this->assertEquals(3600.0, $data['origen'][$clave]['valor']['limite_minimo']);

        // Con fila propia del grupo: aparece en límites y el origen queda null
        JuegoLimite::create([
            'juego_id' => $lotto->id,
            'banca_id' => $banca->id,
            'grupo_id' => $grupo->id,
            'moneda' => 'bs',
            'limite_maximo' => 500,
        ]);

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/limites?grupo_id=' . $grupo->id);

        $data = $response->json('data');

        $this->assertNotNull($data['limites'][$clave]);
        $this->assertEquals(500.0, $data['limites'][$clave]['limite_maximo']);
        $this->assertNull($data['origen'][$clave]);
    }

    public function test_limites_entidad_taquilla_cadena_completa()
    {
        $banca = $this->bancaSeeded();
        $grupo = $this->grupoSeeded();
        $taquilla = $this->taquillaSeeded();
        $lotto = $this->juegoLotto();
        $clave = $lotto->id . ':bs';

        // Fila a nivel grupo: la taquilla sin fila propia hereda del grupo
        JuegoLimite::create([
            'juego_id' => $lotto->id,
            'banca_id' => $banca->id,
            'grupo_id' => $grupo->id,
            'moneda' => 'bs',
            'limite_minimo' => 5000,
        ]);

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/limites?taquilla_id=' . $taquilla->id);

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertNull($data['limites'][$clave]);
        $this->assertNotNull($data['origen'][$clave]);
        $this->assertEquals('grupo', $data['origen'][$clave]['nivel']);
        $this->assertEquals($grupo->id, $data['origen'][$clave]['entidad_id']);
        $this->assertEquals(5000.0, $data['origen'][$clave]['valor']['limite_minimo']);

        // Fila propia de la taquilla: aparece en límites y el origen queda null
        JuegoLimite::create([
            'juego_id' => $lotto->id,
            'banca_id' => $banca->id,
            'grupo_id' => $grupo->id,
            'taquilla_id' => $taquilla->id,
            'moneda' => 'bs',
            'limite_maximo' => 400,
        ]);

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/limites?taquilla_id=' . $taquilla->id);

        $data = $response->json('data');

        $this->assertNotNull($data['limites'][$clave]);
        $this->assertEquals(400.0, $data['limites'][$clave]['limite_maximo']);
        $this->assertNull($data['origen'][$clave]);
    }

    // ==================================================
    // VALIDACIÓN Y ALCANCE DE ROL
    // ==================================================

    public function test_limites_xor_422()
    {
        $banca = $this->bancaSeeded();
        $grupo = $this->grupoSeeded();

        // Sin filtro de entidad → 422
        $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/limites')
            ->assertStatus(422);

        // Más de un filtro → 422
        $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/limites?banca_id=' . $banca->id . '&grupo_id=' . $grupo->id)
            ->assertStatus(422);
    }

    public function test_limites_entidad_inexistente_422()
    {
        $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/limites?banca_id=999999')
            ->assertStatus(422)
            ->assertJsonValidationErrors('banca_id');
    }

    public function test_limites_banca_no_ve_otra_banca()
    {
        $otraBanca = Banca::create([
            'name' => 'Banca Ajena',
            'code' => 'BAZ01',
            'created_by' => $this->superUser()->id,
        ]);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->getJson('/api/limites?banca_id=' . $otraBanca->id);

        // Intersección vacía: 200 con la matriz sin valores (nunca amplía el alcance)
        $response->assertStatus(200);

        $data = $response->json('data');

        foreach ($data['limites'] as $limite) {
            $this->assertNull($limite);
        }

        foreach ($data['origen'] as $origen) {
            $this->assertNull($origen);
        }
    }

    public function test_limites_grupo_no_ve_grupo_ajeno()
    {
        $grupo = $this->grupoSeeded();
        $otroGrupo = Grupo::create([
            'name' => 'Grupo Ajeno',
            'code' => 'OGZ01',
            'banca_id' => $grupo->banca_id,
            'created_by' => $this->superUser()->id,
        ]);

        $response = $this->actingAs($this->grupoUser(), 'sanctum')
            ->getJson('/api/limites?grupo_id=' . $otroGrupo->id);

        $response->assertStatus(200);

        $data = $response->json('data');

        foreach ($data['limites'] as $limite) {
            $this->assertNull($limite);
        }
    }

    public function test_limites_juegos_inactivos_excluidos()
    {
        $inactivo = Juego::create([
            'name' => 'Juego Inactivo',
            'slug' => 'juego-inactivo-' . uniqid(),
            'type' => 'animalitos',
            'active' => false,
        ]);

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/limites?banca_id=' . $this->bancaSeeded()->id);

        $response->assertStatus(200);

        $data = $response->json('data');

        // Solo los 7 juegos activos sembrados, sin el inactivo
        $this->assertCount(7, $data['juegos']);
        $this->assertCount(14, $data['limites']);

        $slugs = collect($data['juegos'])->pluck('slug')->all();
        $this->assertNotContains($inactivo->slug, $slugs);
    }
}
