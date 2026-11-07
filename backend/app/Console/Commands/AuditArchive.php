<?php

namespace App\Console\Commands;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEventData;
use App\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Moves audit events older than the hot window to a compressed NDJSON archive.
 *
 * Dry-run by default. With `--apply` it streams the rows to
 * `audit-YYYYMMDD-HHMMSS.ndjson.gz` (memory O(chunk)), writes `.sha256` and
 * `.manifest.json`, then deletes the hot rows through the retention connection.
 * A write or checksum failure never deletes anything.
 */
final class AuditArchive extends Command
{
    protected $signature = 'audit:archive
        {--months= : Meses de retención hot (default config.audit.retention.hot_months)}
        {--from= : Límite inferior Y-m-d (America/Caracas) o ISO-8601}
        {--to= : Límite superior Y-m-d (America/Caracas) o ISO-8601}
        {--chunk= : Filas por chunk (default config.audit.retention.chunk)}
        {--apply : Escribe el archivo, verifica el checksum y borra los eventos archivados}
        {--disk= : Disco de archivo (default config.audit.archive.disk)}';

    protected $description = 'Archiva eventos de auditoría antiguos en NDJSON.gz con checksum y manifest (dry-run por defecto)';

    private const DATE_ONLY = '/^\d{4}-\d{2}-\d{2}$/';

    public function handle(): int
    {
        $context = app(AuditContext::class);
        $context->reset();
        $context->forSystem('command:audit:archive', null, null, 'command');

        try {
            return $this->perform();
        } finally {
            $context->reset();
        }
    }

    private function perform(): int
    {
        $months = $this->resolveMonths();

        if ($months === null) {
            return self::FAILURE;
        }

        try {
            $from = $this->parseBoundary($this->option('from'), false);
            $to = $this->parseBoundary($this->option('to'), true);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $chunk = $this->resolveChunk();
        $disk = (string) ($this->option('disk') ?: config('audit.archive.disk', 'local'));
        $archivePath = trim((string) config('audit.retention.archive_path', 'audit/archive'), '/');

        $cutoff = CarbonImmutable::now('UTC')->subMonths($months);
        $upper = $to !== null && $to->lessThan($cutoff) ? $to : $cutoff;

        $query = $this->baseQuery($from, $to, $cutoff);
        $total = (clone $query)->count();

        if (! $apply) {
            $this->info(sprintf('Dry-run: %d eventos serían archivados.', $total));

            return self::SUCCESS;
        }

        if ($total === 0) {
            $this->info('No hay eventos para archivar.');

            return self::SUCCESS;
        }

        $filename = 'audit-'.CarbonImmutable::now('UTC')->format('Ymd-His').'.ndjson.gz';
        $relative = ($archivePath !== '' ? $archivePath.'/' : '').$filename;

        $tmp = tempnam(sys_get_temp_dir(), 'audit-archive-');

        if ($tmp === false) {
            $this->error('No se pudo crear el archivo temporal del archivo de auditoría.');

            return self::FAILURE;
        }

        try {
            $count = $this->writeArchive($query, $chunk, $tmp);
            $sha256 = hash_file('sha256', $tmp);

            if ($sha256 === false) {
                throw new RuntimeException('No se pudo calcular el checksum del archivo.');
            }

            $storage = Storage::disk($disk);
            $stream = fopen($tmp, 'rb');

            if ($stream === false) {
                throw new RuntimeException('No se pudo leer el archivo temporal.');
            }

            try {
                if (! $storage->put($relative, $stream)) {
                    throw new RuntimeException('No se pudo escribir el archivo en el disco configurado.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (! $storage->put($relative.'.sha256', $sha256."\n")) {
                throw new RuntimeException('No se pudo escribir el checksum del archivo.');
            }

            $manifest = $this->manifestJson($filename, $sha256, $count, $from, $upper, $months);

            if (! $storage->put($relative.'.manifest.json', $manifest)) {
                throw new RuntimeException('No se pudo escribir el manifest del archivo.');
            }
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete([
                $relative,
                $relative.'.sha256',
                $relative.'.manifest.json',
            ]);

            $this->error('No se pudo escribir el archivo de archivo: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($tmp);
        }

        try {
            $deleted = $this->deleteArchived($from, $to, $cutoff, $chunk);
        } catch (Throwable $exception) {
            $this->error('El archivo se escribió, pero falló el borrado hot: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->emitPurged($filename, $deleted, $from, $upper, $months, $sha256);

        $this->info(sprintf('Archivados %d eventos en %s', $deleted, $relative));

        return self::SUCCESS;
    }

    private function baseQuery(?CarbonImmutable $from, ?CarbonImmutable $to, CarbonImmutable $cutoff): Builder
    {
        return DB::table('audit_events')
            ->where('occurred_at', '<', $cutoff->format('Y-m-d H:i:s'))
            ->when($from !== null, fn ($query) => $query->where('occurred_at', '>=', $from->format('Y-m-d H:i:s')))
            ->when($to !== null, fn ($query) => $query->where('occurred_at', '<=', $to->format('Y-m-d H:i:s')));
    }

    private function writeArchive(Builder $query, int $chunk, string $tmp): int
    {
        $handle = gzopen($tmp, 'wb');

        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el archivo gzip temporal.');
        }

        $count = 0;

        try {
            $query->chunkById($chunk, function (Collection $rows) use ($handle, &$count): void {
                foreach ($rows as $row) {
                    $line = json_encode(
                        $this->serializeRow($row),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    );

                    gzwrite($handle, $line."\n");
                    $count++;
                }
            });
        } finally {
            gzclose($handle);
        }

        return $count;
    }

    private function serializeRow(object $row): array
    {
        $data = (array) $row;

        foreach (['actor_roles', 'before', 'after', 'context'] as $column) {
            if (isset($data[$column]) && is_string($data[$column]) && $data[$column] !== '') {
                $decoded = json_decode($data[$column], true);
                $data[$column] = is_array($decoded) ? $decoded : $data[$column];
            }
        }

        $data['schema_version'] = (int) $data['schema_version'];
        $data['occurred_at'] = CarbonImmutable::parse((string) $row->occurred_at, 'UTC')
            ->format('Y-m-d\TH:i:s\Z');

        return $data;
    }

    private function deleteArchived(?CarbonImmutable $from, ?CarbonImmutable $to, CarbonImmutable $cutoff, int $chunk): int
    {
        $connectionName = (string) config('audit.retention.connection', config('database.default'));
        $connection = DB::connection($connectionName);
        $usesSessionFlag = $connection->getDriverName() === 'mysql';

        if ($usesSessionFlag) {
            $connection->statement('SET @audit_retention = 1');
        }

        $deleted = 0;

        try {
            $connection->table('audit_events')
                ->where('occurred_at', '<', $cutoff->format('Y-m-d H:i:s'))
                ->when($from !== null, fn ($query) => $query->where('occurred_at', '>=', $from->format('Y-m-d H:i:s')))
                ->when($to !== null, fn ($query) => $query->where('occurred_at', '<=', $to->format('Y-m-d H:i:s')))
                ->chunkById($chunk, function (Collection $rows) use ($connection, &$deleted): void {
                    $ids = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all();

                    if ($ids !== []) {
                        $deleted += $connection->table('audit_events')->whereIn('id', $ids)->delete();
                    }
                });
        } finally {
            if ($usesSessionFlag) {
                $connection->statement('SET @audit_retention = 0');
            }
        }

        return $deleted;
    }

    private function manifestJson(string $filename, string $sha256, int $count, ?CarbonImmutable $from, CarbonImmutable $to, int $months): string
    {
        return json_encode([
            'file' => $filename,
            'sha256' => $sha256,
            'count' => $count,
            'from' => $from?->format('Y-m-d\TH:i:s\Z'),
            'to' => $to->format('Y-m-d\TH:i:s\Z'),
            'months' => $months,
            'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private function emitPurged(string $filename, int $count, ?CarbonImmutable $from, CarbonImmutable $to, int $months, string $sha256): void
    {
        app(AuditRecorder::class)->record(
            AuditAction::AuditRetentionPurged,
            new AuditEventData(
                action: AuditAction::AuditRetentionPurged,
                entityType: 'audit',
                context: [
                    'operation' => 'archive',
                    'file' => $filename,
                    'count' => $count,
                    'from' => $from?->format('Y-m-d\TH:i:s\Z'),
                    'to' => $to->format('Y-m-d\TH:i:s\Z'),
                    'months' => $months,
                    'sha256' => $sha256,
                ],
            ),
        );
    }

    private function resolveMonths(): ?int
    {
        $raw = $this->option('months');

        if ($raw === null || $raw === '') {
            return max(1, (int) config('audit.retention.hot_months', 12));
        }

        $months = filter_var($raw, FILTER_VALIDATE_INT);

        if ($months === false || $months <= 0) {
            $this->error('--months debe ser un entero positivo.');

            return null;
        }

        return $months;
    }

    private function resolveChunk(): int
    {
        $default = max(1, (int) config('audit.retention.chunk', 1000));
        $chunk = filter_var($this->option('chunk') ?: $default, FILTER_VALIDATE_INT);

        return $chunk !== false && $chunk > 0 ? $chunk : $default;
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
