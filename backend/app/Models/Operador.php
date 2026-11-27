<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Operador extends Model
{
    protected $table = 'operadores';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'entidad_tipo',
        'entidad_id',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entidad(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'entidad_tipo', 'entidad_id');
    }
}
