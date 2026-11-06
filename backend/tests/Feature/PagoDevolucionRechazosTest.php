<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\AuditEvent;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Pago;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WU3-B — matriz de rechazos de la devolución pre-sorteo.
 *
 * Cubre los dos endpoints de anulación:
 * - `DELETE /api/v1/tickets/{id}`: estado, `resultado_id`, sorteo ocurrido,
 *   ventana efectiva dinámica (5/10/15) con borde `>=`, ticket inexistente,
 *   ticket sin líneas vivas, cross-tenant y all-or-nothing.
 * - `DELETE /api/v1/apuestas/{id}`: solo ventas sueltas; una línea de ticket
 *   responde 422 con el redirect al endpoint de ticket; el cross-tenant se
 *   resuelve 403 ANTES del redirect (política `view`).
 *
 * Todos los rechazos deben dejar cero escrituras: sin `pagos`, sin cambios de
 * estado/soft-delete y sin eventos de auditoría.
 */
class PagoDevolucionRechazosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function superUser(): User
    {
        $super = User::where('email', 'super@lotto.com')->firstOrFail();
        $super->assignRole('super_master');

        return $super;
    }

    private function juego(): Juego
    {
        return Juego::where('slug', 'lotto-activo')->firstOrFail();
    }

    /**
     * @return array{0: Banca, 1: Grupo, 2: Taquilla}
     */
    private function crearJerarquia(string $prefijo, ?int $tiempoEliminacion = null): array
    {
        $sufijo = strtoupper(Str::random(5));

        $banca = Banca::create([
            'name' => "Banca {$prefijo}",
            'code' => "B{$sufijo}",
            'active' => true,
        ]);
        $grupo = Grupo::create([
            'name' => "Grupo {$prefijo}",
            'code' => "G{$sufijo}",
            'banca_id' => $banca->id,
            'active' => true,
        ]);
        $taquilla = Taquilla::create([
            'name' => "Taquilla {$prefijo}",
            'code' => "T{$sufijo}",
            'grupo_id' => $grupo->id,
            'active' => true,
            'tiempo_eliminacion' => $tiempoEliminacion,
        ]);

        return [$banca, $grupo, $taquilla];
    }

    /**
     * Crea una línea con su venta `ingreso`.
     *
     * Claves soportadas: `amount_bs`, `amount_usd`, `metodo_pago`, `estado`,
     * `resultado_id`, `sorteo_hora`, `created_at`.
     *
     * @param  array<string, mixed>  $linea
     */
    private function crearLinea(
        ?Ticket $ticket,
        Taquilla $taquilla,
        Juego $juego,
        User $creador,
        array $linea = []
    ): Apuesta {
        $amountBs = (float) ($linea['amount_bs'] ?? 1000);
        $amountUsd = (float) ($linea['amount_usd'] ?? 0);

        $apuesta = Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'ticket_id' => $ticket?->id,
            'combinacion' => ['animal' => 'perro', 'numero' => 5],
            'amount_bs' => $amountBs,
            'amount_usd' => $amountUsd,
            'exchange_rate_applied' => 36.5000,
            'total_bs_equivalent' => $amountBs + ($amountUsd * 36.50),
            'estado' => $linea['estado'] ?? 'pendiente',
            'resultado_id' => $linea['resultado_id'] ?? null,
            'fecha_hora' => now(),
            'sorteo_hora' => $linea['sorteo_hora'] ?? now()->addHour(),
        ]);

        if (array_key_exists('created_at', $linea)) {
            $apuesta->created_at = $linea['created_at'];
            $apuesta->save();
        }

        $moneda = $amountBs > 0 && $amountUsd > 0
            ? 'mixto'
            : ($amountUsd > 0 ? 'usd' : 'bs');

        Pago::create([
            'taquilla_id' => $taquilla->id,
            'apuesta_id' => $apuesta->id,
            'amount_bs' => $amountBs,
            'amount_usd' => $amountUsd,
            'exchange_rate_applied' => 36.5000,
            'tipo' => 'ingreso',
            'moneda' => $moneda,
            'metodo_pago' => Pago::resolverMetodoPago(
                $linea['metodo_pago'] ?? null,
                $moneda,
                $amountUsd
            ),
            'concepto' => 'Compra de ticket',
            'created_by' => $creador->id,
        ]);

        return $apuesta;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineas
     * @return array{0: Ticket, 1: array<int, Apuesta>}
     */
    private function crearTicket(
        Taquilla $taquilla,
        Juego $juego,
        User $creador,
        array $lineas,
        string $estado = 'pendiente'
    ): array {
        $ticket = Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 0,
            'total_usd' => 0,
            'estado' => $estado,
        ]);

        $totalBs = 0.0;
        $totalUsd = 0.0;
        $apuestas = [];

        foreach ($lineas as $linea) {
            $apuestas[] = $this->crearLinea($ticket, $taquilla, $juego, $creador, $linea);
            $totalBs += (float) ($linea['amount_bs'] ?? 1000);
            $totalUsd += (float) ($linea['amount_usd'] ?? 0);
        }

        $ticket->update([
            'total_bs' => $totalBs,
            'total_usd' => $totalUsd,
        ]);

        return [$ticket->fresh(), $apuestas];
    }

    private function usuarioGrupoAjeno(): User
    {
        [$otraBanca, $otroGrupo] = $this->crearJerarquia('AJENA');

        $ajeno = User::factory()->create([
            'grupo_id' => $otroGrupo->id,
            'banca_id' => $otraBanca->id,
        ]);
        $ajeno->assignRole('grupo');

        return $ajeno;
    }

    private function assertSinEscrituras(Ticket $ticket, array $apuestas): void
    {
        $this->assertSame(0, Pago::where('tipo', 'devolucion')->count());
        $this->assertSame(
            0,
            AuditEvent::whereIn('action', ['ticket.voided', 'bet.voided'])->count()
        );

        foreach ($apuestas as $apuesta) {
            $this->assertNotSoftDeleted('apuestas', ['id' => $apuesta->id]);
            $this->assertSame($apuesta->estado, $apuesta->fresh()->estado);
        }

        $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
        $this->assertSame($ticket->estado, $ticket->fresh()->estado);
    }

    public function test_estado_ganadora_rechaza_sin_escrituras(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('GANADORA');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['estado' => 'ganadora'],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'prueba'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede anular el ticket.');

        $this->assertSinEscrituras($ticket, $apuestas);
    }

    public function test_estado_pagada_rechaza_sin_escrituras(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('PAGADA');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['estado' => 'pagada'],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'prueba'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede anular el ticket.');

        $this->assertSinEscrituras($ticket, $apuestas);
    }

    public function test_estado_perdida_rechaza_sin_escrituras(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('PERDIDA');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['estado' => 'perdida'],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'prueba'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede anular el ticket.');

        $this->assertSinEscrituras($ticket, $apuestas);
    }

    public function test_estado_vencido_rechaza_sin_escrituras(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('VENCIDO');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['estado' => 'vencido'],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'prueba'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede anular el ticket.');

        $this->assertSinEscrituras($ticket, $apuestas);
    }

    public function test_estado_anulada_rechaza_sin_escrituras(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('ANULADA');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['estado' => 'anulada'],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'prueba'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede anular el ticket.');

        $this->assertSinEscrituras($ticket, $apuestas);
    }

    public function test_pendiente_con_resultado_id_rechaza_sin_escrituras(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('CONRESULTADO');
        $juego = $this->juego();

        $resultado = Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => now(),
            'numeros_ganadores' => ['nombre_animal' => 'Perro', 'numero' => 5],
        ]);

        [$ticket, $apuestas] = $this->crearTicket($taquilla, $juego, $user, [
            ['resultado_id' => $resultado->id],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'prueba'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede anular el ticket.');

        $this->assertSinEscrituras($ticket, $apuestas);
    }

    public function test_sorteo_ocurrido_rechaza_sin_escrituras(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('SORTEO');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['sorteo_hora' => now()->subMinute()],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'prueba'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El sorteo de una o mas jugadas ya ocurrio, no se puede anular.');

        $this->assertSinEscrituras($ticket, $apuestas);
    }

    public function test_ventana_de_5_minutos_hace_boundary_con_el_borde_exacto(): void
    {
        $this->assertVentanaLimite(5);
    }

    public function test_ventana_de_10_minutos_hace_boundary_con_el_borde_exacto(): void
    {
        $this->assertVentanaLimite(10);
    }

    public function test_ventana_de_15_minutos_hace_boundary_con_el_borde_exacto(): void
    {
        $this->assertVentanaLimite(15);
    }

    private function assertVentanaLimite(int $minutos): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:00'));

        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('W'.$minutos, $minutos);

        // Borde exacto: `diffInMinutes >= tiempo` rechaza.
        [$borde, $lineasBorde] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['created_at' => Carbon::now()->subMinutes($minutos)],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$borde->id, ['motivo' => 'prueba'])
            ->assertStatus(422)
            ->assertJsonPath('message', "El ticket excedió los {$minutos} minutos para ser anulado.");

        $this->assertSinEscrituras($borde, $lineasBorde);

        // Un minuto por dentro: se anula y devuelve.
        [$dentro, $lineasDentro] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['created_at' => Carbon::now()->subMinutes($minutos - 1)],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$dentro->id, ['motivo' => 'prueba'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('tickets', ['id' => $dentro->id]);
        $this->assertSame(
            'anulada',
            Ticket::withTrashed()->findOrFail($dentro->id)->estado
        );
        $this->assertSame(
            1,
            Pago::where('apuesta_id', $lineasDentro[0]->id)
                ->where('tipo', 'devolucion')
                ->count()
        );
    }

    public function test_ticket_inexistente_devuelve_404(): void
    {
        $user = $this->superUser();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/999999', ['motivo' => 'prueba'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Recurso no encontrado.');
    }

    public function test_ticket_sin_lineas_vivas_devuelve_422(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('SINVIVAS');

        $ticket = Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 0,
            'total_usd' => 0,
            'estado' => 'pendiente',
        ]);

        $apuesta = $this->crearLinea($ticket, $taquilla, $this->juego(), $user);
        $apuesta->delete();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'prueba'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El ticket no tiene jugadas activas para anular.');

        $this->assertSame(0, Pago::where('tipo', 'devolucion')->count());
        $this->assertSame(
            0,
            AuditEvent::whereIn('action', ['ticket.voided', 'bet.voided'])->count()
        );
        $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
        $this->assertSame('pendiente', $ticket->fresh()->estado);
    }

    public function test_cross_tenant_devuelve_403_sin_escrituras(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('PROPIA');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['amount_bs' => 1000],
        ]);

        $ajeno = $this->usuarioGrupoAjeno();

        $this->actingAs($ajeno, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'prueba'])
            ->assertForbidden()
            ->assertJsonPath('message', 'No autorizado.');

        $this->assertSinEscrituras($ticket, $apuestas);
    }

    public function test_all_or_nothing_una_linea_mala_no_escribe_ninguna_linea(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('MIXTA');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['amount_bs' => 500],
            ['amount_bs' => 750, 'estado' => 'ganadora'],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'prueba'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede anular el ticket.');

        $this->assertSinEscrituras($ticket, $apuestas);
    }

    public function test_delete_apuestas_anula_venta_suelta_y_registra_devolucion(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('SUELTA');

        $apuesta = $this->crearLinea(null, $taquilla, $this->juego(), $user, [
            'amount_bs' => 800,
            'metodo_pago' => 'transferencia',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/apuestas/'.$apuesta->id, [
                'motivo' => 'Error del operador',
                'referencia' => 'DEV-SUELTA',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Apuesta anulada correctamente.')
            ->assertJsonPath('data.devolucion.apuesta_id', $apuesta->id)
            ->assertJsonPath('data.devolucion.metodo_pago', 'transferencia')
            ->assertJsonPath('data.devolucion.moneda', 'bs');

        $devolucion = Pago::where('apuesta_id', $apuesta->id)
            ->where('tipo', 'devolucion')
            ->firstOrFail();

        $this->assertSame($devolucion->id, $response->json('data.devolucion.pago_id'));
        $this->assertSame('DEV-SUELTA', $devolucion->referencia);
        $this->assertSoftDeleted('apuestas', ['id' => $apuesta->id]);
        $this->assertSame(
            'anulada',
            Apuesta::withTrashed()->findOrFail($apuesta->id)->estado
        );

        $event = AuditEvent::where('action', 'bet.voided')->firstOrFail();
        $this->assertSame($apuesta->id, (int) $event->entity_id);
        $this->assertSame('Error del operador', $event->reason_text);
        $this->assertSame('other', $event->reason_code);
        $this->assertSame($devolucion->id, $event->context['devolucion']['pago_id']);
        $this->assertDatabaseMissing('logs', ['action' => 'apuesta.anulada']);
        $this->assertDatabaseMissing('logs', ['action' => 'apuesta.deleted']);
    }

    public function test_delete_apuestas_rechaza_una_linea_de_ticket_con_redirect(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('LINEATICKET');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['amount_bs' => 1000, 'created_at' => now()->subMinutes(10)],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/apuestas/'.$apuestas[0]->id, ['motivo' => 'test'])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                "La anulación se realiza a nivel de ticket: use DELETE /api/v1/tickets/{$ticket->id}."
            );

        $this->assertSame(0, Pago::where('tipo', 'devolucion')->count());
        $this->assertSame(
            0,
            AuditEvent::whereIn('action', ['ticket.voided', 'bet.voided'])->count()
        );
        $this->assertNotSoftDeleted('apuestas', ['id' => $apuestas[0]->id]);
        $this->assertSame('pendiente', $apuestas[0]->fresh()->estado);
        $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
    }

    public function test_delete_apuestas_cross_tenant_devuelve_403_antes_del_redirect(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('AJENA-LINEA');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['amount_bs' => 1000, 'created_at' => now()->subMinutes(10)],
        ]);

        $ajeno = $this->usuarioGrupoAjeno();

        $this->actingAs($ajeno, 'sanctum')
            ->deleteJson('/api/v1/apuestas/'.$apuestas[0]->id, ['motivo' => 'prueba'])
            ->assertForbidden()
            ->assertJsonPath('message', 'No autorizado.');

        $this->assertSame(0, Pago::where('tipo', 'devolucion')->count());
        $this->assertSame(
            0,
            AuditEvent::whereIn('action', ['ticket.voided', 'bet.voided'])->count()
        );
        $this->assertNotSoftDeleted('apuestas', ['id' => $apuestas[0]->id]);
        $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
    }

    public function test_delete_apuestas_suelta_fuera_de_ventana_rechaza_sin_escrituras(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:00'));

        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('SUELTA-VIEJA');

        $apuesta = $this->crearLinea(null, $taquilla, $this->juego(), $user, [
            'amount_bs' => 1000,
            'created_at' => Carbon::now()->subMinutes(5),
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/apuestas/'.$apuesta->id, ['motivo' => 'prueba'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El ticket excedió los 5 minutos para ser anulado.');

        $this->assertSame(0, Pago::where('tipo', 'devolucion')->count());
        $this->assertSame(
            0,
            AuditEvent::whereIn('action', ['ticket.voided', 'bet.voided'])->count()
        );
        $this->assertNotSoftDeleted('apuestas', ['id' => $apuesta->id]);
        $this->assertSame('pendiente', $apuesta->fresh()->estado);
    }
}
