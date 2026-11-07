<?php

namespace App\Services;

use App\Audit\AuditRedactor;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Scope\AlcanceService;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\ValidationException;

/**
 * Capa de lectura de `audit_events`: filtros, alcance por rol, cursor
 * pagination y serialización redactada.
 *
 * La consulta siempre pasa por `AlcanceService::applyToAuditoria`; los
 * payloads `before`/`after`/`context` se re-redactan en lectura como defensa
 * en profundidad y nunca se desenmascaran.
 */
final class AuditQueryService
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /**
     * Alias de `occurred_at` sin cast, para que el cursor de Laravel codifique
     * un primitivo en lugar del `DateTimeImmutable` del cast `UtcDateTime`.
     */
    private const CURSOR_OCCURRED_COLUMN = 'occurred_at_cursor';

    public function __construct(
        private readonly AlcanceService $alcance,
        private readonly AuditRedactor $redactor,
    ) {}

    /**
     * Construye la consulta acotada por scope y filtros, en el orden de
     * lectura `occurred_at DESC, id DESC`.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(User $actor, array $filters): Builder
    {
        $query = AuditEvent::query();

        $this->alcance->applyToAuditoria($query, $actor);

        // El alias se usa solo para las condiciones/orden del cursor; la
        // serialización sigue leyendo el atributo casteado `occurred_at`.
        $query->select(['audit_events.*', 'occurred_at as '.self::CURSOR_OCCURRED_COLUMN]);

        if (($filters['action'] ?? null) !== null && $filters['action'] !== '') {
            $query->where('action', $filters['action']);
        }

        if (($filters['domain'] ?? null) !== null && $filters['domain'] !== '') {
            $query->where('action', 'like', $filters['domain'].'.%');
        }

        if (($filters['actor_id'] ?? null) !== null && $filters['actor_id'] !== '') {
            $query->where('actor_id', (int) $filters['actor_id']);
        }

        if (($filters['actor_type'] ?? null) !== null && $filters['actor_type'] !== '') {
            $query->where('actor_type', $filters['actor_type']);
        }

        if (($filters['entity_type'] ?? null) !== null && $filters['entity_type'] !== '') {
            $query->where('entity_type', $filters['entity_type']);
        }

        if (($filters['entity_id'] ?? null) !== null && $filters['entity_id'] !== '') {
            $query->where('entity_id', (int) $filters['entity_id']);
        }

        if (($filters['request_id'] ?? null) !== null && $filters['request_id'] !== '') {
            $query->where('request_id', $filters['request_id']);
        }

        if (($filters['reason_code'] ?? null) !== null && $filters['reason_code'] !== '') {
            $query->where('reason_code', $filters['reason_code']);
        }

        if (($filters['from'] ?? null) !== null && $filters['from'] !== '') {
            $query->where('occurred_at', '>=', $this->boundary('from', (string) $filters['from'], false));
        }

        if (($filters['to'] ?? null) !== null && $filters['to'] !== '') {
            $query->where('occurred_at', '<=', $this->boundary('to', (string) $filters['to'], true));
        }

        return $query
            ->orderByDesc(self::CURSOR_OCCURRED_COLUMN)
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, next_cursor: ?string, prev_cursor: ?string}
     */
    public function page(User $actor, array $filters, int $perPage = self::DEFAULT_PER_PAGE, ?string $cursor = null): array
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $paginator = $this->query($actor, $filters)
            ->cursorPaginate($perPage, ['*'], 'cursor', $this->resolveCursor($cursor));

        return [
            'data' => array_map(fn (AuditEvent $event): array => $this->toArray($event), $paginator->items()),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'prev_cursor' => $paginator->previousCursor()?->encode(),
        ];
    }

    /**
     * Envelope de lectura (design §7.3). No hidrata la relación `User`: solo
     * los snapshots `actor_label`/`actor_roles`.
     *
     * @return array<string, mixed>
     */
    public function toArray(AuditEvent $event): array
    {
        return [
            'id' => (int) $event->id,
            'event_uuid' => $event->event_uuid,
            'schema_version' => (int) $event->schema_version,
            'occurred_at' => $event->occurred_at
                ?->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
            'actor' => [
                'type' => $event->actor_type,
                'id' => $event->actor_id,
                'label' => $event->actor_label,
                'roles' => $event->actor_roles ?? [],
            ],
            'action' => $event->action,
            'domain' => $this->domainOf($event->action),
            'entity' => [
                'type' => $event->entity_type,
                'id' => $event->entity_id,
            ],
            'scope' => [
                'banca_id' => $event->banca_id,
                'grupo_id' => $event->grupo_id,
                'agencia_id' => $event->agencia_id,
                'taquilla_id' => $event->taquilla_id,
            ],
            'before' => $this->redactPayload($event->before),
            'after' => $this->redactPayload($event->after),
            'reason' => [
                'code' => $event->reason_code,
                'text' => $event->reason_text,
            ],
            'context' => $this->redactPayload($event->context),
            'ip' => $event->ip,
            'user_agent' => $event->user_agent,
            'request_id' => $event->request_id,
            'job_id' => $event->job_id,
            'hash' => $event->hash,
            'prev_hash' => $event->prev_hash,
        ];
    }

    /**
     * Decodifica y valida el cursor antes de pasarlo al paginador: un cursor
     * corrupto o de otro endpoint se rechaza con 422, nunca con 500.
     */
    private function resolveCursor(?string $cursor): ?Cursor
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $parsed = Cursor::fromEncoded($cursor);
        $parameters = $parsed?->toArray() ?? [];

        if (! $parsed instanceof Cursor
            || ! array_key_exists(self::CURSOR_OCCURRED_COLUMN, $parameters)
            || ! array_key_exists('id', $parameters)
            || ! is_string($parameters[self::CURSOR_OCCURRED_COLUMN])
            || ! is_numeric($parameters['id'])
            || strtotime($parameters[self::CURSOR_OCCURRED_COLUMN]) === false
            || ! is_bool($parameters['_pointsToNextItems'] ?? null)) {
            throw ValidationException::withMessages([
                'cursor' => ['El cursor no es válido.'],
            ]);
        }

        return $parsed;
    }

    /**
     * Convierte un límite `from`/`to` a UTC.
     *
     * Una fecha `Y-m-d` se interpreta en la zona de la app (`America/Caracas`)
     * y `to` sin hora cubre el fin de ese día. Un ISO-8601 conserva su offset
     * (o usa la zona de la app si es naive) y se compara exacto.
     */
    private function boundary(string $attribute, string $value, bool $endOfDay): string
    {
        $timezone = new DateTimeZone((string) config('app.timezone', 'America/Caracas'));
        $dateOnly = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;

        try {
            $date = new DateTimeImmutable($dateOnly ? $value.' 00:00:00' : $value, $timezone);
        } catch (Exception) {
            throw ValidationException::withMessages([
                $attribute => ["El filtro {$attribute} no es una fecha válida."],
            ]);
        }

        if ($dateOnly && $endOfDay) {
            $date = $date->setTime(23, 59, 59);
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @param  array<string|int, mixed>|null  $payload
     * @return array<string|int, mixed>|null
     */
    private function redactPayload(?array $payload): ?array
    {
        return $payload === null ? null : $this->redactor->redact($payload);
    }

    private function domainOf(string $action): string
    {
        $separator = strpos($action, '.');

        return $separator === false ? $action : substr($action, 0, $separator);
    }
}
