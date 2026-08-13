<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('juego_id')->constrained('juegos')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('estado', 20)->default('pending');
            $table->unsignedTinyInteger('intentos')->default(0);
            $table->text('ultimo_error')->nullable();
            $table->timestamps();

            $table->unique(['juego_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_states');
    }
};
