<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Banca;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
