<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\AuditEvent;
use App\Models\DetalleApuesta;
use App\Models\Juego;
use App\Models\Pago;
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
 * El tipo `devolucion` ya no se acepta en este endpoint: la devolucion se
 * registra anulando el ticket (D8/D9). Se mantiene el valor en el enum de
 * validacion para poder devolver el 422 con el mensaje del flujo correcto.
 */
class PagoTipoTest extends TestCase
{
    use RefreshDatabase;

    private const MENSAJE_DEVOLUCION = 'Las devoluciones se registran anulando el ticket: use DELETE /api/v1/tickets/{id}.';

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

    private function fixturePendiente(): array
    {
        $juego = Juego::where('slug', 'lotto-activo')->firstOrFail();
        $taquilla = Taquilla::factory()->create();

        $ticket = Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 1000,
            'total_usd' => 0,
            'estado' => 'pendiente',
        ]);

        $apuesta = Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'ticket_id' => $ticket->id,
            'juego_id' => $juego->id,
            'combinacion' => json_encode(['animal' => 'perro', 'numero' => 5]),
            'amount_bs' => 1000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 1000,
            'estado' => 'pendiente',
            'fecha_hora' => now(),
            'sorteo_hora' => now()->addHour(),
        ]);

        $detalle = DetalleApuesta::create([
            'apuesta_id' => $apuesta->id,
            'combinacion' => json_encode(['animal' => 'perro', 'numero' => 5]),
            'monto' => 1000,
            'premio_ganado' => null,
            'premio_ganado_usd' => null,
        ]);

        return [$apuesta, $detalle, $ticket];
    }

    public function test_devolucion_por_pagos_rechazada_sin_escrituras(): void
    {
        [$apuesta, $detalle, $ticket] = $this->fixturePendiente();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/pagos', [
                'apuesta_id' => $apuesta->id,
                'tipo' => 'devolucion',
                'moneda' => 'bs',
                'amount_bs' => 1000,
                'amount_usd' => 0,
                'concepto' => 'Devolución de dinero',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', self::MENSAJE_DEVOLUCION);

        $this->assertDatabaseMissing('pagos', ['apuesta_id' => $apuesta->id]);
        $this->assertSame(0, Pago::count());
        $this->assertSame('pendiente', $apuesta->fresh()->estado);
        $this->assertNull($detalle->fresh()->premio_ganado);
        $this->assertSame('pendiente', $ticket->fresh()->estado);
        $this->assertSame(0, AuditEvent::where('action', 'pago.prize_paid')->count());
        $this->assertNotSoftDeleted('apuestas', ['id' => $apuesta->id]);
        $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
    }

    public function test_tipo_fuera_del_enum_sigue_fallando_en_validacion(): void
    {
        [$apuesta] = $this->fixturePendiente();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/pagos', [
                'apuesta_id' => $apuesta->id,
                'tipo' => 'otro',
                'moneda' => 'bs',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('tipo');

        $this->assertSame(0, Pago::count());
    }
}
