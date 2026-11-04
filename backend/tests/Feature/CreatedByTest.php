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
 * WU-1a — `users.created_by` como metadata de fila.
 *
 * Cubre: asignación server-side del actor en los 4 stores de usuarios,
 * ignorancia de `created_by` enviado en el payload (mass assignment),
 * relaciones `creator()`/`createdUsers()` y `null` para usuarios existentes.
 */
class CreatedByTest extends TestCase
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

        return $super;
    }

    private function masterUser(): User
    {
        return User::where('email', 'master@lotto.com')->first();
    }

    private function bancaSeeded(): Banca
    {
        return Banca::where('code', 'BT001')->first();
    }

    private function grupoSeeded(): Grupo
    {
        return Grupo::where('code', 'GT001')->first();
    }

    private function agenciaSeeded(): Agencia
    {
        return Agencia::where('code', 'LT001')->first();
    }

    // ==================================================
    // UserController::store
    // ==================================================

    public function test_user_store_registra_created_by_del_actor()
    {
        $super = $this->superUser();

        $response = $this->actingAs($super, 'sanctum')
            ->postJson('/api/v1/users', [
                'user_name' => 'Master Creado',
                'user_email' => 'master-creado@test.com',
                'user_password' => 'password123',
                'role' => 'master',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'master-creado@test.com',
            'created_by' => $super->id,
        ]);
    }

    public function test_user_store_ignora_created_by_enviado_en_el_payload()
    {
        $super = $this->superUser();
        $otro = $this->masterUser();

        $response = $this->actingAs($super, 'sanctum')
            ->postJson('/api/v1/users', [
                'user_name' => 'Usuario Payload Ajeno',
                'user_email' => 'payload-ajeno@test.com',
                'user_password' => 'password123',
                'role' => 'master',
                'created_by' => $otro->id,
            ]);

        $response->assertStatus(201);

        $creado = User::where('email', 'payload-ajeno@test.com')->first();

        $this->assertSame($super->id, (int) $creado->created_by);
        $this->assertNotSame($otro->id, (int) $creado->created_by);
    }

    // ==================================================
    // BancaController::store / GrupoController::store / TaquillaController::store
    // ==================================================

    public function test_banca_store_registra_created_by_del_actor_en_el_usuario_creado()
    {
        $super = $this->superUser();

        $response = $this->actingAs($super, 'sanctum')
            ->postJson('/api/v1/bancas', [
                'name' => 'Banca CreatedBy',
                'code' => 'BCBY01',
                'user_name' => 'Usuario Banca CreatedBy',
                'user_email' => 'banca-createdby@test.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'banca-createdby@test.com',
            'created_by' => $super->id,
        ]);
    }

    public function test_grupo_store_registra_created_by_del_actor_en_el_usuario_creado()
    {
        $super = $this->superUser();
        $banca = $this->bancaSeeded();

        $response = $this->actingAs($super, 'sanctum')
            ->postJson('/api/v1/grupos', [
                'name' => 'Grupo CreatedBy',
                'code' => 'GCBY01',
                'banca_id' => $banca->id,
                'user_name' => 'Usuario Grupo CreatedBy',
                'user_email' => 'grupo-createdby@test.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'grupo-createdby@test.com',
            'created_by' => $super->id,
        ]);
    }

    public function test_taquilla_store_registra_created_by_del_actor_en_el_usuario_creado()
    {
        $super = $this->superUser();
        $grupo = $this->grupoSeeded();
        $agencia = $this->agenciaSeeded();

        $response = $this->actingAs($super, 'sanctum')
            ->postJson('/api/v1/taquillas', [
                'name' => 'Taquilla CreatedBy',
                'code' => 'TCBY01',
                'grupo_id' => $grupo->id,
                'agencia_id' => $agencia->id,
                'user_name' => 'Usuario Taquilla CreatedBy',
                'user_email' => 'taquilla-createdby@test.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(201);

        $taquilla = Taquilla::where('code', 'TCBY01')->first();

        $this->assertDatabaseHas('users', [
            'email' => 'taquilla-createdby@test.com',
            'taquilla_id' => $taquilla->id,
            'created_by' => $super->id,
        ]);
    }

    // ==================================================
    // Relaciones
    // ==================================================

    public function test_creator_y_created_users_devuelven_la_cadena()
    {
        $super = User::factory()->create();
        $master = User::factory()->create(['created_by' => $super->id]);
        $banca = User::factory()->create(['created_by' => $master->id]);

        $this->assertTrue($master->creator->is($super));
        $this->assertTrue($banca->creator->is($master));
        $this->assertNull($super->creator);

        $this->assertEqualsCanonicalizing(
            [$master->id],
            $super->createdUsers()->pluck('id')->all()
        );
        $this->assertEqualsCanonicalizing(
            [$banca->id],
            $master->createdUsers()->pluck('id')->all()
        );
        $this->assertCount(0, $banca->createdUsers);
    }

    // ==================================================
    // Migración: existentes sin creador quedan null
    // ==================================================

    public function test_usuarios_preexistentes_quedan_sin_created_by()
    {
        $usuario = User::factory()->create();

        $this->assertNull($usuario->fresh()->created_by);
        $this->assertDatabaseHas('users', [
            'id' => $usuario->id,
            'created_by' => null,
        ]);
    }
}
