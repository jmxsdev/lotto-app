<?php

namespace Tests\Feature;

use App\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReleasePublishCommandTest extends TestCase
{
    use RefreshDatabase;

    private function escribirInstalador(string $filename, string $contenido = 'binario-del-instalador'): string
    {
        $path = sys_get_temp_dir().'/'.$filename;
        file_put_contents($path, $contenido);

        return $path;
    }

    public function test_publish_calcula_sha256_y_mueve_el_archivo_al_disco(): void
    {
        Storage::fake('releases');
        $path = $this->escribirInstalador('Taquilla-Setup-1.0.0.exe');

        $this->artisan('releases:publish', ['path' => $path])->assertSuccessful();

        $release = Release::query()->current()->first();
        $this->assertNotNull($release);
        $this->assertSame('1.0.0', $release->version);
        $this->assertSame(hash('sha256', 'binario-del-instalador'), $release->sha256);
        $this->assertSame('Taquilla-Setup-1.0.0.exe', $release->file_path);
        $this->assertSame(strlen('binario-del-instalador'), $release->file_size);
        $this->assertNotNull($release->published_at);

        Storage::disk('releases')->assertExists('Taquilla-Setup-1.0.0.exe');
    }

    public function test_publish_reemplaza_la_fila_unica_sin_historial(): void
    {
        Storage::fake('releases');
        $path1 = $this->escribirInstalador('Taquilla-Setup-1.0.0.exe', 'contenido-v1');
        $path2 = $this->escribirInstalador('Taquilla-Setup-2.0.0.exe', 'contenido-v2');

        $this->artisan('releases:publish', ['path' => $path1])->assertSuccessful();
        $this->artisan('releases:publish', ['path' => $path2])->assertSuccessful();

        // D3: nunca retiene historial — una sola fila, reemplaza el archivo viejo
        $this->assertSame(1, Release::count());
        $release = Release::query()->current()->first();
        $this->assertSame('2.0.0', $release->version);
        Storage::disk('releases')->assertExists('Taquilla-Setup-2.0.0.exe');
        Storage::disk('releases')->assertMissing('Taquilla-Setup-1.0.0.exe');
    }

    public function test_publish_version_explicita_gana_al_parse_del_filename(): void
    {
        Storage::fake('releases');
        $path = $this->escribirInstalador('Taquilla-Setup-1.0.0.exe', 'contenido');

        $this->artisan('releases:publish', ['path' => $path, '--release-version' => '9.9.9'])->assertSuccessful();

        $release = Release::query()->current()->first();
        $this->assertSame('9.9.9', $release->version);
        Storage::disk('releases')->assertExists('Taquilla-Setup-9.9.9.exe');
    }

    public function test_publish_archivo_inexistente_falla(): void
    {
        $this->artisan('releases:publish', ['path' => sys_get_temp_dir().'/no-existe-xyz.exe'])
            ->assertExitCode(1);
    }

    public function test_publish_con_latest_yml_lo_persiste_junto_al_instalador(): void
    {
        Storage::fake('releases');
        $path = $this->escribirInstalador('Taquilla-Setup-1.0.3.exe', 'v3');
        $ymlPath = $this->escribirInstalador('latest.yml', "version: 1.0.3\nsha512: abc123\n");

        $this->artisan('releases:publish', ['path' => $path, '--release-version' => '1.0.3', '--latest-yml' => $ymlPath])
            ->assertSuccessful();

        $release = Release::query()->current()->first();
        $this->assertSame('1.0.3', $release->version);
        Storage::disk('releases')->assertExists('latest.yml');
        Storage::disk('releases')->assertExists('Taquilla-Setup-1.0.3.exe');
        $this->assertSame("version: 1.0.3\nsha512: abc123\n", Storage::disk('releases')->get('latest.yml'));
    }

    public function test_publish_con_latest_yml_reemplaza_el_anterior(): void
    {
        Storage::fake('releases');
        $path1 = $this->escribirInstalador('Taquilla-Setup-1.0.2.exe', 'v2');
        $path2 = $this->escribirInstalador('Taquilla-Setup-2.0.0.exe', 'v200');
        $yml1 = $this->escribirInstalador('latest-v1.yml', "version: 1.0.2\nsha512: old\n");
        $yml2 = $this->escribirInstalador('latest-v2.yml', "version: 2.0.0\nsha512: new\n");

        $this->artisan('releases:publish', ['path' => $path1, '--latest-yml' => $yml1])->assertSuccessful();
        $this->artisan('releases:publish', ['path' => $path2, '--latest-yml' => $yml2])->assertSuccessful();

        $this->assertSame(1, Release::count());
        Storage::disk('releases')->assertExists('latest.yml');
        $this->assertSame("version: 2.0.0\nsha512: new\n", Storage::disk('releases')->get('latest.yml'));
    }

    public function test_publish_rechaza_latest_yml_con_version_incoherente(): void
    {
        Storage::fake('releases');
        $path = $this->escribirInstalador('Taquilla-Setup-1.0.3.exe', 'v3');
        $ymlPath = $this->escribirInstalador('latest.yml', "version: 1.0.2\nsha512: abc\n");

        $this->artisan('releases:publish', ['path' => $path, '--release-version' => '1.0.3', '--latest-yml' => $ymlPath])
            ->assertExitCode(1);

        // Nada se persiste: ni fila ni artefactos en el disco de releases.
        $this->assertSame(0, Release::count());
        Storage::disk('releases')->assertMissing('latest.yml');
        Storage::disk('releases')->assertMissing('Taquilla-Setup-1.0.3.exe');
    }

    public function test_publish_rechaza_latest_yml_sin_version_o_sin_sha512(): void
    {
        Storage::fake('releases');
        $path = $this->escribirInstalador('Taquilla-Setup-1.0.3.exe', 'v3');
        $sinVersion = $this->escribirInstalador('latest.yml', "sha512: abc\n");
        $sinSha = $this->escribirInstalador('latest.yml', "version: 1.0.3\n");

        $this->artisan('releases:publish', ['path' => $path, '--release-version' => '1.0.3', '--latest-yml' => $sinVersion])
            ->assertExitCode(1);
        $this->artisan('releases:publish', ['path' => $path, '--release-version' => '1.0.3', '--latest-yml' => $sinSha])
            ->assertExitCode(1);
    }
}
