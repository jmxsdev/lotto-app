<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeState extends Model
{
    protected $fillable = [
        'juego_id', 'fecha', 'estado', 'intentos', 'ultimo_error',
    ];

    protected $casts = [
        'fecha' => 'date',
        'intentos' => 'integer',
    ];

    public function juego(): BelongsTo
    {
        return $this->belongsTo(Juego::class);
    }
}
