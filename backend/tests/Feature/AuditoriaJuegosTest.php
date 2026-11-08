<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Juego;
use App\Models\PluginJuego;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * WU-6a — game audit events: `game.updated` P1 best-effort, `game.toggled`
 * P0 fail-closed and `game.prizes_updated` P0 with a required reason.
 *
 * WU-12a removed the legacy `juego_auditoria` dual-write; `audit_events` is
 * the single source of evidence.
 */
class AuditoriaJuegosTest extends TestCase
{
    use RefreshDatabase;

    private static bool $failAudit = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

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

    private function superUser(): User
    {
        return User::where('email', 'super@lotto.com')->firstOrFail();
    }

    private function juego(string $slug = 'triple-zulia'): Juego
    {
        return Juego::where('slug', $slug)->firstOrFail();
    }

    private function lastEvent(string $action): AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->latest('id')->firstOrFail();
    }

    private function assertGlobalScope(AuditEvent $event): void
    {
        $this->assertNull($event->banca_id);
        $this->assertNull($event->grupo_id);
        $this->assertNull($event->agencia_id);
        $this->assertNull($event->taquilla_id);
    }

    // --- game.updated (P1 best-effort) ---

    public function test_update_emite_game_updated_con_actor_entidad_y_before_after(): void
    {
        $user = $this->superUser();
        $juego = $this->juego();
        $before = $juego->only(['name', 'config']);
        $nuevaConfig = array_merge($juego->config, ['custom' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}", [
                'name' => 'Triple Zulia Auditado',
                'config' => $nuevaConfig,
                'reason_code' => 'business_decision',
                'reason_text' => 'Ajuste de configuración',
            ])
            ->assertStatus(200);

        $event = $this->lastEvent('game.updated');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame('s***@lotto.com', $event->actor_label);
        $this->assertNotSame($user->email, $event->actor_label);
        $this->assertSame('juego', $event->entity_type);
        $this->assertSame($juego->id, $event->entity_id);
        $this->assertSame($before['name'], $event->before['name']);
        $this->assertEqualsCanonicalizing($before['config'], $event->before['config']);
        $this->assertEqualsCanonicalizing($nuevaConfig, $event->after['config']);
        $this->assertSame('Triple Zulia Auditado', $event->after['name']);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertSame('Ajuste de configuración', $event->reason_text);
        $this->assertArrayNotHasKey('created_at', $event->after);
        $this->assertArrayNotHasKey('updated_at', $event->after);
        $this->assertGlobalScope($event);
    }

    public function test_update_auditoria_p1_no_bloquea_la_actualizacion(): void
    {
        $user = $this->superUser();
        $juego = $this->juego();

        self::$failAudit = true;

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}", ['name' => 'Nombre con auditoría caída'])
            ->assertStatus(200);

        $this->assertSame('Nombre con auditoría caída', $juego->fresh()->name);
        $this->assertSame(
            0,
            AuditEvent::where('action', '!=', 'permission.catalog_synced')->count(),
        );
    }

    // --- game.toggled (P0 fail-closed) ---

    public function test_toggle_emite_game_toggled_p0_con_before_after(): void
    {
        $user = $this->superUser();
        $juego = $this->juego();
        $this->assertTrue($juego->active);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/juegos/{$juego->id}/toggle", ['active' => false])
            ->assertStatus(200);

        $event = $this->lastEvent('game.toggled');

        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame('juego', $event->entity_type);
        $this->assertSame($juego->id, $event->entity_id);
        $this->assertSame(['active' => true], $event->before);
        $this->assertSame(['active' => false], $event->after);
        $this->assertNull($event->reason_code);
        $this->assertGlobalScope($event);
    }

    public function test_toggle_hace_rollback_si_falla_la_auditoria(): void
    {
        $user = $this->superUser();
        $juego = $this->juego();
        $plugin = PluginJuego::where('juego_id', $juego->id)->firstOrFail();

        self::$failAudit = true;

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/juegos/{$juego->id}/toggle", ['active' => false])
            ->assertStatus(500);

        $this->assertTrue($juego->fresh()->active, 'El toggle debe revertirse.');
        $this->assertTrue($plugin->fresh()->active, 'El plugin debe revertirse.');
        $this->assertSame(
            0,
            AuditEvent::where('action', '!=', 'permission.catalog_synced')->count(),
        );
    }

    // --- game.prizes_updated (P0 + required reason) ---

    public function test_premios_emite_game_prizes_updated_p0_con_before_after_y_motivo(): void
    {
        $user = $this->superUser();
        $juego = $this->juego();
        $premiosAntes = $juego->config['premios'];

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}/premios", [
                'base' => 700,
                'modalidades' => ['terminal' => 70],
                'comodines' => [],
                'reason_code' => 'business_decision',
                'reason_text' => 'Nuevos premios aprobados',
            ])
            ->assertStatus(200);

        $event = $this->lastEvent('game.prizes_updated');

        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame('juego', $event->entity_type);
        $this->assertSame($juego->id, $event->entity_id);
        $this->assertEqualsCanonicalizing($premiosAntes, $event->before['premios']);
        $this->assertEqualsCanonicalizing([
            'base' => 700,
            'modalidades' => ['terminal' => 70],
            'comodines' => [],
            'posicion_triples' => ['a', 'b', 'c'],
        ], $event->after['premios']);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertSame('Nuevos premios aprobados', $event->reason_text);
        $this->assertGlobalScope($event);
    }

    public function test_premios_sin_motivo_422_y_no_cambia_nada(): void
    {
        $user = $this->superUser();
        $juego = $this->juego();
        $configAntes = $juego->config;
        $eventosAntes = AuditEvent::count();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}/premios", [
                'base' => 700,
                'modalidades' => ['terminal' => 70],
                'comodines' => [],
            ])
            ->assertStatus(422);

        $this->assertEqualsCanonicalizing($configAntes, $juego->fresh()->config);
        $this->assertSame($eventosAntes, AuditEvent::count());
    }

    // --- No secrets or timestamps ---

    public function test_eventos_de_juegos_no_guardan_secretos_ni_timestamps(): void
    {
        $user = $this->superUser();
        $juego = $this->juego();

        $forbidden = [
            'password', 'clave_actual', 'clave_nueva', 'clave_cierre', 'token',
            'secret', 'api_key', 'authorization', 'created_at', 'updated_at',
        ];

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}", [
                'name' => 'Triple Zulia Seguro',
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/juegos/{$juego->id}/toggle", [
                'active' => false,
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}/premios", [
                'base' => 700,
                'modalidades' => ['terminal' => 70],
                'comodines' => [],
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(200);

        $this->assertSame(3, AuditEvent::where('action', '!=', 'permission.catalog_synced')->count());

        foreach (AuditEvent::all() as $event) {
            foreach (['before', 'after', 'context'] as $column) {
                $this->assertSame([], array_intersect(array_keys($event->{$column} ?? []), $forbidden));
            }
        }
    }
}
