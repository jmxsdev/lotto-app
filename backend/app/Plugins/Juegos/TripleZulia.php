<?php

namespace App\Plugins\Juegos;

use App\Plugins\Contracts\JuegoInterface;
use Illuminate\Validation\Rule;

class TripleZulia implements JuegoInterface
{
    protected array $signos = [
        'ARI', 'TAU', 'GEM', 'CAN', 'LEO', 'VIR',
        'LIB', 'ESC', 'SAG', 'CAP', 'ACU', 'PIS',
    ];

    protected array $signosLabels = [
        'ARI' => 'Aries',
        'TAU' => 'Tauro',
        'GEM' => 'Géminis',
        'CAN' => 'Cáncer',
        'LEO' => 'Leo',
        'VIR' => 'Virgo',
        'LIB' => 'Libra',
        'ESC' => 'Escorpio',
        'SAG' => 'Sagitario',
        'CAP' => 'Capricornio',
        'ACU' => 'Acuario',
        'PIS' => 'Piscis',
    ];

    protected string $multiplicador = '30';

    public function validarApuesta(array $data): bool
    {
        $tipo = $data['combinacion']['tipo'] ?? null;

        if ($tipo === 'triple_a' || $tipo === 'triple_b') {
            $numero = $data['combinacion']['numero'] ?? null;
            return $numero !== null && preg_match('/^\d{3}$/', $numero);
        }

        if ($tipo === 'triple_c') {
            $numero = $data['combinacion']['numero'] ?? null;
            $signo = $data['combinacion']['signo'] ?? null;
            return $numero !== null && preg_match('/^\d{3}$/', $numero)
                && $signo !== null && in_array(strtoupper($signo), $this->signos);
        }

        return false;
    }

    public function calcularPremio(array $apuesta, array $resultados): float|int
    {
        $tipoApostado = $apuesta['combinacion']['tipo'] ?? null;
        $numeroApostado = $apuesta['combinacion']['numero'] ?? null;
        $signoApostado = $apuesta['combinacion']['signo'] ?? null;

        $numerosGanadores = $resultados['numeros_ganadores'] ?? [];

        $coincideNumero = false;
        foreach (['triple_a', 'triple_b', 'triple_c'] as $tipo) {
            if (isset($numerosGanadores[$tipo]) && $numerosGanadores[$tipo] === $numeroApostado) {
                $coincideNumero = true;
                break;
            }
        }

        if (!$coincideNumero) {
            return 0;
        }

        if ($tipoApostado === 'triple_c' && $signoApostado) {
            $signoGanador = $numerosGanadores['signo'] ?? null;
            if (strtoupper($signoApostado) !== strtoupper($signoGanador ?? '')) {
                return 0;
            }
        }

        $monto = $apuesta['monto'] ?? $apuesta['total_bs_equivalent'] ?? 0;
        return $monto * $this->multiplicador;
    }

    public function obtenerReglas(): array
    {
        return [
            'descripcion' => 'Acierta el número de 3 cifras y gana ' . $this->multiplicador . ' veces tu apuesta.',
            'tipo' => 'numero',
            'modalidades' => [
                'triple_a' => 'Número de 3 cifras',
                'triple_b' => 'Número de 3 cifras',
                'triple_c' => 'Número de 3 cifras + signo zodiacal',
            ],
            'multiplicador' => (float) $this->multiplicador,
            'rango_numeros' => '000-999',
        ];
    }

    public function obtenerOpciones(): array
    {
        $opciones = [];
        foreach ($this->signos as $sigla) {
            $opciones[] = [
                'label' => $this->signosLabels[$sigla],
                'value' => $sigla,
                'numero' => null,
                'metadata' => ['tipo' => 'signo'],
            ];
        }
        return $opciones;
    }

    public function obtenerMultiplicador(): float
    {
        return (float) $this->multiplicador;
    }

    public function getValidationRules(): array
    {
        return [
            'combinacion' => 'required|array|min:1',
            'combinacion.tipo' => ['required', Rule::in(['triple_a', 'triple_b', 'triple_c'])],
            'combinacion.numero' => 'required|string|size:3',
            'combinacion.signo' => 'required_if:combinacion.tipo,triple_c|nullable|string|max:3',
        ];
    }

    public function getValidationMessages(): array
    {
        return [
            'combinacion.tipo.in' => 'La modalidad debe ser triple_a, triple_b o triple_c.',
            'combinacion.numero.size' => 'El número debe tener exactamente 3 dígitos.',
            'combinacion.signo.required_if' => 'El signo zodiacal es obligatorio para Triple C.',
        ];
    }
}
