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
 * CORRECCIÓN DE AUDITORÍA (P4) — creación/validación de taquillas robusta.
 *
 * El camino de creación y validación de taquillas debe devolver 422 (nunca
 * 404/500 ni TypeError) cuando el contexto padre (grupo/local/banca) está
 * ausente o soft-deleted, preservando el camino de éxito con jerarquía viva.
 */
class TaquillaStoreTest extends TestCase
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

    private function agenciaUser(): User
    {
        $agencia = User::where('email', 'agencia@lotto.com')->first();
        $agencia->assignRole('agencia');

        return $agencia;
    }

    private function grupoSeeded(): Grupo
    {
        return Grupo::where('code', 'GT001')->first();
    }

    private function localSeeded(): Agencia
    {
        return Agencia::where('code', 'LT001')->first();
    }

    /**
     * Payload base para POST /api/v1/taquillas con la jerarquía sembrada.
     * Los IDs por defecto se resuelven de forma perezosa para que un test
     * pueda pasar explícitamente un padre soft-deleted.
     */
    private function payload(array $overrides = []): array
    {
        $base = [
            'name' => 'Taquilla Store',
            'code' => 'TST01',
            'user_name' => 'Usuario Taquilla',
            'user_email' => 'taquilla-store@test.com',
            'user_password' => 'password123',
        ];

        if (! array_key_exists('grupo_id', $overrides)) {
            $base['grupo_id'] = $this->grupoSeeded()->id;
        }

        if (! array_key_exists('agencia_id', $overrides)) {
            $base['agencia_id'] = $this->localSeeded()->id;
        }

        return array_merge($base, $overrides);
    }

    // ==================================================
    // CAMINO DE ÉXITO (guard verde: baseline no regresa)
    // ==================================================

    public function test_grupo_vivo_crea_taquilla_y_persiste_agencia_id()
    {
        $grupo = $this->grupoSeeded();
        $local = $this->localSeeded();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('taquilla.agencia_id', $local->id);

        $this->assertDatabaseHas('taquillas', [
            'code' => 'TST01',
            'grupo_id' => $grupo->id,
            'agencia_id' => $local->id,
        ]);

        // El usuario taquilla hereda la banca del grupo.
        $this->assertDatabaseHas('users', [
            'email' => 'taquilla-store@test.com',
            'banca_id' => $grupo->banca_id,
            'grupo_id' => $grupo->id,
            'agencia_id' => $local->id,
        ]);
    }

    // ==================================================
    // PADRES SOFT-DELETED → 422 (nunca 404/500)
    // ==================================================

    public function test_grupo_soft_deleted_devuelve_422()
    {
        $grupo = $this->grupoSeeded();
        $grupoId = $grupo->id;
        $localId = $this->localSeeded()->id;

        $grupo->delete();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', $this->payload([
                'grupo_id' => $grupoId,
                'agencia_id' => $localId,
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors('grupo_id');

        $this->assertDatabaseMissing('taquillas', ['code' => 'TST01']);
    }

    public function test_agencia_soft_deleted_devuelve_422()
    {
        $grupo = $this->grupoSeeded();
        $local = Agencia::factory()->create(['grupo_id' => $grupo->id]);
        $local->delete();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', $this->payload(['agencia_id' => $local->id]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors('agencia_id');

        $this->assertDatabaseMissing('taquillas', ['code' => 'TST01']);
    }

    public function test_agencia_rol_grupo_soft_deleted_devuelve_422()
    {
        // El rol agencia deriva grupo_id y agencia_id de SU local (LT001 del
        // seed): la regla debe fallar en validación (422) cuando ese grupo
        // está soft-deleted, antes de llegar a authorizeGrupoAccess (404).
        // Los IDs se pasan explícitos porque payload() resuelve los defaults
        // de forma perezosa y GT001 ya no es visible tras el soft-delete.
        $grupo = $this->grupoSeeded();
        $local = $this->localSeeded();
        $grupo->delete();

        $response = $this->actingAs($this->agenciaUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', $this->payload([
                'grupo_id' => $grupo->id,
                'agencia_id' => $local->id,
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors('grupo_id');

        $this->assertDatabaseMissing('taquillas', ['code' => 'TST01']);
    }

    // ==================================================
    // COLISIONES ÚNICAS → 422 (guard verde)
    // ==================================================

    public function test_codigo_duplicado_devuelve_422()
    {
        // TT001 existe en el seeder.
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', $this->payload(['code' => 'TT001']));

        $response->assertStatus(422)
            ->assertJsonValidationErrors('code');

        // No debe crearse una segunda fila con el código duplicado.
        $this->assertSame(1, Taquilla::where('code', 'TT001')->count());
    }

    public function test_user_email_duplicado_devuelve_422()
    {
        // taquilla@lotto.com existe en el seeder.
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', $this->payload(['user_email' => 'taquilla@lotto.com']));

        $response->assertStatus(422)
            ->assertJsonValidationErrors('user_email');

        $this->assertDatabaseMissing('taquillas', ['code' => 'TST01']);
    }

    // ==================================================
    // UPDATE CON GRUPO SOFT-DELETED → 422, sin TypeError/500
    // ==================================================

    public function test_update_con_grupo_soft_deleted_devuelve_422_sin_500()
    {
        $banca = Banca::factory()->create(['active' => true]);
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $local = Agencia::factory()->create(['grupo_id' => $grupo->id, 'active' => true]);
        $taquilla = Taquilla::factory()->create([
            'grupo_id' => $grupo->id,
            'agencia_id' => $local->id,
            'active' => true,
        ]);

        $grupo->delete();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson('/api/v1/taquillas/'.$taquilla->id, ['vigencia_premios' => 3]);

        $response->assertStatus(422);

        // El fallo de validación no debe mutar la taquilla.
        $this->assertNull($taquilla->fresh()->vigencia_premios);
    }

    // ==================================================
    // GRUPO SIN BANCA VIVA → 422 (nunca 500)
    // ==================================================

    public function test_grupo_vivo_sin_banca_devuelve_422()
    {
        // grupos.banca_id es NOT NULL con FK cascade, por lo que el estado
        // "sin banca válida" se materializa soft-deleteando la banca: el
        // grupo sigue vivo pero $grupo->banca resuelve null.
        $banca = Banca::factory()->create(['active' => true]);
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $local = Agencia::factory()->create(['grupo_id' => $grupo->id, 'active' => true]);

        $banca->delete();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', [
                'name' => 'Taquilla Sin Banca',
                'code' => 'TSB01',
                'grupo_id' => $grupo->id,
                'agencia_id' => $local->id,
                'user_name' => 'Usuario Sin Banca',
                'user_email' => 'sin-banca@test.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'El grupo no tiene una banca válida.');

        $this->assertDatabaseMissing('taquillas', ['code' => 'TSB01']);
    }

    // ==================================================
    // ACTIVATION CODE SERVER-SIDE (P7) — la API genera SIEMPRE el código
    // ==================================================
    //
    // Contexto de producción (2026-09-05/08): el panel enviaba códigos
    // hardcodeados (`789456123`, `123456`) que ya existían en prod →
    // UniqueConstraintViolationException en `taquillas_activation_code_unique`.
    // Decisión: la API ignora el valor del cliente, genera el código en
    // servidor y lo devuelve en la respuesta (el panel lo muestra read-only).

    public function test_activation_code_enviado_es_ignorado_y_se_genera_en_servidor()
    {
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', $this->payload([
                'code' => 'TAC01',
                // Valor hardcodeado que el panel enviaba en prod (caso real).
                'activation_code' => '789456123',
            ]));

        $response->assertStatus(201);

        $generated = $response->json('taquilla.activation_code');

        // El valor enviado por el cliente NO se persiste: se genera en servidor.
        $this->assertNotNull($generated);
        $this->assertNotSame('789456123', $generated);

        // El código generado viaja en la respuesta JSON (el panel lo necesita
        // para mostrarlo) y es exactamente el que queda persistido.
        $this->assertDatabaseHas('taquillas', [
            'code' => 'TAC01',
            'activation_code' => $generated,
        ]);
    }

    public function test_activation_code_generado_sin_enviar_es_no_vacio()
    {
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', $this->payload(['code' => 'TAC02']));

        $response->assertStatus(201);

        $generated = $response->json('taquilla.activation_code');

        // Sin código enviado, la API genera uno no vacío y lo persiste.
        $this->assertNotEmpty($generated);

        $this->assertDatabaseHas('taquillas', [
            'code' => 'TAC02',
            'activation_code' => $generated,
        ]);
    }

    public function test_dos_creaciones_generan_codigos_distintos()
    {
        $response1 = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', $this->payload(['code' => 'TAC03']));
        $response2 = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', $this->payload([
                'code' => 'TAC04',
                'user_email' => 'taquilla-04@test.com',
            ]));

        $response1->assertStatus(201);
        $response2->assertStatus(201);

        $code1 = $response1->json('taquilla.activation_code');
        $code2 = $response2->json('taquilla.activation_code');

        // Dos creaciones consecutivas nunca comparten código de activación.
        $this->assertNotSame($code1, $code2);
    }

    // ==================================================
    // NACIMIENTO INACTIVO (activacion-taquilla) — el store
    // fuerza active=false ignorando el payload del cliente.
    // ==================================================

    public function test_store_fuerza_active_false_ignorando_payload()
    {
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', $this->payload([
                'code' => 'TNA01',
                'active' => true,
            ]));

        $response->assertStatus(201)
            ->assertJsonPath('taquilla.active', false);

        // La BD persiste active=false y sin MAC, sin importar el payload.
        $this->assertDatabaseHas('taquillas', [
            'code' => 'TNA01',
            'active' => false,
            'mac_address' => null,
        ]);
    }

    public function test_taquilla_creada_con_active_true_puede_activarse_por_codigo()
    {
        // Cliente envía active:true (bug histórico): el store lo ignora y
        // la taquilla nace inactiva, por lo que /activar NO debe responder 403.
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', $this->payload([
                'code' => 'TNA02',
                'active' => true,
            ]));

        $response->assertStatus(201);

        $codigo = $response->json('taquilla.activation_code');
        $this->assertNotEmpty($codigo);

        $activacion = $this->postJson('/api/v1/activar', [
            'activation_code' => $codigo,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'test-fp-creada',
        ]);

        $activacion->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('taquillas', [
            'code' => 'TNA02',
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'test-fp-creada',
        ]);
    }

    public function test_listado_taquillas_ordena_por_id_desc()
    {
        // Dos creaciones consecutivas: la última debe aparecer primero.
        $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', $this->payload(['code' => 'TNA10']));
        $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', $this->payload([
                'code' => 'TNA11',
                'user_email' => 'taquilla-11@test.com',
            ]));

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/taquillas');

        $response->assertStatus(200);

        $lista = $response->json();
        $this->assertNotEmpty($lista);

        // La fila nueva (última creada, id mayor) es la primera del listado.
        $this->assertSame('TNA11', $lista[0]['code']);
    }
}
