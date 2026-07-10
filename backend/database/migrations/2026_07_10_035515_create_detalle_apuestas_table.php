<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_apuestas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('apuesta_id')->constrained()->onDelete('cascade');
            $table->json('combinacion');
            $table->decimal('monto', 12, 2);
            $table->decimal('premio_posible', 12, 2);
            $table->decimal('premio_ganado', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_apuestas');
    }
};
