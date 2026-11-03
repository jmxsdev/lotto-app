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
 * WU2 — el egreso es el UNICO tipo que muta estado, premio y ticket, y lo hace
 * en una transaccion con `for update` sobre la apuesta.
 *
 * Cobertura del contrato `pagos`:
 * - solo egreso muta (`ingreso`/`devolucion` rechazados no escriben nada);
 * - doble egreso secuencial -> 1x201 + 1x422 + una sola fila;
 * - el camino de egreso emite un `select ... for update` (lock de fila);
 * - montos persistidos son los del MOTOR (el request solo valida +-0.01);
 * - legacy `pendiente` + `resultado_id` sigue pagandose;
 * - cascada al ticket: solo lineas vivas, tickets anulados se ignoran.
 */
class PagoEgresoUnicoMutanteTest extends TestCase
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
        return User::where('email', 'super@lotto.com')->firstOrFail();
    }

    private function juego(): Juego
    {
        return Juego::where('slug', 'lotto-activo')->firstOrFail();
    }

    private function ticket(Taquilla $taquilla, string $estado = 'pendiente'): Ticket
    {
        return Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 10,
            'total_usd' => 0,
            'estado' => $estado,
        ]);
    }

    private function apuesta(
        Juego $juego,
        Taquilla $taquilla,
        ?Ticket $ticket,
        string $estado,
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

    private function detalle(Apuesta $apuesta): DetalleApuesta
    {
        return DetalleApuesta::create([
            'apuesta_id' => $apuesta->id,
            'combinacion' => json_encode(['animal' => 'perro', 'numero' => 5]),
            'monto' => 10,
            'premio_ganado' => null,
            'premio_ganado_usd' => null,
        ]);
    }

    private function resultadoCoincidente(Juego $juego): Resultado
    {
        return Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => now(),
            'numeros_ganadores' => ['nombre_animal' => 'Perro', 'numero' => 5],
        ]);
    }

    private function pagar(Apuesta $apuesta, array $extra = [])
    {
        return $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/pagos', array_merge([
                'apuesta_id' => $apuesta->id,
                'tipo' => 'egreso',
                'moneda' => 'bs',
            ], $extra));
    }

    private function fixtureGanadora(?Ticket $ticket = null): array
    {
        $juego = $this->juego();
        $taquilla = Taquilla::factory()->create();
        $ticket ??= $this->ticket($taquilla);
        $resultado = $this->resultadoCoincidente($juego);
        $apuesta = $this->apuesta($juego, $taquilla, $ticket, 'ganadora', $resultado);
        $detalle = $this->detalle($apuesta);

        return [$apuesta, $detalle, $ticket, $taquilla, $juego];
    }

    public function test_solo_el_egreso_muta_estado_premio_y_ticket(): void
    {
        [$apuesta, $detalle, $ticket] = $this->fixtureGanadora();

        // 1) ingreso -> 422 sin escrituras.
        $this->pagar($apuesta, ['tipo' => 'ingreso'])->assertStatus(422);

        // 2) devolucion -> 422 sin escrituras.
        $this->pagar($apuesta, ['tipo' => 'devolucion'])->assertStatus(422);

        $this->assertSame('ganadora', $apuesta->fresh()->estado);
        $this->assertNull($detalle->fresh()->premio_ganado);
        $this->assertSame('pendiente', $ticket->fresh()->estado);
        $this->assertSame(0, Pago::where('apuesta_id', $apuesta->id)->count());
        $this->assertSame(0, Log::where('action', 'pago_premio')->count());

        // 3) egreso -> 201 y unica mutacion.
        $response = $this->pagar($apuesta, ['amount_bs' => 300, 'amount_usd' => 0]);

        $response->assertStatus(201);
        $this->assertEquals(300.0, $response->json('premio.premio_bs'));
        $this->assertEquals(0.0, $response->json('premio.premio_usd'));

        $this->assertSame('pagada', $apuesta->fresh()->estado);
        $this->assertEquals(300.00, (float) $detalle->fresh()->premio_ganado);
        $this->assertSame('pagada', $ticket->fresh()->estado);

        $pagos = Pago::where('apuesta_id', $apuesta->id)->get();
        $this->assertCount(1, $pagos);
        $this->assertSame('egreso', $pagos->first()->tipo);
        $this->assertEquals(300.00, (float) $pagos->first()->amount_bs);

        $log = Log::where('action', 'pago_premio')->firstOrFail();
        $this->assertSame('egreso', $log->details['tipo']);
        $this->assertSame($pagos->first()->id, $log->details['pago_id']);
        $this->assertEquals(300.0, (float) $log->details['premio_bs']);
    }

    public function test_doble_egreso_secuencial_da_un_201_un_422_y_una_sola_fila(): void
    {
        [$apuesta] = $this->fixtureGanadora();

        $primero = $this->pagar($apuesta);
        $segundo = $this->pagar($apuesta);

        $primero->assertStatus(201);
        $segundo->assertStatus(422)
            ->assertJsonPath('message', 'No se puede pagar una apuesta pagada.');

        $this->assertSame(
            1,
            Pago::where('apuesta_id', $apuesta->id)->where('tipo', 'egreso')->count(),
            'Un egreso nunca se duplica.'
        );
        $this->assertSame('pagada', $apuesta->fresh()->estado);
        $this->assertSame(1, Log::where('action', 'pago_premio')->count());
    }

    public function test_el_camino_de_egreso_bloquea_la_apuesta_con_for_update(): void
    {
        [$apuesta] = $this->fixtureGanadora();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->pagar($apuesta)->assertStatus(201);

        $consultas = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $bloqueantes = $consultas->filter(function (string $sql) {
            $normalizada = strtolower($sql);

            return str_contains($normalizada, 'select')
                && str_contains($normalizada, '`apuestas`')
                && str_contains($normalizada, 'for update');
        });

        $this->assertTrue(
            $bloqueantes->isNotEmpty(),
            'El egreso debe tomar lock de fila (select ... for update) sobre apuestas.'
        );
    }

    public function test_egreso_legacy_pendiente_con_resultado_se_paga(): void
    {
        $juego = $this->juego();
        $taquilla = Taquilla::factory()->create();
        $ticket = $this->ticket($taquilla);
        $resultado = $this->resultadoCoincidente($juego);
        $apuesta = $this->apuesta($juego, $taquilla, $ticket, 'pendiente', $resultado);
        $detalle = $this->detalle($apuesta);

        $this->pagar($apuesta, ['amount_bs' => 300])->assertStatus(201);

        $this->assertSame('pagada', $apuesta->fresh()->estado);
        $this->assertEquals(300.00, (float) $detalle->fresh()->premio_ganado);
    }

    public function test_egreso_persiste_el_valor_del_motor_dentro_de_la_tolerancia(): void
    {
        [$apuesta, $detalle] = $this->fixtureGanadora();

        // Diferencia exacta de +0.01: dentro de la tolerancia, pero el monto
        // persistido es el del MOTOR (300.00), no el enviado (300.01).
        $this->pagar($apuesta, ['amount_bs' => 300.01])->assertStatus(201);

        $pago = Pago::where('apuesta_id', $apuesta->id)->where('tipo', 'egreso')->firstOrFail();
        $this->assertEquals(300.00, (float) $pago->amount_bs);
        $this->assertEquals(0.00, (float) $pago->amount_usd);
        $this->assertEquals(300.00, (float) $detalle->fresh()->premio_ganado);
        $this->assertEquals(0.00, (float) $detalle->fresh()->premio_ganado_usd);
    }

    public function test_monto_fuera_de_tolerancia_rechaza_sin_escrituras(): void
    {
        [$apuesta, $detalle, $ticket] = $this->fixtureGanadora();

        $response = $this->pagar($apuesta, ['amount_bs' => 301]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'El monto del premio no coincide con lo calculado.')
            ->assertJsonPath('premio_esperado_bs', 300);

        $this->assertSame('ganadora', $apuesta->fresh()->estado);
        $this->assertNull($detalle->fresh()->premio_ganado);
        $this->assertSame('pendiente', $ticket->fresh()->estado);
        $this->assertSame(0, Pago::where('apuesta_id', $apuesta->id)->count());
    }

    public function test_cascada_ignora_tickets_anulados(): void
    {
        $juego = $this->juego();
        $taquilla = Taquilla::factory()->create();
        $ticket = $this->ticket($taquilla, 'anulada');
        $resultado = $this->resultadoCoincidente($juego);
        $apuesta = $this->apuesta($juego, $taquilla, $ticket, 'ganadora', $resultado);
        $this->detalle($apuesta);

        $this->pagar($apuesta)->assertStatus(201);

        $this->assertSame('pagada', $apuesta->fresh()->estado);
        $this->assertSame('anulada', $ticket->fresh()->estado, 'Un ticket anulado no se re-marca pagada.');
    }

    public function test_cascada_solo_considera_lineas_vivas_del_ticket(): void
    {
        $juego = $this->juego();
        $taquilla = Taquilla::factory()->create();
        $ticket = $this->ticket($taquilla);
        $resultado = $this->resultadoCoincidente($juego);

        $apuesta = $this->apuesta($juego, $taquilla, $ticket, 'ganadora', $resultado);
        $this->detalle($apuesta);

        // Linea hermana ya resuelta (perdida) + linea hermana soft-deleted:
        // ninguna debe bloquear la cascada ni ser contada dos veces.
        $perdida = $this->apuesta($juego, $taquilla, $ticket, 'perdida');
        $borrada = $this->apuesta($juego, $taquilla, $ticket, 'pendiente');
        $borrada->delete();

        $this->pagar($apuesta)->assertStatus(201);

        $this->assertSame('pagada', $ticket->fresh()->estado);
        $this->assertSame('perdida', $perdida->fresh()->estado);
        $this->assertSoftDeleted('apuestas', ['id' => $borrada->id]);
    }

    public function test_egreso_con_premio_cero_rechaza_sin_escrituras(): void
    {
        $juego = $this->juego();
        $taquilla = Taquilla::factory()->create();
        $resultado = Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => now(),
            'numeros_ganadores' => ['nombre_animal' => 'Gato', 'numero' => 9],
        ]);
        $apuesta = $this->apuesta($juego, $taquilla, null, 'ganadora', $resultado);
        $this->detalle($apuesta);

        $response = $this->pagar($apuesta);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'La apuesta no resultó ganadora. No puede pagarse un premio.');

        $this->assertSame('ganadora', $apuesta->fresh()->estado);
        $this->assertNull($apuesta->fresh()->detalles()->first()->premio_ganado);
        $this->assertSame(0, Pago::where('apuesta_id', $apuesta->id)->count());
        $this->assertSame(0, Log::where('action', 'pago_premio')->count());
    }

    public function test_cascada_ignora_tickets_soft_deleted(): void
    {
        // Guard de la cascada: un ticket trashed no se re-marca `pagada` ni
        // provoca un `every()` sobre una coleccion vacia.
        $juego = $this->juego();
        $taquilla = Taquilla::factory()->create();
        $ticket = $this->ticket($taquilla);
        $resultado = $this->resultadoCoincidente($juego);
        $apuesta = $this->apuesta($juego, $taquilla, $ticket, 'ganadora', $resultado);
        $this->detalle($apuesta);

        $ticket->delete();

        $this->pagar($apuesta)->assertStatus(201);

        $this->assertSame('pagada', $apuesta->fresh()->estado);
        $this->assertSoftDeleted('tickets', ['id' => $ticket->id]);
    }
}
