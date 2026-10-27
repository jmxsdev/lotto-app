<?php

namespace App\Plugins\Juegos;

use App\Plugins\Contracts\JuegoInterface;
use App\Plugins\Contracts\JuegoMultiSorteoInterface;
use App\Support\Texto;
use Illuminate\Validation\Rule;

/**
 * Adaptador de FORMA del juego de animalitos (D1/C, design §3.3).
 *
 * El plugin NO decide dinero: expone la clave canónica del acierto
 * (`evaluarAcierto`); el multiplicador lo resuelve PremiosEngine desde
 * `config.premios` (REQ1).
 *
 * Comodines (REQ6): el plugin emite en `meta.comodines` las señales que trae
 * el resultado (flag MEGA, letra Selva A/B, figura 75/99, palabra PATRONUS).
 * Como el plugin no conoce el juego, emite el superset de claves por señal
 * (p. ej. figura 75 → 'patronus-75' y 'guacharo-75'); el engine filtra por
 * las comodines configuradas de ese juego (design §3.3 paso 4).
 */
class Animalitos implements JuegoInterface, JuegoMultiSorteoInterface
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
        $combinacion = $data['combinacion'] ?? [];

        // Tripleta multi-sorteo (Cazalotón / Loto Chaima):
        // `{modalidad:'tripleta', selecciones:[{animal}×3]}`. Las 3 figuras
        // deben ser distintas y se liquidan contra sorteos distintos del día.
        $selecciones = $combinacion['selecciones'] ?? [];
        if (! empty($selecciones)) {
            return $this->seleccionesTripletaValidas($selecciones, $opciones);
        }

        $animal = Texto::normalizar($combinacion['animal'] ?? null);

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
     * Tripleta multi-sorteo: EXACTAMENTE 3 selecciones de animal DISTINTAS.
     * Cada animal se valida igual que el simple (label o valor, acentos
     * normalizados — REQ2/H13). El settlement exige que las 3 aparezcan en
     * sorteos distintos del mismo juego/día.
     *
     * @param  array<int, array<string, mixed>>  $selecciones
     */
    private function seleccionesTripletaValidas(array $selecciones, ?array $opciones): bool
    {
        if (count($selecciones) !== 3) {
            return false;
        }

        $normalizados = [];

        foreach ($selecciones as $seleccion) {
            $animal = Texto::normalizar((string) ($seleccion['animal'] ?? ''));

            if ($animal === '') {
                return false;
            }

            if ($opciones !== null && ! empty($opciones)) {
                $nombres = array_map(fn ($o) => Texto::normalizar((string) ($o['label'] ?? '')), $opciones);
                $valores = array_map(fn ($o) => Texto::normalizar((string) ($o['value'] ?? '')), $opciones);

                if (! in_array($animal, $nombres, true) && ! in_array($animal, $valores, true)) {
                    return false;
                }
            } elseif (! in_array($animal, $this->animales, true)) {
                return false;
            }

            $normalizados[] = $animal;
        }

        return count(array_unique($normalizados)) === 3;
    }

    /**
     * Evalúa la tripleta contra los resultados elegibles del juego/día.
     *
     * Matching 1:1: cada selección se asigna a un resultado distinto; un mismo
     * sorteo no puede satisfacer dos figuras. Todo o nada: solo devuelve
     * `coincide=true` cuando las 3 quedan asignadas.
     *
     * @param  array<string, mixed>  $apuesta
     * @param  array<int, array{id: int, hora_sorteo: string|null, numeros_ganadores: array<string, mixed>|string|null}>  $resultados
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    public function evaluarMultiSorteo(array $apuesta, array $resultados): array
    {
        $selecciones = $apuesta['combinacion']['selecciones'] ?? [];

        if (count($selecciones) !== 3) {
            return ['coincide' => false, 'clave' => 'tripleta', 'meta' => []];
        }

        $buscados = [];

        foreach ($selecciones as $seleccion) {
            $animal = Texto::normalizar((string) ($seleccion['animal'] ?? ''));

            if ($animal === '') {
                return ['coincide' => false, 'clave' => 'tripleta', 'meta' => []];
            }

            $buscados[] = $animal;
        }

        if (count(array_unique($buscados)) !== 3) {
            return ['coincide' => false, 'clave' => 'tripleta', 'meta' => []];
        }

        // Resultados elegibles: [{id, nombre_animal}] normalizados.
        $candidatos = [];

        foreach ($resultados as $resultado) {
            $numeros = $resultado['numeros_ganadores'] ?? [];

            if (is_string($numeros)) {
                $numeros = json_decode($numeros, true) ?? [];
            }

            if (! is_array($numeros)) {
                continue;
            }

            $nombre = Texto::normalizar($numeros['nombre_animal'] ?? null);

            if ($nombre === '') {
                continue;
            }

            $candidatos[] = [
                'id' => (int) ($resultado['id'] ?? 0),
                'nombre' => $nombre,
                // Un mismo sorteo (misma hora) no puede satisfacer dos
                // selecciones. Si no hay hora, el ID es la identidad.
                'clave' => (string) ($resultado['hora_sorteo'] ?? '') !== ''
                    ? (string) $resultado['hora_sorteo']
                    : 'id:'.(int) ($resultado['id'] ?? 0),
            ];
        }

        $asignados = $this->asignarTripleta($buscados, $candidatos, 0, []);

        if ($asignados === null) {
            return ['coincide' => false, 'clave' => 'tripleta', 'meta' => []];
        }

        return [
            'coincide' => true,
            'clave' => 'tripleta',
            'meta' => ['resultado_ids' => $asignados],
        ];
    }

    /**
     * Backtracking simple para 3 selecciones: asigna cada una a un sorteo
     * distinto no usado. `$usados` guarda la clave del sorteo (hora o ID), no
     * la posición, para que dos figuras no se satisfagan con el mismo sorteo.
     *
     * @param  array<int, string>  $buscados
     * @param  array<int, array{id: int, nombre: string, clave: string}>  $candidatos
     * @param  array<int, string>  $usados
     * @return array<int, int>|null
     */
    private function asignarTripleta(array $buscados, array $candidatos, int $indice, array $usados): ?array
    {
        if ($indice >= count($buscados)) {
            return [];
        }

        foreach ($candidatos as $candidato) {
            if (in_array($candidato['clave'], $usados, true)) {
                continue;
            }

            if ($candidato['nombre'] !== $buscados[$indice]) {
                continue;
            }

            $resto = $this->asignarTripleta(
                $buscados,
                $candidatos,
                $indice + 1,
                [...$usados, $candidato['clave']]
            );

            if ($resto !== null) {
                return [$candidato['id'], ...$resto];
            }
        }

        return null;
    }

    /**
     * Forma del acierto (design §3.3): compara el animal apostado contra el
     * del resultado normalizando acentos en ambos lados (H13/N10, REQ2).
     *
     * Con 3 `selecciones[]` de animal NO se liquida aquí: la tripleta es
     * multi-sorteo y se evalúa en `evaluarMultiSorteo()` contra los resultados
     * del día. La Dupleta (2 animales) nunca se emite ni se liquida.
     *
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    public function evaluarAcierto(array $apuesta, array $resultados): array
    {
        $combinacion = $apuesta['combinacion'] ?? [];
        $numerosGanadores = $this->numerosGanadores($resultados);

        $selecciones = $combinacion['selecciones'] ?? [];
        if (count($selecciones) >= 3) {
            // La tripleta es multi-sorteo: se evalúa en `evaluarMultiSorteo`
            // contra los resultados del día, nunca contra un solo resultado.
            return ['coincide' => false, 'clave' => 'tripleta', 'meta' => []];
        }

        // Selva Plus: el sorteo con comodín A/B no trae `nombre_animal`; la
        // letra identifica la figura apostable. Solo gana la apuesta al
        // comodín; una apuesta a un animal pierde.
        $letra = strtoupper(trim((string) ($numerosGanadores['comodin'] ?? '')));
        if (in_array($letra, ['A', 'B'], true)) {
            return $this->evaluarComodinLetra($combinacion, $numerosGanadores, $letra);
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
     * Comodín de letra (Selva Plus A/B): compara la apuesta contra la clave
     * canónica (`comodin-a`/`comodin-b`), la letra, el nombre oficial y el
     * label de la UI (`Leoncito (comodín A)`).
     *
     * @param  array<string, mixed>  $combinacion
     * @param  array<string, mixed>  $numeros
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    private function evaluarComodinLetra(array $combinacion, array $numeros, string $letra): array
    {
        $letraLower = strtolower($letra);
        $clave = 'comodin-'.$letraLower;
        $nombre = $this->claveComodinNormalizada($numeros['comodin_nombre'] ?? null);
        $apostado = $this->claveComodinNormalizada($combinacion['animal'] ?? null);

        $esperados = array_values(array_unique(array_filter([
            $this->claveComodinNormalizada($clave),              // comodin a
            $this->claveComodinNormalizada($letra),              // a
            $nombre,                                              // leoncito
            trim($nombre.' comodin '.$letraLower),                // leoncito comodin a
            trim('comodin '.$letraLower.' '.$nombre),             // comodin a leoncito
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
     * Normaliza una clave de comodín sin acentos, mayúsculas ni signos.
     */
    private function claveComodinNormalizada(mixed $valor): string
    {
        $normalizado = Texto::normalizar(is_scalar($valor) ? (string) $valor : '');
        $compacto = preg_replace('/[^a-z0-9]+/', ' ', $normalizado) ?? '';

        return trim($compacto);
    }

    protected function numerosGanadores(array $resultados): array
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
    protected function comodinesDelResultado(array $numeros): array
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
            'combinacion.animal' => [
                'nullable',
                'string',
                'max:50',
                // S6: con `selecciones[]` (tripleta) no viaja `animal` raíz;
                // los animales van en `selecciones[].animal` (contrato §2.2).
                Rule::requiredIf(fn () => empty(request('combinacion.selecciones'))),
            ],
            'combinacion.numero' => 'nullable|integer|min:0|max:99',
            // S6: Tripleta same-draw — exactamente 3 animales.
            'combinacion.selecciones' => ['nullable', 'array', 'size:3'],
            'combinacion.selecciones.*.animal' => ['required', 'string', 'max:50'],
        ];
    }

    public function getValidationMessages(): array
    {
        return [
            'combinacion.animal.in' => 'El animal seleccionado no es válido.',
            'combinacion.selecciones.size' => 'La tripleta debe llevar exactamente 3 animales.',
            'combinacion.selecciones.*.animal.required' => 'Cada selección de la tripleta debe indicar un animal.',
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
