<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Juego;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ApuestaService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S2 taquilla-operativa — `tiempo_eliminacion_efectivo` en GET /tickets y
 * GET /tickets/{id} (spec taquilla-anulacion §1).
 *
 * El front anula con la ventana EFECTIVA configurada (taquilla → grupo →
 * banca → 5), nunca con un 5 hardcodeado. El controller debe exponer el
 * valor de `ApuestaService::getEffectiveTiempoEliminacion` en ambas rutas.
 */
class TicketEliminacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function taquillaUser(Taquilla $taquilla): User
    {
        $user = User::factory()->forAgencia($taquilla)->create();
        $user->assignRole('taquilla');

        return $user;
    }

    private function ticketDe(Taquilla $taquilla, ?int $ventana): Ticket
    {
        if ($ventana !== null) {
            $taquilla->update(['tiempo_eliminacion' => $ventana]);
        }
        $ticket = Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 10,
            'total_usd' => 0,
            'estado' => 'pendiente',
        ]);
        Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => Juego::where('slug', 'lotto-activo')->first()->id,
            'combinacion' => json_encode(['animal' => 'Perro', 'numero' => 14]),
            'amount_bs' => 10,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 10,
            'estado' => 'pendiente',
            'fecha_hora' => now(),
            'sorteo_hora' => now()->addHour(),
            'ticket_id' => $ticket->id,
            'ticket_code' => $ticket->ticket_code,
        ]);

        return $ticket;
    }

    public function test_index_incluye_tiempo_eliminacion_efectivo(): void
    {
        $taquilla = Taquilla::factory()->create();
        $ticket = $this->ticketDe($taquilla, 10);
        $user = $this->taquillaUser($taquilla);

        $resp = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Device-MAC', $taquilla->mac_address)
            ->getJson('/api/v1/tickets');

        $resp->assertOk();
        $items = $resp->json('data.data');
        $item = collect($items)->firstWhere('id', $ticket->id);
        $this->assertNotNull($item, 'el ticket aparece en la colección');
        $this->assertSame(
            $taquilla->tiempo_eliminacion,
            $item['tiempo_eliminacion_efectivo'],
            'index expone la ventana efectiva configurada (10), no un 5 fijo'
        );
    }

    public function test_show_incluye_tiempo_eliminacion_efectivo(): void
    {
        $taquilla = Taquilla::factory()->create();
        $ticket = $this->ticketDe($taquilla, 10);
        $user = $this->taquillaUser($taquilla);

        $resp = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Device-MAC', $taquilla->mac_address)
            ->getJson('/api/v1/tickets/'.$ticket->id);

        $resp->assertOk();
        $this->assertSame(
            10,
            $resp->json('data.tiempo_eliminacion_efectivo'),
            'show expone la ventana efectiva configurada'
        );
    }

    public function test_default_5_cuando_nadie_configura_ventana(): void
    {
        $taquilla = Taquilla::factory()->create();
        $ticket = $this->ticketDe($taquilla, null);
        $user = $this->taquillaUser($taquilla);

        $resp = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Device-MAC', $taquilla->mac_address)
            ->getJson('/api/v1/tickets/'.$ticket->id);

        $resp->assertOk();
        $this->assertSame(
            app(ApuestaService::class)->getEffectiveTiempoEliminacion($taquilla->id),
            $resp->json('data.tiempo_eliminacion_efectivo'),
            'default de la cascada (5) sin configuración'
        );
    }
}
