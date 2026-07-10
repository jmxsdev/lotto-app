<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        // Super Master
        User::create([
            'name' => 'Super Master',
            'email' => 'super@lotto.com',
            'password' => Hash::make('password'),
            'role' => 'super_master',
            'active' => true,
        ]);

        // Master (ejemplo)
        User::create([
            'name' => 'Master Test',
            'email' => 'master@lotto.com',
            'password' => Hash::make('password'),
            'role' => 'master',
            'active' => true,
        ]);

        // Banca (necesitamos una banca existente, la creamos antes)
        $banca = \App\Models\Banca::create(['name' => 'Banca Test', 'code' => 'BT001']);
        User::create([
            'name' => 'Banca User',
            'email' => 'banca@lotto.com',
            'password' => Hash::make('password'),
            'role' => 'banca',
            'banca_id' => $banca->id,
            'active' => true,
        ]);

        // Grupo (necesitamos un grupo)
        $grupo = \App\Models\Grupo::create(['name' => 'Grupo Test', 'code' => 'GT001', 'banca_id' => $banca->id]);
        User::create([
            'name' => 'Grupo User',
            'email' => 'grupo@lotto.com',
            'password' => Hash::make('password'),
            'role' => 'grupo',
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);

        // Taquilla (necesitamos una taquilla)
        $taquilla = \App\Models\Taquilla::create([
            'name' => 'Taquilla Test',
            'code' => 'TT001',
            'grupo_id' => $grupo->id,
            'activation_code' => 'ABCDE',
            'active' => true,
        ]);
        User::create([
            'name' => 'Taquilla User',
            'email' => 'taquilla@lotto.com',
            'password' => Hash::make('password'),
            'role' => 'taquilla',
            'taquilla_id' => $taquilla->id,
            'active' => true,
        ]);
    }
}
