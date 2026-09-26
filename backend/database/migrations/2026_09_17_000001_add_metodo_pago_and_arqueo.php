<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega metodo_pago a pagos (con backfill) y los campos de arqueo,
     * faltante/sobrante y desglose al cierre de caja.
     */
    public function up(): void
    {
        // La lista es literal y autocontenida (no usa la const del modelo).
        $metodos = ['efectivo', 'transferencia', 'pago_movil', 'punto_venta'];

        Schema::table('pagos', function (Blueprint $table) use ($metodos) {
            $table->enum('metodo_pago', $metodos)
                ->nullable()
                ->default('efectivo')
                ->after('moneda');
        });

        // Backfill histórico (OQ5): las filas sin método se aproximan a efectivo.
        DB::table('pagos')->whereNull('metodo_pago')->update(['metodo_pago' => 'efectivo']);

        // Re-aserción MySQL-only del ENUM final (patrón alter_pagos_tipo_add_devolucion):
        // fija la lista canónica en producción; metadata-only, mismo ENUM que el ADD.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE pagos MODIFY COLUMN metodo_pago '
                ."ENUM('efectivo','transferencia','pago_movil','punto_venta') "
                ."NULL DEFAULT 'efectivo'"
            );
        }

        Schema::table('cierres_caja', function (Blueprint $table) {
            $table->decimal('arqueo_efectivo_bs', 12, 2)->nullable()->after('total_efectivo_usd');
            $table->decimal('arqueo_efectivo_usd', 12, 2)->nullable()->after('arqueo_efectivo_bs');
            $table->decimal('faltante_sobrante_bs', 12, 2)->nullable()->after('arqueo_efectivo_usd');
            $table->decimal('faltante_sobrante_usd', 12, 2)->nullable()->after('faltante_sobrante_bs');
            $table->json('desglose_metodos')->nullable()->after('faltante_sobrante_usd');
            $table->index(['taquilla_id', 'fecha_fin'], 'cierres_caja_taquilla_fecha_fin_index');
        });
    }

    public function down(): void
    {
        // MySQL: InnoDB reutiliza el índice compuesto para respaldar la FK de
        // taquilla_id (error 1553), por lo que hay que soltar la FK antes del
        // índice y re-crearla después para restaurar el esquema original.
        if (DB::connection()->getDriverName() === 'mysql') {
            Schema::table('cierres_caja', function (Blueprint $table) {
                $table->dropForeign(['taquilla_id']);
            });
        }

        Schema::table('cierres_caja', function (Blueprint $table) {
            $table->dropIndex('cierres_caja_taquilla_fecha_fin_index');
            $table->dropColumn([
                'arqueo_efectivo_bs', 'arqueo_efectivo_usd',
                'faltante_sobrante_bs', 'faltante_sobrante_usd',
                'desglose_metodos',
            ]);
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            Schema::table('cierres_caja', function (Blueprint $table) {
                $table->foreign('taquilla_id')->references('id')->on('taquillas')->onDelete('cascade');
            });
        }

        Schema::table('pagos', function (Blueprint $table) {
            $table->dropColumn('metodo_pago');
        });
    }
};
