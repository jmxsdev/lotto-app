<?php

namespace App\Console\Commands;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEventData;
use App\Audit\AuditReason;
use App\Audit\AuditRecorder;
use App\Models\Release;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReleasePublishCommand extends Command
{
    protected $signature = 'releases:publish {path : Ruta del instalador .exe en el VPS} {--release-version= : Versión de la release (default: parse del filename Taquilla-Setup-<version>.exe)} {--latest-yml= : Ruta del latest.yml de electron-updater; se persiste en la misma transacción D3} {--reason-code=business_decision : Código de motivo para la auditoría (AuditReason; default business_decision)}';

    protected $description = 'Publica una release de la taquilla: SHA-256, mueve el .exe al disco releases, persiste el latest.yml del feed y reemplaza la fila única (D3, sin historial)';

    public function handle(): int
    {
        $context = app(AuditContext::class);
        $context->reset();
        $context->forSystem('command:releases:publish', null, null, 'command');

        try {
            return $this->publishRelease();
        } finally {
            $context->reset();
        }
    }

    private function publishRelease(): int
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

        $latestYml = $this->option('latest-yml');

        if ($latestYml !== null && ! is_file($latestYml)) {
            $this->error("El latest.yml no existe: {$latestYml}");

            return self::FAILURE;
        }

        if ($latestYml !== null && ($error = $this->validarLatestYml($latestYml, $version))) {
            $this->error($error);

            return self::FAILURE;
        }

        $sha256 = hash_file('sha256', $path);
        $size = filesize($path);
        $target = 'Taquilla-Setup-'.$version.'.exe';

        $release = DB::transaction(function () use ($path, $target, $version, $sha256, $size, $latestYml) {
            // D3: reemplazo — elimina fila(s) y archivo(s) anterior(es), nunca historial
            foreach (Release::all() as $old) {
                Storage::disk('releases')->delete($old->file_path);
                $old->delete();
            }

            Storage::disk('releases')->delete('latest.yml');

            Storage::disk('releases')->putFileAs('', $path, $target);

            if ($latestYml !== null) {
                Storage::disk('releases')->putFileAs('', $latestYml, 'latest.yml');
            }

            return Release::create([
                'version' => $version,
                'sha256' => $sha256,
                'file_path' => $target,
                'file_size' => $size,
                'published_at' => now(),
            ]);
        });

        $this->registrarPublicacion($release, $latestYml !== null);

        $this->info("Release {$version} publicada (sha256: {$sha256}).");

        return self::SUCCESS;
    }

    /**
     * P1 best-effort: la release ya está publicada; un fallo de auditoría no
     * revierte la transacción ni cambia el código de salida. La política de
     * `release.published` exige motivo, así que se sanea el código recibido y
     * se cae a `business_decision` si no pertenece al enum cerrado.
     */
    private function registrarPublicacion(Release $release, bool $conLatestYml): void
    {
        $reasonCode = AuditReason::tryFrom((string) $this->option('reason-code'))
            ?? AuditReason::BusinessDecision;

        app(AuditRecorder::class)->record(
            AuditAction::ReleasePublished,
            new AuditEventData(
                action: AuditAction::ReleasePublished,
                entityType: 'release',
                entityId: (int) $release->id,
                after: [
                    'version' => $release->version,
                    'sha256' => $release->sha256,
                    'file_path' => $release->file_path,
                    'file_size' => $release->file_size,
                    'published_at' => $release->published_at?->toIso8601String(),
                ],
                context: [
                    'command' => 'releases:publish',
                    'latest_yml' => $conLatestYml,
                ],
                reasonCode: $reasonCode->value,
            ),
        );
    }

    /**
     * Valida el latest.yml del feed (D6): extrae `version:` y `sha512:` por
     * regex y rechaza si faltan o si la versión no coincide con la publicada.
     * Sin dependencia YAML nueva.
     */
    private function validarLatestYml(string $latestYml, string $version): ?string
    {
        $contenido = (string) file_get_contents($latestYml);

        if (! preg_match('/^version:\s*(.+)$/m', $contenido, $mVersion)) {
            return 'El latest.yml no tiene un campo `version:` (D6).';
        }

        if (! preg_match('/^sha512:\s*(.+)$/m', $contenido, $mSha)) {
            return 'El latest.yml no tiene un campo `sha512:` (D6).';
        }

        if (trim($mVersion[1]) !== $version) {
            return "El latest.yml declara version: {$mVersion[1]} pero la release publicada es {$version} (D6).";
        }

        return null;
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
