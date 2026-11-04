<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\DetalleApuesta;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Pago;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Caso 3 (#84) — ownership del egreso por rol.
 *
 * `PagoController::store` autoriza con `ApuestaPolicy::view` DENTRO del lock y
 * ANTES del estado: taquilla/grupo/banca/master solo su árbol jerárquico;
 * super_master global. Un egreso cross-tenant devuelve 403 y no muta nada.
 * Los rechazos determinísticos (tipo no-egreso) siguen siendo 422 antes de
 * cargar la apuesta.
 */
class PagoEgresoScopeTest extends TestCase
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
        return Juego::where('slug', 'lotto-activo')->firstOrFail();
    }

    /**
     * @return array{0: Banca, 1: Grupo, 2: Taquilla}
     */
    private function jerarquia(?int $masterId = null): array
    {
        $banca = Banca::factory()->create(['master_id' => $masterId]);
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id]);
        $taquilla = Taquilla::factory()->create(['grupo_id' => $grupo->id]);

        return [$banca, $grupo, $taquilla];
    }

    private function usuarioBanca(Banca $banca): User
    {
        return User::factory()->withRole('banca')->create(['banca_id' => $banca->id]);
    }

    private function usuarioGrupo(Grupo $grupo): User
    {
        return User::factory()->withRole('grupo')->create([
            'banca_id' => $grupo->banca_id,
            'grupo_id' => $grupo->id,
        ]);
    }

    private function masterDe(Banca $banca): User
    {
        $master = User::factory()->withRole('master')->create();
        $banca->update(['master_id' => $master->id]);

        return $master;
    }

    private function ticket(Taquilla $taquilla): Ticket
    {
        return Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 10,
            'total_usd' => 0,
            'estado' => 'pendiente',
        ]);
    }

    /**
     * Apuesta ganadora pagable (resultado coincidente + detalle sin premio).
     *
     * @return array{0: Apuesta, 1: Ticket}
     */
    private function apuestaGanadora(Taquilla $taquilla): array
    {
        $juego = $this->juego();
        $ticket = $this->ticket($taquilla);

        $resultado = Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => now(),
            'numeros_ganadores' => ['nombre_animal' => 'Perro', 'numero' => 5],
        ]);

        $apuesta = Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'ticket_id' => $ticket->id,
            'juego_id' => $juego->id,
            'resultado_id' => $resultado->id,
            'combinacion' => ['animal' => 'Perro', 'numero' => 5],
            'amount_bs' => 10,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 10,
            'estado' => 'ganadora',
            'fecha_hora' => now(),
            'sorteo_hora' => now()->addHour(),
        ]);

        DetalleApuesta::create([
            'apuesta_id' => $apuesta->id,
            'combinacion' => ['animal' => 'Perro', 'numero' => 5],
            'monto' => 10,
            'premio_ganado' => null,
            'premio_ganado_usd' => null,
        ]);

        return [$apuesta, $ticket];
    }

    private function pagar(User $user, Apuesta $apuesta, array $extra = [])
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/pagos', array_merge([
                'apuesta_id' => $apuesta->id,
                'tipo' => 'egreso',
                'moneda' => 'bs',
            ], $extra));
    }

    private function assertSinMutacion(Apuesta $apuesta): void
    {
        $this->assertSame('ganadora', $apuesta->fresh()->estado);
        $this->assertSame(0, Pago::where('apuesta_id', $apuesta->id)->where('tipo', 'egreso')->count());
        $this->assertNull($apuesta->fresh()->detalles()->first()?->premio_ganado);
    }

    public function test_banca_paga_apuesta_ajena_403_sin_mutacion(): void
    {
        [$bancaPropia] = $this->jerarquia();
        $bancaUser = $this->usuarioBanca($bancaPropia);

        [, , $taquillaAjena] = $this->jerarquia();
        [$apuestaAjena] = $this->apuestaGanadora($taquillaAjena);

        $this->pagar($bancaUser, $apuestaAjena)
            ->assertForbidden()
            ->assertJsonPath('message', 'No autorizado.');

        $this->assertSinMutacion($apuestaAjena);
    }

    public function test_grupo_paga_apuesta_ajena_403_sin_mutacion(): void
    {
        [, $grupoPropio] = $this->jerarquia();
        $grupoUser = $this->usuarioGrupo($grupoPropio);

        [, , $taquillaAjena] = $this->jerarquia();
        [$apuestaAjena] = $this->apuestaGanadora($taquillaAjena);

        $this->pagar($grupoUser, $apuestaAjena)->assertForbidden();

        $this->assertSinMutacion($apuestaAjena);
    }

    public function test_master_paga_apuesta_ajena_403_sin_mutacion(): void
    {
        [$bancaPropia] = $this->jerarquia();
        $master = $this->masterDe($bancaPropia);

        [, , $taquillaAjena] = $this->jerarquia();
        [$apuestaAjena] = $this->apuestaGanadora($taquillaAjena);

        $this->pagar($master, $apuestaAjena)->assertForbidden();

        $this->assertSinMutacion($apuestaAjena);
    }

    public function test_master_sin_bancas_no_paga_403(): void
    {
        $master = User::factory()->withRole('master')->create();
        [, , $taquilla] = $this->jerarquia();
        [$apuesta] = $this->apuestaGanadora($taquilla);

        $this->pagar($master, $apuesta)->assertForbidden();

        $this->assertSinMutacion($apuesta);
    }

    public function test_banca_paga_apuesta_propia_201(): void
    {
        $bancaPropia = Banca::factory()->create();
        $bancaUser = $this->usuarioBanca($bancaPropia);

        // La apuesta debe colgar de la banca del usuario.
        $grupoPropio = Grupo::factory()->create(['banca_id' => $bancaPropia->id]);
        $taquillaPropia = Taquilla::factory()->create(['grupo_id' => $grupoPropio->id]);
        [$apuesta] = $this->apuestaGanadora($taquillaPropia);

        $this->pagar($bancaUser, $apuesta)->assertCreated();

        $this->assertSame('pagada', $apuesta->fresh()->estado);
        $this->assertSame(
            1,
            Pago::where('apuesta_id', $apuesta->id)->where('tipo', 'egreso')->count()
        );
        $this->assertEquals(300.00, (float) $apuesta->fresh()->detalles()->first()->premio_ganado);
    }

    public function test_tipo_no_egreso_devuelve_422_antes_de_autorizar(): void
    {
        [$bancaPropia] = $this->jerarquia();
        $bancaUser = $this->usuarioBanca($bancaPropia);

        [, , $taquillaAjena] = $this->jerarquia();
        [$apuestaAjena] = $this->apuestaGanadora($taquillaAjena);

        // Cross-tenant + tipo inválido: gana el 422 determinístico.
        $this->pagar($bancaUser, $apuestaAjena, ['tipo' => 'ingreso'])->assertStatus(422);
        $this->pagar($bancaUser, $apuestaAjena, ['tipo' => 'devolucion'])->assertStatus(422);

        $this->assertSinMutacion($apuestaAjena);
    }

    public function test_doble_egreso_devuelve_422_y_una_sola_fila(): void
    {
        $bancaPropia = Banca::factory()->create();
        $bancaUser = $this->usuarioBanca($bancaPropia);
        $grupoPropio = Grupo::factory()->create(['banca_id' => $bancaPropia->id]);
        $taquilla = Taquilla::factory()->create(['grupo_id' => $grupoPropio->id]);
        [$apuesta] = $this->apuestaGanadora($taquilla);

        $this->pagar($bancaUser, $apuesta)->assertCreated();

        $this->pagar($bancaUser, $apuesta)
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede pagar una apuesta pagada.');

        $this->assertSame(
            1,
            Pago::where('apuesta_id', $apuesta->id)->where('tipo', 'egreso')->count()
        );
    }
}
