<?php

namespace Tests\Feature;

use App\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReleaseFeedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Publica la release vigente (D3: fila única) con su .exe y latest.yml
     * en el disco fake de releases.
     */
    private function publicarRelease(string $version, string $contenido = 'binario-del-instalador'): void
    {
        Storage::fake('releases');
        Storage::disk('releases')->put("Taquilla-Setup-{$version}.exe", $contenido);
        Storage::disk('releases')->put('latest.yml', "version: {$version}\nsha512: abc123\n");
        Release::create([
            'version' => $version,
            'sha256' => hash('sha256', $contenido),
            'file_path' => "Taquilla-Setup-{$version}.exe",
            'file_size' => strlen($contenido),
            'published_at' => now(),
        ]);
    }

    public function test_feed_sirve_latest_yml_sin_auth_sin_gzip_sin_redirect(): void
    {
        $this->publicarRelease('1.0.3');
        Storage::disk('releases')->put('latest.yml', "version: 1.0.3\nsha512: abc123\n");

        // Sin autenticación: la taquilla empaquetada consulta sin token.
        $response = $this->get('/api/v1/releases/feed/latest.yml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/octet-stream');
        $response->assertHeaderMissing('Location');
        $response->assertHeaderMissing('Content-Encoding');
        $this->assertSame("version: 1.0.3\nsha512: abc123\n", $response->streamedContent());
    }

    public function test_feed_soporta_descarga_parcial_range_206(): void
    {
        $contenido = str_repeat('x', 1024);
        $this->publicarRelease('1.0.3', $contenido);

        $response = $this->get('/api/v1/releases/feed/Taquilla-Setup-1.0.3.exe', ['Range' => 'bytes=0-99']);

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 0-99/1024');
        $response->assertHeader('Content-Type', 'application/octet-stream');
        $response->assertHeaderMissing('Location');
        $response->assertHeaderMissing('Content-Encoding');
        $this->assertSame(substr($contenido, 0, 100), $response->streamedContent());
    }

    public function test_feed_rechaza_traversal_encoded(): void
    {
        $this->publicarRelease('1.0.3');

        // Traversal (doble-encoded y single-encoded): nunca expone el disco.
        $this->get('/api/v1/releases/feed/%252e%252e%252f.env')->assertStatus(404);
        $this->get('/api/v1/releases/feed/..%2F.env')->assertStatus(404);
    }

    public function test_feed_rechaza_archivos_fuera_de_la_lista_permitida(): void
    {
        $this->publicarRelease('1.0.3');

        // .env del backend (no es un artefacto de release)
        $this->get('/api/v1/releases/feed/.env')->assertStatus(404);

        // .bak del yml (nunca se sirve un artefacto antiguo)
        $this->get('/api/v1/releases/feed/latest.yml.bak')->assertStatus(404);

        // blockmap de una versión que NO es la vigente
        $this->get('/api/v1/releases/feed/Taquilla-Setup-2.0.0.exe.blockmap')->assertStatus(404);

        // instalador de una versión distinta a la fila vigente
        $this->get('/api/v1/releases/feed/Taquilla-Setup-2.0.0.exe')->assertStatus(404);

        // nombre arbitrario
        $this->get('/api/v1/releases/feed/nota-importante.txt')->assertStatus(404);
    }

    public function test_feed_sirve_blockmap_de_la_release_vigente(): void
    {
        $this->publicarRelease('1.0.3');
        Storage::disk('releases')->put('Taquilla-Setup-1.0.3.exe.blockmap', 'blockmap-binario');

        $response = $this->get('/api/v1/releases/feed/Taquilla-Setup-1.0.3.exe.blockmap');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertSame('blockmap-binario', $response->streamedContent());
    }

    public function test_feed_404_sin_release_publicada(): void
    {
        $this->get('/api/v1/releases/feed/latest.yml')->assertStatus(404);
    }

    public function test_feed_429_al_superar_120_peticiones_por_minuto(): void
    {
        $this->publicarRelease('1.0.3');

        for ($i = 0; $i < 120; $i++) {
            $this->get('/api/v1/releases/feed/latest.yml')->assertStatus(200);
        }

        $this->get('/api/v1/releases/feed/latest.yml')->assertStatus(429);
    }
}
