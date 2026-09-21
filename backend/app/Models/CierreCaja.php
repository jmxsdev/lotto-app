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
        'arqueo_efectivo_bs', 'arqueo_efectivo_usd',
        'faltante_sobrante_bs', 'faltante_sobrante_usd',
        'desglose_metodos',
        'exchange_rate_cierre', 'created_by',
        'reclosed_by', 'reclosed_at',
    ];

    protected $casts = [
        'fecha_inicio' => 'datetime',
        'fecha_fin' => 'datetime',
        'reclosed_at' => 'datetime',
        'total_ventas_bs' => 'decimal:2',
        'total_ventas_usd' => 'decimal:2',
        'total_ventas_bs_equivalent' => 'decimal:2',
        'total_egresos_bs' => 'decimal:2',
        'total_egresos_usd' => 'decimal:2',
        'total_efectivo_bs' => 'decimal:2',
        'total_efectivo_usd' => 'decimal:2',
        'arqueo_efectivo_bs' => 'decimal:2',
        'arqueo_efectivo_usd' => 'decimal:2',
        'faltante_sobrante_bs' => 'decimal:2',
        'faltante_sobrante_usd' => 'decimal:2',
        'desglose_metodos' => 'array',
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
