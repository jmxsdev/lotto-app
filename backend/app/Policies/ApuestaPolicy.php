<?php

namespace App\Policies;

use App\Models\Apuesta;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ApuestaPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_master', 'master', 'banca', 'grupo', 'taquilla']);
    }

    public function view(User $user, Apuesta $apuesta): bool
    {
        if (in_array($user->role, ['super_master', 'master'])) return true;
        if ($user->role === 'banca') {
            return $apuesta->taquilla->grupo->banca_id === $user->banca_id;
        }
        if ($user->role === 'grupo') {
            return $apuesta->taquilla->grupo_id === $user->grupo_id;
        }
        if ($user->role === 'taquilla') {
            return $apuesta->taquilla_id === $user->taquilla_id;
        }
        return false;
    }

    public function create(User $user): bool
    {
        return $user->role === 'taquilla';
    }

    public function delete(User $user, Apuesta $apuesta): bool
    {
        if ($apuesta->estado !== 'pendiente') {
            return false;
        }

        if ($apuesta->resultado_id !== null) {
            return false;
        }

        if ($apuesta->created_at->diffInMinutes(now()) >= 5) {
            return false;
        }

        if ($user->role === 'taquilla') {
            return $apuesta->taquilla_id === $user->taquilla_id;
        }

        if ($user->role === 'grupo') {
            return $apuesta->taquilla->grupo_id === $user->grupo_id;
        }

        if ($user->role === 'banca') {
            return $apuesta->taquilla->grupo->banca_id === $user->banca_id;
        }

        return in_array($user->role, ['super_master', 'master']);
    }
}
