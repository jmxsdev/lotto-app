<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comisiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('banca_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('grupo_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('taquilla_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('periodo'); // ej: "2026-07"
            $table->decimal('monto_comision', 12, 2);
            $table->enum('estado', ['pendiente', 'pagado'])->default('pendiente');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comisiones');
    }
};
