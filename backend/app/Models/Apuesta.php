<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Apuesta extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'taquilla_id', 'juego_id', 'resultado_id', 'combinacion', 'ticket_code',
        'amount_bs', 'amount_usd', 'exchange_rate_applied', 'total_bs_equivalent',
        'estado', 'fecha_hora', 'sorteo_hora'
    ];

    protected $casts = [
        'amount_bs' => 'decimal:2',
        'amount_usd' => 'decimal:2',
        'exchange_rate_applied' => 'decimal:4',
        'total_bs_equivalent' => 'decimal:2',
        'fecha_hora' => 'datetime',
        'sorteo_hora' => 'datetime',
        'combinacion' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($apuesta) {
            if (!$apuesta->ticket_code) {
                $apuesta->ticket_code = strtoupper(Str::random(8));
            }
        });
    }

    public function taquilla()
    {
        return $this->belongsTo(Taquilla::class);
    }

    public function juego()
    {
        return $this->belongsTo(Juego::class);
    }

    public function resultado()
    {
        return $this->belongsTo(Resultado::class);
    }

    public function detalles()
    {
        return $this->hasMany(DetalleApuesta::class);
    }

    public function pago()
    {
        return $this->hasOne(Pago::class);
    }
    
    public function getAnimalApostadoAttribute(): ?string
    {
        return $this->combinacion['animal'] ?? null;
    }
    
    public function getNumeroApostadoAttribute(): ?int
    {
        return $this->combinacion['numero'] ?? null;
    }
}
