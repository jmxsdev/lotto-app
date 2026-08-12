<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\ExchangeRate;
use App\Models\Juego;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ApuestaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
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

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'leon', 'numero' => 5],
                'amount_bs' => 1800,
                'amount_usd' => 50,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
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

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'leon'],
                'amount_bs' => 1000,
                'amount_usd' => 0,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
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

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        // Crear apuesta con tasa 36.50
        $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'leon'],
                'amount_bs' => 1800,
                'amount_usd' => 50,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
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

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla1 = \App\Models\Taquilla::factory()->create();
        $taquilla2 = \App\Models\Taquilla::factory()->create();

        $taquillaUser1 = User::factory()->create([
            'taquilla_id' => $taquilla1->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser1->assignRole('taquilla');
        $taquilla1->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $taquillaUser2 = User::factory()->create([
            'taquilla_id' => $taquilla2->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser2->assignRole('taquilla');
        $taquilla2->update(['mac_address' => '11:22:33:44:55:66']);

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
        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser1, 'sanctum')
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

        $juego = Juego::where('slug', 'lotto-activo')->first();

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

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'leon'],
                'amount_bs' => 0,
                'amount_usd' => 0,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
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

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'dragon_inexistente'],
                'amount_bs' => 1000,
                'amount_usd' => 0,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
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

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

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

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
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

        $juego = Juego::where('slug', 'lotto-activo')->first();

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
        ExchangeRate::query()->delete();

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        // Sin tasa activa configurada
        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'leon'],
                'amount_bs' => 1000,
                'amount_usd' => 0,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(422);
        $content = $response->json('message');
        $this->assertStringContainsString('tasa de cambio', $content);
    }

    // ============================================
    // Phase 2: Inheritance Resolvers + Validation Tests
    // ============================================

    protected function crearJerarquiaConMonedas(?array $bancaMonedas = null, ?array $grupoMonedas = null): \App\Models\Taquilla
    {
        $banca = \App\Models\Banca::create([
            'name' => 'Banca Test Fase 2',
            'code' => 'BTF2' . uniqid(),
            'monedas_permitidas' => $bancaMonedas,
            'active' => true,
        ]);

        $grupo = \App\Models\Grupo::create([
            'name' => 'Grupo Test Fase 2',
            'code' => 'GTF2' . uniqid(),
            'banca_id' => $banca->id,
            'monedas_permitidas' => $grupoMonedas,
            'active' => true,
        ]);

        return \App\Models\Taquilla::create([
            'name' => 'Taquilla Test Fase 2',
            'code' => 'TTF2' . uniqid(),
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);
    }

    public function test_rechaza_apuesta_en_moneda_deshabilitada()
    {
        $user = User::where('email', 'super@lotto.com')->first();

        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $juego = Juego::where('slug', 'lotto-activo')->first();

        // Banca permite solo BS, no USD
        $taquilla = $this->crearJerarquiaConMonedas(
            ['bs' => true, 'usd' => false],
            null // grupo hereda
        );

        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'leon', 'numero' => 5],
                'amount_bs' => 0,
                'amount_usd' => 50,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(422);
        $content = $response->json('message');
        $this->assertStringContainsString('USD', $content);
    }

    public function test_rechaza_apuesta_que_excede_limite_maximo()
    {
        $user = User::where('email', 'super@lotto.com')->first();

        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();
        $bancaId = $taquilla->grupo->banca_id;

        // Configurar límite máximo: 100 BS a nivel banca
        \App\Models\JuegoLimite::create([
            'juego_id' => $juego->id,
            'banca_id' => $bancaId,
            'moneda' => 'bs',
            'grupo_id' => null,
            'taquilla_id' => null,
            'limite_maximo' => 100,
            'limite_minimo' => 3600,
        ]);

        // También necesitamos un límite mínimo para BS que el juego pide
        \App\Models\JuegoLimite::updateOrCreate(
            [
                'juego_id' => $juego->id,
                'banca_id' => $bancaId,
                'moneda' => 'bs',
                'grupo_id' => null,
                'taquilla_id' => null,
            ],
            ['limite_minimo' => 0, 'limite_maximo' => 100]
        );

        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        // Apuesta 150 BS (excede máximo 100)
        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'leon', 'numero' => 5],
                'amount_bs' => 150,
                'amount_usd' => 0,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(422);
        $content = $response->json('message');
        $this->assertStringContainsString('límite máximo', $content);
    }

    public function test_rechaza_apuesta_debajo_limite_minimo()
    {
        $user = User::where('email', 'super@lotto.com')->first();

        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();
        $bancaId = $taquilla->grupo->banca_id;

        // Configurar límite mínimo: 5000 BS a nivel banca
        \App\Models\JuegoLimite::updateOrCreate(
            [
                'juego_id' => $juego->id,
                'banca_id' => $bancaId,
                'moneda' => 'bs',
                'grupo_id' => null,
                'taquilla_id' => null,
            ],
            ['limite_minimo' => 5000, 'limite_maximo' => null]
        );

        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        // Apuesta 3000 BS (por debajo del mínimo 5000)
        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'leon', 'numero' => 5],
                'amount_bs' => 3000,
                'amount_usd' => 0,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(422);
        $content = $response->json('message');
        $this->assertStringContainsString('límite mínimo', $content);
    }

    public function test_limite_taquilla_prevalece_sobre_grupo()
    {
        $user = User::where('email', 'super@lotto.com')->first();

        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = \App\Models\Taquilla::factory()->create();
        $bancaId = $taquilla->grupo->banca_id;
        $grupoId = $taquilla->grupo_id;

        // Limite a nivel banca: max 200
        \App\Models\JuegoLimite::create([
            'juego_id' => $juego->id,
            'banca_id' => $bancaId,
            'moneda' => 'bs',
            'grupo_id' => null,
            'taquilla_id' => null,
            'limite_minimo' => 0,
            'limite_maximo' => 200,
        ]);

        // Limite a nivel grupo: max 100 (más restrictivo que banca)
        \App\Models\JuegoLimite::create([
            'juego_id' => $juego->id,
            'banca_id' => $bancaId,
            'moneda' => 'bs',
            'grupo_id' => $grupoId,
            'taquilla_id' => null,
            'limite_minimo' => 0,
            'limite_maximo' => 100,
        ]);

        // Limite a nivel taquilla: max 50 (aún más restrictivo)
        \App\Models\JuegoLimite::create([
            'juego_id' => $juego->id,
            'banca_id' => $bancaId,
            'moneda' => 'bs',
            'grupo_id' => $grupoId,
            'taquilla_id' => $taquilla->id,
            'limite_minimo' => 0,
            'limite_maximo' => 50,
        ]);

        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        // Apuesta 60 BS: debería ser rechazada porque taquilla tiene max 50
        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'leon', 'numero' => 5],
                'amount_bs' => 60,
                'amount_usd' => 0,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(422);
        $content = $response->json('message');
        $this->assertStringContainsString('50', $content);
    }

    public function test_monedas_interseccion_banca_y_grupo()
    {
        $user = User::where('email', 'super@lotto.com')->first();

        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $juego = Juego::where('slug', 'lotto-activo')->first();

        // Banca: ambas habilitadas; Grupo: solo BS
        $taquilla = $this->crearJerarquiaConMonedas(
            ['bs' => true, 'usd' => true],
            ['bs' => true, 'usd' => false]
        );

        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        // Apuesta en USD debería ser rechazada (grupo deshabilitó USD)
        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'leon', 'numero' => 5],
                'amount_bs' => 0,
                'amount_usd' => 50,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(422);
        $content = $response->json('message');
        $this->assertStringContainsString('USD', $content);

        // Pero apuesta en BS sí debe pasar
        \App\Models\JuegoLimite::updateOrCreate(
            [
                'juego_id' => $juego->id,
                'banca_id' => $taquilla->grupo->banca_id,
                'moneda' => 'bs',
                'grupo_id' => null,
                'taquilla_id' => null,
            ],
            ['limite_minimo' => 0, 'limite_maximo' => null]
        );

        $responseBs = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'leon', 'numero' => 5],
                'amount_bs' => 2000,
                'amount_usd' => 0,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ]);

        $responseBs->assertStatus(201);
    }
}
