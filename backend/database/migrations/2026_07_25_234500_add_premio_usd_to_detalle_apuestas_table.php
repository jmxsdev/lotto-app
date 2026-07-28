<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detalle_apuestas', function (Blueprint $table) {
            $table->decimal('premio_posible_usd', 12, 2)->default(0)->after('premio_posible');
            $table->decimal('premio_ganado_usd', 12, 2)->nullable()->after('premio_ganado');
        });
    }

    public function down(): void
    {
        Schema::table('detalle_apuestas', function (Blueprint $table) {
            $table->dropColumn(['premio_posible_usd', 'premio_ganado_usd']);
        });
    }
};
