<?php

namespace Tests\Feature;

use App\Models\Master;
use App\Models\Operador;
use App\Models\SuperMaster;
use App\Models\User;
use Database\Seeders\EntidadesRaizSeeder;
use Database\Seeders\OperadoresSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifica las entidades raíz (`super_masters`/`masters`) y el vínculo único
 * de operador por entidad. No cubre todavía el switch de `bancas.master_id`
 * a `masters.id`.
 */
class EntidadesRaizYOperadoresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesAndPermissionsSeeder::class,
            UsersSeeder::class,
            EntidadesRaizSeeder::class,
            OperadoresSeeder::class,
        ]);
    }

    public function test_seed_crea_entidades_raiz_y_operadores(): void
    {
        $this->assertSame(1, SuperMaster::count());
        $this->assertSame(1, Master::count());
        $this->assertSame(7, Operador::count());

        $super = User::where('email', 'super@lotto.com')->firstOrFail();
        $master = User::where('email', 'master@lotto.com')->firstOrFail();

        $this->assertInstanceOf(SuperMaster::class, $super->operador->entidad);
        $this->assertInstanceOf(Master::class, $master->operador->entidad);
        $this->assertSame(
            $super->operador->entidad->id,
            $master->operador->entidad->superMaster->id,
        );

        $this->assertEqualsCanonicalizing(
            ['super_master', 'master', 'banca', 'grupo', 'taquilla', 'agencia'],
            Operador::query()->pluck('entidad_tipo')->unique()->values()->all(),
        );
    }

    public function test_no_permite_dos_operadores_activos_para_la_misma_entidad(): void
    {
        $super = User::where('email', 'super@lotto.com')->firstOrFail();
        $otro = User::factory()->create();

        $this->expectException(QueryException::class);

        Operador::create([
            'user_id' => $otro->id,
            'entidad_tipo' => 'super_master',
            'entidad_id' => $super->operador->entidad_id,
            'active' => true,
        ]);
    }
}
