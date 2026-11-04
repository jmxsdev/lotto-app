<?php

namespace App\Policies;

use App\Models\Taquilla;
use App\Models\User;
use App\Support\Scope\AlcanceService;

/**
 * Autorización de taquilla (issue #85): delega el alcance en `AlcanceService`.
 *
 * `viewAny` solo valida el contrato de listado (`ENTIDADES`); el filtrado de
 * filas lo aplica el controller con `applyToEntidades`. `operar` expone la
 * regla de cierre de caja (`CIERRE_ITEM`, variante agencia→grupo).
 */
class TaquillaPolicy
{
    public function __construct(
        private readonly AlcanceService $alcance,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(AlcanceService::ENTIDADES);
    }

    public function view(User $actor, Taquilla $taquilla): bool
    {
        return $this->alcance->alcanceDeEntidad($actor, 'taquilla', $taquilla);
    }

    public function update(User $actor, Taquilla $taquilla): bool
    {
        return $this->alcance->puedeGestionar($actor, 'taquilla', $taquilla);
    }

    public function delete(User $actor, Taquilla $taquilla): bool
    {
        return $this->alcance->puedeGestionar($actor, 'taquilla', $taquilla);
    }

    /** ¿Puede operar (cerrar caja de) esta taquilla? */
    public function operar(User $actor, Taquilla $taquilla): bool
    {
        return $this->alcance->puedeOperarTaquilla($actor, $taquilla);
    }
}
