<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('resultados', function (Blueprint $table) {
            $table->string('hora_sorteo')->nullable()->after('fecha_sorteo');
            $table->string('nombre_animal')->nullable()->after('numeros_ganadores');
            $table->string('imagen_animal')->nullable()->after('nombre_animal');
            $table->string('color_animal')->nullable()->after('imagen_animal');
            $table->string('sorteo_id_externo')->nullable()->after('color_animal');
            $table->string('pais')->nullable()->after('sorteo_id_externo');
            
            $table->unique(['juego_id', 'fecha_sorteo', 'hora_sorteo'], 'resultados_juego_fecha_hora_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resultados', function (Blueprint $table) {
            $table->dropUnique('resultados_juego_fecha_hora_unique');
            $table->dropColumn([
                'hora_sorteo',
                'nombre_animal',
                'imagen_animal',
                'color_animal',
                'sorteo_id_externo',
                'pais'
            ]);
        });
    }
};
