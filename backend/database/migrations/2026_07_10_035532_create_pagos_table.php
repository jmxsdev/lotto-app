<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taquilla_id')->constrained()->onDelete('cascade');
            $table->foreignId('apuesta_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('amount_bs', 12, 2)->default(0);
            $table->decimal('amount_usd', 12, 2)->default(0);
            $table->decimal('exchange_rate_applied', 10, 4);
            $table->enum('tipo', ['ingreso', 'egreso']);
            $table->string('concepto');
            $table->string('referencia')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
