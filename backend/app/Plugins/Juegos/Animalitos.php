<?php

namespace App\Plugins\Juegos;

use App\Plugins\Contracts\JuegoInterface;
use App\Support\Texto;

/**
 * Adaptador de FORMA del juego de animalitos (D1/C, design §3.3).
 *
 * El plugin NO decide dinero: expone la clave canónica del acierto
 * (`evaluarAcierto`) y la modalidad para `premio_posible` (`modalidadDe`);
 * el multiplicador lo resuelve PremiosEngine desde `config.premios` (REQ1).
 *
 * Comodines (REQ6): el plugin emite en `meta.comodines` las señales que trae
 * el resultado (flag MEGA, letra Selva A/B, figura 75/99, palabra PATRONUS).
 * Como el plugin no conoce el juego, emite el superset de claves por señal
 * (p. ej. figura 75 → 'patronus-75' y 'guacharo-75'); el engine filtra por
 * las comodines configuradas de ese juego (design §3.3 paso 4).
 */
class Animalitos implements JuegoInterface
{
    protected array $map = [
        'ballena' => 0,
        'delfin' => 0,
        'carnero' => 1,
        'toro' => 2,
        'ciempies' => 3,
        'alacran' => 4,
        'leon' => 5,
        'rana' => 6,
        'perico' => 7,
        'raton' => 8,
        'aguila' => 9,
        'tigre' => 10,
        'gato' => 11,
        'caballo' => 12,
        'mono' => 13,
        'paloma' => 14,
        'zorro' => 15,
        'oso' => 16,
        'pavo' => 17,
        'burro' => 18,
        'chivo' => 19,
        'cochino' => 20,
        'gallo' => 21,
        'camello' => 22,
        'cebra' => 23,
        'iguana' => 24,
        'gallina' => 25,
        'vaca' => 26,
        'perro' => 27,
        'zamuro' => 28,
        'elefante' => 29,
        'caiman' => 30,
        'lapa' => 31,
        'ardilla' => 32,
        'pescado' => 33,
        'venado' => 34,
        'jirafa' => 35,
        'culebra' => 36,
    ];

    protected array $animales;

    protected string $multiplicador = '30';

    public function __construct()
    {
        $this->animales = array_keys($this->map);
    }

    public function validarApuesta(array $data, ?array $opciones = null): bool
    {
        $animal = Texto::normalizar($data['combinacion']['animal'] ?? null);

        if ($animal === '') {
            return false;
        }

        if ($opciones !== null && ! empty($opciones)) {
            $nombres = array_map(fn ($o) => Texto::normalizar((string) ($o['label'] ?? '')), $opciones);
            $valores = array_map(fn ($o) => Texto::normalizar((string) ($o['value'] ?? '')), $opciones);

            return in_array($animal, $nombres, true) || in_array($animal, $valores, true);
        }

        return in_array($animal, $this->animales, true);
    }

    /**
     * Forma del acierto (design §3.3): compara el animal apostado contra el
     * del resultado normalizando acentos en ambos lados (H13/N10, REQ2).
     *
     * F2 (D8/§3.4): con 3 `selecciones[]` de animal del MISMO sorteo evalúa la
     * Tripleta (Cazalotón 200× / Loto Chaima 50×) contra las figuras del
     * resultado (`numeros_ganadores.figuras[]`); la Dupleta (2 animales, 2
     * sorteos) nunca se emite ni se liquida.
     *
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    public function evaluarAcierto(array $apuesta, array $resultados): array
    {
        $combinacion = $apuesta['combinacion'] ?? [];
        $numerosGanadores = $this->numerosGanadores($resultados);

        $selecciones = $combinacion['selecciones'] ?? [];
        if (count($selecciones) >= 3) {
            return $this->evaluarTripleta($selecciones, $numerosGanadores);
        }

        $animalGanador = $numerosGanadores['nombre_animal'] ?? null;
        $animalApostado = $combinacion['animal'] ?? null;

        if (! $animalGanador || ! $animalApostado) {
            return ['coincide' => false, 'clave' => 'base', 'meta' => []];
        }

        if (Texto::normalizar((string) $animalApostado) !== Texto::normalizar((string) $animalGanador)) {
            return ['coincide' => false, 'clave' => 'base', 'meta' => []];
        }

        return [
            'coincide' => true,
            'clave' => 'base',
            'meta' => ['comodines' => $this->comodinesDelResultado($numerosGanadores)],
        ];
    }

    /**
     * Modalidad canónica para `premio_posible` (D9/§3.1): el acierto simple de
     * animalitos es siempre la base; con 3 selecciones del mismo sorteo deriva
     * `tripleta` (F2/D8 §3.4).
     */
    public function modalidadDe(array $combinacion): string
    {
        $selecciones = $combinacion['selecciones'] ?? [];

        return count($selecciones) >= 3 ? 'tripleta' : 'base';
    }

    /**
     * Tripleta same-draw (F2/§3.2, §3.4): las 3 figuras apostadas deben estar
     * entre las figuras del sorteo (`numeros_ganadores.figuras[]`), con acentos
     * normalizados (REQ2). Clave canónica `tripleta`.
     *
     * @param  array<int, array<string, mixed>>  $selecciones
     * @param  array<string, mixed>  $numeros
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    private function evaluarTripleta(array $selecciones, array $numeros): array
    {
        $figuras = $numeros['figuras'] ?? [];

        if (! is_array($figuras) || count($figuras) < 3) {
            return ['coincide' => false, 'clave' => 'tripleta', 'meta' => []];
        }

        $nombresResultado = array_map(
            fn ($figura) => Texto::normalizar((string) ($figura['animal'] ?? $figura['nombre_animal'] ?? '')),
            $figuras
        );

        foreach ($selecciones as $seleccion) {
            $animal = Texto::normalizar((string) ($seleccion['animal'] ?? ''));

            if ($animal === '' || ! in_array($animal, $nombresResultado, true)) {
                return ['coincide' => false, 'clave' => 'tripleta', 'meta' => []];
            }
        }

        return ['coincide' => true, 'clave' => 'tripleta', 'meta' => []];
    }

    private function numerosGanadores(array $resultados): array
    {
        $numeros = $resultados['numeros_ganadores'] ?? [];

        if (is_string($numeros)) {
            $numeros = json_decode($numeros, true) ?? [];
        }

        return is_array($numeros) ? $numeros : [];
    }

    /**
     * Señales de comodines presentes en el resultado (REQ6/H1/H8b/H14).
     * Superset por diseño: el engine filtra por `config.premios.comodines`.
     *
     * @return array<int, string>
     */
    private function comodinesDelResultado(array $numeros): array
    {
        $comodines = [];

        if (in_array($numeros['comodin'] ?? null, [true, 'true', 1, '1'], true)) {
            $comodines[] = 'mega';
        }

        $letra = strtoupper(trim((string) ($numeros['comodin'] ?? '')));
        if ($letra === 'A') {
            $comodines[] = 'comodin-a';
        } elseif ($letra === 'B') {
            $comodines[] = 'comodin-b';
        }

        $numero = (int) ($numeros['numero'] ?? -1);
        if ($numero === 99) {
            $comodines[] = 'guacharito-99';
        } elseif ($numero === 75) {
            $comodines[] = 'guacharo-75';
            $comodines[] = 'patronus-75';
        }

        if (! empty($numeros['patronus'])) {
            $comodines[] = 'patronus-palabra';
        }

        return $comodines;
    }

    public function calcularPremio(array $apuesta, array $resultados): array
    {
        $numerosGanadores = $resultados['numeros_ganadores'] ?? [];
        $animalGanador = is_array($numerosGanadores) ? ($numerosGanadores['nombre_animal'] ?? null) : null;
        $animalApostado = $apuesta['combinacion']['animal'] ?? null;

        if (! $animalGanador || ! $animalApostado) {
            return ['premio_bs' => 0, 'premio_usd' => 0];
        }

        if (strtolower($animalApostado) === strtolower($animalGanador)) {
            $amountBs = $apuesta['amount_bs'] ?? 0;
            $amountUsd = $apuesta['amount_usd'] ?? 0;

            return [
                'premio_bs' => $amountBs * (float) $this->multiplicador,
                'premio_usd' => $amountUsd * (float) $this->multiplicador,
            ];
        }

        return ['premio_bs' => 0, 'premio_usd' => 0];
    }

    public function obtenerReglas(): array
    {
        return [
            'descripcion' => 'Acierta el animal ganador y gana '.$this->multiplicador.' veces tu apuesta.',
            'tipo' => 'animal',
            'animales_disponibles' => count($this->animales),
            'multiplicador' => (float) $this->multiplicador,
        ];
    }

    public function obtenerOpciones(): array
    {
        $opciones = [];
        foreach ($this->map as $animal => $numero) {
            $opciones[] = [
                'label' => ucfirst($animal),
                'value' => $animal,
                'numero' => $numero,
            ];
        }

        return $opciones;
    }

    public function obtenerHorarios(): array
    {
        return ['09:00', '10:00', '11:00', '12:00', '13:00', '14:00',
            '15:00', '16:00', '17:00', '18:00', '19:00'];
    }

    public function obtenerModalidades(): array
    {
        return [
            [
                'code' => 'animal',
                'label' => 'Animal',
                'digitos' => null,
                'requiere_signo' => false,
                'descripcion' => 'Seleccione un animal (0-36)',
                'opciones_url' => null,
            ],
        ];
    }

    public function obtenerMultiplicador(): float
    {
        return (float) $this->multiplicador;
    }

    public function getValidationRules(): array
    {
        return [
            'combinacion' => 'required|array|min:1',
            'combinacion.animal' => ['required', 'string', 'max:50'],
            'combinacion.numero' => 'nullable|integer|min:0|max:99',
        ];
    }

    public function getValidationMessages(): array
    {
        return [
            'combinacion.animal.in' => 'El animal seleccionado no es válido.',
        ];
    }

    public function obtenerAnimalPorNumero(int $numero): ?string
    {
        foreach ($this->map as $animal => $num) {
            if ($num === $numero) {
                return $animal;
            }
        }

        return null;
    }

    public function obtenerNumeroPorAnimal(string $animal): ?int
    {
        $animal = strtolower(trim($animal));

        return $this->map[$animal] ?? null;
    }
}
