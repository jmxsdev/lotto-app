<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JuegoOpcion extends Model
{
    use HasFactory;

    protected $table = 'juego_opciones';

    protected $fillable = [
        'juego_id', 'label', 'value', 'numero', 'imagen_url',
        'color', 'metadata', 'active', 'sort_order'
    ];

    protected $casts = [
        'metadata' => 'array',
        'active' => 'boolean',
    ];

    public function juego()
    {
        return $this->belongsTo(Juego::class);
    }
}
