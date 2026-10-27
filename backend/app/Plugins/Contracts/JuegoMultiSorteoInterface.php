<?php

namespace App\Plugins\Contracts;

/**
 * Capacidad opcional para jugadas que dependen de más de un resultado del día
 * (p. ej. la tripleta multi-sorteo de animalitos).
 *
 * No se agrega a `JuegoInterface` para no romper los plugins de un solo
 * resultado (`Tripletas`, `Terminales`) ni sus call sites. El manager consulta
 * esta interfaz solo cuando el settlement lo necesita.
 */
interface JuegoMultiSorteoInterface
{
    /**
     * Evalúa una jugada contra un conjunto de resultados del mismo juego/día.
     *
     * @param  array<string, mixed>  $apuesta
     * @param  array<int, array{id: int, hora_sorteo: string|null, numeros_ganadores: array<string, mixed>|string|null}>  $resultados
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    public function evaluarMultiSorteo(array $apuesta, array $resultados): array;
}
