<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\DetalleApuesta;
use App\Models\ExchangeRate;
use App\Models\Juego;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ApuestaService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Tripleta multi-sorteo todo o nada (Cazalotón 200× / Loto Chaima 50×).
 *
 * Reglamentos: 3 figuras distintas en 3 sorteos distintos del mismo juego y
 * día. Un sorteo no puede satisfacer dos selecciones; un resultado de otro
 * juego no cuenta; 1-2 aciertos no pagan.
 */
class TripletaMultiSorteoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function juego(string $slug = 'cazaloton'): Juego
    {
        return Juego::where('slug', $slug)->firstOrFail();
    }

    private function taquilla(): Taquilla
    {
        return Taquilla::factory()->create();
    }

    private function ticket(Taquilla $taquilla): Ticket
    {
        return Ticket::create([
            'taquilla_id' => $taquilla->id,
            'ticket_code' => 'T-'.uniqid(),
            'total_bs' => 10,
            'total_usd' => 0,
            'estado' => 'pendiente',
        ]);
    }

    /**
     * @param  array<int, string>  $animales
     * @param  array<string, mixed>|null  $premiosSnapshot
     */
    private function tripletaPara(
        Juego $juego,
        Taquilla $taquilla,
        Ticket $ticket,
        string $fecha,
        string $horaVenta,
        array $animales,
        ?array $premiosSnapshot = null
    ): Apuesta {
        $combinacion = [
            'modalidad' => 'tripleta',
            'selecciones' => array_map(fn (string $animal) => ['animal' => $animal], $animales),
        ];

        $apuesta = Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'ticket_id' => $ticket->id,
            'juego_id' => $juego->id,
            'combinacion' => json_encode($combinacion),
            'amount_bs' => 10,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 10,
            'estado' => 'pendiente',
            'fecha_hora' => $fecha.' '.$horaVenta.':00',
            'sorteo_hora' => $fecha.' '.$horaVenta.':00',
        ]);

        DetalleApuesta::create([
            'apuesta_id' => $apuesta->id,
            'combinacion' => json_encode($combinacion),
            'monto' => 10,
            'premio_ganado' => null,
            'premio_ganado_usd' => null,
            'premios_snapshot' => $premiosSnapshot ?? ($juego->config['premios'] ?? null),
        ]);

        return $apuesta;
    }

    private function resultado(Juego $juego, string $fecha, string $hora, string $animal, int $numero = 1): Resultado
    {
        return Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => $fecha,
            'hora_sorteo' => $hora,
            'numeros_ganadores' => ['numero' => $numero, 'nombre_animal' => $animal],
        ]);
    }

    public function test_tres_figuras_en_tres_sorteos_pagan_200x(): void
    {
        $juego = $this->juego();
        $taquilla = $this->taquilla();
        $ticket = $this->ticket($taquilla);
        $fecha = '2026-09-21';

        $apuesta = $this->tripletaPara($juego, $taquilla, $ticket, $fecha, '08:00', ['Perro', 'Gato', 'León']);

        $service = new ApuestaService;

        $r1 = $this->resultado($juego, $fecha, '09:00', 'Perro', 27);
        $this->assertSame(0, $service->verificarGanadores($r1));
        $this->assertSame('pendiente', $apuesta->refresh()->estado, 'Con una figura sigue pendiente.');

        $r2 = $this->resultado($juego, $fecha, '10:00', 'Gato', 11);
        $this->assertSame(0, $service->verificarGanadores($r2));
        $this->assertSame('pendiente', $apuesta->refresh()->estado, 'Con dos figuras sigue pendiente.');

        $r3 = $this->resultado($juego, $fecha, '11:00', 'León', 5);
        $this->assertSame(1, $service->verificarGanadores($r3));

        $apuesta->refresh();
        $this->assertSame('ganadora', $apuesta->estado);
        $this->assertSame($r3->id, $apuesta->resultado_id);
        $this->assertCount(3, $apuesta->resultado_ids);
        $this->assertSame([$r1->id, $r2->id, $r3->id], $apuesta->resultado_ids);

        $detalle = DetalleApuesta::where('apuesta_id', $apuesta->id)->first();
        $this->assertSame('2000.00', $detalle->premio_ganado, '10 Bs × 200× = 2000.');

        $ticket->refresh();
        $this->assertSame('2000.00', $ticket->premio_total_bs);
        $this->assertSame('ganador', $ticket->estado);

        // Idempotencia: reprocesar el mismo resultado no duplica.
        $this->assertSame(0, $service->verificarGanadores($r3));
        $this->assertSame('2000.00', $ticket->refresh()->premio_total_bs);
    }

    public function test_tripleta_300_del_snapshot_se_conserva_intacta(): void
    {
        // D4: el snapshot manda al liquidar. Un snapshot con `tripleta: 300`
        // (aunque la config vigente diga 200) debe seguir pagando 300× y el
        // valor persistido no puede alterarse.
        $juego = $this->juego();
        $taquilla = $this->taquilla();
        $ticket = $this->ticket($taquilla);
        $fecha = '2026-09-21';

        $snapshot = [
            'base' => 30,
            'modalidades' => ['tripleta' => 300],
            'posicion_triples' => [],
            'comodines' => [],
        ];

        $apuesta = $this->tripletaPara($juego, $taquilla, $ticket, $fecha, '08:00', ['Perro', 'Gato', 'León'], $snapshot);

        $detalle = DetalleApuesta::where('apuesta_id', $apuesta->id)->first();
        $this->assertSame(300, $detalle->premios_snapshot['modalidades']['tripleta'], 'El snapshot conserva tripleta 300.');

        $service = new ApuestaService;
        $service->verificarGanadores($this->resultado($juego, $fecha, '09:00', 'Perro', 27));
        $service->verificarGanadores($this->resultado($juego, $fecha, '10:00', 'Gato', 11));
        $service->verificarGanadores($this->resultado($juego, $fecha, '11:00', 'León', 5));

        $detalle->refresh();
        $this->assertSame('3000.00', $detalle->premio_ganado, '10 Bs × 300× del snapshot.');
        // Comparación por valor: MySQL reordena las claves de los objetos JSON,
        // así que el orden de `premios_snapshot` no es contrato.
        $this->assertEquals($snapshot, $detalle->premios_snapshot, 'El snapshot no cambia al liquidar.');
    }

    public function test_una_o_dos_figuras_no_pagan_y_la_ultima_extraccion_marca_perdida(): void
    {
        $juego = $this->juego();
        $taquilla = $this->taquilla();
        $ticket = $this->ticket($taquilla);
        $fecha = '2026-09-21';

        $apuesta = $this->tripletaPara($juego, $taquilla, $ticket, $fecha, '08:00', ['Perro', 'Gato', 'León']);
        $service = new ApuestaService;

        $this->resultado($juego, $fecha, '09:00', 'Perro', 27);
        $this->resultado($juego, $fecha, '10:00', 'Gato', 11);

        // Cazalotón cierra 19:00: la última extracción no completa la tripleta.
        $ultima = $this->resultado($juego, $fecha, '19:00', 'Camello', 22);
        $service->verificarGanadores($ultima);

        $apuesta->refresh();
        $this->assertSame('perdida', $apuesta->estado);
        $this->assertSame(0.0, (float) DetalleApuesta::where('apuesta_id', $apuesta->id)->first()->premio_ganado);
        $this->assertSame('0.00', $ticket->refresh()->premio_total_bs ?? '0.00');
    }

    public function test_tripleta_con_figuras_repetidas_no_gana(): void
    {
        $juego = $this->juego();
        $taquilla = $this->taquilla();
        $ticket = $this->ticket($taquilla);
        $fecha = '2026-09-21';

        $apuesta = $this->tripletaPara($juego, $taquilla, $ticket, $fecha, '08:00', ['Perro', 'Perro', 'Gato']);

        $r = $this->resultado($juego, $fecha, '09:00', 'Perro', 27);
        (new ApuestaService)->verificarGanadores($r);

        $apuesta->refresh();
        $this->assertSame('pendiente', $apuesta->estado);
        $this->assertNull($apuesta->resultado_id);
    }

    public function test_resultados_de_otro_juego_no_cuentan(): void
    {
        $cazaloton = $this->juego('cazaloton');
        $chaima = $this->juego('loto-chaima');
        $taquilla = $this->taquilla();
        $ticket = $this->ticket($taquilla);
        $fecha = '2026-09-21';

        $apuesta = $this->tripletaPara($cazaloton, $taquilla, $ticket, $fecha, '08:00', ['Perro', 'Gato', 'León']);

        $this->resultado($chaima, $fecha, '09:00', 'Perro', 27);
        $this->resultado($chaima, $fecha, '10:00', 'Gato', 11);
        $this->resultado($chaima, $fecha, '11:00', 'León', 5);

        (new ApuestaService)->verificarGanadores(Resultado::where('juego_id', $chaima->id)->first());

        $apuesta->refresh();
        $this->assertSame('pendiente', $apuesta->estado, 'La tripleta de Cazalotón no usa resultados de Chaima.');
    }

    public function test_pago_de_tripleta_ganadora_usa_la_evidencia(): void
    {
        $juego = $this->juego();
        $taquilla = $this->taquilla();
        $ticket = $this->ticket($taquilla);
        $fecha = '2026-09-21';
        $user = User::where('email', 'super@lotto.com')->firstOrFail();

        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $apuesta = $this->tripletaPara($juego, $taquilla, $ticket, $fecha, '08:00', ['Perro', 'Gato', 'León']);
        $service = new ApuestaService;

        $service->verificarGanadores($this->resultado($juego, $fecha, '09:00', 'Perro', 27));
        $service->verificarGanadores($this->resultado($juego, $fecha, '10:00', 'Gato', 11));
        $service->verificarGanadores($this->resultado($juego, $fecha, '11:00', 'León', 5));

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/pagos', [
            'apuesta_id' => $apuesta->id,
            'tipo' => 'egreso',
            'moneda' => 'bs',
            'amount_bs' => 2000,
            'amount_usd' => 0,
        ]);

        $response->assertStatus(201);
        $this->assertSame('pagada', $apuesta->refresh()->estado);
    }

    public function test_pago_sin_evidencia_se_rechaza(): void
    {
        $juego = $this->juego();
        $taquilla = $this->taquilla();
        $ticket = $this->ticket($taquilla);
        $fecha = '2026-09-21';
        $user = User::where('email', 'super@lotto.com')->firstOrFail();

        $apuesta = $this->tripletaPara($juego, $taquilla, $ticket, $fecha, '08:00', ['Perro', 'Gato', 'León']);
        $resultado = $this->resultado($juego, $fecha, '09:00', 'Perro', 27);

        $apuesta->update([
            'estado' => 'ganadora',
            'resultado_id' => $resultado->id,
            'resultado_ids' => null,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/pagos', [
            'apuesta_id' => $apuesta->id,
            'tipo' => 'egreso',
            'moneda' => 'bs',
            'amount_bs' => 2000,
            'amount_usd' => 0,
        ]);

        $response->assertStatus(422);
        $this->assertSame('ganadora', $apuesta->refresh()->estado);
    }

    public function test_venta_rechaza_tripleta_con_menos_de_tres_sorteos_futuros(): void
    {
        Carbon::setTestNow('2026-09-21 18:00:00');

        try {
            $juego = $this->juego();
            $taquilla = $this->taquilla();
            $user = User::where('email', 'super@lotto.com')->firstOrFail();

            ExchangeRate::create([
                'rate' => 36.50,
                'base_currency' => 'USD',
                'reference_date' => now(),
                'set_by' => $user->id,
                'is_active' => true,
            ]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/3 sorteos futuros/');

            (new ApuestaService)->createApuesta([
                'juego_id' => $juego->id,
                'combinacion' => [
                    'modalidad' => 'tripleta',
                    'selecciones' => [
                        ['animal' => 'Perro'],
                        ['animal' => 'Gato'],
                        ['animal' => 'León'],
                    ],
                ],
                'amount_bs' => 3600,
                'amount_usd' => 0,
            ], $taquilla->id, $user->id);
        } finally {
            Carbon::setTestNow();
        }
    }
}
