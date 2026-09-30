<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\Comision;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PR 1 (S1) — default global de comisiones.
 *
 * GET /api/v1/comisiones/defaults expone las 2 filas sembradas (bs/usd);
 * PUT las sobreescribe; roles sin manage_comisiones reciben 403.
 *
 * PR 3 (S4, slice 3a) — ledger y liquidación (D4/D5/D7/D8):
 * POST /api/v1/comisiones/liquidar escribe filas SOLO para Grupo y
 * Taquilla (nunca banca), congeladas; PATCH /{comision}/pagar transiciona
 * pendiente→pagado (idempotente); rangos solapados → 422 con ids; el
 * listado GET /comisiones es paginado y con alcance por rol.
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

    // ==================================================
    // Helpers del ledger
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
            'type' => 'animalitos',
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

    private function liquidar(User $user, array $payload): TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/comisiones/liquidar', $payload);
    }

    // ==================================================
    // 4.3 Ledger (S11–S14, D4/D5/D7/D8)
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

    public function test_liquidar_crea_filas_solo_para_grupo_y_taquilla_sin_banca()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearJerarquia();

        // banca 10 (propia) + taquilla 100 ⇒ taquilla liquida 90;
        // grupo sin fila propia ⇒ efectiva 10 (fallback banca) ⇒ liquida 10.
        $this->limite($juego->id, $banca->id, null, null, 'bs', 10);
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 100);

        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, '2026-09-15 10:00:00');

        $response = $this->liquidar($this->superUser(), [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
        ]);

        $response->assertStatus(201);

        $this->assertSame(2, Comision::count());

        // Fila del Grupo: monto 10.00, estado pendiente, rango persistido
        $this->assertDatabaseHas('comisiones', [
            'grupo_id' => $grupo->id,
            'banca_id' => null,
            'taquilla_id' => null,
            'periodo' => '2026-09-01..2026-09-30',
            'monto_comision' => '10.00',
            'estado' => 'pendiente',
            'fecha_inicio' => '2026-09-01',
            'fecha_fin' => '2026-09-30',
        ]);

        // Fila de la Taquilla: monto 90.00 (D11)
        $this->assertDatabaseHas('comisiones', [
            'taquilla_id' => $taquilla->id,
            'banca_id' => null,
            'grupo_id' => null,
            'periodo' => '2026-09-01..2026-09-30',
            'monto_comision' => '90.00',
            'estado' => 'pendiente',
        ]);

        // La banca NUNCA genera fila (D7): ninguna fila con banca asignada
        $this->assertDatabaseMissing('comisiones', ['banca_id' => $banca->id]);
    }

    public function test_patch_pagar_transiciona_pendiente_a_pagado()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearJerarquia();
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);
        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, '2026-09-15 10:00:00');

        $this->liquidar($this->superUser(), [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
        ])->assertStatus(201);

        $fila = Comision::where('taquilla_id', $taquilla->id)->firstOrFail();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->patchJson("/api/v1/comisiones/{$fila->id}/pagar");

        $response->assertStatus(200);
        $response->assertJsonPath('estado', 'pagado');
        $this->assertDatabaseHas('comisiones', ['id' => $fila->id, 'estado' => 'pagado']);
    }

    public function test_patch_pagar_en_fila_ya_pagada_es_idempotente()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearJerarquia();
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);
        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, '2026-09-15 10:00:00');

        $this->liquidar($this->superUser(), [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
        ])->assertStatus(201);

        $fila = Comision::where('taquilla_id', $taquilla->id)->firstOrFail();

        $this->actingAs($this->superUser(), 'sanctum')
            ->patchJson("/api/v1/comisiones/{$fila->id}/pagar")
            ->assertStatus(200);

        // Segundo PATCH: 200 no-op (D5), sin transición inversa
        $segundo = $this->actingAs($this->superUser(), 'sanctum')
            ->patchJson("/api/v1/comisiones/{$fila->id}/pagar");

        $segundo->assertStatus(200);
        $segundo->assertJsonPath('estado', 'pagado');
        $this->assertDatabaseHas('comisiones', [
            'id' => $fila->id,
            'estado' => 'pagado',
            'monto_comision' => '50.00',
        ]);
    }

    public function test_liquidar_congela_el_monto_ante_cambios_posteriores_de_tasa()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearJerarquia();
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);
        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, '2026-09-15 10:00:00');

        $this->liquidar($this->superUser(), [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
        ])->assertStatus(201);

        // Editar porcentaje_pago DESPUÉS de liquidar: la fila ya no cambia
        JuegoLimite::where('juego_id', $juego->id)
            ->where('taquilla_id', $taquilla->id)
            ->update(['porcentaje_pago' => 100]);

        $this->assertDatabaseHas('comisiones', [
            'taquilla_id' => $taquilla->id,
            'monto_comision' => '50.00',
        ]);
    }

    public function test_liquidar_rango_solapado_422_con_ids_de_entidades_conflictivas()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearJerarquia();
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);
        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, '2026-09-15 10:00:00');

        $this->liquidar($this->superUser(), [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
        ])->assertStatus(201);
        $this->assertSame(2, Comision::count());

        // Rango solapado [09-15, 10-15]: rechaza TODO el lote (D4)
        $response = $this->liquidar($this->superUser(), [
            'desde' => '2026-09-15',
            'hasta' => '2026-10-15',
        ]);

        $response->assertStatus(422);

        $conflictos = $response->json('conflictos');
        $this->assertCount(2, $conflictos);
        $this->assertContains(['nivel' => 'grupo', 'entidad_id' => $grupo->id], $conflictos);
        $this->assertContains(['nivel' => 'taquilla', 'entidad_id' => $taquilla->id], $conflictos);

        // Sin doble conteo: ninguna fila nueva
        $this->assertSame(2, Comision::count());
    }

    public function test_liquidar_mismo_rango_rechazado_sin_doble_conteo()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearJerarquia();
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);
        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, '2026-09-15 10:00:00');

        $this->liquidar($this->superUser(), [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
        ])->assertStatus(201);

        // Re-liquidar el MISMO rango = solapamiento → 422 (no-double-count documentado)
        $response = $this->liquidar($this->superUser(), [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
        ]);

        $response->assertStatus(422);
        $this->assertSame(2, Comision::count());
    }

    public function test_liquidar_403_para_rol_sin_manage_comisiones()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearJerarquia();
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);
        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, '2026-09-15 10:00:00');

        $response = $this->liquidar($this->bancaUser(), [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, Comision::count());
    }

    public function test_liquidar_rango_sin_ventas_responde_201_con_lista_vacia()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearJerarquia();
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);

        $response = $this->liquidar($this->superUser(), [
            'desde' => '2026-01-01',
            'hasta' => '2026-01-31',
        ]);

        $response->assertStatus(201);
        $response->assertJsonCount(0, 'data');
        $this->assertSame(0, Comision::count());
    }

    public function test_liquidar_422_si_hasta_es_anterior_a_desde()
    {
        $response = $this->liquidar($this->superUser(), [
            'desde' => '2026-09-30',
            'hasta' => '2026-09-01',
        ]);

        $response->assertStatus(422);
    }

    // ==================================================
    // 4.5 Listado (GET /comisiones, D8) y alcance master
    // ==================================================

    public function test_get_comisiones_lista_paginada_para_super_master()
    {
        [$juego, $banca, $grupo, $taquilla] = $this->crearJerarquia();
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);
        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, '2026-09-15 10:00:00');

        $this->liquidar($this->superUser(), [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
        ])->assertStatus(201);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/comisiones?per_page=10');

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('total'));
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(10, $response->json('per_page'));
    }

    public function test_master_liquida_y_ve_solo_las_bancas_que_administra()
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
            'name' => 'Juego Master Scope',
            'slug' => 'juego-master-scope-'.uniqid(),
            'type' => 'animalitos',
            'active' => true,
        ]);
        $this->limite($juego->id, $bancaMia->id, $grupoMio->id, $taquillaMia->id, 'bs', 50);
        $this->limite($juego->id, $bancaAjena->id, $grupoAjeno->id, $taquillaAjena->id, 'bs', 30);

        $this->apuesta($juego->id, $taquillaMia->id, 100.0, 0.0, 36.5, '2026-09-15 10:00:00');
        $this->apuesta($juego->id, $taquillaAjena->id, 100.0, 0.0, 36.5, '2026-09-15 11:00:00');

        // Master liquida SOLO su banca (default banca_ids = masterBancaIds)
        $this->liquidar($master, [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
        ])->assertStatus(201);
        $this->assertSame(2, Comision::count());

        // Super Master liquida la banca ajena (alcance explícito)
        $this->liquidar($this->superUser(), [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
            'banca_ids' => [$bancaAjena->id],
        ])->assertStatus(201);
        $this->assertSame(4, Comision::count());

        // El master solo ve sus 2 filas (grupo + taquilla de su banca)
        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/comisiones?per_page=50');

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('total'));

        $visibles = collect($response->json('data'));
        $this->assertCount(2, $visibles);
        $this->assertNotNull($visibles->firstWhere('taquilla_id', $taquillaMia->id));
        $this->assertNotNull($visibles->firstWhere('grupo_id', $grupoMio->id));
        $this->assertNull($visibles->firstWhere('taquilla_id', $taquillaAjena->id));
        $this->assertNull($visibles->firstWhere('grupo_id', $grupoAjeno->id));
    }

    public function test_get_comisiones_master_sin_bancas_ve_lista_vacia()
    {
        $master = $this->masterUser();

        // Existen filas liquidadas, pero el master no administra ninguna banca
        [$juego, $banca, $grupo, $taquilla] = $this->crearJerarquia();
        $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 'bs', 50);
        $this->apuesta($juego->id, $taquilla->id, 100.0, 0.0, 36.5, '2026-09-15 10:00:00');
        $this->liquidar($this->superUser(), [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
        ])->assertStatus(201);

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/comisiones?per_page=50');

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('total'));
        $this->assertCount(0, $response->json('data'));
    }

    public function test_patch_pagar_403_para_master_fuera_de_su_alcance()
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
            'name' => 'Juego Pagar Scope',
            'slug' => 'juego-pagar-scope-'.uniqid(),
            'type' => 'animalitos',
            'active' => true,
        ]);
        $this->limite($juego->id, $bancaMia->id, $grupoMio->id, $taquillaMia->id, 'bs', 50);
        $this->limite($juego->id, $bancaAjena->id, $grupoAjeno->id, $taquillaAjena->id, 'bs', 30);
        $this->apuesta($juego->id, $taquillaMia->id, 100.0, 0.0, 36.5, '2026-09-15 10:00:00');
        $this->apuesta($juego->id, $taquillaAjena->id, 100.0, 0.0, 36.5, '2026-09-15 11:00:00');

        $this->liquidar($this->superUser(), [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
        ])->assertStatus(201);

        // El master NO puede pagar una fila de una banca que no administra (D8)
        $filaAjena = Comision::where('taquilla_id', $taquillaAjena->id)->firstOrFail();

        $response = $this->actingAs($master, 'sanctum')
            ->patchJson("/api/v1/comisiones/{$filaAjena->id}/pagar");

        $response->assertStatus(403);
        $this->assertDatabaseHas('comisiones', ['id' => $filaAjena->id, 'estado' => 'pendiente']);
    }

    public function test_liquidar_403_para_master_con_banca_ids_fuera_de_su_alcance()
    {
        $master = $this->masterUser();

        $bancaMia = Banca::create([
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

        $response = $this->liquidar($master, [
            'desde' => '2026-09-01',
            'hasta' => '2026-09-30',
            'banca_ids' => [$bancaAjena->id],
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, Comision::count());
    }
}
