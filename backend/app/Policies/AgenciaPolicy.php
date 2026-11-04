<?php

namespace App\Policies;

use App\Models\Agencia;
use App\Models\User;
use App\Support\Scope\AlcanceService;

/**
 * Autorización de agencia (issue #85): delega el alcance en `AlcanceService`.
 *
 * `viewAny` solo valida el contrato de listado (`ENTIDADES`); el filtrado de
 * filas lo aplica el controller con `applyToEntidades`.
 */
class AgenciaPolicy
{
    public function __construct(
        private readonly AlcanceService $alcance,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(AlcanceService::ENTIDADES);
    }

    public function view(User $actor, Agencia $agencia): bool
    {
        return $this->alcance->alcanceDeEntidad($actor, 'agencia', $agencia);
    }

    public function update(User $actor, Agencia $agencia): bool
    {
        return $this->alcance->puedeGestionar($actor, 'agencia', $agencia);
    }

    public function delete(User $actor, Agencia $agencia): bool
    {
        return $this->alcance->puedeGestionar($actor, 'agencia', $agencia);
    }
}
