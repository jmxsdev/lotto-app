<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
