<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidencia de resultados para jugadas multi-sorteo (tripleta).
 *
 * `resultado_id` sigue apuntando al último resultado que completó la jugada
 * (compatibilidad con el flujo de pago). `resultado_ids` conserva los 3 IDs
 * usados para auditoría y para recalcular el premio en el pago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apuestas', function (Blueprint $table) {
            $table->json('resultado_ids')->nullable()->after('resultado_id');
        });
    }

    public function down(): void
    {
        Schema::table('apuestas', function (Blueprint $table) {
            $table->dropColumn('resultado_ids');
        });
    }
};
