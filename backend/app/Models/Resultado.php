<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Resultado extends Model
{
    use HasFactory;

    protected $fillable = [
        'juego_id', 'fecha_sorteo', 'numeros_ganadores', 'premios_detalle'
    ];

    protected $casts = [
        'fecha_sorteo' => 'datetime',
        'numeros_ganadores' => 'array',
        'premios_detalle' => 'array',
    ];

    public function juego()
    {
        return $this->belongsTo(Juego::class);
    }
}
