<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Log;
use App\Models\Pago;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WU-3 — regresión del caso 5 (anulación de ticket).
 *
 * `TicketAnulacionService` consulta las líneas vivas con el scope de
 * SoftDeletes. Un ticket cuyas líneas fueron soft-deleted (o que nunca tuvo
 * líneas vivas) debe rechazarse con 422 sin registrar devolución ni mutar
 * el ticket. Sin cambios de producción: solo se pinea el comportamiento.
 */
class TicketAnulacionRegresionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function superUser(): User
    {
        $user = User::where('email', 'super@lotto.com')->firstOrFail();
        $user->assignRole('super_master');

        return $user;
    }

    private function juego(): Juego
    {
        return Juego::where('slug', 'lotto-activo')->firstOrFail();
    }

    private function crearTaquilla(): Taquilla
    {
        $banca = Banca::factory()->create(['active' => true]);
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);

        return Taquilla::factory()->create(['grupo_id' => $grupo->id, 'active' => true]);
    }

    /**
     * Crea una línea (apuesta + venta) del ticket y la soft-deletea.
     */
    private function crearLineaSoftDeleted(
        Ticket $ticket,
        Taquilla $taquilla,
        Juego $juego,
        User $creador,
        float $montoBs
    ): Apuesta {
        $apuesta = Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'ticket_id' => $ticket->id,
            'combinacion' => ['animal' => 'perro', 'numero' => 5],
            'amount_bs' => $montoBs,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.5000,
            'total_bs_equivalent' => $montoBs,
            'estado' => 'pendiente',
            'fecha_hora' => now(),
            'sorteo_hora' => now()->addHour(),
        ]);

        Pago::create([
            'taquilla_id' => $taquilla->id,
            'apuesta_id' => $apuesta->id,
            'amount_bs' => $montoBs,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.5000,
            'tipo' => 'ingreso',
            'moneda' => 'bs',
            'metodo_pago' => 'efectivo',
            'concepto' => 'Compra de ticket',
            'created_by' => $creador->id,
        ]);

        $apuesta->delete();

        return $apuesta;
    }

    public function test_ticket_con_todas_sus_lineas_soft_deleted_devuelve_422_sin_devolucion(): void
    {
        $user = $this->superUser();
        $taquilla = $this->crearTaquilla();
        $juego = $this->juego();

        $ticket = Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 1500,
            'total_usd' => 0,
            'estado' => 'pendiente',
        ]);

        $apuestas = [
            $this->crearLineaSoftDeleted($ticket, $taquilla, $juego, $user, 1000),
            $this->crearLineaSoftDeleted($ticket, $taquilla, $juego, $user, 500),
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'El ticket no tiene jugadas activas para anular.');

        // Sin devolución ni log: no se escribió nada.
        $this->assertSame(0, Pago::where('tipo', 'devolucion')->count());
        $this->assertSame(0, Log::count());

        // El ticket no se mutó: sigue vivo y pendiente, con sus totales.
        $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
        $ticket->refresh();
        $this->assertSame('pendiente', $ticket->estado);
        $this->assertSame('1500.00', $ticket->total_bs);
        $this->assertSame('0.00', $ticket->total_usd);

        // Las líneas siguen soft-deleted y con su estado original.
        foreach ($apuestas as $apuesta) {
            $this->assertSoftDeleted('apuestas', ['id' => $apuesta->id]);
            $this->assertSame(
                'pendiente',
                Apuesta::withTrashed()->findOrFail($apuesta->id)->estado
            );
        }
    }
}
