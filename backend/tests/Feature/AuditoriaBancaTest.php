<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Banca;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * WU-7a-1 — banca audit events: `banca.created`/`banca.updated` P1
 * best-effort, `banca.toggled`/`banca.deleted` P0 fail-closed with a required
 * reason, and the generated `user.created` P0 that shares the store
 * transaction.
 */
class AuditoriaBancaTest extends TestCase
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
            'name' => 'Super Banca',
            'email' => 'super-banca@test.com',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Banca Auditada',
            'code' => 'BAU01',
            'user_name' => 'Operador Banca',
            'user_email' => 'operador-banca@test.com',
            'user_password' => 'password123',
        ], $overrides);
    }

    private function lastEvent(string $action): AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->latest('id')->firstOrFail();
    }

    // --- store: banca.created (P1) + user.created (P0) ---

    public function test_store_records_banca_created_p1_and_user_created_p0(): void
    {
        $actor = $this->superMaster();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/bancas', $this->storePayload([
                'email' => 'banca-contacto@test.com',
                'reason_code' => 'business_decision',
            ]))
            ->assertStatus(201);

        $banca = Banca::where('code', 'BAU01')->firstOrFail();
        $user = User::where('email', 'operador-banca@test.com')->firstOrFail();

        $bancaCreated = $this->lastEvent('banca.created');

        $this->assertSame('user', $bancaCreated->actor_type);
        $this->assertSame($actor->id, $bancaCreated->actor_id);
        $this->assertSame('banca', $bancaCreated->entity_type);
        $this->assertSame($banca->id, $bancaCreated->entity_id);
        $this->assertSame($banca->id, $bancaCreated->banca_id);
        $this->assertNull($bancaCreated->before);
        $this->assertSame('Banca Auditada', $bancaCreated->after['name']);
        $this->assertSame('BAU01', $bancaCreated->after['code']);
        $this->assertTrue($bancaCreated->after['active']);
        $this->assertSame($actor->id, $bancaCreated->after['created_by']);
        $this->assertSame('b***@test.com', $bancaCreated->after['email']);
        $this->assertArrayNotHasKey('created_at', $bancaCreated->after);
        $this->assertArrayNotHasKey('updated_at', $bancaCreated->after);

        $userCreated = $this->lastEvent('user.created');
        $attributes = (string) json_encode($userCreated->getAttributes());

        $this->assertSame('user', $userCreated->actor_type);
        $this->assertSame($actor->id, $userCreated->actor_id);
        $this->assertSame($actor->email, $userCreated->actor_label);
        $this->assertSame(['super_master'], $userCreated->actor_roles);
        $this->assertSame('user', $userCreated->entity_type);
        $this->assertSame($user->id, $userCreated->entity_id);
        $this->assertSame($banca->id, $userCreated->banca_id);
        $this->assertNull($userCreated->before);
        $this->assertSame('Operador Banca', $userCreated->after['name']);
        $this->assertSame('o***@test.com', $userCreated->after['email']);
        $this->assertTrue($userCreated->after['active']);
        $this->assertSame($banca->id, $userCreated->after['banca_id']);
        $this->assertSame($actor->id, $userCreated->after['created_by']);
        $this->assertSame(['banca'], $userCreated->after['roles']);
        $this->assertArrayNotHasKey('password', $userCreated->after);
        $this->assertStringNotContainsString($user->password, $attributes);
    }

    public function test_store_rolls_back_banca_and_user_when_user_created_audit_fails(): void
    {
        $actor = $this->superMaster();

        self::$failAudit = true;

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/bancas', $this->storePayload(['code' => 'BAUFAIL']))
            ->assertStatus(500);

        $this->assertDatabaseMissing('bancas', ['code' => 'BAUFAIL']);
        $this->assertDatabaseMissing('users', ['email' => 'operador-banca@test.com']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'user.created']);
        $this->assertSame(0, AuditEvent::count());
    }

    // --- update: banca.updated (P1) ---

    public function test_update_records_banca_updated_with_before_after_and_reason(): void
    {
        $actor = $this->superMaster();
        $banca = Banca::factory()->create([
            'name' => 'Nombre Original',
            'code' => 'BUPD01',
            'email' => 'original@test.com',
            'active' => true,
        ]);

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/bancas/'.$banca->id, [
                'name' => 'Nombre Nuevo',
                'email' => 'nuevo@test.com',
                'reason_code' => 'business_decision',
                'reason_text' => 'Actualización operativa',
            ])
            ->assertStatus(200);

        $event = $this->lastEvent('banca.updated');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('banca', $event->entity_type);
        $this->assertSame($banca->id, $event->entity_id);
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

    public function test_update_audit_failure_does_not_block_business_change(): void
    {
        $actor = $this->superMaster();
        $banca = Banca::factory()->create(['name' => 'Nombre Estable']);

        self::$failAudit = true;

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/bancas/'.$banca->id, ['name' => 'Nombre Actualizado'])
            ->assertStatus(200);

        $this->assertSame('Nombre Actualizado', $banca->fresh()->name);
        $this->assertDatabaseMissing('audit_events', ['action' => 'banca.updated']);
    }

    // --- toggle: banca.toggled (P0, required reason) ---

    public function test_toggle_with_reason_records_banca_toggled_p0(): void
    {
        $actor = $this->superMaster();
        $banca = Banca::factory()->create(['active' => true]);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/bancas/{$banca->id}/toggle", [
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200)
            ->assertJsonPath('active', false);

        $event = $this->lastEvent('banca.toggled');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('banca', $event->entity_type);
        $this->assertSame($banca->id, $event->entity_id);
        $this->assertSame($banca->id, $event->banca_id);
        $this->assertSame(['active' => true], $event->before);
        $this->assertSame(['active' => false], $event->after);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertFalse($banca->fresh()->active);
    }

    public function test_toggle_without_reason_returns_422_and_keeps_active(): void
    {
        $actor = $this->superMaster();
        $banca = Banca::factory()->create(['active' => true]);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/bancas/{$banca->id}/toggle")
            ->assertStatus(422);

        $this->assertTrue($banca->fresh()->active);
        $this->assertDatabaseMissing('audit_events', ['action' => 'banca.toggled']);
    }

    // --- destroy: banca.deleted (P0, required reason) ---

    public function test_destroy_with_reason_records_banca_deleted_snapshot(): void
    {
        $actor = $this->superMaster();
        $banca = Banca::factory()->create([
            'name' => 'Banca Baja',
            'code' => 'BDEL01',
            'active' => true,
        ]);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/bancas/'.$banca->id, [
                'reason_code' => 'business_decision',
                'reason_text' => 'Cierre operativo',
            ])
            ->assertStatus(200);

        $this->assertSoftDeleted('bancas', ['id' => $banca->id]);

        $event = $this->lastEvent('banca.deleted');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('banca', $event->entity_type);
        $this->assertSame($banca->id, $event->entity_id);
        $this->assertSame($banca->id, $event->banca_id);
        $this->assertNull($event->after);
        $this->assertSame('Banca Baja', $event->before['name']);
        $this->assertSame('BDEL01', $event->before['code']);
        $this->assertTrue($event->before['active']);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertSame('Cierre operativo', $event->reason_text);
        $this->assertArrayNotHasKey('deleted_at', $event->before);
        $this->assertArrayNotHasKey('created_at', $event->before);
        $this->assertArrayNotHasKey('updated_at', $event->before);
    }

    public function test_destroy_without_reason_returns_422_and_keeps_banca(): void
    {
        $actor = $this->superMaster();
        $banca = Banca::factory()->create();

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/bancas/'.$banca->id)
            ->assertStatus(422);

        $this->assertNotSoftDeleted('bancas', ['id' => $banca->id]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'banca.deleted']);
    }

    public function test_destroy_rolls_back_when_audit_write_fails(): void
    {
        $actor = $this->superMaster();
        $banca = Banca::factory()->create();

        self::$failAudit = true;

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/bancas/'.$banca->id, ['reason_code' => 'business_decision'])
            ->assertStatus(500);

        $this->assertNotSoftDeleted('bancas', ['id' => $banca->id]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'banca.deleted']);
    }

    // --- No secrets or timestamps ---

    public function test_banca_events_have_no_secrets_or_timestamps(): void
    {
        $actor = $this->superMaster();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/bancas', $this->storePayload([
                'email' => 'contacto@test.com',
                'reason_code' => 'business_decision',
            ]))
            ->assertStatus(201);

        $createdBanca = Banca::where('code', 'BAU01')->firstOrFail();

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/bancas/'.$createdBanca->id, [
                'name' => 'Banca Segura',
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/bancas/{$createdBanca->id}/toggle", [
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $toDelete = Banca::factory()->create(['code' => 'BDEL99']);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/bancas/'.$toDelete->id, [
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $forbidden = [
            'password', 'clave_actual', 'clave_nueva', 'clave_cierre', 'token',
            'secret', 'api_key', 'authorization', 'created_at', 'updated_at', 'deleted_at',
        ];

        $this->assertSame(5, AuditEvent::count());

        foreach (AuditEvent::all() as $event) {
            foreach (['before', 'after', 'context'] as $column) {
                $this->assertSame([], array_intersect(array_keys($event->{$column} ?? []), $forbidden));
            }
        }
    }
}
