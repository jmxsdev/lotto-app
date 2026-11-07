<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies the legacy `juego_auditoria` history into `audit_events`.
 *
 * The deterministic `event_uuid` (sha1 of `juego_auditoria:<id>`) keeps the
 * migration idempotent: re-running it skips rows that were already copied.
 * Only business events with an actor are copied; scrapers never wrote here.
 */
return new class extends Migration
{
    private const CHUNK = 500;

    /**
     * @var array<string, string>
     */
    private const ACTION_MAP = [
        'activar' => 'game.toggled',
        'desactivar' => 'game.toggled',
        'actualizar' => 'game.updated',
        'premios' => 'game.prizes_updated',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('juego_auditoria') || ! Schema::hasTable('audit_events')) {
            return;
        }

        DB::table('juego_auditoria')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows): void {
                $events = [];

                foreach ($rows as $row) {
                    $events[] = $this->toAuditEvent($row);
                }

                if ($events !== []) {
                    DB::table('audit_events')->insertOrIgnore($events);
                }
            });
    }

    public function down(): void
    {
        // The append-only triggers are dropped by the later migration first
        // (Laravel rolls back 000005 before this one), so this DELETE is safe
        // during a normal rollback. A targeted rollback needs 000005 reverted
        // explicitly first.
        DB::table('audit_events')
            ->where('context->legacy_source', 'juego_auditoria')
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function toAuditEvent(object $row): array
    {
        $cambios = is_string($row->cambios) ? json_decode($row->cambios, true) : $row->cambios;
        $cambios = is_array($cambios) ? $cambios : [];

        return [
            'event_uuid' => sha1('juego_auditoria:'.$row->id),
            'schema_version' => 1,
            'occurred_at' => CarbonImmutable::parse($row->created_at, config('app.timezone'))
                ->utc()
                ->format('Y-m-d H:i:s'),
            'actor_type' => 'user',
            'actor_id' => $row->user_id,
            'actor_label' => null,
            'actor_roles' => null,
            'action' => self::ACTION_MAP[$row->accion] ?? 'game.updated',
            'entity_type' => 'juego',
            'entity_id' => $row->juego_id,
            'banca_id' => null,
            'grupo_id' => null,
            'agencia_id' => null,
            'taquilla_id' => null,
            'before' => $this->encodeJson($cambios['before'] ?? null),
            'after' => $this->encodeJson($cambios['after'] ?? null),
            'context' => $this->encodeJson([
                'legacy_source' => 'juego_auditoria',
                'legacy_id' => $row->id,
                'legacy_accion' => $row->accion,
            ]),
            'reason_code' => null,
            'reason_text' => null,
            'ip' => null,
            'user_agent' => null,
            'request_id' => null,
            'job_id' => null,
            'hash' => null,
            'prev_hash' => null,
        ];
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
};
