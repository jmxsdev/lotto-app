<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\AuditEvent;
use App\Models\Banca;
use App\Models\DetalleApuesta;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Pago;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketAnulacionService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * WU3-A — anulación de ticket con devolución (D9).
 *
 * `DELETE /api/v1/tickets/{id}` deja de ser un soft-delete a secas: valida la
 * policy por cada línea viva, registra un `Pago(tipo=devolucion)` por línea
 * espejando la venta, marca apuestas + ticket `anulada`, los soft-deletea y
 * escribe los eventos de auditoría `ticket.voided`/`bet.voided`, todo en una
 * transacción.
 */
class PagoDevolucionAnulacionTest extends TestCase
{
    use RefreshDatabase;

    private static bool $failAudit = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);

        AuditEvent::creating(static function (): void {
            if (self::$failAudit) {
                throw new RuntimeException('forced audit insert failure');
            }
        });
    }

    protected function tearDown(): void
    {
        self::$failAudit = false;
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
    private function crearJerarquia(string $prefijo): array
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
        ]);

        return [$banca, $grupo, $taquilla];
    }

    /**
     * Crea una línea (apuesta + detalle + venta `ingreso`).
     *
     * Claves soportadas: `amount_bs`, `amount_usd`, `metodo_pago`,
     * `antiguedad_minutos`, `premio_ganado`, `premio_ganado_usd`.
     *
     * @param  array<string, mixed>  $linea
     */
    private function crearLinea(
        ?Ticket $ticket,
        Taquilla $taquilla,
        Juego $juego,
        User $creador,
        array $linea
    ): Apuesta {
        $amountBs = (float) ($linea['amount_bs'] ?? 0);
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
            'estado' => 'pendiente',
            'fecha_hora' => now(),
            'sorteo_hora' => now()->addHour(),
        ]);

        if (isset($linea['antiguedad_minutos'])) {
            $apuesta->created_at = now()->subMinutes((int) $linea['antiguedad_minutos']);
            $apuesta->save();
        }

        DetalleApuesta::create([
            'apuesta_id' => $apuesta->id,
            'combinacion' => ['animal' => 'perro', 'numero' => 5],
            'monto' => $amountBs + ($amountUsd * 36.50),
            'premio_ganado' => $linea['premio_ganado'] ?? null,
            'premio_ganado_usd' => $linea['premio_ganado_usd'] ?? null,
        ]);

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
     * Crea un ticket con sus líneas y su venta (`ingreso`) correspondiente.
     *
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
            $totalBs += (float) ($linea['amount_bs'] ?? 0);
            $totalUsd += (float) ($linea['amount_usd'] ?? 0);
        }

        $ticket->update([
            'total_bs' => $totalBs,
            'total_usd' => $totalUsd,
        ]);

        return [$ticket->fresh(), $apuestas];
    }

    /**
     * Crea una apuesta sin ticket (venta suelta) con su `ingreso`.
     *
     * @param  array<string, mixed>  $linea
     */
    private function crearApuestaSuelta(
        Taquilla $taquilla,
        Juego $juego,
        User $creador,
        array $linea
    ): Apuesta {
        return $this->crearLinea(null, $taquilla, $juego, $creador, $linea);
    }

    public function test_anulacion_registra_devolucion_espejo_de_la_venta(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('MIRROR');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['amount_bs' => 1000, 'amount_usd' => 0, 'metodo_pago' => 'pago_movil'],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'Error del operador']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Ticket anulado correctamente.')
            ->assertJsonPath('data.ticket_id', $ticket->id)
            ->assertJsonPath('data.apuestas', 1)
            ->assertJsonCount(1, 'data.devoluciones');

        $this->assertEquals(1000.0, $response->json('data.total_bs'));
        $this->assertEquals(0.0, $response->json('data.total_usd'));

        $this->assertDatabaseHas('pagos', [
            'apuesta_id' => $apuestas[0]->id,
            'taquilla_id' => $taquilla->id,
            'tipo' => 'devolucion',
            'amount_bs' => 1000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.5000,
            'moneda' => 'bs',
            'metodo_pago' => 'pago_movil',
            'concepto' => 'Devolución de ticket',
            'created_by' => $user->id,
        ]);
    }

    public function test_anulacion_multilinea_registra_una_devolucion_por_linea(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('MULTI');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['amount_bs' => 1000, 'amount_usd' => 0, 'metodo_pago' => 'pago_movil'],
            ['amount_bs' => 0, 'amount_usd' => 5, 'metodo_pago' => 'transferencia'],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'Error del operador']);

        $response->assertOk()
            ->assertJsonPath('data.apuestas', 2)
            ->assertJsonCount(2, 'data.devoluciones');

        $this->assertEquals(1000.0, $response->json('data.total_bs'));
        $this->assertEquals(5.0, $response->json('data.total_usd'));

        // Línea BS: espeja el método de la venta.
        $this->assertDatabaseHas('pagos', [
            'apuesta_id' => $apuestas[0]->id,
            'tipo' => 'devolucion',
            'amount_bs' => 1000,
            'amount_usd' => 0,
            'moneda' => 'bs',
            'metodo_pago' => 'pago_movil',
        ]);

        // Línea USD: `resolverMetodoPago` fuerza efectivo.
        $this->assertDatabaseHas('pagos', [
            'apuesta_id' => $apuestas[1]->id,
            'tipo' => 'devolucion',
            'amount_bs' => 0,
            'amount_usd' => 5,
            'moneda' => 'usd',
            'metodo_pago' => 'efectivo',
        ]);

        $this->assertSame(2, Pago::where('tipo', 'devolucion')->count());
    }

    public function test_apuestas_y_ticket_quedan_anulados_y_soft_deleted(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('STATE');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['amount_bs' => 500],
            ['amount_bs' => 750],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'Error del operador'])
            ->assertOk();

        foreach ($apuestas as $apuesta) {
            $this->assertSoftDeleted('apuestas', ['id' => $apuesta->id]);
            $this->assertSame(
                'anulada',
                Apuesta::withTrashed()->findOrFail($apuesta->id)->estado
            );
        }

        $this->assertSoftDeleted('tickets', ['id' => $ticket->id]);
        $this->assertSame(
            'anulada',
            Ticket::withTrashed()->findOrFail($ticket->id)->estado
        );
    }

    public function test_la_devolucion_no_toca_el_premio_ganado(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('PRIZE');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            [
                'amount_bs' => 1000,
                'premio_ganado' => 300,
                'premio_ganado_usd' => 12,
            ],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'Error del operador'])
            ->assertOk();

        $detalle = DetalleApuesta::where('apuesta_id', $apuestas[0]->id)->firstOrFail();
        $this->assertSame('300.00', $detalle->premio_ganado);
        $this->assertSame('12.00', $detalle->premio_ganado_usd);

        $this->assertSame(
            'anulada',
            Apuesta::withTrashed()->findOrFail($apuestas[0]->id)->estado
        );
        $this->assertSame(0, Pago::where('tipo', 'egreso')->count());
    }

    public function test_anulacion_registra_eventos_de_auditoria(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('LOG');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['amount_bs' => 1000, 'metodo_pago' => 'pago_movil'],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'Error del operador'])
            ->assertOk();

        $this->assertSame(1, AuditEvent::where('action', 'ticket.voided')->count());
        $this->assertSame(1, AuditEvent::where('action', 'bet.voided')->count());
        $this->assertDatabaseMissing('logs', ['action' => 'ticket_anulado']);

        $event = AuditEvent::where('action', 'ticket.voided')->firstOrFail();
        $this->assertSame($user->id, (int) $event->actor_id);
        $this->assertSame($ticket->id, (int) $event->entity_id);
        $this->assertSame('ticket', $event->entity_type);
        $this->assertSame('other', $event->reason_code);
        $this->assertSame('Error del operador', $event->reason_text);
        $this->assertSame([$apuestas[0]->id], $event->context['apuestas']);
        $this->assertEquals(1000.0, (float) $event->context['total_bs']);
        $this->assertEquals(0.0, (float) $event->context['total_usd']);

        $devolucion = Pago::where('apuesta_id', $apuestas[0]->id)
            ->where('tipo', 'devolucion')
            ->firstOrFail();

        $betEvent = AuditEvent::where('action', 'bet.voided')->firstOrFail();
        $this->assertSame($devolucion->id, $betEvent->context['devolucion']['pago_id']);
        $this->assertSame($apuestas[0]->id, $betEvent->context['devolucion']['apuesta_id']);
        $this->assertSame('pago_movil', $betEvent->context['devolucion']['metodo_pago']);
    }

    public function test_reintento_tras_la_anulacion_devuelve_404(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('RETRY');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['amount_bs' => 1000],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'Error del operador'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'Error del operador'])
            ->assertNotFound();

        $this->assertSame(
            1,
            Pago::where('apuesta_id', $apuestas[0]->id)->where('tipo', 'devolucion')->count()
        );
        $this->assertSame(1, AuditEvent::where('action', 'ticket.voided')->count());
    }

    public function test_ticket_de_otro_grupo_devuelve_403(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('OWN');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['amount_bs' => 1000],
        ]);

        [$otraBanca, $otroGrupo] = $this->crearJerarquia('AJENA');
        $ajeno = User::factory()->create([
            'grupo_id' => $otroGrupo->id,
            'banca_id' => $otraBanca->id,
        ]);
        $ajeno->assignRole('grupo');

        $response = $this->actingAs($ajeno, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'Error del operador']);

        $response->assertForbidden()
            ->assertJsonPath('message', 'No autorizado.');

        $this->assertSame(0, Pago::where('tipo', 'devolucion')->count());
        $this->assertNotSoftDeleted('apuestas', ['id' => $apuestas[0]->id]);
        $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
        $this->assertSame(
            0,
            AuditEvent::whereIn('action', ['ticket.voided', 'bet.voided'])->count()
        );
    }

    public function test_ticket_sin_jugadas_vivas_devuelve_422(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('EMPTY');

        $ticket = Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 0,
            'total_usd' => 0,
            'estado' => 'pendiente',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'Error del operador']);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'El ticket no tiene jugadas activas para anular.');

        $this->assertSame(0, Pago::where('tipo', 'devolucion')->count());
        $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
        $this->assertSame('pendiente', $ticket->fresh()->estado);
        $this->assertSame(
            0,
            AuditEvent::whereIn('action', ['ticket.voided', 'bet.voided'])->count()
        );
    }

    public function test_all_or_nothing_una_linea_mala_no_escribe_nada(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('ALLORNOTHING');
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $this->juego(), $user, [
            ['amount_bs' => 500],
            ['amount_bs' => 750, 'antiguedad_minutos' => 10],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'Error del operador']);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'El ticket excedió los 5 minutos para ser anulado.');

        $this->assertSame(0, Pago::where('tipo', 'devolucion')->count());
        $this->assertSame(
            0,
            AuditEvent::whereIn('action', ['ticket.voided', 'bet.voided'])->count()
        );

        foreach ($apuestas as $apuesta) {
            $this->assertNotSoftDeleted('apuestas', ['id' => $apuesta->id]);
            $this->assertSame('pendiente', $apuesta->fresh()->estado);
        }

        $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
        $this->assertSame('pendiente', $ticket->fresh()->estado);
    }

    public function test_ticket_no_pendiente_devuelve_422_sin_escrituras(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('NOPEND');
        [$ticket, $apuestas] = $this->crearTicket(
            $taquilla,
            $this->juego(),
            $user,
            [['amount_bs' => 1000]],
            'ganador'
        );

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'Error del operador']);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Solo se pueden anular tickets pendientes.');

        $this->assertSame(0, Pago::where('tipo', 'devolucion')->count());
        $this->assertNotSoftDeleted('apuestas', ['id' => $apuestas[0]->id]);
        $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
    }

    public function test_rollback_si_falla_la_auditoria_no_deja_devolucion_ni_anulacion(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('ROLLBACK');
        $juego = $this->juego();
        [$ticket, $apuestas] = $this->crearTicket($taquilla, $juego, $user, [
            ['amount_bs' => 1000],
        ]);

        // Falla inyectada en la escritura de auditoría: devolución, estados y
        // soft-deletes ya se ejecutaron dentro de la transacción y deben
        // revertirse completos (500, sin evidencia parcial).
        self::$failAudit = true;

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'Error del operador'])
            ->assertStatus(500);

        self::$failAudit = false;

        $this->assertSame(
            0,
            Pago::where('apuesta_id', $apuestas[0]->id)->where('tipo', 'devolucion')->count()
        );
        $this->assertSame('pendiente', $apuestas[0]->fresh()->estado);
        $this->assertNotSoftDeleted('apuestas', ['id' => $apuestas[0]->id]);
        $this->assertSame('pendiente', $ticket->fresh()->estado);
        $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
        $this->assertSame(0, AuditEvent::where('action', 'ticket.voided')->count());
    }

    public function test_anular_apuesta_suelta_registra_devolucion_y_la_anula(): void
    {
        $user = $this->superUser();
        [, , $taquilla] = $this->crearJerarquia('SUELTA');
        $apuesta = $this->crearApuestaSuelta($taquilla, $this->juego(), $user, [
            'amount_bs' => 800,
            'amount_usd' => 0,
            'metodo_pago' => 'transferencia',
        ]);

        $pago = app(TicketAnulacionService::class)
            ->anularApuestaSuelta($apuesta, $user, ['motivo' => 'Error del operador']);

        $this->assertInstanceOf(Pago::class, $pago);
        $this->assertSame('devolucion', $pago->tipo);
        $this->assertSame($apuesta->id, $pago->apuesta_id);
        $this->assertEquals(800.0, (float) $pago->amount_bs);
        $this->assertSame('transferencia', $pago->metodo_pago);
        $this->assertSame('bs', $pago->moneda);

        $this->assertSoftDeleted('apuestas', ['id' => $apuesta->id]);
        $this->assertSame(
            'anulada',
            Apuesta::withTrashed()->findOrFail($apuesta->id)->estado
        );

        $event = AuditEvent::where('action', 'bet.voided')->firstOrFail();
        $this->assertSame($apuesta->id, (int) $event->entity_id);
        $this->assertSame('Error del operador', $event->reason_text);
        $this->assertSame($pago->id, $event->context['devolucion']['pago_id']);
        $this->assertDatabaseMissing('logs', ['action' => 'apuesta.anulada']);
    }
}
