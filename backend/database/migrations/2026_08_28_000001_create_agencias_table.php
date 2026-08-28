<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea la tabla de agencias (locales físicos), nivel intermedio entre
     * grupo y taquilla. Solo identidad: no configura límites/monedas/
     * vigencia/tiempo (passthrough).
     */
    public function up(): void
    {
        Schema::create('agencias', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->foreignId('grupo_id')->constrained()->onDelete('cascade');
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rif')->nullable();
            $table->string('email')->nullable();
            $table->string('telefono')->nullable();
            $table->string('direccion')->nullable();
            $table->string('estado')->nullable();
            $table->string('municipio')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('grupo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agencias');
    }
};
