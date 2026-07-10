<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apuestas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taquilla_id')->constrained()->onDelete('cascade');
            $table->foreignId('juego_id')->constrained()->onDelete('cascade');
            $table->decimal('amount_bs', 12, 2)->default(0);
            $table->decimal('amount_usd', 12, 2)->default(0);
            $table->decimal('exchange_rate_applied', 10, 4);
            $table->decimal('total_bs_equivalent', 12, 2);
            $table->enum('estado', ['pendiente', 'pagada', 'anulada'])->default('pendiente');
            $table->timestamp('fecha_hora')->useCurrent();
            $table->softDeletes(); // Para la regla de eliminación de 5 min
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apuestas');
    }
};
