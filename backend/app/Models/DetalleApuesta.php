<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetalleApuesta extends Model
{
    use HasFactory;

    protected $fillable = [
        'apuesta_id', 'combinacion', 'monto',
        'premio_posible', 'premio_posible_usd',
        'premio_ganado', 'premio_ganado_usd',
    ];

    protected $casts = [
        'combinacion' => 'array',
        'monto' => 'decimal:2',
        'premio_posible' => 'decimal:2',
        'premio_posible_usd' => 'decimal:2',
        'premio_ganado' => 'decimal:2',
        'premio_ganado_usd' => 'decimal:2',
    ];

    public function apuesta()
    {
        return $this->belongsTo(Apuesta::class);
    }
}
