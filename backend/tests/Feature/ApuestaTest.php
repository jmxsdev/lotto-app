<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\DetalleApuesta;
use App\Models\ExchangeRate;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApuestaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);
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

        $taquilla = Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/v1/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'perro', 'numero' => 5],
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

        $taquilla = Taquilla::factory()->create();
        JuegoLimite::create([
            'juego_id' => $juego->id,
            'banca_id' => $taquilla->grupo->banca_id,
            'moneda' => 'bs',
            'limite_minimo' => 3600,
        ]);
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/v1/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'perro'],
                'amount_bs' => 1000,
                'amount_usd' => 0,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(422);
        $content = $response->json('message');
        $this->assertStringContainsString('límite mínimo', $content);
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

        $taquilla = Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        // Crear apuesta con tasa 36.50
        $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/v1/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'perro'],
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

        $taquilla1 = Taquilla::factory()->create();
        $taquilla2 = Taquilla::factory()->create();

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
            ->getJson('/api/v1/apuestas');

        $apuestas = $response->json('data.data');
        foreach ($apuestas as $apuesta) {
            $this->assertEquals($taquilla1->id, $apuesta['taquilla_id']);
        }
    }

    public function test_master_ve_solo_apuestas_de_sus_bancas()
    {
        $master = User::where('email', 'master@lotto.com')->first();
        $master->assignRole('master');

        $juego = Juego::where('slug', 'lotto-activo')->first();

        // Banca propia del master: sus apuestas deben aparecer
        $bancaPropia = Banca::factory()->create(['master_id' => $master->id]);
        $grupoPropio = Grupo::factory()->create(['banca_id' => $bancaPropia->id]);
        $taquilla1 = Taquilla::factory()->create(['grupo_id' => $grupoPropio->id]);

        // Banca ajena (sin master): sus apuestas NO deben aparecer
        $taquilla2 = Taquilla::factory()->create();

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
            ->getJson('/api/v1/apuestas');

        $response->assertStatus(200);

        $taquillas = collect($response->json('data.data'))->pluck('taquilla_id')->all();
        $this->assertContains($taquilla1->id, $taquillas);
        $this->assertNotContains($taquilla2->id, $taquillas);
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

        $taquilla = Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/v1/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'perro'],
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

        $taquilla = Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/v1/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'dragon_inexistente'],
                'amount_bs' => 5000,
                'amount_usd' => 0,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Animal no válido', $response->json('message'));
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

        $taquilla = Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $apuesta = Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'combinacion' => json_encode(['animal' => 'perro', 'numero' => 5]),
            'amount_bs' => 1800,
            'amount_usd' => 50,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 3625,
            'estado' => 'pendiente',
        ]);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->getJson("/api/v1/apuestas/{$apuesta->id}");

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

        $taquilla = Taquilla::factory()->create();

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
            ->getJson('/api/v1/apuestas');
        $responseIndex->assertStatus(200);

        // Luego probar resumen
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/apuestas/resumen');

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

        $taquilla = Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        // Sin tasa activa configurada
        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/v1/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'perro'],
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

    protected function crearJerarquiaConMonedas(?array $bancaMonedas = null, ?array $grupoMonedas = null): Taquilla
    {
        $banca = Banca::create([
            'name' => 'Banca Test Fase 2',
            'code' => 'BTF2'.uniqid(),
            'monedas_permitidas' => $bancaMonedas,
            'active' => true,
        ]);

        $grupo = Grupo::create([
            'name' => 'Grupo Test Fase 2',
            'code' => 'GTF2'.uniqid(),
            'banca_id' => $banca->id,
            'monedas_permitidas' => $grupoMonedas,
            'active' => true,
        ]);

        return Taquilla::create([
            'name' => 'Taquilla Test Fase 2',
            'code' => 'TTF2'.uniqid(),
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
            ->postJson('/api/v1/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'perro', 'numero' => 5],
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

        $taquilla = Taquilla::factory()->create();
        $bancaId = $taquilla->grupo->banca_id;

        // Configurar límite máximo: 100 BS a nivel banca
        JuegoLimite::create([
            'juego_id' => $juego->id,
            'banca_id' => $bancaId,
            'moneda' => 'bs',
            'grupo_id' => null,
            'taquilla_id' => null,
            'limite_maximo' => 100,
            'limite_minimo' => 3600,
        ]);

        // También necesitamos un límite mínimo para BS que el juego pide
        JuegoLimite::updateOrCreate(
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
            ->postJson('/api/v1/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'perro', 'numero' => 5],
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

        $taquilla = Taquilla::factory()->create();
        $bancaId = $taquilla->grupo->banca_id;

        // Configurar límite mínimo: 5000 BS a nivel banca
        JuegoLimite::updateOrCreate(
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
            ->postJson('/api/v1/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'perro', 'numero' => 5],
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

        $taquilla = Taquilla::factory()->create();
        $bancaId = $taquilla->grupo->banca_id;
        $grupoId = $taquilla->grupo_id;

        // Limite a nivel banca: max 200
        JuegoLimite::create([
            'juego_id' => $juego->id,
            'banca_id' => $bancaId,
            'moneda' => 'bs',
            'grupo_id' => null,
            'taquilla_id' => null,
            'limite_minimo' => 0,
            'limite_maximo' => 200,
        ]);

        // Limite a nivel grupo: max 100 (más restrictivo que banca)
        JuegoLimite::create([
            'juego_id' => $juego->id,
            'banca_id' => $bancaId,
            'moneda' => 'bs',
            'grupo_id' => $grupoId,
            'taquilla_id' => null,
            'limite_minimo' => 0,
            'limite_maximo' => 100,
        ]);

        // Limite a nivel taquilla: max 50 (aún más restrictivo)
        JuegoLimite::create([
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
            ->postJson('/api/v1/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'perro', 'numero' => 5],
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
            ->postJson('/api/v1/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'perro', 'numero' => 5],
                'amount_bs' => 0,
                'amount_usd' => 50,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(422);
        $content = $response->json('message');
        $this->assertStringContainsString('USD', $content);

        // Pero apuesta en BS sí debe pasar
        JuegoLimite::updateOrCreate(
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
            ->postJson('/api/v1/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'perro', 'numero' => 5],
                'amount_bs' => 2000,
                'amount_usd' => 0,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ]);

        $responseBs->assertStatus(201);
    }

    // ============================================
    // F1d 1.12 — PagoController: motor + estado ganadora / legacy pendiente (REQ10/D5/N11)
    // ============================================

    private function crearApuestaGanadoraParaPago(string $estado, bool $conResultado = true): Apuesta
    {
        $user = User::where('email', 'super@lotto.com')->first();
        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = Taquilla::factory()->create();
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        // Resultado con "Delfin" (sin acento) y apuesta "Delfín" (con acento):
        // el motor normaliza (H13/N10); el plugin legacy no.
        $resultado = Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => '2026-09-21',
            'hora_sorteo' => '13:00',
            'numeros_ganadores' => ['numero' => 0, 'nombre_animal' => 'Delfin'],
        ]);

        $apuesta = Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'combinacion' => json_encode(['animal' => 'Delfín', 'numero' => 0]),
            'amount_bs' => 10,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 10,
            'estado' => $estado,
            'resultado_id' => $conResultado ? $resultado->id : null,
        ]);

        $apuesta->setRelation('resultado', $resultado);

        DetalleApuesta::create([
            'apuesta_id' => $apuesta->id,
            'combinacion' => json_encode(['animal' => 'Delfín', 'numero' => 0]),
            'monto' => 10,
            'premio_posible' => 300,
            'premio_posible_usd' => 0,
            'premio_ganado' => null,
            'premio_ganado_usd' => null,
        ]);

        return $apuesta;
    }

    public function test_pago_apuesta_ganadora_aceptado_contra_motor()
    {
        // REQ10/D5: una apuesta en estado `ganadora` (premio > 0, resultado_id)
        // se paga validando contra el motor (30× base lotto-activo, acentos).
        $user = User::where('email', 'super@lotto.com')->first();
        $apuesta = $this->crearApuestaGanadoraParaPago('ganadora');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/pagos', [
                'apuesta_id' => $apuesta->id,
                'tipo' => 'egreso',
                'moneda' => 'bs',
                'amount_bs' => 300,
                'amount_usd' => 0,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.apuesta_id', $apuesta->id);

        $this->assertDatabaseHas('apuestas', [
            'id' => $apuesta->id,
            'estado' => 'pagada',
        ]);
        $this->assertDatabaseHas('detalle_apuestas', [
            'apuesta_id' => $apuesta->id,
            'premio_ganado' => 300.00,
        ]);
    }

    public function test_pago_apuesta_pendiente_legacy_con_resultado_aceptado()
    {
        // REQ10/N11: compatibilidad — una apuesta legacy `pendiente` que ya
        // tiene resultado_id (liquidada antes del cambio de estados) se paga.
        $user = User::where('email', 'super@lotto.com')->first();
        $apuesta = $this->crearApuestaGanadoraParaPago('pendiente');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/pagos', [
                'apuesta_id' => $apuesta->id,
                'tipo' => 'egreso',
                'moneda' => 'bs',
                'amount_bs' => 300,
                'amount_usd' => 0,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('apuestas', [
            'id' => $apuesta->id,
            'estado' => 'pagada',
        ]);
    }

    public function test_pago_apuesta_pendiente_sin_resultado_rechazado()
    {
        // D5: `pendiente` SIN resultado_id no se paga como egreso (no está
        // liquidada); el monto del premio no puede validarse contra el motor.
        $user = User::where('email', 'super@lotto.com')->first();
        $apuesta = $this->crearApuestaGanadoraParaPago('pendiente', false);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/pagos', [
                'apuesta_id' => $apuesta->id,
                'tipo' => 'egreso',
                'moneda' => 'bs',
                'amount_bs' => 300,
                'amount_usd' => 0,
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('apuestas', [
            'id' => $apuesta->id,
            'estado' => 'pendiente',
        ]);
    }

    public function test_pago_monto_no_coincide_con_motor_rechazado()
    {
        // N11: el monto se valida contra el motor (10 Bs × 30× = 300);
        // un monto distinto (301) se rechaza.
        $user = User::where('email', 'super@lotto.com')->first();
        $apuesta = $this->crearApuestaGanadoraParaPago('ganadora');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/pagos', [
                'apuesta_id' => $apuesta->id,
                'tipo' => 'egreso',
                'moneda' => 'bs',
                'amount_bs' => 301,
                'amount_usd' => 0,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('premio_esperado_bs', 300);
    }
}
