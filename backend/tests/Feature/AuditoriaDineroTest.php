<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ComisionDefault;
use App\Models\ExchangeRate;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * WU-4 — auditoría del dominio dinero: tasas y defaults de comisión P0
 * fail-closed, con scope global y before/after de campos seguros.
 */
class AuditoriaDineroTest extends TestCase
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

    private function lastEvent(string $action): AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->latest('id')->firstOrFail();
    }

    private function eventCount(string $action): int
    {
        return AuditEvent::query()->where('action', $action)->count();
    }

    private function assertGlobalScope(AuditEvent $event): void
    {
        $this->assertNull($event->banca_id);
        $this->assertNull($event->grupo_id);
        $this->assertNull($event->agencia_id);
        $this->assertNull($event->taquilla_id);
    }

    private function inactiveRate(User $actor, float $rate): ExchangeRate
    {
        return ExchangeRate::create([
            'rate' => $rate,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $actor->id,
            'notes' => 'Tasa inactiva',
            'is_active' => false,
        ]);
    }

    // --- Tasas: store ---

    public function test_store_tasa_inactiva_emite_created_sin_activacion(): void
    {
        $actor = $this->superUser();

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/exchange-rates', [
            'rate' => 40.25,
            'notes' => 'Tasa histórica de prueba',
        ]);
        $response->assertStatus(201);
        $event = $this->lastEvent('exchange_rate.created');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame($actor->email, $event->actor_label);
        $this->assertSame((int) $response->json('id'), $event->entity_id);
        $this->assertNull($event->before);
        $this->assertSame(40.25, $event->after['rate']);
        $this->assertSame('USD', $event->after['base_currency']);
        $this->assertSame($actor->id, $event->after['set_by']);
        $this->assertSame('Tasa histórica de prueba', $event->after['notes']);
        $this->assertFalse($event->after['is_active']);
        $this->assertArrayNotHasKey('created_at', $event->after);
        $this->assertSame(0, $this->eventCount('exchange_rate.activated'));
        $this->assertGlobalScope($event);
    }

    public function test_store_tasa_activa_emite_created_y_activated_y_desactiva_la_anterior(): void
    {
        $actor = $this->superUser();
        $previous = ExchangeRate::where('is_active', true)->firstOrFail();

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/exchange-rates', [
            'rate' => 41.75,
            'notes' => 'Nueva tasa activa',
            'set_active' => true,
        ]);
        $response->assertStatus(201);
        $newId = (int) $response->json('id');

        $this->assertDatabaseHas('exchange_rates', ['id' => $previous->id, 'is_active' => false]);
        $this->assertDatabaseHas('exchange_rates', ['id' => $newId, 'is_active' => true]);
        $this->assertTrue($this->lastEvent('exchange_rate.created')->after['is_active']);

        $activated = $this->lastEvent('exchange_rate.activated');
        $this->assertSame($newId, $activated->entity_id);
        $this->assertSame(['is_active' => false], $activated->before);
        $this->assertSame(['is_active' => true], $activated->after);
        $this->assertSame($previous->id, $activated->context['previous_active_id']);
        $this->assertSame(1, $activated->context['deactivated_count']);
        $this->assertGlobalScope($activated);
    }

    // --- Tasas: update ---
    public function test_update_tasa_y_notas_emite_updated_con_before_after_y_motivo(): void
    {
        $actor = $this->superUser();
        $rate = ExchangeRate::where('is_active', true)->firstOrFail();
        $originalRate = $rate->rate;

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/exchange-rates/'.$rate->id, [
                'rate' => 39.90,
                'notes' => 'Ajuste de tasa',
                'reason_code' => 'data_correction',
                'reason_text' => 'Corrección del BCV',
            ])
            ->assertStatus(200);

        $event = $this->lastEvent('exchange_rate.updated');

        $this->assertSame($rate->id, $event->entity_id);
        $this->assertSame($originalRate, $event->before['rate']);
        $this->assertSame(39.90, $event->after['rate']);
        $this->assertSame('Tasa inicial', $event->before['notes']);
        $this->assertSame('Ajuste de tasa', $event->after['notes']);
        $this->assertArrayNotHasKey('is_active', $event->before);
        $this->assertSame('data_correction', $event->reason_code);
        $this->assertSame('Corrección del BCV', $event->reason_text);
        $this->assertSame(0, $this->eventCount('exchange_rate.activated'));
        $this->assertGlobalScope($event);
    }

    public function test_update_con_activacion_emite_updated_y_activated(): void
    {
        $actor = $this->superUser();
        $previous = ExchangeRate::where('is_active', true)->firstOrFail();
        $rate = $this->inactiveRate($actor, 37.25);

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/exchange-rates/'.$rate->id, ['is_active' => true])
            ->assertStatus(200);

        $this->assertDatabaseHas('exchange_rates', ['id' => $previous->id, 'is_active' => false]);
        $this->assertDatabaseHas('exchange_rates', ['id' => $rate->id, 'is_active' => true]);

        $updated = $this->lastEvent('exchange_rate.updated');
        $this->assertSame($rate->id, $updated->entity_id);
        $this->assertSame(['is_active' => false], $updated->before);
        $this->assertSame(['is_active' => true], $updated->after);

        $activated = $this->lastEvent('exchange_rate.activated');
        $this->assertSame(['is_active' => false], $activated->before);
        $this->assertSame(['is_active' => true], $activated->after);
        $this->assertSame($previous->id, $activated->context['previous_active_id']);
        $this->assertSame(1, $activated->context['deactivated_count']);
        $this->assertGlobalScope($activated);
    }

    // --- Tasas: setActive ---

    public function test_set_active_emite_activated_con_before_after_y_contexto(): void
    {
        $actor = $this->superUser();
        $previous = ExchangeRate::where('is_active', true)->firstOrFail();
        $rate = $this->inactiveRate($actor, 38.60);

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/exchange-rates/'.$rate->id.'/set-active', ['reason_code' => 'operational_error'])
            ->assertStatus(200);

        $this->assertDatabaseHas('exchange_rates', ['id' => $previous->id, 'is_active' => false]);
        $this->assertDatabaseHas('exchange_rates', ['id' => $rate->id, 'is_active' => true]);

        $event = $this->lastEvent('exchange_rate.activated');
        $this->assertSame($rate->id, $event->entity_id);
        $this->assertSame(['is_active' => false], $event->before);
        $this->assertSame(['is_active' => true], $event->after);
        $this->assertSame($previous->id, $event->context['previous_active_id']);
        $this->assertSame(1, $event->context['deactivated_count']);
        $this->assertSame('operational_error', $event->reason_code);
        $this->assertSame(0, $this->eventCount('exchange_rate.updated'));
        $this->assertGlobalScope($event);
    }

    // --- Comisiones: updateDefaults ---

    public function test_update_defaults_emite_evento_con_before_after_bs_usd(): void
    {
        $actor = $this->superUser();

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/comisiones/defaults', [
                'defaults' => [
                    ['moneda' => 'bs', 'porcentaje_pago' => 15.50],
                    ['moneda' => 'usd', 'porcentaje_pago' => 10.00],
                ],
                'reason_code' => 'business_decision',
                'reason_text' => 'Ajuste global de comisión',
            ])
            ->assertStatus(200);
        $event = $this->lastEvent('commission_defaults.updated');

        $this->assertSame('configuracion', $event->entity_type);
        $this->assertNull($event->entity_id);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame([
            ['moneda' => 'bs', 'porcentaje_pago' => null],
            ['moneda' => 'usd', 'porcentaje_pago' => null],
        ], $event->before);
        $this->assertSame([
            ['moneda' => 'bs', 'porcentaje_pago' => '15.50'],
            ['moneda' => 'usd', 'porcentaje_pago' => '10.00'],
        ], $event->after);
        $this->assertSame('business_decision', $event->reason_code);
        $this->assertSame('Ajuste global de comisión', $event->reason_text);
        $this->assertGlobalScope($event);

        // Las lecturas de dinero no emiten eventos.
        $count = AuditEvent::count();
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/comisiones/defaults')->assertStatus(200);
        $this->getJson('/api/v1/exchange-rate/active')->assertStatus(200);
        $this->assertSame($count, AuditEvent::count());
    }

    // --- P0 fail-closed ---

    public function test_store_hace_rollback_si_falla_la_auditoria(): void
    {
        $actor = $this->superUser();

        self::$failAudit = true;

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/exchange-rates', [
            'rate' => 42.00,
            'set_active' => true,
        ])->assertStatus(500);

        $this->assertDatabaseCount('exchange_rates', 1);
        $this->assertFalse(ExchangeRate::query()->where('rate', 42.00)->exists());
        $this->assertSame(1, ExchangeRate::where('is_active', true)->count());
        $this->assertSame(
            0,
            AuditEvent::where('action', '!=', 'permission.catalog_synced')->count(),
        );
    }

    public function test_update_hace_rollback_si_falla_la_auditoria(): void
    {
        $actor = $this->superUser();
        $rate = ExchangeRate::where('is_active', true)->firstOrFail();
        $originalRate = $rate->rate;

        self::$failAudit = true;

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/exchange-rates/'.$rate->id, ['rate' => 43.00])
            ->assertStatus(500);

        $this->assertSame($originalRate, $rate->refresh()->rate);
        $this->assertSame(
            0,
            AuditEvent::where('action', '!=', 'permission.catalog_synced')->count(),
        );
    }

    public function test_update_defaults_hace_rollback_si_falla_la_auditoria(): void
    {
        $actor = $this->superUser();

        self::$failAudit = true;

        $this->actingAs($actor, 'sanctum')->putJson('/api/v1/comisiones/defaults', [
            'defaults' => [['moneda' => 'bs', 'porcentaje_pago' => 16]],
        ])->assertStatus(500);

        $this->assertSame(2, ComisionDefault::count());
        $this->assertNull(ComisionDefault::where('moneda', 'bs')->firstOrFail()->porcentaje_pago);
        $this->assertNull(ComisionDefault::where('moneda', 'usd')->firstOrFail()->porcentaje_pago);
        $this->assertSame(
            0,
            AuditEvent::where('action', '!=', 'permission.catalog_synced')->count(),
        );
    }

    // --- Sin secretos ni timestamps ---

    public function test_eventos_de_dinero_no_guardan_secretos_ni_timestamps(): void
    {
        $actor = $this->superUser();
        $forbidden = [
            'password', 'clave_actual', 'clave_nueva', 'clave_cierre', 'token',
            'secret', 'api_key', 'authorization', 'created_at', 'updated_at',
        ];

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/exchange-rates', ['rate' => 40.00, 'set_active' => true])
            ->assertStatus(201);

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/comisiones/defaults', ['defaults' => [['moneda' => 'bs', 'porcentaje_pago' => 12]]])
            ->assertStatus(200);

        $this->assertGreaterThanOrEqual(3, AuditEvent::count());

        foreach (AuditEvent::all() as $event) {
            foreach (['before', 'after', 'context'] as $column) {
                $this->assertSame([], array_intersect(array_keys($event->{$column} ?? []), $forbidden));
            }
        }
    }
}
