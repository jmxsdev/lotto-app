<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\ExchangeRate;
use App\Models\Juego;
use App\Models\Taquilla;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApuestaSorteoPasadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);
    }

    private function crearTaquillaConUsuario(): array
    {
        $taquilla = Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        return [$taquilla, $taquillaUser];
    }

    private function crearTasaActiva(): void
    {
        $user = User::where('email', 'super@lotto.com')->first();

        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);
    }

    // REQ-BK-01 (D3): un sorteo ya pasado debe RECHAZARSE con 422 y no saltar
    // al siguiente sorteo futuro. La transacción de POST /tickets debe
    // revertir todo: 0 apuestas y 0 tickets.
    public function test_rechaza_sorteo_pasado_en_ticket_y_no_crea_nada(): void
    {
        $this->crearTasaActiva();

        $juego = Juego::where('slug', 'lotto-activo')->first();
        [, $taquillaUser] = $this->crearTaquillaConUsuario();

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/v1/tickets', [
                'lines' => [
                    [
                        'juego_id' => $juego->id,
                        'combinacion' => ['animal' => 'perro', 'numero' => 5],
                        'amount_bs' => 1800,
                        'amount_usd' => 50,
                        'sorteo_hora' => now()->subHours(2)->format('Y-m-d H:i:s'),
                    ],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'El sorteo seleccionado ya pasó. Seleccione un horario futuro.');

        $this->assertDatabaseCount('apuestas', 0);
        $this->assertDatabaseCount('tickets', 0);
    }

    // Triangulación: un sorteo FUTURO sigue creándose (rama isPast() falsa).
    public function test_acepta_sorteo_futuro_en_ticket(): void
    {
        $this->crearTasaActiva();

        $juego = Juego::where('slug', 'lotto-activo')->first();
        [$taquilla, $taquillaUser] = $this->crearTaquillaConUsuario();

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/v1/tickets', [
                'lines' => [
                    [
                        'juego_id' => $juego->id,
                        'combinacion' => ['animal' => 'perro', 'numero' => 5],
                        'amount_bs' => 1800,
                        'amount_usd' => 50,
                        'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Ticket creado exitosamente.');

        $this->assertDatabaseCount('apuestas', 1);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseHas('apuestas', [
            'taquilla_id' => $taquilla->id,
            'total_bs_equivalent' => 3625.00,
        ]);
    }

    // Triangulación: sin sorteo_hora, getNextDrawTime sigue aplicando
    // (el salto solo queda para el caso ausente, D3).
    public function test_acepta_ticket_sin_sorteo_hora_usa_siguiente_sorteo(): void
    {
        $this->crearTasaActiva();

        $juego = Juego::where('slug', 'lotto-activo')->first();
        [$taquilla, $taquillaUser] = $this->crearTaquillaConUsuario();

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/v1/tickets', [
                'lines' => [
                    [
                        'juego_id' => $juego->id,
                        'combinacion' => ['animal' => 'perro', 'numero' => 5],
                        'amount_bs' => 1800,
                        'amount_usd' => 50,
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Ticket creado exitosamente.');

        $this->assertDatabaseCount('apuestas', 1);
        $this->assertDatabaseCount('tickets', 1);

        $apuesta = Apuesta::where('taquilla_id', $taquilla->id)->first();
        $this->assertNotNull($apuesta);
        $this->assertTrue(
            Carbon::parse($apuesta->sorteo_hora)->isFuture(),
            'Sin sorteo_hora el servicio debe asignar el siguiente sorteo futuro.'
        );
    }
}
