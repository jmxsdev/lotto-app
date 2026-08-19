<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        // Super Master
        $super = User::create([
            'name' => 'Super Master',
            'email' => 'super@lotto.com',
            'password' => Hash::make(env('SEEDER_PASSWORD', 'password')),
            'role' => 'super_master',
            'active' => true,
        ]);
        $super->assignRole('super_master');

        // Master (ejemplo)
        $master = User::create([
            'name' => 'Master Test',
            'email' => 'master@lotto.com',
            'password' => Hash::make(env('SEEDER_PASSWORD', 'password')),
            'role' => 'master',
            'active' => true,
        ]);
        $master->assignRole('master');

        // Banca (necesitamos una banca existente, la creamos antes)
        $banca = Banca::create([
            'name' => 'Banca Test',
            'code' => 'BT001',
            'created_by' => $super->id,
        ]);
        $bancaUser = User::create([
            'name' => 'Banca User',
            'email' => 'banca@lotto.com',
            'password' => Hash::make(env('SEEDER_PASSWORD', 'password')),
            'role' => 'banca',
            'banca_id' => $banca->id,
            'active' => true,
        ]);
        $bancaUser->assignRole('banca');

        // Grupo (necesitamos un grupo)
        $grupo = Grupo::create([
            'name' => 'Grupo Test',
            'code' => 'GT001',
            'banca_id' => $banca->id,
            'created_by' => $super->id,
        ]);
        $grupoUser = User::create([
            'name' => 'Grupo User',
            'email' => 'grupo@lotto.com',
            'password' => Hash::make(env('SEEDER_PASSWORD', 'password')),
            'role' => 'grupo',
            'banca_id' => $grupo->banca_id,
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);
        $grupoUser->assignRole('grupo');

        // Taquilla normal (requiere activación manual)
        $taquilla = Taquilla::create([
            'name' => 'Taquilla Test',
            'code' => 'TT001',
            'grupo_id' => $grupo->id,
            'activation_code' => 'ABCDE',
            'active' => false,
            'created_by' => $super->id,
        ]);
        $taquillaUser = User::create([
            'name' => 'Taquilla User',
            'email' => 'taquilla@lotto.com',
            'password' => Hash::make(env('SEEDER_PASSWORD', 'password')),
            'role' => 'taquilla',
            'banca_id' => $grupo->banca_id,
            'grupo_id' => $grupo->id,
            'taquilla_id' => $taquilla->id,
            'active' => true,
        ]);
        $taquillaUser->assignRole('taquilla');

        // Taquilla DEMO (pre-activada para web/Vercel)
        $demoTaquilla = Taquilla::create([
            'name' => 'Taquilla Demo',
            'code' => 'DEMO01',
            'grupo_id' => $grupo->id,
            'activation_code' => 'DEMO01',
            'mac_address' => '00:1A:2B:3C:4D:5E',
            'device_fingerprint' => 'demo-device-001',
            'active' => true,
            'created_by' => $super->id,
        ]);
        $demoUser = User::create([
            'name' => 'Demo User',
            'email' => 'demo@lotto.com',
            'password' => Hash::make(env('SEEDER_PASSWORD', 'password')),
            'role' => 'taquilla',
            'banca_id' => $grupo->banca_id,
            'grupo_id' => $grupo->id,
            'taquilla_id' => $demoTaquilla->id,
            'active' => true,
        ]);
        $demoUser->assignRole('taquilla');
    }
}
