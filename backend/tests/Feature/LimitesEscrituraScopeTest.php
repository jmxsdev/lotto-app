<?php

namespace Tests\Feature;

use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WU-3 (casos 6/7) — alcance de ESCRITURA de límites.
 *
 * La banca solo escribe a nivel de su propia banca y dentro de su subárbol
 * (sus grupos y las taquillas de esos grupos). Los datos de otra banca
 * reciben 403, y la autorización precede a la validación de restrictividad:
 * un actor fuera de alcance recibe 403, nunca 422 con datos del padre ajeno.
 */
class LimitesEscrituraScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function bancaUser(): User
    {
        $user = User::where('email', 'banca@lotto.com')->firstOrFail();
        $user->assignRole('banca');

        return $user;
    }

    private function bancaPropia(): Banca
    {
        return Banca::where('code', 'BT001')->firstOrFail();
    }

    private function grupoPropio(): Grupo
    {
        return Grupo::where('code', 'GT001')->firstOrFail();
    }

    private function taquillaPropia(): Taquilla
    {
        return Taquilla::where('code', 'TT001')->firstOrFail();
    }

    private function juego(): Juego
    {
        return Juego::where('slug', 'lotto-activo')->firstOrFail();
    }

    /** @return array{0: Banca, 1: Grupo, 2: Taquilla} */
    private function jerarquiaAjena(): array
    {
        $banca = Banca::factory()->create(['active' => true]);
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $taquilla = Taquilla::factory()->create(['grupo_id' => $grupo->id, 'active' => true]);

        return [$banca, $grupo, $taquilla];
    }

    private function fijarMaximoBanca(Banca $banca, float $maximo): void
    {
        JuegoLimite::updateOrCreate(
            [
                'juego_id' => $this->juego()->id,
                'banca_id' => $banca->id,
                'grupo_id' => null,
                'taquilla_id' => null,
                'moneda' => 'bs',
            ],
            ['limite_maximo' => $maximo]
        );
    }

    // ==================================================
    // ESCRITURA DENTRO DEL SUBÁRBOL PROPIO
    // ==================================================

    public function test_banca_escribe_en_su_propio_grupo_y_taquilla(): void
    {
        $user = $this->bancaUser();
        $banca = $this->bancaPropia();
        $grupo = $this->grupoPropio();
        $taquilla = $this->taquillaPropia();
        $juego = $this->juego();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/limites/'.$juego->id, [
                'banca_id' => $banca->id,
                'grupo_id' => $grupo->id,
                'moneda' => 'bs',
                'limite_maximo' => 1000,
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('juego_limites', [
            'juego_id' => $juego->id,
            'banca_id' => $banca->id,
            'grupo_id' => $grupo->id,
            'taquilla_id' => null,
            'moneda' => 'bs',
            'limite_maximo' => 1000,
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/limites/'.$juego->id, [
                'banca_id' => $banca->id,
                'grupo_id' => $grupo->id,
                'taquilla_id' => $taquilla->id,
                'moneda' => 'bs',
                'limite_maximo' => 900,
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('juego_limites', [
            'juego_id' => $juego->id,
            'banca_id' => $banca->id,
            'grupo_id' => $grupo->id,
            'taquilla_id' => $taquilla->id,
            'moneda' => 'bs',
            'limite_maximo' => 900,
        ]);
    }

    public function test_banca_escribe_en_su_propia_banca(): void
    {
        $user = $this->bancaUser();
        $banca = $this->bancaPropia();
        $juego = $this->juego();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/limites/'.$juego->id, [
                'banca_id' => $banca->id,
                'moneda' => 'bs',
                'limite_maximo' => 5000,
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('juego_limites', [
            'juego_id' => $juego->id,
            'banca_id' => $banca->id,
            'grupo_id' => null,
            'taquilla_id' => null,
            'moneda' => 'bs',
            'limite_maximo' => 5000,
        ]);
    }

    // ==================================================
    // SUBÁRBOL AJENO → 403
    // ==================================================

    public function test_banca_con_grupo_de_otra_banca_403(): void
    {
        [, $otroGrupo] = $this->jerarquiaAjena();

        $this->actingAs($this->bancaUser(), 'sanctum')
            ->putJson('/api/v1/limites/'.$this->juego()->id, [
                'banca_id' => $this->bancaPropia()->id,
                'grupo_id' => $otroGrupo->id,
                'moneda' => 'bs',
                'limite_maximo' => 1234,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('juego_limites', [
            'juego_id' => $this->juego()->id,
            'grupo_id' => $otroGrupo->id,
            'moneda' => 'bs',
            'limite_maximo' => 1234,
        ]);
    }

    public function test_banca_sobre_otra_banca_403(): void
    {
        [$otraBanca] = $this->jerarquiaAjena();

        $this->actingAs($this->bancaUser(), 'sanctum')
            ->putJson('/api/v1/limites/'.$this->juego()->id, [
                'banca_id' => $otraBanca->id,
                'moneda' => 'bs',
                'limite_maximo' => 1234,
            ])
            ->assertForbidden();
    }

    public function test_banca_con_taquilla_de_otro_grupo_403(): void
    {
        $user = $this->bancaUser();
        $banca = $this->bancaPropia();
        $grupo = $this->grupoPropio();
        $juego = $this->juego();

        // Misma banca, pero la taquilla cuelga de OTRO grupo.
        $otroGrupoPropio = Grupo::factory()->create(['banca_id' => $banca->id, 'active' => true]);
        $taquillaOtroGrupo = Taquilla::factory()->create([
            'grupo_id' => $otroGrupoPropio->id,
            'active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/limites/'.$juego->id, [
                'banca_id' => $banca->id,
                'grupo_id' => $grupo->id,
                'taquilla_id' => $taquillaOtroGrupo->id,
                'moneda' => 'bs',
                'limite_maximo' => 900,
            ])
            ->assertForbidden();

        // Taquilla de otra banca (aunque se indique el grupo correcto).
        [, $grupoAjeno, $taquillaAjena] = $this->jerarquiaAjena();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/limites/'.$juego->id, [
                'banca_id' => $banca->id,
                'grupo_id' => $grupoAjeno->id,
                'taquilla_id' => $taquillaAjena->id,
                'moneda' => 'bs',
                'limite_maximo' => 900,
            ])
            ->assertForbidden();
    }

    public function test_batch_rechaza_item_de_otra_banca_sin_persistir(): void
    {
        [$otraBanca] = $this->jerarquiaAjena();
        $banca = $this->bancaPropia();
        $juego = $this->juego();

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->postJson('/api/v1/limites/batch', [
                'limites' => [
                    [
                        'juego_id' => $juego->id,
                        'banca_id' => $banca->id,
                        'moneda' => 'bs',
                        'limite_maximo' => 321,
                    ],
                    [
                        'juego_id' => $juego->id,
                        'banca_id' => $otraBanca->id,
                        'moneda' => 'bs',
                        // Tope inválido para animalitos (16%): si la
                        // autorización no precediera, devolvería 422.
                        'porcentaje_pago' => 50,
                    ],
                ],
            ]);

        $response->assertForbidden();

        // 403 antes de la transacción: ni siquiera el ítem propio se escribe.
        $this->assertDatabaseMissing('juego_limites', [
            'juego_id' => $juego->id,
            'banca_id' => $banca->id,
            'grupo_id' => null,
            'taquilla_id' => null,
            'moneda' => 'bs',
            'limite_maximo' => 321,
        ]);
    }

    public function test_actor_fuera_de_alcance_con_restrictividad_invalida_403_no_422(): void
    {
        [$otraBanca, $otroGrupo] = $this->jerarquiaAjena();
        // El padre ajeno existe con max 500: el payload (max 900) violaría
        // la restrictividad si se validara antes de autorizar.
        $this->fijarMaximoBanca($otraBanca, 500);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->putJson('/api/v1/limites/'.$this->juego()->id, [
                'banca_id' => $otraBanca->id,
                'grupo_id' => $otroGrupo->id,
                'moneda' => 'bs',
                'limite_maximo' => 900,
            ]);

        $response->assertForbidden();

        $this->assertDatabaseMissing('juego_limites', [
            'juego_id' => $this->juego()->id,
            'grupo_id' => $otroGrupo->id,
            'moneda' => 'bs',
            'limite_maximo' => 900,
        ]);
    }

    public function test_actor_en_alcance_con_restrictividad_invalida_422(): void
    {
        $banca = $this->bancaPropia();
        $grupo = $this->grupoPropio();
        $this->fijarMaximoBanca($banca, 500);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->putJson('/api/v1/limites/'.$this->juego()->id, [
                'banca_id' => $banca->id,
                'grupo_id' => $grupo->id,
                'moneda' => 'bs',
                'limite_maximo' => 900,
            ]);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('juego_limites', [
            'juego_id' => $this->juego()->id,
            'grupo_id' => $grupo->id,
            'moneda' => 'bs',
            'limite_maximo' => 900,
        ]);
    }
}
