<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PR 1 (S1) — default global de comisiones.
 *
 * GET /api/v1/comisiones/defaults expone las 2 filas sembradas (bs/usd);
 * PUT las sobreescribe; roles sin manage_comisiones reciben 403.
 *
 * Modelo A — comisiones consultivas: GET /comisiones/consulta devuelve
 * montos por rango sin persistir; el ledger `comisiones` se archiva en
 * `comisiones_archivo_2026` y se elimina de la base.
 *
 * Enmienda (2026-10-01) — el rol `grupo` escribe la matriz de SUS
 * taquillas (PUT/batch de límites) dentro de su subárbol; fuera de
 * alcance ⇒ 403.
 */
class ComisionesApiTest extends TestCase
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

    private function masterUser(): User
    {
        $master = User::where('email', 'master@lotto.com')->first();
        $master->assignRole('master');

        return $master;
    }

    private function bancaUser(): User
    {
        $user = User::where('email', 'banca@lotto.com')->first();
        $user->assignRole('banca');

        return $user;
    }

    private function grupoUser(): User
    {
        $user = User::where('email', 'grupo@lotto.com')->first();
        $user->assignRole('grupo');

        return $user;
    }

    private function taquillaUser(): User
    {
        $user = User::where('email', 'taquilla@lotto.com')->first();
        $user->assignRole('taquilla');

        return $user;
    }

    // ==================================================
    // Helpers de jerarquía y ventas
    // ==================================================

    /**
     * @return array{0: Juego, 1: Banca, 2: Grupo, 3: Taquilla}
     */
    private function crearJerarquia(): array
    {
        $banca = Banca::create([
            'name' => 'Banca Comisiones API',
            'code' => 'BAPI'.uniqid(),
            'active' => true,
        ]);

        $grupo = Grupo::create([
            'name' => 'Grupo Comisiones API',
            'code' => 'GAPI'.uniqid(),
            'banca_id' => $banca->id,
            'active' => true,
        ]);

        $taquilla = Taquilla::create([
            'name' => 'Taquilla Comisiones API',
            'code' => 'TAPI'.uniqid(),
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);

        $juego = Juego::create([
            'name' => 'Juego Comisiones API',
            'slug' => 'juego-comisiones-api-'.uniqid(),
            'type' => 'terminales',
            'active' => true,
        ]);

        return [$juego, $banca, $grupo, $taquilla];
    }

    private function limite(int $juegoId, int $bancaId, ?int $grupoId, ?int $taquillaId, string $moneda, $porcentajePago): void
    {
        JuegoLimite::create([
            'juego_id' => $juegoId,
            'banca_id' => $bancaId,
            'grupo_id' => $grupoId,
            'taquilla_id' => $taquillaId,
            'moneda' => $moneda,
            'porcentaje_pago' => $porcentajePago,
        ]);
    }

    private function apuesta(int $juegoId, int $taquillaId, float $amountBs, float $amountUsd, float $tasaCambio, ?string $fechaHora = null): void
    {
        Apuesta::create([
            'taquilla_id' => $taquillaId,
            'juego_id' => $juegoId,
            'amount_bs' => $amountBs,
            'amount_usd' => $amountUsd,
            'exchange_rate_applied' => $tasaCambio,
            'total_bs_equivalent' => round($amountBs + ($amountUsd * $tasaCambio), 2),
            'estado' => 'pendiente',
            'fecha_hora' => $fechaHora ?? now(),
        ]);
    }

    private function consulta(User $user, array $query = []): TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/comisiones/consulta?'.http_build_query($query));
    }

    // ==================================================
    // Defaults globales (S1)
    // ==================================================

    public function test_get_defaults_devuelve_las_2_filas_bs_usd()
    {
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/comisiones/defaults');

        $response->assertStatus(200);

        $defaults = $response->json();
        $this->assertCount(2, $defaults);
        $this->assertEquals(['bs', 'usd'], collect($defaults)->pluck('moneda')->sort()->values()->all());
    }

    public function test_put_defaults_sobreescribe_los_porcentajes()
    {
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson('/api/v1/comisiones/defaults', [
                'defaults' => [
                    ['moneda' => 'bs', 'porcentaje_pago' => 15.50],
                    ['moneda' => 'usd', 'porcentaje_pago' => 10.00],
                ],
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('comision_defaults', ['moneda' => 'bs', 'porcentaje_pago' => 15.50]);
        $this->assertDatabaseHas('comision_defaults', ['moneda' => 'usd', 'porcentaje_pago' => 10.00]);
    }

    public function test_put_defaults_403_para_rol_sin_manage_comisiones()
    {
        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->putJson('/api/v1/comisiones/defaults', [
                'defaults' => [
                    ['moneda' => 'bs', 'porcentaje_pago' => 15.50],
                ],
            ]);

        $response->assertStatus(403);
    }

    public function test_get_defaults_403_para_rol_no_super_master()
    {
        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->getJson('/api/v1/comisiones/defaults');

        $response->assertStatus(403);
    }

    public function test_put_defaults_422_si_falta_porcentaje_o_moneda_invalida()
    {
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson('/api/v1/comisiones/defaults', [
                'defaults' => [
                    ['moneda' => 'eur', 'porcentaje_pago' => 15.50],
                ],
            ]);

        $response->assertStatus(422);
    }

    // ==================================================
    // Consulta read-only por rango (Modelo A)
    // ==================================================

    public function test_consulta_devuelve_tres_niveles_con_monto_string()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearJerarquia();

        // Suma cero 3 niveles: banca 10 + grupo 20 + taquilla 100 ⇒ 10 / 20 / 70
        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);
        $this->limite($juego->id, $banca->id, $grupo->id, null, 'bs', 20);
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 100);
        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, '2026-09-15 10:00:00');

        $response = $this->consulta($this->superUser(), [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('meta.desde', '2026-09-01');
        $response->assertJsonPath('meta.hasta', '2026-09-30');
        $response->assertJsonPath('meta.total', 3);

        $filas = collect($response->json('data'));

        $filaBanca = $filas->firstWhere('nivel', 'banca');
        $this->assertNotNull($filaBanca);
        $this->assertSame($banca->id, $filaBanca['entidad_id']);
        $this->assertSame('Banca Comisiones API', $filaBanca['entidad']);
        $this->assertSame('10.00', $filaBanca['monto_comision']);

        $filaGrupo = $filas->firstWhere('nivel', 'grupo');
        $this->assertNotNull($filaGrupo);
        $this->assertSame($grupo->id, $filaGrupo['entidad_id']);
        $this->assertSame('20.00', $filaGrupo['monto_comision']);

        $filaTaquilla = $filas->firstWhere('nivel', 'taquilla');
        $this->assertNotNull($filaTaquilla);
        $this->assertSame($taquilla->id, $filaTaquilla['entidad_id']);
        $this->assertSame('70.00', $filaTaquilla['monto_comision']);

        // Solo lectura: sin estado del ledger ni listas de conflictos
        $this->assertArrayNotHasKey('estado', $filaBanca);
        $this->assertArrayNotHasKey('conflictos', $response->json());
    }

    public function test_consulta_rango_sin_ventas_devuelve_data_vacia()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearJerarquia();
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);

        $response = $this->consulta($this->superUser(), [
            'desde' => '2026-01-01',
            'hasta' => '2026-01-31',
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('meta.total', 0);
    }

    public function test_consulta_master_solo_ve_sus_bancas()
    {
        $master = $this->masterUser();

        $bancaMia = Banca::create([
            'name' => 'Banca del Master',
            'code' => 'BM'.uniqid(),
            'master_id' => $master->id,
            'active' => true,
        ]);
        $grupoMio = Grupo::create([
            'name' => 'Grupo del Master',
            'code' => 'GM'.uniqid(),
            'banca_id' => $bancaMia->id,
            'active' => true,
        ]);
        $taquillaMia = Taquilla::create([
            'name' => 'Taquilla del Master',
            'code' => 'TM'.uniqid(),
            'grupo_id' => $grupoMio->id,
            'active' => true,
        ]);

        $bancaAjena = Banca::create([
            'name' => 'Banca Ajena',
            'code' => 'BA'.uniqid(),
            'active' => true,
        ]);
        $grupoAjeno = Grupo::create([
            'name' => 'Grupo Ajeno',
            'code' => 'GA'.uniqid(),
            'banca_id' => $bancaAjena->id,
            'active' => true,
        ]);
        $taquillaAjena = Taquilla::create([
            'name' => 'Taquilla Ajena',
            'code' => 'TA'.uniqid(),
            'grupo_id' => $grupoAjeno->id,
            'active' => true,
        ]);

        $juego = Juego::create([
            'name' => 'Juego Consulta Scope',
            'slug' => 'juego-consulta-scope-'.uniqid(),
            'type' => 'terminales',
            'active' => true,
        ]);
        $this->limite($juego->id, $bancaMia->id, $grupoMio->id, $taquillaMia->id, 'bs', 50);
        $this->limite($juego->id, $bancaAjena->id, $grupoAjeno->id, $taquillaAjena->id, 'bs', 30);

        $this->apuesta($juego->id, $taquillaMia->id, 100.0, 0.0, 36.5, '2026-09-15 10:00:00');
        $this->apuesta($juego->id, $taquillaAjena->id, 100.0, 0.0, 36.5, '2026-09-15 11:00:00');

        // Sin banca_ids ⇒ masterBancaIds(): solo la cadena de su banca
        $response = $this->consulta($master, [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');

        $filas = collect($response->json('data'));

        $this->assertNotNull($filas->first(fn ($f) => $f['nivel'] === 'banca' && $f['entidad_id'] === $bancaMia->id));
        $this->assertNotNull($filas->first(fn ($f) => $f['nivel'] === 'grupo' && $f['entidad_id'] === $grupoMio->id));
        $this->assertNotNull($filas->first(fn ($f) => $f['nivel'] === 'taquilla' && $f['entidad_id'] === $taquillaMia->id));

        $this->assertNull($filas->first(fn ($f) => $f['nivel'] === 'banca' && $f['entidad_id'] === $bancaAjena->id));
        $this->assertNull($filas->first(fn ($f) => $f['nivel'] === 'grupo' && $f['entidad_id'] === $grupoAjeno->id));
        $this->assertNull($filas->first(fn ($f) => $f['nivel'] === 'taquilla' && $f['entidad_id'] === $taquillaAjena->id));
    }

    public function test_consulta_master_sin_bancas_devuelve_lista_vacia()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearJerarquia();
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);
        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, '2026-09-15 10:00:00');

        // El master no administra ninguna banca: ve vacío, nunca global
        $response = $this->consulta($this->masterUser(), [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    public function test_consulta_master_con_banca_ids_fuera_de_alcance_403()
    {
        $master = $this->masterUser();

        Banca::create([
            'name' => 'Banca del Master',
            'code' => 'BM'.uniqid(),
            'master_id' => $master->id,
            'active' => true,
        ]);
        $bancaAjena = Banca::create([
            'name' => 'Banca Ajena',
            'code' => 'BA'.uniqid(),
            'active' => true,
        ]);

        $response = $this->consulta($master, [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
            'banca_ids' => [$bancaAjena->id],
        ]);

        $response->assertStatus(403);
    }

    public function test_consulta_403_para_roles_no_autorizados()
    {
        $usuarios = [
            'banca' => $this->bancaUser(),
            'grupo' => $this->grupoUser(),
            'taquilla' => $this->taquillaUser(),
        ];

        foreach ($usuarios as $rol => $user) {
            $response = $this->consulta($user, [
                'desde' => '2026-09-01',
                'hasta' => '2026-09-30',
            ]);

            $this->assertSame(403, $response->status(), "El rol {$rol} debe recibir 403.");
        }
    }

    public function test_consulta_no_persiste_en_el_ledger_ni_registra_liquidacion()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearJerarquia();
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);
        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, '2026-09-15 10:00:00');

        $this->consulta($this->superUser(), [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
        ])->assertStatus(200);

        // El ledger ya no existe como store activo: la tabla fue archivada
        // y eliminada por la migración archive+drop.
        $this->assertFalse(Schema::hasTable('comisiones'));
        $this->assertDatabaseMissing('logs', ['action' => 'comision_liquidar']);
    }

    public function test_consulta_422_si_faltan_fechas_o_rango_invertido()
    {
        $this->consulta($this->superUser(), [])->assertStatus(422);

        $this->consulta($this->superUser(), [
            'desde' => '2026-09-30',
            'hasta' => '2026-09-01',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['hasta']]);
    }

    public function test_archivo_reemplaza_la_tabla_comisiones()
    {
        // End-state de la migración archive+drop sobre una DB de test fresca:
        // el ledger activo desaparece y el archivo queda listo (vacío).
        $this->assertFalse(Schema::hasTable('comisiones'));
        $this->assertTrue(Schema::hasTable('comisiones_archivo_2026'));
        $this->assertDatabaseCount('comisiones_archivo_2026', 0);
    }

    // ==================================================
    // Enmienda (2026-10-01) — el rol grupo escribe SU matriz
    // ==================================================

    public function test_grupo_escribe_matriz_de_sus_taquillas()
    {
        $grupoUser = $this->grupoUser();
        $banca = Banca::findOrFail($grupoUser->banca_id);
        $grupo = Grupo::findOrFail($grupoUser->grupo_id);
        $taquilla = Taquilla::where('grupo_id', $grupo->id)->firstOrFail();

        $juego = Juego::create([
            'name' => 'Juego Matriz Grupo',
            'slug' => 'juego-matriz-grupo-'.uniqid(),
            'type' => 'terminales',
            'active' => true,
        ]);

        // Comisión + mín/máx sobre una taquilla propia: sin 403 en su scope
        $response = $this->actingAs($grupoUser, 'sanctum')
            ->putJson('/api/v1/limites/'.$juego->id, [
                'banca_id' => $banca->id,
                'grupo_id' => $grupo->id,
                'taquilla_id' => $taquilla->id,
                'moneda' => 'bs',
                'porcentaje_pago' => 15,
                'limite_minimo' => 10,
                'limite_maximo' => 500,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('juego_limites', [
            'juego_id' => $juego->id,
            'banca_id' => $banca->id,
            'grupo_id' => $grupo->id,
            'taquilla_id' => $taquilla->id,
            'moneda' => 'bs',
            'porcentaje_pago' => 15,
            'limite_minimo' => 10,
            'limite_maximo' => 500,
        ]);
    }

    public function test_grupo_403_al_escribir_matriz_fuera_de_su_scope()
    {
        $grupoUser = $this->grupoUser();
        $banca = Banca::findOrFail($grupoUser->banca_id);

        $otroGrupo = Grupo::create([
            'name' => 'Grupo Ajeno Matriz',
            'code' => 'GAM'.uniqid(),
            'banca_id' => $banca->id,
            'active' => true,
        ]);
        $taquillaAjena = Taquilla::create([
            'name' => 'Taquilla Ajena Matriz',
            'code' => 'TAM'.uniqid(),
            'grupo_id' => $otroGrupo->id,
            'active' => true,
        ]);

        $juego = Juego::create([
            'name' => 'Juego Matriz Ajena',
            'slug' => 'juego-matriz-ajena-'.uniqid(),
            'type' => 'terminales',
            'active' => true,
        ]);

        $response = $this->actingAs($grupoUser, 'sanctum')
            ->putJson('/api/v1/limites/'.$juego->id, [
                'banca_id' => $banca->id,
                'grupo_id' => $otroGrupo->id,
                'taquilla_id' => $taquillaAjena->id,
                'moneda' => 'bs',
                'porcentaje_pago' => 15,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('juego_limites', [
            'juego_id' => $juego->id,
            'taquilla_id' => $taquillaAjena->id,
        ]);
    }

    // ==================================================
    // Tope por tipo en configuración (animalitos 16, tripletas 25)
    // ==================================================

    private function juegoPorTipo(string $type): Juego
    {
        return Juego::create([
            'name' => 'Juego '.$type.' '.uniqid(),
            'slug' => 'juego-'.$type.'-'.uniqid(),
            'type' => $type,
            'active' => true,
        ]);
    }

    private function putLimite(User $user, Juego $juego, array $payload): TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/limites/'.$juego->id, $payload);
    }

    public function test_put_limite_animalitos_sobre_tope_422()
    {
        [, $banca, $grupo, $taquilla] = $this->crearJerarquia();
        $juego = $this->juegoPorTipo('animalitos');

        $this->putLimite($this->superUser(), $juego, [
            'banca_id' => $banca->id,
            'grupo_id' => $grupo->id,
            'taquilla_id' => $taquilla->id,
            'moneda' => 'bs',
            'porcentaje_pago' => 17,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('juego_limites', [
            'juego_id' => $juego->id,
            'taquilla_id' => $taquilla->id,
        ]);
    }

    public function test_put_limite_tripletas_sobre_tope_422()
    {
        [, $banca, $grupo, $taquilla] = $this->crearJerarquia();
        $juego = $this->juegoPorTipo('tripletas');

        $this->putLimite($this->superUser(), $juego, [
            'banca_id' => $banca->id,
            'grupo_id' => $grupo->id,
            'taquilla_id' => $taquilla->id,
            'moneda' => 'bs',
            'porcentaje_pago' => 26,
        ])->assertStatus(422);
    }

    public function test_put_limite_dentro_del_tope_201()
    {
        [, $banca, $grupo, $taquilla] = $this->crearJerarquia();
        $juego = $this->juegoPorTipo('animalitos');

        $this->putLimite($this->superUser(), $juego, [
            'banca_id' => $banca->id,
            'grupo_id' => $grupo->id,
            'taquilla_id' => $taquilla->id,
            'moneda' => 'bs',
            'porcentaje_pago' => 16,
        ])->assertStatus(201);

        $this->assertDatabaseHas('juego_limites', [
            'juego_id' => $juego->id,
            'taquilla_id' => $taquilla->id,
            'porcentaje_pago' => 16,
        ]);
    }

    public function test_put_limite_tripletas_en_tope_201()
    {
        [, $banca, $grupo, $taquilla] = $this->crearJerarquia();
        $juego = $this->juegoPorTipo('tripletas');

        $this->putLimite($this->superUser(), $juego, [
            'banca_id' => $banca->id,
            'grupo_id' => $grupo->id,
            'taquilla_id' => $taquilla->id,
            'moneda' => 'bs',
            'porcentaje_pago' => 25,
        ])->assertStatus(201);
    }

    public function test_put_limite_terminales_hasta_100_201()
    {
        [, $banca, $grupo, $taquilla] = $this->crearJerarquia();
        $juego = $this->juegoPorTipo('terminales');

        $this->putLimite($this->superUser(), $juego, [
            'banca_id' => $banca->id,
            'grupo_id' => $grupo->id,
            'taquilla_id' => $taquilla->id,
            'moneda' => 'bs',
            'porcentaje_pago' => 100,
        ])->assertStatus(201);
    }

    public function test_batch_scope_banca_animalitos_sobre_tope_422()
    {
        $this->crearJerarquia();
        $juego = $this->juegoPorTipo('animalitos');

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/limites/batch', [
                'scope' => ['tipo' => 'bancas'],
                'limites' => [
                    ['juego_id' => $juego->id, 'moneda' => 'bs', 'porcentaje_pago' => 17],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_batch_scope_grupo_tripletas_sobre_tope_422()
    {
        $this->crearJerarquia();
        $juego = $this->juegoPorTipo('tripletas');

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/limites/batch', [
                'scope' => ['tipo' => 'grupos'],
                'limites' => [
                    ['juego_id' => $juego->id, 'moneda' => 'bs', 'porcentaje_pago' => 26],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_store_banca_con_limite_animalitos_sobre_tope_422()
    {
        $juego = $this->juegoPorTipo('animalitos');
        $code = 'BTOPE'.uniqid();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/bancas', [
                'name' => 'Banca Tope',
                'code' => $code,
                'user_name' => 'Banca Tope',
                'user_email' => 'banca-tope-'.uniqid().'@lotto.com',
                'user_password' => 'password123',
                'limites' => [
                    ['juego_id' => $juego->id, 'moneda' => 'bs', 'porcentaje_pago' => 17],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('bancas', ['code' => $code]);
    }

    public function test_put_defaults_17_422()
    {
        $this->actingAs($this->superUser(), 'sanctum')
            ->putJson('/api/v1/comisiones/defaults', [
                'defaults' => [['moneda' => 'bs', 'porcentaje_pago' => 17]],
            ])
            ->assertStatus(422);
    }

    public function test_put_defaults_16_200()
    {
        $this->actingAs($this->superUser(), 'sanctum')
            ->putJson('/api/v1/comisiones/defaults', [
                'defaults' => [['moneda' => 'bs', 'porcentaje_pago' => 16]],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('comision_defaults', ['moneda' => 'bs', 'porcentaje_pago' => 16]);
    }
}
