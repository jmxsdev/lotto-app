<?php

namespace Tests\Feature;

use App\Models\Juego;
use App\Models\JuegoAuditoria;
use App\Models\PluginJuego;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S3 — PATCH /api/v1/juegos/{juego}/toggle (deuda docs/dev/pendientes-front.md §7;
 * spec configuracion-premios REQ "Toggle del juego").
 *
 * El endpoint requiere body `{active: bool}` (422 si falta), persiste el
 * estado, sincroniza el plugin del juego y audita con `accion=activar` o
 * `accion=desactivar` (before/after) + updated_by.
 */
class JuegoToggleTest extends TestCase
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

    public function test_toggle_422_sin_body(): void
    {
        // REQ toggle: `active` es required|boolean → sin body no hay toggle.
        $juego = $this->juego('triple-zulia');

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->patchJson("/api/v1/juegos/{$juego->id}/toggle");

        $response->assertStatus(422);

        $this->assertTrue($juego->fresh()->active, 'El 422 no debe cambiar el estado vigente.');
    }

    public function test_toggle_desactiva_persiste_y_audita(): void
    {
        $user = $this->superUser();
        $juego = $this->juego('triple-zulia');
        $this->assertTrue($juego->active);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/juegos/{$juego->id}/toggle", ['active' => false]);

        $response->assertStatus(200);
        $response->assertJsonPath('active', false);
        $this->assertFalse($juego->fresh()->active, 'El estado debe persistir en BD.');
        $this->assertSame($user->id, $juego->fresh()->updated_by, 'updated_by se registra.');

        $auditoria = JuegoAuditoria::where('juego_id', $juego->id)
            ->where('accion', 'desactivar')
            ->firstOrFail();
        $this->assertSame($user->id, $auditoria->user_id);
        $this->assertSame(['active' => true], $auditoria->cambios['before']);
        $this->assertSame(['active' => false], $auditoria->cambios['after']);
    }

    public function test_toggle_activa_audita_accion_activar(): void
    {
        $user = $this->superUser();
        $juego = $this->juego('triple-zulia');
        $juego->update(['active' => false]);
        PluginJuego::where('juego_id', $juego->id)->update(['active' => false]);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/juegos/{$juego->id}/toggle", ['active' => true]);

        $response->assertStatus(200);
        $response->assertJsonPath('active', true);
        $this->assertTrue($juego->fresh()->active);

        $auditoria = JuegoAuditoria::where('juego_id', $juego->id)
            ->where('accion', 'activar')
            ->firstOrFail();
        $this->assertSame($user->id, $auditoria->user_id);
        $this->assertSame(['active' => false], $auditoria->cambios['before']);
        $this->assertSame(['active' => true], $auditoria->cambios['after']);
    }

    public function test_toggle_plugin_sincronizado_en_ambos_sentidos(): void
    {
        // El plugin debe reflejar el estado del juego tanto al desactivar
        // como al REACTIVAR (la relación `pluginJuego` filtra por active=true,
        // así que la reactivación debe mirar la fila del plugin, no la relación).
        $user = $this->superUser();
        $juego = $this->juego('triple-zulia');
        $plugin = PluginJuego::where('juego_id', $juego->id)->firstOrFail();
        $this->assertTrue($plugin->active);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/juegos/{$juego->id}/toggle", ['active' => false])
            ->assertStatus(200);
        $this->assertFalse($plugin->fresh()->active, 'El plugin se desactiva con el juego.');

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/juegos/{$juego->id}/toggle", ['active' => true])
            ->assertStatus(200);
        $this->assertTrue($plugin->fresh()->active, 'El plugin se reactiva con el juego.');
    }
}
