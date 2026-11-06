<?php

namespace App\Audit;

use App\Exceptions\AuditValidationException;
use App\Exceptions\AuditWriteException;
use App\Models\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single write path for the audit trail: normalize, validate, redact, persist.
 * P0 fail-closed writes require a transaction; P1/P2 degrade with a log entry.
 * When `audit.enabled=false`, writes are no-ops; `critical()` returns a detached
 * unsaved event to satisfy its non-null contract.
 */
class AuditRecorder
{
    public function __construct(
        private AuditContext $context,
        private AuditRedactor $redactor,
        private AuditScopeResolver $scopeResolver,
        private AuditChainHasher $chain,
    ) {}

    public function record(AuditAction $action, AuditEventData $data): ?AuditEvent
    {
        if (! $this->enabled()) {
            return null;
        }

        $this->assertActionMatches($action, $data);

        return $this->shouldFailClosed($action)
            ? $this->critical($action, $data)
            : $this->bestEffort($action, $data);
    }

    public function critical(AuditAction $action, AuditEventData $data): AuditEvent
    {
        if (! $this->enabled()) {
            return new AuditEvent;
        }

        $this->assertActionMatches($action, $data);

        if (DB::transactionLevel() <= 0) {
            throw new AuditWriteException('Audit critical writes require an active transaction.');
        }

        try {
            return $this->persist($this->buildRow($action, $data));
        } catch (AuditValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AuditWriteException('Unable to persist the audit event.', 0, $exception);
        }
    }

    public function bestEffort(AuditAction $action, AuditEventData $data): ?AuditEvent
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $row = $this->buildRow($action, $data);

            return $this->hashChainEnabled()
                ? DB::transaction(fn (): AuditEvent => $this->persistWithChain($row))
                : $this->persist($row);
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return null;
        }
    }

    /**
     * Validate and insert a homogeneous batch. Duplicates by `event_uuid` are
     * success. Returns the rows accepted after validation (existing UUIDs are
     * still counted). Returns 0 when disabled or when a best-effort batch fails.
     */
    public function recordMany(AuditAction $action, iterable $data): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $rows = [];

        foreach ($data as $item) {
            if (! $item instanceof AuditEventData) {
                throw AuditValidationException::forField('data', 'Cada evento debe ser una instancia de AuditEventData.');
            }

            $rows[] = $this->buildRow($action, $item);
        }

        if ($rows === []) {
            return 0;
        }

        $failClosed = $this->shouldFailClosed($action);
        $chunk = max(1, (int) config('audit.export.chunk', 500));

        try {
            DB::transaction(function () use ($rows, $chunk): void {
                if ($this->hashChainEnabled()) {
                    foreach ($rows as $row) {
                        $this->persistWithChain($row);
                    }

                    return;
                }

                foreach (array_chunk($rows, $chunk) as $batch) {
                    DB::table('audit_events')->insertOrIgnore(
                        array_map(fn (array $row): array => $this->encodeForInsert($row), $batch),
                    );
                }
            });
        } catch (Throwable $exception) {
            if ($failClosed) {
                throw new AuditWriteException('Unable to persist the audit batch.', 0, $exception);
            }

            $this->logFailure($exception);

            return 0;
        }

        return count($rows);
    }

    private function buildRow(AuditAction $action, AuditEventData $data): array
    {
        $this->assertActionMatches($action, $data);

        $actor = $this->resolveActor($data);
        $context = array_merge($data->context, $this->context->toContext());
        $requestId = $data->requestId ?? ($context['request_id'] ?? null);
        $jobId = $data->jobId ?? ($context['job_id'] ?? null);
        $reason = $this->resolveReason($action, $data, $context);

        unset($context['request_id'], $context['job_id'], $context['reason_code'], $context['reason_text']);

        $normalized = new AuditEventData(
            action: $action,
            eventUuid: $data->eventUuid,
            schemaVersion: $data->schemaVersion,
            occurredAt: $data->occurredAt,
            actorType: AuditActorType::tryFrom($actor['type']) ?? AuditActorType::User,
            actorId: $actor['id'],
            actorLabel: $this->truncate($actor['label'], 191),
            actorRoles: $actor['roles'],
            entityType: $data->entityType ?? $action->entityType(),
            entityId: $data->entityId,
            scope: $data->scope ?? new AuditScope,
            before: $data->before === null ? null : $this->redactor->redact($data->before),
            after: $data->after === null ? null : $this->redactor->redact($data->after),
            context: $this->redactor->redact($context),
            reasonCode: $reason['code'],
            reasonText: $reason['text'],
            ip: $data->ip ?? $this->context->ip(),
            userAgent: $this->truncate($data->userAgent ?? $this->context->userAgent(), 512),
            requestId: is_string($requestId) ? $requestId : null,
            jobId: is_string($jobId) ? $jobId : null,
        );

        return $normalized->toRow();
    }

    private function resolveActor(AuditEventData $data): array
    {
        $explicit = $data->actorId !== null
            || $data->actorLabel !== null
            || $data->actorRoles !== null
            || $data->actorType !== AuditActorType::User;

        if ($explicit) {
            return [
                'type' => $data->actorType->value,
                'id' => $data->actorId,
                'label' => $data->actorLabel,
                'roles' => $data->actorRoles ?? [],
            ];
        }

        $system = $this->context->system();

        if ($system !== null) {
            $source = $this->context->source() ?? 'job';
            $job = $this->context->jobId();
            $label = $system['label'] ?? ($job !== null && $job !== '' ? "system:{$source}:{$job}" : "system:{$source}");

            return [
                'type' => AuditActorType::System->value,
                'id' => null,
                'label' => $label,
                'roles' => $system['roles'] ?? [],
            ];
        }

        $actor = $this->context->actor() ?? [];

        return [
            'type' => (string) ($actor['type'] ?? AuditActorType::User->value),
            'id' => $actor['id'] ?? null,
            'label' => $actor['label'] ?? null,
            'roles' => $actor['roles'] ?? [],
        ];
    }

    private function resolveReason(AuditAction $action, AuditEventData $data, array $context): array
    {
        $code = $data->reasonCode ?? ($context['reason_code'] ?? null);
        $text = $data->reasonText ?? ($context['reason_text'] ?? null);

        if ($code !== null && (! is_string($code) || AuditReason::tryFrom($code) === null)) {
            throw AuditValidationException::forField('reason_code', 'El motivo no es válido.');
        }

        $policy = $action->reasonPolicy();

        if ($policy->isRequired() && ($code === null || $code === '')) {
            throw AuditValidationException::forField('reason_code', 'El motivo es requerido para esta acción.');
        }

        if ($policy->isAutomatic() && ($code === null || $code === '')) {
            $code = $action->defaultAutomaticReason();
        }

        return ['code' => $code !== '' ? $code : null, 'text' => $this->redactor->redactReason($text)];
    }

    private function persist(array $row): AuditEvent
    {
        if ($this->hashChainEnabled()) {
            return $this->persistWithChain($row);
        }

        $row['hash'] = null;
        $row['prev_hash'] = null;

        return $this->insertOne($row);
    }

    private function persistWithChain(array $row): AuditEvent
    {
        if ($existing = $this->findByUuid((string) $row['event_uuid'])) {
            return $existing;
        }

        $state = DB::table('audit_chain_state')->where('id', 1)->lockForUpdate()->first();
        $prevHash = $state?->last_hash;
        $payload = $row;
        unset($payload['hash'], $payload['prev_hash']);

        $hash = $this->chain->hash($prevHash, $payload);
        $row['prev_hash'] = $prevHash;
        $row['hash'] = $hash;

        $event = $this->insertOne($row);

        if ($event->hash === $hash) {
            DB::table('audit_chain_state')->where('id', 1)->update([
                'last_event_id' => $event->getKey(),
                'last_hash' => $hash,
                'updated_at' => now(),
            ]);
        }

        return $event;
    }

    private function insertOne(array $row): AuditEvent
    {
        try {
            return AuditEvent::create($row);
        } catch (Throwable $exception) {
            if ($this->isUniqueViolation($exception) && ($existing = $this->findByUuid((string) $row['event_uuid']))) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function findByUuid(string $eventUuid): ?AuditEvent
    {
        return AuditEvent::query()->where('event_uuid', $eventUuid)->first();
    }

    private function isUniqueViolation(Throwable $exception): bool
    {
        if (! $exception instanceof QueryException) {
            return false;
        }

        return (int) ($exception->errorInfo[1] ?? 0) === 1062
            || str_contains($exception->getMessage(), 'Duplicate entry')
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed');
    }

    private function encodeForInsert(array $row): array
    {
        foreach (['actor_roles', 'before', 'after', 'context'] as $column) {
            if ($row[$column] !== null) {
                $row[$column] = json_encode(
                    $row[$column],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
            }
        }

        return $row;
    }

    private function assertActionMatches(AuditAction $action, AuditEventData $data): void
    {
        if ($action !== $data->action) {
            throw AuditValidationException::forField('action', 'La acción del evento no coincide.');
        }
    }

    private function enabled(): bool
    {
        return (bool) config('audit.enabled', true);
    }

    private function hashChainEnabled(): bool
    {
        return (bool) config('audit.hash_chain', false);
    }

    private function shouldFailClosed(AuditAction $action): bool
    {
        if (! (bool) config('audit.fail_closed', true) || $action->priority() !== AuditPriority::P0) {
            return false;
        }

        $domains = array_map(
            static fn (mixed $domain): string => strtolower(trim((string) $domain)),
            (array) config('audit.fail_closed_domains', []),
        );

        return in_array('*', $domains, true) || in_array($action->domain(), $domains, true);
    }

    private function logFailure(Throwable $exception): void
    {
        Log::error('Audit event write failed.', ['exception' => $exception::class]);
    }

    private function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }
}
