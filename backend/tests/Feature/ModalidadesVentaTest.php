<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\ExchangeRate;
use App\Models\Juego;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TQ-05a (S5) — Venta de modalidades single-draw (contrato §2.1,
 * spec "Modalidades single-draw"): `POST /api/v1/tickets` acepta `punta`,
 * `terminal`, `uña`, `aproximacion`, `signo_terminal`, `signo_solo`,
 * `arrimao` y `pegadito` → 201 con `premio_posible > 0` persistido en
 * `detalle_apuestas`; dígitos inválidos → 422.
 *
 * Gate OQ1 (design §9): `Tripletas::validarApuesta` solo aceptaba
 * `triple_a|b|c` y las reglas del Request solo admitían esos tipos, por lo
 * que hoy estos shapes dan 422 aunque el motor ya los soporta.
 *
 * Patrón de escenario: ApuestaSorteoPasadoTest (taquilla sembrada + tasa
 * activa + Sanctum + X-Device-MAC). Juegos del DatabaseSeeder con sus
 * premios oficiales (PremiosOficiales::configPara).
 */
class ModalidadesVentaTest extends TestCase
{
    use RefreshDatabase;

    /** Monto mínimo efectivo de la banca sembrada (3600 Bs, seeders). */
    private const MONTO_MINIMO_BS = 3600.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);
    }

    private function crearTaquillaConUsuario(): array
    {
        $taquilla = Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        return [$taquilla, $taquillaUser];
    }

    private function crearTasaActiva(): void
    {
        $user = User::where('email', 'super@lotto.com')->first();

        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);
    }

    /**
     * POST /api/v1/tickets con UNA línea single-draw del juego dado.
     *
     * @param  array<string, mixed>  $combinacion
     */
    private function postLinea(string $slug, array $combinacion, float $montoBs = self::MONTO_MINIMO_BS): TestResponse
    {
        $this->crearTasaActiva();

        $juego = Juego::where('slug', $slug)->first();
        [, $taquillaUser] = $this->crearTaquillaConUsuario();

        return $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/v1/tickets', [
                'lines' => [
                    [
                        'juego_id' => $juego->id,
                        'combinacion' => $combinacion,
                        'amount_bs' => $montoBs,
                        'amount_usd' => 0,
                        'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
                    ],
                ],
            ]);
    }

    /**
     * La modalidad single-draw crea el ticket (201) y persiste un
     * `premio_posible` = monto × multiplicador (contrato §2.1: el front NO
     * hardcodea; el motor decide desde `config.premios`).
     *
     * @param  array<string, mixed>  $combinacion
     */
    private function assertPremioPosible(string $slug, array $combinacion, float $multiplicador): void
    {
        $response = $this->postLinea($slug, $combinacion);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Ticket creado exitosamente.')
            ->assertJsonPath('data.apuestas.0.juego.slug', $slug);

        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('apuestas', 1);

        $apuesta = Apuesta::where('juego_id', Juego::where('slug', $slug)->first()->id)->first();
        $this->assertNotNull($apuesta);

        $detalle = $apuesta->detalles()->first();
        $this->assertNotNull($detalle);
        $this->assertGreaterThan(
            0,
            $detalle->premio_posible,
            "[{$slug}] premio_posible debe ser > 0 para la modalidad single-draw."
        );
        $this->assertEqualsWithDelta(
            self::MONTO_MINIMO_BS * $multiplicador,
            (float) $detalle->premio_posible,
            0.01,
            "[{$slug}] premio_posible = monto × {$multiplicador}× (motor, no front)."
        );
    }

    // ---------------- 201 por modalidad single-draw (contrato §2.1) ----------------

    public function test_punta_trio_activo_crea_ticket_con_premio_posible(): void
    {
        $this->assertPremioPosible('trio-activo', ['tipo' => 'punta', 'numero' => '45'], 60);
    }

    public function test_terminal_trio_activo_crea_ticket_con_premio_posible(): void
    {
        $this->assertPremioPosible('trio-activo', ['tipo' => 'terminal', 'numero' => '52'], 60);
    }

    public function test_una_zamorano_crea_ticket_con_premio_posible(): void
    {
        $this->assertPremioPosible('triple-zamorano', ['tipo' => 'uña', 'numero' => '2'], 5);
    }

    public function test_aproximacion_triple_facil_crea_ticket_con_premio_posible(): void
    {
        $this->assertPremioPosible('triple-facil', ['tipo' => 'aproximacion', 'numero' => '51'], 10);
    }

    public function test_signo_terminal_zulia_crea_ticket_con_premio_posible(): void
    {
        $this->assertPremioPosible('triple-zulia', ['tipo' => 'signo_terminal', 'numero' => '59', 'signo' => 'LEO'], 600);
    }

    public function test_signo_solo_chance_crea_ticket_con_premio_posible(): void
    {
        $this->assertPremioPosible('triple-chance', ['tipo' => 'signo_solo', 'signo' => 'LEO'], 6);
    }

    public function test_arrimao_arrejuntado_crea_ticket_con_premio_posible(): void
    {
        $this->assertPremioPosible('el-arrejuntado', ['tipo' => 'arrimao', 'numero' => '1825'], 6000);
    }

    public function test_pegadito_arrejuntado_crea_ticket_con_premio_posible(): void
    {
        $this->assertPremioPosible('el-arrejuntado', ['tipo' => 'pegadito', 'numero' => '10503'], 60000);
    }

    // ---------------- Triangulación: dígitos sin padding (contrato §2.1 nota) ----------------

    public function test_numero_sin_padding_se_acepta_y_el_motor_normaliza(): void
    {
        // "5" → el motor normaliza a "05" (2 cifras para punta); el premio
        // posible no depende del padding (mismo multiplicador).
        $this->assertPremioPosible('trio-activo', ['tipo' => 'punta', 'numero' => '5'], 60);
    }

    // ---------------- 422 por dígitos inválidos (spec: "Dígitos inválidos rechazados") ----------------

    public function test_punta_con_tres_cifras_se_rechaza(): void
    {
        $response = $this->postLinea('trio-activo', ['tipo' => 'punta', 'numero' => '453']);

        $response->assertStatus(422);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('apuestas', 0);
    }

    public function test_arrimao_con_mas_de_cuatro_cifras_se_rechaza(): void
    {
        // Contrato §2.1 nota: los números viajan sin padding y el motor
        // normaliza (1..N cifras); un arrimao con 5 cifras excede el tope 4.
        $response = $this->postLinea('el-arrejuntado', ['tipo' => 'arrimao', 'numero' => '18255']);

        $response->assertStatus(422);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('apuestas', 0);
    }

    public function test_pegadito_con_caracteres_no_numericos_se_rechaza(): void
    {
        $response = $this->postLinea('el-arrejuntado', ['tipo' => 'pegadito', 'numero' => '10x03']);

        $response->assertStatus(422);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('apuestas', 0);
    }

    public function test_signo_terminal_sin_signo_se_rechaza(): void
    {
        $response = $this->postLinea('triple-zulia', ['tipo' => 'signo_terminal', 'numero' => '59']);

        $response->assertStatus(422);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('apuestas', 0);
    }

    // ---------------- S6: multi-selección same-draw (contrato §2.2, design §4) ----------------

    public function test_cruzado_chance_crea_ticket_con_premio_posible(): void
    {
        $this->assertPremioPosible('triple-chance', [
            'modalidad' => 'cruzado',
            'selecciones' => [
                ['tipo' => 'punta', 'numero' => '75'],
                ['tipo' => 'punta', 'numero' => '14'],
            ],
        ], 3000);
    }

    public function test_triple_a_b_chance_crea_ticket_con_premio_posible(): void
    {
        $this->assertPremioPosible('triple-chance', [
            'modalidad' => 'triple_a_b',
            'selecciones' => [
                ['tipo' => 'triple_a', 'numero' => '756'],
                ['tipo' => 'triple_b', 'numero' => '146'],
            ],
        ], 200000);
    }

    public function test_tripleta_cazaloton_crea_ticket_con_premio_posible(): void
    {
        $this->assertPremioPosible('cazaloton', [
            'modalidad' => 'tripleta',
            'selecciones' => [
                ['animal' => 'Perro'],
                ['animal' => 'Gato'],
                ['animal' => 'León'],
            ],
        ], 200);
    }

    public function test_tripleta_loto_chaima_crea_ticket_con_premio_posible(): void
    {
        $this->assertPremioPosible('loto-chaima', [
            'modalidad' => 'tripleta',
            'selecciones' => [
                ['animal' => 'Perro'],
                ['animal' => 'Gato'],
                ['animal' => 'León'],
            ],
        ], 50);
    }

    public function test_cruzado_con_punta_de_tres_cifras_se_rechaza(): void
    {
        $response = $this->postLinea('triple-chance', [
            'modalidad' => 'cruzado',
            'selecciones' => [
                ['tipo' => 'punta', 'numero' => '753'],
                ['tipo' => 'punta', 'numero' => '14'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('apuestas', 0);
    }

    public function test_triple_a_b_con_triple_de_dos_cifras_se_rechaza(): void
    {
        $response = $this->postLinea('triple-chance', [
            'modalidad' => 'triple_a_b',
            'selecciones' => [
                ['tipo' => 'triple_a', 'numero' => '75'],
                ['tipo' => 'triple_b', 'numero' => '146'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('apuestas', 0);
    }

    public function test_tripleta_con_dos_selecciones_se_rechaza(): void
    {
        $response = $this->postLinea('cazaloton', [
            'modalidad' => 'tripleta',
            'selecciones' => [
                ['animal' => 'Perro'],
                ['animal' => 'Gato'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('apuestas', 0);
    }

    public function test_tripleta_con_animal_invalido_se_rechaza(): void
    {
        $response = $this->postLinea('cazaloton', [
            'modalidad' => 'tripleta',
            'selecciones' => [
                ['animal' => 'Perro'],
                ['animal' => 'Gato'],
                ['animal' => 'Dragón'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('apuestas', 0);
    }
}
