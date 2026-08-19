<?php

namespace Tests\Feature;

use App\Models\Banca;
use App\Models\Grupo;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PR 2 — informacion-fiscal.
 *
 * Los controllers de banca, grupo y agencia aceptan y persisten los
 * campos fiscales opcionales; las respuestas GET los incluyen.
 */
class InformacionFiscalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);
    }

    private function superUser(): User
    {
        $super = User::where('email', 'super@lotto.com')->first();
        $super->assignRole('super_master');

        return $super;
    }

    private function datosFiscales(string $prefijo): array
    {
        return [
            'rif' => "J-{$prefijo}1234567-8",
            'email' => "{$prefijo}@empresa.com",
            'telefono' => '+58-414-1234567',
            'direccion' => 'Av. Principal, Edif. Centro',
            'estado' => 'Zulia',
            'municipio' => 'Maracaibo',
        ];
    }

    public function test_fiscal_info_se_guarda_en_banca()
    {
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/bancas', array_merge([
                'name' => 'Banca Fiscal',
                'code' => 'BFISC',
                'user_name' => 'Usuario Banca Fiscal',
                'user_email' => 'banca-fiscal@lotto.com',
                'user_password' => 'password123',
            ], $this->datosFiscales('banca')));

        $response->assertStatus(201);

        $bancaId = $response->json('banca.id');
        $this->assertDatabaseHas('bancas', [
            'id' => $bancaId,
            'rif' => 'J-banca1234567-8',
            'email' => 'banca@empresa.com',
            'telefono' => '+58-414-1234567',
            'direccion' => 'Av. Principal, Edif. Centro',
            'estado' => 'Zulia',
            'municipio' => 'Maracaibo',
        ]);

        // R5: los GET devuelven los campos fiscales
        $get = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/bancas/'.$bancaId);

        $get->assertStatus(200)
            ->assertJsonPath('rif', 'J-banca1234567-8')
            ->assertJsonPath('email', 'banca@empresa.com')
            ->assertJsonPath('estado', 'Zulia');
    }

    public function test_fiscal_info_se_guarda_en_grupo()
    {
        $banca = Banca::create(['name' => 'Banca Fiscal G', 'code' => 'BFISCG', 'active' => true]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/grupos', array_merge([
                'name' => 'Grupo Fiscal',
                'code' => 'GFISC',
                'banca_id' => $banca->id,
                'user_name' => 'Usuario Grupo Fiscal',
                'user_email' => 'grupo-fiscal@lotto.com',
                'user_password' => 'password123',
            ], $this->datosFiscales('grupo')));

        $response->assertStatus(201);

        $this->assertDatabaseHas('grupos', [
            'code' => 'GFISC',
            'rif' => 'J-grupo1234567-8',
            'email' => 'grupo@empresa.com',
            'telefono' => '+58-414-1234567',
            'direccion' => 'Av. Principal, Edif. Centro',
            'estado' => 'Zulia',
            'municipio' => 'Maracaibo',
        ]);
    }

    public function test_fiscal_info_se_guarda_en_taquilla()
    {
        $banca = Banca::create(['name' => 'Banca Fiscal T', 'code' => 'BFISCT', 'active' => true]);
        $grupo = Grupo::create(['name' => 'Grupo Fiscal T', 'code' => 'GFISCT', 'banca_id' => $banca->id, 'active' => true]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', array_merge([
                'name' => 'Agencia Fiscal',
                'code' => 'TFISC',
                'grupo_id' => $grupo->id,
                'user_name' => 'Usuario Agencia Fiscal',
                'user_email' => 'taquilla-fiscal@lotto.com',
                'user_password' => 'password123',
            ], $this->datosFiscales('taquilla')));

        $response->assertStatus(201);

        $this->assertDatabaseHas('taquillas', [
            'code' => 'TFISC',
            'rif' => 'J-taquilla1234567-8',
            'email' => 'taquilla@empresa.com',
            'telefono' => '+58-414-1234567',
            'direccion' => 'Av. Principal, Edif. Centro',
            'estado' => 'Zulia',
            'municipio' => 'Maracaibo',
        ]);
    }
}
