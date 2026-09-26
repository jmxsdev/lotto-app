<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class Pago extends Model
{
    use HasFactory;

    /** Métodos de pago permitidos (enum pagos.metodo_pago). */
    public const METODOS_PAGO = ['efectivo', 'transferencia', 'pago_movil', 'punto_venta'];

    protected $fillable = [
        'taquilla_id', 'apuesta_id', 'amount_bs', 'amount_usd',
        'exchange_rate_applied', 'tipo', 'moneda', 'concepto', 'referencia',
        'metodo_pago', 'created_by',
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

    /**
     * Resuelve el método de pago efectivo de un Pago.
     *
     * - Si se omite el método, se usa el default 'efectivo'.
     * - Si el pago es USD (por moneda o por componente en dólares),
     *   el método se fuerza a 'efectivo'.
     * - Cualquier valor fuera de METODOS_PAGO se rechaza.
     */
    public static function resolverMetodoPago(?string $metodo, string $moneda, float|int|string $amountUsd = 0): string
    {
        $metodo ??= 'efectivo';

        if (! in_array($metodo, self::METODOS_PAGO, true)) {
            throw new InvalidArgumentException("Metodo de pago invalido: {$metodo}");
        }

        if ($moneda === 'usd' || (float) $amountUsd > 0) {
            return 'efectivo';
        }

        return $metodo;
    }
}
