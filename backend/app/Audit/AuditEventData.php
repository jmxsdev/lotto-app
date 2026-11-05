<?php

namespace App\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Immutable value object for a single audit event.
 *
 * It only normalizes and transports data. Persistence, actor resolution and
 * PII redaction belong to the recorder layer.
 */
final readonly class AuditEventData
{
    public const SCHEMA_VERSION = 1;

    public const REASON_TEXT_MAX = 500;

    public string $eventUuid;

    public DateTimeImmutable $occurredAt;

    public ?string $reasonCode;

    public ?string $reasonText;

    public function __construct(
        public AuditAction $action,
        ?string $eventUuid = null,
        public int $schemaVersion = self::SCHEMA_VERSION,
        ?DateTimeImmutable $occurredAt = null,
        public AuditActorType $actorType = AuditActorType::User,
        public ?int $actorId = null,
        public ?string $actorLabel = null,
        public ?array $actorRoles = null,
        public ?string $entityType = null,
        public int|string|null $entityId = null,
        public ?AuditScope $scope = null,
        public ?array $before = null,
        public ?array $after = null,
        public array $context = [],
        ?string $reasonCode = null,
        ?string $reasonText = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $requestId = null,
        public ?string $jobId = null,
        public ?string $hash = null,
        public ?string $prevHash = null,
    ) {
        $timezone = new DateTimeZone('UTC');

        $this->eventUuid = $eventUuid ?? self::newUuid();
        $this->occurredAt = ($occurredAt ?? new DateTimeImmutable('now', $timezone))
            ->setTimezone($timezone);
        $this->reasonCode = self::normalizeReasonCode($reasonCode);
        $this->reasonText = self::normalizeReasonText($reasonText);
    }

    /**
     * Build event data from an Eloquent model.
     *
     * Create events never carry `before`; delete-style events (`.deleted` and
     * `.limit_deleted`) never carry `after`.
     */
    public static function forModel(
        Model $model,
        AuditAction $action,
        ?array $before = null,
        ?array $after = null,
        array $context = [],
    ): self {
        if (str_ends_with($action->value, '.created')) {
            $before = null;
        }

        if (str_ends_with($action->value, 'deleted')) {
            $after = null;
        }

        $key = $model->getKey();

        return new self(
            action: $action,
            actorType: AuditActorType::User,
            entityType: $action->entityType(),
            entityId: is_int($key) || is_string($key) ? $key : null,
            before: $before,
            after: $after,
            context: $context,
        );
    }

    public function withReason(?AuditReason $code, ?string $text): self
    {
        return new self(
            action: $this->action,
            eventUuid: $this->eventUuid,
            schemaVersion: $this->schemaVersion,
            occurredAt: $this->occurredAt,
            actorType: $this->actorType,
            actorId: $this->actorId,
            actorLabel: $this->actorLabel,
            actorRoles: $this->actorRoles,
            entityType: $this->entityType,
            entityId: $this->entityId,
            scope: $this->scope,
            before: $this->before,
            after: $this->after,
            context: $this->context,
            reasonCode: $code?->value,
            reasonText: $text,
            ip: $this->ip,
            userAgent: $this->userAgent,
            requestId: $this->requestId,
            jobId: $this->jobId,
            hash: $this->hash,
            prevHash: $this->prevHash,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'event_uuid' => $this->eventUuid,
            'schema_version' => $this->schemaVersion,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
            'actor_type' => $this->actorType->value,
            'actor_id' => $this->actorId,
            'actor_label' => $this->actorLabel,
            'actor_roles' => $this->actorRoles,
            'action' => $this->action->value,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'banca_id' => $this->scope?->bancaId,
            'grupo_id' => $this->scope?->grupoId,
            'agencia_id' => $this->scope?->agenciaId,
            'taquilla_id' => $this->scope?->taquillaId,
            'before' => $this->before,
            'after' => $this->after,
            'context' => $this->context === [] ? null : $this->context,
            'reason_code' => $this->reasonCode,
            'reason_text' => $this->reasonText,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'request_id' => $this->requestId,
            'job_id' => $this->jobId,
            'hash' => $this->hash,
            'prev_hash' => $this->prevHash,
        ];
    }

    private static function normalizeReasonCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $code = trim($code);

        if ($code === '') {
            return null;
        }

        if (AuditReason::tryFrom($code) === null) {
            throw new InvalidArgumentException("Unknown audit reason code [{$code}].");
        }

        return $code;
    }

    private static function normalizeReasonText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim($text);

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, self::REASON_TEXT_MAX);
    }

    private static function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
