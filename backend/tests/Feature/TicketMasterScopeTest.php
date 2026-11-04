<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Caso 4 (#84) — alcance master en `TicketController`.
 *
 * `index`, `show` y `ganadores` acotan al master a los tickets/apuestas que
 * cuelgan de sus bancas (`bancas.master_id`). Un master sin bancas ve vacío y
 * un ticket ajeno devuelve 403. super_master conserva alcance global.
 */
class TicketMasterScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);
    }

    private function master(): User
    {
        return User::factory()->withRole('master')->create();
    }

    private function superMaster(): User
    {
        return User::factory()->withRole('super_master')->create();
    }

    /**
     * @return array{0: Banca, 1: Grupo, 2: Taquilla}
     */
    private function jerarquia(?int $masterId = null): array
    {
        $banca = Banca::factory()->create(['master_id' => $masterId]);
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id]);
        $taquilla = Taquilla::factory()->create(['grupo_id' => $grupo->id]);

        return [$banca, $grupo, $taquilla];
    }

    private function ticket(Taquilla $taquilla, string $codigo): Ticket
    {
        return Ticket::create([
            'ticket_code' => $codigo,
            'taquilla_id' => $taquilla->id,
            'total_bs' => 10,
            'total_usd' => 0,
            'estado' => 'pendiente',
        ]);
    }

    /**
     * Apuesta ganadora del sorteo indicado, asociada a un ticket propio.
     */
    private function apuestaGanadora(
        Taquilla $taquilla,
        Ticket $ticket,
        Juego $juego,
        string $fecha
    ): Apuesta {
        return Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'ticket_id' => $ticket->id,
            'juego_id' => $juego->id,
            'combinacion' => ['animal' => 'Delfín', 'numero' => 0],
            'amount_bs' => 10,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 10,
            'estado' => 'pendiente',
            'fecha_hora' => $fecha.' 13:00:00',
            'sorteo_hora' => $fecha.' 13:00:00',
        ]);
    }

    public function test_master_solo_ve_tickets_de_sus_bancas(): void
    {
        $master = $this->master();
        [, , $taquillaPropia] = $this->jerarquia($master->id);
        [, , $taquillaAjena] = $this->jerarquia();

        $ticketPropio = $this->ticket($taquillaPropia, 'MASTER-OWN');
        $ticketAjeno = $this->ticket($taquillaAjena, 'MASTER-FOREIGN');

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/tickets?per_page=100');

        $response->assertOk();

        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($ticketPropio->id, $ids);
        $this->assertNotContains($ticketAjeno->id, $ids);
    }

    public function test_master_sin_bancas_ve_lista_vacia(): void
    {
        $master = $this->master();
        $this->jerarquia();

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/tickets?per_page=100');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.data'));
    }

    public function test_master_consulta_ticket_ajeno_403(): void
    {
        $master = $this->master();
        [, , $taquillaPropia] = $this->jerarquia($master->id);
        [, , $taquillaAjena] = $this->jerarquia();

        $ticketPropio = $this->ticket($taquillaPropia, 'MASTER-OWN');
        $ticketAjeno = $this->ticket($taquillaAjena, 'MASTER-FOREIGN');

        $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/tickets/'.$ticketPropio->id)
            ->assertOk()
            ->assertJsonPath('data.id', $ticketPropio->id);

        $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/tickets/'.$ticketAjeno->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'No autorizado.');
    }

    public function test_super_master_ve_todos_los_tickets(): void
    {
        $super = $this->superMaster();
        [, , $taquillaA] = $this->jerarquia();
        [, , $taquillaB] = $this->jerarquia();

        $ticketA = $this->ticket($taquillaA, 'SUPER-A');
        $ticketB = $this->ticket($taquillaB, 'SUPER-B');

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/tickets?per_page=100');

        $response->assertOk();

        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($ticketA->id, $ids);
        $this->assertContains($ticketB->id, $ids);
    }

    public function test_master_ganadores_solo_sus_bancas(): void
    {
        $master = $this->master();
        $juego = Juego::where('slug', 'lotto-activo')->firstOrFail();
        $fecha = '2026-09-21';

        Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => $fecha,
            'hora_sorteo' => '13:00',
            'numeros_ganadores' => ['numero' => 0, 'nombre_animal' => 'Delfin'],
        ]);

        [, , $taquillaPropia] = $this->jerarquia($master->id);
        [, , $taquillaAjena] = $this->jerarquia();

        $ticketPropio = $this->ticket($taquillaPropia, 'GANA-OWN');
        $ticketAjeno = $this->ticket($taquillaAjena, 'GANA-FOREIGN');

        $this->apuestaGanadora($taquillaPropia, $ticketPropio, $juego, $fecha);
        $this->apuestaGanadora($taquillaAjena, $ticketAjeno, $juego, $fecha);

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/tickets/ganadores?fecha='.$fecha);

        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('GANA-OWN', $data[0]['ticket_code']);
    }
}
