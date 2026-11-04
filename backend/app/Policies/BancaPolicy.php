<?php

namespace App\Policies;

use App\Models\Banca;
use App\Models\User;
use App\Support\Scope\AlcanceService;

/**
 * Autorización de banca (issue #85): delega el alcance en `AlcanceService`.
 *
 * `viewAny` solo valida el contrato de listado (`ENTIDADES`); el filtrado de
 * filas lo aplica el controller con `applyToEntidades`.
 */
class BancaPolicy
{
    public function __construct(
        private readonly AlcanceService $alcance,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(AlcanceService::ENTIDADES);
    }

    public function view(User $actor, Banca $banca): bool
    {
        return $this->alcance->alcanceDeEntidad($actor, 'banca', $banca);
    }

    public function update(User $actor, Banca $banca): bool
    {
        return $this->alcance->puedeGestionar($actor, 'banca', $banca);
    }

    public function delete(User $actor, Banca $banca): bool
    {
        return $this->alcance->puedeGestionar($actor, 'banca', $banca);
    }
}
