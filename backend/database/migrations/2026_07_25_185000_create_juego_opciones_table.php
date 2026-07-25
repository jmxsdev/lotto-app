<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('juego_opciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('juego_id')->constrained()->onDelete('cascade');
            $table->string('label');
            $table->string('value');
            $table->integer('numero')->nullable();
            $table->string('imagen_url')->nullable();
            $table->string('color')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['juego_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('juego_opciones');
    }
};
