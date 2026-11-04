<?php

namespace App\Policies;

use App\Models\User;

/**
 * Autorización jerárquica única para el CRUD de usuarios.
 *
 * Matriz (issue #84): un rol solo gestiona su subárbol y los usuarios que
 * creó (`created_by`), siempre de rango estrictamente menor. Pares y
 * superiores quedan prohibidos. El `super_master` es intocable para el
 * borrado y su rol nunca se reescribe.
 */
class UserPolicy
{
    /**
     * Rango de cada rol: mayor número = mayor jerarquía.
     *
     * @var array<string, int>
     */
    private const RANKS = [
        'super_master' => 6,
        'master' => 5,
        'banca' => 4,
        'grupo' => 3,
        'agencia' => 2,
        'taquilla' => 1,
    ];

    public function view(User $actor, User $target): bool
    {
        // La ficha propia siempre se puede leer (incluye taquilla).
        if ($actor->id === $target->id) {
            return true;
        }

        if ($this->isSuperMaster($actor)) {
            return true;
        }

        return $this->canManageTarget($actor, $target);
    }

    public function update(User $actor, User $target): bool
    {
        // Autoedición permitida para todos salvo taquilla (matriz: solo
        // lectura de su propio usuario).
        if ($actor->id === $target->id) {
            return ! $actor->hasRole('taquilla');
        }

        if ($this->isSuperMaster($actor)) {
            return true;
        }

        return $this->canManageTarget($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        // Nadie borra a un super_master (incluido otro super_master).
        if ($target->hasRole('super_master')) {
            return false;
        }

        // Nadie se borra a sí mismo.
        if ($actor->id === $target->id) {
            return false;
        }

        if ($this->isSuperMaster($actor)) {
            return true;
        }

        return $this->canManageTarget($actor, $target);
    }

    /**
     * ¿El actor puede crear un usuario nuevo con este rol y estos vínculos?
     */
    public function canCreate(User $actor, string $role, array $data): bool
    {
        $rank = $this->roleRank($role);

        if ($rank === null) {
            return false;
        }

        // El super_master conserva la creación global (incluido otro super).
        if ($this->isSuperMaster($actor)) {
            return true;
        }

        $actorRank = $this->roleRank($actor->primaryRole());

        // Solo rangos estrictamente menores: sin pares ni superiores.
        if ($actorRank === null || $rank >= $actorRank) {
            return false;
        }

        return $this->bindingWithinScope($actor, $data, null);
    }

    /**
     * ¿El actor puede asignar este rol/vínculos a un usuario (nuevo o
     * existente)? Impide autopromoción, pares, superiores y mover a un
     * usuario fuera del subárbol del actor.
     */
    public function canAssign(User $actor, string $role, array $data, ?User $target = null): bool
    {
        $rank = $this->roleRank($role);

        if ($rank === null) {
            return false;
        }

        // Un super_master nunca cambia de rol.
        if ($target !== null && $target->hasRole('super_master')) {
            return false;
        }

        $actorRank = $this->roleRank($actor->primaryRole());

        // Sin rol igual o superior al del actor.
        if ($actorRank === null || $rank >= $actorRank) {
            return false;
        }

        return $this->bindingWithinScope($actor, $data, $target);
    }

    /**
     * Alcance común de view/update/delete sobre un usuario existente.
     */
    private function canManageTarget(User $actor, User $target): bool
    {
        $actorRank = $this->roleRank($actor->primaryRole());
        $targetRank = $this->roleRank($target->primaryRole());

        if ($actorRank === null || $targetRank === null) {
            return false;
        }

        // Solo rangos estrictamente menores: pares y superiores prohibidos.
        if ($targetRank >= $actorRank) {
            return false;
        }

        // Nunca se gestiona al propio creador.
        if ((int) $target->id === (int) $actor->created_by) {
            return false;
        }

        // Subárbol de la entidad del actor o un usuario creado por él.
        return $this->isWithinSubtree($actor, $target)
            || (int) $target->created_by === (int) $actor->id;
    }

    /**
     * ¿El usuario cuelga del subárbol de la entidad del actor?
     */
    private function isWithinSubtree(User $actor, User $target): bool
    {
        if ($actor->hasRole('master')) {
            $bancaId = $this->bancaIdOf($target);

            return $bancaId !== null && $actor->masterCanAccessBanca($bancaId);
        }

        if ($actor->hasRole('banca')) {
            return $actor->banca_id !== null
                && $this->bancaIdOf($target) === (int) $actor->banca_id;
        }

        if ($actor->hasRole('grupo')) {
            return $actor->grupo_id !== null
                && $this->grupoIdOf($target) === (int) $actor->grupo_id;
        }

        if ($actor->hasRole('agencia')) {
            return $actor->agencia_id !== null
                && $this->agenciaIdOf($target) === (int) $actor->agencia_id;
        }

        // Taquilla no administra subárbol alguno.
        return false;
    }

    /**
     * ¿Los vínculos resultantes caen dentro del subárbol del actor?
     */
    private function bindingWithinScope(User $actor, array $data, ?User $target): bool
    {
        if ($this->isSuperMaster($actor)) {
            return true;
        }

        $bancaId = $this->bindingValue($data, 'banca_id', $target, fn (User $u) => $this->bancaIdOf($u));
        $grupoId = $this->bindingValue($data, 'grupo_id', $target, fn (User $u) => $this->grupoIdOf($u));
        $agenciaId = $this->bindingValue($data, 'agencia_id', $target, fn (User $u) => $this->agenciaIdOf($u));

        if ($actor->hasRole('master')) {
            return $bancaId !== null && $actor->masterCanAccessBanca((int) $bancaId);
        }

        if ($actor->hasRole('banca')) {
            return $actor->banca_id !== null && $bancaId === (int) $actor->banca_id;
        }

        if ($actor->hasRole('grupo')) {
            return $actor->grupo_id !== null && $grupoId === (int) $actor->grupo_id;
        }

        if ($actor->hasRole('agencia')) {
            return $actor->agencia_id !== null && $agenciaId === (int) $actor->agencia_id;
        }

        return false;
    }

    /**
     * Valor efectivo de un vínculo: el enviado si la clave existe, si no el
     * del usuario existente (resuelto por cadena).
     */
    private function bindingValue(array $data, string $key, ?User $target, callable $resolver): ?int
    {
        if (array_key_exists($key, $data)) {
            return $data[$key] !== null ? (int) $data[$key] : null;
        }

        return $target !== null ? $resolver($target) : null;
    }

    private function bancaIdOf(User $user): ?int
    {
        if ($user->banca_id !== null) {
            return (int) $user->banca_id;
        }

        if ($user->grupo_id !== null) {
            $bancaId = $user->grupo?->banca_id;

            return $bancaId !== null ? (int) $bancaId : null;
        }

        if ($user->agencia_id !== null) {
            $bancaId = $user->agencia?->grupo?->banca_id;

            return $bancaId !== null ? (int) $bancaId : null;
        }

        if ($user->taquilla_id !== null) {
            $bancaId = $user->taquilla?->grupo?->banca_id;

            return $bancaId !== null ? (int) $bancaId : null;
        }

        return null;
    }

    private function grupoIdOf(User $user): ?int
    {
        if ($user->grupo_id !== null) {
            return (int) $user->grupo_id;
        }

        if ($user->agencia_id !== null) {
            $grupoId = $user->agencia?->grupo_id;

            return $grupoId !== null ? (int) $grupoId : null;
        }

        if ($user->taquilla_id !== null) {
            $grupoId = $user->taquilla?->grupo_id;

            return $grupoId !== null ? (int) $grupoId : null;
        }

        return null;
    }

    private function agenciaIdOf(User $user): ?int
    {
        if ($user->agencia_id !== null) {
            return (int) $user->agencia_id;
        }

        if ($user->taquilla_id !== null) {
            $agenciaId = $user->taquilla?->agencia_id;

            return $agenciaId !== null ? (int) $agenciaId : null;
        }

        return null;
    }

    private function roleRank(?string $role): ?int
    {
        if ($role === null) {
            return null;
        }

        return self::RANKS[$role] ?? null;
    }

    private function isSuperMaster(User $user): bool
    {
        return $user->hasRole('super_master');
    }
}
