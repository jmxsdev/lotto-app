<?php

namespace App\Plugins\Contracts;

/**
 * Contrato de plugin de juego (D1/C, design §3.3).
 *
 * El plugin es un adaptador de FORMA: expone la clave canónica del acierto
 * (`evaluarAcierto`); NUNCA decide dinero — el multiplicador lo resuelve
 * PremiosEngine desde `config.premios` (REQ1).
 */
interface JuegoInterface
{
    /**
     * Valida la FORMA vendible de la jugada.
     *
     * `$premios` es el snapshot/config de `config.premios` del juego. Cuando
     * viaja, el plugin aplica el whitelist total de claves canónicas
     * (`modalidades` + producto base) y el catálogo de slots posicionales.
     * null = validación de forma pura (compatibilidad con call sites legacy).
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>|null  $opciones
     * @param  array<string, mixed>|null  $premios
     */
    public function validarApuesta(array $data, ?array $opciones = null, ?array $premios = null): bool;

    /**
     * Forma del acierto: qué apostó vs qué salió, en claves canónicas.
     *
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    public function evaluarAcierto(array $apuesta, array $resultados): array;

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
