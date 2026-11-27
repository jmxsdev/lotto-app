<?php

namespace Database\Seeders;

use App\Models\Master;
use App\Models\Operador;
use App\Models\SuperMaster;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Crea el vínculo operador → entidad para los usuarios ya sembrados.
 *
 * Depende de `EntidadesRaizSeeder`: resuelve las raíces por sus códigos
 * deterministas (`SM-{user_id}`, `M-{user_id}`). Para banca, grupo, agencia y
 * taquilla usa las proyecciones `users.*_id` del esquema actual.
 */
class OperadoresSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::query()->get();

        $superUser = $users->first(
            fn (User $user): bool => $user->primaryRole() === 'super_master',
        );

        if ($superUser === null) {
            return;
        }

        $super = SuperMaster::query()
            ->where('code', 'SM-'.$superUser->id)
            ->first();

        if ($super === null) {
            return;
        }

        foreach ($users as $user) {
            $rol = $user->primaryRole();

            if ($rol === null) {
                continue;
            }

            $entidadId = match ($rol) {
                'super_master' => $super->id,
                'master' => Master::query()
                    ->where('super_master_id', $super->id)
                    ->where('code', 'M-'.$user->id)
                    ->value('id'),
                'banca' => $user->banca_id,
                'grupo' => $user->grupo_id,
                'agencia' => $user->agencia_id,
                'taquilla' => $user->taquilla_id,
                default => null,
            };

            if ($entidadId === null) {
                continue;
            }

            Operador::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'entidad_tipo' => $rol,
                    'entidad_id' => $entidadId,
                    'active' => true,
                ],
            );
        }
    }
}
