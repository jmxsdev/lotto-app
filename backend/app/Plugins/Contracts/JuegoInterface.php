<?php

namespace App\Plugins\Contracts;

/**
 * Contrato de plugin de juego (D1/C, design §3.3).
 *
 * El plugin es un adaptador de FORMA: expone la clave canónica del acierto
 * (`evaluarAcierto`) y la modalidad para `premio_posible` (`modalidadDe`);
 * NUNCA decide dinero — el multiplicador lo resuelve PremiosEngine desde
 * `config.premios` (REQ1).
 */
interface JuegoInterface
{
    public function validarApuesta(array $data, ?array $opciones = null): bool;

    /**
     * Forma del acierto: qué apostó vs qué salió, en claves canónicas.
     *
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    public function evaluarAcierto(array $apuesta, array $resultados): array;

    /**
     * Clave canónica de la modalidad apostada (premio_posible, D9/§3.1).
     */
    public function modalidadDe(array $combinacion): string;

    /**
     * @deprecated Legacy pre-motor (F1a): el dinero ahora lo decide
     *             PremiosEngine vía JuegoPluginManager. Se conserva por los
     *             call sites transicionales (F1d/Fase 3 los migran).
     */
    public function calcularPremio(array $apuesta, array $resultados): array;

    public function obtenerReglas(): array;

    public function obtenerOpciones(): array;

    public function obtenerHorarios(): array;

    public function obtenerModalidades(): array;

    /**
     * @deprecated Legacy pre-motor (F1a): el multiplicador ahora sale de
     *             config.premios vía PremiosEngine (REQ1).
     */
    public function obtenerMultiplicador(): float;

    public function getValidationRules(): array;

    public function getValidationMessages(): array;
}
