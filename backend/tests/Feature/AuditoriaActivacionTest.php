<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Log;
use App\Models\Taquilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class AuditoriaActivacionTest extends TestCase
{
    use RefreshDatabase;

    private static bool $failAudit = false;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function activar(array $payload = []): TestResponse
    {
        return $this->postJson('/api/v1/activar', array_merge([
            'activation_code' => 'CODE1',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'fp-act-001',
        ], $payload));
    }

    private function auditEvent(string $action): AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->latest('id')->firstOrFail();
    }

    public function test_codigo_inexistente_registra_activation_failed_sin_entidad(): void
    {
        $response = $this->activar(['activation_code' => 'INVALID']);

        $response->assertStatus(404)->assertJsonPath('success', false);

        $event = $this->auditEvent('taquilla.activation_failed');
        $attributes = (string) json_encode($event->getAttributes());

        $this->assertSame('device', $event->actor_type);
        $this->assertNull($event->actor_id);
        $this->assertSame('device:AA:BB:CC:**:**:**', $event->actor_label);
        $this->assertSame([], $event->actor_roles);
        $this->assertSame('taquilla', $event->entity_type);
        $this->assertNull($event->entity_id);
        $this->assertNull($event->banca_id);
        $this->assertNull($event->taquilla_id);
        $this->assertSame('code_not_found', $event->context['failure_reason']);
        $this->assertSame('****ALID', $event->context['activation_code']);
        $this->assertSame('AA:BB:CC:**:**:**', $event->context['mac_address']);
        $this->assertSame('operational_error', $event->reason_code);
        $this->assertStringNotContainsString('INVALID', $attributes);
        $this->assertStringNotContainsString('AA:BB:CC:DD:EE:FF', $attributes);
    }

    public function test_activacion_exitosa_registra_entidad_scope_y_antes_despues_enmascarados(): void
    {
        $taquilla = Taquilla::factory()->create([
            'activation_code' => 'ABC123',
            'active' => false,
            'mac_address' => null,
            'device_fingerprint' => null,
            'last_connection_at' => null,
        ]);

        $this->activar(['activation_code' => 'ABC123'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $event = $this->auditEvent('taquilla.activation_succeeded');
        $attributes = (string) json_encode($event->getAttributes());

        $this->assertSame('device', $event->actor_type);
        $this->assertSame($taquilla->id, $event->actor_id);
        $this->assertSame('taquilla:'.$taquilla->id, $event->actor_label);
        $this->assertSame([], $event->actor_roles);
        $this->assertSame('taquilla', $event->entity_type);
        $this->assertSame($taquilla->id, $event->entity_id);
        $this->assertSame($taquilla->grupo->banca_id, $event->banca_id);
        $this->assertSame($taquilla->grupo_id, $event->grupo_id);
        $this->assertSame($taquilla->id, $event->taquilla_id);
        $this->assertSame('activation', $event->context['mode']);
        $this->assertSame('****C123', $event->context['activation_code']);
        $this->assertFalse($event->before['active']);
        $this->assertTrue($event->after['active']);
        $this->assertSame('AA:BB:CC:**:**:**', $event->after['mac_address']);
        $this->assertSame(hash('sha256', 'fp-act-001'), $event->after['device_fingerprint']);
        $this->assertStringNotContainsString('ABC123', $attributes);
        $this->assertStringNotContainsString('AA:BB:CC:DD:EE:FF', $attributes);
        $this->assertStringNotContainsString('fp-act-001', $attributes);
    }

    public function test_reactivacion_con_la_misma_mac_registra_modo_reactivacion(): void
    {
        $taquilla = Taquilla::factory()->create([
            'activation_code' => 'RCT001',
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'fp-old',
            'last_connection_at' => null,
        ]);

        $this->activar(['activation_code' => 'RCT001'])
            ->assertStatus(200)
            ->assertJsonPath('message', 'Taquilla reactivada exitosamente.');

        $event = $this->auditEvent('taquilla.activation_succeeded');

        $this->assertSame('reactivation_same_mac', $event->context['mode']);
        $this->assertSame($taquilla->id, $event->entity_id);
        $this->assertTrue($event->after['active']);
        $this->assertNotNull($event->after['last_connection_at']);
        $this->assertSame(hash('sha256', 'fp-old'), $event->after['device_fingerprint']);
    }

    public function test_mac_distinta_registra_activation_failed_sin_mutar_la_taquilla(): void
    {
        $taquilla = Taquilla::factory()->create([
            'activation_code' => 'ABC123',
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'fp-original',
        ]);

        $response = $this->activar([
            'activation_code' => 'ABC123',
            'mac_address' => '11:22:33:44:55:66',
            'device_fingerprint' => 'fp-attacker',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Esta taquilla ya está activada con otra dirección MAC.');

        $event = $this->auditEvent('taquilla.activation_failed');

        $this->assertSame($taquilla->id, $event->entity_id);
        $this->assertSame('mac_mismatch', $event->context['failure_reason']);
        $this->assertSame('****C123', $event->context['activation_code']);
        $this->assertSame('11:22:33:**:**:**', $event->context['mac_address']);
        $this->assertNull($event->before);
        $this->assertNull($event->after);

        $this->assertDatabaseHas('taquillas', [
            'id' => $taquilla->id,
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'fp-original',
        ]);
    }

    public function test_mac_reasignada_registra_evento_de_la_taquilla_desplazada(): void
    {
        $desplazada = Taquilla::factory()->create([
            'activation_code' => 'OLD1',
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'fp-old',
        ]);

        $nueva = Taquilla::factory()->create([
            'activation_code' => 'NEW2',
            'active' => false,
            'mac_address' => null,
            'device_fingerprint' => null,
        ]);

        $this->activar([
            'activation_code' => 'NEW2',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'fp-new',
        ])->assertStatus(200);

        $event = $this->auditEvent('taquilla.mac_reassigned');

        $this->assertSame('taquilla', $event->entity_type);
        $this->assertSame($desplazada->id, $event->entity_id);
        $this->assertSame($desplazada->id, $event->taquilla_id);
        $this->assertSame($nueva->id, $event->context['reassigned_to_taquilla_id']);
        $this->assertTrue($event->before['active']);
        $this->assertFalse($event->after['active']);
        $this->assertSame('AA:BB:CC:**:**:**', $event->before['mac_address']);
        $this->assertSame(hash('sha256', 'fp-old'), $event->before['device_fingerprint']);

        $this->assertDatabaseHas('taquillas', [
            'id' => $desplazada->id,
            'active' => false,
            'mac_address' => null,
            'device_fingerprint' => null,
        ]);
        $this->assertDatabaseHas('taquillas', [
            'id' => $nueva->id,
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'fp-new',
        ]);
    }

    public function test_log_operativo_guarda_details_como_array_sin_doble_codificacion(): void
    {
        Taquilla::factory()->create([
            'activation_code' => 'LOG123',
            'active' => false,
            'mac_address' => null,
        ]);

        $this->activar(['activation_code' => 'LOG123'])->assertStatus(200);

        $log = Log::where('action', 'activacion_taquilla')->latest('id')->firstOrFail();

        $this->assertIsArray($log->details);
        $this->assertTrue($log->details['exitoso']);
        $this->assertSame('****G123', $log->details['activation_code']);

        $raw = (string) DB::table('logs')->where('action', 'activacion_taquilla')->latest('id')->value('details');

        $this->assertStringStartsWith('{', $raw);
        $this->assertIsArray(json_decode($raw, true));
    }

    public function test_fail_closed_hace_rollback_de_la_activacion_si_falla_la_auditoria(): void
    {
        $taquilla = Taquilla::factory()->create([
            'activation_code' => 'ABC123',
            'active' => false,
            'mac_address' => null,
            'device_fingerprint' => null,
        ]);

        self::$failAudit = true;

        $response = $this->activar(['activation_code' => 'ABC123']);

        $response->assertStatus(500);

        $this->assertDatabaseCount('audit_events', 0);
        $this->assertDatabaseCount('logs', 0);
        $this->assertDatabaseHas('taquillas', [
            'id' => $taquilla->id,
            'active' => false,
            'mac_address' => null,
        ]);
    }

    public function test_dispositivo_no_registrado_registra_verification_failed(): void
    {
        $response = $this->postJson('/api/v1/dispositivo/verificar', [
            'device_fingerprint' => 'fp-desconocido',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 'pending');

        $event = $this->auditEvent('device.verification_failed');
        $attributes = (string) json_encode($event->getAttributes());
        $hash = hash('sha256', 'fp-desconocido');

        $this->assertSame('device', $event->actor_type);
        $this->assertNull($event->actor_id);
        $this->assertSame('device:'.$hash, $event->actor_label);
        $this->assertSame('device', $event->entity_type);
        $this->assertNull($event->entity_id);
        $this->assertSame('device_not_registered', $event->context['failure_reason']);
        $this->assertSame($hash, $event->context['device_fingerprint']);
        $this->assertSame('security_incident', $event->reason_code);
        $this->assertStringNotContainsString('fp-desconocido', $attributes);
    }

    public function test_dispositivo_registrado_no_emite_evento(): void
    {
        Taquilla::factory()->create([
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'fp-conocido',
        ]);

        $this->postJson('/api/v1/dispositivo/verificar', [
            'device_fingerprint' => 'fp-conocido',
        ])->assertStatus(200)->assertJsonPath('status', 'active');

        $this->assertDatabaseCount('audit_events', 0);
    }
}
