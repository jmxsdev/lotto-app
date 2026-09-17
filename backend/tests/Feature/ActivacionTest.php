<?php

namespace Tests\Feature;

use App\Models\Log;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_activacion_exitosa_con_datos_validos()
    {
        $taquilla = Taquilla::factory()->create([
            'activation_code' => 'ABC123',
            'active' => false,
            'mac_address' => null,
        ]);

        $response = $this->postJson('/api/v1/activar', [
            'activation_code' => 'ABC123',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'test-fp-001',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.mac_address', 'AA:BB:CC:DD:EE:FF');

        $this->assertDatabaseHas('taquillas', [
            'id' => $taquilla->id,
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'test-fp-001',
        ]);

        // Verificar que se creó log de activación
        $log = Log::where('action', 'activacion_taquilla')->latest()->first();
        $this->assertNotNull($log);
        $this->assertEquals(true, json_decode($log->details)->exitoso);
    }

    public function test_rechazo_con_codigo_inexistente()
    {
        $response = $this->postJson('/api/v1/activar', [
            'activation_code' => 'INVALID',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'test-fp-001',
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_rechazo_con_mac_invalida()
    {
        $taquilla = Taquilla::factory()->create(['activation_code' => 'ABC123']);

        $response = $this->postJson('/api/v1/activar', [
            'activation_code' => 'ABC123',
            'mac_address' => 'invalid-mac-format',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('mac_address');
    }

    public function test_reasignacion_automatica_desactiva_anterior()
    {
        $taquilla1 = Taquilla::factory()->create([
            'activation_code' => 'CODE1',
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'test-fp-001',
        ]);

        $taquilla2 = Taquilla::factory()->create([
            'activation_code' => 'CODE2',
            'active' => false,
            'mac_address' => null,
        ]);

        // Activar taquilla 2 con MAC de taquilla 1 (debería desactivar taquilla 1)
        $response = $this->postJson('/api/v1/activar', [
            'activation_code' => 'CODE2',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'test-fp-001',
        ]);

        $response->assertStatus(200);

        // Verificar que taquilla 1 quedó desactivada
        $this->assertDatabaseHas('taquillas', [
            'id' => $taquilla1->id,
            'active' => false,
            'mac_address' => null,
        ]);

        // Verificar que taquilla 2 quedó activa
        $this->assertDatabaseHas('taquillas', [
            'id' => $taquilla2->id,
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'test-fp-001',
        ]);
    }

    public function test_middleware_verify_mac_bloquea_sin_header()
    {
        $taquilla = Taquilla::factory()->create();
        $user = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $user->assignRole('taquilla');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/apuestas');

        $response->assertStatus(403);
    }

    public function test_middleware_verify_mac_bloquea_mac_diferente()
    {
        $taquilla = Taquilla::factory()->create([
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'test-fp-001',
        ]);
        $user = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $user->assignRole('taquilla');

        $response = $this->withHeaders(['X-Device-MAC' => '11:22:33:44:55:66'])
            ->actingAs($user, 'sanctum')
            ->getJson('/api/v1/apuestas');

        $response->assertStatus(403);
    }

    public function test_middleware_verify_mac_permite_mac_correcta()
    {
        $taquilla = Taquilla::factory()->create([
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'test-fp-001',
        ]);
        $user = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $user->assignRole('taquilla');

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF', 'X-Device-Fingerprint' => 'test-fp-001'])
            ->actingAs($user, 'sanctum')
            ->getJson('/api/v1/apuestas');

        $response->assertStatus(200);
    }

    public function test_endpoint_activar_no_requiere_auth()
    {
        $taquilla = Taquilla::factory()->create([
            'activation_code' => 'PUBLIC',
            'active' => false,
            'mac_address' => null,
        ]);

        $response = $this->postJson('/api/v1/activar', [
            'activation_code' => 'PUBLIC',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'test-fp-001',
        ]);

        $response->assertStatus(200);
    }

    public function test_master_user_puede_acceder_sin_mac()
    {
        $master = User::where('email', 'master@lotto.com')->first();
        $master->assignRole('master');

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/exchange-rates');

        $response->assertStatus(200);
    }

    // ==================================================
    // CARACTERIZACIÓN (activacion-taquilla): reactivación
    // y protección de MAC — comportamiento actual preservado.
    // ==================================================

    public function test_reactivacion_misma_mac_responde_200_y_actualiza_last_connection_at()
    {
        $taquilla = Taquilla::factory()->create([
            'activation_code' => 'ABC123',
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'test-fp-001',
            'last_connection_at' => null,
        ]);

        $response = $this->postJson('/api/v1/activar', [
            'activation_code' => 'ABC123',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'test-fp-001',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Taquilla reactivada exitosamente.');

        // La reactivación con la misma MAC actualiza last_connection_at.
        $this->assertNotNull($taquilla->fresh()->last_connection_at);
        $this->assertDatabaseHas('taquillas', [
            'id' => $taquilla->id,
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);
    }

    public function test_activa_con_mac_distinta_devuelve_403()
    {
        $taquilla = Taquilla::factory()->create([
            'activation_code' => 'ABC123',
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'test-fp-001',
        ]);

        $response = $this->postJson('/api/v1/activar', [
            'activation_code' => 'ABC123',
            'mac_address' => '11:22:33:44:55:66',
            'device_fingerprint' => 'test-fp-002',
        ]);

        // Protección de MAC: nunca activar con una MAC distinta.
        $response->assertStatus(403)
            ->assertJsonPath('message', 'Esta taquilla ya está activada con otra dirección MAC.');

        $this->assertDatabaseHas('taquillas', [
            'id' => $taquilla->id,
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);
    }
}
