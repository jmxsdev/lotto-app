<?php

namespace App\Services;

use App\Audit\AuditAction;
use App\Audit\AuditEventData;
use App\Audit\AuditRecorder;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Streaming CSV export of the audit trail.
 *
 * Reuses `AuditQueryService` so filters, row scope and read-time redaction stay
 * identical to `GET /audit-events`. The export never hydrates the full result
 * set: rows are written per `audit.export.chunk` page, so memory usage stays
 * flat regardless of the number of matched events.
 */
final class AuditExportService
{
    /**
     * Exact CSV header, in order (design §7.5).
     *
     * @var array<int, string>
     */
    public const COLUMNS = [
        'occurred_at',
        'event_uuid',
        'schema_version',
        'action',
        'actor_type',
        'actor_id',
        'actor_label',
        'actor_roles',
        'entity_type',
        'entity_id',
        'banca_id',
        'grupo_id',
        'agencia_id',
        'taquilla_id',
        'reason_code',
        'reason_text',
        'before',
        'after',
        'context',
        'ip',
        'user_agent',
        'request_id',
        'job_id',
        'hash',
        'prev_hash',
    ];

    private const BOM = "\xEF\xBB\xBF";

    /**
     * Characters that trigger spreadsheet formula evaluation.
     *
     * @var array<int, string>
     */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(
        private readonly AuditQueryService $query,
        private readonly AuditRecorder $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function stream(User $actor, array $filters): StreamedResponse
    {
        $query = $this->query->query($actor, $filters);
        $count = (clone $query)->reorder()->count();

        $this->assertWithinLimit($count);

        ignore_user_abort(true);

        $chunk = max(1, (int) config('audit.export.chunk', 500));
        $filename = 'audit-events-'.now()->format('Ymd-His').'.csv';

        $response = response()->streamDownload(
            function () use ($query, $filters, $count, $chunk): void {
                $output = fopen('php://output', 'wb');

                try {
                    if ($output === false) {
                        return;
                    }

                    fwrite($output, self::BOM);
                    fputcsv($output, self::COLUMNS, ',', '"', '');

                    $query->chunk($chunk, function (iterable $events) use ($output): void {
                        foreach ($events as $event) {
                            if ($event instanceof AuditEvent) {
                                fputcsv($output, $this->row($event), ',', '"', '');
                            }
                        }
                    });
                } finally {
                    if (is_resource($output)) {
                        fclose($output);
                    }

                    $this->recordExport($filters, $count);
                }
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );

        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');

        return $response;
    }

    /**
     * Build one CSV row from the already-redacted read envelope.
     *
     * @return array<int, string>
     */
    private function row(AuditEvent $event): array
    {
        $data = $this->query->toArray($event);
        $actor = $data['actor'] ?? [];
        $entity = $data['entity'] ?? [];
        $scope = $data['scope'] ?? [];
        $reason = $data['reason'] ?? [];

        return array_map(
            fn (mixed $cell): string => $this->cell($cell),
            [
                $data['occurred_at'] ?? null,
                $data['event_uuid'] ?? null,
                $data['schema_version'] ?? null,
                $data['action'] ?? null,
                $actor['type'] ?? null,
                $actor['id'] ?? null,
                $actor['label'] ?? null,
                $this->json($actor['roles'] ?? null),
                $entity['type'] ?? null,
                $entity['id'] ?? null,
                $scope['banca_id'] ?? null,
                $scope['grupo_id'] ?? null,
                $scope['agencia_id'] ?? null,
                $scope['taquilla_id'] ?? null,
                $reason['code'] ?? null,
                $reason['text'] ?? null,
                $this->json($data['before'] ?? null),
                $this->json($data['after'] ?? null),
                $this->json($data['context'] ?? null),
                $data['ip'] ?? null,
                $data['user_agent'] ?? null,
                $data['request_id'] ?? null,
                $data['job_id'] ?? null,
                $data['hash'] ?? null,
                $data['prev_hash'] ?? null,
            ],
        );
    }

    /**
     * Normalize a cell: null becomes an empty cell and strings that could be
     * interpreted as spreadsheet formulas are prefixed with a single quote.
     */
    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        if (is_array($value)) {
            $value = $this->json($value) ?? '';
        }

        $value = (string) $value;

        if ($value !== '' && in_array($value[0], self::FORMULA_PREFIXES, true)) {
            return "'".$value;
        }

        return $value;
    }

    private function json(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private function assertWithinLimit(int $count): void
    {
        $max = (int) config('audit.export.max_rows', 50000);

        if ($max > 0 && $count > $max) {
            throw ValidationException::withMessages([
                'filters' => ["El export supera el máximo de {$max} filas ({$count} encontradas). Acote los filtros."],
            ]);
        }
    }

    /**
     * Record the export attempt as evidence. Failure must never break the
     * download, so every throwable is swallowed here.
     *
     * @param  array<string, mixed>  $filters
     */
    private function recordExport(array $filters, int $count): void
    {
        try {
            $this->recorder->record(
                AuditAction::AuditExported,
                new AuditEventData(
                    action: AuditAction::AuditExported,
                    entityType: 'audit',
                    context: [
                        'filters' => $filters,
                        'count' => $count,
                        'format' => 'csv',
                        'status' => connection_aborted() ? 'aborted' : 'completed',
                    ],
                ),
            );
        } catch (Throwable) {
            // Best-effort evidence: the CSV download wins over audit failures.
        }
    }
}
