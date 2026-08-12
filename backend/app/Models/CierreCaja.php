<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CierreCaja extends Model
{
    use HasFactory;

    protected $table = 'cierres_caja';

    protected $fillable = [
        'taquilla_id', 'fecha_inicio', 'fecha_fin',
        'total_ventas_bs', 'total_ventas_usd', 'total_ventas_bs_equivalent',
        'total_egresos_bs', 'total_egresos_usd',
        'total_efectivo_bs', 'total_efectivo_usd',
        'exchange_rate_cierre', 'created_by'
    ];

    protected $casts = [
        'fecha_inicio' => 'datetime',
        'fecha_fin' => 'datetime',
        'total_ventas_bs' => 'decimal:2',
        'total_ventas_usd' => 'decimal:2',
        'total_ventas_bs_equivalent' => 'decimal:2',
        'total_egresos_bs' => 'decimal:2',
        'total_egresos_usd' => 'decimal:2',
        'total_efectivo_bs' => 'decimal:2',
        'total_efectivo_usd' => 'decimal:2',
        'exchange_rate_cierre' => 'decimal:4',
    ];

    public function taquilla()
    {
        return $this->belongsTo(Taquilla::class);
    }

    public function creador()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
