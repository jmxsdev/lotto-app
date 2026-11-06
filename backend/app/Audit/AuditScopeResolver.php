<?php

namespace App\Audit;

use App\Models\Agencia;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Derives the denormalized hierarchy scope from an actor or a model.
 *
 * Only attributes already present on the model are read; a relation is loaded
 * only when the parent level is not stored on the row (for example a Taquilla
 * does not carry `banca_id`, so its `grupo` is resolved once). The resolver
 * never writes and never fails: unknown entities produce an empty scope.
 *
 * `forEntity(type, id)` is intentionally absent: deriving a full chain from an
 * arbitrary type/id would require a query with no benefit over `forModel()`,
 * which is what the recorder already has when it emits an event.
 */
final class AuditScopeResolver
{
    public function forActor(?Authenticatable $user): AuditScope
    {
        if (! $user instanceof Model) {
            return new AuditScope;
        }

        return $this->forModel($user);
    }

    public function forModel(Model $model): AuditScope
    {
        if ($model instanceof Banca) {
            return new AuditScope(bancaId: $this->key($model));
        }

        if ($model instanceof Grupo) {
            return new AuditScope(
                bancaId: $this->attribute($model, 'banca_id'),
                grupoId: $this->key($model),
            );
        }

        if ($model instanceof Agencia) {
            return new AuditScope(
                bancaId: $this->attribute($model, 'banca_id') ?? $this->relationInt($model, 'grupo', 'banca_id'),
                grupoId: $this->attribute($model, 'grupo_id'),
                agenciaId: $this->key($model),
            );
        }

        if ($model instanceof Taquilla) {
            return new AuditScope(
                bancaId: $this->attribute($model, 'banca_id') ?? $this->relationInt($model, 'grupo', 'banca_id'),
                grupoId: $this->attribute($model, 'grupo_id'),
                agenciaId: $this->attribute($model, 'agencia_id'),
                taquillaId: $this->key($model),
            );
        }

        if ($model instanceof User) {
            return new AuditScope(
                bancaId: $this->attribute($model, 'banca_id'),
                grupoId: $this->attribute($model, 'grupo_id'),
                agenciaId: $this->attribute($model, 'agencia_id'),
                taquillaId: $this->attribute($model, 'taquilla_id'),
            );
        }

        return $this->forGenericModel($model);
    }

    /**
     * Merge every provided scope into an empty one.
     */
    public function merge(AuditScope ...$scopes): AuditScope
    {
        return (new AuditScope)->merge(...$scopes);
    }

    /**
     * Models that only store a lower level of the chain (Ticket, Pago,
     * Apuesta, CierreCaja only carry `taquilla_id`) borrow the missing levels
     * from the parent once.
     */
    private function forGenericModel(Model $model): AuditScope
    {
        $scope = new AuditScope(
            bancaId: $this->attribute($model, 'banca_id'),
            grupoId: $this->attribute($model, 'grupo_id'),
            agenciaId: $this->attribute($model, 'agencia_id'),
            taquillaId: $this->attribute($model, 'taquilla_id'),
        );

        if ($scope->bancaId !== null) {
            return $scope;
        }

        if ($scope->taquillaId !== null) {
            $parent = $this->relation($model, 'taquilla');
        } elseif ($scope->grupoId !== null) {
            $parent = $this->relation($model, 'grupo');
        } elseif ($scope->agenciaId !== null) {
            $parent = $this->relation($model, 'agencia');
        } else {
            return $scope;
        }

        return $parent instanceof Model
            ? $scope->merge($this->forModel($parent))
            : $scope;
    }

    private function key(Model $model): ?int
    {
        return $this->intOrNull($model->getKey());
    }

    private function attribute(Model $model, string $key): ?int
    {
        $attributes = $model->getAttributes();

        if (! array_key_exists($key, $attributes)) {
            return null;
        }

        return $this->intOrNull($attributes[$key]);
    }

    private function relation(Model $model, string $relation): ?Model
    {
        if (! method_exists($model, $relation)) {
            return null;
        }

        $related = $model->getRelationValue($relation);

        return $related instanceof Model ? $related : null;
    }

    private function relationInt(Model $model, string $relation, string $attribute): ?int
    {
        $related = $this->relation($model, $relation);

        return $related instanceof Model ? $this->attribute($related, $attribute) : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
