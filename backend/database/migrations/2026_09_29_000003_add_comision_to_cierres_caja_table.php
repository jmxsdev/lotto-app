<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columna de comisión en cierres de caja (D10).
 *
 * `comision_bs_equivalent` (decimal, nullable): comisión del período en
 * bs-equivalente, persistida en el snapshot del cierre. Nullable para no
 * romper cierres legacy; S5 la alimenta desde ComisionService
 * (calcularTotales/previsualizar/reporteSemanal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cierres_caja', function (Blueprint $table) {
            $table->decimal('comision_bs_equivalent', 12, 2)->nullable()->after('total_efectivo_usd');
        });
    }

    public function down(): void
    {
        Schema::table('cierres_caja', function (Blueprint $table) {
            $table->dropColumn('comision_bs_equivalent');
        });
    }
};
