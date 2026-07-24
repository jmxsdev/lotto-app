<?php

namespace App\Plugins\Contracts;

interface JuegoInterface
{
    /**
     * Validar que la apuesta cumpla con las reglas del juego.
     * @param array $data Datos de la apuesta (ej. combinacion, monto, etc.)
     * @return bool
     */
    public function validarApuesta(array $data): bool;

    /**
     * Calcular el premio basado en la apuesta y los resultados.
     * @param array $apuesta Datos de la apuesta.
     * @param array $resultados Datos del sorteo (ej. números ganadores).
     * @return float|int Monto del premio.
     */
    public function calcularPremio(array $apuesta, array $resultados): float|int;

    /**
     * Obtener las reglas del juego (para mostrar en la interfaz).
     * @return array
     */
    public function obtenerReglas(): array;
}
