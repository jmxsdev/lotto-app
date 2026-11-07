<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Banca;
use App\Models\Configuracion;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * WU-6b — limit and bet-expiration-window audit events.
 *
 * `game.limits_updated` is P0 fail-closed (limit write rolls back when the
 * evidence cannot be persisted); `game.limits_batch_updated`,
 * `game.limit_deleted` and `config.bet_expiration_window_updated` are P1
 * best-effort (the business change persists even when the audit insert fails).
 */
class AuditoriaLimitesConfigTest extends TestCase
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

    private function banca(): Banca
    {
        return Banca::where('code', 'BT001')->firstOrFail();
    }

    private function grupo(): Grupo
    {
        return Grupo::where('code', 'GT001')->firstOrFail();
    }

    private function juego(string $slug = 'juego-auditoria-limites'): Juego
    {
        return Juego::firstOrCreate(
            ['slug' => $slug],
            ['name' => 'Juego Auditoría Límites', 'type' => 'animalitos', 'active' => true],
        );
    }

    private function crearLimite(Juego $juego, array $overrides = []): JuegoLimite
    {
        return JuegoLimite::create(array_merge([
            'juego_id' => $juego->id,
            'banca_id' => $this->banca()->id,
            'grupo_id' => null,
            'taquilla_id' => null,
            'moneda' => 'bs',
            'limite_maximo' => 100,
        ], $overrides));
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

    private function assertSinSecretos(AuditEvent $event): void
    {
        $forbidden = [
            'password', 'clave_actual', 'clave_nueva', 'clave_cierre', 'token',
            'secret', 'api_key', 'authorization', 'created_at', 'updated_at',
        ];

        foreach (['before', 'after', 'context'] as $column) {
            $this->assertSame([], array_intersect(array_keys($event->{$column} ?? []), $forbidden));
        }
    }

    // --- game.limits_updated (P0 fail-closed) ---

    public function test_update_crea_y_actualiza_con_before_y_after(): void
    {
        $user = $this->superUser();
        $juego = $this->juego();
        $banca = $this->banca();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/limites/{$juego->id}", [
                'banca_id' => $banca->id,
                'moneda' => 'bs',
                'limite_minimo' => 10,
                'limite_maximo' => 500,
                'porcentaje_pago' => 15,
                'participacion' => 50,
                'reason_code' => 'business_decision',
                'reason_text' => 'Límite aprobado por operaciones',
            ])
            ->assertStatus(201);

        $limite = JuegoLimite::where('juego_id', $juego->id)->firstOrFail();
        $creado = $this->lastEvent('game.limits_updated');

        $this->assertSame('user', $creado->actor_type);
        $this->assertSame($user->id, $creado->actor_id);
        $this->assertSame('juego_limite', $creado->entity_type);
        $this->assertSame($limite->id, $creado->entity_id);
        $this->assertSame($banca->id, $creado->banca_id);
        $this->assertNull($creado->before);
        $this->assertEquals(10.0, $creado->after['limite_minimo']);
        $this->assertEquals(500.0, $creado->after['limite_maximo']);
        $this->assertEquals(15.0, $creado->after['porcentaje_pago']);
        $this->assertEquals(50.0, $creado->after['participacion']);
        $this->assertSame('business_decision', $creado->reason_code);
        $this->assertSame('Límite aprobado por operaciones', $creado->reason_text);
        $this->assertArrayNotHasKey('id', $creado->after);
        $this->assertSinSecretos($creado);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/limites/{$juego->id}", [
                'banca_id' => $banca->id,
                'moneda' => 'bs',
                'limite_maximo' => 900,
            ])
            ->assertStatus(200);

        $actualizado = $this->lastEvent('game.limits_updated');

        $this->assertSame($limite->id, $actualizado->entity_id);
        $this->assertEquals(500.0, $actualizado->before['limite_maximo']);
        $this->assertEquals(900.0, $actualizado->after['limite_maximo']);
        $this->assertNull($actualizado->reason_code);
        $this->assertEquals(900.0, (float) $limite->fresh()->limite_maximo);
        $this->assertSinSecretos($actualizado);
        $this->assertSame(2, AuditEvent::where('action', '!=', 'permission.catalog_synced')->count());
    }

    public function test_update_con_null_explicito_elimina_la_fila_y_registra_after_null(): void
    {
        $user = $this->superUser();
        $juego = $this->juego();
        $limite = $this->crearLimite($juego, ['limite_maximo' => 100]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/limites/{$juego->id}", [
                'banca_id' => $this->banca()->id,
                'moneda' => 'bs',
                'limite_maximo' => null,
            ])
            ->assertStatus(200);

        $this->assertDatabaseMissing('juego_limites', ['id' => $limite->id]);

        // La fila ya no existe: entity_id conserva el último id conocido y
        // `context.deleted` documenta el borrado.
        $event = $this->lastEvent('game.limits_updated');

        $this->assertSame($limite->id, $event->entity_id);
        $this->assertEquals(100.0, $event->before['limite_maximo']);
        $this->assertNull($event->after);
        $this->assertTrue($event->context['deleted']);
    }

    public function test_update_falla_cerrado_si_la_auditoria_falla(): void
    {
        $user = $this->superUser();
        $juego = $this->juego();
        $limite = $this->crearLimite($juego, ['limite_maximo' => 100]);

        self::$failAudit = true;

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/limites/{$juego->id}", [
                'banca_id' => $this->banca()->id,
                'moneda' => 'bs',
                'limite_maximo' => 999,
            ])
            ->assertStatus(500);

        $this->assertEquals(100.0, (float) $limite->fresh()->limite_maximo, 'El límite debe revertirse.');
        $this->assertSame(
            0,
            AuditEvent::where('action', '!=', 'permission.catalog_synced')->count(),
        );
    }

    // --- game.limits_batch_updated (P1 best-effort) ---

    public function test_batch_emite_resumen_y_no_bloquea_si_la_auditoria_falla(): void
    {
        $user = $this->superUser();
        $juego = $this->juego();
        $banca = $this->banca();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/limites/batch', [
                'limites' => [
                    ['juego_id' => $juego->id, 'banca_id' => $banca->id, 'moneda' => 'bs', 'limite_maximo' => 500],
                    ['juego_id' => $juego->id, 'banca_id' => $banca->id, 'moneda' => 'usd', 'limite_maximo' => 50],
                ],
                'reason_code' => 'business_decision',
            ])
            ->assertStatus(201);

        $event = $this->lastEvent('game.limits_batch_updated');

        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame('juego_limite', $event->entity_type);
        $this->assertNull($event->entity_id);
        $this->assertGlobalScope($event);
        $this->assertSame(2, $event->context['count']);
        $this->assertSame(0, $event->before['count']);
        $this->assertSame(2, $event->after['count']);
        $this->assertCount(2, $event->after['items']);
        $this->assertFalse($event->context['truncated']);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertSinSecretos($event);

        self::$failAudit = true;

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/limites/batch', [
                'limites' => [
                    ['juego_id' => $juego->id, 'banca_id' => $banca->id, 'moneda' => 'bs', 'limite_maximo' => 250],
                ],
            ])
            ->assertStatus(201);

        $this->assertEquals(250.0, (float) JuegoLimite::where('juego_id', $juego->id)
            ->where('moneda', 'bs')
            ->value('limite_maximo'));
        $this->assertSame(1, AuditEvent::where('action', '!=', 'permission.catalog_synced')->count());
    }

    // --- game.limit_deleted (P1 best-effort) ---

    public function test_destroy_emite_snapshot_y_no_bloquea_si_la_auditoria_falla(): void
    {
        $user = $this->superUser();
        $juego = $this->juego();
        $grupo = $this->grupo();
        $limite = $this->crearLimite($juego, ['grupo_id' => $grupo->id, 'limite_maximo' => 50]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/limites/{$limite->id}", [
                'reason_code' => 'business_decision',
                'reason_text' => 'Límite obsoleto',
            ])
            ->assertStatus(200);

        $this->assertDatabaseMissing('juego_limites', ['id' => $limite->id]);

        $event = $this->lastEvent('game.limit_deleted');

        $this->assertSame('juego_limite', $event->entity_type);
        $this->assertSame($limite->id, $event->entity_id);
        $this->assertSame($this->banca()->id, $event->banca_id);
        $this->assertSame($grupo->id, $event->grupo_id);
        $this->assertNull($event->taquilla_id);
        $this->assertEquals(50.0, $event->before['limite_maximo']);
        $this->assertNull($event->after);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertSame('Límite obsoleto', $event->reason_text);
        $this->assertSinSecretos($event);

        self::$failAudit = true;

        $otro = $this->crearLimite($juego, ['moneda' => 'usd']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/limites/{$otro->id}", ['reason_code' => 'business_decision'])
            ->assertStatus(200);

        $this->assertDatabaseMissing('juego_limites', ['id' => $otro->id]);
        $this->assertSame(1, AuditEvent::where('action', '!=', 'permission.catalog_synced')->count());
    }

    // --- config.bet_expiration_window_updated (P1 best-effort) ---

    public function test_config_ventana_emite_before_after_y_no_bloquea_si_falla(): void
    {
        $user = $this->superUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/configuraciones/apuestas-vencimiento', [
                'horas' => 48,
                'reason_code' => 'business_decision',
                'reason_text' => 'Ventana ampliada por operaciones',
            ])
            ->assertStatus(200)
            ->assertJsonPath('horas', 48);

        $event = $this->lastEvent('config.bet_expiration_window_updated');

        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame('configuracion', $event->entity_type);
        $this->assertNull($event->entity_id);
        $this->assertGlobalScope($event);
        $this->assertSame(['horas' => 24], $event->before);
        $this->assertSame(['horas' => 48], $event->after);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertSame('Ventana ampliada por operaciones', $event->reason_text);
        $this->assertSinSecretos($event);

        self::$failAudit = true;

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/configuraciones/apuestas-vencimiento', ['horas' => 24])
            ->assertStatus(200)
            ->assertJsonPath('horas', 24);

        $this->assertSame(1, Configuracion::count());
        $this->assertSame(24, Configuracion::firstOrFail()->value['horas']);
        $this->assertSame(1, AuditEvent::where('action', '!=', 'permission.catalog_synced')->count());
    }
}
