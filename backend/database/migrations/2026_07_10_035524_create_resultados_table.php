<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resultados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('juego_id')->constrained()->onDelete('cascade');
            $table->timestamp('fecha_sorteo');
            $table->json('numeros_ganadores');
            $table->json('premios_detalle')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultados');
    }
};
