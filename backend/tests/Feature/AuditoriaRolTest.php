<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Banca;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WU-3a-2 — `user.role_changed` audit event.
 *
 * `UserController::update` emits one P0 event per real role change inside the
 * same transaction that persists the user and `syncRoles`. A missing reason is
 * rejected with 422 and the role is rolled back. The legacy `logs` row is gone.
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

    private function auditEvent(string $action): AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->latest('id')->firstOrFail();
    }

    public function test_cambio_de_rol_registra_user_role_changed(): void
    {
        $actor = $this->superUser();
        $target = User::factory()->withRole('taquilla')->create();

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, [
                'role' => 'master',
                'reason_code' => 'data_correction',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('role', 'master');

        $event = $this->auditEvent('user.role_changed');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame(['super_master'], $event->actor_roles);
        $this->assertSame('user', $event->entity_type);
        $this->assertSame($target->id, $event->entity_id);
        $this->assertSame(['taquilla'], $event->before['roles']);
        $this->assertSame(['master'], $event->after['roles']);
        $this->assertSame('data_correction', $event->reason_code);

        $this->assertSame(['master'], $target->fresh()->getRoleNames()->all());

        // The audit event replaces the legacy operational log.
        $this->assertDatabaseMissing('logs', ['action' => 'user.role_changed']);
    }

    public function test_mismo_rol_no_registra_role_changed(): void
    {
        $actor = $this->superUser();
        $target = User::factory()->withRole('master')->create();

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, ['role' => 'master']);

        $response->assertStatus(200);

        // Un cambio que no toca el rol tampoco emite el evento de rol.
        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, ['name' => 'Solo Renombrado']);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Solo Renombrado');

        $this->assertDatabaseMissing('audit_events', ['action' => 'user.role_changed']);
    }

    public function test_role_event_incluye_actor_target_before_after_y_scope(): void
    {
        $actor = $this->superUser();
        $banca = Banca::factory()->create();
        $target = User::factory()->withRole('banca')->create([
            'banca_id' => $banca->id,
            'email' => 'target-auditado@test.com',
        ]);

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, [
                'role' => 'master',
                'reason_code' => 'business_decision',
                'reason_text' => 'Role updated by operations',
            ])
            ->assertStatus(200);

        $event = $this->auditEvent('user.role_changed');

        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame($target->id, $event->entity_id);
        $this->assertSame($banca->id, $event->banca_id);
        $this->assertSame(['banca'], $event->before['roles']);
        $this->assertSame(['master'], $event->after['roles']);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertSame('Role updated by operations', $event->reason_text);
    }

    public function test_403_no_registra_evento_de_rol(): void
    {
        $bancaPropia = Banca::factory()->create();
        $bancaAjena = Banca::factory()->create();

        $actor = User::factory()->withRole('banca')->create(['banca_id' => $bancaPropia->id]);
        $target = User::factory()->withRole('taquilla')->create(['banca_id' => $bancaAjena->id]);

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, [
                'role' => 'master',
                'reason_code' => 'data_correction',
            ]);

        $response->assertStatus(403);

        $this->assertDatabaseMissing('audit_events', ['action' => 'user.role_changed']);
        $this->assertSame(['taquilla'], $target->fresh()->getRoleNames()->all());
    }
}
