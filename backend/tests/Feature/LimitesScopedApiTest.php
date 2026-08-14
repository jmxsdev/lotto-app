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
 *
 * PR 2 — GET /api/limites en modo scope.
 *
 * Matriz de TODAS las entidades del tipo visibles para el rol, indexada
 * por "entidad_id:juego_id:moneda", con `mixto` por juego×moneda y sin
 * origen. XOR con los filtros de entidad (scope + filtro → 422).
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

    // ==================================================
    // MODO SCOPE — TODAS LAS ENTIDADES DEL TIPO
    // ==================================================

    public function test_scope_bancas_master_ve_todas()
    {
        $banca = $this->bancaSeeded();
        $lotto = $this->juegoLotto();

        // Segunda banca sin filas de límites sembradas
        $otraBanca = Banca::create([
            'name' => 'Banca Norte',
            'code' => 'BNZ01',
            'created_by' => $this->superUser()->id,
        ]);

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/limites?scope=bancas');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Todas las bancas visibles para master, con tipo y nombre
        $this->assertCount(2, $data['entidades']);
        $ids = collect($data['entidades'])->pluck('id')->all();
        $this->assertContains($banca->id, $ids);
        $this->assertContains($otraBanca->id, $ids);
        $this->assertEquals('banca', $data['entidades'][0]['tipo']);
        $this->assertEquals('Banca Test', $data['entidades'][0]['name']);

        // Sin origen en modo scope; juegos completos
        $this->assertArrayNotHasKey('origen', $data);
        $this->assertCount(7, $data['juegos']);
        $this->assertCount(28, $data['limites']); // 2 entidades × 7 juegos × 2 monedas

        // Fila sembrada a nivel banca: solo en la banca con límite
        $claveConFila = $banca->id . ':' . $lotto->id . ':bs';
        $claveSinFila = $otraBanca->id . ':' . $lotto->id . ':bs';
        $this->assertNotNull($data['limites'][$claveConFila]);
        $this->assertEquals(3600.0, $data['limites'][$claveConFila]['limite_minimo']);
        $this->assertNull($data['limites'][$claveSinFila]);

        // mixto: una banca con fila y otra sin fila para lotto:bs
        $this->assertTrue($data['mixto'][$lotto->id . ':bs']);
        // Nadie tiene fila en usd: todas coinciden → false
        $this->assertFalse($data['mixto'][$lotto->id . ':usd']);
    }

    public function test_scope_grupos_banca_solo_sus_grupos()
    {
        $grupo = $this->grupoSeeded();

        // Segundo grupo de SU banca: debe aparecer
        $grupoPropio = Grupo::create([
            'name' => 'Grupo Propio 2',
            'code' => 'GP201',
            'banca_id' => $grupo->banca_id,
            'created_by' => $this->superUser()->id,
        ]);

        // Grupo de OTRA banca: fuera del alcance del rol banca
        $otraBanca = Banca::create([
            'name' => 'Banca Ajena',
            'code' => 'BAZ01',
            'created_by' => $this->superUser()->id,
        ]);
        $grupoAjeno = Grupo::create([
            'name' => 'Grupo Ajeno',
            'code' => 'OGZ01',
            'banca_id' => $otraBanca->id,
            'created_by' => $this->superUser()->id,
        ]);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->getJson('/api/limites?scope=grupos');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Solo sus grupos (GT001 + GP201), nunca el de la otra banca
        $ids = collect($data['entidades'])->pluck('id')->all();
        $this->assertCount(2, $data['entidades']);
        $this->assertContains($grupo->id, $ids);
        $this->assertContains($grupoPropio->id, $ids);
        $this->assertNotContains($grupoAjeno->id, $ids);
        $this->assertEquals('grupo', $data['entidades'][0]['tipo']);

        // Sin filas propias a nivel grupo: mapa todo null
        $clave = $grupo->id . ':' . $this->juegoLotto()->id . ':bs';
        $this->assertNull($data['limites'][$clave]);

        // Todas las entidades sin fila propia: mixto false
        $this->assertCount(14, $data['mixto']);
        foreach ($data['mixto'] as $valor) {
            $this->assertFalse($valor);
        }
    }

    public function test_scope_taquillas_grupo_solo_sus_agencias()
    {
        $taquilla = $this->taquillaSeeded();

        // Agencia de otro grupo (de otra banca): fuera del alcance del rol grupo
        $otraBanca = Banca::create([
            'name' => 'Banca Ajena',
            'code' => 'BAZ01',
            'created_by' => $this->superUser()->id,
        ]);
        $otroGrupo = Grupo::create([
            'name' => 'Grupo Ajeno',
            'code' => 'OGZ01',
            'banca_id' => $otraBanca->id,
            'created_by' => $this->superUser()->id,
        ]);
        $taquillaAjena = Taquilla::create([
            'name' => 'Agencia Ajena',
            'code' => 'TAZ01',
            'grupo_id' => $otroGrupo->id,
            'active' => true,
            'created_by' => $this->superUser()->id,
        ]);

        $response = $this->actingAs($this->grupoUser(), 'sanctum')
            ->getJson('/api/limites?scope=taquillas');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Solo las agencias de SU grupo (TT001 + DEMO01), nunca la ajena
        $ids = collect($data['entidades'])->pluck('id')->all();
        $this->assertCount(2, $data['entidades']);
        $this->assertContains($taquilla->id, $ids);
        $this->assertNotContains($taquillaAjena->id, $ids);
        $this->assertEquals('taquilla', $data['entidades'][0]['tipo']);
        $this->assertArrayNotHasKey('origen', $data);
    }

    public function test_scope_mixto_true_cuando_estados_difieren()
    {
        $grupo = $this->grupoSeeded();
        $taquilla = $this->taquillaSeeded();
        $lotto = $this->juegoLotto();

        // Segunda agencia del mismo grupo, sin fila de límites
        $otraTaquilla = Taquilla::create([
            'name' => 'Agencia B',
            'code' => 'TT002',
            'grupo_id' => $grupo->id,
            'active' => true,
            'created_by' => $this->superUser()->id,
        ]);

        // Fila propia SOLO para la primera agencia
        JuegoLimite::create([
            'juego_id' => $lotto->id,
            'banca_id' => $grupo->banca_id,
            'grupo_id' => $grupo->id,
            'taquilla_id' => $taquilla->id,
            'moneda' => 'bs',
            'limite_maximo' => 400,
        ]);

        $response = $this->actingAs($this->grupoUser(), 'sanctum')
            ->getJson('/api/limites?scope=taquillas');

        $response->assertStatus(200);

        $data = $response->json('data');

        // TT001 con fila propia; TT002 (y DEMO01) sin fila
        $this->assertNotNull($data['limites'][$taquilla->id . ':' . $lotto->id . ':bs']);
        $this->assertEquals(400.0, $data['limites'][$taquilla->id . ':' . $lotto->id . ':bs']['limite_maximo']);
        $this->assertNull($data['limites'][$otraTaquilla->id . ':' . $lotto->id . ':bs']);

        // Estados diferentes entre entidades del alcance → mixto true
        $this->assertTrue($data['mixto'][$lotto->id . ':bs']);
        // Nadie tiene fila en usd → todas coinciden → false
        $this->assertFalse($data['mixto'][$lotto->id . ':usd']);
    }

    public function test_scope_mixto_false_cuando_todos_iguales()
    {
        $banca = $this->bancaSeeded();
        $grupo = $this->grupoSeeded();
        $taquilla = $this->taquillaSeeded();
        $lotto = $this->juegoLotto();

        $consultar = fn () => $this->actingAs($this->grupoUser(), 'sanctum')
            ->getJson('/api/limites?scope=taquillas')
            ->assertStatus(200)
            ->json('data');

        // Sin filas propias: todas coinciden → mixto false en todo
        $data = $consultar();
        $this->assertCount(14, $data['mixto']);
        foreach ($data['mixto'] as $valor) {
            $this->assertFalse($valor);
        }

        // Con fila propia en TODAS las agencias: vuelven a coincidir → false
        foreach (Taquilla::where('grupo_id', $grupo->id)->get() as $tq) {
            JuegoLimite::create([
                'juego_id' => $lotto->id,
                'banca_id' => $banca->id,
                'grupo_id' => $grupo->id,
                'taquilla_id' => $tq->id,
                'moneda' => 'bs',
                'limite_minimo' => 100,
            ]);
        }

        $data = $consultar();
        $this->assertFalse($data['mixto'][$lotto->id . ':bs']);
        $this->assertNotNull($data['limites'][$taquilla->id . ':' . $lotto->id . ':bs']);
    }

    public function test_scope_xor_422()
    {
        $banca = $this->bancaSeeded();

        // scope + filtro de entidad → 422
        $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/limites?scope=bancas&banca_id=' . $banca->id)
            ->assertStatus(422);

        // scope inválido → 422 por validación
        $this->actingAs($this->masterUser(), 'sanctum')
            ->getJson('/api/limites?scope=invalido')
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope');
    }

    // ==================================================
    // BATCH — MODO SCOPE (fan-out) + SEMÁNTICA PARCIAL
    // ==================================================

    public function test_batch_scope_grupo_expande_a_sus_agencias()
    {
        $grupo = $this->grupoSeeded();
        $taquilla = $this->taquillaSeeded();
        $lotto = $this->juegoLotto();

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/limites/batch', [
                'scope' => ['tipo' => 'grupo', 'id' => $grupo->id],
                'limites' => [
                    ['juego_id' => $lotto->id, 'moneda' => 'bs', 'limite_minimo' => 4000],
                ],
            ]);

        $response->assertStatus(201);

        // Grupo + su agencia (TT001) reciben la fila
        $this->assertDatabaseHas('juego_limites', [
            'juego_id' => $lotto->id, 'moneda' => 'bs', 'grupo_id' => $grupo->id, 'taquilla_id' => null, 'limite_minimo' => 4000,
        ]);
        $this->assertDatabaseHas('juego_limites', [
            'juego_id' => $lotto->id, 'moneda' => 'bs', 'taquilla_id' => $taquilla->id, 'limite_minimo' => 4000,
        ]);
    }

    public function test_batch_scope_banca_expande_grupos_y_agencias()
    {
        $banca = $this->bancaSeeded();
        $grupo = $this->grupoSeeded();
        $taquilla = $this->taquillaSeeded();
        $lotto = $this->juegoLotto();

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/limites/batch', [
                'scope' => ['tipo' => 'banca', 'id' => $banca->id],
                'limites' => [
                    ['juego_id' => $lotto->id, 'moneda' => 'bs', 'limite_maximo' => 900],
                ],
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('juego_limites', ['juego_id' => $lotto->id, 'moneda' => 'bs', 'banca_id' => $banca->id, 'grupo_id' => null, 'limite_maximo' => 900]);
        $this->assertDatabaseHas('juego_limites', ['juego_id' => $lotto->id, 'moneda' => 'bs', 'grupo_id' => $grupo->id, 'limite_maximo' => 900]);
        $this->assertDatabaseHas('juego_limites', ['juego_id' => $lotto->id, 'moneda' => 'bs', 'taquilla_id' => $taquilla->id, 'limite_maximo' => 900]);
    }

    public function test_batch_scope_taquilla_solo_esa()
    {
        $taquilla = $this->taquillaSeeded();
        $grupo = $this->grupoSeeded();
        $lotto = $this->juegoLotto();

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/limites/batch', [
                'scope' => ['tipo' => 'taquilla', 'id' => $taquilla->id],
                'limites' => [
                    ['juego_id' => $lotto->id, 'moneda' => 'bs', 'limite_minimo' => 4000],
                ],
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('juego_limites', ['juego_id' => $lotto->id, 'moneda' => 'bs', 'taquilla_id' => $taquilla->id, 'limite_minimo' => 4000]);
        // El grupo NO recibe la fila (a nivel grupo: taquilla_id null)
        $this->assertDatabaseMissing('juego_limites', ['juego_id' => $lotto->id, 'moneda' => 'bs', 'grupo_id' => $grupo->id, 'taquilla_id' => null, 'limite_minimo' => 4000]);
    }

    public function test_batch_scope_con_entidad_en_items_422()
    {
        $banca = $this->bancaSeeded();
        $lotto = $this->juegoLotto();

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/limites/batch', [
                'scope' => ['tipo' => 'banca', 'id' => $banca->id],
                'limites' => [
                    ['juego_id' => $lotto->id, 'moneda' => 'bs', 'banca_id' => $banca->id, 'limite_minimo' => 100],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_batch_scope_root_fuera_de_jerarquia_banca_403()
    {
        $banca = $this->bancaSeeded();
        $lotto = $this->juegoLotto();

        // Banca ajena a la del usuario banca
        $otraBanca = Banca::create([
            'name' => 'Banca Ajena',
            'code' => 'BAJ01',
            'created_by' => $this->superUser()->id,
        ]);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->postJson('/api/limites/batch', [
                'scope' => ['tipo' => 'banca', 'id' => $otraBanca->id],
                'limites' => [
                    [ "juego_id" => $lotto->id, "moneda" => "bs", "limite_minimo" => 4000],
                ],
            ]);

        $response->assertStatus(403);
    }

    public function test_batch_null_elimina_fila()
    {
        $banca = $this->bancaSeeded();
        $lotto = $this->juegoLotto();

        // Crear fila previa
        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/limites/batch', [
                'limites' => [
                    ['juego_id' => $lotto->id, 'banca_id' => $banca->id, 'moneda' => 'bs', 'limite_minimo' => 100],
                ],
            ]);
        $response->assertStatus(201);

        $this->assertDatabaseHas('juego_limites', ['juego_id' => $lotto->id, 'banca_id' => $banca->id, 'moneda' => 'bs']);

        // Null explícito → elimina la fila (volver a heredar)
        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/limites/batch', [
                'limites' => [
                    ['juego_id' => $lotto->id, 'banca_id' => $banca->id, 'moneda' => 'bs', 'limite_minimo' => null],
                ],
            ]);
        $response->assertStatus(201);

        $this->assertDatabaseMissing('juego_limites', ['juego_id' => $lotto->id, 'banca_id' => $banca->id, 'moneda' => 'bs']);
    }

    public function test_batch_campos_ausentes_no_sobreescriben()
    {
        $banca = $this->bancaSeeded();
        $lotto = $this->juegoLotto();

        // Primera escritura: min + max
        $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/limites/batch', [
                'limites' => [
                    ['juego_id' => $lotto->id, 'banca_id' => $banca->id, 'moneda' => 'bs', 'limite_minimo' => 100, 'limite_maximo' => 900],
                ],
            ])->assertStatus(201);

        // Segunda escritura: solo min — max debe conservarse
        $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/limites/batch', [
                'limites' => [
                    ['juego_id' => $lotto->id, 'banca_id' => $banca->id, 'moneda' => 'bs', 'limite_minimo' => 200],
                ],
            ])->assertStatus(201);

        $this->assertDatabaseHas('juego_limites', [
            'juego_id' => $lotto->id, 'banca_id' => $banca->id, 'moneda' => 'bs',
            'limite_minimo' => 200, 'limite_maximo' => 900,
        ]);
    }

    public function test_batch_hierarquia_violacion_422_rollback()
    {
        $banca = $this->bancaSeeded();
        $grupo = $this->grupoSeeded();
        $lotto = $this->juegoLotto();

        // Padre (banca): max 500
        $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/limites/batch', [
                'limites' => [
                    ['juego_id' => $lotto->id, 'banca_id' => $banca->id, 'moneda' => 'bs', 'limite_maximo' => 500],
                ],
            ])->assertStatus(201);

        // Hijo (grupo) intenta max 900 > 500 → 422 y rollback total
        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/limites/batch', [
                'scope' => ['tipo' => 'grupo', 'id' => $grupo->id],
                'limites' => [
                    ['juego_id' => $lotto->id, 'moneda' => 'bs', 'limite_maximo' => 900],
                ],
            ]);

        $response->assertStatus(422);

        // Rollback: no debe quedar la fila del grupo
        $this->assertDatabaseMissing('juego_limites', ['juego_id' => $lotto->id, 'moneda' => 'bs', 'grupo_id' => $grupo->id]);
    }

    public function test_batch_scope_tipo_plural_todas_las_agencias()
    {
        $taquilla = $this->taquillaSeeded();
        $lotto = $this->juegoLotto();

        // scope tipo plural sin id: todas las agencias visibles para master
        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/limites/batch', [
                'scope' => ['tipo' => 'taquillas'],
                'limites' => [
                    ['juego_id' => $lotto->id, 'moneda' => 'bs', 'limite_maximo' => 800],
                ],
            ]);

        $response->assertStatus(201);

        // La agencia sembrada TT001 recibe la fila
        $this->assertDatabaseHas('juego_limites', [
            'juego_id' => $lotto->id, 'moneda' => 'bs', 'taquilla_id' => $taquilla->id, 'limite_maximo' => 800,
        ]);
    }

    public function test_batch_scope_tipo_singular_sin_id_422()
    {
        $lotto = $this->juegoLotto();

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->postJson('/api/limites/batch', [
                'scope' => ['tipo' => 'taquilla'],
                'limites' => [
                    ['juego_id' => $lotto->id, 'moneda' => 'bs', 'limite_maximo' => 800],
                ],
            ]);

        $response->assertStatus(422);
    }
}
