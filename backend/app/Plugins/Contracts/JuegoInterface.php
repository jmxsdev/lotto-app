<?php

namespace App\Plugins\Contracts;

interface JuegoInterface
{
    public function validarApuesta(array $data): bool;
    public function calcularPremio(array $apuesta, array $resultados): array;
    public function obtenerReglas(): array;
    public function obtenerOpciones(): array;
    public function obtenerHorarios(): array;
    public function obtenerModalidades(): array;
    public function obtenerMultiplicador(): float;
    public function getValidationRules(): array;
    public function getValidationMessages(): array;
}
