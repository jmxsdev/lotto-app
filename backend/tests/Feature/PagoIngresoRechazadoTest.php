<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\DetalleApuesta;
use App\Models\Juego;
use App\Models\Pago;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WU2 — `POST /api/v1/pagos` es egreso-only.
 *
 * Un `ingreso` se registra automaticamente al vender (`ApuestaService`), por lo
 * que el endpoint lo rechaza con 422 ANTES de cargar la apuesta: cero `Pago`,
 * cero cambio de estado/premio y cero cascada al ticket.
 */
class PagoIngresoRechazadoTest extends TestCase
{
    use RefreshDatabase;

    private const MENSAJE_INGRESO = 'El ingreso se registra automáticamente al vender; no se admite por este endpoint.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);
    }

    private function superUser(): User
    {
        return User::where('email', 'super@lotto.com')->firstOrFail();
    }

    private function juego(): Juego
    {
        return Juego::where('slug', 'lotto-activo')->firstOrFail();
    }

    private function ticket(Taquilla $taquilla): Ticket
    {
        return Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 10,
            'total_usd' => 0,
            'estado' => 'pendiente',
        ]);
    }

    private function apuesta(Juego $juego, Taquilla $taquilla, ?Ticket $ticket, array $extra = []): Apuesta
    {
        return Apuesta::create(array_merge([
            'taquilla_id' => $taquilla->id,
            'ticket_id' => $ticket?->id,
            'juego_id' => $juego->id,
            'combinacion' => json_encode(['animal' => 'perro', 'numero' => 5]),
            'amount_bs' => 10,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 10,
            'estado' => 'pendiente',
            'fecha_hora' => now(),
            'sorteo_hora' => now()->addHour(),
        ], $extra));
    }

    private function detalle(Apuesta $apuesta, ?float $premioBs = null, ?float $premioUsd = null): DetalleApuesta
    {
        return DetalleApuesta::create([
            'apuesta_id' => $apuesta->id,
            'combinacion' => json_encode(['animal' => 'perro', 'numero' => 5]),
            'monto' => 10,
            'premio_ganado' => $premioBs,
            'premio_ganado_usd' => $premioUsd,
        ]);
    }

    private function intentarIngreso(Apuesta $apuesta, array $extra = [])
    {
        return $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/pagos', array_merge([
                'apuesta_id' => $apuesta->id,
                'tipo' => 'ingreso',
                'moneda' => 'bs',
            ], $extra));
    }

    public function test_ingreso_sobre_apuesta_pendiente_rechazado_sin_escrituras(): void
    {
        $taquilla = Taquilla::factory()->create();
        $ticket = $this->ticket($taquilla);
        $apuesta = $this->apuesta($this->juego(), $taquilla, $ticket);
        $detalle = $this->detalle($apuesta);

        $response = $this->intentarIngreso($apuesta, ['amount_bs' => 10, 'amount_usd' => 0]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', self::MENSAJE_INGRESO);

        $this->assertSame(0, Pago::where('apuesta_id', $apuesta->id)->count(), 'Un ingreso rechazado no crea pagos.');
        $this->assertSame('pendiente', $apuesta->fresh()->estado);
        $this->assertNull($detalle->fresh()->premio_ganado);
        $this->assertSame('pendiente', $ticket->fresh()->estado, 'Sin cascada al ticket.');
        $this->assertDatabaseMissing('logs', ['action' => 'pago_premio']);
    }

    public function test_ingreso_sobre_apuesta_ganadora_rechazado_preserva_premio_y_ticket(): void
    {
        $taquilla = Taquilla::factory()->create();
        $ticket = $this->ticket($taquilla);
        $juego = $this->juego();

        $resultado = Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => now(),
            'numeros_ganadores' => ['nombre_animal' => 'Perro', 'numero' => 5],
        ]);

        $apuesta = $this->apuesta($juego, $taquilla, $ticket, [
            'estado' => 'ganadora',
            'resultado_id' => $resultado->id,
        ]);
        $detalle = $this->detalle($apuesta, 300.00, 0.00);

        $response = $this->intentarIngreso($apuesta, ['amount_bs' => 0, 'amount_usd' => 0]);

        $response->assertStatus(422)
            ->assertJsonPath('message', self::MENSAJE_INGRESO);

        $this->assertSame(0, Pago::where('apuesta_id', $apuesta->id)->count());
        $this->assertSame('ganadora', $apuesta->fresh()->estado);
        $this->assertEquals(300.00, (float) $detalle->fresh()->premio_ganado, 'La evidencia del premio queda intacta.');
        $this->assertSame('pendiente', $ticket->fresh()->estado);
    }

    public function test_ingreso_inexistente_falla_primero_en_validacion(): void
    {
        // El orden del contrato (design §7.4): la validacion de input corre
        // antes de la rama de tipo, asi que un payload malformado sigue
        // devolviendo el 422 generico de validacion.
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/pagos', [
                'apuesta_id' => 999999,
                'tipo' => 'ingreso',
                'moneda' => 'bs',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('apuesta_id');

        $this->assertSame(0, Pago::count());
    }
}
