<?php

namespace App\Plugins\Juegos;

use App\Plugins\Contracts\JuegoInterface;

/**
 * Adaptador de FORMA del juego de terminales (D1/C, design §3.3).
 *
 * N1 (REQ3): `evaluarAcierto` liquida contra la clave `numero` que persisten
 * los scrapers (el bug histórico buscaba `terminal` y nunca pagaba); se
 * conserva `terminal` solo como fallback defensivo. N9: comparación con
 * padding a 2 cifras ("7" ≡ "07"). El premio (60×, reglamento) lo decide
 * PremiosEngine desde `config.premios` (REQ1); el plugin no hardcodea dinero.
 */
class Terminales implements JuegoInterface
{
    protected string $multiplicador = '20';

    protected array $numerosValidos;

    public function __construct()
    {
        $this->numerosValidos = range(0, 99);
    }

    public function validarApuesta(array $data, ?array $opciones = null): bool
    {
        $numero = $data['combinacion']['numero'] ?? null;
        if ($numero === null) {
            return false;
        }
        $num = (int) $numero;

        return $num >= 0 && $num <= 99;
    }

    /**
     * Forma del acierto (design §3.3): compara el número apostado contra la
     * clave `numero` del resultado (fallback `terminal`), con padding a 2
     * cifras (N1/N9, REQ3).
     *
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    public function evaluarAcierto(array $apuesta, array $resultados): array
    {
        $numeroApostado = $apuesta['combinacion']['numero'] ?? null;
        $numerosGanadores = $this->numerosGanadores($resultados);

        $terminalGanador = $numerosGanadores['numero'] ?? $numerosGanadores['terminal'] ?? null;

        if ($numeroApostado === null || $terminalGanador === null) {
            return ['coincide' => false, 'clave' => 'terminal', 'meta' => []];
        }

        $apostado = str_pad((string) ((int) $numeroApostado), 2, '0', STR_PAD_LEFT);
        $ganador = str_pad((string) ((int) $terminalGanador), 2, '0', STR_PAD_LEFT);

        if ($apostado !== $ganador) {
            return ['coincide' => false, 'clave' => 'terminal', 'meta' => []];
        }

        return ['coincide' => true, 'clave' => 'terminal', 'meta' => []];
    }

    /**
     * Modalidad canónica para `premio_posible`: el acierto de 2 cifras es la
     * modalidad `terminal` (D9/§3.1); cae al base del juego en el engine.
     */
    public function modalidadDe(array $combinacion): string
    {
        return 'terminal';
    }

    private function numerosGanadores(array $resultados): array
    {
        $numeros = $resultados['numeros_ganadores'] ?? [];

        if (is_string($numeros)) {
            $numeros = json_decode($numeros, true) ?? [];
        }

        return is_array($numeros) ? $numeros : [];
    }

    public function calcularPremio(array $apuesta, array $resultados): array
    {
        $numeroApostado = $apuesta['combinacion']['numero'] ?? null;
        $numerosGanadores = $resultados['numeros_ganadores'] ?? [];

        if (is_string($numerosGanadores)) {
            $numerosGanadores = json_decode($numerosGanadores, true) ?? [];
        }

        $terminalGanador = is_array($numerosGanadores)
            ? ($numerosGanadores['terminal'] ?? null)
            : null;

        if ($terminalGanador === null || (string) $numeroApostado !== (string) $terminalGanador) {
            return ['premio_bs' => 0, 'premio_usd' => 0];
        }

        $amountBs = $apuesta['amount_bs'] ?? 0;
        $amountUsd = $apuesta['amount_usd'] ?? 0;

        return [
            'premio_bs' => $amountBs * (float) $this->multiplicador,
            'premio_usd' => $amountUsd * (float) $this->multiplicador,
        ];
    }

    public function obtenerReglas(): array
    {
        return [
            'descripcion' => 'Acierta el número de 2 cifras y gana '.$this->multiplicador.' veces tu apuesta.',
            'tipo' => 'numero',
            'modalidades' => $this->obtenerModalidades(),
            'multiplicador' => (float) $this->multiplicador,
            'rango_numeros' => '00-99',
        ];
    }

    public function obtenerOpciones(): array
    {
        $opciones = [];
        foreach (range(0, 99) as $num) {
            $opciones[] = [
                'label' => str_pad($num, 2, '0', STR_PAD_LEFT),
                'value' => (string) $num,
                'numero' => $num,
            ];
        }

        return $opciones;
    }

    public function obtenerHorarios(): array
    {
        return ['10:00', '12:00', '14:00', '16:00', '18:00', '20:00'];
    }

    public function obtenerModalidades(): array
    {
        return [
            [
                'code' => 'terminal',
                'label' => 'Terminal',
                'digitos' => 2,
                'requiere_signo' => false,
                'descripcion' => 'Número de 2 cifras (00-99)',
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
            'combinacion.numero' => 'required|integer|min:0|max:99',
        ];
    }

    public function getValidationMessages(): array
    {
        return [
            'combinacion.numero.required' => 'El número de terminal es obligatorio.',
            'combinacion.numero.min' => 'El número debe estar entre 00 y 99.',
            'combinacion.numero.max' => 'El número debe estar entre 00 y 99.',
        ];
    }
}
