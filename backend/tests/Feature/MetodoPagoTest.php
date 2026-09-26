<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\ExchangeRate;
use App\Models\Juego;
use App\Models\Pago;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PR 2 — captura de metodo_pago en venta (ingreso) y premio (egreso/devolucion).
 *
 * Contrato AD-10 (aditivo): omitir metodo_pago mantiene el default 'efectivo';
 * moneda USD (o componente USD) fuerza 'efectivo'; valores fuera del enum
 * responden 422. Un pago mixto lleva un único metodo_pago para todo el pago.
 */
class MetodoPagoTest extends TestCase
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

    private function postApuesta(array $payload, User $user): TestResponse
    {
        return $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($user, 'sanctum')
            ->postJson('/api/v1/apuestas', $payload);
    }

    private function payloadApuesta(int $juegoId, float $bs, float $usd, array $extra = []): array
    {
        return array_merge([
            'juego_id' => $juegoId,
            'combinacion' => ['animal' => 'perro', 'numero' => 5],
            'amount_bs' => $bs,
            'amount_usd' => $usd,
            'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
        ], $extra);
    }

    public function test_venta_ves_con_transferencia_persiste_en_el_pago_ingreso()
    {
        [$juego, $taquilla, $user] = $this->crearTaquillaUsuario();

        $response = $this->postApuesta(
            $this->payloadApuesta($juego->id, 1800, 0, ['metodo_pago' => 'transferencia']),
            $user
        );

        $response->assertStatus(201);

        $this->assertDatabaseHas('pagos', [
            'taquilla_id' => $taquilla->id,
            'tipo' => 'ingreso',
            'metodo_pago' => 'transferencia',
        ]);
    }

    public function test_venta_usd_fuerza_metodo_efectivo()
    {
        [$juego, $taquilla, $user] = $this->crearTaquillaUsuario();

        $response = $this->postApuesta(
            $this->payloadApuesta($juego->id, 0, 50, ['metodo_pago' => 'pago_movil']),
            $user
        );

        $response->assertStatus(201);

        $this->assertDatabaseHas('pagos', [
            'taquilla_id' => $taquilla->id,
            'tipo' => 'ingreso',
            'metodo_pago' => 'efectivo',
        ]);
    }

    public function test_venta_sin_metodo_pago_usa_default_efectivo()
    {
        [$juego, $taquilla, $user] = $this->crearTaquillaUsuario();

        $response = $this->postApuesta(
            $this->payloadApuesta($juego->id, 1800, 0),
            $user
        );

        $response->assertStatus(201);

        $this->assertDatabaseHas('pagos', [
            'taquilla_id' => $taquilla->id,
            'tipo' => 'ingreso',
            'metodo_pago' => 'efectivo',
        ]);
    }

    public function test_venta_metodo_pago_invalido_responde_422()
    {
        [$juego, $taquilla, $user] = $this->crearTaquillaUsuario();

        $response = $this->postApuesta(
            $this->payloadApuesta($juego->id, 1800, 0, ['metodo_pago' => 'tarjeta']),
            $user
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('metodo_pago');

        $this->assertDatabaseMissing('pagos', [
            'taquilla_id' => $taquilla->id,
        ]);
    }

    public function test_ticket_metodo_pago_aplica_a_cada_ingreso_generado()
    {
        [$juego, $taquilla, $user] = $this->crearTaquillaUsuario();

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($user, 'sanctum')
            ->postJson('/api/v1/tickets', [
                'metodo_pago' => 'pago_movil',
                'lines' => [
                    ['juego_id' => $juego->id, 'combinacion' => ['animal' => 'perro', 'numero' => 5], 'amount_bs' => 1800, 'amount_usd' => 0],
                    ['juego_id' => $juego->id, 'combinacion' => ['animal' => 'gato', 'numero' => 12], 'amount_bs' => 1800, 'amount_usd' => 0],
                ],
            ]);

        $response->assertStatus(201);

        $this->assertSame(
            2,
            Pago::where('taquilla_id', $taquilla->id)
                ->where('tipo', 'ingreso')
                ->where('metodo_pago', 'pago_movil')
                ->count()
        );
    }

    public function test_premio_ves_con_pago_movil_persiste_en_el_pago_egreso()
    {
        [$juego, $taquilla, $user] = $this->crearTaquillaUsuario();

        $apuesta = $this->crearApuestaGanadora($juego, $taquilla, 100, 0);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($user, 'sanctum')
            ->postJson('/api/v1/pagos', [
                'apuesta_id' => $apuesta->id,
                'tipo' => 'egreso',
                'moneda' => 'bs',
                'amount_bs' => 3000,
                'amount_usd' => 0,
                'metodo_pago' => 'pago_movil',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('pagos', [
            'apuesta_id' => $apuesta->id,
            'tipo' => 'egreso',
            'metodo_pago' => 'pago_movil',
        ]);
    }

    public function test_premio_usd_fuerza_metodo_efectivo()
    {
        [$juego, $taquilla, $user] = $this->crearTaquillaUsuario();

        $apuesta = $this->crearApuestaGanadora($juego, $taquilla, 0, 5);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($user, 'sanctum')
            ->postJson('/api/v1/pagos', [
                'apuesta_id' => $apuesta->id,
                'tipo' => 'egreso',
                'moneda' => 'usd',
                'amount_bs' => 0,
                'amount_usd' => 150,
                'metodo_pago' => 'transferencia',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('pagos', [
            'apuesta_id' => $apuesta->id,
            'tipo' => 'egreso',
            'metodo_pago' => 'efectivo',
        ]);
    }

    public function test_pago_mixto_lleva_un_unico_metodo()
    {
        [$juego, $taquilla, $user] = $this->crearTaquillaUsuario();

        $apuesta = $this->crearApuestaGanadora($juego, $taquilla, 100, 5);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($user, 'sanctum')
            ->postJson('/api/v1/pagos', [
                'apuesta_id' => $apuesta->id,
                'tipo' => 'egreso',
                'moneda' => 'mixto',
                'amount_bs' => 3000,
                'amount_usd' => 150,
                'metodo_pago' => 'punto_venta',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('pagos', [
            'apuesta_id' => $apuesta->id,
            'tipo' => 'egreso',
            'metodo_pago' => 'efectivo',
        ]);

        $this->assertSame(
            1,
            Pago::where('apuesta_id', $apuesta->id)->where('tipo', 'egreso')->count()
        );
    }

    public function test_premio_metodo_pago_invalido_responde_422()
    {
        [$juego, $taquilla, $user] = $this->crearTaquillaUsuario();

        $apuesta = $this->crearApuestaGanadora($juego, $taquilla, 100, 0);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($user, 'sanctum')
            ->postJson('/api/v1/pagos', [
                'apuesta_id' => $apuesta->id,
                'tipo' => 'egreso',
                'moneda' => 'bs',
                'amount_bs' => 3000,
                'amount_usd' => 0,
                'metodo_pago' => 'tarjeta',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('metodo_pago');
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
}
