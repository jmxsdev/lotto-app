<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JuegoHorario extends Model
{
    use HasFactory;

    protected $fillable = [
        'juego_id', 'hora', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function juego()
    {
        return $this->belongsTo(Juego::class);
    }
}
