<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega el estado `ganadora` al ENUM de `apuestas` (REQ13/D5).
 *
 * Transiciones: `pendiente → ganadora` (premio > 0, con `resultado_id`) →
 * `pagada` (pago manual). `vencido` YA existe en el ENUM (migración
 * `2026_08_11_000004_add_vencido_to_estados`); no se duplica.
 *
 * Idempotente: re-ejecutar el ALTER con el mismo ENUM es un no-op en MySQL.
 * Reversible: las filas `ganadora` vuelven a `pendiente` (conservando su
 * `resultado_id` para no re-liquidar como nuevas) y se restaura el ENUM previo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE apuestas MODIFY COLUMN estado '
            ."ENUM('pendiente','pagada','anulada','perdida','vencido','ganadora') "
            ."DEFAULT 'pendiente'"
        );
    }

    public function down(): void
    {
        // Evita el truncado a '' del ENUM: primero se reubica la fila.
        DB::statement("UPDATE apuestas SET estado = 'pendiente' WHERE estado = 'ganadora'");

        DB::statement(
            'ALTER TABLE apuestas MODIFY COLUMN estado '
            ."ENUM('pendiente','pagada','anulada','perdida','vencido') "
            ."DEFAULT 'pendiente'"
        );
    }
};
