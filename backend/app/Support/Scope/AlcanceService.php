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
     * Acotar un listado de usuarios al alcance del actor.
     *
     * Master/banca/grupo incluyen además los usuarios que crearon
     * (`created_by` como metadata de fila).
     */
    public function applyToUsuarios(Builder $q, User $actor, string $mensaje): Builder
    {
        $rol = $this->primerRol($actor, self::ENTIDADES);

        if ($rol === null) {
            $this->denegar($mensaje);
        }

        if ($rol === 'super_master') {
            return $q;
        }

        if ($rol === 'master') {
            $ids = $actor->masterBancaIds();

            return $q->where(function (Builder $query) use ($actor, $ids) {
                if ($ids->isEmpty()) {
                    $query->where('created_by', $actor->id);

                    return;
                }

                $query->whereIn('banca_id', $ids)
                    ->orWhereHas(
                        'taquilla',
                        fn (Builder $t) => $t->whereHas(
                            'grupo',
                            fn (Builder $g) => $g->whereIn('banca_id', $ids)
                        )
                    )
                    ->orWhere('created_by', $actor->id);
            });
        }

        if ($rol === 'banca') {
            if (! $actor->banca_id) {
                $this->denegar(self::MENSAJE_SIN_BANCA);
            }

            return $q->where(function (Builder $query) use ($actor) {
                $query->where('banca_id', $actor->banca_id)
                    ->orWhereHas(
                        'taquilla',
                        fn (Builder $t) => $t->whereHas(
                            'grupo',
                            fn (Builder $g) => $g->where('banca_id', $actor->banca_id)
                        )
                    )
                    ->orWhere('created_by', $actor->id);
            });
        }

        if ($rol === 'grupo') {
            if (! $actor->grupo_id) {
                $this->denegar(self::MENSAJE_SIN_GRUPO);
            }

            return $q->where(function (Builder $query) use ($actor) {
                $query->where('grupo_id', $actor->grupo_id)
                    ->orWhereHas(
                        'taquilla',
                        fn (Builder $t) => $t->where('grupo_id', $actor->grupo_id)
                    )
                    ->orWhere('created_by', $actor->id);
            });
        }

        if ($rol === 'agencia') {
            if (! $actor->agencia_id) {
                $this->denegar(self::MENSAJE_SIN_AGENCIA);
            }

            return $q->where(function (Builder $query) use ($actor) {
                $query->where('agencia_id', $actor->agencia_id)
                    ->orWhere('created_by', $actor->id);
            });
        }

        // taquilla: solo se ve a sí misma
        return $q->where('id', $actor->id);
    }

    /**
     * Acotar un listado de entidades (`banca|grupo|agencia|taquilla|limite`).
     *
     * `$mensaje` es el texto de denegación para un rol sin listado permitido
     * en ese contexto; los mensajes de vínculo faltante son constantes.
     */
    public function applyToEntidades(Builder $q, User $actor, string $entidad, string $mensaje): Builder
    {
        return match ($entidad) {
            'banca' => $this->applyToBancas($q, $actor, $mensaje),
            'grupo' => $this->applyToGrupos($q, $actor, $mensaje),
            'agencia' => $this->applyToAgencias($q, $actor, $mensaje),
            'taquilla' => $this->applyToTaquillas($q, $actor, $mensaje),
            'limite' => $this->applyToLimites($q, $actor, $mensaje),
            default => $this->denegar($mensaje),
        };
    }

    /**
     * Entidades del tipo consultado visibles para el actor.
     *
     * `$raiz` interseca el alcance del rol con el mini-alcance de las pestañas
     * de entidad: `['tipo' => 'banca', 'id' => X]` o `['tipo' => 'grupo', 'id' => Y]`.
     * Rol ausente o fuera de catálogo: colección vacía (fail-closed).
     *
     * @param  array{tipo: string, id: int}|null  $raiz
     * @return Collection<int, Banca|Grupo|Taquilla>
     */

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

    // -----------------------------------------------------------------
    // Listados por entidad
    // -----------------------------------------------------------------

    private function applyToBancas(Builder $q, User $actor, string $mensaje): Builder
    {
        return match ($this->primerRol($actor, self::ENTIDADES)) {
            'super_master' => $q,
            'master' => $this->aplicarMasterBancaScope($q, $actor, 'id'),
            default => $this->denegar($mensaje),
        };
    }

    private function applyToGrupos(Builder $q, User $actor, string $mensaje): Builder
    {
        $rol = $this->primerRol($actor, self::ENTIDADES);

        if ($rol === 'super_master') {
            return $q;
        }

        if ($rol === 'master') {
            return $this->aplicarMasterBancaScope($q, $actor);
        }

        if ($rol === 'banca') {
            if (! $actor->banca_id) {
                $this->denegar(self::MENSAJE_SIN_BANCA);
            }

            return $q->where('banca_id', $actor->banca_id);
        }

        if ($rol === 'grupo') {
            if (! $actor->grupo_id) {
                $this->denegar(self::MENSAJE_SIN_GRUPO);
            }

            return $q->where('id', $actor->grupo_id);
        }

        return $this->denegar($mensaje);
    }

    private function applyToAgencias(Builder $q, User $actor, string $mensaje): Builder
    {
        $rol = $this->primerRol($actor, self::ENTIDADES);

        if ($rol === 'super_master') {
            return $q;
        }

        if ($rol === 'master') {
            return $this->aplicarMasterGroupScope($q, $actor);
        }

        if ($rol === 'banca') {
            if (! $actor->banca_id) {
                $this->denegar(self::MENSAJE_SIN_BANCA);
            }

            return $q->whereHas(
                'grupo',
                fn (Builder $g) => $g->where('banca_id', $actor->banca_id)
            );
        }

        if ($rol === 'grupo') {
            if (! $actor->grupo_id) {
                $this->denegar(self::MENSAJE_SIN_GRUPO);
            }

            return $q->where('grupo_id', $actor->grupo_id);
        }

        if ($rol === 'agencia') {
            if (! $actor->agencia_id) {
                $this->denegar(self::MENSAJE_SIN_AGENCIA);
            }

            return $q->whereKey($actor->agencia_id);
        }

        return $this->denegar($mensaje);
    }

    private function applyToTaquillas(Builder $q, User $actor, string $mensaje): Builder
    {
        $rol = $this->primerRol($actor, self::ENTIDADES);

        if ($rol === 'super_master') {
            return $q;
        }

        if ($rol === 'master') {
            return $this->aplicarMasterGroupScope($q, $actor);
        }

        if ($rol === 'banca') {
            if (! $actor->banca_id) {
                $this->denegar(self::MENSAJE_SIN_BANCA);
            }

            return $q->whereHas(
                'grupo',
                fn (Builder $g) => $g->where('banca_id', $actor->banca_id)
            );
        }

        if ($rol === 'grupo') {
            if (! $actor->grupo_id) {
                $this->denegar(self::MENSAJE_SIN_GRUPO);
            }

            return $q->where('grupo_id', $actor->grupo_id);
        }

        if ($rol === 'agencia') {
            if (! $actor->agencia_id) {
                $this->denegar(self::MENSAJE_SIN_AGENCIA);
            }

            return $q->where('agencia_id', $actor->agencia_id);
        }

        return $this->denegar($mensaje);
    }

    private function applyToLimites(Builder $q, User $actor, string $mensaje): Builder
    {
        $rol = $this->primerRol($actor, self::LIMITES);

        if ($rol === 'super_master') {
            return $q;
        }

        if ($rol === 'master') {
            return $this->aplicarMasterBancaScope($q, $actor);
        }

        if ($rol === 'banca') {
            return $q->where('banca_id', $actor->banca_id);
        }

        if ($rol === 'agencia') {
            return $q->where(function (Builder $query) use ($actor) {
                $query->where(function (Builder $banca) use ($actor) {
                    $banca->where('banca_id', $actor->banca_id)
                        ->whereNull('grupo_id')
                        ->whereNull('taquilla_id');
                })->orWhere('grupo_id', $actor->grupo_id)
                    ->orWhereIn(
                        'taquilla_id',
                        Taquilla::where('agencia_id', $actor->agencia_id)->pluck('id')
                    );
            });
        }

        if ($rol === 'grupo') {
            return $q->where(function (Builder $query) use ($actor) {
                $query->where('grupo_id', $actor->grupo_id)
                    ->orWhere(function (Builder $banca) use ($actor) {
                        $banca->whereNull('grupo_id')
                            ->whereNull('taquilla_id')
                            ->whereHas(
                                'grupo',
                                fn (Builder $g) => $g->where('banca_id', $actor->banca_id)
                            );
                    });
            });
        }

        return $this->denegar($mensaje);
    }

    // -----------------------------------------------------------------
    // Visibilidad de entidades (JuegoController)
    // -----------------------------------------------------------------


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
