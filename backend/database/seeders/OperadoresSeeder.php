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
 * Depende de `EntidadesRaizSeeder`: resuelve cada raíz por su código
 * determinista (`SM-{user_id}`) y cada master por `M-{user_id}`. Para banca,
 * grupo, agencia y taquilla usa las proyecciones `users.*_id` del esquema
 * actual.
 */
class OperadoresSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::query()->get();

        $superPorUsuario = [];

        foreach ($users as $user) {
            if ($user->primaryRole() !== 'super_master') {
                continue;
            }

            $super = SuperMaster::query()
                ->where('code', 'SM-'.$user->id)
                ->first();

            if ($super !== null) {
                $superPorUsuario[$user->id] = $super;
            }
        }

        if ($superPorUsuario === []) {
            return;
        }

        foreach ($users as $user) {
            $rol = $user->primaryRole();

            if ($rol === null) {
                continue;
            }

            $entidadId = match ($rol) {
                'super_master' => isset($superPorUsuario[$user->id])
                    ? $superPorUsuario[$user->id]->id
                    : null,
                'master' => Master::query()
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
