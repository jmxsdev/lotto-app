<?php

namespace App\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Opt-in Eloquent trait that records create/update/delete audit events.
 *
 * It is a helper, not a global observer: a model only emits events when it uses
 * the trait and declares an action map. Models configure the trait through
 * methods (never properties) so they cannot collide with Eloquent attributes or
 * with a parent model's own properties.
 *
 * The trait never opens a transaction. P0 actions rely on the caller's
 * transaction (design §4.5): controllers and services must wrap the mutation
 * and the audit write in `DB::transaction()` so a failed write rolls the
 * business change back. The caller is also responsible for setting a reason in
 * `AuditContext` before destructive actions that require one.
 */
trait Auditable
{
    /**
     * Register the model events once the model boots.
     */
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            $model->recordAuditEvent('created');
        });

        static::updated(function (Model $model): void {
            $model->recordAuditEvent('updated');
        });

        static::deleted(function (Model $model): void {
            $model->recordAuditEvent('deleted');
        });
    }

    /**
     * Map of Eloquent events to audit actions. Returning an empty map (the
     * default) keeps the audit disabled for the model.
     *
     * @return array<string, AuditAction>
     */
    protected function auditActionMap(): array
    {
        return [];
    }

    /**
     * Attributes that must never be captured, on top of the recorder's PII rules.
     *
     * @return array<int, string>
     */
    protected function auditExclude(): array
    {
        return [];
    }

    /**
     * Extra attributes whose value the trait replaces with `[REDACTED]`.
     *
     * @return array<int, string>
     */
    protected function auditRedact(): array
    {
        return [];
    }

    public function auditActionFor(string $event): ?AuditAction
    {
        $action = $this->auditActionMap()[$event] ?? null;

        return $action instanceof AuditAction ? $action : null;
    }

    public function auditEnabledFor(string $event): bool
    {
        return $this->auditActionFor($event) !== null;
    }

    /**
     * Full snapshot of the current attributes, minus excluded keys and with the
     * configured redactions applied. Used for `created` and `deleted`.
     *
     * @return array<string, mixed>
     */
    public function auditSnapshot(): array
    {
        return $this->redactAuditPayload(
            Arr::except($this->getAttributes(), $this->auditExcludedAttributes()),
        );
    }

    /**
     * Safe diff of the last save, minus excluded keys and with the configured
     * redactions applied.
     *
     * @return array<string, mixed>
     */
    public function auditChanges(): array
    {
        return $this->redactAuditPayload(
            Arr::except($this->getChanges(), $this->auditExcludedAttributes()),
        );
    }

    /**
     * Technical timestamp columns are never evidence of a business change.
     *
     * @return array<int, string>
     */
    private function auditExcludedAttributes(): array
    {
        return array_values(array_unique(array_merge(
            ['created_at', 'updated_at', 'deleted_at'],
            $this->auditExclude(),
        )));
    }

    /**
     * Build and record the event for the current model event.
     */
    protected function recordAuditEvent(string $event): void
    {
        $action = $this->auditActionFor($event);

        if ($action === null) {
            return;
        }

        $context = [
            'model_event' => $event,
            'model' => static::class,
        ];

        $data = match ($event) {
            'created' => $this->auditEventData($action, null, $this->auditSnapshot(), $context),
            'updated' => $this->updatedAuditEventData($action, $context),
            'deleted' => $this->auditEventData($action, $this->auditSnapshot(), null, $context),
            default => null,
        };

        if ($data === null) {
            return;
        }

        app(AuditRecorder::class)->record($action, $data);
    }

    private function updatedAuditEventData(AuditAction $action, array $context): ?AuditEventData
    {
        $changes = $this->auditChanges();

        if ($changes === []) {
            return null;
        }

        $before = $this->redactAuditPayload(
            Arr::only($this->getOriginal(), array_keys($changes)),
        );

        return $this->auditEventData($action, $before, $changes, $context);
    }

    /**
     * Build the DTO from the model and attach the derived hierarchy scope.
     *
     * `AuditEventData::forModel()` owns the create/delete normalization (null
     * `before` on create, null `after` on delete); the scope is added afterwards
     * because `forModel()` does not take one.
     */
    private function auditEventData(AuditAction $action, ?array $before, ?array $after, array $context): AuditEventData
    {
        $data = AuditEventData::forModel($this, $action, $before, $after, $context);

        return new AuditEventData(
            action: $data->action,
            eventUuid: $data->eventUuid,
            schemaVersion: $data->schemaVersion,
            occurredAt: $data->occurredAt,
            actorType: $data->actorType,
            entityType: $data->entityType,
            entityId: $data->entityId,
            scope: app(AuditScopeResolver::class)->forModel($this),
            before: $data->before,
            after: $data->after,
            context: $data->context,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactAuditPayload(array $payload): array
    {
        foreach ($this->auditRedact() as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = AuditRedactor::REDACTED;
            }
        }

        return $payload;
    }
}
