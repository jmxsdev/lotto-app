<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla comision_defaults (D1): default global de comisión por
 * moneda (bs/usd), aplicable a todos los juegos cuando ningún nivel de la
 * jerarquía define porcentaje_pago.
 *
 * Dos filas sembradas (una por moneda); `porcentaje_pago` nullable:
 * NULL = default global no definido (la tasa efectiva cae a 0.00).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comision_defaults', function (Blueprint $table) {
            $table->id();
            $table->enum('moneda', ['bs', 'usd'])->unique();
            $table->decimal('porcentaje_pago', 5, 2)->nullable();
            $table->timestamps();
        });

        DB::table('comision_defaults')->insert([
            ['moneda' => 'bs', 'porcentaje_pago' => null, 'created_at' => now(), 'updated_at' => now()],
            ['moneda' => 'usd', 'porcentaje_pago' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('comision_defaults');
    }
};
