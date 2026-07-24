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
        Schema::table('apuestas', function (Blueprint $table) {
            $table->json('combinacion')->nullable()->after('juego_id');
            $table->string('ticket_code', 20)->nullable()->unique()->after('taquilla_id');
            $table->timestamp('sorteo_hora')->nullable()->after('total_bs_equivalent');
            $table->foreignId('resultado_id')->nullable()->after('juego_id')->constrained()->onDelete('set null');
            
            // Índices para queries frecuentes
            $table->index(['taquilla_id', 'estado']);
            $table->index(['fecha_hora']);
            $table->index('sorteo_hora');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('apuestas', function (Blueprint $table) {
            $table->dropForeign(['resultado_id']);
            $table->dropIndex(['taquilla_id', 'estado']);
            $table->dropIndex(['fecha_hora']);
            $table->dropIndex('sorteo_hora');
            $table->dropUnique(['ticket_code']);
            $table->dropColumn(['combinacion', 'ticket_code', 'sorteo_hora', 'resultado_id']);
        });
    }
};
