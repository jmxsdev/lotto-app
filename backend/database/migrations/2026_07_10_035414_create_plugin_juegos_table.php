<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_juegos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('juego_id')->constrained()->onDelete('cascade');
            $table->string('class_namespace');
            $table->string('version')->default('1.0.0');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_juegos');
    }
};
