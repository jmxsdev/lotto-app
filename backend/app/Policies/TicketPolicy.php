<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use App\Support\Scope\AlcanceService;

/**
 * Autorización de ticket (issue #85): delega el alcance en `AlcanceService`.
 *
 * `viewAny` solo valida el contrato de listado (`ENTIDADES`); el filtrado de
 * filas lo aplica el controller con `applyToTickets`.
 */
class TicketPolicy
{
    public function __construct(
        private readonly AlcanceService $alcance,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(AlcanceService::ENTIDADES);
    }

    public function view(User $actor, Ticket $ticket): bool
    {
        return $this->alcance->alcanceDeEntidad($actor, 'ticket', $ticket);
    }

    public function update(User $actor, Ticket $ticket): bool
    {
        return $this->alcance->puedeGestionar($actor, 'ticket', $ticket);
    }

    public function delete(User $actor, Ticket $ticket): bool
    {
        return $this->alcance->puedeGestionar($actor, 'ticket', $ticket);
    }
}
