<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JuegoLimite extends Model
{
    use HasFactory;

    protected $fillable = [
        'juego_id',
        'banca_id',
        'grupo_id',
        'taquilla_id',
        'moneda',
        'limite_minimo',
        'limite_maximo',
        'porcentaje_pago',
        'participacion',
        'fraccion',
        'limite_tiempo',
    ];

    protected $casts = [
        'limite_minimo' => 'decimal:2',
        'limite_maximo' => 'decimal:2',
        'porcentaje_pago' => 'decimal:2',
        'participacion' => 'decimal:2',
        'fraccion' => 'boolean',
        'limite_tiempo' => 'integer',
    ];

    public function juego()
    {
        return $this->belongsTo(Juego::class);
    }

    public function banca()
    {
        return $this->belongsTo(Banca::class);
    }

    public function grupo()
    {
        return $this->belongsTo(Grupo::class);
    }

    public function taquilla()
    {
        return $this->belongsTo(Taquilla::class);
    }
}
