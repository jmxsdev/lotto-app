<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea la tabla juego_limites con herencia jerárquica
     * (banca → grupo → taquilla) y restricción única por entidad.
     *
     * Idempotente: si la tabla ya existe (deploy interrumpido en un DDL no
     * transaccional, o BD pre-provisionada sin registro de migración), se
     * omite la creación para que `migrate --force` no falle con 42S01.
     */
    public function up(): void
    {
        if (Schema::hasTable('juego_limites')) {
            return;
        }

        Schema::create('juego_limites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('juego_id')->constrained('juegos')->onDelete('cascade');
            $table->foreignId('banca_id')->constrained('bancas')->onDelete('cascade');
            $table->foreignId('grupo_id')->nullable()->constrained('grupos')->onDelete('cascade');
            $table->foreignId('taquilla_id')->nullable()->constrained('taquillas')->onDelete('cascade');
            $table->enum('moneda', ['bs', 'usd']);
            $table->decimal('limite_minimo', 12, 2)->nullable();
            $table->decimal('limite_maximo', 12, 2)->nullable();
            $table->decimal('porcentaje_pago', 5, 2)->nullable();
            $table->decimal('participacion', 10, 2)->nullable();
            $table->tinyInteger('fraccion')->default(0);
            $table->integer('limite_tiempo')->nullable()->comment('minutes');
            $table->timestamps();
        });

        // Índice único con COALESCE para permitir múltiples NULL en columnas
        // de la jerarquía sin violar la restricción de unicidad.
        DB::statement(
            'CREATE UNIQUE INDEX idx_jl_entity_level ON juego_limites'
            . ' (juego_id, moneda, banca_id, (COALESCE(grupo_id, 4294967295)), (COALESCE(taquilla_id, 4294967295)))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('juego_limites');
    }
};
