<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resultados', function (Blueprint $table) {
            $table->dropColumn([
                'nombre_animal',
                'imagen_animal',
                'color_animal',
                'pais',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('resultados', function (Blueprint $table) {
            $table->string('nombre_animal')->nullable()->after('numeros_ganadores');
            $table->string('imagen_animal')->nullable()->after('nombre_animal');
            $table->string('color_animal')->nullable()->after('imagen_animal');
            $table->string('pais')->nullable()->after('sorteo_id_externo');
        });
    }
};
