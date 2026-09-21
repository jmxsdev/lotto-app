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
     * F2 (D8/§3.1, §3.2): soporta también las modalidades de un solo sorteo —
     * posiciones del triple (terminal/punta/uña), aproximación (terminal ±1),
     * números exactos del Arrejuntado (arrimao 4 cifras, pegadito 5 cifras),
     * claves con signo (signo_terminal, signo_uña, signo_solo) y la
     * multi-selección del MISMO sorteo (Cruzado y Par Millonario A+B) via
     * `combinacion.selecciones[]`. El triple seco emite la clave del TIPO
     * apostado (`triple_a`/`triple_b`, el-arrejuntado 600×); los demás juegos
     * caen al base por fallback del engine.
     *
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    public function evaluarAcierto(array $apuesta, array $resultados): array
    {
        $combinacion = $apuesta['combinacion'] ?? [];
        $numerosGanadores = $this->numerosGanadores($resultados);

        if (! empty($combinacion['selecciones'])) {
            return $this->evaluarSelecciones($combinacion, $numerosGanadores);
        }

        $tipo = $combinacion['tipo'] ?? null;

        return match ($tipo) {
            'triple_a' => $this->aciertoTripleSimple('triple_a', $combinacion, $numerosGanadores),
            'triple_b' => $this->aciertoTripleSimple('triple_b', $combinacion, $numerosGanadores),
            'triple_c' => $this->aciertoTripleConSigno($combinacion, $numerosGanadores),
            'terminal' => $this->aciertoPosicion($combinacion, $numerosGanadores, 1, 2, 'terminal'),
            'punta' => $this->aciertoPosicion($combinacion, $numerosGanadores, 0, 2, 'punta'),
            'uña' => $this->aciertoPosicion($combinacion, $numerosGanadores, 2, 1, 'uña'),
            'aproximacion' => $this->aciertoAproximacion($combinacion, $numerosGanadores),
            'signo_terminal' => $this->aciertoSignoPosicion($combinacion, $numerosGanadores, 1, 2, 'signo_terminal'),
            'signo_uña' => $this->aciertoSignoPosicion($combinacion, $numerosGanadores, 2, 1, 'signo_uña'),
            'signo_solo' => $this->aciertoSoloSigno($combinacion, $numerosGanadores),
            'arrimao' => $this->aciertoNumeroExacto($combinacion, $numerosGanadores, 'arrimao', 4),
            'pegadito' => $this->aciertoNumeroExacto($combinacion, $numerosGanadores, 'pegadito', 5),
            default => ['coincide' => false, 'clave' => 'base', 'meta' => []],
        };
    }

    /**
     * Modalidad canónica para `premio_posible` (D9/§3.1, F2): deriva la clave
     * del vocabulario §3.1 desde el shape de `combinacion` — tipo apostado,
     * posición del triple o `selecciones[]` same-draw. La Dupleta nunca se
     * emite (D8: fuera de alcance).
     */
    public function modalidadDe(array $combinacion): string
    {
        if (! empty($combinacion['selecciones'])) {
            return $this->modalidadDeSelecciones($combinacion['selecciones']);
        }

        return match ($combinacion['tipo'] ?? null) {
            'triple_a' => 'triple_a',
            'triple_b' => 'triple_b',
            'triple_c' => 'signo_triple',
            'terminal' => 'terminal',
            'punta' => 'punta',
            'uña' => 'uña',
            'aproximacion' => 'aproximacion',
            'signo_terminal' => 'signo_terminal',
            'signo_uña' => 'signo_uña',
            'signo_solo' => 'signo_solo',
            'arrimao' => 'arrimao',
            'pegadito' => 'pegadito',
            default => 'base',
        };
    }

    /**
     * Modalidad canónica de la multi-selección same-draw (§3.4): dos triples
     * → Par Millonario A+B (`triple_a_b`); dos puntas → Cruzado (tier 3.000×).
     */
    private function modalidadDeSelecciones(array $selecciones): string
    {
        $tipos = array_map(fn ($s) => (string) ($s['tipo'] ?? ''), $selecciones);

        if (in_array('triple_a', $tipos, true) && in_array('triple_b', $tipos, true)) {
            return 'triple_a_b';
        }

        $puntas = array_filter($tipos, fn ($t) => str_starts_with($t, 'punta'));
        if (count($puntas) >= 2) {
            return 'cruzado';
        }

        return 'base';
    }

    /**
     * Triple seco: paga SOLO si el número está en el tipo apostado (REQ5/N3);
     * la clave emitida es el tipo apostado (el-arrejuntado `triple_a`/`triple_b`
     * 600×, §3.2; en el resto cae al base del engine).
     *
     * @param  array<string, mixed>  $combinacion
     * @param  array<string, mixed>  $numeros
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    private function aciertoTripleSimple(string $tipo, array $combinacion, array $numeros): array
    {
        $numeroApostado = $combinacion['numero'] ?? null;
        $numeroGanador = $numeros[$tipo] ?? null;

        if ($numeroApostado === null || $numeroGanador === null) {
            return ['coincide' => false, 'clave' => $tipo, 'meta' => []];
        }

        $apostado = str_pad((string) $numeroApostado, 3, '0', STR_PAD_LEFT);
        $ganador = str_pad((string) $numeroGanador, 3, '0', STR_PAD_LEFT);

        return $apostado === $ganador
            ? ['coincide' => true, 'clave' => $tipo, 'meta' => []]
            : ['coincide' => false, 'clave' => $tipo, 'meta' => []];
    }

    /**
     * Triple + signo (zodiacal): número exacto en triple_c + signo igual
     * (label o sigla, REQ4/N2). Clave `signo_triple`.
     *
     * @param  array<string, mixed>  $combinacion
     * @param  array<string, mixed>  $numeros
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    private function aciertoTripleConSigno(array $combinacion, array $numeros): array
    {
        $numeroApostado = $combinacion['numero'] ?? null;
        $signoApostado = $combinacion['signo'] ?? null;
        $numeroGanador = $numeros['triple_c'] ?? null;

        if ($numeroApostado === null || $numeroGanador === null) {
            return ['coincide' => false, 'clave' => 'signo_triple', 'meta' => []];
        }

        $apostado = str_pad((string) $numeroApostado, 3, '0', STR_PAD_LEFT);
        $ganador = str_pad((string) $numeroGanador, 3, '0', STR_PAD_LEFT);

        if ($apostado !== $ganador) {
            return ['coincide' => false, 'clave' => 'signo_triple', 'meta' => []];
        }

        $signoGanador = $numeros['signo'] ?? null;

        if (! $signoGanador || $this->normalizarSigno($signoApostado) !== $this->normalizarSigno($signoGanador)) {
            return ['coincide' => false, 'clave' => 'signo_triple', 'meta' => []];
        }

        return ['coincide' => true, 'clave' => 'signo_triple', 'meta' => []];
    }

    /**
     * Posición del triple (F2/§3.1): terminal (2 últimas), punta (2 primeras)
     * o uña (última). El número apostado se compara contra la posición de
     * CUALQUIER triple del sorteo (A, B o C) con padding.
     *
     * @param  array<string, mixed>  $combinacion
     * @param  array<string, mixed>  $numeros
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    private function aciertoPosicion(array $combinacion, array $numeros, int $offset, int $len, string $clave): array
    {
        $numeroApostado = $combinacion['numero'] ?? null;

        if ($numeroApostado === null) {
            return ['coincide' => false, 'clave' => $clave, 'meta' => []];
        }

        foreach (['triple_a', 'triple_b', 'triple_c'] as $tipo) {
            $triple = $numeros[$tipo] ?? null;
            if ($triple === null) {
                continue;
            }

            if ($this->coincidePosicion((string) $numeroApostado, (string) $triple, $offset, $len)) {
                return ['coincide' => true, 'clave' => $clave, 'meta' => []];
            }
        }

        return ['coincide' => false, 'clave' => $clave, 'meta' => []];
    }

    /**
     * Aproximación (F2/§3.1, Triple Fácil 10×): el número apostado es el
     * terminal del triple (2 últimas cifras) ±1, sin envoltura (00–99).
     *
     * @param  array<string, mixed>  $combinacion
     * @param  array<string, mixed>  $numeros
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    private function aciertoAproximacion(array $combinacion, array $numeros): array
    {
        $numeroApostado = $combinacion['numero'] ?? null;

        if ($numeroApostado === null) {
            return ['coincide' => false, 'clave' => 'aproximacion', 'meta' => []];
        }

        $apostado = (int) $numeroApostado;

        foreach (['triple_a', 'triple_b', 'triple_c'] as $tipo) {
            $triple = $numeros[$tipo] ?? null;
            if ($triple === null) {
                continue;
            }

            $terminal = (int) substr(str_pad((string) $triple, 3, '0', STR_PAD_LEFT), 1, 2);

            if ($apostado >= 0 && $apostado <= 99 && abs($apostado - $terminal) <= 1) {
                return ['coincide' => true, 'clave' => 'aproximacion', 'meta' => []];
            }
        }

        return ['coincide' => false, 'clave' => 'aproximacion', 'meta' => []];
    }

    /**
     * Posición del triple + signo (F2/§3.1): signo_terminal (terminal +
     * signo) y signo_uña (uña + signo). El triple de referencia es el
     * `triple_c` (el que viaja con el signo del sorteo en los resultados
     * actuales de tripletas).
     *
     * @param  array<string, mixed>  $combinacion
     * @param  array<string, mixed>  $numeros
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    private function aciertoSignoPosicion(array $combinacion, array $numeros, int $offset, int $len, string $clave): array
    {
        $numeroApostado = $combinacion['numero'] ?? null;
        $signoApostado = $combinacion['signo'] ?? null;
        $tripleC = $numeros['triple_c'] ?? null;
        $signoGanador = $numeros['signo'] ?? null;

        if ($numeroApostado === null || $tripleC === null || ! $signoGanador) {
            return ['coincide' => false, 'clave' => $clave, 'meta' => []];
        }

        if ($this->normalizarSigno($signoApostado) !== $this->normalizarSigno($signoGanador)) {
            return ['coincide' => false, 'clave' => $clave, 'meta' => []];
        }

        return $this->coincidePosicion((string) $numeroApostado, (string) $tripleC, $offset, $len)
            ? ['coincide' => true, 'clave' => $clave, 'meta' => []]
            : ['coincide' => false, 'clave' => $clave, 'meta' => []];
    }

    /**
     * Signo solo sin número (F2/§3.1, Triple Chance signo_solo 6×).
     *
     * @param  array<string, mixed>  $combinacion
     * @param  array<string, mixed>  $numeros
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    private function aciertoSoloSigno(array $combinacion, array $numeros): array
    {
        $signoApostado = $combinacion['signo'] ?? null;
        $signoGanador = $numeros['signo'] ?? null;

        if (! $signoGanador || ! $signoApostado) {
            return ['coincide' => false, 'clave' => 'signo_solo', 'meta' => []];
        }

        return $this->normalizarSigno($signoApostado) === $this->normalizarSigno($signoGanador)
            ? ['coincide' => true, 'clave' => 'signo_solo', 'meta' => []]
            : ['coincide' => false, 'clave' => 'signo_solo', 'meta' => []];
    }

    /**
     * Número exacto de 4/5 cifras (F2/§3.1, El Arrejuntado): arrimao 6.000× y
     * pegadito 60.000× contra las claves homónimas que persiste el scraper.
     *
     * @param  array<string, mixed>  $combinacion
     * @param  array<string, mixed>  $numeros
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    private function aciertoNumeroExacto(array $combinacion, array $numeros, string $clave, int $digitos): array
    {
        $numeroApostado = $combinacion['numero'] ?? null;
        $numeroGanador = $numeros[$clave] ?? null;

        if ($numeroApostado === null || $numeroGanador === null) {
            return ['coincide' => false, 'clave' => $clave, 'meta' => []];
        }

        $apostado = str_pad((string) $numeroApostado, $digitos, '0', STR_PAD_LEFT);
        $ganador = str_pad((string) $numeroGanador, $digitos, '0', STR_PAD_LEFT);

        return $apostado === $ganador
            ? ['coincide' => true, 'clave' => $clave, 'meta' => []]
            : ['coincide' => false, 'clave' => $clave, 'meta' => []];
    }

    /**
     * Multi-selección del MISMO sorteo (F2/D8 §3.4, sin tablas): Cruzado (dos
     * puntas contra las 2 primeras de A y B: ambas → `cruzado` 3.000×, una →
     * `cruzado_10` 10×) y Par Millonario (dos triples exactos: ambos →
     * `triple_a_b` 200.000×, uno → `solo_a_b` 150×).
     *
     * @param  array<string, mixed>  $combinacion
     * @param  array<string, mixed>  $numeros
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    private function evaluarSelecciones(array $combinacion, array $numeros): array
    {
        $selecciones = $combinacion['selecciones'];
        $tipos = array_map(fn ($s) => (string) ($s['tipo'] ?? ''), $selecciones);
        $modalidad = (string) ($combinacion['modalidad'] ?? '');

        $esCruzado = $modalidad === 'cruzado'
            || count(array_filter($tipos, fn ($t) => str_starts_with($t, 'punta'))) >= 2;

        if ($esCruzado) {
            $puntaA = substr(str_pad((string) ($numeros['triple_a'] ?? ''), 3, '0', STR_PAD_LEFT), 0, 2);
            $puntaB = substr(str_pad((string) ($numeros['triple_b'] ?? ''), 3, '0', STR_PAD_LEFT), 0, 2);

            $aciertoA = $this->seleccionEsPunta($selecciones[0] ?? [], $puntaA);
            $aciertoB = $this->seleccionEsPunta($selecciones[1] ?? [], $puntaB);

            if ($aciertoA && $aciertoB) {
                return ['coincide' => true, 'clave' => 'cruzado', 'meta' => []];
            }

            if ($aciertoA || $aciertoB) {
                return ['coincide' => true, 'clave' => 'cruzado_10', 'meta' => []];
            }

            return ['coincide' => false, 'clave' => 'cruzado', 'meta' => []];
        }

        $esPar = $modalidad === 'triple_a_b'
            || (in_array('triple_a', $tipos, true) && in_array('triple_b', $tipos, true));

        if ($esPar) {
            $tripleA = str_pad((string) ($numeros['triple_a'] ?? ''), 3, '0', STR_PAD_LEFT);
            $tripleB = str_pad((string) ($numeros['triple_b'] ?? ''), 3, '0', STR_PAD_LEFT);

            $aciertoA = $this->seleccionEsTriple($selecciones[0] ?? [], $tripleA);
            $aciertoB = $this->seleccionEsTriple($selecciones[1] ?? [], $tripleB);

            if ($aciertoA && $aciertoB) {
                return ['coincide' => true, 'clave' => 'triple_a_b', 'meta' => []];
            }

            if ($aciertoA || $aciertoB) {
                return ['coincide' => true, 'clave' => 'solo_a_b', 'meta' => []];
            }

            return ['coincide' => false, 'clave' => 'triple_a_b', 'meta' => []];
        }

        return ['coincide' => false, 'clave' => 'base', 'meta' => []];
    }

    private function seleccionEsPunta(array $seleccion, string $puntaGanadora): bool
    {
        $numero = (string) ($seleccion['numero'] ?? '');

        return str_pad($numero, 2, '0', STR_PAD_LEFT) === $puntaGanadora;
    }

    private function seleccionEsTriple(array $seleccion, string $tripleGanador): bool
    {
        $numero = (string) ($seleccion['numero'] ?? '');

        return str_pad($numero, 3, '0', STR_PAD_LEFT) === $tripleGanador;
    }

    /**
     * Compara el número apostado contra una posición del triple normalizado a
     * 3 cifras (N9): terminal (offset 1, len 2), punta (offset 0, len 2) y
     * uña (offset 2, len 1).
     */
    private function coincidePosicion(string $apostado, string $triple, int $offset, int $len): bool
    {
        $triplePad = str_pad($triple, 3, '0', STR_PAD_LEFT);
        $derivada = substr($triplePad, $offset, $len);
        $apostadoPad = str_pad($apostado, $len, '0', STR_PAD_LEFT);

        return $derivada === $apostadoPad;
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
