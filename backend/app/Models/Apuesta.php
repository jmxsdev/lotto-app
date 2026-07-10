<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Apuesta extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'taquilla_id', 'juego_id', 'amount_bs', 'amount_usd',
        'exchange_rate_applied', 'total_bs_equivalent', 'estado',
        'fecha_hora'
    ];

    protected $casts = [
        'amount_bs' => 'decimal:2',
        'amount_usd' => 'decimal:2',
        'exchange_rate_applied' => 'decimal:4',
        'total_bs_equivalent' => 'decimal:2',
        'fecha_hora' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    public function taquilla()
    {
        return $this->belongsTo(Taquilla::class);
    }

    public function juego()
    {
        return $this->belongsTo(Juego::class);
    }

    public function detalles()
    {
        return $this->hasMany(DetalleApuesta::class);
    }

    public function pago()
    {
        return $this->hasOne(Pago::class);
    }
}
