<?php

namespace App\Plugins\Juegos\Animalitos;

use App\Plugins\Juegos\Animalitos;
use App\Plugins\Scrapers\SelvaPlusScraper;
use App\Support\Texto;
use Illuminate\Support\Str;

/**
 * Forma de acierto de Selva Plus (primer plugin por juego del issue #63).
 *
 * La fuente oficial devuelve la LETRA del comodín en `result` ("A"/"B"), no un
 * animal: en ese sorteo no existe `nombre_animal`. La UI oficial resuelve la
 * letra a `Comodín A, Leoncito` / `Comodín B, Selva Plus` y ofrece las dos
 * figuras como opciones apostables (101 animalitos + 2 comodines).
 *
 * Por eso este plugin:
 * - liquida la apuesta al comodín cuando el resultado trae la letra;
 * - no paga apuestas a animales en un sorteo con comodín;
 * - no paga una apuesta al comodín contra un resultado numérico;
 * - limita las señales de comodín a A/B (no hereda las de número/Monje).
 */
class SelvaPlus extends Animalitos
{
    /**
     * En Selva no hay tripleta: la jugada de 3 selecciones no se vende.
     */
    public function validarApuesta(array $data, ?array $opciones = null): bool
    {
        if (! empty($data['combinacion']['selecciones'])) {
            return false;
        }

        return parent::validarApuesta($data, $opciones);
    }

    /**
     * Selva no tiene tripleta: cualquier intento multi-sorteo se rechaza en
     * la forma, incluso si una apuesta legacy llegara con 3 selecciones.
     *
     * @param  array<string, mixed>  $apuesta
     * @param  array<int, array<string, mixed>>  $resultados
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    public function evaluarMultiSorteo(array $apuesta, array $resultados): array
    {
        return ['coincide' => false, 'clave' => 'tripleta', 'meta' => []];
    }

    /**
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    public function evaluarAcierto(array $apuesta, array $resultados): array
    {
        $numeros = $this->numerosGanadores($resultados);
        $letra = strtoupper(trim((string) ($numeros['comodin'] ?? '')));

        if (! in_array($letra, ['A', 'B'], true)) {
            return parent::evaluarAcierto($apuesta, $resultados);
        }

        $letraLower = strtolower($letra);
        $clave = 'comodin-'.$letraLower;
        $nombre = $this->claveNormalizada($numeros['comodin_nombre'] ?? null);
        $apostado = $this->claveNormalizada($apuesta['combinacion']['animal'] ?? null);

        $esperados = array_values(array_unique(array_filter([
            $this->claveNormalizada($clave),                       // comodin a
            $this->claveNormalizada($letra),                       // a
            $nombre,                                               // leoncito
            trim($nombre.' comodin '.$letraLower),                 // leoncito comodin a
            trim('comodin '.$letraLower.' '.$nombre),              // comodin a leoncito
        ])));

        if ($apostado !== '' && in_array($apostado, $esperados, true)) {
            return [
                'coincide' => true,
                'clave' => 'base',
                'meta' => ['comodines' => [$clave]],
            ];
        }

        return ['coincide' => false, 'clave' => 'base', 'meta' => []];
    }

    /**
     * Selva solo tiene comodines de letra: no hereda las señales numéricas
     * (99/75) ni la palabra PATRONUS de otros juegos.
     *
     * @return array<int, string>
     */
    protected function comodinesDelResultado(array $numeros): array
    {
        $letra = strtoupper(trim((string) ($numeros['comodin'] ?? '')));

        return match ($letra) {
            'A' => ['comodin-a'],
            'B' => ['comodin-b'],
            default => [],
        };
    }

    /**
     * Fallback sin `juego_opciones`: 101 figuras del zoológico propio + los 2
     * comodines oficiales. El seeder sigue siendo la fuente en producción.
     *
     * @return array<int, array{label: string, value: string, numero: int|null}>
     */
    public function obtenerOpciones(): array
    {
        $opciones = [];

        foreach (SelvaPlusScraper::ZOOLOGICO as $clave => $nombre) {
            $opciones[] = [
                'label' => $nombre,
                'value' => Str::slug($nombre),
                'numero' => (int) $clave,
            ];
        }

        $opciones[] = [
            'label' => 'Leoncito (comodín A)',
            'value' => 'comodin-a',
            'numero' => null,
        ];
        $opciones[] = [
            'label' => 'Selva Plus (comodín B)',
            'value' => 'comodin-b',
            'numero' => null,
        ];

        return $opciones;
    }

    /**
     * Normaliza una clave para comparar el value canónico (`comodin-a`), la
     * letra (`A`), el nombre oficial (`Leoncito`) y el label
     * (`Leoncito (comodín A)`) sin depender de acentos, mayúsculas ni signos.
     */
    private function claveNormalizada(mixed $valor): string
    {
        $normalizado = Texto::normalizar(is_scalar($valor) ? (string) $valor : '');
        $compacto = preg_replace('/[^a-z0-9]+/', ' ', $normalizado) ?? '';

        return trim($compacto);
    }
}
