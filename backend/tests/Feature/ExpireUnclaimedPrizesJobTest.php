<?php

namespace Tests\Feature;

use App\Jobs\ExpireUnclaimedPrizesJob;
use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Taquilla;
use App\Models\Ticket;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireUnclaimedPrizesJobTest extends TestCase
{
    use RefreshDatabase;

    private Juego $juego;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);
        $this->juego = Juego::where('slug', 'lotto-activo')->first();
    }

    /**
     * 6.4 — test_job_marca_como_vencido_ticket_ganador_expirado:
     * Ticket con estado='ganador' más antiguo que la vigencia efectiva debe
     * marcarse como vencido junto con todas sus apuestas.
     */
    public function test_job_marca_como_vencido_ticket_ganador_expirado(): void
    {
        // Jerarquía: banca (60) → grupo (30) → taquilla (null=hereda grupo)
        $banca = Banca::create([
            'name' => 'Banca ExpJD', 'code' => 'BEXJD',
            'active' => true, 'vigencia_premios' => 60,
        ]);
        $grupo = Grupo::create([
            'name' => 'Grupo ExpJD', 'code' => 'GEXJD',
            'banca_id' => $banca->id, 'active' => true,
            'vigencia_premios' => 30,
        ]);
        $taquilla = Taquilla::create([
            'name' => 'T-ExpJD', 'code' => 'TEXJD',
            'grupo_id' => $grupo->id, 'active' => true,
            'vigencia_premios' => null, // hereda 30 del grupo
        ]);

        // Ticket ganador con 35 días de antigüedad (35 > 30 = grupo)
        $ticket = Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 1000,
            'total_usd' => 0,
            'estado' => 'ganador',
            'ticket_code' => 'T-EXPIRED-001',
        ]);
        // Forzar created_at a 35 días en el pasado
        \DB::table('tickets')->where('id', $ticket->id)
            ->update(['created_at' => now()->subDays(35)]);

        // Apuesta asociada al ticket (también en estado ganador)
        Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'ticket_id' => $ticket->id,
            'juego_id' => $this->juego->id,
            'amount_bs' => 1000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 1000,
            'estado' => 'pendiente',
            'fecha_hora' => now()->subDays(35),
        ]);

        // Ejecutar el job
        $job = new ExpireUnclaimedPrizesJob;
        $job->handle();

        // Verificar ticket cambiado a vencido
        $ticket->refresh();
        $this->assertEquals('vencido', $ticket->estado,
            'El ticket expirado debe marcarse como vencido.');

        // Verificar que las apuestas del ticket también cambiaron
        $apuestas = Apuesta::where('ticket_id', $ticket->id)->get();
        $this->assertNotEmpty($apuestas);
        foreach ($apuestas as $apuesta) {
            $this->assertEquals('vencido', $apuesta->estado,
                'Todas las apuestas del ticket deben marcarse como vencidas.');
        }
    }

    /**
     * 6.4 — test_job_no_marca_ticket_dentro_de_vigencia:
     * Ticket dentro del período de vigencia debe permanecer en estado ganador.
     */
    public function test_job_no_marca_ticket_dentro_de_vigencia(): void
    {
        $banca = Banca::create([
            'name' => 'Banca DentroJD', 'code' => 'BDJD',
            'active' => true, 'vigencia_premios' => 60,
        ]);
        $grupo = Grupo::create([
            'name' => 'Grupo DentroJD', 'code' => 'GDJD',
            'banca_id' => $banca->id, 'active' => true,
            'vigencia_premios' => 30,
        ]);
        $taquilla = Taquilla::create([
            'name' => 'T-DentroJD', 'code' => 'TDJD',
            'grupo_id' => $grupo->id, 'active' => true,
            'vigencia_premios' => null,
        ]);

        // Ticket con solo 10 días de antigüedad (10 < 30 de grupo)
        $ticket = Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 500,
            'total_usd' => 0,
            'estado' => 'ganador',
            'ticket_code' => 'T-DENTRO-001',
        ]);
        \DB::table('tickets')->where('id', $ticket->id)
            ->update(['created_at' => now()->subDays(10)]);

        Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'ticket_id' => $ticket->id,
            'juego_id' => $this->juego->id,
            'amount_bs' => 500,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 500,
            'estado' => 'pendiente',
            'fecha_hora' => now()->subDays(10),
        ]);

        $job = new ExpireUnclaimedPrizesJob;
        $job->handle();

        $ticket->refresh();
        $this->assertEquals('ganador', $ticket->estado,
            'El ticket dentro de vigencia debe mantenerse como ganador.');
    }

    /**
     * 6.4 — test_job_es_idempotente:
     * Re-ejecutar el job no debe afectar tickets que ya están vencidos.
     * La consulta WHERE estado='ganador' protege contra reprocesamiento.
     */
    public function test_job_es_idempotente(): void
    {
        $banca = Banca::create([
            'name' => 'Banca IdemJD', 'code' => 'BIJD',
            'active' => true, 'vigencia_premios' => 15,
        ]);
        $grupo = Grupo::create([
            'name' => 'Grupo IdemJD', 'code' => 'GIJD',
            'banca_id' => $banca->id, 'active' => true,
            'vigencia_premios' => null, // hereda 15 de banca
        ]);
        $taquilla = Taquilla::create([
            'name' => 'T-IdemJD', 'code' => 'TIJD',
            'grupo_id' => $grupo->id, 'active' => true,
            'vigencia_premios' => null,
        ]);

        // Ticket que expirará en la primera ejecución (20 días > 15 vigencia)
        $ticket = Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 2000,
            'total_usd' => 0,
            'estado' => 'ganador',
            'ticket_code' => 'T-IDEM-001',
        ]);
        \DB::table('tickets')->where('id', $ticket->id)
            ->update(['created_at' => now()->subDays(20)]);

        Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'ticket_id' => $ticket->id,
            'juego_id' => $this->juego->id,
            'amount_bs' => 2000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 2000,
            'estado' => 'pendiente',
            'fecha_hora' => now()->subDays(20),
        ]);

        // Primera ejecución — debe expirarlo
        $job1 = new ExpireUnclaimedPrizesJob;
        $job1->handle();

        $ticket->refresh();
        $this->assertEquals('vencido', $ticket->estado,
            'El ticket expirado debe estar en estado vencido tras la primera ejecución.');

        // Segunda ejecución — no debe modificar nada (WHERE estado='ganador')
        $job2 = new ExpireUnclaimedPrizesJob;
        $job2->handle();

        $ticket->refresh();
        $this->assertEquals('vencido', $ticket->estado,
            'El ticket ya vencido no debe ser afectado por una segunda ejecución (idempotencia).');
    }

    /**
     * 6.4 — test_grupo_sin_vigencia_nunca_expira:
     * vigencia_premios=NULL en todos los niveles jerárquicos significa que
     * los tickets nunca expiran, sin importar su antigüedad.
     */
    public function test_grupo_sin_vigencia_nunca_expira(): void
    {
        $banca = Banca::create([
            'name' => 'Banca NullJD', 'code' => 'BNJD',
            'active' => true, 'vigencia_premios' => null,
        ]);
        $grupo = Grupo::create([
            'name' => 'Grupo NullJD', 'code' => 'GNJD',
            'banca_id' => $banca->id, 'active' => true,
            'vigencia_premios' => null,
        ]);
        $taquilla = Taquilla::create([
            'name' => 'T-NullJD', 'code' => 'TNJD',
            'grupo_id' => $grupo->id, 'active' => true,
            'vigencia_premios' => null,
        ]);

        // Ticket muy antiguo (500 días), pero sin vigencia configurada en ningún nivel
        $ticket = Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 5000,
            'total_usd' => 0,
            'estado' => 'ganador',
            'ticket_code' => 'T-NOEXP-001',
        ]);
        \DB::table('tickets')->where('id', $ticket->id)
            ->update(['created_at' => now()->subDays(500)]);

        Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'ticket_id' => $ticket->id,
            'juego_id' => $this->juego->id,
            'amount_bs' => 5000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 5000,
            'estado' => 'pendiente',
            'fecha_hora' => now()->subDays(500),
        ]);

        $job = new ExpireUnclaimedPrizesJob;
        $job->handle();

        $ticket->refresh();
        $this->assertEquals('ganador', $ticket->estado,
            'El ticket sin vigencia configurada en ningún nivel debe permanecer como ganador.');
    }
}
