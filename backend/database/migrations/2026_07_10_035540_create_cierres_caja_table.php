<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cierres_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taquilla_id')->constrained()->onDelete('cascade');
            $table->timestamp('fecha_inicio');
            $table->timestamp('fecha_fin')->nullable();
            $table->decimal('total_ventas_bs', 12, 2)->default(0);
            $table->decimal('total_ventas_usd', 12, 2)->default(0);
            $table->decimal('total_ventas_bs_equivalent', 12, 2)->default(0);
            $table->decimal('total_egresos_bs', 12, 2)->default(0);
            $table->decimal('total_egresos_usd', 12, 2)->default(0);
            $table->decimal('total_efectivo_bs', 12, 2)->default(0);
            $table->decimal('total_efectivo_usd', 12, 2)->default(0);
            $table->decimal('exchange_rate_cierre', 10, 4);
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cierres_caja');
    }
};
