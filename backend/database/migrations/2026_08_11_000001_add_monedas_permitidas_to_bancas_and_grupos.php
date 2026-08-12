<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega columnas JSON monedas_permitidas a bancas y grupos.
     * NULL = ambas monedas habilitadas (sin restricción).
     */
    public function up(): void
    {
        Schema::table('bancas', function (Blueprint $table) {
            $table->json('monedas_permitidas')->nullable()->after('config');
        });

        Schema::table('grupos', function (Blueprint $table) {
            $table->json('monedas_permitidas')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('bancas', function (Blueprint $table) {
            $table->dropColumn('monedas_permitidas');
        });

        Schema::table('grupos', function (Blueprint $table) {
            $table->dropColumn('monedas_permitidas');
        });
    }
};
