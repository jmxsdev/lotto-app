<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * WU-7a-2 — grupo audit events: `grupo.created`/`grupo.updated` P1
 * best-effort, `grupo.toggled`/`grupo.deleted` P0 fail-closed with a required
 * reason, `grupo.banca_changed` P0 when the parent banca changes, and the
 * generated `user.created` P0 that shares the store transaction.
 */
class AuditoriaGrupoTest extends TestCase
{
    use RefreshDatabase;

    private static bool $failAudit = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        AuditEvent::creating(static function (): void {
            if (self::$failAudit) {
                throw new RuntimeException('forced audit insert failure');
            }
        });
    }

    protected function tearDown(): void
    {
        self::$failAudit = false;

        parent::tearDown();
    }

    private function superMaster(): User
    {
        return User::factory()->withRole('super_master')->create([
            'name' => 'Super Grupo',
            'email' => 'super-grupo@test.com',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(Banca $banca, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Grupo Auditado',
            'code' => 'GAU01',
            'banca_id' => $banca->id,
            'user_name' => 'Operador Grupo',
            'user_email' => 'operador-grupo@test.com',
            'user_password' => 'password123',
        ], $overrides);
    }

    private function lastEvent(string $action): AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->latest('id')->firstOrFail();
    }

    // --- store: grupo.created (P1) + user.created (P0) ---

    public function test_store_records_grupo_created_p1_and_user_created_p0(): void
    {
        $actor = $this->superMaster();
        $banca = Banca::factory()->create();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/grupos', $this->storePayload($banca, [
                'email' => 'grupo-contacto@test.com',
                'reason_code' => 'business_decision',
            ]))
            ->assertStatus(201);

        $grupo = Grupo::where('code', 'GAU01')->firstOrFail();
        $user = User::where('email', 'operador-grupo@test.com')->firstOrFail();

        $grupoCreated = $this->lastEvent('grupo.created');

        $this->assertSame('user', $grupoCreated->actor_type);
        $this->assertSame($actor->id, $grupoCreated->actor_id);
        $this->assertSame('grupo', $grupoCreated->entity_type);
        $this->assertSame($grupo->id, $grupoCreated->entity_id);
        $this->assertSame($banca->id, $grupoCreated->banca_id);
        $this->assertSame($grupo->id, $grupoCreated->grupo_id);
        $this->assertNull($grupoCreated->before);
        $this->assertSame('Grupo Auditado', $grupoCreated->after['name']);
        $this->assertSame('GAU01', $grupoCreated->after['code']);
        $this->assertTrue($grupoCreated->after['active']);
        $this->assertSame($banca->id, $grupoCreated->after['banca_id']);
        $this->assertSame($actor->id, $grupoCreated->after['created_by']);
        $this->assertSame('g***@test.com', $grupoCreated->after['email']);
        $this->assertArrayNotHasKey('created_at', $grupoCreated->after);
        $this->assertArrayNotHasKey('updated_at', $grupoCreated->after);

        $userCreated = $this->lastEvent('user.created');
        $attributes = (string) json_encode($userCreated->getAttributes());

        $this->assertSame('user', $userCreated->actor_type);
        $this->assertSame($actor->id, $userCreated->actor_id);
        $this->assertSame('s***@test.com', $userCreated->actor_label);
        $this->assertNotSame($actor->email, $userCreated->actor_label);
        $this->assertSame(['super_master'], $userCreated->actor_roles);
        $this->assertSame('user', $userCreated->entity_type);
        $this->assertSame($user->id, $userCreated->entity_id);
        $this->assertSame($banca->id, $userCreated->banca_id);
        $this->assertSame($grupo->id, $userCreated->grupo_id);
        $this->assertNull($userCreated->before);
        $this->assertSame('Operador Grupo', $userCreated->after['name']);
        $this->assertSame('o***@test.com', $userCreated->after['email']);
        $this->assertTrue($userCreated->after['active']);
        $this->assertSame($banca->id, $userCreated->after['banca_id']);
        $this->assertSame($grupo->id, $userCreated->after['grupo_id']);
        $this->assertSame($actor->id, $userCreated->after['created_by']);
        $this->assertSame(['grupo'], $userCreated->after['roles']);
        $this->assertArrayNotHasKey('password', $userCreated->after);
        $this->assertStringNotContainsString($user->password, $attributes);
    }

    public function test_store_rolls_back_grupo_and_user_when_user_created_audit_fails(): void
    {
        $actor = $this->superMaster();
        $banca = Banca::factory()->create();

        self::$failAudit = true;

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/grupos', $this->storePayload($banca, ['code' => 'GAUFAIL']))
            ->assertStatus(500);

        $this->assertDatabaseMissing('grupos', ['code' => 'GAUFAIL']);
        $this->assertDatabaseMissing('users', ['email' => 'operador-grupo@test.com']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'user.created']);
        $this->assertSame(
            0,
            AuditEvent::where('action', '!=', 'permission.catalog_synced')->count(),
        );
    }

    // --- update: grupo.updated (P1) ---

    public function test_update_records_grupo_updated_with_before_after(): void
    {
        $actor = $this->superMaster();
        $banca = Banca::factory()->create();
        $grupo = Grupo::factory()->create([
            'banca_id' => $banca->id,
            'name' => 'Nombre Original',
            'code' => 'GUPD01',
            'email' => 'original@test.com',
            'active' => true,
        ]);

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/grupos/'.$grupo->id, [
                'name' => 'Nombre Nuevo',
                'email' => 'nuevo@test.com',
                'reason_code' => 'business_decision',
                'reason_text' => 'Actualización operativa',
            ])
            ->assertStatus(200);

        $event = $this->lastEvent('grupo.updated');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('grupo', $event->entity_type);
        $this->assertSame($grupo->id, $event->entity_id);
        $this->assertSame($banca->id, $event->banca_id);
        $this->assertSame('Nombre Original', $event->before['name']);
        $this->assertSame('Nombre Nuevo', $event->after['name']);
        $this->assertSame('o***@test.com', $event->before['email']);
        $this->assertSame('n***@test.com', $event->after['email']);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertSame('Actualización operativa', $event->reason_text);
        $this->assertArrayNotHasKey('created_at', $event->after);
        $this->assertArrayNotHasKey('updated_at', $event->after);
    }

    // --- update: grupo.banca_changed (P0, required reason) ---

    public function test_update_banca_change_with_reason_records_banca_changed_p0(): void
    {
        $actor = $this->superMaster();
        $bancaOriginal = Banca::factory()->create();
        $bancaNueva = Banca::factory()->create();
        $grupo = Grupo::factory()->create(['banca_id' => $bancaOriginal->id]);

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/grupos/'.$grupo->id, [
                'banca_id' => $bancaNueva->id,
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $event = $this->lastEvent('grupo.banca_changed');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('grupo', $event->entity_type);
        $this->assertSame($grupo->id, $event->entity_id);
        $this->assertSame(['banca_id' => $bancaOriginal->id], $event->before);
        $this->assertSame(['banca_id' => $bancaNueva->id], $event->after);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertSame($bancaNueva->id, $grupo->fresh()->banca_id);
    }

    public function test_update_banca_change_without_reason_returns_422_and_keeps_banca(): void
    {
        $actor = $this->superMaster();
        $bancaOriginal = Banca::factory()->create();
        $bancaNueva = Banca::factory()->create();
        $grupo = Grupo::factory()->create(['banca_id' => $bancaOriginal->id]);

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/grupos/'.$grupo->id, ['banca_id' => $bancaNueva->id])
            ->assertStatus(422);

        $this->assertSame($bancaOriginal->id, $grupo->fresh()->banca_id);
        $this->assertDatabaseMissing('audit_events', ['action' => 'grupo.banca_changed']);
    }

    // --- toggle: grupo.toggled (P0, required reason) ---

    public function test_toggle_with_reason_records_grupo_toggled_p0(): void
    {
        $actor = $this->superMaster();
        $grupo = Grupo::factory()->create(['active' => true]);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/grupos/{$grupo->id}/toggle", [
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200)
            ->assertJsonPath('active', false);

        $event = $this->lastEvent('grupo.toggled');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('grupo', $event->entity_type);
        $this->assertSame($grupo->id, $event->entity_id);
        $this->assertSame($grupo->banca_id, $event->banca_id);
        $this->assertSame(['active' => true], $event->before);
        $this->assertSame(['active' => false], $event->after);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertFalse($grupo->fresh()->active);
    }

    public function test_toggle_without_reason_returns_422_and_keeps_active(): void
    {
        $actor = $this->superMaster();
        $grupo = Grupo::factory()->create(['active' => true]);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/grupos/{$grupo->id}/toggle")
            ->assertStatus(422);

        $this->assertTrue($grupo->fresh()->active);
        $this->assertDatabaseMissing('audit_events', ['action' => 'grupo.toggled']);
    }

    // --- destroy: grupo.deleted (P0, required reason) ---

    public function test_destroy_with_reason_records_grupo_deleted_snapshot(): void
    {
        $actor = $this->superMaster();
        $grupo = Grupo::factory()->create([
            'name' => 'Grupo Baja',
            'code' => 'GDEL01',
            'active' => true,
        ]);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/grupos/'.$grupo->id, [
                'reason_code' => 'business_decision',
                'reason_text' => 'Cierre operativo',
            ])
            ->assertStatus(200);

        $this->assertSoftDeleted('grupos', ['id' => $grupo->id]);

        $event = $this->lastEvent('grupo.deleted');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('grupo', $event->entity_type);
        $this->assertSame($grupo->id, $event->entity_id);
        $this->assertSame($grupo->banca_id, $event->banca_id);
        $this->assertNull($event->after);
        $this->assertSame('Grupo Baja', $event->before['name']);
        $this->assertSame('GDEL01', $event->before['code']);
        $this->assertTrue($event->before['active']);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertSame('Cierre operativo', $event->reason_text);
        $this->assertArrayNotHasKey('deleted_at', $event->before);
        $this->assertArrayNotHasKey('created_at', $event->before);
        $this->assertArrayNotHasKey('updated_at', $event->before);
    }

    public function test_destroy_without_reason_returns_422_and_keeps_grupo(): void
    {
        $actor = $this->superMaster();
        $grupo = Grupo::factory()->create();

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/grupos/'.$grupo->id)
            ->assertStatus(422);

        $this->assertNotSoftDeleted('grupos', ['id' => $grupo->id]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'grupo.deleted']);
    }

    public function test_destroy_rolls_back_when_audit_write_fails(): void
    {
        $actor = $this->superMaster();
        $grupo = Grupo::factory()->create();

        self::$failAudit = true;

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/grupos/'.$grupo->id, ['reason_code' => 'business_decision'])
            ->assertStatus(500);

        $this->assertNotSoftDeleted('grupos', ['id' => $grupo->id]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'grupo.deleted']);
    }

    // --- No secrets or timestamps ---

    public function test_grupo_events_have_no_secrets_or_timestamps(): void
    {
        $actor = $this->superMaster();
        $banca = Banca::factory()->create();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/grupos', $this->storePayload($banca, [
                'email' => 'contacto@test.com',
                'reason_code' => 'business_decision',
            ]))
            ->assertStatus(201);

        $createdGrupo = Grupo::where('code', 'GAU01')->firstOrFail();

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/grupos/'.$createdGrupo->id, [
                'name' => 'Grupo Seguro',
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/grupos/{$createdGrupo->id}/toggle", [
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $toDelete = Grupo::factory()->create(['code' => 'GDEL99']);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/grupos/'.$toDelete->id, [
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $forbidden = [
            'password', 'clave_actual', 'clave_nueva', 'clave_cierre', 'token',
            'secret', 'api_key', 'authorization', 'created_at', 'updated_at', 'deleted_at',
        ];

        $this->assertSame(5, AuditEvent::where('action', '!=', 'permission.catalog_synced')->count());

        foreach (AuditEvent::all() as $event) {
            foreach (['before', 'after', 'context'] as $column) {
                $this->assertSame([], array_intersect(array_keys($event->{$column} ?? []), $forbidden));
            }
        }
    }
}
