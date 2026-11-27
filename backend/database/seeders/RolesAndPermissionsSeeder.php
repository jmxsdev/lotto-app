<?php

namespace Database\Seeders;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEventData;
use App\Audit\AuditRecorder;
use App\Producto\PerfilLoteria;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fuente única del catálogo de permisos por capability (BE-04).
 *
 * - 36 permisos con guard `api`, alineados con la matriz capability × rol de
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
    public const PERMISSIONS = PerfilLoteria::PERMISOS;

    /**
     * Matriz capability × rol (design §"Matriz final").
     *
     * `super_master` se resuelve por bypass y queda fuera de la matriz.
     *
     * @var array<string, array<int, string>>
     */
    public const MATRIX = PerfilLoteria::MATRIZ;

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

        $context = app(AuditContext::class);
        $context->reset();
        $context->forSystem('seeder:RolesAndPermissionsSeeder', null, null, 'seeder');

        try {
            app(AuditRecorder::class)->record(
                AuditAction::PermissionCatalogSynced,
                new AuditEventData(
                    action: AuditAction::PermissionCatalogSynced,
                    entityType: 'permission_catalog',
                    context: [
                        'guard' => 'api',
                        'permissions' => count(self::PERMISSIONS),
                        'roles' => array_merge(
                            array_map('count', self::MATRIX),
                            ['super_master' => 0],
                        ),
                    ],
                ),
            );
        } finally {
            $context->reset();
        }

        $this->command->info('Roles y permisos creados correctamente.');
    }
}
