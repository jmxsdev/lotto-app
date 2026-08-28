<?php

namespace Tests\Unit;

use App\Models\Agencia;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgenciasBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function crearJerarquiaSinAgencia(): array
    {
        $banca = Banca::factory()->create(['name' => 'Banca Backfill', 'code' => 'BB001']);
        $grupo1 = Grupo::factory()->create(['name' => 'Grupo Uno', 'code' => 'GU001', 'banca_id' => $banca->id]);
        $grupo2 = Grupo::factory()->create(['name' => 'Grupo Dos', 'code' => 'GU002', 'banca_id' => $banca->id]);

        $taquilla1 = Taquilla::factory()->create(['grupo_id' => $grupo1->id, 'code' => 'TB001']);
        $taquilla2 = Taquilla::factory()->create(['grupo_id' => $grupo2->id, 'code' => 'TB002']);

        $user1 = User::factory()->create([
            'role' => 'taquilla',
            'taquilla_id' => $taquilla1->id,
            'grupo_id' => $grupo1->id,
            'banca_id' => $banca->id,
        ]);

        return [$banca, $grupo1, $grupo2, $taquilla1, $taquilla2, $user1];
    }

    public function test_primera_ejecucion_crea_un_local_por_grupo_y_vincula_taquillas_y_usuarios()
    {
        [, $grupo1, $grupo2, $taquilla1, $taquilla2, $user1] = $this->crearJerarquiaSinAgencia();

        $this->artisan('agencias:backfill')->assertSuccessful();

        // 1 local por grupo (2 grupos nuevos → 2 agencias + 1 del seeder)
        $this->assertEquals(1, Agencia::where('grupo_id', $grupo1->id)->count());
        $this->assertEquals(1, Agencia::where('grupo_id', $grupo2->id)->count());

        $agencia1 = Agencia::where('grupo_id', $grupo1->id)->first();
        $this->assertNotNull($agencia1);
        $this->assertEquals('Grupo Uno - Local', $agencia1->name);
        $this->assertEquals('GU001-L01', $agencia1->code);

        $agencia2 = Agencia::where('grupo_id', $grupo2->id)->first();
        $this->assertNotNull($agencia2);
        $this->assertEquals('Grupo Dos - Local', $agencia2->name);
        $this->assertEquals('GU002-L01', $agencia2->code);

        // Taquillas vinculadas a la agencia de su grupo
        $taquilla1->refresh();
        $taquilla2->refresh();
        $this->assertEquals($agencia1->id, $taquilla1->agencia_id);
        $this->assertEquals($agencia2->id, $taquilla2->agencia_id);

        // Usuario rol taquilla vinculado a la agencia de su taquilla
        $user1->refresh();
        $this->assertEquals($agencia1->id, $user1->agencia_id);
    }

    public function test_reejecucion_no_duplica_agencias()
    {
        [, $grupo1, $grupo2, $taquilla1, , $user1] = $this->crearJerarquiaSinAgencia();

        $this->artisan('agencias:backfill')->assertSuccessful();
        $this->artisan('agencias:backfill')->assertSuccessful();

        // Sigue habiendo 1 local por grupo (2 nuevos + 1 del seeder)
        $this->assertDatabaseCount('agencias', 3);
        $this->assertEquals(1, Agencia::where('grupo_id', $grupo1->id)->count());
        $this->assertEquals(1, Agencia::where('grupo_id', $grupo2->id)->count());

        // Los vínculos se conservan
        $taquilla1->refresh();
        $user1->refresh();
        $this->assertNotNull($taquilla1->agencia_id);
        $this->assertNotNull($user1->agencia_id);
    }

    public function test_produccion_exige_force()
    {
        $this->crearJerarquiaSinAgencia();

        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('agencias:backfill')->assertFailed();

        // Nada se creó sin --force (solo queda la agencia del seeder)
        $this->assertDatabaseCount('agencias', 1);

        $this->artisan('agencias:backfill', ['--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('agencias', 3);
    }

    public function test_master_id_se_asigna_desde_created_by_solo_si_creador_es_master()
    {
        $super = User::where('email', 'super@lotto.com')->first();
        $master = User::where('email', 'master@lotto.com')->first();
        $master->assignRole('master');

        // Banca creada por un master → master_id debe apuntar al master
        $bancaMaster = Banca::factory()->create([
            'name' => 'Banca Master',
            'code' => 'BM001',
            'created_by' => $master->id,
        ]);

        // Banca creada por un super → master_id debe quedar null
        $bancaSuper = Banca::factory()->create([
            'name' => 'Banca Super',
            'code' => 'BS001',
            'created_by' => $super->id,
        ]);

        $this->artisan('agencias:backfill')->assertSuccessful();

        $bancaMaster->refresh();
        $bancaSuper->refresh();
        $this->assertEquals($master->id, $bancaMaster->master_id);
        $this->assertNull($bancaSuper->master_id);
    }

    public function test_usuarios_rol_no_taquilla_no_reciben_agencia_id()
    {
        $banca = Banca::factory()->create(['name' => 'Banca Grupos', 'code' => 'BG001']);
        $grupo = Grupo::factory()->create(['name' => 'Grupo Usuarios', 'code' => 'GUS01', 'banca_id' => $banca->id]);

        $userGrupo = User::factory()->create([
            'role' => 'grupo',
            'grupo_id' => $grupo->id,
            'banca_id' => $banca->id,
        ]);

        $this->artisan('agencias:backfill')->assertSuccessful();

        $userGrupo->refresh();
        $this->assertNull($userGrupo->agencia_id);
    }
}
