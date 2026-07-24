<?php

namespace App\Plugins\Juegos;

use App\Plugins\Contracts\JuegoInterface;

class Animalitos implements JuegoInterface
{
    /**
     * Mapeo de animal a número (según Lotto Activo).
     */
    protected $map = [
        'delfin'     => 0,
        'ballena'    => 0,  // Nota: 0 y 00 son ambos delfín/ballena según la lotería, ajusta si tu lotería distingue entre 0 y 00
        'carnero'    => 1,
        'toro'       => 2,
        'ciempies'   => 3,
        'alacran'    => 4,
        'leon'       => 5,
        'caiman'     => 6,
        'tigre'      => 7,
        'raton'      => 8,
        'aguila'     => 9,
        'burro'      => 10,
        'gato'       => 11,
        'loro'       => 12,
        'mono'       => 13,
        'paloma'     => 14,
        'zorro'      => 15,
        'lapa'       => 16,
        'venado'     => 17,
        'gallo'      => 18,
        'pavo'       => 19,
        'cochino'    => 20,
        'gallina'    => 21,
        'camello'    => 22,
        'cebra'      => 23,
        'iguana'     => 24,
        'vaca'       => 25,
        'perico'     => 26,
        'perro'      => 27,
        'zamuro'     => 28,
        'elefante'   => 29,
        'pavo_real'  => 30,
        'pescado'    => 31,
        'ardilla'    => 32,
        'mariposa'   => 33,
        'abeja'      => 34,
        'jirafa'     => 35,
        'conejo'     => 36,
    ];

    /**
     * Lista de animales válidos (para validación rápida).
     */
    protected $animales;

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

    public function calcularPremio(array $apuesta, array $resultados): float|int
    {
        $animalGanador = $resultados['animal_ganador'] ?? null;
        $animalApostado = $apuesta['combinacion']['animal'] ?? null;

        if (!$animalGanador || !$animalApostado) {
            return 0;
        }

        if (strtolower($animalApostado) === strtolower($animalGanador)) {
            $monto = $apuesta['monto'] ?? 0;
            return $monto * 30; // x30 como ejemplo, ajusta según reglas
        }

        return 0;
    }

    /**
     * Obtener el animal correspondiente a un número.
     */
    public function obtenerAnimalPorNumero(int $numero): ?string
    {
        // Buscar el animal cuyo número coincida
        foreach ($this->map as $animal => $num) {
            if ($num === $numero) {
                return $animal;
            }
        }
        return null;
    }

    /**
     * Obtener el número correspondiente a un animal.
     */
    public function obtenerNumeroPorAnimal(string $animal): ?int
    {
        $animal = strtolower(trim($animal));
        return $this->map[$animal] ?? null;
    }

    public function obtenerReglas(): array
    {
        return [
            'descripcion' => 'Acierta el animal ganador y gana 30 veces tu apuesta.',
            'animales' => $this->animales,
            'map' => $this->map,
        ];
    }
}
