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
 * WU-7b-1 — agencia audit events: `agencia.created`/`agencia.updated` P1
 * best-effort, `agencia.toggled`/`agencia.deleted` P0 fail-closed with a
 * required reason, and `agencia.banca_changed` P0 when the parent grupo
 * changes. The agencia store does not create a user, so no `user.created`
 * event is emitted here.
 */
class AuditoriaAgenciaTest extends TestCase
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
            'name' => 'Super Agencia',
            'email' => 'super-agencia@test.com',
        ]);
    }

    /**
     * Isolated hierarchy: banca → grupo → agencia.
     *
     * @return array{0: Banca, 1: Grupo, 2: Agencia}
     */
    private function jerarquia(array $agenciaOverrides = []): array
    {
        $banca = Banca::factory()->create(['active' => true]);
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $agencia = Agencia::factory()->create(array_merge([
            'grupo_id' => $grupo->id,
            'active' => true,
        ], $agenciaOverrides));

        return [$banca, $grupo, $agencia];
    }

    private function lastEvent(string $action): AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->latest('id')->firstOrFail();
    }

    // --- store: agencia.created (P1) ---

    public function test_store_records_agencia_created_p1(): void
    {
        $actor = $this->superMaster();
        $banca = Banca::factory()->create();
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id]);

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/agencias', [
                'name' => 'Agencia Auditada',
                'code' => 'AGAU01',
                'grupo_id' => $grupo->id,
                'email' => 'contacto@test.com',
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(201);

        $agencia = Agencia::where('code', 'AGAU01')->firstOrFail();
        $event = $this->lastEvent('agencia.created');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('agencia', $event->entity_type);
        $this->assertSame($agencia->id, $event->entity_id);
        $this->assertSame($banca->id, $event->banca_id);
        $this->assertSame($grupo->id, $event->grupo_id);
        $this->assertSame($agencia->id, $event->agencia_id);
        $this->assertNull($event->before);
        $this->assertSame('Agencia Auditada', $event->after['name']);
        $this->assertSame('AGAU01', $event->after['code']);
        $this->assertTrue($event->after['active']);
        $this->assertSame($grupo->id, $event->after['grupo_id']);
        $this->assertSame($actor->id, $event->after['created_by']);
        $this->assertSame('c***@test.com', $event->after['email']);
        $this->assertArrayNotHasKey('created_at', $event->after);
        $this->assertArrayNotHasKey('updated_at', $event->after);
    }

    // --- update: agencia.updated (P1) ---

    public function test_update_records_agencia_updated_with_before_after(): void
    {
        $actor = $this->superMaster();
        [$banca, , $agencia] = $this->jerarquia([
            'name' => 'Nombre Original',
            'code' => 'AGUPD01',
            'email' => 'original@test.com',
        ]);

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/agencias/'.$agencia->id, [
                'name' => 'Nombre Nuevo',
                'email' => 'nuevo@test.com',
                'reason_code' => 'business_decision',
                'reason_text' => 'Actualización operativa',
            ])
            ->assertStatus(200);

        $event = $this->lastEvent('agencia.updated');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('agencia', $event->entity_type);
        $this->assertSame($agencia->id, $event->entity_id);
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

    // --- update: agencia.banca_changed (P0, required reason) ---

    public function test_update_grupo_with_reason_records_banca_changed_p0(): void
    {
        $actor = $this->superMaster();
        [$bancaOriginal, $grupoOriginal, $agencia] = $this->jerarquia();

        $bancaNueva = Banca::factory()->create();
        $grupoNuevo = Grupo::factory()->create(['banca_id' => $bancaNueva->id]);

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/agencias/'.$agencia->id, [
                'grupo_id' => $grupoNuevo->id,
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $event = $this->lastEvent('agencia.banca_changed');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('agencia', $event->entity_type);
        $this->assertSame($agencia->id, $event->entity_id);
        $this->assertSame($bancaNueva->id, $event->banca_id);
        $this->assertEqualsCanonicalizing(
            ['grupo_id' => $grupoOriginal->id, 'banca_id' => $bancaOriginal->id],
            $event->before,
        );
        $this->assertEqualsCanonicalizing(
            ['grupo_id' => $grupoNuevo->id, 'banca_id' => $bancaNueva->id],
            $event->after,
        );
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertSame($grupoNuevo->id, $agencia->fresh()->grupo_id);
    }

    public function test_update_grupo_without_reason_returns_422_and_keeps_grupo(): void
    {
        $actor = $this->superMaster();
        [, $grupoOriginal, $agencia] = $this->jerarquia();

        $bancaNueva = Banca::factory()->create();
        $grupoNuevo = Grupo::factory()->create(['banca_id' => $bancaNueva->id]);

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/agencias/'.$agencia->id, [
                'grupo_id' => $grupoNuevo->id,
            ])
            ->assertStatus(422);

        $this->assertSame($grupoOriginal->id, $agencia->fresh()->grupo_id);
        $this->assertDatabaseMissing('audit_events', ['action' => 'agencia.banca_changed']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'agencia.updated']);
    }

    // --- toggle: agencia.toggled (P0, required reason) ---

    public function test_toggle_with_reason_records_agencia_toggled_p0(): void
    {
        $actor = $this->superMaster();
        [$banca, $grupo, $agencia] = $this->jerarquia(['active' => true]);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/agencias/{$agencia->id}/toggle", [
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200)
            ->assertJsonPath('active', false);

        $event = $this->lastEvent('agencia.toggled');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('agencia', $event->entity_type);
        $this->assertSame($agencia->id, $event->entity_id);
        $this->assertSame($banca->id, $event->banca_id);
        $this->assertSame($grupo->id, $event->grupo_id);
        $this->assertSame($agencia->id, $event->agencia_id);
        $this->assertSame(['active' => true], $event->before);
        $this->assertSame(['active' => false], $event->after);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertFalse($agencia->fresh()->active);
    }

    public function test_toggle_without_reason_returns_422_and_keeps_active(): void
    {
        $actor = $this->superMaster();
        [, , $agencia] = $this->jerarquia(['active' => true]);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/agencias/{$agencia->id}/toggle")
            ->assertStatus(422);

        $this->assertTrue($agencia->fresh()->active);
        $this->assertDatabaseMissing('audit_events', ['action' => 'agencia.toggled']);
    }

    // --- destroy: agencia.deleted (P0, required reason) ---

    public function test_destroy_with_reason_records_agencia_deleted_snapshot(): void
    {
        $actor = $this->superMaster();
        [$banca, $grupo, $agencia] = $this->jerarquia([
            'name' => 'Agencia Baja',
            'code' => 'AGDEL01',
            'active' => true,
        ]);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/agencias/'.$agencia->id, [
                'reason_code' => 'business_decision',
                'reason_text' => 'Cierre operativo',
            ])
            ->assertStatus(200);

        $this->assertSoftDeleted('agencias', ['id' => $agencia->id]);

        $event = $this->lastEvent('agencia.deleted');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('agencia', $event->entity_type);
        $this->assertSame($agencia->id, $event->entity_id);
        $this->assertSame($banca->id, $event->banca_id);
        $this->assertSame($grupo->id, $event->grupo_id);
        $this->assertSame($agencia->id, $event->agencia_id);
        $this->assertNull($event->after);
        $this->assertSame('Agencia Baja', $event->before['name']);
        $this->assertSame('AGDEL01', $event->before['code']);
        $this->assertTrue($event->before['active']);
        $this->assertSame($grupo->id, $event->before['grupo_id']);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertSame('Cierre operativo', $event->reason_text);
        $this->assertArrayNotHasKey('deleted_at', $event->before);
        $this->assertArrayNotHasKey('created_at', $event->before);
        $this->assertArrayNotHasKey('updated_at', $event->before);
    }

    public function test_destroy_without_reason_returns_422_and_keeps_cascade_intact(): void
    {
        $actor = $this->superMaster();
        [, $grupo, $agencia] = $this->jerarquia();

        $taquilla = Taquilla::factory()->create([
            'grupo_id' => $grupo->id,
            'agencia_id' => $agencia->id,
            'active' => true,
        ]);
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'agencia_id' => $agencia->id,
            'active' => true,
        ]);
        $taquillaUser->assignRole('taquilla');

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/agencias/'.$agencia->id)
            ->assertStatus(422);

        $this->assertNotSoftDeleted('agencias', ['id' => $agencia->id]);
        $this->assertNotSoftDeleted('taquillas', ['id' => $taquilla->id]);
        $this->assertDatabaseHas('users', ['id' => $taquillaUser->id, 'active' => true]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'agencia.deleted']);
    }

    public function test_destroy_rolls_back_cascade_when_audit_write_fails(): void
    {
        $actor = $this->superMaster();
        [, $grupo, $agencia] = $this->jerarquia();

        $taquilla = Taquilla::factory()->create([
            'grupo_id' => $grupo->id,
            'agencia_id' => $agencia->id,
            'active' => true,
        ]);
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'agencia_id' => $agencia->id,
            'active' => true,
        ]);
        $taquillaUser->assignRole('taquilla');

        self::$failAudit = true;

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/agencias/'.$agencia->id, [
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(500);

        $this->assertNotSoftDeleted('agencias', ['id' => $agencia->id]);
        $this->assertNotSoftDeleted('taquillas', ['id' => $taquilla->id]);
        $this->assertDatabaseHas('users', ['id' => $taquillaUser->id, 'active' => true]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'agencia.deleted']);
    }

    // --- No secrets or timestamps ---

    public function test_agencia_events_have_no_secrets_or_timestamps(): void
    {
        $actor = $this->superMaster();
        [$banca, $grupo] = $this->jerarquia();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/agencias', [
                'name' => 'Agencia Segura',
                'code' => 'AGSEG01',
                'grupo_id' => $grupo->id,
                'email' => 'contacto@test.com',
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(201);

        $created = Agencia::where('code', 'AGSEG01')->firstOrFail();

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/agencias/'.$created->id, [
                'name' => 'Agencia Segura Renombrada',
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/agencias/{$created->id}/toggle", [
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $toDelete = Agencia::factory()->create([
            'grupo_id' => $grupo->id,
            'code' => 'AGSEG02',
        ]);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/v1/agencias/'.$toDelete->id, [
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $forbidden = [
            'password', 'clave_actual', 'clave_nueva', 'clave_cierre', 'token',
            'secret', 'api_key', 'authorization', 'created_at', 'updated_at', 'deleted_at',
        ];

        $this->assertSame(4, AuditEvent::where('action', '!=', 'permission.catalog_synced')->count());
        $this->assertSame($banca->id, $this->lastEvent('agencia.updated')->banca_id);

        foreach (AuditEvent::all() as $event) {
            foreach (['before', 'after', 'context'] as $column) {
                $this->assertSame([], array_intersect(array_keys($event->{$column} ?? []), $forbidden));
            }
        }
    }
}
