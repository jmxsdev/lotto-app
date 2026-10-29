<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\ExchangeRate;
use App\Models\Juego;
use App\Models\Taquilla;
use App\Models\User;
use App\Services\JuegoPluginManager;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TQ-05a (S5) — Venta de modalidades single-draw (contrato §2.1,
 * spec "Modalidades single-draw"): `POST /api/v1/tickets` acepta `punta`,
 * `terminal`, `uña`, `aproximacion`, `signo_terminal`, `signo_solo`,
 * `arrimao` y `pegadito` → 201 y persiste la combinación en
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
        // Fecha fija para que la tripleta (jugada de día) siempre tenga 3+
        // sorteos futuros y el test no dependa de la hora real de ejecución.
        Carbon::setTestNow('2026-09-21 08:00:00');
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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
     * La modalidad crea el ticket (201) y persiste la combinación. El monto
     * posible ya no se calcula: el contrato de venta no expone premio posible.
     *
     * @param  array<string, mixed>  $combinacion
     */
    private function assertVentaModalidad(string $slug, array $combinacion, float $multiplicadorLegacy = 0): void
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
        $this->assertEquals($combinacion, $detalle->combinacion, "[{$slug}] la combinación se persiste tal cual.");
    }

    // ---------------- WU-1: whitelist total en la venta ----------------

    public function test_triple_a_zulia_crea_ticket(): void
    {
        // `triple_a` NO vive en premios.modalidades de Zulia: es el producto
        // base y debe venderse igual.
        $this->assertVentaModalidad('triple-zulia', ['tipo' => 'triple_a', 'numero' => '452']);
    }

    public function test_triple_b_trio_activo_crea_ticket(): void
    {
        // Triangulación: otro juego cuyo catálogo tampoco declara triple_b.
        $this->assertVentaModalidad('trio-activo', ['tipo' => 'triple_b', 'numero' => '731']);
    }

    public function test_tipo_base_se_rechaza(): void
    {
        $response = $this->postLinea('triple-zulia', ['tipo' => 'base', 'numero' => '452']);

        $response->assertStatus(422);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('apuestas', 0);
    }

    public function test_modalidad_dupleta_se_rechaza(): void
    {
        // La modalidad declarada también pasa por el whitelist: `dupleta` no
        // está configurada, aunque la forma de las selecciones sea vendible.
        $response = $this->postLinea('triple-chance', [
            'modalidad' => 'dupleta',
            'selecciones' => [
                ['tipo' => 'punta', 'numero' => '75'],
                ['tipo' => 'punta', 'numero' => '14'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('apuestas', 0);
    }

    public function test_una_se_normaliza_a_una_con_tilde_al_persistir(): void
    {
        $response = $this->postLinea('triple-zamorano', ['tipo' => 'una', 'numero' => '2', 'triple' => 'a']);

        $response->assertStatus(201);

        $detalle = Apuesta::first()?->detalles()->first();
        $this->assertNotNull($detalle);
        $this->assertSame('uña', $detalle->combinacion['tipo']);
        $this->assertSame('2', $detalle->combinacion['numero']);
        $this->assertSame('a', $detalle->combinacion['triple']);
    }

    // ---------------- 201 por modalidad single-draw (contrato §2.1) ----------------

    public function test_punta_trio_activo_crea_ticket(): void
    {
        $this->assertVentaModalidad('trio-activo', ['tipo' => 'punta', 'numero' => '45', 'triple' => 'a'], 60);
    }

    public function test_terminal_trio_activo_crea_ticket(): void
    {
        $this->assertVentaModalidad('trio-activo', ['tipo' => 'terminal', 'numero' => '52', 'triple' => 'a'], 60);
    }

    public function test_una_zamorano_crea_ticket(): void
    {
        $this->assertVentaModalidad('triple-zamorano', ['tipo' => 'uña', 'numero' => '2', 'triple' => 'a'], 5);
    }

    // ---------------- WU-2: posicional atada al triple vendido ----------------

    public function test_posicional_sin_triple_se_rechaza(): void
    {
        // D3: sin `triple` no hay default; el plugin rechaza la venta.
        $response = $this->postLinea('triple-zulia', ['tipo' => 'terminal', 'numero' => '62']);

        $response->assertStatus(422);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('apuestas', 0);
    }

    public function test_posicional_con_slot_no_habilitado_se_rechaza(): void
    {
        // Zamorano habilita uña en A y C: el slot B no existe para el juego.
        $response = $this->postLinea('triple-zamorano', ['tipo' => 'uña', 'numero' => '2', 'triple' => 'b']);

        $response->assertStatus(422);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('apuestas', 0);
    }

    public function test_posicional_en_tachira_se_rechaza(): void
    {
        // Táchira no tiene posicional (`posicion_triples: []`).
        $response = $this->postLinea('triple-tachira', ['tipo' => 'terminal', 'numero' => '62', 'triple' => 'a']);

        $response->assertStatus(422);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('apuestas', 0);
    }

    public function test_posicional_con_triple_habilitado_crea_ticket(): void
    {
        $this->assertVentaModalidad('triple-zulia', ['tipo' => 'terminal', 'numero' => '62', 'triple' => 'a']);
    }

    public function test_signo_terminal_sin_triple_crea_ticket_y_liquida_contra_triple_c(): void
    {
        // signo_terminal NO exige `triple`: su referencia es `triple_c`.
        $this->assertVentaModalidad('triple-zulia', ['tipo' => 'signo_terminal', 'numero' => '59', 'signo' => 'LEO'], 600);

        $apuesta = Apuesta::first();
        $juego = Juego::where('slug', 'triple-zulia')->firstOrFail();
        $manager = app(JuegoPluginManager::class);

        // 59 es la terminal de triple_a (159) pero no la de triple_c (408).
        $perdida = $manager->calcularPremio(
            $juego,
            ['combinacion' => $apuesta->combinacion, 'amount_bs' => 10, 'amount_usd' => 0],
            ['numeros_ganadores' => ['triple_a' => '159', 'triple_b' => '222', 'triple_c' => '408', 'signo' => 'LEO']]
        );
        $this->assertSame(0.0, $perdida['premio_bs'], 'No liquida contra triple_a.');

        $ganada = $manager->calcularPremio(
            $juego,
            ['combinacion' => $apuesta->combinacion, 'amount_bs' => 10, 'amount_usd' => 0],
            ['numeros_ganadores' => ['triple_a' => '111', 'triple_b' => '222', 'triple_c' => '259', 'signo' => 'LEO']]
        );
        $this->assertSame(6000.0, $ganada['premio_bs'], '10 Bs × 600× contra triple_c.');
    }

    public function test_aproximacion_triple_facil_crea_ticket(): void
    {
        $this->assertVentaModalidad('triple-facil', ['tipo' => 'aproximacion', 'numero' => '51'], 10);
    }

    public function test_signo_terminal_zulia_crea_ticket(): void
    {
        $this->assertVentaModalidad('triple-zulia', ['tipo' => 'signo_terminal', 'numero' => '59', 'signo' => 'LEO'], 600);
    }

    public function test_signo_solo_chance_crea_ticket(): void
    {
        $this->assertVentaModalidad('triple-chance', ['tipo' => 'signo_solo', 'signo' => 'LEO'], 6);
    }

    public function test_arrimao_arrejuntado_crea_ticket(): void
    {
        $this->assertVentaModalidad('el-arrejuntado', ['tipo' => 'arrimao', 'numero' => '1825'], 6000);
    }

    public function test_pegadito_arrejuntado_crea_ticket(): void
    {
        $this->assertVentaModalidad('el-arrejuntado', ['tipo' => 'pegadito', 'numero' => '10503'], 60000);
    }

    // ---------------- Triangulación: dígitos sin padding (contrato §2.1 nota) ----------------

    public function test_numero_sin_padding_se_acepta_y_el_motor_normaliza(): void
    {
        // "5" → el motor normaliza a "05" (2 cifras para punta); el premio
        // posible no depende del padding (mismo multiplicador).
        $this->assertVentaModalidad('trio-activo', ['tipo' => 'punta', 'numero' => '5', 'triple' => 'a'], 60);
    }

    // ---------------- 422 por dígitos inválidos (spec: "Dígitos inválidos rechazados") ----------------

    public function test_punta_con_tres_cifras_se_rechaza(): void
    {
        $response = $this->postLinea('trio-activo', ['tipo' => 'punta', 'numero' => '453', 'triple' => 'a']);

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

    public function test_cruzado_chance_crea_ticket(): void
    {
        $this->assertVentaModalidad('triple-chance', [
            'modalidad' => 'cruzado',
            'selecciones' => [
                ['tipo' => 'punta', 'numero' => '75'],
                ['tipo' => 'punta', 'numero' => '14'],
            ],
        ], 3000);
    }

    public function test_triple_a_b_chance_crea_ticket(): void
    {
        $this->assertVentaModalidad('triple-chance', [
            'modalidad' => 'triple_a_b',
            'selecciones' => [
                ['tipo' => 'triple_a', 'numero' => '756'],
                ['tipo' => 'triple_b', 'numero' => '146'],
            ],
        ], 200000);
    }

    public function test_tripleta_cazaloton_crea_ticket(): void
    {
        $this->assertVentaModalidad('cazaloton', [
            'modalidad' => 'tripleta',
            'selecciones' => [
                ['animal' => 'Perro'],
                ['animal' => 'Gato'],
                ['animal' => 'León'],
            ],
        ], 200);
    }

    public function test_tripleta_loto_chaima_crea_ticket(): void
    {
        $this->assertVentaModalidad('loto-chaima', [
            'modalidad' => 'tripleta',
            'selecciones' => [
                ['animal' => 'Perro'],
                ['animal' => 'Gato'],
                ['animal' => 'León'],
            ],
        ], 50);
    }

    public function test_tripleta_en_selva_se_rechaza_por_modalidad_no_soportada(): void
    {
        $response = $this->postLinea('selva-plus', [
            'modalidad' => 'tripleta',
            'selecciones' => [
                ['animal' => 'Perro'],
                ['animal' => 'Gato'],
                ['animal' => 'León'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('tickets', 0);
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
