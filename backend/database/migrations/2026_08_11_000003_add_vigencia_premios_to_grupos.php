<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega vigencia_premios (días) a bancas, grupos y taquillas.
     * Herencia jerárquica: banca → grupo → taquilla.
     * Cada nivel solo puede acortar (más restrictivo) el plazo del padre.
     * NULL = los premios nunca expiran en ese nivel.
     */
    public function up(): void
    {
        Schema::table('bancas', function (Blueprint $table) {
            $table->integer('vigencia_premios')
                ->nullable()
                ->after('monedas_permitidas')
                ->comment('días; NULL = no expira');
        });

        Schema::table('grupos', function (Blueprint $table) {
            $table->integer('vigencia_premios')
                ->nullable()
                ->after('monedas_permitidas')
                ->comment('días; NULL = no expira');
        });

        Schema::table('taquillas', function (Blueprint $table) {
            $table->integer('vigencia_premios')
                ->nullable()
                ->after('code')
                ->comment('días; NULL = no expira');
        });
    }

    public function down(): void
    {
        Schema::table('bancas', function (Blueprint $table) {
            $table->dropColumn('vigencia_premios');
        });

        Schema::table('grupos', function (Blueprint $table) {
            $table->dropColumn('vigencia_premios');
        });

        Schema::table('taquillas', function (Blueprint $table) {
            $table->dropColumn('vigencia_premios');
        });
    }
};
