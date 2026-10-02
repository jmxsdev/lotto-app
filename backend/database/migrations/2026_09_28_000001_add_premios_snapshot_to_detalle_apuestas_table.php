<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S2/D4 — Snapshot de premios por apuesta (sin retroactividad).
 *
 * `detalle_apuestas.premios_snapshot` guarda `config.premios` vigente al
 * vender; la liquidación y el pago resuelven contra él. NULL = apuesta legacy
 * (vendida antes de la feature) → fallback al `config.premios` actual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detalle_apuestas', function (Blueprint $table) {
            $table->json('premios_snapshot')->nullable()->after('premio_ganado_usd');
        });
    }

    public function down(): void
    {
        Schema::table('detalle_apuestas', function (Blueprint $table) {
            $table->dropColumn('premios_snapshot');
        });
    }
};
