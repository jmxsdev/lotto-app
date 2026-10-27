<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina `premio_posible` del contrato de venta (issue #63).
 *
 * El dato no se imprime en el ticket, ningún front lo consume y no interviene
 * en el pago ni en el settlement. Se retira el cálculo, la persistencia y las
 * columnas para no dejar un contrato muerto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detalle_apuestas', function (Blueprint $table) {
            $table->dropColumn(['premio_posible', 'premio_posible_usd']);
        });
    }

    public function down(): void
    {
        Schema::table('detalle_apuestas', function (Blueprint $table) {
            $table->decimal('premio_posible', 12, 2)->default(0);
            $table->decimal('premio_posible_usd', 12, 2)->default(0);
        });
    }
};
