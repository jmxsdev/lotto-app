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
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_public_can_get_active_rate()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        $this->assertNotNull($user, 'Usuario super@lotto.com no encontrado.');

        $rate = ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'notes' => 'Tasa de prueba',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/exchange-rate/active');

        $response->assertStatus(200)
                 ->assertJson([
                     'rate' => 36.50,
                     'base_currency' => 'USD',
                 ]);
    }

    public function test_only_super_master_or_master_can_create_rate()
    {
        $banca = Banca::factory()->create();
        $userBanca = User::factory()->create([
            'role' => 'banca',
            'banca_id' => $banca->id,
        ]);
        $userBanca->assignRole('banca');

        $response = $this->actingAs($userBanca, 'sanctum')
                         ->postJson('/api/exchange-rates', [
                             'rate' => 37.00,
                             'notes' => 'Tasa de prueba',
                         ]);

        $response->assertStatus(403);

        $userMaster = User::factory()->create([
            'role' => 'master',
            'banca_id' => $banca->id,
        ]);
        $userMaster->assignRole('master');

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

    public function test_new_active_rate_deactivates_previous_ones()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        $this->assertNotNull($user);

        $rate1 = ExchangeRate::create([
            'rate' => 36.00,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'notes' => 'Tasa 1',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
                         ->postJson('/api/exchange-rates', [
                             'rate' => 37.00,
                             'notes' => 'Tasa 2',
                             'set_active' => true,
                         ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('exchange_rates', [
            'id' => $rate1->id,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('exchange_rates', [
            'rate' => 37.00,
            'is_active' => true,
        ]);
    }

    public function test_can_get_history_of_rates()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        $this->assertNotNull($user);

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
        $response->assertJsonCount(3);
        $response->assertJsonFragment(['rate' => 35.00]);
        $response->assertJsonFragment(['rate' => 36.50]);
    }
}
