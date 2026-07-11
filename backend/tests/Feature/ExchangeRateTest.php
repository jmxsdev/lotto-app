<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\ExchangeRate;
use App\Models\Banca;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    /**
     * Prueba: El endpoint público devuelve la tasa activa
     */
    public function test_public_can_get_active_rate()
    {
        // Obtener el usuario Super Master para crear la tasa
        $user = User::where('email', 'super@lotto.com')->first();
        $this->assertNotNull($user, 'Usuario super@lotto.com no encontrado. Ejecuta el seeder primero.');

        // Crear una tasa activa
        $rate = ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'notes' => 'Tasa de prueba',
            'is_active' => true,
        ]);

        // Probar endpoint público
        $response = $this->getJson('/api/exchange-rate/active');

        $response->assertStatus(200)
                 ->assertJson([
                     'rate' => 36.50,
                     'base_currency' => 'USD',
                 ]);
    }

    /**
     * Prueba: Solo Super Master o Master pueden crear tasas
     */
    public function test_only_super_master_or_master_can_create_rate()
    {
        // Crear usuario Banca
        $banca = Banca::factory()->create();
        $userBanca = User::factory()->create([
            'role' => 'banca',
            'banca_id' => $banca->id,
        ]);
        $userBanca->assignRole('banca');

        // Intentar crear tasa como Banca (debe fallar)
        $response = $this->actingAs($userBanca, 'sanctum')
                         ->postJson('/api/exchange-rates', [
                             'rate' => 37.00,
                             'notes' => 'Tasa de prueba',
                         ]);

        $response->assertStatus(403); // Forbidden

        // Crear usuario Master
        $userMaster = User::factory()->create([
            'role' => 'master',
            'banca_id' => $banca->id,
        ]);
        $userMaster->assignRole('master');

        // Intentar crear tasa como Master (debe funcionar)
        $response = $this->actingAs($userMaster, 'sanctum')
                         ->postJson('/api/exchange-rates', [
                             'rate' => 38.00,
                             'notes' => 'Tasa por Master',
                             'set_active' => true,
                         ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('exchange_rates', [
            'rate' => 38.00,
            'is_active' => true,
        ]);
    }

    /**
     * Prueba: Al activar una nueva tasa, la anterior se desactiva
     */
    public function test_new_active_rate_deactivates_previous_ones()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        $this->assertNotNull($user);

        // Crear tasa activa inicial
        $rate1 = ExchangeRate::create([
            'rate' => 36.00,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'notes' => 'Tasa 1',
            'is_active' => true,
        ]);

        // Crear nueva tasa activa como Super Master
        $response = $this->actingAs($user, 'sanctum')
                         ->postJson('/api/exchange-rates', [
                             'rate' => 37.00,
                             'notes' => 'Tasa 2',
                             'set_active' => true,
                         ]);

        $response->assertStatus(201);

        // Verificar que la primera tasa ya no esté activa
        $this->assertDatabaseHas('exchange_rates', [
            'id' => $rate1->id,
            'is_active' => false,
        ]);

        // Verificar que la nueva tasa esté activa
        $this->assertDatabaseHas('exchange_rates', [
            'rate' => 37.00,
            'is_active' => true,
        ]);
    }

    /**
     * Prueba: Se puede obtener el historial de tasas
     */
    public function test_can_get_history_of_rates()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        $this->assertNotNull($user);

        // Crear tasas históricas
        ExchangeRate::create([
            'rate' => 35.00,
            'base_currency' => 'USD',
            'reference_date' => now()->subDays(2),
            'set_by' => $user->id,
            'notes' => 'Tasa antigua',
            'is_active' => false,
        ]);

        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now()->subDay(),
            'set_by' => $user->id,
            'notes' => 'Tasa actual',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
                         ->getJson('/api/exchange-rates');

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['rate' => 35.00]);
        $response->assertJsonFragment(['rate' => 36.50]);
    }
}
