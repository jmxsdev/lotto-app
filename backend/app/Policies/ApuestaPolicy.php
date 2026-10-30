<?php

namespace App\Policies;

use App\Models\Apuesta;
use App\Models\User;
use App\Services\ApuestaService;

class ApuestaPolicy
{
    /** La apuesta no está en `pendiente`. */
    public const MOTIVO_ESTADO = 'estado';

    /** La apuesta ya tiene un resultado asignado. */
    public const MOTIVO_CON_RESULTADO = 'con_resultado';

    /** El sorteo de la jugada ya ocurrió. */
    public const MOTIVO_SORTEO_OCURRIDO = 'sorteo_ocurrido';

    /** La apuesta superó la ventana efectiva de eliminación. */
    public const MOTIVO_FUERA_DE_VENTANA = 'fuera_de_ventana';

    /** El usuario no pertenece al árbol de la taquilla de la apuesta. */
    public const MOTIVO_SIN_OWNERSHIP = 'sin_ownership';

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_master', 'master', 'banca', 'grupo', 'agencia', 'taquilla']);
    }

    public function view(User $user, Apuesta $apuesta): bool
    {
        if (in_array($user->role, ['super_master', 'master'])) {
            return true;
        }
        if ($user->role === 'banca') {
            return $apuesta->taquilla->grupo->banca_id === $user->banca_id;
        }
        if ($user->role === 'grupo') {
            return $apuesta->taquilla->grupo_id === $user->grupo_id;
        }
        if ($user->role === 'agencia') {
            return $user->agencia_id !== null
                && $apuesta->taquilla->agencia_id === $user->agencia_id;
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
        return $this->motivoNoAnulable($user, $apuesta) === null;
    }

    /**
     * Motivo por el que la apuesta no puede anularse, o `null` si es anulable.
     *
     * Orden de precedencia (sin cambios respecto de `delete()`):
     * `estado → con_resultado → sorteo_ocurrido → fuera_de_ventana → sin_ownership`.
     *
     * @param  int|null  $minutosEfectivos  Ventana ya resuelta por el llamador
     *                                      (evita repetir la consulta de jerarquía).
     * @return string|null Uno de los códigos `MOTIVO_*`, o `null` si es anulable.
     */
    public function motivoNoAnulable(User $user, Apuesta $apuesta, ?int $minutosEfectivos = null): ?string
    {
        if ($apuesta->estado !== 'pendiente') {
            return self::MOTIVO_ESTADO;
        }

        if ($apuesta->resultado_id !== null) {
            return self::MOTIVO_CON_RESULTADO;
        }

        if ($apuesta->sorteo_hora && $apuesta->sorteo_hora->isPast()) {
            return self::MOTIVO_SORTEO_OCURRIDO;
        }

        $tiempo = $minutosEfectivos
            ?? app(ApuestaService::class)->getEffectiveTiempoEliminacion($apuesta->taquilla_id);
        if ($apuesta->created_at->diffInMinutes(now()) >= $tiempo) {
            return self::MOTIVO_FUERA_DE_VENTANA;
        }

        if (! $this->poseeApuesta($user, $apuesta)) {
            return self::MOTIVO_SIN_OWNERSHIP;
        }

        return null;
    }

    /**
     * Ownership del árbol de la taquilla (taquilla → agencia → grupo → banca;
     * `master`/`super_master` sobre cualquiera).
     */
    private function poseeApuesta(User $user, Apuesta $apuesta): bool
    {
        if ($user->role === 'taquilla') {
            return $apuesta->taquilla_id === $user->taquilla_id;
        }

        if ($user->role === 'agencia') {
            return $user->agencia_id !== null
                && $apuesta->taquilla->agencia_id === $user->agencia_id;
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
