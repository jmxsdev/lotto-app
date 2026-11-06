<?php

namespace Tests\Feature;

use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WU-1b — matriz jerárquica de usuarios (`UserPolicy`).
 *
 * Cubre: super_master intocable en rol y borrado; master restringido a su
 * subárbol/creados y prohibido sobre super/pares/creador; banca/grupo/agencia
 * solo gestionan su subárbol o sus creados de rango menor; taquilla solo
 * lectura de su propio usuario; `created_by` como metadata de fila.
 */
class AlcanceJerarquicoUsuariosTest extends TestCase
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

    private function masterUser(): User
    {
        $master = User::where('email', 'master@lotto.com')->firstOrFail();

        // El master administra la banca sembrada; sin ella solo vería creados.
        Banca::where('code', 'BT001')->update(['master_id' => $master->id]);

        return $master;
    }

    private function bancaUser(): User
    {
        return User::where('email', 'banca@lotto.com')->firstOrFail();
    }

    private function grupoUser(): User
    {
        return User::where('email', 'grupo@lotto.com')->firstOrFail();
    }

    private function agenciaUser(): User
    {
        return User::where('email', 'agencia@lotto.com')->firstOrFail();
    }

    private function demoUser(): User
    {
        return User::where('email', 'demo@lotto.com')->firstOrFail();
    }

    /**
     * Headers de la taquilla DEMO pre-activada en el seeder.
     */
    private function demoHeaders(): array
    {
        return [
            'X-Device-MAC' => '00:1A:2B:3C:4D:5E',
            'X-Device-Fingerprint' => 'demo-device-001',
        ];
    }

    private function bancaSeeded(): Banca
    {
        return Banca::where('code', 'BT001')->firstOrFail();
    }

    private function grupoSeeded(): Grupo
    {
        return Grupo::where('code', 'GT001')->firstOrFail();
    }

    private function payloadUsuario(array $overrides = []): array
    {
        return array_merge([
            'user_name' => 'Usuario Nuevo',
            'user_email' => 'nuevo-'.uniqid().'@lotto.com',
            'user_password' => 'password123',
        ], $overrides);
    }

    // ==================================================
    // super_master intocable
    // ==================================================

    public function test_master_no_puede_editar_super_master_y_password_intacto()
    {
        $super = $this->superUser();
        $hashOriginal = $super->password;

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->putJson('/api/v1/users/'.$super->id, [
                'password' => 'intento-de-cambio',
            ]);

        $response->assertStatus(403);
        $this->assertSame($hashOriginal, $super->fresh()->password);
    }

    public function test_master_no_puede_eliminar_super_master()
    {
        $super = $this->superUser();

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->deleteJson('/api/v1/users/'.$super->id);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $super->id]);
    }

    public function test_super_master_no_puede_eliminar_a_otro_super_master()
    {
        $otroSuper = User::factory()->withRole('super_master')->create();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->deleteJson('/api/v1/users/'.$otroSuper->id);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $otroSuper->id]);
    }

    public function test_super_master_no_puede_eliminarse_a_si_mismo()
    {
        $super = $this->superUser();

        $response = $this->actingAs($super, 'sanctum')
            ->deleteJson('/api/v1/users/'.$super->id);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $super->id]);
    }

    public function test_master_no_puede_eliminar_a_su_creador()
    {
        $master = $this->masterUser();
        $creador = User::findOrFail($master->created_by);

        $response = $this->actingAs($master, 'sanctum')
            ->deleteJson('/api/v1/users/'.$creador->id);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $creador->id]);
    }

    public function test_master_no_puede_editar_a_otro_master()
    {
        $otroMaster = User::factory()->withRole('master')->create();

        $response = $this->actingAs($this->masterUser(), 'sanctum')
            ->putJson('/api/v1/users/'.$otroMaster->id, [
                'name' => 'Intento sobre par',
            ]);

        $response->assertStatus(403);
        $this->assertNotSame('Intento sobre par', $otroMaster->fresh()->name);
    }

    // ==================================================
    // Pares y superiores no se crean
    // ==================================================

    public function test_banca_no_puede_crear_otra_banca()
    {
        $banca = $this->bancaSeeded();
        $email = 'banca-par-'.uniqid().'@lotto.com';

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->postJson('/api/v1/users', $this->payloadUsuario([
                'user_email' => $email,
                'role' => 'banca',
                'banca_id' => $banca->id,
            ]));

        $response->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    public function test_grupo_no_puede_crear_otro_grupo()
    {
        $grupo = $this->grupoSeeded();
        $email = 'grupo-par-'.uniqid().'@lotto.com';

        $response = $this->actingAs($this->grupoUser(), 'sanctum')
            ->postJson('/api/v1/users', $this->payloadUsuario([
                'user_email' => $email,
                'role' => 'grupo',
                'grupo_id' => $grupo->id,
            ]));

        $response->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    public function test_agencia_no_puede_crear_otra_agencia()
    {
        $agencia = $this->agenciaUser();
        $local = $agencia->agencia;
        $email = 'agencia-par-'.uniqid().'@lotto.com';

        $response = $this->actingAs($agencia, 'sanctum')
            ->postJson('/api/v1/users', $this->payloadUsuario([
                'user_email' => $email,
                'role' => 'agencia',
                'agencia_id' => $local->id,
            ]));

        $response->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    // ==================================================
    // Borrado de rango menor dentro del subárbol
    // ==================================================

    public function test_banca_puede_eliminar_usuario_de_rango_menor_de_su_banca()
    {
        $target = User::where('email', 'grupo@lotto.com')->firstOrFail();

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->deleteJson('/api/v1/users/'.$target->id, ['reason_code' => 'employee_offboarding']);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_grupo_puede_eliminar_usuario_de_rango_menor_de_su_grupo()
    {
        $target = User::where('email', 'taquilla@lotto.com')->firstOrFail();

        $response = $this->actingAs($this->grupoUser(), 'sanctum')
            ->deleteJson('/api/v1/users/'.$target->id, ['reason_code' => 'employee_offboarding']);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_agencia_puede_eliminar_usuario_de_rango_menor_de_su_local()
    {
        $agencia = $this->agenciaUser();
        $local = $agencia->agencia;

        $taquilla = Taquilla::create([
            'name' => 'Taquilla del local',
            'code' => 'TAL01',
            'grupo_id' => $local->grupo_id,
            'agencia_id' => $local->id,
            'activation_code' => 'TAL01-CODE',
            'active' => true,
            'created_by' => $agencia->id,
        ]);

        $target = User::factory()->withRole('taquilla')->create([
            'banca_id' => $local->grupo->banca_id,
            'grupo_id' => $local->grupo_id,
            'agencia_id' => $local->id,
            'taquilla_id' => $taquilla->id,
            'created_by' => $agencia->id,
        ]);

        $response = $this->actingAs($agencia, 'sanctum')
            ->deleteJson('/api/v1/users/'.$target->id, ['reason_code' => 'employee_offboarding']);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_banca_no_puede_eliminar_banca_par_de_otra_banca()
    {
        $otraBanca = Banca::factory()->create(['active' => true]);
        $par = User::factory()->withRole('banca')->create([
            'banca_id' => $otraBanca->id,
        ]);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->deleteJson('/api/v1/users/'.$par->id);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $par->id]);
    }

    public function test_banca_no_puede_eliminar_usuario_de_rango_menor_de_otra_banca()
    {
        $otraBanca = Banca::factory()->create(['active' => true]);
        $target = User::factory()->withRole('taquilla')->create([
            'banca_id' => $otraBanca->id,
        ]);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->deleteJson('/api/v1/users/'.$target->id, ['reason_code' => 'employee_offboarding']);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_banca_no_puede_mover_usuario_fuera_de_su_subarbol()
    {
        $target = User::where('email', 'taquilla@lotto.com')->firstOrFail();

        $otraBanca = Banca::factory()->create(['active' => true]);
        $otroGrupo = Grupo::factory()->create([
            'banca_id' => $otraBanca->id,
            'active' => true,
        ]);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->putJson('/api/v1/users/'.$target->id, [
                'grupo_id' => $otroGrupo->id,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'grupo_id' => $this->grupoSeeded()->id,
        ]);
    }

    public function test_banca_no_puede_autopromocionarse()
    {
        $banca = $this->bancaUser();

        $response = $this->actingAs($banca, 'sanctum')
            ->putJson('/api/v1/users/'.$banca->id, [
                'role' => 'master',
            ]);

        $response->assertStatus(403);
        $this->assertTrue($banca->fresh()->hasRole('banca'));
        $this->assertFalse($banca->fresh()->hasRole('master'));
    }

    // ==================================================
    // Taquilla: solo lectura de su propio usuario
    // ==================================================

    public function test_taquilla_solo_puede_ver_su_propio_usuario()
    {
        $demo = $this->demoUser();
        $otro = User::factory()->withRole('taquilla')->create();

        $this->actingAs($demo, 'sanctum')
            ->withHeaders($this->demoHeaders())
            ->getJson('/api/v1/users/'.$demo->id)
            ->assertStatus(200);

        $this->actingAs($demo, 'sanctum')
            ->withHeaders($this->demoHeaders())
            ->getJson('/api/v1/users/'.$otro->id)
            ->assertStatus(403);
    }

    public function test_taquilla_no_puede_editar_ni_borrar_su_usuario()
    {
        $demo = $this->demoUser();

        $this->actingAs($demo, 'sanctum')
            ->withHeaders($this->demoHeaders())
            ->putJson('/api/v1/users/'.$demo->id, ['name' => 'Intento Taquilla'])
            ->assertStatus(403);

        $this->actingAs($demo, 'sanctum')
            ->withHeaders($this->demoHeaders())
            ->deleteJson('/api/v1/users/'.$demo->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $demo->id]);
    }

    // ==================================================
    // created_by: metadata de fila, nunca del payload
    // ==================================================

    public function test_created_by_del_payload_se_ignora()
    {
        $actor = $this->superUser();
        $otro = $this->masterUser();
        $banca = $this->bancaSeeded();

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/users', $this->payloadUsuario([
                'role' => 'banca',
                'banca_id' => $banca->id,
                'created_by' => $otro->id,
            ]));

        $response->assertStatus(201);

        $creado = User::where('email', $response->json('email'))->firstOrFail();

        $this->assertSame((int) $actor->id, (int) $creado->created_by);
        $this->assertNotSame((int) $otro->id, (int) $creado->created_by);
    }

    public function test_index_incluye_usuarios_creados_y_excluye_ajenos()
    {
        $banca = $this->bancaUser();

        $creado = User::factory()->withRole('taquilla')->create([
            'created_by' => $banca->id,
        ]);

        $otraBanca = Banca::factory()->create(['active' => true]);
        $ajeno = User::factory()->withRole('taquilla')->create([
            'banca_id' => $otraBanca->id,
        ]);

        $response = $this->actingAs($banca, 'sanctum')
            ->getJson('/api/v1/users');

        $response->assertStatus(200);

        $emails = collect($response->json())->pluck('email');

        $this->assertContains($creado->email, $emails);
        $this->assertNotContains($ajeno->email, $emails);
        $this->assertNotContains('super@lotto.com', $emails);
    }
}
