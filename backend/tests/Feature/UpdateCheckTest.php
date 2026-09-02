<?php

namespace Tests\Feature;

use App\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_check_publico_devuelve_version_y_sha256(): void
    {
        Release::factory()->create([
            'version' => '2.1.0',
            'sha256' => hash('sha256', 'instalador-2.1.0'),
            'published_at' => now()->subHour(),
        ]);

        // Sin autenticación: la taquilla instalada consulta sin token
        $response = $this->getJson('/api/v1/update-check');

        $response->assertStatus(200)
            ->assertJson([
                'version' => '2.1.0',
                'sha256' => hash('sha256', 'instalador-2.1.0'),
            ]);
    }

    public function test_update_check_es_solo_notificacion_sin_auto_install(): void
    {
        Release::factory()->create(['published_at' => now()->subHour()]);

        $response = $this->getJson('/api/v1/update-check');

        $response->assertStatus(200);
        $body = $response->json();

        // D1: notify-only — nunca expone URL de descarga ni instrucciones de instalación
        $this->assertArrayNotHasKey('url', $body);
        $this->assertArrayNotHasKey('download_url', $body);
        $this->assertArrayNotHasKey('install', $body);
        $this->assertArrayNotHasKey('auto_update', $body);

        $this->assertArrayHasKey('version', $body);
        $this->assertArrayHasKey('sha256', $body);
    }

    public function test_update_check_404_sin_release_publicada(): void
    {
        $response = $this->getJson('/api/v1/update-check');

        $response->assertStatus(404)->assertJsonStructure(['message']);
    }

    public function test_update_check_throttle_429_al_exceder_limite(): void
    {
        Release::factory()->create(['published_at' => now()->subHour()]);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/update-check')->assertStatus(200);
        }

        $this->getJson('/api/v1/update-check')->assertStatus(429);
    }
}
