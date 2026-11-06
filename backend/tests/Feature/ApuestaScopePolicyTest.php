<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Pago;
use App\Models\Taquilla;
use App\Models\User;
use App\Policies\ApuestaPolicy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Caso 2 (#84) — `ApuestaPolicy::view/delete` acotado para master.
 *
 * super_master conserva alcance global; master solo autoriza apuestas que
 * cuelgan de una banca que administra (`bancas.master_id`). Un master sin
 * bancas queda denegado. `GET/DELETE /api/v1/apuestas/{id}` consumen `view`,
 * por lo que el alcance se ejerce también por HTTP.
 */
class ApuestaScopePolicyTest extends TestCase
{
    use RefreshDatabase;

    private ApuestaPolicy $policy;

    private Juego $juego;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->policy = app(ApuestaPolicy::class);
        $this->juego = Juego::where('slug', 'lotto-activo')->firstOrFail();
    }

    private function master(): User
    {
        return User::factory()->withRole('master')->create();
    }

    private function superMaster(): User
    {
        return User::factory()->withRole('super_master')->create();
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

    private function apuesta(Taquilla $taquilla): Apuesta
    {
        return Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $this->juego->id,
            'combinacion' => ['animal' => 'Perro', 'numero' => 5],
            'amount_bs' => 100,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 100,
            'estado' => 'pendiente',
            'fecha_hora' => now(),
            'sorteo_hora' => now()->addHour(),
        ]);
    }

    public function test_master_ve_y_anula_una_apuesta_de_su_banca(): void
    {
        $master = $this->master();
        [, , $taquilla] = $this->jerarquia($master->id);
        $apuesta = $this->apuesta($taquilla);

        $this->assertTrue($this->policy->view($master, $apuesta));
        $this->assertTrue($this->policy->delete($master, $apuesta));

        $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/apuestas/'.$apuesta->id)
            ->assertOk()
            ->assertJsonPath('data.id', $apuesta->id);

        $this->actingAs($master, 'sanctum')
            ->deleteJson('/api/v1/apuestas/'.$apuesta->id, ['motivo' => 'scope'])
            ->assertOk();

        $this->assertSoftDeleted('apuestas', ['id' => $apuesta->id]);
    }

    public function test_master_no_ve_ni_anula_apuestas_de_banca_ajena(): void
    {
        $master = $this->master();
        $this->jerarquia($master->id);
        [, , $taquillaAjena] = $this->jerarquia();
        $apuestaAjena = $this->apuesta($taquillaAjena);

        $this->assertFalse($this->policy->view($master, $apuestaAjena));
        $this->assertFalse($this->policy->delete($master, $apuestaAjena));

        $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/apuestas/'.$apuestaAjena->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'No autorizado.');

        $this->actingAs($master, 'sanctum')
            ->deleteJson('/api/v1/apuestas/'.$apuestaAjena->id, ['motivo' => 'prueba'])
            ->assertForbidden()
            ->assertJsonPath('message', 'No autorizado.');

        $this->assertNotSoftDeleted('apuestas', ['id' => $apuestaAjena->id]);
        $this->assertSame('pendiente', $apuestaAjena->fresh()->estado);
        $this->assertSame(
            0,
            Pago::where('apuesta_id', $apuestaAjena->id)->where('tipo', 'devolucion')->count()
        );
    }

    public function test_master_sin_bancas_queda_denegado(): void
    {
        $master = $this->master();
        [, , $taquilla] = $this->jerarquia();
        $apuesta = $this->apuesta($taquilla);

        $this->assertFalse($this->policy->view($master, $apuesta));
        $this->assertFalse($this->policy->delete($master, $apuesta));

        $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/apuestas/'.$apuesta->id)
            ->assertForbidden();
    }

    public function test_super_master_conserva_alcance_global(): void
    {
        $super = $this->superMaster();
        [, , $taquilla] = $this->jerarquia();
        $apuesta = $this->apuesta($taquilla);

        $this->assertTrue($this->policy->view($super, $apuesta));
        $this->assertTrue($this->policy->delete($super, $apuesta));

        $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/apuestas/'.$apuesta->id)
            ->assertOk()
            ->assertJsonPath('data.id', $apuesta->id);
    }

    public function test_apuesta_sin_cadena_de_taquilla_deniega_al_master(): void
    {
        $master = $this->master();

        $huerfana = new Apuesta(['taquilla_id' => 999999]);

        $this->assertFalse($this->policy->view($master, $huerfana));
    }
}
