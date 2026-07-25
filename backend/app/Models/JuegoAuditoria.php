<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JuegoAuditoria extends Model
{
    protected $table = 'juego_auditoria';

    protected $fillable = [
        'juego_id', 'user_id', 'accion', 'cambios',
    ];

    protected $casts = [
        'cambios' => 'array',
    ];

    public function juego()
    {
        return $this->belongsTo(Juego::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
