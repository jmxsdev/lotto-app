<?php

namespace Tests\Feature;

use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * PR 5 — filtros de entidad en GET /api/users (tarea 3.4).
 *
 * Los filtros explícitos banca_id/grupo_id/taquilla_id intersectan el alcance
 * jerárquico del rol: un filtro ajeno devuelve lista vacía (nunca datos de
 * otras entidades, nunca 403). El rol taquilla solo se ve a sí misma.
 */
class GestionEntidadesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
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

    private function grupoUser(): User
    {
        $user = User::where('email', 'grupo@lotto.com')->first();
        $user->assignRole('grupo');

        return $user;
    }

    private function bancaSeeded(): Banca
    {
        return Banca::where('code', 'BT001')->first();
    }

    private function grupoSeeded(): Grupo
    {
        return Grupo::where('code', 'GT001')->first();
    }

    private function taquillaSeeded(): Taquilla
    {
        return Taquilla::where('code', 'TT001')->first();
    }

    /**
     * Crea una banca ajena con un grupo y una taquilla propios.
     *
     * @return array{0: Banca, 1: Grupo, 2: Taquilla}
     */
    private function bancaAjena(): array
    {
        $super = $this->superUser();
        $banca = Banca::create(['name' => 'Banca Ajena', 'code' => 'BAJ01', 'created_by' => $super->id]);
        $grupo = Grupo::create(['name' => 'Grupo Ajeno', 'code' => 'GAJ01', 'banca_id' => $banca->id, 'created_by' => $super->id]);
        $taquilla = Taquilla::create([
            'name' => 'Agencia Ajena',
            'code' => 'AAJ01',
            'grupo_id' => $grupo->id,
            'activation_code' => 'AAJ01-CODE',
            'active' => true,
            'created_by' => $super->id,
        ]);

        return [$banca, $grupo, $taquilla];
    }

    /**
     * Crea un usuario con rol y vínculos de entidad dados.
     */
    private function crearUsuario(string $role, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge(['role' => $role], $overrides));
        $user->assignRole($role);

        return $user;
    }

    private function emails($response): Collection
    {
        return collect($response->json())->pluck('email');
    }

    // ==================================================
    // SUPER MASTER: FILTROS EXPLÍCITOS
    // ==================================================

    public function test_super_master_filtra_por_banca()
    {
        [$bancaAjena] = $this->bancaAjena();
        $usuarioAjeno = $this->crearUsuario('banca', ['banca_id' => $bancaAjena->id]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/users?banca_id=' . $this->bancaSeeded()->id);

        $response->assertStatus(200);

        $emails = $this->emails($response);

        // Usuarios de la banca sembrada (BT001), incluidos los de su cadena
        $this->assertContains('banca@lotto.com', $emails);
        $this->assertContains('grupo@lotto.com', $emails);
        $this->assertContains('taquilla@lotto.com', $emails);
        $this->assertContains('demo@lotto.com', $emails);

        // Sin banca o de otra banca quedan fuera
        $this->assertNotContains('super@lotto.com', $emails);
        $this->assertNotContains('master@lotto.com', $emails);
        $this->assertNotContains($usuarioAjeno->email, $emails);
    }

    public function test_super_master_filtra_por_grupo()
    {
        $grupo = $this->grupoSeeded();
        $otroGrupo = Grupo::create(['name' => 'Otro Grupo', 'code' => 'OGR01', 'banca_id' => $grupo->banca_id, 'created_by' => $this->superUser()->id]);
        $usuarioOtroGrupo = $this->crearUsuario('grupo', ['banca_id' => $otroGrupo->banca_id, 'grupo_id' => $otroGrupo->id]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/users?grupo_id=' . $grupo->id);

        $response->assertStatus(200);

        $emails = $this->emails($response);

        // Usuarios del grupo sembrado (GT001), incluidos los de sus agencias
        $this->assertContains('grupo@lotto.com', $emails);
        $this->assertContains('taquilla@lotto.com', $emails);
        $this->assertContains('demo@lotto.com', $emails);

        $this->assertNotContains('banca@lotto.com', $emails);
        $this->assertNotContains('super@lotto.com', $emails);
        $this->assertNotContains($usuarioOtroGrupo->email, $emails);
    }

    public function test_super_master_filtra_por_taquilla()
    {
        $taquilla = $this->taquillaSeeded();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/users?taquilla_id=' . $taquilla->id);

        $response->assertStatus(200);

        // Solo el usuario de TT001; demo@lotto.com está en DEMO01
        $this->assertEquals(['taquilla@lotto.com'], $this->emails($response)->values()->all());
    }

    // ==================================================
    // BANCA: ALCANCE + FILTROS
    // ==================================================

    public function test_banca_solo_ve_usuarios_de_su_banca()
    {
        [$bancaAjena] = $this->bancaAjena();
        $usuarioAjeno = $this->crearUsuario('banca', ['banca_id' => $bancaAjena->id]);

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->getJson('/api/users');

        $response->assertStatus(200);

        $emails = $this->emails($response);

        // Su banca, por vínculo directo o por cadena
        $this->assertContains('banca@lotto.com', $emails);
        $this->assertContains('grupo@lotto.com', $emails);
        $this->assertContains('taquilla@lotto.com', $emails);
        $this->assertContains('demo@lotto.com', $emails);

        $this->assertNotContains('super@lotto.com', $emails);
        $this->assertNotContains('master@lotto.com', $emails);
        $this->assertNotContains($usuarioAjeno->email, $emails);
    }

    public function test_banca_filtro_grupo_propio_ok()
    {
        $grupo = $this->grupoSeeded();

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->getJson('/api/users?grupo_id=' . $grupo->id);

        $response->assertStatus(200);

        $emails = $this->emails($response);

        // Usuarios de su grupo (GT001)
        $this->assertContains('grupo@lotto.com', $emails);
        $this->assertContains('taquilla@lotto.com', $emails);
        $this->assertContains('demo@lotto.com', $emails);

        // El propio usuario banca no tiene grupo vinculado
        $this->assertNotContains('banca@lotto.com', $emails);
        $this->assertNotContains('super@lotto.com', $emails);
    }

    public function test_banca_filtro_grupo_ajeno_vacio()
    {
        [, $grupoAjeno] = $this->bancaAjena();
        $this->crearUsuario('grupo', ['banca_id' => $grupoAjeno->banca_id, 'grupo_id' => $grupoAjeno->id]);

        // El filtro no amplía el alcance: grupo de otra banca → lista vacía
        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->getJson('/api/users?grupo_id=' . $grupoAjeno->id);

        $response->assertStatus(200);
        $this->assertEmpty($response->json());
    }

    // ==================================================
    // GRUPO: ALCANCE + FILTROS
    // ==================================================

    public function test_grupo_filtro_taquilla_propia_ok()
    {
        $taquilla = $this->taquillaSeeded();

        $response = $this->actingAs($this->grupoUser(), 'sanctum')
            ->getJson('/api/users?taquilla_id=' . $taquilla->id);

        $response->assertStatus(200);

        $emails = $this->emails($response);

        $this->assertContains('taquilla@lotto.com', $emails);
        // demo@lotto.com es de DEMO01 (otra taquilla de su grupo)
        $this->assertNotContains('demo@lotto.com', $emails);
        // El usuario grupo no tiene taquilla vinculada
        $this->assertNotContains('grupo@lotto.com', $emails);
    }

    public function test_grupo_filtro_taquilla_ajena_vacia()
    {
        $grupo = $this->grupoSeeded();
        $otroGrupo = Grupo::create(['name' => 'Grupo Vecino', 'code' => 'GVZ01', 'banca_id' => $grupo->banca_id, 'created_by' => $this->superUser()->id]);
        $otraTaquilla = Taquilla::create([
            'name' => 'Agencia Vecina',
            'code' => 'AVZ01',
            'grupo_id' => $otroGrupo->id,
            'activation_code' => 'AVZ01-CODE',
            'active' => true,
            'created_by' => $this->superUser()->id,
        ]);
        $this->crearUsuario('taquilla', [
            'banca_id' => $otroGrupo->banca_id,
            'grupo_id' => $otroGrupo->id,
            'taquilla_id' => $otraTaquilla->id,
        ]);

        // Taquilla de otro grupo (aunque sea de su misma banca) → lista vacía
        $response = $this->actingAs($this->grupoUser(), 'sanctum')
            ->getJson('/api/users?taquilla_id=' . $otraTaquilla->id);

        $response->assertStatus(200);
        $this->assertEmpty($response->json());
    }

    // ==================================================
    // VALIDACIÓN Y ROL TAQUILLA
    // ==================================================

    public function test_filtro_invalido_422()
    {
        // banca_id inexistente
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/users?banca_id=999999');

        $response->assertStatus(422)
            ->assertJsonValidationErrors('banca_id');

        // taquilla_id no numérico
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/users?taquilla_id=abc');

        $response->assertStatus(422)
            ->assertJsonValidationErrors('taquilla_id');
    }

    public function test_taquilla_solo_se_ve_a_si_misma()
    {
        // Demo es la taquilla pre-activada con MAC registrada: se autentica
        // como terminal real (verify.mac exige MAC para el rol taquilla).
        $demo = User::where('email', 'demo@lotto.com')->first();
        $demo->assignRole('taquilla');

        $response = $this->actingAs($demo, 'sanctum')
            ->withHeader('X-Device-MAC', '00:1A:2B:3C:4D:5E')
            ->withHeader('X-Device-Fingerprint', 'demo-device-001')
            ->getJson('/api/users');

        $response->assertStatus(200);

        $usuarios = $response->json();

        $this->assertCount(1, $usuarios);
        $this->assertEquals('demo@lotto.com', $usuarios[0]['email']);
    }
}
