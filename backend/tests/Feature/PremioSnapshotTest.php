<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\DetalleApuesta;
use App\Models\ExchangeRate;
use App\Models\Juego;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\User;
use App\Services\ApuestaService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * S2 — Snapshot de premios por apuesta (design D4; spec configuracion-premios
 * REQ "Snapshot de premios por apuesta (sin retroactividad)" + deltas
 * motor-premios "Liquidación contra snapshot" / "Pago contra snapshot").
 *
 * La venta persiste `config.premios` vigente en `detalle_apuestas.premios_snapshot`;
 * una edición posterior de premios (PUT /juegos/{id}/premios, S1a) NO altera las
 * apuestas ya vendidas: la liquidación (`verificarGanadores`) y el pago
 * (`PagoController`) resuelven contra el snapshot. Apuestas sin snapshot (vendidas
 * antes de la feature) usan el `config.premios` actual como fallback legacy.
 */
class PremioSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function juego(): Juego
    {
        return Juego::where('slug', 'monje-millonario')->firstOrFail();
    }

    private function superUser(): User
    {
        return User::where('email', 'super@lotto.com')->firstOrFail();
    }

    private function fechaSorteoFutura(): string
    {
        return now()->addDays(3)->toDateString();
    }

    private function tasaActiva(): void
    {
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $this->superUser()->id,
            'is_active' => true,
        ]);
    }

    /**
     * Taquilla con usuario cajero (role taquilla + MAC) para el pago, como
     * PagoPremioSinMontosTest.
     *
     * @return array{0: Taquilla, 1: User}
     */
    private function crearTaquillaUsuario(): array
    {
        $taquilla = Taquilla::factory()->create();
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $user = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $user->assignRole('taquilla');

        return [$taquilla, $user];
    }

    /**
     * Vende una apuesta por el camino real (ApuestaService::createApuesta) con
     * sorteo futuro fijo compartido con el resultado de los tests.
     *
     * @param  array<string, mixed>  $combinacion
     */
    private function vender(Juego $juego, array $combinacion, Taquilla $taquilla, float $bs = 10.0): Apuesta
    {
        $this->tasaActiva();

        return (new ApuestaService)->createApuesta([
            'juego_id' => $juego->id,
            'combinacion' => $combinacion,
            'amount_bs' => $bs,
            'amount_usd' => 0,
            'sorteo_hora' => $this->fechaSorteoFutura().' 13:00:00',
        ], $taquilla->id, $this->superUser()->id);
    }

    /**
     * Edita los premios del juego por el endpoint de S1a (PUT /premios).
     *
     * @param  array<string, mixed>  $premios
     */
    private function editarPremios(Juego $juego, array $premios): TestResponse
    {
        return $this->actingAs($this->superUser(), 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}/premios", $premios);
    }

    /**
     * Crea el resultado del sorteo futuro compartido (fecha fija + 13:00).
     *
     * @param  array<string, mixed>  $numerosGanadores
     */
    private function resultadoDe(Juego $juego, array $numerosGanadores): Resultado
    {
        return Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => $this->fechaSorteoFutura(),
            'hora_sorteo' => '13:00',
            'numeros_ganadores' => $numerosGanadores,
        ]);
    }

    /**
     * Paga el premio de una apuesta como cajero de su misma taquilla.
     *
     * @param  array<string, mixed>  $extra
     */
    private function pagar(Apuesta $apuesta, User $user, array $extra = []): TestResponse
    {
        return $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($user, 'sanctum')
            ->postJson('/api/v1/pagos', array_merge([
                'apuesta_id' => $apuesta->id,
                'tipo' => 'egreso',
                'moneda' => 'bs',
            ], $extra));
    }

    // ==================================================
    // Snapshot persistido al vender (REQ snapshot)
    // ==================================================

    public function test_venta_persiste_snapshot_de_premios_en_el_detalle(): void
    {
        [$taquilla] = $this->crearTaquillaUsuario();
        $juego = $this->juego();

        $apuesta = $this->vender($juego, ['animal' => 'Tucán', 'numero' => 42], $taquilla);

        $detalle = DetalleApuesta::where('apuesta_id', $apuesta->id)->firstOrFail();

        $this->assertEqualsCanonicalizing(
            $juego->config['premios'],
            $detalle->premios_snapshot,
            'Al vender se persiste config.premios vigente como snapshot del detalle.'
        );
        $this->assertSame(50, $detalle->premios_snapshot['base'], 'monje-millonario: base oficial 50×.');
    }

    // ==================================================
    // Edición posterior no altera apuestas vendidas (REQ snapshot)
    // ==================================================

    public function test_edicion_posterior_no_altera_la_liquidacion_de_la_apuesta_vendida(): void
    {
        [$taquilla] = $this->crearTaquillaUsuario();
        $juego = $this->juego();

        // Venta con base 50× (snapshot).
        $apuesta = $this->vender($juego, ['animal' => 'Tucán', 'numero' => 42], $taquilla);
        $this->assertSame(50, $apuesta->detalles()->first()->premios_snapshot['base']);

        // Edición posterior vía endpoint S1a: base 50 → 60, sin comodines.
        $this->editarPremios($juego, ['base' => 60, 'modalidades' => [], 'comodines' => []])
            ->assertStatus(200);
        $this->assertSame(60, $juego->fresh()->config['premios']['base']);

        $resultado = $this->resultadoDe($juego, ['numero' => 42, 'nombre_animal' => 'Tucán']);
        $ganadoras = (new ApuestaService)->verificarGanadores($resultado);
        $this->assertSame(1, $ganadoras);

        $detalle = $apuesta->detalles()->first();
        $this->assertSame(
            '500.00',
            $detalle->premio_ganado,
            'Liquida 10 Bs × 50× (snapshot), NO 60× (config actual editado).'
        );
    }

    // ==================================================
    // Pago usa el snapshot (REQ pago + delta motor-premios)
    // ==================================================

    public function test_pago_valida_contra_el_snapshot_y_rechaza_el_monto_del_config_nuevo(): void
    {
        [$taquilla, $user] = $this->crearTaquillaUsuario();
        $juego = $this->juego();

        $apuesta = $this->vender($juego, ['animal' => 'Tucán', 'numero' => 42], $taquilla);
        $this->editarPremios($juego, ['base' => 60, 'modalidades' => [], 'comodines' => []])
            ->assertStatus(200);

        $resultado = $this->resultadoDe($juego, ['numero' => 42, 'nombre_animal' => 'Tucán']);
        (new ApuestaService)->verificarGanadores($resultado);
        $this->assertSame('ganadora', $apuesta->fresh()->estado);

        // Monto del config NUEVO (60× = 600) → 422: el snapshot manda (50× = 500).
        $rechazo = $this->pagar($apuesta, $user, ['amount_bs' => 600, 'amount_usd' => 0]);
        $rechazo->assertStatus(422);
        $this->assertEquals(500.0, $rechazo->json('premio_esperado_bs'), 'Espera el premio del snapshot, no del config actual.');

        // Sin montos: el backend aplica el premio del snapshot (500).
        $pago = $this->pagar($apuesta, $user);
        $pago->assertStatus(201);
        $this->assertEquals(500.0, $pago->json('premio.premio_bs'));
        $this->assertSame('pagada', $apuesta->fresh()->estado);
    }

    // ==================================================
    // Fallback legacy sin snapshot (REQ snapshot + delta motor-premios)
    // ==================================================

    public function test_fallback_legacy_sin_snapshot_usa_el_config_actual(): void
    {
        [$taquilla, $user] = $this->crearTaquillaUsuario();
        $juego = $this->juego();

        // Apuesta legacy: creada directa sin premios_snapshot (NULL), como las
        // vendidas antes de la feature.
        $apuesta = Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'combinacion' => json_encode(['animal' => 'Tucán', 'numero' => 42]),
            'amount_bs' => 10,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 10,
            'estado' => 'pendiente',
            'fecha_hora' => $this->fechaSorteoFutura().' 13:00:00',
            'sorteo_hora' => $this->fechaSorteoFutura().' 13:00:00',
        ]);

        DetalleApuesta::create([
            'apuesta_id' => $apuesta->id,
            'combinacion' => json_encode(['animal' => 'Tucán', 'numero' => 42]),
            'monto' => 10,
            'premio_posible' => 500,
            'premio_posible_usd' => 0,
        ]);
        $this->assertNull($apuesta->detalles()->first()->premios_snapshot, 'Legacy: sin snapshot.');

        // Edición posterior: el config actual pasa a 60×.
        $this->editarPremios($juego, ['base' => 60, 'modalidades' => [], 'comodines' => []])
            ->assertStatus(200);

        $resultado = $this->resultadoDe($juego, ['numero' => 42, 'nombre_animal' => 'Tucán']);
        $ganadoras = (new ApuestaService)->verificarGanadores($resultado);
        $this->assertSame(1, $ganadoras);
        $this->assertSame(
            '600.00',
            $apuesta->detalles()->first()->premio_ganado,
            'Legacy sin snapshot: 10 Bs × 60× (config actual).'
        );

        // Pago legacy: valida contra el config actual (600).
        $pago = $this->pagar($apuesta, $user, ['amount_bs' => 600, 'amount_usd' => 0]);
        $pago->assertStatus(201);
        $this->assertSame('pagada', $apuesta->fresh()->estado);
    }

    public function test_fallback_legacy_sin_detalle_paga_sin_romper_el_flujo(): void
    {
        [$taquilla, $user] = $this->crearTaquillaUsuario();
        $juego = $this->juego();

        // Anomalia de datos cubierta por el guard null-safe de PagoController:
        // apuesta liquidable SIN fila de detalle (la invariante createApuesta
        // crea una; el guard no debe romper si falta). La liquidacion persiste
        // por query-builder (no-op sin detalle) y el pago cae al config actual.
        $apuesta = Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'combinacion' => json_encode(['animal' => 'Tucán', 'numero' => 42]),
            'amount_bs' => 10,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 10,
            'estado' => 'pendiente',
            'fecha_hora' => $this->fechaSorteoFutura().' 13:00:00',
            'sorteo_hora' => $this->fechaSorteoFutura().' 13:00:00',
        ]);

        $resultado = $this->resultadoDe($juego, ['numero' => 42, 'nombre_animal' => 'Tucán']);
        $ganadoras = (new ApuestaService)->verificarGanadores($resultado);

        $this->assertSame(1, $ganadoras, 'La apuesta gana aunque no tenga detalle persistido.');
        $this->assertSame('ganadora', $apuesta->fresh()->estado);
        $this->assertNull($apuesta->fresh()->detalles()->first(), 'Escenario: apuesta sin detalle.');

        // Sin montos: el backend aplica el premio del config actual (50× = 500)
        // sin romper por el detalle ausente (fallback legacy).
        $pago = $this->pagar($apuesta, $user);
        $pago->assertStatus(201);
        $this->assertEquals(500.0, $pago->json('premio.premio_bs'));
        $this->assertSame('pagada', $apuesta->fresh()->estado);
    }

    // ==================================================
    // Comodines del snapshot congelados (REQ snapshot, REQ6)
    // ==================================================

    public function test_comodines_del_snapshot_congelados_tras_la_edicion(): void
    {
        [$taquilla] = $this->crearTaquillaUsuario();
        $juego = $this->juego();

        // Venta con snapshot que conserva los comodines vigentes (palabra PATRONUS).
        $apuesta = $this->vender($juego, ['animal' => 'Tucán', 'numero' => 42], $taquilla);
        $this->assertArrayHasKey(
            'patronus-palabra',
            $apuesta->detalles()->first()->premios_snapshot['comodines'],
            'El snapshot al vender incluye el comodín palabra PATRONUS.'
        );

        // Edición posterior: elimina TODOS los comodines y sube la base a 60×.
        $this->editarPremios($juego, ['base' => 60, 'modalidades' => [], 'comodines' => []])
            ->assertStatus(200);
        $this->assertSame([], $juego->fresh()->config['premios']['comodines']);

        // Resultado con la señal del comodín palabra (patronus=true).
        $resultado = $this->resultadoDe($juego, ['numero' => 42, 'nombre_animal' => 'Tucán', 'patronus' => true]);
        (new ApuestaService)->verificarGanadores($resultado);

        $detalle = $apuesta->detalles()->first();
        $this->assertSame(
            '700.00',
            $detalle->premio_ganado,
            'Comodín congelado: base 50 (snapshot) + palabra +20× = 70× → 10 × 70 = 700. '
            .'Si usara el config actual (sin comodines, base 60) pagaría 600.'
        );
    }
}
