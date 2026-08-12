<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Migra datos de juegos.costo_minimo → juego_limites.limite_minimo
     * (a nivel banca, moneda='bs') y elimina la columna costo_minimo.
     */
    public function up(): void
    {
        // Migrar costo_minimo existente a juego_limites
        $bancaId = DB::table('bancas')->value('id');

        if ($bancaId) {
            $juegosConCosto = DB::table('juegos')
                ->whereNotNull('costo_minimo')
                ->get();

            foreach ($juegosConCosto as $juego) {
                DB::table('juego_limites')->insert([
                    'juego_id' => $juego->id,
                    'banca_id' => $bancaId,
                    'moneda' => 'bs',
                    'limite_minimo' => $juego->costo_minimo,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Eliminar columna costo_minimo de juegos
        Schema::table('juegos', function (Blueprint $table) {
            $table->dropColumn('costo_minimo');
        });
    }

    public function down(): void
    {
        // Re-crear columna costo_minimo
        Schema::table('juegos', function (Blueprint $table) {
            $table->decimal('costo_minimo', 10, 2)->nullable()->after('scraper_url');
        });

        // Restaurar datos desde juego_limites (solo moneda='bs')
        DB::table('juego_limites')
            ->where('moneda', 'bs')
            ->whereNotNull('limite_minimo')
            ->orderBy('id')
            ->each(function ($limite) {
                DB::table('juegos')
                    ->where('id', $limite->juego_id)
                    ->update(['costo_minimo' => $limite->limite_minimo]);
            });

        // Limpiar juego_limites (la tabla se elimina en la migración 000002)
    }
};
