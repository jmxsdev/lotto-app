<?php

namespace App\Plugins\Juegos;

use App\Plugins\Contracts\JuegoInterface;
use Illuminate\Validation\Rule;

class Animalitos implements JuegoInterface
{
    protected array $map = [
        'ballena'  => 0,
        'delfin'   => 0,
        'carnero'  => 1,
        'toro'     => 2,
        'ciempies' => 3,
        'alacran'  => 4,
        'leon'     => 5,
        'rana'     => 6,
        'perico'   => 7,
        'raton'    => 8,
        'aguila'   => 9,
        'tigre'    => 10,
        'gato'     => 11,
        'caballo'  => 12,
        'mono'     => 13,
        'paloma'   => 14,
        'zorro'    => 15,
        'oso'      => 16,
        'pavo'     => 17,
        'burro'    => 18,
        'chivo'    => 19,
        'cochino'  => 20,
        'gallo'    => 21,
        'camello'  => 22,
        'cobra'    => 23,
        'iguana'   => 24,
        'gallina'  => 25,
        'vaca'     => 26,
        'perro'    => 27,
        'zamuro'   => 28,
        'elefante' => 29,
        'caiman'   => 30,
        'lapa'     => 31,
        'ardilla'  => 32,
        'pescado'  => 33,
        'venado'   => 34,
        'jirafa'   => 35,
        'culebra'  => 36,
    ];

    protected array $animales;

    protected string $multiplicador = '30';

    public function __construct()
    {
        $this->animales = array_keys($this->map);
    }

    public function validarApuesta(array $data): bool
    {
        if (!isset($data['combinacion']['animal'])) {
            return false;
        }
        $animal = strtolower(trim($data['combinacion']['animal']));
        return in_array($animal, $this->animales);
    }

    public function calcularPremio(array $apuesta, array $resultados): array
    {
        $numerosGanadores = $resultados['numeros_ganadores'] ?? [];
        $animalGanador = is_array($numerosGanadores) ? ($numerosGanadores['nombre_animal'] ?? null) : null;
        $animalApostado = $apuesta['combinacion']['animal'] ?? null;

        if (!$animalGanador || !$animalApostado) {
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
            'descripcion' => 'Acierta el animal ganador y gana ' . $this->multiplicador . ' veces tu apuesta.',
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
            'combinacion.animal' => ['required', 'string', 'max:50', Rule::in($this->animales)],
            'combinacion.numero' => 'nullable|integer|min:0|max:36',
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
