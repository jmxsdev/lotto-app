<?php

namespace Database\Seeders;

use App\Models\Master;
use App\Models\SuperMaster;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Crea las entidades raíz de la jerarquía: una fila `super_masters` por cada
 * usuario super_master y una fila `masters` por cada usuario master.
 *
 * Los códigos son deterministas (`SM-{user_id}`, `M-{user_id}`) para que
 * `OperadoresSeeder` pueda resolver el vínculo sin compartir estado en memoria.
 */
class EntidadesRaizSeeder extends Seeder
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

        $super = SuperMaster::updateOrCreate(
            ['code' => 'SM-'.$superUser->id],
            [
                'name' => $superUser->name,
                'active' => true,
                'created_by' => null,
            ],
        );

        foreach ($users as $user) {
            if ($user->is($superUser) || $user->primaryRole() !== 'master') {
                continue;
            }

            Master::updateOrCreate(
                [
                    'super_master_id' => $super->id,
                    'code' => 'M-'.$user->id,
                ],
                [
                    'name' => $user->name,
                    'active' => true,
                    'created_by' => $superUser->id,
                ],
            );
        }
    }
}
