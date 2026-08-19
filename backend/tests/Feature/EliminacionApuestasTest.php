<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ApuestaService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PR 2 — eliminacion-apuestas.
 *
 * Ventana de eliminación configurable por agencia (tiempo_eliminacion,
 * default 5 minutos): ApuestaPolicy y TicketController leen el valor
 * efectivo de la agencia.
 */
class EliminacionApuestasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);
    }

    private function superUser(): User
    {
        $super = User::where('email', 'super@lotto.com')->first();
        $super->assignRole('super_master');

        return $super;
    }

    /**
     * Crea una agencia con su jerarquía y un ticket pendiente cuya apuesta
     * tiene created_at desplazado N minutos al pasado.
     */
    private function crearTicketConApuesta(?int $tiempoEliminacion, int $antiguedadMinutos): Ticket
    {
        $banca = Banca::create(['name' => 'Banca Elim', 'code' => 'BELIM', 'active' => true]);
        $grupo = Grupo::create(['name' => 'Grupo Elim', 'code' => 'GELIM', 'banca_id' => $banca->id, 'active' => true]);

        $taquillaData = [
            'name' => 'Agencia Elim',
            'code' => 'TELIM',
            'grupo_id' => $grupo->id,
            'active' => true,
        ];
        if ($tiempoEliminacion !== null) {
            $taquillaData['tiempo_eliminacion'] = $tiempoEliminacion;
        }
        $taquilla = Taquilla::create($taquillaData);

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $ticket = Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 1000,
            'total_usd' => 0,
            'estado' => 'pendiente',
        ]);

        $apuesta = Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'ticket_id' => $ticket->id,
            'amount_bs' => 1000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 1000,
            'estado' => 'pendiente',
        ]);
        $apuesta->created_at = now()->subMinutes($antiguedadMinutos);
        $apuesta->save();

        return $ticket;
    }

    public function test_tiempo_eliminacion_default_cinco_minutos()
    {
        $grupo = Grupo::factory()->create();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', [
                'name' => 'Agencia Default',
                'code' => 'TDFLT',
                'grupo_id' => $grupo->id,
                'user_name' => 'Usuario Default',
                'user_email' => 'default-elim@lotto.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(201);
        // NULL = hereda; el valor efectivo es el default del sistema (5)
        $this->assertDatabaseHas('taquillas', [
            'code' => 'TDFLT',
            'tiempo_eliminacion' => null,
        ]);

        $taquilla = Taquilla::where('code', 'TDFLT')->first();
        $efectivo = app(ApuestaService::class)
            ->getEffectiveTiempoEliminacion($taquilla->id);
        $this->assertEquals(5, $efectivo);
    }

    public function test_tiempo_hereda_de_grupo_cuando_taquilla_no_configurada()
    {
        $banca = Banca::create(['name' => 'Banca T', 'code' => 'BCT', 'active' => true, 'tiempo_eliminacion' => 15]);
        $grupo = Grupo::create(['name' => 'Grupo T', 'code' => 'GCT', 'banca_id' => $banca->id, 'active' => true, 'tiempo_eliminacion' => 10]);
        $taquilla = Taquilla::create(['name' => 'Agencia T', 'code' => 'TCT', 'grupo_id' => $grupo->id, 'active' => true]);

        $service = new ApuestaService;
        $this->assertEquals(10, $service->getEffectiveTiempoEliminacion($taquilla->id));
    }

    public function test_tiempo_hereda_de_banca_cuando_grupo_y_taquilla_no_configurados()
    {
        $banca = Banca::create(['name' => 'Banca B', 'code' => 'BCB', 'active' => true, 'tiempo_eliminacion' => 15]);
        $grupo = Grupo::create(['name' => 'Grupo B', 'code' => 'GCB', 'banca_id' => $banca->id, 'active' => true]);
        $taquilla = Taquilla::create(['name' => 'Agencia B', 'code' => 'TCB', 'grupo_id' => $grupo->id, 'active' => true]);

        $service = new ApuestaService;
        $this->assertEquals(15, $service->getEffectiveTiempoEliminacion($taquilla->id));
    }

    public function test_grupo_no_puede_exceder_tiempo_de_la_banca()
    {
        $banca = Banca::create(['name' => 'Banca R', 'code' => 'BCR', 'active' => true, 'tiempo_eliminacion' => 10]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/grupos', [
                'name' => 'Grupo Exceso',
                'code' => 'GEXC',
                'banca_id' => $banca->id,
                'tiempo_eliminacion' => 15,
                'user_name' => 'Usuario Grupo',
                'user_email' => 'grupo-exceso@lotto.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('no puede ser mayor', $response->json('message'));
    }

    public function test_taquilla_no_puede_exceder_tiempo_del_grupo()
    {
        $banca = Banca::create(['name' => 'Banca Q', 'code' => 'BCQ', 'active' => true, 'tiempo_eliminacion' => 20]);
        $grupo = Grupo::create(['name' => 'Grupo Q', 'code' => 'GCQ', 'banca_id' => $banca->id, 'active' => true, 'tiempo_eliminacion' => 10]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', [
                'name' => 'Agencia Exceso',
                'code' => 'TEXC',
                'grupo_id' => $grupo->id,
                'tiempo_eliminacion' => 15,
                'user_name' => 'Usuario Agencia',
                'user_email' => 'agencia-exceso@lotto.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('no puede ser mayor', $response->json('message'));
    }

    public function test_apuesta_dentro_ventana_se_puede_eliminar()
    {
        $ticket = $this->crearTicketConApuesta(null, 3);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Ticket anulado correctamente.');

        $this->assertSoftDeleted('apuestas', ['ticket_id' => $ticket->id]);
    }

    public function test_apuesta_fuera_ventana_no_se_puede_eliminar()
    {
        $ticket = $this->crearTicketConApuesta(null, 8);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'El ticket excedió los 5 minutos para ser anulado.');

        $this->assertDatabaseHas('apuestas', ['ticket_id' => $ticket->id]);
    }

    public function test_ventana_configurable_respetada()
    {
        // Agencia con ventana de 10 minutos permite eliminar una apuesta de 7
        $ticket = $this->crearTicketConApuesta(10, 7);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id);

        $response->assertStatus(200);
        $this->assertSoftDeleted('apuestas', ['ticket_id' => $ticket->id]);
    }

    public function test_mensaje_ticket_dinamico()
    {
        // Agencia con ventana de 10 minutos; apuesta de 12 → mensaje dinámico
        $ticket = $this->crearTicketConApuesta(10, 12);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id);

        $response->assertStatus(422);
        $this->assertStringContainsString('10 minutos', $response->json('message'));
    }
}
