<?php

namespace Tests\Feature;

use App\Models\Banca;
use App\Models\Log;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WU-3 — auditoría de cambios de rol (`user.role_changed`).
 *
 * `UserController::update` registra un único log por cambio real de rol,
 * dentro de la misma transacción que persiste el usuario y `syncRoles`.
 * Sin cambio, sin `role` en el request o con un 403 previo no hay log.
 * `details` es un array (cast del modelo), nunca JSON anidado.
 */
class AuditoriaRolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function superUser(): User
    {
        return User::factory()->withRole('super_master')->create();
    }

    private function logsDeRol(): int
    {
        return Log::where('action', 'user.role_changed')->count();
    }

    public function test_cambio_de_rol_registra_user_role_changed(): void
    {
        $actor = $this->superUser();
        $target = User::factory()->withRole('taquilla')->create();

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, ['role' => 'master']);

        $response->assertStatus(200)
            ->assertJsonPath('role', 'master');

        $this->assertSame(1, $this->logsDeRol());
        $this->assertDatabaseHas('logs', [
            'user_id' => $actor->id,
            'action' => 'user.role_changed',
        ]);

        $log = Log::where('action', 'user.role_changed')->firstOrFail();
        $this->assertSame($target->id, $log->details['target_id']);
        $this->assertSame(['master'], $target->fresh()->getRoleNames()->all());
    }

    public function test_mismo_rol_no_registra_log(): void
    {
        $actor = $this->superUser();
        $target = User::factory()->withRole('master')->create();

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, ['role' => 'master']);

        $response->assertStatus(200);

        // Sin `role` en el request tampoco se audita.
        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, ['name' => 'Solo Renombrado']);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Solo Renombrado');

        $this->assertSame(0, $this->logsDeRol());
    }

    public function test_log_incluye_actor_target_before_after(): void
    {
        $actor = $this->superUser();
        $banca = Banca::factory()->create();
        $target = User::factory()->withRole('banca')->create([
            'banca_id' => $banca->id,
            'email' => 'target-auditado@test.com',
        ]);

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, ['role' => 'master'])
            ->assertStatus(200);

        $log = Log::where('action', 'user.role_changed')->firstOrFail();

        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame($actor->id, $log->details['actor_id']);
        $this->assertSame($target->id, $log->details['target_id']);
        $this->assertSame('target-auditado@test.com', $log->details['target_email']);
        $this->assertSame(['banca'], $log->details['before']);
        $this->assertSame(['master'], $log->details['after']);
    }

    public function test_details_es_array_sin_doble_json(): void
    {
        $actor = $this->superUser();
        $target = User::factory()->withRole('taquilla')->create();

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, ['role' => 'master'])
            ->assertStatus(200);

        $log = Log::where('action', 'user.role_changed')->firstOrFail();

        $this->assertIsArray($log->details);
        $this->assertArrayHasKey('actor_id', $log->details);

        // La columna guarda JSON de un array plano: decodificar una sola vez
        // devuelve el array, no un string JSON (doble encode).
        $raw = DB::table('logs')->where('id', $log->id)->value('details');
        $decoded = json_decode($raw, true);

        $this->assertIsArray($decoded);
        $this->assertIsNotString($decoded);
        $this->assertSame($actor->id, $decoded['actor_id']);
        $this->assertSame($target->id, $decoded['target_id']);
    }

    public function test_403_no_registra_log(): void
    {
        $bancaPropia = Banca::factory()->create();
        $bancaAjena = Banca::factory()->create();

        $actor = User::factory()->withRole('banca')->create(['banca_id' => $bancaPropia->id]);
        $target = User::factory()->withRole('taquilla')->create(['banca_id' => $bancaAjena->id]);

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, ['role' => 'master']);

        $response->assertStatus(403);

        $this->assertSame(0, $this->logsDeRol());
        $this->assertSame(['taquilla'], $target->fresh()->getRoleNames()->all());
    }
}
