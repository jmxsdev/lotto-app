<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Campos dormidos (WU1): se retiran de la API pero las columnas quedan
     * en BD. Ocultos de la serialización porque `limites()`, `updateLimites`
     * y `batchLimites` devuelven el modelo crudo (decisión A2 del design).
     */
    protected $hidden = [
        'fraccion',
        'limite_tiempo',
    ];

    public function juego()
    {
        return $this->belongsTo(Juego::class);
    }

    /**
     * Solo filas cuyo juego está activo: los límites de juegos inactivos
     * (p. ej. la-ricachona, REQ7) no deben exponerse en la matriz de límites.
     */
    public function scopeDeJuegosActivos(Builder $query): Builder
    {
        return $query->whereHas('juego', fn (Builder $q) => $q->where('active', true));
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
