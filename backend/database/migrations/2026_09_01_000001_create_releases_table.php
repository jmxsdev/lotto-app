<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de releases de la taquilla. Semántica D3: fila única (latest-only),
     * sin historial — el comando releases:publish reemplaza la fila anterior.
     */
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->string('version')->unique();
            $table->string('sha256', 64);
            $table->string('file_path');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
