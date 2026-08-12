<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agrega el tipo 'devolucion' al ENUM de pagos.tipo.
     *
     * ALTER exclusivo de MySQL: SQLite trata enum como varchar sin
     * restricción, por lo que no requiere cambios.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE pagos MODIFY COLUMN tipo "
            . "ENUM('ingreso','egreso','devolucion') "
            . "NOT NULL"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Revertir solo si no existen pagos de tipo devolucion
        $devoluciones = DB::table('pagos')->where('tipo', 'devolucion')->count();
        if ($devoluciones > 0) {
            throw new \RuntimeException(
                'No se puede revertir: existen pagos con tipo devolucion.'
            );
        }

        DB::statement(
            "ALTER TABLE pagos MODIFY COLUMN tipo "
            . "ENUM('ingreso','egreso') "
            . "NOT NULL"
        );
    }
};
