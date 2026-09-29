<?php

namespace App\Services;

use App\Models\ComisionDefault;
use App\Models\Grupo;
use App\Models\JuegoLimite;
use App\Models\Taquilla;

/**
 * Lectores de tasa de comisión (S2, D2/D11).
 *
 * - tasaPropia: fila propia del nivel sobre juego_limites.porcentaje_pago;
 *   NULL/ausente = 0.
 * - tasaEfectiva: cascada taquilla > grupo > banca > default global de la
 *   moneda; una fila con porcentaje_pago NULL cede al siguiente nivel;
 *   sin definir en la cadena Y sin default global ⇒ 0.00.
 * - tasaLiquidable: tasa efectiva topada por el tope acumulado (D11):
 *   `min(tasaEfectiva, max(0, 100 − Σ tasas propias de ancestros))`,
 *   calculada por (entidad, moneda). Grupo ⇒ Σ{banca}; taquilla ⇒
 *   Σ{grupo, banca}; banca no tiene ancestros.
 *
 * El path de venta (createApuesta/validarMonedaYLimites/getEffectiveLimit)
 * NO se toca: este servicio es de solo lectura sobre las mismas tablas.
 */
class ComisionService
{
    /**
     * Tasa propia de un nivel: su fila configurada directamente.
     * NULL o ausente ⇒ 0.0.
     */
    public function tasaPropia(string $nivel, int $entidadId, int $juegoId, string $moneda): float
    {
        $query = JuegoLimite::where('juego_id', $juegoId)
            ->where('moneda', $moneda);

        if ($nivel === 'taquilla') {
            $query->where('taquilla_id', $entidadId);
        } elseif ($nivel === 'grupo') {
            $query->where('grupo_id', $entidadId)->whereNull('taquilla_id');
        } else {
            $query->where('banca_id', $entidadId)->whereNull('grupo_id')->whereNull('taquilla_id');
        }

        $fila = $query->first();

        return $fila && $fila->porcentaje_pago !== null ? (float) $fila->porcentaje_pago : 0.0;
    }

    /**
     * Tasa efectiva por cascada: taquilla > grupo > banca > default global.
     * Fila con porcentaje_pago NULL cede al siguiente nivel (D2).
     */
    public function tasaEfectiva(string $nivel, int $entidadId, int $juegoId, string $moneda): float
    {
        [$bancaId, $grupoId, $taquillaId] = $this->cadena($nivel, $entidadId);

        if ($bancaId === null) {
            return $this->defaultGlobal($moneda);
        }

        $filas = JuegoLimite::where('juego_id', $juegoId)
            ->where('moneda', $moneda)
            ->where(function ($q) use ($bancaId, $grupoId, $taquillaId) {
                $primera = true;

                if ($taquillaId !== null) {
                    $q->where('taquilla_id', $taquillaId);
                    $primera = false;
                }

                if ($grupoId !== null) {
                    $grupo = fn ($q2) => $q2->whereNull('taquilla_id')->where('grupo_id', $grupoId);
                    $primera ? $q->where($grupo) : $q->orWhere($grupo);
                    $primera = false;
                }

                $banca = fn ($q2) => $q2->whereNull('taquilla_id')->whereNull('grupo_id')->where('banca_id', $bancaId);
                $primera ? $q->where($banca) : $q->orWhere($banca);
            })
            ->orderByRaw('taquilla_id IS NOT NULL DESC, grupo_id IS NOT NULL DESC')
            ->get();

        foreach ($filas as $fila) {
            if ($fila->porcentaje_pago !== null) {
                return (float) $fila->porcentaje_pago;
            }
        }

        return $this->defaultGlobal($moneda);
    }

    /**
     * Tasa liquidable: tasa efectiva topada por el tope acumulado (D11).
     * Σ ancestros = tasas PROPIAS de los ancestros (NULL/ausente = 0),
     * piso 0; por moneda.
     */
    public function tasaLiquidable(string $nivel, int $entidadId, int $juegoId, string $moneda): float
    {
        $efectiva = $this->tasaEfectiva($nivel, $entidadId, $juegoId, $moneda);
        $sumaAncestros = $this->sumaTasasPropiasAncestros($nivel, $entidadId, $juegoId, $moneda);

        return min($efectiva, max(0.0, 100.0 - $sumaAncestros));
    }

    /**
     * Suma de tasas propias de los ancestros del nivel.
     * Grupo ⇒ {banca}; taquilla ⇒ {grupo, banca}; banca ⇒ sin ancestros.
     */
    private function sumaTasasPropiasAncestros(string $nivel, int $entidadId, int $juegoId, string $moneda): float
    {
        if ($nivel === 'grupo') {
            $grupo = Grupo::find($entidadId);

            return $grupo ? $this->tasaPropia('banca', $grupo->banca_id, $juegoId, $moneda) : 0.0;
        }

        if ($nivel === 'taquilla') {
            $taquilla = Taquilla::with('grupo')->find($entidadId);

            if (! $taquilla || ! $taquilla->grupo) {
                return 0.0;
            }

            return $this->tasaPropia('grupo', $taquilla->grupo_id, $juegoId, $moneda)
                + $this->tasaPropia('banca', $taquilla->grupo->banca_id, $juegoId, $moneda);
        }

        return 0.0;
    }

    /**
     * Resolver la cadena (banca, grupo, taquilla) del nivel consultado.
     *
     * @return array{0: int|null, 1: int|null, 2: int|null}
     */
    private function cadena(string $nivel, int $entidadId): array
    {
        if ($nivel === 'banca') {
            return [$entidadId, null, null];
        }

        if ($nivel === 'grupo') {
            $grupo = Grupo::find($entidadId);

            return [$grupo?->banca_id, $entidadId, null];
        }

        $taquilla = Taquilla::with('grupo')->find($entidadId);

        return [
            $taquilla?->grupo?->banca_id,
            $taquilla?->grupo_id,
            $entidadId,
        ];
    }

    /**
     * Default global de la moneda (D1); NULL/ausente ⇒ 0.0.
     */
    private function defaultGlobal(string $moneda): float
    {
        $default = ComisionDefault::where('moneda', $moneda)->first();

        return $default && $default->porcentaje_pago !== null ? (float) $default->porcentaje_pago : 0.0;
    }
}
