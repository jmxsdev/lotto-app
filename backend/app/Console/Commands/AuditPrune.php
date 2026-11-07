<?php

namespace App\Console\Commands;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEventData;
use App\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Prunes archived audit files older than the archive retention window.
 *
 * Dry-run by default. Deletion requires `--apply` and a matching `.sha256`
 * unless `--force` is present. Every run records `audit.retention_purged` (P1).
 */
final class AuditPrune extends Command
{
    protected $signature = 'audit:prune
        {--years= : Años de retención del archivo (default config.audit.archive.years)}
        {--apply : Elimina los archivos candidatos}
        {--force : Elimina aunque el checksum no coincida}
        {--dry-run : Fuerza el modo dry-run (default)}';

    protected $description = 'Poda archivos de auditoría NDJSON.gz con más antigüedad que la retención (dry-run por defecto)';

    public function handle(): int
    {
        $context = app(AuditContext::class);
        $context->reset();
        $context->forSystem('command:audit:prune', null, null, 'command');

        try {
            return $this->perform();
        } finally {
            $context->reset();
        }
    }

    private function perform(): int
    {
        $years = $this->resolveYears();

        if ($years === null) {
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply') && ! (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $disk = (string) config('audit.archive.disk', 'local');
        $archivePath = trim((string) config('audit.retention.archive_path', 'audit/archive'), '/');
        $storage = Storage::disk($disk);
        $threshold = CarbonImmutable::now('UTC')->subYears($years)->getTimestamp();

        $candidates = $this->candidates($storage, $archivePath, $threshold);

        if ($candidates === []) {
            $this->info('No hay archivos candidatos para podar.');

            $this->emit($apply, $years, []);

            return self::SUCCESS;
        }

        $pruned = [];
        $failed = [];

        foreach ($candidates as $file) {
            if (! $force && ! $this->checksumMatches($storage, $file)) {
                $failed[] = $file;
                $this->error('Checksum inválido, no se poda: '.$file);

                continue;
            }

            if (! $apply) {
                continue;
            }

            $storage->delete([$file, $file.'.sha256', $file.'.manifest.json']);
            $pruned[] = $file;
        }

        if ($apply) {
            $this->info(sprintf('Podados %d archivo(s) de %d candidato(s).', count($pruned), count($candidates)));
        } else {
            $this->info(sprintf('Dry-run: %d archivo(s) candidato(s) para podar.', count($candidates)));
        }

        $this->emit($apply, $years, $apply ? $pruned : $candidates);

        return $failed === [] || $force ? self::SUCCESS : self::FAILURE;
    }

    private function candidates(Filesystem $storage, string $archivePath, int $threshold): array
    {
        $candidates = [];

        foreach ($storage->files($archivePath) as $file) {
            if (! str_ends_with($file, '.ndjson.gz')) {
                continue;
            }

            if ($storage->lastModified($file) < $threshold) {
                $candidates[] = $file;
            }
        }

        sort($candidates);

        return $candidates;
    }

    private function checksumMatches(Filesystem $storage, string $file): bool
    {
        $shaFile = $file.'.sha256';

        if (! $storage->exists($shaFile)) {
            return true;
        }

        $expected = strtolower(trim((string) $storage->get($shaFile)));

        if ($expected === '') {
            return false;
        }

        $stream = $storage->readStream($file);

        if (! is_resource($stream)) {
            return false;
        }

        $context = hash_init('sha256');

        try {
            hash_update_stream($context, $stream);
        } finally {
            fclose($stream);
        }

        return hash_equals($expected, hash_final($context));
    }

    private function emit(bool $applied, int $years, array $files): void
    {
        app(AuditRecorder::class)->record(
            AuditAction::AuditRetentionPurged,
            new AuditEventData(
                action: AuditAction::AuditRetentionPurged,
                entityType: 'audit',
                context: [
                    'operation' => 'prune',
                    'files' => array_values($files),
                    'years' => $years,
                    'dry_run' => ! $applied,
                ],
            ),
        );
    }

    private function resolveYears(): ?int
    {
        $default = max(1, (int) config('audit.archive.years', 5));
        $years = filter_var($this->option('years') ?: $default, FILTER_VALIDATE_INT);

        if ($years === false || $years <= 0) {
            $this->error('--years debe ser un entero positivo.');

            return null;
        }

        return $years;
    }
}
