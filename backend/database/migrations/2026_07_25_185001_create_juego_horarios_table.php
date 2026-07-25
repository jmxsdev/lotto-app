<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('juego_horarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('juego_id')->constrained()->onDelete('cascade');
            $table->time('hora');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['juego_id', 'hora']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('juego_horarios');
    }
};
