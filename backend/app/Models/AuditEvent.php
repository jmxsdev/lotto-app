<?php

namespace App\Models;

use App\Audit\Casts\UtcDateTime;
use App\Exceptions\AuditAppendOnlyException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    protected $table = 'audit_events';

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'schema_version' => 'integer',
        'occurred_at' => UtcDateTime::class,
        'actor_id' => 'integer',
        'entity_id' => 'integer',
        'banca_id' => 'integer',
        'grupo_id' => 'integer',
        'agencia_id' => 'integer',
        'taquilla_id' => 'integer',
        'actor_roles' => 'array',
        'before' => 'array',
        'after' => 'array',
        'context' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new AuditAppendOnlyException('audit_events is append-only: updates are not allowed.');
        });

        static::deleting(static function (): void {
            throw new AuditAppendOnlyException('audit_events is append-only: deletes are not allowed.');
        });
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
