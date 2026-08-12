<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_taquilla_cannot_list_users_but_master_can()
    {
        $taquillaUser = User::factory()->create(['role' => 'taquilla']);
        $taquillaUser->assignRole('taquilla');

        $response = $this->actingAs($taquillaUser, 'sanctum')
                         ->getJson('/api/users');

        $response->assertStatus(403);

        $master = User::where('email', 'master@lotto.com')->first();
        $master->assignRole('master');

        $response = $this->actingAs($master, 'sanctum')
                         ->getJson('/api/users');

        $response->assertStatus(200);
    }

    public function test_banca_can_only_create_groups_in_its_own_banca()
    {
        $banca1 = Banca::factory()->create(['name' => 'Banca 1']);
        $banca2 = Banca::factory()->create(['name' => 'Banca 2']);

        $userBanca1 = User::factory()->create([
            'role' => 'banca',
            'banca_id' => $banca1->id,
        ]);
        $userBanca1->assignRole('banca');

        // Intentar crear grupo en banca 2 (debe fallar)
        $response = $this->actingAs($userBanca1, 'sanctum')
                         ->postJson('/api/grupos', [
                             'name' => 'Grupo Test',
                             'code' => 'GT999',
                             'banca_id' => $banca2->id,
                             'user_name' => 'Usuario Grupo',
                             'user_email' => 'grupo_test@test.com',
                             'user_password' => 'password123',
                         ]);

        $response->assertStatus(403);

        // Intentar crear grupo en banca 1 (debe funcionar)
        $response = $this->actingAs($userBanca1, 'sanctum')
                         ->postJson('/api/grupos', [
                             'name' => 'Grupo Test',
                             'code' => 'GT998',
                             'banca_id' => $banca1->id,
                             'user_name' => 'Usuario Grupo',
                             'user_email' => 'grupo_test2@test.com',
                             'user_password' => 'password123',
                         ]);

        $response->assertStatus(201);
    }

    public function test_grupo_can_only_create_taquillas_in_its_own_grupo()
    {
        $banca = Banca::factory()->create();
        $grupo1 = Grupo::factory()->create(['name' => 'Grupo 1', 'banca_id' => $banca->id]);
        $grupo2 = Grupo::factory()->create(['name' => 'Grupo 2', 'banca_id' => $banca->id]);

        $userGrupo1 = User::factory()->create([
            'role' => 'grupo',
            'grupo_id' => $grupo1->id,
        ]);
        $userGrupo1->assignRole('grupo');

        // Intentar crear taquilla en grupo 2 (debe fallar)
        $response = $this->actingAs($userGrupo1, 'sanctum')
                         ->postJson('/api/taquillas', [
                             'name' => 'Taquilla Test',
                             'code' => 'TT999',
                             'grupo_id' => $grupo2->id,
                             'user_name' => 'Usuario Taquilla',
                             'user_email' => 'taquilla_test@test.com',
                             'user_password' => 'password123',
                         ]);

        $response->assertStatus(403);

        // Intentar crear taquilla en grupo 1 (debe funcionar)
        $response = $this->actingAs($userGrupo1, 'sanctum')
                         ->postJson('/api/taquillas', [
                             'name' => 'Taquilla Test',
                             'code' => 'TT998',
                             'grupo_id' => $grupo1->id,
                             'user_name' => 'Usuario Taquilla',
                             'user_email' => 'taquilla_test2@test.com',
                             'user_password' => 'password123',
                         ]);

        $response->assertStatus(201);
    }

    public function test_grupo_no_excede_limite_banca()
    {
        $master = User::where('email', 'master@lotto.com')->first();
        $master->assignRole('master');

        $banca = Banca::factory()->create(['name' => 'Banca Test', 'active' => true]);
        $grupo = Grupo::factory()->create(['name' => 'Grupo Test', 'banca_id' => $banca->id, 'active' => true]);

        $juego = \App\Models\Juego::first();
        if (!$juego) {
            $juego = \App\Models\Juego::create(['name' => 'Test', 'slug' => 'test-juego', 'active' => true]);
        }

        // Configurar límite a nivel banca: max 100 BS
        JuegoLimite::create([
            'juego_id' => $juego->id,
            'banca_id' => $banca->id,
            'moneda' => 'bs',
            'grupo_id' => null,
            'taquilla_id' => null,
            'limite_maximo' => 100,
        ]);

        // Intentar configurar límite a nivel grupo con max 200 BS (más permisivo que banca)
        $response = $this->actingAs($master, 'sanctum')
            ->putJson('/api/limites/' . $juego->id, [
                'banca_id' => $banca->id,
                'grupo_id' => $grupo->id,
                'moneda' => 'bs',
                'limite_maximo' => 200,
            ]);

        $response->assertStatus(422);
        $content = $response->json('message') ?? $response->getContent();
        $this->assertStringContainsString('100', $content);
    }
}
