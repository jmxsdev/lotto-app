<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Predicado de exclusión de pares de reversión (D10).
 *
 * Una `devolucion` se considera par de reversión —y por tanto NO es una
 * salida de efectivo adicional— cuando su venta (`pagos.apuesta_id`) está
 * anulada (`estado = 'anulada'`) o soft-deleted Y la `fecha_hora` de la venta
 * cae dentro de la ventana del reporte. La venta ya está excluida del lado de
 * ventas, así que restar también la devolución duplicaría la resta.
 *
 * Propiedades:
 * - `from('apuestas as a_sale')` evita el scope global de SoftDeletes: la
 *   venta emparejada es justamente la que está borrada.
 * - `pagos.apuesta_id IS NULL` no encuentra venta: `NOT EXISTS` se cumple y
 *   una devolución suelta sigue contando como salida real.
 * - La ventana se evalúa sobre `a_sale.fecha_hora`, nunca sobre
 *   `pagos.created_at`. `halfOpen = true` usa `[desde, hasta)` para los
 *   cierres; el modo por defecto usa `whereDate` inclusivo para los reportes
 *   de agencia.
 */
final class ReversalPairQuery
{
    public static function aplicar(
        Builder $query,
        DateTimeInterface|string|null $desde,
        DateTimeInterface|string|null $hasta,
        bool $halfOpen = false
    ): Builder {
        return $query->whereNotExists(function ($sub) use ($desde, $hasta, $halfOpen) {
            $sub->selectRaw('1')
                ->from('apuestas as a_sale')
                ->whereColumn('a_sale.id', 'pagos.apuesta_id')
                ->where(function ($where) {
                    $where->where('a_sale.estado', 'anulada')
                        ->orWhereNotNull('a_sale.deleted_at');
                });

            if ($desde !== null && $desde !== '') {
                if ($halfOpen) {
                    $sub->where('a_sale.fecha_hora', '>=', $desde);
                } else {
                    $sub->whereDate('a_sale.fecha_hora', '>=', $desde);
                }
            }

            if ($hasta !== null && $hasta !== '') {
                if ($halfOpen) {
                    $sub->where('a_sale.fecha_hora', '<', $hasta);
                } else {
                    $sub->whereDate('a_sale.fecha_hora', '<=', $hasta);
                }
            }
        });
    }
}
