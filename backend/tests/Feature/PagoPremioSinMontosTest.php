<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\ExchangeRate;
use App\Models\Juego;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * WU pago-premio — backend AUTORITATIVO del premio.
 *
 * Con `tipo=egreso` los montos son OPCIONALES: sin montos el backend aplica el
 * premio calculado por el MOTOR (`config.premios`); con montos se mantiene la
 * validacion +-0.01. La taquilla no calcula ni confirma nada.
 */
class PagoPremioSinMontosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);
    }

    private function crearTaquillaUsuario(): array
    {
        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = Taquilla::factory()->create();
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');

        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => User::where('email', 'super@lotto.com')->first()->id,
            'is_active' => true,
        ]);

        return [$juego, $taquilla, $taquillaUser];
    }

    private function crearApuestaGanadora(Juego $juego, Taquilla $taquilla, float $bs, float $usd): Apuesta
    {
        $apuesta = Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'combinacion' => json_encode(['animal' => 'perro', 'numero' => 5]),
            'amount_bs' => $bs,
            'amount_usd' => $usd,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => $bs + ($usd * 36.50),
            'estado' => 'pendiente',
            'fecha_hora' => now(),
            'sorteo_hora' => now()->addHour(),
        ]);

        $resultado = Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => now(),
            'numeros_ganadores' => ['nombre_animal' => 'Perro', 'numero' => 5],
        ]);

        $apuesta->update(['resultado_id' => $resultado->id]);

        return $apuesta;
    }

    private function pagar(Apuesta $apuesta, User $user, array $extra = []): TestResponse
    {
        return $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($user, 'sanctum')
            ->postJson('/api/v1/pagos', array_merge([
                'apuesta_id' => $apuesta->id,
                'tipo' => 'egreso',
                'moneda' => 'bs',
            ], $extra));
    }

    public function test_egreso_sin_montos_usa_el_premio_calculado_por_el_motor()
    {
        [$juego, $taquilla, $user] = $this->crearTaquillaUsuario();
        $apuesta = $this->crearApuestaGanadora($juego, $taquilla, 10, 0);

        $response = $this->pagar($apuesta, $user);

        $response->assertStatus(201);
        // lotto-activo base 30x: 10 Bs -> 300 Bs (premio del motor, sin montos en el request)
        $this->assertEquals(300.0, $response->json('premio.premio_bs'));
        $this->assertEquals(0.0, $response->json('premio.premio_usd'));

        $this->assertDatabaseHas('pagos', [
            'apuesta_id' => $apuesta->id,
            'tipo' => 'egreso',
            'amount_bs' => 300,
        ]);
        $this->assertSame('pagada', $apuesta->fresh()->estado);
    }

    public function test_egreso_sin_montos_en_usd_usa_el_premio_del_motor()
    {
        [$juego, $taquilla, $user] = $this->crearTaquillaUsuario();
        $apuesta = $this->crearApuestaGanadora($juego, $taquilla, 0, 5);

        $response = $this->pagar($apuesta, $user, ['moneda' => 'usd']);

        $response->assertStatus(201);
        // 5 USD x 30 = 150 USD
        $this->assertEquals(150.0, $response->json('premio.premio_usd'));
        $this->assertDatabaseHas('pagos', [
            'apuesta_id' => $apuesta->id,
            'tipo' => 'egreso',
            'amount_usd' => 150,
        ]);
    }

    public function test_egreso_con_montos_correctos_paga()
    {
        [$juego, $taquilla, $user] = $this->crearTaquillaUsuario();
        $apuesta = $this->crearApuestaGanadora($juego, $taquilla, 10, 0);

        $response = $this->pagar($apuesta, $user, ['amount_bs' => 300, 'amount_usd' => 0]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('pagos', [
            'apuesta_id' => $apuesta->id,
            'tipo' => 'egreso',
            'amount_bs' => 300,
        ]);
    }

    public function test_egreso_con_monto_apostado_rechaza_422_con_el_premio_esperado()
    {
        [$juego, $taquilla, $user] = $this->crearTaquillaUsuario();
        $apuesta = $this->crearApuestaGanadora($juego, $taquilla, 10, 0);

        // Payload viejo de la taquilla: manda el monto apostado (10) en vez del premio (300).
        $response = $this->pagar($apuesta, $user, ['amount_bs' => 10, 'amount_usd' => 0]);

        $response->assertStatus(422);
        $this->assertEquals(300.0, $response->json('premio_esperado_bs'));

        $this->assertSame('pendiente', $apuesta->fresh()->estado);
        $this->assertDatabaseMissing('pagos', ['apuesta_id' => $apuesta->id]);
    }
}
