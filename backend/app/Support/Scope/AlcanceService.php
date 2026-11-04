<?php

namespace App\Support\Scope;

use App\Models\Agencia;
use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\CierreCaja;
use App\Models\Grupo;
use App\Models\JuegoLimite;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Única implementación del recorrido jerárquico de alcance (issue #85).
 *
 * Capa aditiva: los controllers y las policies delegan aquí. El servicio no
 * reimplementa la jerarquía; delega en los helpers de `User`
 * (`masterBancaIds`, `masterBancaScope`, `masterBancaChainScope`,
 * `masterBancaGroupScope`, `masterCanAccessBanca`).
 *
 * El orden de precedencia depende del contrato de cada endpoint y se fija en
 * las constantes `APUESTAS`, `TICKETS`, `POLICY`, `ENTIDADES`, `CIERRE_ITEM`,
 * `LIMITES` y `JUEGO_VIS` (primer match gana).
 */
final class AlcanceService
{
    /**
     * Apuesta / Estadística / Reporte (listados de apuestas).
     *
     * @var array<int, string>
     */
    public const APUESTAS = [
        'taquilla',
        'agencia',
        'grupo',
        'banca',
        'master',
        'super_master',
    ];

    /**
     * TicketController (listado y ganadores). `agencia` queda fuera del
     * contrato de filas: el controller la admite en `autorizarScope` pero no
     * la filtra, y el servicio preserva esa paridad.
     *
     * @var array<int, string>
     */
    public const TICKETS = [
        'taquilla',
        'grupo',
        'banca',
        'master',
    ];

    /**
     * `ApuestaPolicy::view/motivoNoAnulable` (ownership por apuesta).
     *
     * @var array<int, string>
     */
    public const POLICY = [
        'super_master',
        'taquilla',
        'agencia',
        'grupo',
        'banca',
        'master',
    ];

    /**
     * Listados de entidades, usuarios y cierres.
     *
     * @var array<int, string>
     */
    public const ENTIDADES = [
        'super_master',
        'master',
        'banca',
        'grupo',
        'agencia',
        'taquilla',
    ];

    /**
     * Operación sobre una taquilla concreta (cierre de caja): taquilla-first.
     *
     * @var array<int, string>
     */
    public const CIERRE_ITEM = [
        'taquilla',
        'super_master',
        'master',
        'agencia',
        'banca',
        'grupo',
    ];

    /**
     * Límites de juego (JuegoController).
     *
     * @var array<int, string>
     */
    public const LIMITES = [
        'super_master',
        'master',
        'banca',
        'agencia',
        'grupo',
    ];

    /**
     * Visibilidad de entidades para la matriz de límites (JuegoController).
     *
     * @var array<int, string>
     */
    public const JUEGO_VIS = [
        'banca',
        'master',
        'grupo',
        'agencia',
        'super_master',
    ];

    /** Falta el vínculo con la banca del actor. */
    public const MENSAJE_SIN_BANCA = 'No tienes una banca asociada.';

    /** Falta el vínculo con el grupo del actor. */
    public const MENSAJE_SIN_GRUPO = 'No tienes un grupo asociado.';

    /** Falta el vínculo con la agencia del actor. */
    public const MENSAJE_SIN_AGENCIA = 'No tienes una agencia asociada.';

    /** Falta el vínculo con la taquilla del actor. */
    public const MENSAJE_SIN_TAQUILLA = 'No tienes una taquilla asociada.';

    /**
     * Acotar un listado de apuestas al alcance del actor.
     */
    public function applyToApuestas(Builder $q, User $actor): Builder
    {
        $rol = $this->primerRol($actor, self::APUESTAS);

        if ($rol === null) {
            $this->denegar('No autorizado.');
        }

        return match ($rol) {
            'taquilla' => $q->where('taquilla_id', $actor->taquilla_id),
            'agencia' => $q->whereHas(
                'taquilla',
                fn (Builder $t) => $t->where('agencia_id', $actor->agencia_id)
            ),
            'grupo' => $q->whereHas(
                'taquilla.grupo',
                fn (Builder $g) => $g->where('grupo_id', $actor->grupo_id)
            ),
            'banca' => $q->whereHas(
                'taquilla.grupo.banca',
                fn (Builder $b) => $b->where('banca_id', $actor->banca_id)
            ),
            'master' => $this->aplicarMasterChainScope($q, $actor),
            'super_master' => $q,
        };
    }

    /**
     * Acotar un listado de tickets al alcance del actor.
     *
     * Sin rol del contrato TICKETS (p. ej. `super_master` o `agencia`) no se
     * agrega filtro: es la paridad con `TicketController`, cuyo fail-closed
     * vive en `autorizarScope`.
     */
    public function applyToTickets(Builder $q, User $actor): Builder
    {
        $rol = $this->primerRol($actor, self::TICKETS);

        return match ($rol) {
            'taquilla' => $q->where('taquilla_id', $actor->taquilla_id),
            'grupo' => $q->whereHas(
                'taquilla.grupo',
                fn (Builder $g) => $g->where('grupo_id', $actor->grupo_id)
            ),
            'banca' => $q->whereHas(
                'taquilla.grupo.banca',
                fn (Builder $b) => $b->where('banca_id', $actor->banca_id)
            ),
            'master' => $this->aplicarMasterChainScope($q, $actor),
            default => $q,
        };
    }

    /**
     * Acotar un listado de cierres de caja al alcance del actor.
     *
     * `$mensaje` es el texto de denegación para un rol fuera de catálogo; los
     * mensajes de vínculo faltante los aporta el servicio como constantes.
     */
    public function applyToCierres(Builder $q, User $actor, string $mensaje): Builder
    {
        $rol = $this->primerRol($actor, self::ENTIDADES);

        if ($rol === null) {
            $this->denegar($mensaje);
        }

        if ($rol === 'super_master') {
            return $q;
        }

        if ($rol === 'master') {
            return $this->aplicarMasterChainScope($q, $actor);
        }

        if ($rol === 'banca') {
            if (! $actor->banca_id) {
                $this->denegar(self::MENSAJE_SIN_BANCA);
            }

            return $q->whereHas(
                'taquilla.grupo',
                fn (Builder $g) => $g->where('banca_id', $actor->banca_id)
            );
        }

        if ($rol === 'grupo') {
            if (! $actor->grupo_id) {
                $this->denegar(self::MENSAJE_SIN_GRUPO);
            }

            return $q->whereHas(
                'taquilla',
                fn (Builder $t) => $t->where('grupo_id', $actor->grupo_id)
            );
        }

        if ($rol === 'agencia') {
            if (! $actor->agencia_id) {
                $this->denegar(self::MENSAJE_SIN_AGENCIA);
            }

            return $q->whereHas(
                'taquilla',
                fn (Builder $t) => $t->where('agencia_id', $actor->agencia_id)
            );
        }

        // taquilla
        if (! $actor->taquilla_id) {
            $this->denegar(self::MENSAJE_SIN_TAQUILLA);
        }

        return $q->where('taquilla_id', $actor->taquilla_id);
    }

    /**
     * Bancas que el actor administra como master.
     *
     * @return Collection<int, int>
     */
    public function bancasDeMaster(User $actor): Collection
    {
        return $actor->masterBancaIds();
    }

    /**
     * Denegar con el mecanismo único de la capa: 403 + mensaje exacto.
     */
    public function denegar(string $mensaje): never
    {
        abort(403, $mensaje);
    }

    /**
     * Primer rol del actor en el orden de precedencia del contrato.
     *
     * @param  array<int, string>  $roles
     */
    private function primerRol(User $actor, array $roles): ?string
    {
        foreach ($roles as $rol) {
            if ($actor->hasRole($rol)) {
                return $rol;
            }
        }

        return null;
    }

    private function aplicarMasterChainScope(Builder $q, User $actor): Builder
    {
        $actor->masterBancaChainScope()($q);

        return $q;
    }

    private function aplicarMasterBancaScope(Builder $q, User $actor, string $column = 'banca_id'): Builder
    {
        $actor->masterBancaScope($column)($q);

        return $q;
    }

    private function aplicarMasterGroupScope(Builder $q, User $actor): Builder
    {
        $actor->masterBancaGroupScope()($q);

        return $q;
    }
}
