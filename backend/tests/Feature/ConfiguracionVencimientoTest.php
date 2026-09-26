<?php

namespace Tests\Feature;

use App\Models\Configuracion;
use App\Models\User;
use App\Services\ConfiguracionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F3 3.3 — Api/ConfiguracionController (design §5, REQ13).
 *
 * GET/PUT /api/v1/configuraciones/apuestas-vencimiento con
 * `middleware('role:super_master|master')`: solo esos roles leen/editan la
 * ventana; cualquier otro rol → 403 (scenario REQ13).
 */
class ConfiguracionVencimientoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function superUser(): User
    {
        $user = User::where('email', 'super@lotto.com')->first();
        $user->assignRole('super_master');

        return $user;
    }

    private function taquillaUser(): User
    {
        $user = User::factory()->create(['role' => 'taquilla']);
        $user->assignRole('taquilla');

        return $user;
    }

    public function test_get_ventana_devuelve_default_24_sin_fila(): void
    {
        // D5: sin fila → fallback 24 h.
        $this->assertSame(0, Configuracion::count());

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/configuraciones/apuestas-vencimiento');

        $response->assertStatus(200)
            ->assertJsonPath('horas', 24);
    }

    public function test_put_ventana_persiste_y_get_refleja_el_valor(): void
    {
        $user = $this->superUser();

        $put = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/configuraciones/apuestas-vencimiento', ['horas' => 48]);

        $put->assertStatus(200)->assertJsonPath('horas', 48);

        $this->assertDatabaseHas('configuraciones', [
            'key' => ConfiguracionService::CLAVE_VENCIMIENTO,
            'banca_id' => null,
        ]);

        $get = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/configuraciones/apuestas-vencimiento');

        $get->assertStatus(200)->assertJsonPath('horas', 48);
    }

    public function test_master_puede_leer_y_editar_la_ventana(): void
    {
        $master = User::factory()->create(['role' => 'master']);
        $master->assignRole('master');

        $get = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/configuraciones/apuestas-vencimiento');
        $get->assertStatus(200)->assertJsonPath('horas', 24);

        $put = $this->actingAs($master, 'sanctum')
            ->putJson('/api/v1/configuraciones/apuestas-vencimiento', ['horas' => 6]);
        $put->assertStatus(200)->assertJsonPath('horas', 6);
    }

    public function test_get_rechazado_403_para_rol_taquilla(): void
    {
        // REQ13: un rol distinto de super_master|master NO puede leer.
        $response = $this->actingAs($this->taquillaUser(), 'sanctum')
            ->getJson('/api/v1/configuraciones/apuestas-vencimiento');

        $response->assertStatus(403);
    }

    public function test_put_rechazado_403_para_rol_taquilla(): void
    {
        // REQ13: un rol distinto de super_master|master NO puede modificar.
        $response = $this->actingAs($this->taquillaUser(), 'sanctum')
            ->putJson('/api/v1/configuraciones/apuestas-vencimiento', ['horas' => 48]);

        $response->assertStatus(403);
        $this->assertSame(0, Configuracion::count(), 'Nada se persiste tras el 403.');
    }

    public function test_put_valida_horas_obligatorias_y_positivas(): void
    {
        $user = $this->superUser();

        $sinHoras = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/configuraciones/apuestas-vencimiento', []);
        $sinHoras->assertStatus(422);

        $cero = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/configuraciones/apuestas-vencimiento', ['horas' => 0]);
        $cero->assertStatus(422);

        $this->assertSame(0, Configuracion::count(), 'Los PUT inválidos no persisten.');
    }
}
