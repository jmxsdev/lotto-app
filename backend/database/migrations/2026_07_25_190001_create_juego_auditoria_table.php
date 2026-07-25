<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('juego_auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('juego_id')->constrained('juegos')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('accion'); // activar, desactivar, actualizar
            $table->json('cambios')->nullable(); // antes/después
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('juego_auditoria');
    }
};
