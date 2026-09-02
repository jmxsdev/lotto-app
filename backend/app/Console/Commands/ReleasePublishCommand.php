<?php

namespace App\Console\Commands;

use App\Models\Release;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReleasePublishCommand extends Command
{
    protected $signature = 'releases:publish {path : Ruta del instalador .exe en el VPS} {--release-version= : Versión de la release (default: parse del filename Taquilla-Setup-<version>.exe)}';

    protected $description = 'Publica una release de la taquilla: SHA-256, mueve el .exe al disco releases y reemplaza la fila única (D3, sin historial)';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("El archivo no existe: {$path}");

            return self::FAILURE;
        }

        $version = $this->option('release-version') ?: $this->parseVersionFromFilename(basename($path));

        if (! $version) {
            $this->error('No se pudo inferir la versión del filename; usa --release-version= (esperado Taquilla-Setup-<version>.exe).');

            return self::FAILURE;
        }

        $sha256 = hash_file('sha256', $path);
        $size = filesize($path);
        $target = 'Taquilla-Setup-'.$version.'.exe';

        DB::transaction(function () use ($path, $target, $version, $sha256, $size) {
            // D3: reemplazo — elimina fila(s) y archivo(s) anterior(es), nunca historial
            foreach (Release::all() as $old) {
                Storage::disk('releases')->delete($old->file_path);
                $old->delete();
            }

            Storage::disk('releases')->putFileAs('', $path, $target);

            Release::create([
                'version' => $version,
                'sha256' => $sha256,
                'file_path' => $target,
                'file_size' => $size,
                'published_at' => now(),
            ]);
        });

        $this->info("Release {$version} publicada (sha256: {$sha256}).");

        return self::SUCCESS;
    }

    /**
     * Extrae la versión del filename Taquilla-Setup-<version>.exe.
     */
    private function parseVersionFromFilename(string $filename): ?string
    {
        if (preg_match('/^Taquilla-Setup-(.+)\.exe$/', $filename, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
