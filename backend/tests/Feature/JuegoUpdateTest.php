<?php

namespace Tests\Feature;

use App\Models\Juego;
use App\Models\JuegoAuditoria;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S3 — PUT /api/v1/juegos/{juego} (deuda docs/dev/pendientes-front.md §7).
 *
 * `update()` persiste name/config, audita `accion=actualizar` con before/after
 * y updated_by, y está limitado a super_master|master (ruta) → 403 para otros
 * roles. La `config` es reemplazo COMPLETO, no merge (docs/dev/motor-premios.md §8).
 */
class JuegoUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function superUser(): User
    {
        return User::where('email', 'super@lotto.com')->firstOrFail();
    }

    private function juego(string $slug): Juego
    {
        return Juego::where('slug', $slug)->firstOrFail();
    }

    public function test_update_persiste_name_config_y_audita(): void
    {
        $user = $this->superUser();
        $juego = $this->juego('triple-zulia');
        $nameAntes = $juego->name;
        $configAntes = $juego->config;

        $nuevoName = 'Triple Zulia Editado';
        $nuevaConfig = array_merge($juego->config, ['custom' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}", [
                'name' => $nuevoName,
                'config' => $nuevaConfig,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('name', $nuevoName);

        $juego->refresh();
        $this->assertSame($nuevoName, $juego->name);
        $this->assertEqualsCanonicalizing($nuevaConfig, $juego->config);
        $this->assertSame($user->id, $juego->updated_by, 'updated_by se registra en el juego.');

        $auditoria = JuegoAuditoria::where('juego_id', $juego->id)
            ->where('accion', 'actualizar')
            ->firstOrFail();
        $this->assertSame($user->id, $auditoria->user_id);
        $this->assertSame($nameAntes, $auditoria->cambios['before']['name']);
        $this->assertSame($nuevoName, $auditoria->cambios['after']['name']);
        $this->assertEqualsCanonicalizing($configAntes, $auditoria->cambios['before']['config']);
        $this->assertEqualsCanonicalizing($nuevaConfig, $auditoria->cambios['after']['config']);
    }

    public function test_update_config_es_reemplazo_completo_no_merge(): void
    {
        // docs/dev/motor-premios.md §8: PUT /juegos/{id} con `config` es reemplazo
        // COMPLETO (por eso existe el endpoint /premios con merge seguro).
        $juego = $this->juego('triple-zulia');

        $this->actingAs($this->superUser(), 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}", ['config' => ['solo' => 'esto']])
            ->assertStatus(200);

        $this->assertSame(['solo' => 'esto'], $juego->fresh()->config);
    }

    public function test_update_403_para_rol_no_autorizado(): void
    {
        // Ruta dentro del grupo role:super_master|master → banca no puede.
        $banca = User::where('email', 'banca@lotto.com')->firstOrFail();
        $juego = $this->juego('triple-zulia');

        $this->actingAs($banca, 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}", ['name' => 'X'])
            ->assertStatus(403);
    }
}
