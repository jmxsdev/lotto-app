<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PR 1 (S1) — default global de comisiones.
 *
 * GET /api/v1/comisiones/defaults expone las 2 filas sembradas (bs/usd);
 * PUT las sobreescribe; roles sin manage_comisiones reciben 403.
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

    private function bancaUser(): User
    {
        $user = User::where('email', 'banca@lotto.com')->first();
        $user->assignRole('banca');

        return $user;
    }

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
}
