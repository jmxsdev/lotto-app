<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $map = [
            8 => 1,   // Lotto Activo duplicado → original id=1
            9 => 4,   // Lotto Activo RD Internacional duplicado → original id=4
            10 => 5,  // Lotto Activo Rep Dom duplicado → original id=5
            11 => 6,  // Monje Millonario duplicado → original id=6
        ];

        foreach ($map as $dupId => $originalId) {
            DB::table('apuestas')->where('juego_id', $dupId)->update(['juego_id' => $originalId]);
            DB::table('resultados')->where('juego_id', $dupId)->update(['juego_id' => $originalId]);
            DB::table('juego_horarios')->where('juego_id', $dupId)->delete();
            DB::table('juego_opciones')->where('juego_id', $dupId)->delete();
            DB::table('juegos')->where('id', $dupId)->delete();
        }
    }

    public function down(): void
    {
        // No reversible — los duplicados no deben volver a crearse
    }
};
