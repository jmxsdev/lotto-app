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

class AgenciaModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function crearAgenciaConDescendientes(): array
    {
        $banca = Banca::factory()->create(['name' => 'Banca Model', 'code' => 'BM001']);
        $grupo = Grupo::factory()->create(['name' => 'Grupo Model', 'code' => 'GM001', 'banca_id' => $banca->id]);
        $agencia = Agencia::factory()->create(['name' => 'Local Model', 'code' => 'LM001', 'grupo_id' => $grupo->id]);
        $taquilla = Taquilla::factory()->create(['grupo_id' => $grupo->id, 'agencia_id' => $agencia->id, 'code' => 'TM001']);
        $user = User::factory()->create([
            'role' => 'taquilla',
            'taquilla_id' => $taquilla->id,
            'agencia_id' => $agencia->id,
        ]);

        return [$banca, $grupo, $agencia, $taquilla, $user];
    }

    public function test_agencia_pertenece_a_grupo_y_tiene_taquillas_y_usuarios()
    {
        [, $grupo, $agencia, $taquilla, $user] = $this->crearAgenciaConDescendientes();

        $this->assertEquals($grupo->id, $agencia->grupo->id);
        $this->assertTrue($agencia->taquillas->contains($taquilla));
        $this->assertTrue($agencia->users->contains($user));
        $this->assertEquals($agencia->id, $taquilla->agencia->id);
        $this->assertEquals($agencia->id, $user->agencia->id);
        $this->assertTrue($grupo->agencias->contains($agencia));
    }

    public function test_eliminar_agencia_pone_null_agencia_id_en_taquillas_y_usuarios()
    {
        [, , $agencia, $taquilla, $user] = $this->crearAgenciaConDescendientes();

        $agencia->forceDelete();

        $taquilla->refresh();
        $user->refresh();
        $this->assertNull($taquilla->agencia_id);
        $this->assertNull($user->agencia_id);
    }

    public function test_master_banca_ids_devuelve_solo_bancas_del_master()
    {
        $master = User::where('email', 'master@lotto.com')->first();
        $super = User::where('email', 'super@lotto.com')->first();

        $banca1 = Banca::factory()->create(['name' => 'Banca Master 1', 'code' => 'BM101', 'master_id' => $master->id]);
        $banca2 = Banca::factory()->create(['name' => 'Banca Master 2', 'code' => 'BM102', 'master_id' => $master->id]);
        Banca::factory()->create(['name' => 'Banca Ajena', 'code' => 'BM103']);

        $ids = $master->masterBancaIds();
        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains($banca1->id));
        $this->assertTrue($ids->contains($banca2->id));
        $this->assertFalse($ids->contains(Banca::where('code', 'BM103')->first()->id));

        // El super (sin bancas como master) obtiene lista vacía
        $this->assertCount(0, $super->masterBancaIds());
    }

    public function test_banca_master_relacion_devuelve_usuario()
    {
        $master = User::where('email', 'master@lotto.com')->first();
        $banca = Banca::factory()->create(['name' => 'Banca Rel', 'code' => 'BREL', 'master_id' => $master->id]);

        $this->assertEquals($master->id, $banca->master->id);
    }
}
