<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Juego;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F1d 1.13 — TicketController::ganadores (REQ9/N7).
 *
 * - whereTime('sorteo_hora', hora): un ganador se declara SOLO contra el
 *   sorteo apostado, nunca contra otro sorteo del mismo día (REQ9).
 * - whereIn(estado): acepta `pendiente` y `ganadora` (impaga).
 * - cálculo con el MOTOR (manager → PremiosEngine), no con el plugin directo.
 */
class TicketGanadoresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function superUser(): User
    {
        $user = User::where('email', 'super@lotto.com')->first();
        $user->assignRole('super_master');

        return $user;
    }

    /**
     * Apuesta al sorteo 13:00 con animal "Delfín" (acento) por 10 Bs.
     */
    private function apuestaAlSorteo(Juego $juego, string $fecha, string $hora, string $estado = 'pendiente'): Apuesta
    {
        $taquilla = Taquilla::factory()->create();

        return Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'combinacion' => json_encode(['animal' => 'Delfín', 'numero' => 0]),
            'amount_bs' => 10,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 10,
            'estado' => $estado,
            'fecha_hora' => $fecha.' '.$hora.':00',
            'sorteo_hora' => $fecha.' '.$hora.':00',
        ]);
    }

    private function resultadoDe(Juego $juego, string $fecha, string $hora, array $numeros): Resultado
    {
        return Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => $fecha,
            'hora_sorteo' => $hora,
            'numeros_ganadores' => $numeros,
        ]);
    }

    public function test_ganador_solo_del_sorteo_apostado()
    {
        // REQ9: apuesta al 13:00 con Delfín; el mismo día hay un resultado
        // ganador a las 16:30 con el MISMO animal. La apuesta NO debe
        // declararse ganadora del 16:30.
        $juego = Juego::where('slug', 'lotto-activo')->first();
        $fecha = '2026-09-21';

        $this->resultadoDe($juego, $fecha, '13:00', ['numero' => 0, 'nombre_animal' => 'Delfin']);
        $this->resultadoDe($juego, $fecha, '16:30', ['numero' => 0, 'nombre_animal' => 'Delfin']);
        $apuesta = $this->apuestaAlSorteo($juego, $fecha, '13:00');

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson("/api/v1/tickets/ganadores?fecha={$fecha}");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data, 'Solo el ticket del 13:00 debe ser ganador.');
        $this->assertCount(1, $data[0]['jugadas'], 'Una sola jugada ganadora, no dos sorteos.');
        $this->assertSame('13:00', substr($data[0]['jugadas'][0]['sorteo_hora'], 11, 5));
        $this->assertSame(300.0, (float) $data[0]['jugadas'][0]['premio_bs'], '10 Bs × 30× = 300 (motor, acentos).');
        $this->assertSame($apuesta->id, $data[0]['jugadas'][0]['apuesta_id']);
    }

    public function test_ganadores_incluye_estado_ganadora()
    {
        // D5/N7: la apuesta `ganadora` (liquidada, impaga) aparece en ganadores.
        $juego = Juego::where('slug', 'lotto-activo')->first();
        $fecha = '2026-09-21';

        $resultado = $this->resultadoDe($juego, $fecha, '13:00', ['numero' => 0, 'nombre_animal' => 'Delfin']);
        $apuesta = $this->apuestaAlSorteo($juego, $fecha, '13:00', 'ganadora');
        $apuesta->update(['resultado_id' => $resultado->id]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson("/api/v1/tickets/ganadores?fecha={$fecha}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($apuesta->id, $data[0]['jugadas'][0]['apuesta_id']);
        $this->assertSame('ganadora', $data[0]['jugadas'][0]['estado']);
    }

    public function test_ganadores_con_comodin_mega_usa_motor()
    {
        // N7/REQ6: el motor aplica el comodín MEGA (40×) sobre la base 30×.
        $juego = Juego::where('slug', 'mega-animal-40')->first();
        $fecha = '2026-09-21';

        $taquilla = Taquilla::factory()->create();
        Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'combinacion' => json_encode(['animal' => 'Águila', 'numero' => 9]),
            'amount_bs' => 10,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 10,
            'estado' => 'pendiente',
            'sorteo_hora' => $fecha.' 13:00:00',
        ]);

        $this->resultadoDe($juego, $fecha, '13:00', [
            'numero' => 9,
            'nombre_animal' => 'Águila',
            'comodin' => true,
        ]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson("/api/v1/tickets/ganadores?fecha={$fecha}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame(400.0, (float) $data[0]['jugadas'][0]['premio_bs'], '10 Bs × 40× (MEGA) = 400 (motor).');
    }
}
