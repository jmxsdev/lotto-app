<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rango genérico en el ledger de comisiones (D3).
 *
 * Añade `fecha_inicio`/`fecha_fin` (date, nullable) + índice; `periodo`
 * sigue como etiqueta `YYYY-MM-DD..YYYY-MM-DD`. Columnas nullable para no
 * romper filas legacy; la consulta de solapamiento de `previsualizar` las
 * usa (fecha_inicio <= hasta AND fecha_fin >= desde).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comisiones', function (Blueprint $table) {
            $table->date('fecha_inicio')->nullable()->after('periodo');
            $table->date('fecha_fin')->nullable()->after('fecha_inicio');
            $table->index(['fecha_inicio', 'fecha_fin']);
        });
    }

    public function down(): void
    {
        Schema::table('comisiones', function (Blueprint $table) {
            $table->dropIndex(['fecha_inicio', 'fecha_fin']);
            $table->dropColumn(['fecha_inicio', 'fecha_fin']);
        });
    }
};
