<?php

namespace App\Console\Commands;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEventData;
use App\Audit\AuditRecorder;
use App\Audit\AuditRedactor;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Converts legacy `logs` and `juego_auditoria` rows to `audit_events`.
 *
 * Dry-run by default. `--apply` uses a deterministic `event_uuid`
 * (`sha1('logs:<id>')` / `sha1('juego_auditoria:<id>')`) plus `insertOrIgnore`,
 * so re-running never duplicates evidence. Scrapers and unknown actions are
 * skipped. With `audit.hash_chain` enabled, `--apply` requires `--allow-chain`;
 * backfilled rows are inserted with `hash`/`prev_hash = null` and stay outside
 * the chain. Backfill before enabling the chain whenever possible.
 */
final class AuditBackfill extends Command
{
    protected $signature = 'audit:backfill
        {--source=all : Fuente: logs, juego_auditoria o all}
        {--from= : Límite inferior Y-m-d (America/Caracas) o ISO-8601}
        {--to= : Límite superior Y-m-d (America/Caracas) o ISO-8601}
        {--limit= : Máximo de filas por fuente}
        {--apply : Inserta los eventos convertidos (dry-run por defecto)}
        {--allow-chain : Permite --apply con AUDIT_HASH_CHAIN=true; el backfill queda fuera de la cadena}';

    protected $description = 'Convierte logs y juego_auditoria legacy a audit_events de forma idempotente (dry-run por defecto)';

    private const CHUNK = 500;

    /** @var array<string, AuditAction> */
    private const LOG_ACTIONS = [
        'user.role_changed' => AuditAction::UserRoleChanged,
        'pago_premio' => AuditAction::PagoPrizePaid,
        'ticket_anulado' => AuditAction::TicketVoided,
        'apuesta.anulada' => AuditAction::BetVoided,
        'auditoria.pago_legacy' => AuditAction::PagoLegacyReconciled,
    ];

    /** @var array<string, AuditAction> */
    private const GAME_ACTIONS = [
        'activar' => AuditAction::GameToggled,
        'desactivar' => AuditAction::GameToggled,
        'actualizar' => AuditAction::GameUpdated,
        'premios' => AuditAction::GamePrizesUpdated,
    ];

    /** @var array<int, AuditAction> */
    private const DEVICE_ACTIONS = [
        AuditAction::TaquillaActivationSucceeded,
        AuditAction::TaquillaActivationFailed,
        AuditAction::TaquillaMacReassigned,
    ];

    public function __construct(
        private readonly AuditRedactor $redactor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $context = app(AuditContext::class);
        $context->reset();
        $context->forSystem('command:audit:backfill', null, null, 'command');

        try {
            return $this->perform();
        } finally {
            $context->reset();
        }
    }

    private function perform(): int
    {
        $apply = (bool) $this->option('apply');
        $source = (string) ($this->option('source') ?: 'all');

        if (! in_array($source, ['all', 'logs', 'juego_auditoria'], true)) {
            $this->error('--source debe ser logs, juego_auditoria o all.');

            return self::FAILURE;
        }

        if ($apply && (bool) config('audit.hash_chain', false) && ! (bool) $this->option('allow-chain')) {
            $this->error('audit.hash_chain está activo: usa --allow-chain para aplicar un backfill fuera de la cadena.');

            return self::FAILURE;
        }

        try {
            $from = $this->parseBoundary($this->option('from'), false);
            $to = $this->parseBoundary($this->option('to'), true);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $limit = $this->resolveLimit();

        if ($limit === false) {
            return self::FAILURE;
        }

        $sources = $source === 'all' ? ['logs', 'juego_auditoria'] : [$source];
        $totals = ['scanned' => 0, 'inserted' => 0, 'skipped' => 0];

        foreach ($sources as $name) {
            $result = $name === 'logs'
                ? $this->backfillLogs($from, $to, $limit, $apply)
                : $this->backfillJuegoAuditoria($from, $to, $limit, $apply);

            $totals['scanned'] += $result['scanned'];
            $totals['inserted'] += $result['inserted'];
            $totals['skipped'] += $result['skipped'];

            $this->info(sprintf(
                '%s: scanned=%d inserted=%d skipped=%d%s',
                $name,
                $result['scanned'],
                $result['inserted'],
                $result['skipped'],
                $apply ? '' : ' (dry-run)'
            ));
        }

        if ($apply) {
            $this->emitCompleted($source, $totals);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{scanned: int, inserted: int, skipped: int}
     */
    private function backfillLogs(?CarbonImmutable $from, ?CarbonImmutable $to, ?int $limit, bool $apply): array
    {
        $rows = $this->legacyQuery('logs', $from, $to, $limit);
        $emails = $this->userEmails($rows);
        $mapped = [];
        foreach ($rows as $row) {
            $event = $this->mapLog($row, $emails);

            if ($event !== null) {
                $mapped[] = $event;
            }
        }

        return $this->persist(count($rows), $mapped, $apply);
    }

    /**
     * @return array{scanned: int, inserted: int, skipped: int}
     */
    private function backfillJuegoAuditoria(?CarbonImmutable $from, ?CarbonImmutable $to, ?int $limit, bool $apply): array
    {
        $rows = $this->legacyQuery('juego_auditoria', $from, $to, $limit);
        $emails = $this->userEmails($rows);
        $mapped = [];

        foreach ($rows as $row) {
            $mapped[] = $this->mapJuegoAuditoria($row, $emails);
        }

        return $this->persist(count($rows), $mapped, $apply);
    }

    /**
     * @param  array<int, array<string, mixed>>  $mapped
     * @return array{scanned: int, inserted: int, skipped: int}
     */
    private function persist(int $scanned, array $mapped, bool $apply): array
    {
        $inserted = 0;

        if ($apply) {
            foreach (array_chunk($mapped, self::CHUNK) as $chunk) {
                $inserted += (int) DB::table('audit_events')->insertOrIgnore($chunk);
            }
        }

        return [
            'scanned' => $scanned,
            'inserted' => $inserted,
            'skipped' => $apply ? $scanned - $inserted : $scanned - count($mapped),
        ];
    }

    /**
     * @param  array<int, string>  $emails
     * @return array<string, mixed>|null
     */
    private function mapLog(object $row, array $emails): ?array
    {
        $legacyAction = (string) $row->action;
        $details = $this->decodeDetails($row->details ?? null);

        $action = $legacyAction === 'activacion_taquilla'
            ? $this->activationAction($details)
            : (self::LOG_ACTIONS[$legacyAction] ?? null);

        if ($action === null) {
            return null;
        }

        [$entityType, $entityId] = $this->entityFor($action, $details);
        $actorId = $this->intOrNull($row->user_id);

        return $this->row(
            eventUuid: sha1('logs:'.$row->id),
            occurredAt: $this->occurredAt($row->created_at),
            actorType: in_array($action, self::DEVICE_ACTIONS, true) && $actorId === null ? 'device' : 'user',
            actorId: $actorId,
            actorLabel: $actorId !== null && isset($emails[$actorId])
                ? $this->redactor->maskEmail((string) $emails[$actorId])
                : null,
            action: $action,
            entityType: $entityType,
            entityId: $entityId,
            before: is_array($details['before'] ?? null) ? $details['before'] : null,
            after: is_array($details['after'] ?? null) ? $details['after'] : null,
            context: [
                'backfill' => true,
                'source' => 'logs',
                'legacy_id' => (int) $row->id,
                'legacy_action' => $legacyAction,
            ],
            ip: $row->ip !== null ? (string) $row->ip : null,
            userAgent: $row->user_agent !== null ? mb_substr((string) $row->user_agent, 0, 512) : null,
        );
    }

    /**
     * @param  array<int, string>  $emails
     * @return array<string, mixed>
     */
    private function mapJuegoAuditoria(object $row, array $emails): array
    {
        $legacyAction = (string) $row->accion;
        $changes = $this->decodeDetails($row->cambios ?? null);
        $actorId = $this->intOrNull($row->user_id);

        return $this->row(
            eventUuid: sha1('juego_auditoria:'.$row->id),
            occurredAt: $this->occurredAt($row->created_at),
            actorType: 'user',
            actorId: $actorId,
            actorLabel: $actorId !== null && isset($emails[$actorId])
                ? $this->redactor->maskEmail((string) $emails[$actorId])
                : null,
            action: self::GAME_ACTIONS[$legacyAction] ?? AuditAction::GameUpdated,
            entityType: 'juego',
            entityId: $this->intOrNull($row->juego_id),
            before: is_array($changes['before'] ?? null) ? $changes['before'] : null,
            after: is_array($changes['after'] ?? null) ? $changes['after'] : null,
            context: [
                'backfill' => true,
                'source' => 'juego_auditoria',
                'legacy_id' => (int) $row->id,
                'legacy_accion' => $legacyAction,
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function row(
        string $eventUuid,
        string $occurredAt,
        string $actorType,
        ?int $actorId,
        ?string $actorLabel,
        AuditAction $action,
        ?string $entityType,
        ?int $entityId,
        ?array $before,
        ?array $after,
        array $context,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        return [
            'event_uuid' => $eventUuid,
            'schema_version' => 1,
            'occurred_at' => $occurredAt,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_label' => $actorLabel,
            'actor_roles' => null,
            'action' => $action->value,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'banca_id' => null,
            'grupo_id' => null,
            'agencia_id' => null,
            'taquilla_id' => null,
            'before' => $before === null ? null : $this->encode($this->redactor->redact($before)),
            'after' => $after === null ? null : $this->encode($this->redactor->redact($after)),
            'context' => $this->encode($context),
            'reason_code' => null,
            'reason_text' => null,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'request_id' => null,
            'job_id' => null,
            'hash' => null,
            'prev_hash' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function activationAction(array $details): AuditAction
    {
        if (($details['mac_reassigned'] ?? null) === true || ($details['motivo'] ?? null) === 'mac_reassigned') {
            return AuditAction::TaquillaMacReassigned;
        }

        if (($details['exitoso'] ?? null) === false) {
            return AuditAction::TaquillaActivationFailed;
        }

        return AuditAction::TaquillaActivationSucceeded;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{0: string|null, 1: int|null}
     */
    private function entityFor(AuditAction $action, array $details): array
    {
        return match ($action) {
            AuditAction::UserRoleChanged => [
                'user',
                $this->intOrNull($details['user_id'] ?? $details['id'] ?? $details['target_user_id'] ?? null),
            ],
            AuditAction::TaquillaActivationSucceeded,
            AuditAction::TaquillaActivationFailed,
            AuditAction::TaquillaMacReassigned => ['taquilla', $this->intOrNull($details['taquilla_id'] ?? null)],
            AuditAction::PagoPrizePaid => ['pago', $this->intOrNull($details['pago_id'] ?? $details['id'] ?? null)],
            AuditAction::TicketVoided => ['ticket', $this->intOrNull($details['ticket_id'] ?? $details['id'] ?? null)],
            AuditAction::BetVoided,
            AuditAction::PagoLegacyReconciled => ['apuesta', $this->intOrNull($details['apuesta_id'] ?? $details['id'] ?? null)],
            default => [$action->entityType(), null],
        };
    }

    /**
     * @return array<int, string>
     */
    private function userEmails(Collection $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if ($row->user_id !== null && $row->user_id !== '') {
                $ids[] = (int) $row->user_id;
            }
        }

        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', array_values(array_unique($ids)))->pluck('email', 'id')->all();
    }

    private function legacyQuery(string $table, ?CarbonImmutable $from, ?CarbonImmutable $to, ?int $limit): Collection
    {
        $query = DB::table($table)
            ->orderBy('id')
            ->when($from !== null, fn ($builder) => $builder->where('created_at', '>=', $from->format('Y-m-d H:i:s')))
            ->when($to !== null, fn ($builder) => $builder->where('created_at', '<=', $to->format('Y-m-d H:i:s')));

        return $limit !== null ? $query->limit($limit)->get() : $query->get();
    }

    /**
     * Decode `details`/`cambios` once, tolerating the legacy double encoding.
     *
     * @return array<string, mixed>
     */
    private function decodeDetails(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;

        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function occurredAt(mixed $createdAt): string
    {
        return CarbonImmutable::parse((string) $createdAt, config('app.timezone'))
            ->utc()
            ->format('Y-m-d H:i:s');
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function emitCompleted(string $source, array $totals): void
    {
        app(AuditContext::class)->forSystem('command:audit:backfill', null, null, $source);
        app(AuditRecorder::class)->record(
            AuditAction::BackfillCompleted,
            new AuditEventData(
                action: AuditAction::BackfillCompleted,
                entityType: 'audit',
                context: [
                    'source' => $source,
                    'scanned' => $totals['scanned'],
                    'inserted' => $totals['inserted'],
                    'skipped' => $totals['skipped'],
                    'dry_run' => false,
                ],
            ),
        );
    }

    private function resolveLimit(): int|false|null
    {
        $raw = $this->option('limit');

        if ($raw === null || $raw === '') {
            return null;
        }

        $limit = filter_var($raw, FILTER_VALIDATE_INT);

        if ($limit === false || $limit <= 0) {
            $this->error('--limit debe ser un entero positivo.');

            return false;
        }

        return $limit;
    }

    private function parseBoundary(mixed $value, bool $endOfDay): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $date = CarbonImmutable::createFromFormat(
                    'Y-m-d H:i:s',
                    $value.' '.($endOfDay ? '23:59:59' : '00:00:00'),
                    new DateTimeZone('America/Caracas'),
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
