<?php

namespace App\Services;

use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Taquilla;

/**
 * Resuelve el "activo efectivo" de las entidades en runtime:
 * propio AND todos los ancestros existentes, sin escrituras en cascada.
 * Un binding faltante (p. ej. taquilla sin grupo) se considera activo.
 */
class ActivacionEfectivaService
{
    /**
     * Una banca está activa si existe y su flag propio está en true.
     */
    public function bancaActiva(int $bancaId): bool
    {
        $banca = Banca::find($bancaId);

        return $banca !== null && $banca->active;
    }

    /**
     * Un grupo está activo si existe, su flag propio está en true
     * y su banca (si existe) está activa.
     */
    public function grupoActivo(int $grupoId): bool
    {
        $grupo = Grupo::with('banca')->find($grupoId);

        if ($grupo === null || ! $grupo->active) {
            return false;
        }

        return ! $grupo->banca || $grupo->banca->active;
    }

    /**
     * Una taquilla está activa si existe, su flag propio está en true
     * y su cadena grupo → banca está activa.
     */
    public function taquillaActiva(int $taquillaId): bool
    {
        $taquilla = Taquilla::with('grupo.banca')->find($taquillaId);

        if ($taquilla === null) {
            return false;
        }

        return $this->estadoTaquilla($taquilla)['active'];
    }

    /**
     * Estado efectivo de una taquilla con la causa del bloqueo,
     * para poder emitir mensajes de error específicos por nivel.
     *
     * @return array{active: bool, causa: 'taquilla'|'grupo'|'banca'|null}
     */
    public function estadoTaquilla(Taquilla $taquilla): array
    {
        $taquilla->loadMissing('grupo.banca');

        if (! $taquilla->active) {
            return ['active' => false, 'causa' => 'taquilla'];
        }

        if ($taquilla->grupo && ! $taquilla->grupo->active) {
            return ['active' => false, 'causa' => 'grupo'];
        }

        if ($taquilla->grupo?->banca && ! $taquilla->grupo->banca->active) {
            return ['active' => false, 'causa' => 'banca'];
        }

        return ['active' => true, 'causa' => null];
    }
}
