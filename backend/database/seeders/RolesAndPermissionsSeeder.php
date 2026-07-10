<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Usuarios
            'manage_users',
            'view_users',
            'create_users',
            'edit_users',
            'delete_users',
            // Bancas
            'manage_bancas',
            'view_bancas',
            // Grupos
            'manage_grupos',
            'view_grupos',
            // Taquillas
            'manage_taquillas',
            'view_taquillas',
            // Juegos
            'manage_juegos',
            // Apuestas
            'create_apuesta',
            'view_apuestas',
            'delete_apuesta',
            // Pagos
            'create_pago',
            'view_pagos',
            // Reportes
            'view_reports',
            'manage_comisiones',
            // Tasas de cambio
            'manage_exchange_rates',
            // Cierres
            'create_cierre',
            'view_cierre',
            // Configuración
            'manage_config',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        // Super Master: todos los permisos
        $roleSuper = Role::firstOrCreate(['name' => 'super_master', 'guard_name' => 'api']);
        $roleSuper->syncPermissions(Permission::all());

        // Master: casi todos (excepto eliminar usuarios y bancas)
        $roleMaster = Role::firstOrCreate(['name' => 'master', 'guard_name' => 'api']);
        $roleMaster->syncPermissions([
            'view_users', 'create_users', 'edit_users',
            'manage_bancas', 'view_bancas',
            'manage_grupos', 'view_grupos',
            'manage_taquillas', 'view_taquillas',
            'manage_juegos',
            'view_apuestas', 'create_apuesta', 'delete_apuesta',
            'create_pago', 'view_pagos',
            'view_reports', 'manage_comisiones',
            'manage_exchange_rates',
            'create_cierre', 'view_cierre',
        ]);

        // Banca: gestiona sus grupos y taquillas
        $roleBanca = Role::firstOrCreate(['name' => 'banca', 'guard_name' => 'api']);
        $roleBanca->syncPermissions([
            'view_grupos', 'manage_grupos',
            'view_taquillas', 'manage_taquillas',
            'view_apuestas', 'create_apuesta', 'delete_apuesta',
            'create_pago', 'view_pagos',
            'view_reports',
            'create_cierre', 'view_cierre',
        ]);

        // Grupo: gestiona sus taquillas
        $roleGrupo = Role::firstOrCreate(['name' => 'grupo', 'guard_name' => 'api']);
        $roleGrupo->syncPermissions([
            'view_taquillas', 'manage_taquillas',
            'view_apuestas', 'create_apuesta', 'delete_apuesta',
            'create_pago', 'view_pagos',
            'view_reports',
            'create_cierre', 'view_cierre',
        ]);

        // Taquilla: solo operaciones básicas
        $roleTaquilla = Role::firstOrCreate(['name' => 'taquilla', 'guard_name' => 'api']);
        $roleTaquilla->syncPermissions([
            'create_apuesta',
            'view_apuestas',
            'delete_apuesta',
            'create_pago',
            'view_pagos',
            'create_cierre',
            'view_cierre',
        ]);

        // Crear usuario Super Master
        $super = User::firstOrCreate(
            ['email' => 'super@lotto.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'role' => 'super_master',
                'active' => true,
            ]
        );
        $super->assignRole('super_master');

        // Crear usuario Master (opcional)
        $master = User::firstOrCreate(
            ['email' => 'master@lotto.com'],
            [
                'name' => 'Master',
                'password' => Hash::make('password'),
                'role' => 'master',
                'active' => true,
            ]
        );
        $master->assignRole('master');

        $this->command->info('Roles, permisos y usuarios creados.');
    }
}
