<?php

namespace Tests\Feature;

use App\Models\Agencia;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CORRECCIÓN FRONT (CREATE MODE) — persistir límites junto con la creación
 * de la entidad (banca/grupo/taquilla).
 *
 * El POST de creación acepta un array `limites` (juego × moneda) que se
 * persiste en la MISMA transacción que la entidad y su usuario jefe:
 * - con límites → 201 y filas en juego_limites con la cadena correcta;
 * - sin límites → 201 sin filas (comportamiento previo intacto);
 * - límites inválidos o que violan la restrictividad del padre → 422 y
 *   rollback completo (la entidad NO se crea).
 *
 * La agencia (local) NO configura límites (passthrough, spec jerarquia-agencias).
 */
class CrearEntidadConLimitesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function superUser(): User
    {
        $super = User::where('email', 'super@lotto.com')->first();
        $super->assignRole('super_master');

        return $super;
    }

    private function juegoLotto(): Juego
    {
        return Juego::where('slug', 'lotto-activo')->first();
    }

    private function bancaSeeded(): Banca
    {
        return Banca::where('code', 'BT001')->first();
    }

    private function grupoSeeded(): Grupo
    {
        return Grupo::where('code', 'GT001')->first();
    }

    // ==================================================
    // BANCA
    // ==================================================

    public function test_store_banca_con_limites_persiste_los_limites_de_la_nueva_banca()
    {
        $lotto = $this->juegoLotto();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/bancas', [
                'name' => 'Banca Con Limites',
                'code' => 'BCL01',
                'user_name' => 'Usuario Banca',
                'user_email' => 'banca-limites@test.com',
                'user_password' => 'password123',
                'limites' => [
                    ['juego_id' => $lotto->id, 'moneda' => 'bs', 'limite_maximo' => 100, 'porcentaje_pago' => 80, 'fraccion' => true],
                ],
            ]);

        $response->assertStatus(201);

        $bancaId = $response->json('banca.id');
        $this->assertDatabaseHas('bancas', ['id' => $bancaId]);

        $limite = JuegoLimite::where('juego_id', $lotto->id)
            ->where('banca_id', $bancaId)
            ->whereNull('grupo_id')
            ->whereNull('taquilla_id')
            ->where('moneda', 'bs')
            ->first();

        $this->assertNotNull($limite, 'El límite de la nueva banca debe persistir.');
        $this->assertSame(100.0, (float) $limite->limite_maximo);
        $this->assertSame(80.0, (float) $limite->porcentaje_pago);
        $this->assertTrue((bool) $limite->fraccion);
    }

    public function test_store_banca_sin_limites_crea_sin_limites()
    {
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/bancas', [
                'name' => 'Banca Sin Limites',
                'code' => 'BSL01',
                'user_name' => 'Usuario Banca',
                'user_email' => 'banca-sin-limites@test.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(201);

        $bancaId = $response->json('banca.id');
        $this->assertDatabaseMissing('juego_limites', ['banca_id' => $bancaId]);
    }

    public function test_store_banca_con_limite_invalido_retorna_422_y_no_crea_la_banca()
    {
        $lotto = $this->juegoLotto();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/bancas', [
                'name' => 'Banca Limite Invalido',
                'code' => 'BLI01',
                'user_name' => 'Usuario Banca',
                'user_email' => 'banca-li@test.com',
                'user_password' => 'password123',
                'limites' => [
                    ['juego_id' => $lotto->id, 'moneda' => 'eur', 'limite_maximo' => 100],
                ],
            ]);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('bancas', ['code' => 'BLI01']);
        $this->assertDatabaseMissing('juego_limites', ['moneda' => 'eur']);
    }

    // ==================================================
    // GRUPO
    // ==================================================

    public function test_store_grupo_con_limites_persiste_la_cadena_banca_grupo()
    {
        $banca = $this->bancaSeeded();
        $lotto = $this->juegoLotto();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/grupos', [
                'name' => 'Grupo Con Limites',
                'code' => 'GCL01',
                'banca_id' => $banca->id,
                'user_name' => 'Usuario Grupo',
                'user_email' => 'grupo-limites@test.com',
                'user_password' => 'password123',
                'limites' => [
                    ['juego_id' => $lotto->id, 'moneda' => 'bs', 'limite_maximo' => 50],
                ],
            ]);

        $response->assertStatus(201);

        $grupoId = $response->json('grupo.id');
        $this->assertDatabaseHas('grupos', ['id' => $grupoId]);

        $limite = JuegoLimite::where('juego_id', $lotto->id)
            ->where('banca_id', $banca->id)
            ->where('grupo_id', $grupoId)
            ->whereNull('taquilla_id')
            ->where('moneda', 'bs')
            ->first();

        $this->assertNotNull($limite, 'El límite del nuevo grupo debe persistir con su banca y grupo.');
        $this->assertSame(50.0, (float) $limite->limite_maximo);
    }

    public function test_store_grupo_con_limite_que_excede_la_banca_retorna_422_y_no_crea_el_grupo()
    {
        $banca = $this->bancaSeeded();
        $lotto = $this->juegoLotto();

        // La banca limita lotto-activo/bs a un máximo de 100
        JuegoLimite::updateOrCreate(
            ['juego_id' => $lotto->id, 'banca_id' => $banca->id, 'moneda' => 'bs', 'grupo_id' => null, 'taquilla_id' => null],
            ['limite_maximo' => 100]
        );

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/grupos', [
                'name' => 'Grupo Limite Excedido',
                'code' => 'GLE01',
                'banca_id' => $banca->id,
                'user_name' => 'Usuario Grupo',
                'user_email' => 'grupo-le@test.com',
                'user_password' => 'password123',
                'limites' => [
                    ['juego_id' => $lotto->id, 'moneda' => 'bs', 'limite_maximo' => 200],
                ],
            ]);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('grupos', ['code' => 'GLE01']);
        $this->assertDatabaseMissing('juego_limites', ['limite_maximo' => 200]);
    }

    // ==================================================
    // TAQUILLA
    // ==================================================

    public function test_store_taquilla_con_limites_persiste_la_cadena_completa()
    {
        $grupo = $this->grupoSeeded();
        $local = Agencia::factory()->create(['grupo_id' => $grupo->id]);
        $lotto = $this->juegoLotto();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', [
                'name' => 'Taquilla Con Limites',
                'code' => 'TCL01',
                'grupo_id' => $grupo->id,
                'agencia_id' => $local->id,
                'user_name' => 'Usuario Taquilla',
                'user_email' => 'taquilla-limites@test.com',
                'user_password' => 'password123',
                'limites' => [
                    ['juego_id' => $lotto->id, 'moneda' => 'bs', 'limite_maximo' => 30],
                ],
            ]);

        $response->assertStatus(201);

        $taquillaId = $response->json('taquilla.id');
        $this->assertDatabaseHas('taquillas', ['id' => $taquillaId]);

        $limite = JuegoLimite::where('juego_id', $lotto->id)
            ->where('banca_id', $grupo->banca_id)
            ->where('grupo_id', $grupo->id)
            ->where('taquilla_id', $taquillaId)
            ->where('moneda', 'bs')
            ->first();

        $this->assertNotNull($limite, 'El límite de la nueva taquilla debe persistir con la cadena completa.');
        $this->assertSame(30.0, (float) $limite->limite_maximo);
    }

    public function test_store_taquilla_con_limite_invalido_retorna_422_y_no_crea_la_taquilla()
    {
        $grupo = $this->grupoSeeded();
        $local = Agencia::factory()->create(['grupo_id' => $grupo->id]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', [
                'name' => 'Taquilla Limite Invalido',
                'code' => 'TLI01',
                'grupo_id' => $grupo->id,
                'agencia_id' => $local->id,
                'user_name' => 'Usuario Taquilla',
                'user_email' => 'taquilla-li@test.com',
                'user_password' => 'password123',
                'limites' => [
                    ['juego_id' => 999999, 'moneda' => 'bs', 'limite_maximo' => 30],
                ],
            ]);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('taquillas', ['code' => 'TLI01']);
    }
}
