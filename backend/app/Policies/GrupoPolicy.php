<?php

namespace App\Policies;

use App\Models\Grupo;
use App\Models\User;
use App\Support\Scope\AlcanceService;

/**
 * Autorización de grupo (issue #85): delega el alcance en `AlcanceService`.
 *
 * `viewAny` solo valida el contrato de listado (`ENTIDADES`); el filtrado de
 * filas lo aplica el controller con `applyToEntidades`.
 */
class GrupoPolicy
{
    public function __construct(
        private readonly AlcanceService $alcance,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(AlcanceService::ENTIDADES);
    }

    public function view(User $actor, Grupo $grupo): bool
    {
        return $this->alcance->alcanceDeEntidad($actor, 'grupo', $grupo);
    }

    public function update(User $actor, Grupo $grupo): bool
    {
        return $this->alcance->puedeGestionar($actor, 'grupo', $grupo);
    }

    public function delete(User $actor, Grupo $grupo): bool
    {
        return $this->alcance->puedeGestionar($actor, 'grupo', $grupo);
    }
}
