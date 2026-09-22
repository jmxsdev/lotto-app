<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\DetalleApuesta;
use App\Models\Juego;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Services\ApuestaService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F3 3.1 — ApuestaService::verificarGanadores (REQ13/D5/N5).
 *
 * - Ganadora: `pendiente → ganadora` con `resultado_id` (premio > 0).
 * - Perdedora: `pendiente → perdida` con `resultado_id`.
 * - `whereNull('resultado_id')`: una apuesta legacy (pendiente con
 *   resultado_id) NO se reprocesa ni se re-liquida (N5).
 */
class VerificarGanadoresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);
    }

    private function juego(): Juego
    {
        return Juego::where('slug', 'lotto-activo')->first();
    }

    private function resultadoDe(Juego $juego, string $fecha, string $hora): Resultado
    {
        return Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => $fecha,
            'hora_sorteo' => $hora,
            'numeros_ganadores' => ['numero' => 0, 'nombre_animal' => 'Delfin'],
        ]);
    }

    private function apuestaPara(Juego $juego, Taquilla $taquilla, string $fecha, string $hora, ?int $ticketId = null, ?int $resultadoId = null, string $estado = 'pendiente'): Apuesta
    {
        $apuesta = Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'ticket_id' => $ticketId,
            'juego_id' => $juego->id,
            'resultado_id' => $resultadoId,
            'combinacion' => json_encode(['animal' => 'Delfín', 'numero' => 0]),
            'amount_bs' => 10,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 10,
            'estado' => $estado,
            'fecha_hora' => $fecha.' '.$hora.':00',
            'sorteo_hora' => $fecha.' '.$hora.':00',
        ]);

        DetalleApuesta::create([
            'apuesta_id' => $apuesta->id,
            'combinacion' => json_encode(['animal' => 'Delfín', 'numero' => 0]),
            'monto' => 10,
            'premio_posible' => 300,
            'premio_posible_usd' => 0,
            'premio_ganado' => null,
            'premio_ganado_usd' => null,
        ]);

        return $apuesta;
    }

    public function test_verificar_ganadores_marca_ganadora_con_resultado_id(): void
    {
        // REQ13/D5: premio > 0 → `pendiente → ganadora` + resultado_id.
        $juego = $this->juego();
        $taquilla = Taquilla::factory()->create();
        $fecha = '2026-09-21';

        $resultado = $this->resultadoDe($juego, $fecha, '13:00');
        $apuesta = $this->apuestaPara($juego, $taquilla, $fecha, '13:00');

        $ganadoras = (new ApuestaService)->verificarGanadores($resultado);

        $this->assertSame(1, $ganadoras);
        $apuesta->refresh();
        $this->assertSame('ganadora', $apuesta->estado, 'La ganadora pasa a estado ganadora (no pendiente).');
        $this->assertSame($resultado->id, $apuesta->resultado_id);

        $detalle = DetalleApuesta::where('apuesta_id', $apuesta->id)->first();
        $this->assertSame('300.00', $detalle->premio_ganado, '10 Bs × 30× = 300 (motor, acentos).');
    }

    public function test_verificar_ganadores_marca_perdida_con_resultado_id(): void
    {
        // D5: premio 0 → `pendiente → perdida` + resultado_id.
        $juego = $this->juego();
        $taquilla = Taquilla::factory()->create();
        $fecha = '2026-09-21';

        $resultado = Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => $fecha,
            'hora_sorteo' => '13:00',
            'numeros_ganadores' => ['numero' => 7, 'nombre_animal' => 'Camello'],
        ]);
        $apuesta = $this->apuestaPara($juego, $taquilla, $fecha, '13:00');

        $ganadoras = (new ApuestaService)->verificarGanadores($resultado);

        $this->assertSame(0, $ganadoras);
        $apuesta->refresh();
        $this->assertSame('perdida', $apuesta->estado);
        $this->assertSame($resultado->id, $apuesta->resultado_id);

        $detalle = DetalleApuesta::where('apuesta_id', $apuesta->id)->first();
        $this->assertNull($detalle->premio_ganado, 'La perdedora no registra premio.');
    }

    public function test_verificar_ganadores_no_reprocesa_apuesta_con_resultado_id(): void
    {
        // N5: `whereNull('resultado_id')` — una apuesta legacy `pendiente` que
        // ya tiene resultado_id (liquidada antes del cambio de estados) NO se
        // vuelve a evaluar: ni su estado ni su premio cambian.
        $juego = $this->juego();
        $taquilla = Taquilla::factory()->create();
        $fecha = '2026-09-21';

        $otroResultado = Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => $fecha,
            'hora_sorteo' => '09:00',
            'numeros_ganadores' => ['numero' => 0, 'nombre_animal' => 'Delfin'],
        ]);
        $resultado = $this->resultadoDe($juego, $fecha, '13:00');

        // Legacy: ganadora pendiente con resultado_id ya asignado.
        $apuesta = $this->apuestaPara($juego, $taquilla, $fecha, '13:00', null, $otroResultado->id, 'pendiente');

        (new ApuestaService)->verificarGanadores($resultado);

        $apuesta->refresh();
        $this->assertSame('pendiente', $apuesta->estado, 'No se reliquida: el estado legacy se conserva.');
        $this->assertSame($otroResultado->id, $apuesta->resultado_id, 'El resultado original se conserva.');
        $this->assertNull(DetalleApuesta::where('apuesta_id', $apuesta->id)->value('premio_ganado'));
    }

    public function test_verificar_ganadores_marca_ticket_ganador_y_premio_total(): void
    {
        // D5: la cascada de ticket marca `ganador` (desde `pendiente`) y
        // acumula premio_total_* (primera vez: desde NULL → valor).
        $juego = $this->juego();
        $taquilla = Taquilla::factory()->create();
        $fecha = '2026-09-21';

        $ticket = Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 20,
            'total_usd' => 0,
            'estado' => 'pendiente',
        ]);
        $this->assertNull($ticket->premio_total_bs, 'Premio total nace NULL (columna nullable).');

        $resultado = $this->resultadoDe($juego, $fecha, '13:00');
        $this->apuestaPara($juego, $taquilla, $fecha, '13:00', $ticket->id);
        $this->apuestaPara($juego, $taquilla, $fecha, '13:00', $ticket->id);

        (new ApuestaService)->verificarGanadores($resultado);

        $ticket->refresh();
        $this->assertSame('ganador', $ticket->estado);
        $this->assertSame('600.00', $ticket->premio_total_bs, 'Dos jugadas ganadoras × 300 = 600.');
        $this->assertSame('0.00', $ticket->premio_total_usd);
    }
}
