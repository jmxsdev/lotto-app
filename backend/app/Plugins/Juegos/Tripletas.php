<?php

namespace App\Plugins\Juegos;

use App\Plugins\Contracts\JuegoInterface;
use App\Support\Texto;
use Illuminate\Validation\Rule;

/**
 * Adaptador de FORMA del juego de tripletas (D1/C, design §3.3).
 *
 * REQ5/N3: la tripleta paga SOLO si el número acertado está en el tipo
 * apostado (triple_a contra triple_a, nunca contra triple_b). REQ4/N2: el
 * signo se acepta como LABEL ("Escorpio") o SIGLA ("ESC"), normalizado en
 * validación y liquidación. N9: comparación con padding a 3 cifras. El
 * multiplicador lo decide PremiosEngine desde `config.premios` (REQ1).
 */
class Tripletas implements JuegoInterface
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

    public function validarApuesta(array $data, ?array $opciones = null): bool
    {
        $tipo = $data['combinacion']['tipo'] ?? null;

        if ($tipo === 'triple_a' || $tipo === 'triple_b') {
            $numero = $data['combinacion']['numero'] ?? null;

            return $numero !== null && preg_match('/^\d{3}$/', (string) $numero);
        }

        if ($tipo === 'triple_c') {
            $numero = $data['combinacion']['numero'] ?? null;
            $signo = $data['combinacion']['signo'] ?? null;

            return $numero !== null && preg_match('/^\d{3}$/', (string) $numero)
                && $this->signoValido($signo);
        }

        return false;
    }

    /**
     * Forma del acierto (design §3.3): compara el número apostado contra el
     * resultado del MISMO tipo apostado (REQ5/N3) con padding a 3 cifras
     * (N9); para triple_c exige además el signo (label o sigla, REQ4/N2).
     *
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    public function evaluarAcierto(array $apuesta, array $resultados): array
    {
        $tipoApostado = $apuesta['combinacion']['tipo'] ?? null;
        $numeroApostado = $apuesta['combinacion']['numero'] ?? null;
        $signoApostado = $apuesta['combinacion']['signo'] ?? null;
        $numerosGanadores = $this->numerosGanadores($resultados);

        if (! in_array($tipoApostado, ['triple_a', 'triple_b', 'triple_c'], true)) {
            return ['coincide' => false, 'clave' => 'base', 'meta' => []];
        }

        // REQ5: solo el tipo apostado; un número en otro tipo NO paga.
        $numeroGanador = $numerosGanadores[$tipoApostado] ?? null;

        if ($numeroApostado === null || $numeroGanador === null) {
            return ['coincide' => false, 'clave' => 'base', 'meta' => []];
        }

        $apostado = str_pad((string) $numeroApostado, 3, '0', STR_PAD_LEFT);
        $ganador = str_pad((string) $numeroGanador, 3, '0', STR_PAD_LEFT);

        if ($apostado !== $ganador) {
            return ['coincide' => false, 'clave' => 'base', 'meta' => []];
        }

        if ($tipoApostado === 'triple_c') {
            $signoGanador = $numerosGanadores['signo'] ?? null;

            if (! $signoGanador || $this->normalizarSigno($signoApostado) !== $this->normalizarSigno($signoGanador)) {
                return ['coincide' => false, 'clave' => 'base', 'meta' => []];
            }

            return ['coincide' => true, 'clave' => 'signo_triple', 'meta' => []];
        }

        return ['coincide' => true, 'clave' => 'base', 'meta' => []];
    }

    /**
     * Modalidad canónica para `premio_posible`: triple simple → base;
     * triple + signo → signo_triple (D9/§3.1).
     */
    public function modalidadDe(array $combinacion): string
    {
        return ($combinacion['tipo'] ?? null) === 'triple_c' ? 'signo_triple' : 'base';
    }

    private function numerosGanadores(array $resultados): array
    {
        $numeros = $resultados['numeros_ganadores'] ?? [];

        if (is_string($numeros)) {
            $numeros = json_decode($numeros, true) ?? [];
        }

        return is_array($numeros) ? $numeros : [];
    }

    /**
     * El signo es válido como SIGLA ("ESC") o como LABEL ("Escorpio"),
     * insensible a acentos y mayúsculas (REQ4/N2).
     */
    private function signoValido(?string $signo): bool
    {
        if ($signo === null || trim($signo) === '') {
            return false;
        }

        return in_array(strtoupper(trim($signo)), $this->signos, true)
            || in_array(Texto::normalizar($signo), $this->labelsNormalizados(), true);
    }

    /**
     * Normaliza un signo a su SIGLA canónica: acepta label ("Escorpio") o
     * sigla ("ESC") de entrada y devuelve "ESC" en ambos casos.
     */
    private function normalizarSigno(?string $signo): string
    {
        if ($signo === null || trim($signo) === '') {
            return '';
        }

        $sigla = strtoupper(trim($signo));

        if (in_array($sigla, $this->signos, true)) {
            return $sigla;
        }

        $porLabel = array_flip($this->labelsNormalizados());

        return $porLabel[Texto::normalizar($signo)] ?? $sigla;
    }

    /**
     * @return array<int, string> Labels normalizados ("escorpio", "géminis"→"geminis", ...)
     */
    private function labelsNormalizados(): array
    {
        return array_map(fn ($label) => Texto::normalizar($label), $this->signosLabels);
    }

    public function calcularPremio(array $apuesta, array $resultados): array
    {
        $tipoApostado = $apuesta['combinacion']['tipo'] ?? null;
        $numeroApostado = $apuesta['combinacion']['numero'] ?? null;
        $signoApostado = $apuesta['combinacion']['signo'] ?? null;

        $numerosGanadores = $resultados['numeros_ganadores'] ?? [];

        if (is_string($numerosGanadores)) {
            $numerosGanadores = json_decode($numerosGanadores, true) ?? [];
        }

        $coincideNumero = false;
        foreach (['triple_a', 'triple_b', 'triple_c'] as $tipo) {
            if (isset($numerosGanadores[$tipo]) && $numerosGanadores[$tipo] === $numeroApostado) {
                $coincideNumero = true;
                break;
            }
        }

        if (! $coincideNumero) {
            return ['premio_bs' => 0, 'premio_usd' => 0];
        }

        if ($tipoApostado === 'triple_c' && $signoApostado) {
            $signoGanador = $numerosGanadores['signo'] ?? null;
            if (strtoupper($signoApostado) !== strtoupper($signoGanador ?? '')) {
                return ['premio_bs' => 0, 'premio_usd' => 0];
            }
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
            'descripcion' => 'Acierta el número de 3 cifras y gana '.$this->multiplicador.' veces tu apuesta.',
            'tipo' => 'numero',
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

    public function obtenerHorarios(): array
    {
        return ['12:45', '16:45', '19:05'];
    }

    public function obtenerModalidades(): array
    {
        return [
            [
                'code' => 'triple_a',
                'label' => 'Triple A',
                'digitos' => 3,
                'requiere_signo' => false,
                'descripcion' => 'Número de 3 cifras',
            ],
            [
                'code' => 'triple_b',
                'label' => 'Triple B',
                'digitos' => 3,
                'requiere_signo' => false,
                'descripcion' => 'Número de 3 cifras',
            ],
            [
                'code' => 'triple_c',
                'label' => 'Triple C',
                'digitos' => 3,
                'requiere_signo' => true,
                'descripcion' => 'Número de 3 cifras + signo zodiacal',
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
            'combinacion.tipo' => ['required', Rule::in(['triple_a', 'triple_b', 'triple_c'])],
            'combinacion.numero' => 'required|string|size:3',
            'combinacion.signo' => 'required_if:combinacion.tipo,triple_c|nullable|string|max:20',
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
