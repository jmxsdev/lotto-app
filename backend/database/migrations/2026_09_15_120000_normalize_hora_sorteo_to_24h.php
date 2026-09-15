<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Normaliza `resultados.hora_sorteo` al formato 24h "H:i".
 *
 * Contexto: los scrapers legacy de la familia lottoactivo guardaban la hora
 * tal como llega del feed ("01:00 PM"), mientras el resto de los scrapers
 * guardan 24h ("13:00"). El formato mezclado rompía el ordenamiento del API
 * (orderBy de string) y la paginación del panel: juegos "desaparecían" de la
 * vista. Esta migración convierte las filas históricas con AM/PM a "H:i".
 *
 * Idempotente: solo toca valores que contienen AM/PM; reintentarla es no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('resultados')
            ->where('hora_sorteo', 'like', '%M')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $hora = $row->hora_sorteo;

                    if (! is_string($hora) || ! preg_match('/[AP]M/i', $hora)) {
                        continue;
                    }

                    try {
                        $normalizada = Carbon::parse(trim($hora), 'America/Caracas')->format('H:i');
                    } catch (Throwable) {
                        continue;
                    }

                    DB::table('resultados')
                        ->where('id', $row->id)
                        ->update(['hora_sorteo' => $normalizada]);
                }
            });
    }

    public function down(): void
    {
        // No reversible: el formato original (12h con AM/PM) no se puede
        // reconstruir de forma fiable sin conocer la convención de origen.
    }
};
