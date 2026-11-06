<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Banca;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AuditoriaUsuariosTest extends TestCase
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
            'name' => 'Super Audit',
            'email' => 'super-audit@test.com',
            'password' => bcrypt('password'),
        ]);
    }

    private function auditEvent(string $action): AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->latest('id')->firstOrFail();
    }

    public function test_store_records_user_created_with_actor_scope_roles_and_no_password(): void
    {
        $actor = $this->superMaster();
        $banca = Banca::factory()->create();

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/users', [
            'user_name' => 'Nuevo Usuario',
            'user_email' => 'nuevo-audit@test.com',
            'user_password' => 'password123',
            'role' => 'banca',
            'banca_id' => $banca->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('role', 'banca');

        $created = User::where('email', 'nuevo-audit@test.com')->firstOrFail();
        $event = $this->auditEvent('user.created');
        $attributes = (string) json_encode($event->getAttributes());

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame($actor->email, $event->actor_label);
        $this->assertSame(['super_master'], $event->actor_roles);
        $this->assertSame('user', $event->entity_type);
        $this->assertSame($created->id, $event->entity_id);
        $this->assertSame($banca->id, $event->banca_id);
        $this->assertNull($event->before);
        $this->assertSame('Nuevo Usuario', $event->after['name']);
        $this->assertSame('n***@test.com', $event->after['email']);
        $this->assertSame(['banca'], $event->after['roles']);
        $this->assertArrayNotHasKey('password', $event->after);
        $this->assertStringNotContainsString($created->password, $attributes);
    }

    public function test_store_rolls_back_user_when_audit_write_fails(): void
    {
        $actor = $this->superMaster();

        self::$failAudit = true;

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/users', [
            'user_name' => 'Fallido',
            'user_email' => 'fallido-audit@test.com',
            'user_password' => 'password123',
            'role' => 'master',
        ]);

        $response->assertStatus(500);
        $this->assertDatabaseMissing('users', ['email' => 'fallido-audit@test.com']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'user.created']);
    }

    public function test_destroy_with_reason_records_snapshot_roles_and_reason(): void
    {
        $actor = $this->superMaster();
        $banca = Banca::factory()->create();
        $target = User::factory()->withRole('banca')->create([
            'name' => 'Baja Usuario',
            'email' => 'baja-audit@test.com',
            'banca_id' => $banca->id,
        ]);

        $response = $this->actingAs($actor, 'sanctum')->deleteJson(
            '/api/v1/users/'.$target->id,
            ['reason_code' => 'data_correction', 'reason_text' => 'Duplicate record'],
        );

        $response->assertStatus(200);

        $this->assertDatabaseMissing('users', ['id' => $target->id]);

        $event = $this->auditEvent('user.deleted');
        $attributes = (string) json_encode($event->getAttributes());

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame($actor->email, $event->actor_label);
        $this->assertSame(['super_master'], $event->actor_roles);
        $this->assertSame('user', $event->entity_type);
        $this->assertSame($target->id, $event->entity_id);
        $this->assertSame($banca->id, $event->banca_id);
        $this->assertNull($event->after);
        $this->assertSame('Baja Usuario', $event->before['name']);
        $this->assertSame('b***@test.com', $event->before['email']);
        $this->assertSame(['banca'], $event->before['roles']);
        $this->assertArrayNotHasKey('password', $event->before);
        $this->assertSame('data_correction', $event->reason_code);
        $this->assertSame('Duplicate record', $event->reason_text);
        $this->assertStringNotContainsString($target->password, $attributes);
    }

    public function test_destroy_without_reason_returns_422_and_keeps_user(): void
    {
        $actor = $this->superMaster();
        $target = User::factory()->withRole('master')->create();

        $response = $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/users/'.$target->id);

        $response->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'user.deleted']);
    }

    public function test_destroy_with_unknown_reason_returns_422_and_keeps_user(): void
    {
        $actor = $this->superMaster();
        $target = User::factory()->withRole('master')->create();

        $response = $this->actingAs($actor, 'sanctum')->deleteJson(
            '/api/v1/users/'.$target->id,
            ['reason_code' => 'not_a_reason'],
        );

        $response->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'user.deleted']);
    }

    public function test_update_records_user_updated_with_before_after_and_no_password(): void
    {
        $actor = $this->superMaster();
        $banca = Banca::factory()->create();
        $target = User::factory()->withRole('banca')->create([
            'name' => 'Nombre Original',
            'email' => 'before-update@test.com',
            'banca_id' => $banca->id,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, [
                'name' => 'Nombre Nuevo',
                'email' => 'new-update@test.com',
            ]);

        $response->assertStatus(200);

        $event = $this->auditEvent('user.updated');
        $attributes = (string) json_encode($event->getAttributes());

        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('user', $event->entity_type);
        $this->assertSame($target->id, $event->entity_id);
        $this->assertSame($banca->id, $event->banca_id);
        $this->assertSame('Nombre Original', $event->before['name']);
        $this->assertSame('Nombre Nuevo', $event->after['name']);
        $this->assertSame('b***@test.com', $event->before['email']);
        $this->assertSame('n***@test.com', $event->after['email']);
        $this->assertArrayNotHasKey('password', $event->after);
        $this->assertStringNotContainsString($target->password, $attributes);

        $this->assertDatabaseMissing('audit_events', ['action' => 'user.role_changed']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'user.password_reset_by_admin']);
    }

    public function test_update_role_change_records_role_event_and_no_legacy_log(): void
    {
        $actor = $this->superMaster();
        $target = User::factory()->withRole('taquilla')->create();

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, [
                'role' => 'master',
                'reason_code' => 'data_correction',
            ]);

        $response->assertStatus(200);

        $event = $this->auditEvent('user.role_changed');

        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame($target->id, $event->entity_id);
        $this->assertSame(['taquilla'], $event->before['roles']);
        $this->assertSame(['master'], $event->after['roles']);
        $this->assertSame('data_correction', $event->reason_code);

        // A role-only change must not produce `user.updated`.
        $this->assertDatabaseMissing('audit_events', ['action' => 'user.updated']);
        $this->assertDatabaseMissing('logs', ['action' => 'user.role_changed']);
    }

    public function test_update_role_change_without_reason_returns_422_and_keeps_role(): void
    {
        $actor = $this->superMaster();
        $target = User::factory()->withRole('taquilla')->create();

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, ['role' => 'master']);

        $response->assertStatus(422);

        $this->assertSame(['taquilla'], $target->fresh()->getRoleNames()->all());
        $this->assertDatabaseMissing('audit_events', ['action' => 'user.role_changed']);
    }

    public function test_update_deactivate_with_reason_records_user_deactivated(): void
    {
        $actor = $this->superMaster();
        $target = User::factory()->withRole('master')->create(['active' => true]);

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, [
                'active' => false,
                'reason_code' => 'employee_offboarding',
            ]);

        $response->assertStatus(200);

        $event = $this->auditEvent('user.deactivated');

        $this->assertSame('user', $event->entity_type);
        $this->assertSame($target->id, $event->entity_id);
        $this->assertSame(['active' => true], $event->before);
        $this->assertSame(['active' => false], $event->after);
        $this->assertSame('employee_offboarding', $event->reason_code);
        $this->assertFalse($target->fresh()->active);

        $this->assertDatabaseMissing('audit_events', ['action' => 'user.updated']);
    }

    public function test_update_deactivate_without_reason_returns_422_and_keeps_active(): void
    {
        $actor = $this->superMaster();
        $target = User::factory()->withRole('master')->create(['active' => true]);

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, ['active' => false]);

        $response->assertStatus(422);

        $this->assertTrue($target->fresh()->active);
        $this->assertDatabaseMissing('audit_events', ['action' => 'user.deactivated']);
    }

    public function test_update_activate_is_recorded_without_reason(): void
    {
        $actor = $this->superMaster();
        $target = User::factory()->withRole('master')->create(['active' => false]);

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, ['active' => true]);

        $response->assertStatus(200);

        $event = $this->auditEvent('user.activated');

        $this->assertSame(['active' => false], $event->before);
        $this->assertSame(['active' => true], $event->after);
        $this->assertNull($event->reason_code);
        $this->assertTrue($target->fresh()->active);

        $this->assertDatabaseMissing('audit_events', ['action' => 'user.deactivated']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'user.updated']);
    }

    public function test_update_password_reset_with_reason_records_context_and_hides_hash(): void
    {
        $actor = $this->superMaster();
        $target = User::factory()->withRole('master')->create();
        $oldHash = $target->password;

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, [
                'password' => 'newpassword123',
                'reason_code' => 'security_incident',
            ]);

        $response->assertStatus(200);

        $event = $this->auditEvent('user.password_reset_by_admin');
        $attributes = (string) json_encode($event->getAttributes());

        $this->assertSame($target->id, $event->entity_id);
        $this->assertNull($event->before);
        $this->assertNull($event->after);
        $this->assertTrue($event->context['password_changed']);
        $this->assertSame('security_incident', $event->reason_code);
        $this->assertStringNotContainsString($oldHash, $attributes);
        $this->assertStringNotContainsString($target->fresh()->password, $attributes);
        $this->assertTrue(Hash::check('newpassword123', $target->fresh()->password));

        $this->assertDatabaseMissing('audit_events', ['action' => 'user.updated']);
    }

    public function test_update_password_reset_without_reason_returns_422_and_keeps_password(): void
    {
        $actor = $this->superMaster();
        $target = User::factory()->withRole('master')->create();
        $oldHash = $target->password;

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, ['password' => 'newpassword123']);

        $response->assertStatus(422);

        $this->assertSame($oldHash, $target->fresh()->password);
        $this->assertDatabaseMissing('audit_events', ['action' => 'user.password_reset_by_admin']);
    }

    public function test_update_rolls_back_when_audit_write_fails(): void
    {
        $actor = $this->superMaster();
        $target = User::factory()->withRole('master')->create(['name' => 'Nombre Estable']);

        self::$failAudit = true;

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, ['name' => 'Nombre Fallido']);

        $response->assertStatus(500);

        $this->assertSame('Nombre Estable', $target->fresh()->name);
        $this->assertDatabaseMissing('audit_events', ['action' => 'user.updated']);
    }
}
