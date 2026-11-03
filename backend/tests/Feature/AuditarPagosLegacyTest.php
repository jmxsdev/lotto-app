<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\DetalleApuesta;
use App\Models\Juego;
use App\Models\Log;
use App\Models\Pago;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WU5 (gated) — `pagos:auditar-legacy`.
 *
 * Feature test (not Unit) because the command runs the C1-C5 SQL queries,
 * recomputes prizes through `PremiosLiquidador` and mutates/writes the DB; it
 * needs `RefreshDatabase`, the seeded catalog and the real plugin engine.
 *
 * Contract:
 * - dry-run is the default and writes nothing (only reads, optional CSV);
 * - `--apply` refuses without explicit `--ids`;
 * - `--apply --ids` recomputes the engine prize, transitions the line
 *   (`ganadora`/`perdida`, never back to `pendiente`), restores the detail
 *   evidence, recomputes the ticket state, keeps `pagos` and writes one
 *   `auditoria.pago_legacy` log with `old_values`/`new_values`;
 * - `--revert --ids` restores `old_values` from the latest log, no other write;
 * - a multi-line ticket that does not reconcile against
 *   `tickets.premio_total_*` goes to manual review with zero writes;
 * - `pagos.amount_*` and `cierres_caja` are never touched. (The retired
 *   `comisiones` ledger is no longer part of the schema — Modelo A.)
 */
class AuditarPagosLegacyTest extends TestCase
{
    use RefreshDatabase;

    private const ACTION = 'auditoria.pago_legacy';

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

    private function taquilla(): Taquilla
    {
        return Taquilla::factory()->create();
    }

    private function resultadoCoincidente(Juego $juego): Resultado
    {
        return Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => now(),
            'numeros_ganadores' => ['nombre_animal' => 'Perro', 'numero' => 5],
        ]);
    }

    private function ticket(
        Taquilla $taquilla,
        string $estado = 'pagada',
        float $premioTotalBs = 0,
        float $premioTotalUsd = 0
    ): Ticket {
        return Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 10,
            'total_usd' => 0,
            'premio_total_bs' => $premioTotalBs,
            'premio_total_usd' => $premioTotalUsd,
            'estado' => $estado,
        ]);
    }

    private function apuesta(
        Juego $juego,
        Taquilla $taquilla,
        ?Ticket $ticket,
        string $estado = 'pagada',
        ?Resultado $resultado = null
    ): Apuesta {
        return Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'ticket_id' => $ticket?->id,
            'juego_id' => $juego->id,
            'combinacion' => json_encode(['animal' => 'perro', 'numero' => 5]),
            'amount_bs' => 10,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 10,
            'estado' => $estado,
            'resultado_id' => $resultado?->id,
            'fecha_hora' => now(),
            'sorteo_hora' => now()->addHour(),
        ]);
    }

    private function detalle(Apuesta $apuesta, ?float $premio = null, ?float $premioUsd = null): DetalleApuesta
    {
        return DetalleApuesta::create([
            'apuesta_id' => $apuesta->id,
            'combinacion' => json_encode(['animal' => 'perro', 'numero' => 5]),
            'monto' => 10,
            'premio_ganado' => $premio,
            'premio_ganado_usd' => $premioUsd,
        ]);
    }

    private function pago(Apuesta $apuesta, string $tipo, float $amountBs): Pago
    {
        return Pago::create([
            'taquilla_id' => $apuesta->taquilla_id,
            'apuesta_id' => $apuesta->id,
            'amount_bs' => $amountBs,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'tipo' => $tipo,
            'moneda' => 'bs',
            'metodo_pago' => 'efectivo',
            'concepto' => "Pago {$tipo} de prueba",
            'created_by' => $this->superUser()->id,
        ]);
    }

    /**
     * C1/C2: apuesta `pagada` sin `egreso`, con evidencia de premio perdida.
     * Tambien es C3 (su ticket esta `pagada` con una linea pagada sin egreso).
     *
     * @return array{0: Apuesta, 1: DetalleApuesta, 2: Ticket}
     */
    private function fixtureC1C2(): array
    {
        $juego = $this->juego();
        $taquilla = $this->taquilla();
        $resultado = $this->resultadoCoincidente($juego);
        $ticket = $this->ticket($taquilla, 'pagada', 300);
        $apuesta = $this->apuesta($juego, $taquilla, $ticket, 'pagada', $resultado);
        $detalle = $this->detalle($apuesta);

        // Evidencia de caja que NO debe tocarse (venta + una devolucion legacy).
        $this->pago($apuesta, 'ingreso', 10);
        $this->pago($apuesta, 'devolucion', 999);

        return [$apuesta, $detalle, $ticket];
    }

    private function fixtureC3(): Ticket
    {
        $juego = $this->juego();
        $taquilla = $this->taquilla();
        $resultado = $this->resultadoCoincidente($juego);
        $ticket = $this->ticket($taquilla, 'pagada', 300);
        $apuesta = $this->apuesta($juego, $taquilla, $ticket, 'pagada', $resultado);
        $this->detalle($apuesta);

        return $ticket;
    }

    private function fixtureC4(): Ticket
    {
        // Ticket `pagada` sin lineas vivas.
        return $this->ticket($this->taquilla(), 'pagada', 0);
    }

    private function fixtureC5(): Apuesta
    {
        // Venta con dos `ingreso` (duplicado legacy), sin ticket.
        $juego = $this->juego();
        $taquilla = $this->taquilla();
        $apuesta = $this->apuesta($juego, $taquilla, null, 'pendiente');
        $this->pago($apuesta, 'ingreso', 10);
        $this->pago($apuesta, 'ingreso', 10);

        return $apuesta;
    }

    public function test_dry_run_encuentra_c1_c2_c3_c4_c5_y_no_escribe_nada(): void
    {
        [$c1, $detalle, $ticket] = $this->fixtureC1C2();
        $c3Ticket = $this->fixtureC3();
        $c4Ticket = $this->fixtureC4();
        $c5 = $this->fixtureC5();

        // El fixture C4 debe ser un ticket `pagada` sin líneas vivas; si esto
        // falla, el problema está en el fixture y no en el comando.
        $this->assertSame('pagada', $c4Ticket->fresh()->estado);
        $this->assertSame(0, $c4Ticket->apuestas()->count());
        $this->assertNotSoftDeleted('tickets', ['id' => $c4Ticket->id]);

        $pagosAntes = Pago::count();
        $logsAntes = Log::count();
        $detallesAntes = DetalleApuesta::count();

        $this->artisan('pagos:auditar-legacy')
            // Las expectativas de encabezado deben ser específicas (`C4:`),
            // no `C4`: la línea de detalle contiene `C4 ticket=...` y, si el
            // mock consume esa línea para el encabezado, la expectativa
            // posterior `ticket=...` queda sin match.
            ->expectsOutputToContain('C1/C2:')
            ->expectsOutputToContain('C3:')
            ->expectsOutputToContain('C4:')
            ->expectsOutputToContain('C5:')
            ->expectsOutputToContain('apuesta='.$c1->id)
            ->expectsOutputToContain('C4 ticket='.$c4Ticket->id)
            ->expectsOutputToContain('apuesta='.$c5->id)
            ->expectsOutputToContain($ticket->taquilla->name)
            ->assertExitCode(0);

        $this->assertSame($pagosAntes, Pago::count());
        $this->assertSame($logsAntes, Log::count());
        $this->assertSame($detallesAntes, DetalleApuesta::count());
        $this->assertSame(0, Log::where('action', self::ACTION)->count());

        $this->assertSame('pagada', $c1->fresh()->estado);
        $this->assertNull($detalle->fresh()->premio_ganado);
        $this->assertSame('pagada', $ticket->fresh()->estado);
        $this->assertSame('pagada', $c3Ticket->fresh()->estado);
        $this->assertSame('pagada', $c4Ticket->fresh()->estado);
        $this->assertSame('pendiente', $c5->fresh()->estado);
    }

    public function test_dry_run_exporta_csv_sin_escribir_en_la_base(): void
    {
        [$c1] = $this->fixtureC1C2();
        $path = tempnam(sys_get_temp_dir(), 'audit-legacy-');

        try {
            $this->artisan('pagos:auditar-legacy', ['--csv' => $path])->assertExitCode(0);

            $csv = (string) file_get_contents($path);
            $this->assertStringContainsString('clase,taquilla_id,taquilla,apuesta_id,ticket_id,detalle', $csv);
            $this->assertStringContainsString('C1/C2', $csv);
            $this->assertStringContainsString('apuesta='.$c1->id, $csv);
            $this->assertSame(0, Log::where('action', self::ACTION)->count());
            $this->assertSame('pagada', $c1->fresh()->estado);
        } finally {
            @unlink($path);
        }
    }

    public function test_apply_sin_ids_se_rechaza_y_no_escribe(): void
    {
        [$apuesta, $detalle, $ticket] = $this->fixtureC1C2();

        $this->artisan('pagos:auditar-legacy', ['--apply' => true])
            ->expectsOutputToContain('--ids')
            ->assertExitCode(1);

        $this->assertSame(0, Log::where('action', self::ACTION)->count());
        $this->assertSame('pagada', $apuesta->fresh()->estado);
        $this->assertNull($detalle->fresh()->premio_ganado);
        $this->assertSame('pagada', $ticket->fresh()->estado);
    }

    public function test_apply_recalcula_premio_estado_detalle_y_ticket_y_conserva_pagos(): void
    {
        [$apuesta, $detalle, $ticket] = $this->fixtureC1C2();

        $pagosAntes = Pago::where('apuesta_id', $apuesta->id)
            ->orderBy('id')
            ->get(['id', 'tipo', 'amount_bs', 'amount_usd'])
            ->toArray();

        $cierreId = DB::table('cierres_caja')->insertGetId([
            'taquilla_id' => $apuesta->taquilla_id,
            'fecha_inicio' => now(),
            'total_ventas_bs' => 123.45,
            'exchange_rate_cierre' => 36.50,
            'created_by' => $this->superUser()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('pagos:auditar-legacy', [
            '--apply' => true,
            '--ids' => (string) $apuesta->id,
        ])->expectsOutputToContain('apuesta='.$apuesta->id)->assertExitCode(0);

        // Estado + evidencia del detalle recalculados por el motor (10 Bs x 30).
        $this->assertSame('ganadora', $apuesta->fresh()->estado);
        $this->assertEquals(300.00, (float) $detalle->fresh()->premio_ganado);
        $this->assertEquals(0.00, (float) $detalle->fresh()->premio_ganado_usd);

        // Unica linea viva ganadora (impaga) -> ticket `ganador`.
        $this->assertSame('ganador', $ticket->fresh()->estado);

        // Evidencia de caja intacta: sin egreso nuevo y mismos montos.
        $pagosDespues = Pago::where('apuesta_id', $apuesta->id)
            ->orderBy('id')
            ->get(['id', 'tipo', 'amount_bs', 'amount_usd'])
            ->toArray();
        $this->assertSame($pagosAntes, $pagosDespues);
        $this->assertSame(0, Pago::where('apuesta_id', $apuesta->id)->where('tipo', 'egreso')->count());

        // Cierres intactos (el ledger de comisiones fue retirado en Modelo A).
        $this->assertDatabaseHas('cierres_caja', ['id' => $cierreId, 'total_ventas_bs' => 123.45]);

        // Un unico log por apuesta, con old_values/new_values.
        $this->assertSame(1, Log::where('action', self::ACTION)->count());
        $log = Log::where('action', self::ACTION)->firstOrFail();
        $this->assertNull($log->user_id);
        $this->assertSame($apuesta->id, (int) $log->details['apuesta_id']);
        $this->assertSame('pagada', $log->details['old_values']['estado']);
        $this->assertSame('ganadora', $log->details['new_values']['estado']);
        $this->assertSame('pagada', $log->details['old_values']['ticket_estado']);
        $this->assertSame('ganador', $log->details['new_values']['ticket_estado']);
        $this->assertEquals(300.0, (float) $log->details['new_values']['premio_ganado']);
        $this->assertEquals(0.0, (float) $log->details['new_values']['premio_ganado_usd']);
    }

    public function test_apply_marca_perdida_cuando_el_motor_calcula_cero(): void
    {
        $juego = $this->juego();
        $taquilla = $this->taquilla();
        $resultado = Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => now(),
            'numeros_ganadores' => ['nombre_animal' => 'Gato', 'numero' => 11],
        ]);
        $ticket = $this->ticket($taquilla, 'pagada', 0);
        $apuesta = $this->apuesta($juego, $taquilla, $ticket, 'pagada', $resultado);
        $detalle = $this->detalle($apuesta, 300);

        $this->artisan('pagos:auditar-legacy', [
            '--apply' => true,
            '--ids' => (string) $apuesta->id,
        ])->assertExitCode(0);

        $this->assertSame('perdida', $apuesta->fresh()->estado);
        $this->assertEquals(0.00, (float) $detalle->fresh()->premio_ganado);
        // Linea perdida + sin otras lineas -> todas resueltas -> ticket pagada.
        $this->assertSame('pagada', $ticket->fresh()->estado);
        $this->assertSame('perdida', Log::where('action', self::ACTION)->firstOrFail()->details['new_values']['estado']);
    }

    public function test_revert_sin_ids_se_rechaza_y_no_escribe(): void
    {
        [$apuesta] = $this->fixtureC1C2();

        $this->artisan('pagos:auditar-legacy', ['--revert' => true])
            ->expectsOutputToContain('--ids')
            ->assertExitCode(1);

        $this->assertSame('pagada', $apuesta->fresh()->estado);
    }

    public function test_revert_restaura_los_old_values_del_ultimo_log(): void
    {
        [$apuesta, $detalle, $ticket] = $this->fixtureC1C2();

        $this->artisan('pagos:auditar-legacy', [
            '--apply' => true,
            '--ids' => (string) $apuesta->id,
        ])->assertExitCode(0);

        $this->assertSame('ganadora', $apuesta->fresh()->estado);
        $this->assertSame('ganador', $ticket->fresh()->estado);

        $this->artisan('pagos:auditar-legacy', [
            '--revert' => true,
            '--ids' => (string) $apuesta->id,
        ])->assertExitCode(0);

        $this->assertSame('pagada', $apuesta->fresh()->estado);
        $this->assertNull($detalle->fresh()->premio_ganado);
        $this->assertNull($detalle->fresh()->premio_ganado_usd);
        $this->assertSame('pagada', $ticket->fresh()->estado);

        // Revert no escribe logs nuevos.
        $this->assertSame(1, Log::where('action', self::ACTION)->count());
    }

    public function test_apply_y_revert_son_mutuamente_excluyentes(): void
    {
        [$apuesta] = $this->fixtureC1C2();

        $this->artisan('pagos:auditar-legacy', [
            '--apply' => true,
            '--revert' => true,
            '--ids' => (string) $apuesta->id,
        ])->assertExitCode(1);

        $this->assertSame('pagada', $apuesta->fresh()->estado);
        $this->assertSame(0, Log::where('action', self::ACTION)->count());
    }

    public function test_ticket_multilinea_que_no_cuadra_va_a_revision_manual_sin_escrituras(): void
    {
        $juego = $this->juego();
        $taquilla = $this->taquilla();
        $resultado = $this->resultadoCoincidente($juego);

        // El ticket declara un total que no coincide con la suma del motor
        // (300 + 0) ni con la evidencia del detalle: revision manual.
        $ticket = $this->ticket($taquilla, 'pagada', 999);
        $apuesta = $this->apuesta($juego, $taquilla, $ticket, 'pagada', $resultado);
        $detalle = $this->detalle($apuesta);

        $hermana = $this->apuesta($juego, $taquilla, $ticket, 'perdida');
        $this->detalle($hermana, 100);

        $this->artisan('pagos:auditar-legacy', [
            '--apply' => true,
            '--ids' => (string) $apuesta->id,
        ])->expectsOutputToContain('REVISION MANUAL')->assertExitCode(1);

        $this->assertSame('pagada', $apuesta->fresh()->estado);
        $this->assertNull($detalle->fresh()->premio_ganado);
        $this->assertSame('pagada', $ticket->fresh()->estado);
        $this->assertSame(0, Log::where('action', self::ACTION)->count());
    }

    public function test_apply_ignora_ids_que_no_son_c1_c2_sin_escribir(): void
    {
        $juego = $this->juego();
        $taquilla = $this->taquilla();
        $ticket = $this->ticket($taquilla, 'pendiente', 0);
        $apuesta = $this->apuesta($juego, $taquilla, $ticket, 'pendiente');
        $this->detalle($apuesta);

        $this->artisan('pagos:auditar-legacy', [
            '--apply' => true,
            '--ids' => (string) $apuesta->id,
        ])->assertExitCode(1);

        $this->assertSame('pendiente', $apuesta->fresh()->estado);
        $this->assertSame(0, Log::where('action', self::ACTION)->count());
    }
}
