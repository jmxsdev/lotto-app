<?php

namespace App\Services;

use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\Resultado;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reconciliación de sorteos esperados-faltantes para el sweep.
 *
 * Cruza `juego_horarios` (sorteos esperados) contra `resultados` del día y
 * devuelve SOLO los juegos con sorteos esperados-faltantes:
 * - El sorteo ya pasó la gracia (`hora <= now - gracia`).
 * - El sorteo está dentro de la ventana de recuperación
 *   (`hora >= now - (gracia + ventana)`): huecos más viejos se consideran gaps
 *   permanentes de la fuente y los visibiliza la alerta, no el sweep.
 * - No existe fila `(juego, fecha, hora)` en `resultados`.
 */
class DrawReconciliationService
{
    /**
     * Juegos con sorteos esperados-faltantes para la fecha dada.
     *
     * @return Collection<int, array{juego: Juego, horas: array<int, string>}>
     */
    public function missingByJuego(string $fecha, int $graceMin, int $windowMin): Collection
    {
        $ahora = now();
        $limiteGracia = $ahora->copy()->subMinutes($graceMin);
        $limiteVentana = $ahora->copy()->subMinutes($graceMin + $windowMin);

        $horarios = JuegoHorario::query()
            ->where('active', true)
            ->whereHas('juego', function ($query) {
                $query->where('requires_scraper', true)->where('active', true);
            })
            ->get();

        $juegos = Juego::whereIn('id', $horarios->pluck('juego_id')->unique())
            ->get()
            ->keyBy('id');

        // Índice O(1) de sorteos ya persistidos: "juego_id|H:i" -> true.
        $persistidos = Resultado::query()
            ->whereDate('fecha_sorteo', $fecha)
            ->whereIn('juego_id', $horarios->pluck('juego_id')->unique())
            ->get(['juego_id', 'hora_sorteo'])
            ->map(fn (Resultado $resultado) => $resultado->juego_id.'|'.$resultado->hora_sorteo)
            ->flip();

        $porJuego = [];

        foreach ($horarios as $horario) {
            $juego = $juegos->get($horario->juego_id);

            if ($juego === null) {
                continue;
            }

            $hora = substr($horario->hora, 0, 5);
            $sorteo = Carbon::parse($fecha.' '.$hora);

            if ($sorteo->gt($limiteGracia)) {
                continue; // aún dentro de la gracia: la fuente puede publicar tarde
            }
            if ($sorteo->lt($limiteVentana)) {
                continue; // fuera de la ventana: gap permanente, lo visibiliza la alerta
            }
            if ($persistidos->has($juego->id.'|'.$hora)) {
                continue; // ya persistido: día sano
            }

            $porJuego[$juego->id]['juego'] = $juego;
            $porJuego[$juego->id]['horas'][] = $hora;
        }

        return collect($porJuego)->values();
    }
}
