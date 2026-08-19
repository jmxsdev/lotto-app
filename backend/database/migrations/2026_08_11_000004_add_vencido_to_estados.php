<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agrega el estado 'vencido' a los ENUM de apuestas y tickets.
     */
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE apuestas MODIFY COLUMN estado '
            ."ENUM('pendiente','pagada','anulada','perdida','vencido') "
            ."DEFAULT 'pendiente'"
        );

        DB::statement(
            'ALTER TABLE tickets MODIFY COLUMN estado '
            ."ENUM('pendiente','pagada','anulada','ganador','vencido') "
            ."DEFAULT 'pendiente'"
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE apuestas MODIFY COLUMN estado '
            ."ENUM('pendiente','pagada','anulada','perdida') "
            ."DEFAULT 'pendiente'"
        );

        DB::statement(
            'ALTER TABLE tickets MODIFY COLUMN estado '
            ."ENUM('pendiente','pagada','anulada','ganador') "
            ."DEFAULT 'pendiente'"
        );
    }
};
