<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fuente única del catálogo de permisos por capability (BE-04).
 *
 * - 34 permisos con guard `api`, alineados con la matriz capability × rol de
 *   `docs/dev/permisos-capabilities.md`.
 * - `super_master` no recibe filas: su bypass vive en `Gate::before`.
 * - `syncPermissions` reemplaza la matriz de cada rol (contrato del seeder);
 *   los permisos retirados se borran explícitamente y cualquier permiso de
 *   terceros ajeno al catálogo se tolera (no se destruyen datos).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Catálogo final de permisos (guard `api`).
     *
     * @var array<int, string>
     */
    public const PERMISSIONS = [
        // Usuarios
        'view_users', 'create_users', 'edit_users', 'delete_users',
        // Bancas
        'view_bancas', 'manage_bancas',
        // Grupos
        'view_grupos', 'manage_grupos',
        // Agencias
        'view_agencias', 'manage_agencias',
        // Taquillas
        'view_taquillas', 'manage_taquillas',
        // Juegos
        'view_juegos', 'manage_juegos',
        // Resultados
        'view_resultados', 'manage_resultados',
        // Apuestas
        'view_apuestas', 'create_apuesta', 'delete_apuesta',
        // Pagos
        'view_pagos', 'create_pago',
        // Cierres
        'view_cierre', 'create_cierre',
        // Límites
        'view_limites', 'manage_limites',
        // Comisiones
        'view_comisiones', 'manage_comisiones',
        // Exchange rates
        'view_exchange_rates', 'manage_exchange_rates',
        // Reportes, logs, releases, clave de cierre y configuración
        'view_reports',
        'view_logs',
        'download_release',
        'manage_clave_cierre',
        'manage_config',
    ];

    /**
     * Matriz capability × rol (design §"Matriz final").
     *
     * `super_master` se resuelve por bypass y queda fuera de la matriz.
     *
     * @var array<string, array<int, string>>
     */
    public const MATRIX = [
        'master' => [
            'view_users', 'create_users', 'edit_users', 'delete_users',
            'view_bancas', 'manage_bancas',
            'view_grupos', 'manage_grupos',
            'view_agencias', 'manage_agencias',
            'view_taquillas', 'manage_taquillas',
            'view_juegos', 'manage_juegos',
            'view_resultados', 'manage_resultados',
            'view_apuestas', 'create_apuesta', 'delete_apuesta',
            'view_pagos', 'create_pago',
            'view_cierre', 'create_cierre',
            'view_limites', 'manage_limites',
            'view_comisiones',
            'view_exchange_rates', 'manage_exchange_rates',
            'view_reports',
            'view_logs',
            'download_release',
            'manage_clave_cierre',
            'manage_config',
        ],
        'banca' => [
            'view_users', 'create_users', 'edit_users', 'delete_users',
            'view_grupos', 'manage_grupos',
            'view_agencias', 'manage_agencias',
            'view_taquillas', 'manage_taquillas',
            'view_juegos',
            'view_resultados',
            'view_apuestas', 'create_apuesta', 'delete_apuesta',
            'view_pagos', 'create_pago',
            'view_cierre', 'create_cierre',
            'view_limites', 'manage_limites',
            'view_reports',
            'download_release',
            'manage_clave_cierre',
        ],
        'grupo' => [
            'view_users', 'create_users', 'edit_users', 'delete_users',
            'view_agencias', 'manage_agencias',
            'view_taquillas', 'manage_taquillas',
            'view_juegos',
            'view_resultados',
            'view_apuestas', 'create_apuesta', 'delete_apuesta',
            'view_pagos', 'create_pago',
            'view_cierre', 'create_cierre',
            'view_limites', 'manage_limites',
            'view_reports',
            'download_release',
        ],
        'agencia' => [
            'view_users', 'create_users', 'edit_users', 'delete_users',
            'view_agencias', 'manage_agencias',
            'view_taquillas', 'manage_taquillas',
            'view_juegos',
            'view_resultados',
            'view_apuestas', 'delete_apuesta',
            'view_cierre', 'create_cierre',
            'view_limites',
            'view_reports',
            'download_release',
        ],
        'taquilla' => [
            'view_users',
            'view_juegos',
            'view_resultados',
            'view_apuestas', 'create_apuesta', 'delete_apuesta',
            'view_pagos', 'create_pago',
            'view_cierre', 'create_cierre',
            'view_reports',
            'download_release',
        ],
    ];

    /**
     * Permisos retirados por BE-04 (se eliminan si existen).
     *
     * @var array<int, string>
     */
    private const RETIRED_PERMISSIONS = [
        'manage_users',
        'manage_apuestas',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        Permission::query()
            ->whereIn('name', self::RETIRED_PERMISSIONS)
            ->where('guard_name', 'api')
            ->delete();

        foreach (self::MATRIX as $roleName => $permissions) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'api'])
                ->syncPermissions($permissions);
        }

        // `super_master` sin permisos asignados: el bypass vive en
        // `AppServiceProvider::boot()` (`Gate::before` acotado al catálogo).
        Role::firstOrCreate(['name' => 'super_master', 'guard_name' => 'api'])
            ->syncPermissions([]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command->info('Roles y permisos creados correctamente.');
    }
}
