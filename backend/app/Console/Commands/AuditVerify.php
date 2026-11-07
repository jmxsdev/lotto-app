<?php

namespace App\Console\Commands;

use App\Audit\AuditAction;
use App\Audit\AuditChainHasher;
use App\Audit\AuditContext;
use App\Audit\AuditEventData;
use App\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use DateTimeZone;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Verifies archived audit files and the optional hash chain.
 *
 * Archives are NDJSON.gz files under `config('audit.retention.archive_path')`.
 * Each file is checked against its `.sha256` (a missing checksum only fails
 * with `--strict`) and every non-empty NDJSON line must be valid JSON with an
 * `event_uuid`. When `audit.hash_chain` is enabled the command walks
 * `audit_events` by ascending id, rebuilds the hashed row exactly like
 * `AuditEventData::toRow()`, and reports the first broken id.
 *
 * `--archive=` accepts a path relative to the archive disk or an absolute
 * local directory. `--from`/`--to` accept `Y-m-d` (America/Caracas) or
 * ISO-8601 and filter `occurred_at`. It records `audit.chain_verified` (P1,
 * best-effort) and returns exit code 1 on any checksum, NDJSON or chain
 * failure.
 */
final class AuditVerify extends Command
{
    protected $signature = 'audit:verify
        {--from= : Límite inferior Y-m-d (America/Caracas) o ISO-8601}
        {--to= : Límite superior Y-m-d (America/Caracas) o ISO-8601}
        {--archive= : Directorio de archivos (relativo al disco o ruta absoluta local)}
        {--strict : Falla si un archivo no tiene checksum .sha256}';

    protected $description = 'Verifica checksums, NDJSON y la cadena de hash de la auditoría';

    private const DATE_ONLY = '/^\d{4}-\d{2}-\d{2}$/';

    public function handle(): int
    {
        $context = app(AuditContext::class);
        $context->reset();
        $context->forSystem('command:audit:verify', null, null, 'command');

        try {
            return $this->perform();
        } finally {
            $context->reset();
        }
    }

    private function perform(): int
    {
        try {
            $from = $this->parseBoundary($this->option('from'), false);
            $to = $this->parseBoundary($this->option('to'), true);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $strict = (bool) $this->option('strict');
        [$archives, $archiveFailures] = $this->verifyArchives($strict);

        $hashChain = (bool) config('audit.hash_chain', false);
        $checked = 0;
        $firstBrokenId = null;

        if ($hashChain) {
            [$checked, $firstBrokenId] = $this->verifyChain($from, $to);
        } else {
            $this->line('Hash chain deshabilitada (AUDIT_HASH_CHAIN=false).');
        }

        $ok = $archiveFailures === 0 && $firstBrokenId === null;

        $this->info(sprintf('Archivos verificados: %d; fallidos: %d.', $archives, $archiveFailures));

        if ($hashChain) {
            $this->info($firstBrokenId === null
                ? sprintf('Hash chain OK: %d eventos verificados.', $checked)
                : sprintf('Hash chain rota en id %d (%d eventos verificados).', $firstBrokenId, $checked));
        }

        $this->emit($ok, $archives, $archiveFailures, $hashChain, $checked, $firstBrokenId, $strict);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0: int, 1: int} verified and failed archive counts
     */
    private function verifyArchives(bool $strict): array
    {
        $target = $this->archiveTarget();
        $verified = 0;
        $failed = 0;

        foreach ($this->archiveFiles($target) as $file) {
            if ($this->verifyArchiveFile($file, $target, $strict)) {
                $verified++;

                continue;
            }

            $failed++;
        }

        return [$verified, $failed];
    }

    /**
     * @param  array{absolute: bool, disk: string|null, path: string}  $target
     */
    private function verifyArchiveFile(string $file, array $target, bool $strict): bool
    {
        $compressed = $this->readContents($file, $target);

        if ($compressed === null) {
            $this->error('No se pudo leer el archivo: '.$file);

            return false;
        }

        $expected = $this->readContents($file.'.sha256', $target);

        if ($expected === null || trim($expected) === '') {
            if ($strict) {
                $this->error('Falta el checksum .sha256: '.$file);

                return false;
            }

            $this->warn('Sin checksum .sha256 (se verifica solo el NDJSON): '.$file);
        } elseif (! hash_equals(strtolower(trim($expected)), hash('sha256', $compressed))) {
            $this->error('Checksum inválido: '.$file);

            return false;
        }

        $ndjson = @gzdecode($compressed);

        if ($ndjson === false) {
            $this->error('No se pudo descomprimir el archivo: '.$file);

            return false;
        }

        foreach (preg_split('/\R/', $ndjson) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            try {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->error('Línea NDJSON inválida: '.$file);

                return false;
            }

            if (! is_array($row) || ! isset($row['event_uuid']) || ! is_string($row['event_uuid']) || $row['event_uuid'] === '') {
                $this->error('Línea sin event_uuid: '.$file);

                return false;
            }
        }

        $this->line('OK '.$file);

        return true;
    }

    /**
     * @return array{0: int, 1: int|null} checked count and first broken id
     */
    private function verifyChain(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $query = DB::table('audit_events')
            ->when($from !== null, fn ($builder) => $builder->where('occurred_at', '>=', $from->format('Y-m-d H:i:s')))
            ->when($to !== null, fn ($builder) => $builder->where('occurred_at', '<=', $to->format('Y-m-d H:i:s')));

        $first = (clone $query)->orderBy('id')->first();

        if ($first === null) {
            return [0, null];
        }

        $previous = DB::table('audit_events')
            ->where('id', '<', $first->id)
            ->orderByDesc('id')
            ->first();

        $expectedPrev = $previous?->hash;
        $checked = 0;
        $firstBrokenId = null;
        $hasher = app(AuditChainHasher::class);
        $chunk = max(1, (int) config('audit.retention.chunk', 1000));

        $query->orderBy('id')->chunkById($chunk, function (Collection $rows) use (&$expectedPrev, &$checked, &$firstBrokenId, $hasher): bool {
            foreach ($rows as $row) {
                $checked++;

                if (! hash_equals((string) ($expectedPrev ?? ''), (string) ($row->prev_hash ?? ''))) {
                    $firstBrokenId = (int) $row->id;

                    return false;
                }

                $hash = $hasher->hash($expectedPrev, $this->rowForHash($row));

                if (! hash_equals((string) ($row->hash ?? ''), $hash)) {
                    $firstBrokenId = (int) $row->id;

                    return false;
                }

                $expectedPrev = $row->hash;
            }

            return true;
        });

        return [$checked, $firstBrokenId];
    }

    /**
     * Rebuild the exact row shape used by `AuditEventData::toRow()` when the
     * hash was originally computed. Keys are normalized because hashing sorts
     * them, but value types must match (ints decode as ints, JSON as arrays).
     *
     * @return array<string, mixed>
     */
    private function rowForHash(object $row): array
    {
        return [
            'event_uuid' => (string) $row->event_uuid,
            'schema_version' => (int) $row->schema_version,
            'occurred_at' => (string) $row->occurred_at,
            'actor_type' => (string) $row->actor_type,
            'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
            'actor_label' => $row->actor_label === null ? null : (string) $row->actor_label,
            'actor_roles' => $this->decodeJson($row->actor_roles),
            'action' => (string) $row->action,
            'entity_type' => $row->entity_type === null ? null : (string) $row->entity_type,
            'entity_id' => $row->entity_id === null ? null : (int) $row->entity_id,
            'banca_id' => $row->banca_id === null ? null : (int) $row->banca_id,
            'grupo_id' => $row->grupo_id === null ? null : (int) $row->grupo_id,
            'agencia_id' => $row->agencia_id === null ? null : (int) $row->agencia_id,
            'taquilla_id' => $row->taquilla_id === null ? null : (int) $row->taquilla_id,
            'before' => $this->decodeJson($row->before),
            'after' => $this->decodeJson($row->after),
            'context' => $this->decodeJson($row->context),
            'reason_code' => $row->reason_code === null ? null : (string) $row->reason_code,
            'reason_text' => $row->reason_text === null ? null : (string) $row->reason_text,
            'ip' => $row->ip === null ? null : (string) $row->ip,
            'user_agent' => $row->user_agent === null ? null : (string) $row->user_agent,
            'request_id' => $row->request_id === null ? null : (string) $row->request_id,
            'job_id' => $row->job_id === null ? null : (string) $row->job_id,
        ];
    }

    private function decodeJson(mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function emit(
        bool $ok,
        int $archives,
        int $archiveFailures,
        bool $hashChain,
        int $checked,
        ?int $firstBrokenId,
        bool $strict,
    ): void {
        app(AuditRecorder::class)->record(
            AuditAction::AuditChainVerified,
            new AuditEventData(
                action: AuditAction::AuditChainVerified,
                entityType: 'audit',
                context: [
                    'ok' => $ok,
                    'archives' => $archives,
                    'archive_failures' => $archiveFailures,
                    'hash_chain' => $hashChain,
                    'checked' => $checked,
                    'first_broken_id' => $firstBrokenId,
                    'strict' => $strict,
                ],
            ),
        );
    }

    /**
     * @return array{absolute: bool, disk: string|null, path: string}
     */
    private function archiveTarget(): array
    {
        $option = $this->option('archive');

        if (is_string($option) && trim($option) !== '' && $this->isAbsolutePath(trim($option))) {
            return [
                'absolute' => true,
                'disk' => null,
                'path' => rtrim(trim($option), '/'),
            ];
        }

        $path = is_string($option) && trim($option) !== ''
            ? trim($option)
            : (string) config('audit.retention.archive_path', 'audit/archive');

        return [
            'absolute' => false,
            'disk' => (string) config('audit.archive.disk', 'local'),
            'path' => trim($path, '/'),
        ];
    }

    /**
     * @param  array{absolute: bool, disk: string|null, path: string}  $target
     * @return array<int, string>
     */
    private function archiveFiles(array $target): array
    {
        if ($target['absolute']) {
            if (! is_dir($target['path'])) {
                return [];
            }

            $files = [];
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($target['path'], FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.ndjson.gz')) {
                    $files[] = $file->getPathname();
                }
            }

            sort($files);

            return $files;
        }

        return array_values(array_filter(
            Storage::disk((string) $target['disk'])->allFiles($target['path']),
            static fn (string $file): bool => str_ends_with($file, '.ndjson.gz'),
        ));
    }

    /**
     * @param  array{absolute: bool, disk: string|null, path: string}  $target
     */
    private function readContents(string $file, array $target): ?string
    {
        if ($target['absolute']) {
            if (! is_file($file)) {
                return null;
            }

            $contents = file_get_contents($file);

            return $contents === false ? null : $contents;
        }

        $storage = Storage::disk((string) $target['disk']);

        return $storage->exists($file) ? (string) $storage->get($file) : null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function parseBoundary(mixed $value, bool $endOfDay): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        try {
            if (preg_match(self::DATE_ONLY, $value) === 1) {
                $timezone = new DateTimeZone('America/Caracas');
                $date = CarbonImmutable::createFromFormat(
                    'Y-m-d H:i:s',
                    $value.' '.($endOfDay ? '23:59:59' : '00:00:00'),
                    $timezone,
                );

                if ($date === false) {
                    throw new InvalidArgumentException('Fecha inválida.');
                }

                return $date->setTimezone('UTC');
            }

            return CarbonImmutable::parse($value)->setTimezone('UTC');
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                "Fecha inválida [{$value}]: usa Y-m-d (America/Caracas) o ISO-8601.",
                0,
                $exception,
            );
        }
    }
}
