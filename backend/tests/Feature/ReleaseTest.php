<?php

namespace Tests\Feature;

use App\Models\Release;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function userPorEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_latest_retorna_la_unica_release_publicada(): void
    {
        $release = Release::factory()->create([
            'version' => '1.2.3',
            'sha256' => str_repeat('ab', 32),
            'published_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->userPorEmail('super@lotto.com'), 'sanctum')
            ->getJson('/api/v1/releases/latest');

        $response->assertStatus(200)
            ->assertJson([
                'version' => '1.2.3',
                'sha256' => str_repeat('ab', 32),
            ]);

        $this->assertEquals(
            $release->published_at->toISOString(),
            $response->json('published_at')
        );
    }

    public function test_latest_no_expone_historial(): void
    {
        Release::factory()->create([
            'version' => '1.0.0',
            'published_at' => now()->subDays(2),
        ]);
        $nueva = Release::factory()->create([
            'version' => '2.0.0',
            'published_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->userPorEmail('super@lotto.com'), 'sanctum')
            ->getJson('/api/v1/releases/latest');

        $response->assertStatus(200);

        // Respuesta de objeto único, no colección: nunca expone historial
        $body = $response->json();
        $this->assertArrayNotHasKey('data', $body);
        $this->assertArrayNotHasKey('items', $body);
        $this->assertArrayNotHasKey('releases', $body);

        $this->assertSame('2.0.0', $body['version']);
        $this->assertNotSame('1.0.0', $body['version']);
        $this->assertEquals(
            $nueva->published_at->toISOString(),
            $body['published_at']
        );
    }

    public function test_latest_404_sin_releases_publicadas(): void
    {
        $response = $this->actingAs($this->userPorEmail('super@lotto.com'), 'sanctum')
            ->getJson('/api/v1/releases/latest');

        $response->assertStatus(404)
            ->assertJsonStructure(['message']);
    }

    public function test_latest_disponible_para_los_cinco_roles_del_panel(): void
    {
        Release::factory()->create(['published_at' => now()->subHour()]);

        foreach (['super@lotto.com', 'master@lotto.com', 'banca@lotto.com', 'grupo@lotto.com', 'agencia@lotto.com'] as $email) {
            $response = $this->actingAs($this->userPorEmail($email), 'sanctum')
                ->getJson('/api/v1/releases/latest');

            $response->assertStatus(200);
            $this->assertArrayHasKey('version', $response->json());
            $this->assertArrayHasKey('sha256', $response->json());
        }
    }

    public function test_latest_denegado_para_rol_taquilla(): void
    {
        Release::factory()->create(['published_at' => now()->subHour()]);

        $response = $this->actingAs($this->userPorEmail('taquilla@lotto.com'), 'sanctum')
            ->getJson('/api/v1/releases/latest');

        $response->assertStatus(403);
    }
}
