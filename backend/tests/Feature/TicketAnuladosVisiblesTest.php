<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Juego;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * fix/taquilla-fixes — los tickets anulados siguen VISIBLES en el historial.
 *
 * `TicketController::destroy` soft-deletea las apuestas + el ticket y marca
 * `estado='anulada'`, pero `index` debe seguir listándolos (con su estado y
 * sin apuestas activas) y el filtro `?estado=anulada` debe devolverlos.
 * Un anulado NUNCA es pagable: sus apuestas quedan trashed (404 en /pagos)
 * y `tiene_ganadores=false`.
 */
class TicketAnuladosVisiblesTest extends TestCase
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

    private function ticketConApuesta(Taquilla $taquilla): Ticket
    {
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

    public function test_index_incluye_ticket_anulado_con_estado_y_sin_apuestas_activas(): void
    {
        $taquilla = Taquilla::factory()->create();
        $ticket = $this->ticketConApuesta($taquilla);
        $user = $this->taquillaUser($taquilla);

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Device-MAC', $taquilla->mac_address)
            ->deleteJson('/api/v1/tickets/'.$ticket->id)
            ->assertOk();

        $this->assertSoftDeleted('tickets', ['id' => $ticket->id]);

        $resp = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Device-MAC', $taquilla->mac_address)
            ->getJson('/api/v1/tickets');

        $resp->assertOk();
        $item = collect($resp->json('data.data'))->firstWhere('id', $ticket->id);
        $this->assertNotNull($item, 'el ticket anulado NO desaparece del historial');
        $this->assertSame('anulada', $item['estado']);
        $this->assertSame([], $item['apuestas'], 'las apuestas trashed no ensucian el shape');
        $this->assertSame(0, $item['ganadoras_count']);
        $this->assertFalse($item['tiene_ganadores'], 'un anulado nunca figura con premios');
    }

    public function test_filtro_estado_anulada_devuelve_solo_anulados(): void
    {
        $taquilla = Taquilla::factory()->create();
        $anulado = $this->ticketConApuesta($taquilla);
        $vivo = $this->ticketConApuesta($taquilla);
        $user = $this->taquillaUser($taquilla);

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Device-MAC', $taquilla->mac_address)
            ->deleteJson('/api/v1/tickets/'.$anulado->id)
            ->assertOk();

        $soloAnulados = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Device-MAC', $taquilla->mac_address)
            ->getJson('/api/v1/tickets?estado=anulada');
        $soloAnulados->assertOk();
        $ids = collect($soloAnulados->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($anulado->id), '?estado=anulada devuelve el anulado');
        $this->assertFalse($ids->contains($vivo->id), '?estado=anulada no mezcla tickets vivos');

        $soloPendientes = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Device-MAC', $taquilla->mac_address)
            ->getJson('/api/v1/tickets?estado=pendiente');
        $soloPendientes->assertOk();
        $idsPendientes = collect($soloPendientes->json('data.data'))->pluck('id');
        $this->assertTrue($idsPendientes->contains($vivo->id));
        $this->assertFalse($idsPendientes->contains($anulado->id), '?estado=pendiente excluye anulados');
    }

    public function test_ticket_anulado_no_es_pagable(): void
    {
        $taquilla = Taquilla::factory()->create();
        $ticket = $this->ticketConApuesta($taquilla);
        $user = $this->taquillaUser($taquilla);
        $apuestaId = $ticket->apuestas()->first()->id;

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Device-MAC', $taquilla->mac_address)
            ->deleteJson('/api/v1/tickets/'.$ticket->id)
            ->assertOk();

        // La apuesta anulada quedó trashed: el egreso responde 404 (no pagable).
        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Device-MAC', $taquilla->mac_address)
            ->postJson('/api/v1/pagos', [
                'apuesta_id' => $apuestaId,
                'tipo' => 'egreso',
                'moneda' => 'bs',
            ])
            ->assertNotFound();

        $this->assertSoftDeleted('apuestas', ['ticket_id' => $ticket->id]);
    }
}
