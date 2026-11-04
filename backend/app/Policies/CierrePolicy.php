<?php

namespace App\Policies;

use App\Models\CierreCaja;
use App\Models\User;
use App\Support\Scope\AlcanceService;

/**
 * Autorización de cierre de caja (issue #85): delega en `AlcanceService`.
 *
 * El modelo es `CierreCaja`, por lo que el registro explícito
 * `Gate::policy(CierreCaja::class, CierrePolicy::class)` no puede depender del
 * auto-discovery de Laravel. `viewAny` solo valida `ENTIDADES`; el listado se
 * filtra con `applyToCierres`.
 */
class CierrePolicy
{
    public function __construct(
        private readonly AlcanceService $alcance,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(AlcanceService::ENTIDADES);
    }

    public function view(User $actor, CierreCaja $cierre): bool
    {
        return $this->alcance->alcanceDeEntidad($actor, 'cierre', $cierre);
    }

    public function update(User $actor, CierreCaja $cierre): bool
    {
        return $this->alcance->puedeGestionar($actor, 'cierre', $cierre);
    }

    public function delete(User $actor, CierreCaja $cierre): bool
    {
        return $this->alcance->puedeGestionar($actor, 'cierre', $cierre);
    }
}
