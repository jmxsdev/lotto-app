<?php

namespace Tests\Feature;

use App\Models\Release;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class DownloadTest extends TestCase
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

    private function publicarRelease(): Release
    {
        Storage::fake('releases');
        Storage::disk('releases')->put('Taquilla-Setup-1.2.3.exe', 'contenido-binario-del-instalador');

        return Release::factory()->create([
            'version' => '1.2.3',
            'file_path' => 'Taquilla-Setup-1.2.3.exe',
            'sha256' => hash('sha256', 'contenido-binario-del-instalador'),
            'published_at' => now()->subHour(),
        ]);
    }

    public function test_download_emite_url_firmada_relativa(): void
    {
        $this->publicarRelease();

        $response = $this->actingAs($this->userPorEmail('super@lotto.com'), 'sanctum')
            ->getJson('/api/v1/releases/download');

        $response->assertStatus(200)
            ->assertJsonStructure(['url', 'expires_at']);

        $url = $response->json('url');
        $this->assertStringStartsWith('/api/v1/releases/serve?', $url);
        $this->assertStringContainsString('expires=', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_download_requiere_autenticacion(): void
    {
        $this->publicarRelease();

        $this->getJson('/api/v1/releases/download')->assertStatus(401);
    }

    public function test_download_404_sin_release_publicada(): void
    {
        $response = $this->actingAs($this->userPorEmail('super@lotto.com'), 'sanctum')
            ->getJson('/api/v1/releases/download');

        $response->assertStatus(404)->assertJsonStructure(['message']);
    }

    public function test_serve_streams_el_archivo_con_headers(): void
    {
        $this->publicarRelease();

        $download = $this->actingAs($this->userPorEmail('super@lotto.com'), 'sanctum')
            ->getJson('/api/v1/releases/download');

        $response = $this->get($download->json('url'));

        $response->assertStatus(200);
        $this->assertSame('contenido-binario-del-instalador', $response->streamedContent());
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Taquilla-Setup-1.2.3.exe', $response->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
    }

    public function test_serve_rechaza_firma_alterada(): void
    {
        $this->publicarRelease();

        $download = $this->actingAs($this->userPorEmail('super@lotto.com'), 'sanctum')
            ->getJson('/api/v1/releases/download');

        $url = $download->json('url');
        $tampered = substr($url, 0, -1).(substr($url, -1) === 'a' ? 'b' : 'a');

        $this->getJson($tampered)->assertStatus(403);
    }

    public function test_serve_rechaza_url_expirada(): void
    {
        $this->publicarRelease();

        $url = URL::temporarySignedRoute('releases.serve', now()->subMinutes(10), absolute: false);

        $this->getJson($url)->assertStatus(403);
    }

    public function test_download_throttle_429_al_exceder_limite(): void
    {
        $this->publicarRelease();

        $user = $this->userPorEmail('super@lotto.com');

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/releases/download')
                ->assertStatus(200);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/releases/download')
            ->assertStatus(429);
    }
}
