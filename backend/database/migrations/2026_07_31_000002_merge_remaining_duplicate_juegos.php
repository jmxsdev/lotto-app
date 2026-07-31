<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Merge "Terminal Trío" → "Terminal Activo"
        $terminalActivo = DB::table('juegos')->where('slug', 'terminal-activo')->first();
        if ($terminalActivo) {
            $dups = DB::table('juegos')->where('name', 'Terminal Trío')->get();
            foreach ($dups as $dup) {
                $this->mergeJuego($dup->id, $terminalActivo->id);
            }
        }

        // Merge "Lotto Activo 2 (Monje Millonario)" → "Monje Millonario"
        $monje = DB::table('juegos')->where('slug', 'monje-millonario')->first();
        if ($monje) {
            $dups = DB::table('juegos')->where('slug', '!=', 'monje-millonario')
                ->where('name', 'like', '%Monje Millonario%')->get();
            foreach ($dups as $dup) {
                $this->mergeJuego($dup->id, $monje->id);
            }
        }
    }

    protected function mergeJuego(int $fromId, int $toId): void
    {
        // Mover apuestas
        DB::table('apuestas')->where('juego_id', $fromId)->update(['juego_id' => $toId]);

        // Mover resultados — eliminar los que harían conflicto (mismo juego+fecha+hora)
        $targetResults = DB::table('resultados')->where('juego_id', $toId)
            ->select('fecha_sorteo', 'hora_sorteo')->get();
        foreach ($targetResults as $tr) {
            DB::table('resultados')->where('juego_id', $fromId)
                ->whereDate('fecha_sorteo', $tr->fecha_sorteo)
                ->where('hora_sorteo', $tr->hora_sorteo)
                ->delete();
        }
        // Mover los que quedan (sin conflicto)
        DB::table('resultados')->where('juego_id', $fromId)->update(['juego_id' => $toId]);

        // Eliminar registros relacionados
        DB::table('juego_horarios')->where('juego_id', $fromId)->delete();
        DB::table('juego_opciones')->where('juego_id', $fromId)->delete();
        DB::table('plugin_juegos')->where('juego_id', $fromId)->delete();
        DB::table('juegos')->where('id', $fromId)->delete();
    }

    public function down(): void
    {
        // No reversible
    }
};
