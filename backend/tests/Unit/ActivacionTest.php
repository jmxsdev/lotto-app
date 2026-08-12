<?php

namespace Tests\Unit;

use App\Models\Taquilla;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ActivacionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_activacion_exitosa_con_codigo_valido()
    {
        $taquilla = Taquilla::factory()->create([
            'activation_code' => 'ABC123',
            'active' => false,
            'mac_address' => null,
        ]);

        $response = $this->postJson('/api/activar', [
            'activation_code' => 'ABC123',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.mac_address', 'AA:BB:CC:DD:EE:FF')
            ->assertJsonPath('data.active', true);

        $this->assertDatabaseHas('taquillas', [
            'id' => $taquilla->id,
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);
    }

    public function test_rechazo_con_codigo_inexistente()
    {
        $response = $this->postJson('/api/activar', [
            'activation_code' => 'INVALID',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_rechazo_con_mac_invalida()
    {
        $taquilla = Taquilla::factory()->create(['activation_code' => 'ABC123']);

        $response = $this->postJson('/api/activar', [
            'activation_code' => 'ABC123',
            'mac_address' => 'invalid-mac',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('mac_address');
    }

    public function test_reactivacion_misma_mac_actualiza_timestamp()
    {
        $taquilla = Taquilla::factory()->create([
            'activation_code' => 'DEF456',
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'last_connection_at' => now()->subHour(), // Establecer timestamp inicial
        ]);

        $oldConnection = $taquilla->last_connection_at;

        sleep(1); // Esperar 1 segundo para que cambie el timestamp

        $response = $this->postJson('/api/activar', [
            'activation_code' => 'DEF456',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $taquilla->refresh();
        $this->assertTrue($taquilla->active);
        $this->assertTrue($taquilla->last_connection_at->gt($oldConnection));
    }
}
