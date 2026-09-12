<?php

namespace App\Services;

use App\Models\Juego;
use App\Models\JuegoOpcion;

/**
 * Genera el catálogo de juegos como arreglo JSON: contrato estable para el
 * front/taquilla (docs/juegos.json). La resolución de opciones usa EXACTAMENTE
 * la misma semántica que JuegoController::opciones: filas de juego_opciones si
 * existen, si no fallback al plugin vía JuegoPluginManager::getPlugin().
 */
class JuegoCatalogoService
{
    public function __construct(private JuegoPluginManager $plugins) {}

    /**
     * @return array{version: int, juegos: array<int, array<string, mixed>>}
     */
    public function generar(): array
    {
        $juegos = Juego::orderBy('id')->get();

        return [
            'version' => 1,
            'juegos' => $juegos->map(fn (Juego $juego) => [
                'id' => $juego->id,
                'slug' => $juego->slug,
                'nombre' => $juego->name,
                'tipo' => $juego->type,
                'premio_multiplo' => $juego->config['premio_multiplo'] ?? null,
                'horarios' => $this->obtenerHorarios($juego),
                'opciones' => $this->obtenerOpciones($juego),
            ])->values()->all(),
        ];
    }

    /**
     * Horarios normalizados a H:i (la columna TIME devuelve "08:00:00")
     * y ordenados ascendentemente. Fallback al plugin si no hay filas.
     *
     * @return array<int, string>
     */
    private function obtenerHorarios(Juego $juego): array
    {
        $horarios = $juego->horarios()
            ->pluck('hora')
            ->map(fn (?string $hora) => $this->normalizarHora($hora))
            ->filter()
            ->sort()
            ->values()
            ->all();

        if (empty($horarios)) {
            $plugin = $this->plugins->getPlugin($juego);
            if ($plugin) {
                $horarios = collect($plugin->obtenerHorarios())
                    ->map(fn (?string $hora) => $this->normalizarHora($hora))
                    ->filter()
                    ->sort()
                    ->values()
                    ->all();
            }
        }

        return $horarios;
    }

    /**
     * Opciones del juego con la misma semántica que JuegoController::opciones:
     * filas de juego_opciones si existen (orden sort_order -> numero); si no,
     * las del plugin tal como las devuelve. Siempre mapeadas a {numero,label,value}.
     *
     * @return array<int, array{numero: int|null, label: string, value: string}>
     */
    private function obtenerOpciones(Juego $juego): array
    {
        $opciones = $juego->opciones()->orderBy('numero')->get();

        if ($opciones->isEmpty()) {
            $plugin = $this->plugins->getPlugin($juego);
            if (! $plugin) {
                return [];
            }

            return array_map(
                fn (array $opcion) => $this->mapearOpcion($opcion),
                $plugin->obtenerOpciones()
            );
        }

        return $opciones
            ->map(fn (JuegoOpcion $opcion) => $this->mapearOpcion($opcion))
            ->values()
            ->all();
    }

    /**
     * @param  JuegoOpcion|array<string, mixed>  $opcion
     * @return array{numero: int|null, label: string, value: string}
     */
    private function mapearOpcion(JuegoOpcion|array $opcion): array
    {
        if ($opcion instanceof JuegoOpcion) {
            return [
                'numero' => $opcion->numero,
                'label' => $opcion->label,
                'value' => $opcion->value,
            ];
        }

        return [
            'numero' => $opcion['numero'] ?? null,
            'label' => $opcion['label'] ?? '',
            'value' => $opcion['value'] ?? '',
        ];
    }

    private function normalizarHora(?string $hora): ?string
    {
        if ($hora === null || trim($hora) === '') {
            return null;
        }

        $hora = trim($hora);

        if (preg_match('/^\d{2}:\d{2}/', $hora, $match) === 1) {
            return $match[0];
        }

        return $hora;
    }
}
