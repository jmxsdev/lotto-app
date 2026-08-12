<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega vigencia_premios (días) a grupos.
     * NULL = los premios nunca expiran.
     */
    public function up(): void
    {
        Schema::table('grupos', function (Blueprint $table) {
            $table->integer('vigencia_premios')
                ->nullable()
                ->after('monedas_permitidas')
                ->comment('días; NULL = no expira');
        });
    }

    public function down(): void
    {
        Schema::table('grupos', function (Blueprint $table) {
            $table->dropColumn('vigencia_premios');
        });
    }
};
