<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\ExchangeRate;
use App\Models\Juego;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApuestaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\JuegoAnimalitosSeeder::class);
    }

    public function test_taquilla_puede_crear_apuesta()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $juego = Juego::where('slug', 'animalitos')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');

        $response = $this->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'leon', 'numero' => 5],
                'amount_bs' => 1800,
                'amount_usd' => 50,
                'sorteo_hora' => '2026-07-24 10:00:00',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.amount_bs', '1800.00')
            ->assertJsonPath('data.amount_usd', '50.00')
            ->assertJsonPath('data.total_bs_equivalent', '3625.00')
            ->assertJsonPath('data.exchange_rate_applied', '36.5000')
            ->assertJsonPath('data.estado', 'pendiente');

        $this->assertDatabaseHas('apuestas', [
            'taquilla_id' => $taquilla->id,
            'total_bs_equivalent' => 3625.00,
            'exchange_rate_applied' => 36.50,
        ]);
    }

    public function test_rechaza_monto_inferior_al_costo_minimo()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $juego = Juego::where('slug', 'animalitos')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');

        $response = $this->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'leon'],
                'amount_bs' => 1000,
                'amount_usd' => 0,
                'sorteo_hora' => '2026-07-24 10:00:00',
            ]);

        $response->assertStatus(422);
        $content = $response->json('message');
        $this->assertStringContainsString('costo mínimo', $content);
    }

    public function test_guarda_tasa_historica_inmutable()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $juego = Juego::where('slug', 'animalitos')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');

        // Crear apuesta con tasa 36.50
        $this->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'leon'],
                'amount_bs' => 1800,
                'amount_usd' => 50,
                'sorteo_hora' => '2026-07-24 10:00:00',
            ]);

        $apuesta = Apuesta::first();
        $this->assertNotNull($apuesta);
        $this->assertEquals(36.50, $apuesta->exchange_rate_applied);

        // Cambiar tasa activa a 37.00
        ExchangeRate::where('is_active', true)->update(['is_active' => false]);
        ExchangeRate::create([
            'rate' => 37.00,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        // Verificar que apuesta mantiene tasa original
        $apuesta->refresh();
        $this->assertEquals(36.50, $apuesta->exchange_rate_applied);
    }

    public function test_usuario_vio_solamente_taquilla_propia()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $juego = Juego::where('slug', 'animalitos')->first();

        $taquilla1 = \App\Models\Taquilla::factory()->create();
        $taquilla2 = \App\Models\Taquilla::factory()->create();

        $taquillaUser1 = User::factory()->create([
            'taquilla_id' => $taquilla1->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser1->assignRole('taquilla');

        $taquillaUser2 = User::factory()->create([
            'taquilla_id' => $taquilla2->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser2->assignRole('taquilla');

        // Crear apuestas en ambas taquillas
        Apuesta::create([
            'taquilla_id' => $taquilla1->id,
            'juego_id' => $juego->id,
            'amount_bs' => 1000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 1000,
            'estado' => 'pendiente',
        ]);

        Apuesta::create([
            'taquilla_id' => $taquilla2->id,
            'juego_id' => $juego->id,
            'amount_bs' => 2000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 2000,
            'estado' => 'pendiente',
        ]);

        // Usuario 1 solo ve apuestas de taquilla 1
        $response = $this->actingAs($taquillaUser1, 'sanctum')
            ->getJson('/api/apuestas');

        $apuestas = $response->json('data.data');
        foreach ($apuestas as $apuesta) {
            $this->assertEquals($taquilla1->id, $apuesta['taquilla_id']);
        }
    }

    public function test_master_ve_todas_las_apuestas()
    {
        $master = User::where('email', 'master@lotto.com')->first();
        $master->assignRole('master');

        $juego = Juego::where('slug', 'animalitos')->first();

        $taquilla1 = \App\Models\Taquilla::factory()->create();
        $taquilla2 = \App\Models\Taquilla::factory()->create();

        Apuesta::create([
            'taquilla_id' => $taquilla1->id,
            'juego_id' => $juego->id,
            'amount_bs' => 1000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 1000,
            'estado' => 'pendiente',
        ]);

        Apuesta::create([
            'taquilla_id' => $taquilla2->id,
            'juego_id' => $juego->id,
            'amount_bs' => 2000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 2000,
            'estado' => 'pendiente',
        ]);

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/apuestas');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_monto_cero_en_ambas_moneda_es_invalido()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $juego = Juego::where('slug', 'animalitos')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');

        $response = $this->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'leon'],
                'amount_bs' => 0,
                'amount_usd' => 0,
                'sorteo_hora' => '2026-07-24 10:00:00',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('monto');
    }

    public function test_animal_no_valido_es_rechazado()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $juego = Juego::where('slug', 'animalitos')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');

        $response = $this->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'dragon_inexistente'],
                'amount_bs' => 1000,
                'amount_usd' => 0,
                'sorteo_hora' => '2026-07-24 10:00:00',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('combinacion.animal');
    }

    public function test_show_detalle_de_apuesta()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $juego = Juego::where('slug', 'animalitos')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');

        $apuesta = Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'combinacion' => json_encode(['animal' => 'leon', 'numero' => 5]),
            'amount_bs' => 1800,
            'amount_usd' => 50,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 3625,
            'estado' => 'pendiente',
        ]);

        $response = $this->actingAs($taquillaUser, 'sanctum')
            ->getJson("/api/apuestas/{$apuesta->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $apuesta->id);
    }

    public function test_resumen_estadistico()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        $user->assignRole('super_master');
        
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $juego = Juego::where('slug', 'animalitos')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();

        Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'amount_bs' => 1800,
            'amount_usd' => 50,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 3625,
            'estado' => 'pendiente',
        ]);

        Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'amount_bs' => 0,
            'amount_usd' => 100,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 3650,
            'estado' => 'pagada',
        ]);

        // Primero probar que index funciona
        $responseIndex = $this->actingAs($user, 'sanctum')
            ->getJson('/api/apuestas');
        $responseIndex->assertStatus(200);

        // Luego probar resumen
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/apuestas/resumen');
        
        $response->assertStatus(200);
        $resumen = $response->json('data');
        
        $this->assertEquals(1800, $resumen['total_bs']);
        $this->assertEquals(150, $resumen['total_usd']);
        $this->assertEquals(7275, $resumen['total_bet_amount_bs']);
        $this->assertEquals(36.50, $resumen['tasa_actual']);
    }

    public function test_rechaza_sin_tasa_activa()
    {
        $juego = Juego::where('slug', 'animalitos')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');

        // Sin tasa activa configurada
        $response = $this->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'leon'],
                'amount_bs' => 1000,
                'amount_usd' => 0,
                'sorteo_hora' => '2026-07-24 10:00:00',
            ]);

        $response->assertStatus(422);
        $content = $response->json('message');
        $this->assertStringContainsString('tasa de cambio', $content);
    }
}
