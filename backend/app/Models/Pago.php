<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pago extends Model
{
    use HasFactory;

    protected $fillable = [
        'taquilla_id', 'apuesta_id', 'amount_bs', 'amount_usd',
        'exchange_rate_applied', 'tipo', 'concepto', 'referencia',
        'created_by'
    ];

    protected $casts = [
        'amount_bs' => 'decimal:2',
        'amount_usd' => 'decimal:2',
        'exchange_rate_applied' => 'decimal:4',
    ];

    public function taquilla()
    {
        return $this->belongsTo(Taquilla::class);
    }

    public function apuesta()
    {
        return $this->belongsTo(Apuesta::class);
    }

    public function creador()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
