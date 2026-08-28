<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\CierreCaja;
use App\Models\ExchangeRate;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F2 — Super banca (master scope).
 *
 * El rol master deja de ser global: ve SOLO sus bancas (bancas.master_id = user.id)
 * y su descendencia. Un master sin bancas asignadas ve NADA (whereRaw('1=0'),
 * nunca global). super_master sigue viendo todo. Los roles inferiores
 * (banca/grupo/agencia) no cambian (regresión).
 */
class SuperBancaScopeTest extends TestCase
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

        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $super->id,
            'is_active' => true,
        ]);

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

    private function juegoLotto(): Juego
    {
        $juego = Juego::where('slug', 'lotto-activo')->first();
        if ($juego) {
            return $juego;
        }

        return Juego::create(['name' => 'Lotto Activo', 'slug' => 'lotto-activo', 'active' => true]);
    }

    /**
     * Crear una jerarquía completa (banca → grupo → taquilla) con datos
     * de apuesta, ticket, cierre y límite. Devuelve los ids creados.
     */
    private function crearJerarquia(string $prefijo, ?int $masterId = null): array
    {
        $banca = Banca::create([
            'name' => "Banca {$prefijo}",
            'code' => "SB-{$prefijo}",
            'master_id' => $masterId,
            'active' => true,
            'created_by' => $this->superUser()->id,
        ]);

        $grupo = Grupo::create([
            'name' => "Grupo {$prefijo}",
            'code' => "SG-{$prefijo}",
            'banca_id' => $banca->id,
            'active' => true,
            'created_by' => $this->superUser()->id,
        ]);

        $taquilla = Taquilla::create([
            'name' => "Taquilla {$prefijo}",
            'code' => "ST-{$prefijo}",
            'grupo_id' => $grupo->id,
            'activation_code' => "ST-{$prefijo}-CODE",
            'active' => true,
            'created_by' => $this->superUser()->id,
        ]);

        $juego = $this->juegoLotto();

        Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'combinacion' => json_encode(['animal' => 'Perro']),
            'amount_bs' => 100,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 100,
            'estado' => 'pendiente',
            'fecha_hora' => now()->subDay(),
            'sorteo_hora' => now()->addHour(),
        ]);

        Ticket::create([
            'ticket_code' => "STK-{$prefijo}",
            'taquilla_id' => $taquilla->id,
            'total_bs' => 100,
            'total_usd' => 0,
            'estado' => 'pendiente',
        ]);

        CierreCaja::create([
            'taquilla_id' => $taquilla->id,
            'fecha_inicio' => now()->subDay(),
            'fecha_fin' => now(),
            'total_ventas_bs' => 100,
            'total_ventas_usd' => 0,
            'total_ventas_bs_equivalent' => 100,
            'total_egresos_bs' => 0,
            'total_egresos_usd' => 0,
            'total_efectivo_bs' => 100,
            'total_efectivo_usd' => 0,
            'exchange_rate_cierre' => 36.50,
            'created_by' => $this->superUser()->id,
        ]);

        JuegoLimite::create([
            'juego_id' => $juego->id,
            'banca_id' => $banca->id,
            'grupo_id' => null,
            'taquilla_id' => null,
            'moneda' => 'bs',
            'limite_maximo' => 500,
        ]);

        return ['banca' => $banca, 'grupo' => $grupo, 'taquilla' => $taquilla];
    }

    // ==================================================
    // MASTER CON BANCAS PROPIAS — LISTADO SCOPED
    // ==================================================

    public function test_master_lista_solo_sus_bancas()
    {
        $master = $this->masterUser();
        $propia = $this->crearJerarquia('Propia', $master->id);
        $ajena = $this->crearJerarquia('Ajena'); // sin master_id

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/bancas');

        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($propia['banca']->id, $ids);
        $this->assertNotContains($ajena['banca']->id, $ids);
    }

    public function test_master_lista_solo_sus_grupos_y_taquillas()
    {
        $master = $this->masterUser();
        $propia = $this->crearJerarquia('Propia', $master->id);
        $ajena = $this->crearJerarquia('Ajena');

        // Grupos
        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/grupos');
        $response->assertStatus(200);
        $grupos = collect($response->json())->pluck('id')->all();
        $this->assertContains($propia['grupo']->id, $grupos);
        $this->assertNotContains($ajena['grupo']->id, $grupos);

        // Taquillas
        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/taquillas');
        $response->assertStatus(200);
        $taquillas = collect($response->json())->pluck('id')->all();
        $this->assertContains($propia['taquilla']->id, $taquillas);
        $this->assertNotContains($ajena['taquilla']->id, $taquillas);
    }

    public function test_master_lista_solo_usuarios_de_sus_bancas()
    {
        $master = $this->masterUser();
        $propia = $this->crearJerarquia('Propia', $master->id);
        $ajena = $this->crearJerarquia('Ajena');

        $usuarioPropio = User::factory()->create([
            'role' => 'taquilla',
            'banca_id' => $propia['banca']->id,
            'grupo_id' => $propia['grupo']->id,
            'taquilla_id' => $propia['taquilla']->id,
        ]);
        $usuarioPropio->assignRole('taquilla');

        $usuarioAjeno = User::factory()->create([
            'role' => 'taquilla',
            'banca_id' => $ajena['banca']->id,
            'grupo_id' => $ajena['grupo']->id,
            'taquilla_id' => $ajena['taquilla']->id,
        ]);
        $usuarioAjeno->assignRole('taquilla');

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/users');

        $response->assertStatus(200);

        $emails = collect($response->json())->pluck('email')->all();
        $this->assertContains($usuarioPropio->email, $emails);
        $this->assertNotContains($usuarioAjeno->email, $emails);
    }

    public function test_master_ve_solo_apuestas_de_sus_bancas()
    {
        $master = $this->masterUser();
        $propia = $this->crearJerarquia('Propia', $master->id);
        $ajena = $this->crearJerarquia('Ajena');

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/apuestas');

        $response->assertStatus(200);

        $taquillas = collect($response->json('data.data'))->pluck('taquilla_id')->all();
        $this->assertContains($propia['taquilla']->id, $taquillas);
        $this->assertNotContains($ajena['taquilla']->id, $taquillas);
    }

    public function test_master_ve_solo_cierres_de_sus_bancas()
    {
        $master = $this->masterUser();
        $propia = $this->crearJerarquia('Propia', $master->id);
        $ajena = $this->crearJerarquia('Ajena');

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/cierre');

        $response->assertStatus(200);

        $taquillas = collect($response->json('data'))->pluck('taquilla_id')->all();
        $this->assertNotContains($ajena['taquilla']->id, $taquillas);
        $this->assertContains($propia['taquilla']->id, $taquillas);
    }

    public function test_master_ve_solo_limites_de_sus_bancas()
    {
        $master = $this->masterUser();
        $propia = $this->crearJerarquia('Propia', $master->id);
        $ajena = $this->crearJerarquia('Ajena');
        $juego = $this->juegoLotto();

        // Cada jerarquía ya creó su límite a nivel banca (mismo juego+moneda).
        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/limites/'.$juego->id);

        $response->assertStatus(200);

        $bancas = collect($response->json())->pluck('banca_id')->all();
        $this->assertContains($propia['banca']->id, $bancas);
        $this->assertNotContains($ajena['banca']->id, $bancas);
    }

    // ==================================================
    // MASTER EN REPORTES Y ESTADÍSTICAS
    // ==================================================

    public function test_master_reportes_ventas_totales_solo_sus_bancas()
    {
        $master = $this->masterUser();
        $propia = $this->crearJerarquia('Propia', $master->id);
        $this->crearJerarquia('Ajena');

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/reportes/ventas-totales');

        $response->assertStatus(200);

        $entidades = collect($response->json('data'))->pluck('Entidad')->all();
        $this->assertContains('Banca Propia', $entidades);
        $this->assertNotContains('Banca Ajena', $entidades);
    }

    public function test_master_estadisticas_rendimiento_solo_sus_bancas()
    {
        $master = $this->masterUser();
        $this->crearJerarquia('Propia', $master->id);
        $this->crearJerarquia('Ajena');

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/estadisticas/rendimiento');

        $response->assertStatus(200);

        // La serie de ventas debe sumar solo la apuesta de su banca (100)
        $ventas = collect($response->json('series.ventas'))->sum();
        $this->assertEquals(100.0, $ventas);
    }

    // ==================================================
    // MASTER SIN BANCAS — VACÍO (NUNCA GLOBAL)
    // ==================================================

    public function test_master_sin_bancas_no_ve_entidades()
    {
        $master = $this->masterUser(); // sin master_id en ninguna banca
        $this->crearJerarquia('Ajena');

        // Bancas: 200 con lista vacía
        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/bancas');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json());

        // Grupos y taquillas: vacíos
        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/grupos');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json());

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/taquillas');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json());

        // Apuestas: vacío
        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/apuestas');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.data'));

        // Cierres: vacío
        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/cierre');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));

        // Límites: vacío
        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/limites/'.$this->juegoLotto()->id);
        $response->assertStatus(200);
        $this->assertCount(0, $response->json());

        // Reportes: sin datos
        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/reportes/ventas-totales');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_master_sin_bancas_no_accede_a_banca_ajena()
    {
        $master = $this->masterUser();
        $ajena = $this->crearJerarquia('Ajena');

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/bancas/'.$ajena['banca']->id);

        $response->assertStatus(403);
    }

    // ==================================================
    // SUPER MASTER SIGUE GLOBAL
    // ==================================================

    public function test_super_master_sigue_viendo_todo()
    {
        $super = $this->superUser();
        $this->crearJerarquia('Propia', $this->masterUser()->id);
        $this->crearJerarquia('Ajena');

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/bancas');

        $response->assertStatus(200);
        // Todas las bancas: las 2 creadas + BT001 del seeder
        $this->assertGreaterThanOrEqual(3, count($response->json()));
    }

    // ==================================================
    // REGRESIÓN: BANCA NO CAMBIA
    // ==================================================

    public function test_banca_sigue_sin_ver_otras_bancas()
    {
        $banca = $this->bancaUser();
        $ajena = $this->crearJerarquia('Ajena');

        // El rol banca no puede listar bancas (403, sin cambios)
        $response = $this->actingAs($banca, 'sanctum')
            ->getJson('/api/v1/bancas');
        $response->assertStatus(403);

        // Tampoco puede ver el detalle de una banca ajena
        $response = $this->actingAs($banca, 'sanctum')
            ->getJson('/api/v1/bancas/'.$ajena['banca']->id);
        $response->assertStatus(403);
    }
}
