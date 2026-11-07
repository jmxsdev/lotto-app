<?php

namespace Tests\Feature;

use App\Models\Agencia;
use App\Models\AuditEvent;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * WU-7b-2 — taquilla audit events.
 *
 * `taquilla.created`/`taquilla.updated` are P1 best-effort;
 * `taquilla.toggled`/`taquilla.deleted`/`taquilla.group_changed` are P0
 * fail-closed with a required reason. The store also creates the taquilla
 * operator, so its `user.created` event is P0 and shares the transaction.
 */
class AuditoriaTaquillaTest extends TestCase
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
            'name' => 'Super Taquilla',
            'email' => 'super-taquilla@test.com',
        ]);
    }

    /**
     * Isolated hierarchy: banca → grupo → agencia.
     *
     * @return array{0: Banca, 1: Grupo, 2: Agencia}
     */
    private function jerarquia(): array
    {
        $banca = Banca::factory()->create(['active' => true]);
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $agencia = Agencia::factory()->create(['grupo_id' => $grupo->id, 'active' => true]);

        return [$banca, $grupo, $agencia];
    }

    /**
     * Two sibling groups (same banca) with one local each.
     *
     * @return array{0: Banca, 1: Grupo, 2: Agencia, 3: Grupo, 4: Agencia}
     */
    private function dosGrupos(): array
    {
        $banca = Banca::factory()->create(['active' => true]);
        $grupoX = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $localX = Agencia::factory()->create(['grupo_id' => $grupoX->id, 'active' => true]);
        $grupoY = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $localY = Agencia::factory()->create(['grupo_id' => $grupoY->id, 'active' => true]);

        return [$banca, $grupoX, $localX, $grupoY, $localY];
    }

    private function makeTaquilla(Grupo $grupo, Agencia $agencia, array $overrides = []): Taquilla
    {
        return Taquilla::factory()->create(array_merge([
            'grupo_id' => $grupo->id,
            'agencia_id' => $agencia->id,
            'active' => true,
        ], $overrides));
    }

    private function lastEvent(string $action): AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->latest('id')->firstOrFail();
    }

    // --- store: user.created (P0) + taquilla.created (P1) ---

    public function test_store_records_taquilla_created_p1_and_user_created_p0(): void
    {
        $actor = $this->superMaster();
        [$banca, $grupo, $agencia] = $this->jerarquia();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/taquillas', [
                'name' => 'Taquilla Auditada',
                'code' => 'TAU01',
                'grupo_id' => $grupo->id,
                'agencia_id' => $agencia->id,
                'email' => 'contacto@test.com',
                'user_name' => 'Operador Taquilla',
                'user_email' => 'operador-taquilla@test.com',
                'user_password' => 'password123',
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(201);

        $taquilla = Taquilla::where('code', 'TAU01')->firstOrFail();
        $generated = User::where('email', 'operador-taquilla@test.com')->firstOrFail();

        $userEvent = $this->lastEvent('user.created');

        $this->assertSame('user', $userEvent->actor_type);
        $this->assertSame($actor->id, $userEvent->actor_id);
        $this->assertSame('user', $userEvent->entity_type);
        $this->assertSame($generated->id, $userEvent->entity_id);
        $this->assertSame($banca->id, $userEvent->banca_id);
        $this->assertSame($grupo->id, $userEvent->grupo_id);
        $this->assertSame($agencia->id, $userEvent->agencia_id);
        $this->assertSame($taquilla->id, $userEvent->taquilla_id);
        $this->assertNull($userEvent->before);
        $this->assertSame('Operador Taquilla', $userEvent->after['name']);
        $this->assertSame('o***@test.com', $userEvent->after['email']);
        $this->assertTrue($userEvent->after['active']);
        $this->assertSame($banca->id, $userEvent->after['banca_id']);
        $this->assertSame($grupo->id, $userEvent->after['grupo_id']);
        $this->assertSame($agencia->id, $userEvent->after['agencia_id']);
        $this->assertSame($taquilla->id, $userEvent->after['taquilla_id']);
        $this->assertSame($actor->id, $userEvent->after['created_by']);
        $this->assertSame(['taquilla'], $userEvent->after['roles']);
        $this->assertSame('business_decision', $userEvent->reason_code);
        $this->assertArrayNotHasKey('password', $userEvent->after);
        $this->assertStringNotContainsString(
            $generated->password,
            json_encode([$userEvent->before, $userEvent->after, $userEvent->context]),
        );

        $taquillaEvent = $this->lastEvent('taquilla.created');

        $this->assertSame('user', $taquillaEvent->actor_type);
        $this->assertSame($actor->id, $taquillaEvent->actor_id);
        $this->assertSame('taquilla', $taquillaEvent->entity_type);
        $this->assertSame($taquilla->id, $taquillaEvent->entity_id);
        $this->assertSame($banca->id, $taquillaEvent->banca_id);
        $this->assertSame($grupo->id, $taquillaEvent->grupo_id);
        $this->assertSame($agencia->id, $taquillaEvent->agencia_id);
        $this->assertSame($taquilla->id, $taquillaEvent->taquilla_id);
        $this->assertNull($taquillaEvent->before);
        $this->assertSame('Taquilla Auditada', $taquillaEvent->after['name']);
        $this->assertSame('TAU01', $taquillaEvent->after['code']);
        $this->assertSame($grupo->id, $taquillaEvent->after['grupo_id']);
        $this->assertSame($agencia->id, $taquillaEvent->after['agencia_id']);
        $this->assertFalse($taquillaEvent->after['active']);
        $this->assertSame($actor->id, $taquillaEvent->after['created_by']);
        $this->assertStringStartsWith('****', $taquillaEvent->after['activation_code']);
        $this->assertNotSame($taquilla->activation_code, $taquillaEvent->after['activation_code']);
        $this->assertArrayNotHasKey('mac_address', $taquillaEvent->after);
        $this->assertArrayNotHasKey('device_fingerprint', $taquillaEvent->after);
    }

    public function test_store_rolls_back_taquilla_and_user_when_user_created_audit_fails(): void
    {
        $actor = $this->superMaster();
        [, $grupo, $agencia] = $this->jerarquia();

        self::$failAudit = true;

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/taquillas', [
                'name' => 'Taquilla Fallida',
                'code' => 'TAUFAIL',
                'grupo_id' => $grupo->id,
                'agencia_id' => $agencia->id,
                'user_name' => 'Operador Fallido',
                'user_email' => 'operador-fallido@test.com',
                'user_password' => 'password123',
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(500);

        $this->assertDatabaseMissing('taquillas', ['code' => 'TAUFAIL']);
        $this->assertDatabaseMissing('users', ['email' => 'operador-fallido@test.com']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'user.created']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'taquilla.created']);
    }

    // --- update: taquilla.updated (P1) ---

    public function test_update_records_taquilla_updated_with_before_after(): void
    {
        $actor = $this->superMaster();
        [$banca, $grupo, $agencia] = $this->jerarquia();
        $taquilla = $this->makeTaquilla($grupo, $agencia, [
            'name' => 'Nombre Original',
            'code' => 'TUPD01',
            'email' => 'original@test.com',
        ]);

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/taquillas/'.$taquilla->id, [
                'name' => 'Nombre Nuevo',
                'reason_code' => 'business_decision',
                'reason_text' => 'Actualización operativa',
            ])
            ->assertStatus(200);

        $event = $this->lastEvent('taquilla.updated');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('taquilla', $event->entity_type);
        $this->assertSame($taquilla->id, $event->entity_id);
        $this->assertSame($banca->id, $event->banca_id);
        $this->assertSame('Nombre Original', $event->before['name']);
        $this->assertSame('Nombre Nuevo', $event->after['name']);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertSame('Actualización operativa', $event->reason_text);
        $this->assertArrayNotHasKey('created_at', $event->after);
        $this->assertArrayNotHasKey('updated_at', $event->after);
        $this->assertDatabaseMissing('audit_events', ['action' => 'taquilla.group_changed']);
    }

    // --- update: taquilla.group_changed (P0, required reason) ---

    public function test_update_group_with_reason_records_group_changed_p0(): void
    {
        $actor = $this->superMaster();
        [$banca, $grupoOriginal, $localOriginal, $grupoNuevo, $localNuevo] = $this->dosGrupos();
        $taquilla = $this->makeTaquilla($grupoOriginal, $localOriginal, ['code' => 'TGC01']);

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/taquillas/'.$taquilla->id, [
                'grupo_id' => $grupoNuevo->id,
                'agencia_id' => $localNuevo->id,
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $event = $this->lastEvent('taquilla.group_changed');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('taquilla', $event->entity_type);
        $this->assertSame($taquilla->id, $event->entity_id);
        $this->assertSame($banca->id, $event->banca_id);
        $this->assertSame($grupoNuevo->id, $event->grupo_id);
        $this->assertSame($localNuevo->id, $event->agencia_id);
        $this->assertEqualsCanonicalizing(
            ['grupo_id' => $grupoOriginal->id, 'agencia_id' => $localOriginal->id],
            $event->before,
        );
        $this->assertEqualsCanonicalizing(
            ['grupo_id' => $grupoNuevo->id, 'agencia_id' => $localNuevo->id],
            $event->after,
        );
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertSame($grupoNuevo->id, $taquilla->fresh()->grupo_id);
        $this->assertSame($localNuevo->id, $taquilla->fresh()->agencia_id);
    }

    public function test_update_group_without_reason_returns_422_and_keeps_fks(): void
    {
        $actor = $this->superMaster();
        [, $grupoOriginal, $localOriginal, $grupoNuevo, $localNuevo] = $this->dosGrupos();
        $taquilla = $this->makeTaquilla($grupoOriginal, $localOriginal, ['code' => 'TGC02']);

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/taquillas/'.$taquilla->id, [
                'grupo_id' => $grupoNuevo->id,
                'agencia_id' => $localNuevo->id,
            ])
            ->assertStatus(422);

        $this->assertSame($grupoOriginal->id, $taquilla->fresh()->grupo_id);
        $this->assertSame($localOriginal->id, $taquilla->fresh()->agencia_id);
        $this->assertDatabaseMissing('audit_events', ['action' => 'taquilla.group_changed']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'taquilla.updated']);
    }

    // --- toggle: taquilla.toggled (P0, required reason) ---

    public function test_toggle_with_reason_records_taquilla_toggled_p0(): void
    {
        $actor = $this->superMaster();
        [$banca, $grupo, $agencia] = $this->jerarquia();
        $taquilla = $this->makeTaquilla($grupo, $agencia, ['active' => true]);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/taquillas/{$taquilla->id}/toggle", [
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200)
            ->assertJsonPath('active', false);

        $event = $this->lastEvent('taquilla.toggled');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('taquilla', $event->entity_type);
        $this->assertSame($taquilla->id, $event->entity_id);
        $this->assertSame($banca->id, $event->banca_id);
        $this->assertSame($grupo->id, $event->grupo_id);
        $this->assertSame($agencia->id, $event->agencia_id);
        $this->assertSame($taquilla->id, $event->taquilla_id);
        $this->assertSame(['active' => true], $event->before);
        $this->assertSame(['active' => false], $event->after);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertFalse($taquilla->fresh()->active);
    }

    public function test_toggle_without_reason_returns_422_and_keeps_active(): void
    {
        $actor = $this->superMaster();
        [, $grupo, $agencia] = $this->jerarquia();
        $taquilla = $this->makeTaquilla($grupo, $agencia, ['active' => true]);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/taquillas/{$taquilla->id}/toggle")
            ->assertStatus(422);

        $this->assertTrue($taquilla->fresh()->active);
        $this->assertDatabaseMissing('audit_events', ['action' => 'taquilla.toggled']);
    }

    // --- destroy: taquilla.deleted (P0, required reason) ---

    public function test_destroy_with_reason_records_taquilla_deleted_snapshot(): void
    {
        $actor = $this->superMaster();
        [$banca, $grupo, $agencia] = $this->jerarquia();
        $taquilla = $this->makeTaquilla($grupo, $agencia, [
            'name' => 'Taquilla Baja',
            'code' => 'TDEL01',
            'active' => true,
        ]);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/taquillas/'.$taquilla->id, [
                'reason_code' => 'business_decision',
                'reason_text' => 'Cierre operativo',
            ])
            ->assertStatus(200);

        $this->assertSoftDeleted('taquillas', ['id' => $taquilla->id]);

        $event = $this->lastEvent('taquilla.deleted');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('taquilla', $event->entity_type);
        $this->assertSame($taquilla->id, $event->entity_id);
        $this->assertSame($banca->id, $event->banca_id);
        $this->assertSame($grupo->id, $event->grupo_id);
        $this->assertSame($agencia->id, $event->agencia_id);
        $this->assertSame($taquilla->id, $event->taquilla_id);
        $this->assertNull($event->after);
        $this->assertSame('Taquilla Baja', $event->before['name']);
        $this->assertSame('TDEL01', $event->before['code']);
        $this->assertSame($grupo->id, $event->before['grupo_id']);
        $this->assertSame($agencia->id, $event->before['agencia_id']);
        $this->assertTrue($event->before['active']);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertSame('Cierre operativo', $event->reason_text);
        $this->assertArrayNotHasKey('deleted_at', $event->before);
        $this->assertArrayNotHasKey('created_at', $event->before);
        $this->assertArrayNotHasKey('updated_at', $event->before);
    }

    public function test_destroy_without_reason_returns_422_and_keeps_taquilla(): void
    {
        $actor = $this->superMaster();
        [, $grupo, $agencia] = $this->jerarquia();
        $taquilla = $this->makeTaquilla($grupo, $agencia);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/taquillas/'.$taquilla->id)
            ->assertStatus(422);

        $this->assertNotSoftDeleted('taquillas', ['id' => $taquilla->id]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'taquilla.deleted']);
    }

    public function test_destroy_rolls_back_when_audit_write_fails(): void
    {
        $actor = $this->superMaster();
        [, $grupo, $agencia] = $this->jerarquia();
        $taquilla = $this->makeTaquilla($grupo, $agencia);

        self::$failAudit = true;

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/taquillas/'.$taquilla->id, [
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(500);

        $this->assertNotSoftDeleted('taquillas', ['id' => $taquilla->id]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'taquilla.deleted']);
    }

    // --- No secrets or timestamps ---

    public function test_taquilla_events_have_no_secrets_or_timestamps(): void
    {
        $actor = $this->superMaster();
        [$banca, $grupo, $agencia] = $this->jerarquia();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/taquillas', [
                'name' => 'Taquilla Segura',
                'code' => 'TSEG01',
                'grupo_id' => $grupo->id,
                'agencia_id' => $agencia->id,
                'user_name' => 'Operador Seguro',
                'user_email' => 'operador-seguro@test.com',
                'user_password' => 'password123',
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(201);

        $created = Taquilla::where('code', 'TSEG01')->firstOrFail();

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/taquillas/'.$created->id, [
                'name' => 'Taquilla Segura Renombrada',
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/taquillas/{$created->id}/toggle", [
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $toDelete = $this->makeTaquilla($grupo, $agencia, ['code' => 'TSEG02']);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/taquillas/'.$toDelete->id, [
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $forbidden = [
            'password', 'mac_address', 'device_fingerprint', 'clave_actual',
            'clave_nueva', 'clave_cierre', 'token', 'secret', 'api_key',
            'authorization', 'created_at', 'updated_at', 'deleted_at',
        ];

        $this->assertSame(5, AuditEvent::count());
        $this->assertSame($banca->id, $this->lastEvent('taquilla.updated')->banca_id);

        $payload = json_encode(AuditEvent::all()->map(fn (AuditEvent $event): array => [
            'before' => $event->before,
            'after' => $event->after,
            'context' => $event->context,
        ])->all());

        $this->assertStringNotContainsString($created->activation_code, $payload);
        $this->assertStringNotContainsString(
            User::where('email', 'operador-seguro@test.com')->firstOrFail()->password,
            $payload,
        );

        foreach (AuditEvent::all() as $event) {
            foreach (['before', 'after', 'context'] as $column) {
                $this->assertSame([], array_intersect(array_keys($event->{$column} ?? []), $forbidden));
            }
        }
    }
}
