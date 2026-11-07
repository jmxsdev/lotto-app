<?php

namespace App\Models;

use App\Exceptions\AuditAppendOnlyException;
use Illuminate\Database\Eloquent\Model;

/**
 * Legacy game audit table, now read-only.
 *
 * New game events are written to `audit_events` by `AuditRecorder`; this model
 * only preserves historical reads (e.g. `Juego::auditoria()`). Any write
 * attempt throws `AuditAppendOnlyException`.
 */
class JuegoAuditoria extends Model
{
    protected $table = 'juego_auditoria';

    protected $fillable = [
        'juego_id', 'user_id', 'accion', 'cambios',
    ];

    protected $casts = [
        'cambios' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(static function (): void {
            throw new AuditAppendOnlyException('juego_auditoria is read-only; use audit_events.');
        });

        static::updating(static function (): void {
            throw new AuditAppendOnlyException('juego_auditoria is read-only; use audit_events.');
        });

        static::deleting(static function (): void {
            throw new AuditAppendOnlyException('juego_auditoria is read-only; use audit_events.');
        });
    }

    public function juego()
    {
        return $this->belongsTo(Juego::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
