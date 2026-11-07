<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Juego;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use SplFileInfo;
use Tests\TestCase;

/**
 * Contrato del catálogo de permisos por capability (BE-04, WU-1): catálogo de
 * 36 permisos guard `api`, matriz capability × rol, bypass acotado de
 * `super_master` y prohibición de permisos directos a usuarios.
 */
class PermissionCatalogTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private const CATALOGO = [
        // Usuarios, bancas, grupos y agencias
        'view_users', 'create_users', 'edit_users', 'delete_users',
        'view_bancas', 'manage_bancas', 'view_grupos', 'manage_grupos',
        'view_agencias', 'manage_agencias',
        // Taquillas, juegos, resultados, apuestas, pagos y cierres
        'view_taquillas', 'manage_taquillas', 'view_juegos', 'manage_juegos',
        'view_resultados', 'manage_resultados',
        'view_apuestas', 'create_apuesta', 'delete_apuesta',
        'view_pagos', 'create_pago', 'view_cierre', 'create_cierre',
        // Límites, comisiones, exchange, reportes, logs y releases
        'view_limites', 'manage_limites', 'view_comisiones', 'manage_comisiones',
        'view_exchange_rates', 'manage_exchange_rates', 'view_reports',
        'view_logs', 'view_audit', 'export_audit',
        'download_release', 'manage_clave_cierre', 'manage_config',
    ];

    /** @var array<string, int> */
    private const CONTEOS = [
        'super_master' => 0,
        'master' => 35,
        'banca' => 24,
        'grupo' => 21,
        'agencia' => 17,
        'taquilla' => 12,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_catalogo_exacto_de_36_permisos_con_guard_api(): void
    {
        $this->assertCount(36, self::CATALOGO);

        $actuales = Permission::query()->where('guard_name', 'api')->pluck('name')->all();
        $esperados = self::CATALOGO;
        sort($actuales);
        sort($esperados);

        $this->assertSame($esperados, $actuales, 'El seeder debe sembrar exactamente el catálogo de 36 permisos.');

        $this->assertSame(
            ['api'],
            Permission::query()->whereIn('name', self::CATALOGO)->distinct()->pluck('guard_name')->all(),
            'Todos los permisos del catálogo deben tener guard `api`.',
        );

        $this->assertSame(
            0,
            Permission::query()->whereIn('name', ['manage_users', 'manage_apuestas'])->count(),
            '`manage_users` y `manage_apuestas` no deben existir en el catálogo.',
        );
    }

    public function test_el_seeder_emite_permission_catalog_synced(): void
    {
        $event = AuditEvent::query()->where('action', 'permission.catalog_synced')->sole();

        $this->assertSame('system', $event->actor_type);
        $this->assertSame('seeder:RolesAndPermissionsSeeder', $event->actor_label);
        $this->assertSame('permission_catalog', $event->entity_type);
        $this->assertSame('seeder', $event->context['source']);
        $this->assertSame('api', $event->context['guard']);
        $this->assertSame(36, $event->context['permissions']);
        $this->assertEquals([
            'master' => 35,
            'banca' => 24,
            'grupo' => 21,
            'agencia' => 17,
            'taquilla' => 12,
            'super_master' => 0,
        ], $event->context['roles']);
        $this->assertSame('unspecified', $event->reason_code);
    }

    /**
     * @param  array<int, string>  $esperados
     */
    #[DataProvider('rolesYMatriz')]
    public function test_matriz_exacta_por_rol(string $rol, int $conteo, array $esperados): void
    {
        $this->assertCount($conteo, $esperados, "La matriz de referencia de {$rol} debe declarar {$conteo} permisos.");

        $role = Role::query()->where('name', $rol)->where('guard_name', 'api')->firstOrFail();

        $actuales = $role->permissions->pluck('name')->all();
        sort($actuales);
        sort($esperados);

        $this->assertCount($conteo, $actuales, "El rol {$rol} debe tener {$conteo} permisos asignados.");
        $this->assertSame($esperados, $actuales, "La matriz del rol {$rol} no coincide con el design.");
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: array<int, string>}>
     */
    public static function rolesYMatriz(): array
    {
        return [
            'super_master' => ['super_master', 0, []],
            'master' => ['master', 35, [
                'view_users', 'create_users', 'edit_users', 'delete_users',
                'view_bancas', 'manage_bancas', 'view_grupos', 'manage_grupos',
                'view_agencias', 'manage_agencias', 'view_taquillas', 'manage_taquillas',
                'view_juegos', 'manage_juegos', 'view_resultados', 'manage_resultados',
                'view_apuestas', 'create_apuesta', 'delete_apuesta', 'view_pagos',
                'create_pago', 'view_cierre', 'create_cierre', 'view_limites',
                'manage_limites', 'view_comisiones', 'view_exchange_rates',
                'manage_exchange_rates', 'view_reports', 'view_logs', 'view_audit',
                'export_audit', 'download_release', 'manage_clave_cierre', 'manage_config',
            ]],
            'banca' => ['banca', 24, [
                'view_users', 'create_users', 'edit_users', 'delete_users',
                'view_grupos', 'manage_grupos', 'view_agencias', 'manage_agencias',
                'view_taquillas', 'manage_taquillas', 'view_juegos', 'view_resultados',
                'view_apuestas', 'create_apuesta', 'delete_apuesta', 'view_pagos',
                'create_pago', 'view_cierre', 'create_cierre', 'view_limites',
                'manage_limites', 'view_reports', 'download_release', 'manage_clave_cierre',
            ]],
            'grupo' => ['grupo', 21, [
                'view_users', 'create_users', 'edit_users', 'delete_users',
                'view_agencias', 'manage_agencias', 'view_taquillas', 'manage_taquillas',
                'view_juegos', 'view_resultados', 'view_apuestas', 'create_apuesta',
                'delete_apuesta', 'view_pagos', 'create_pago', 'view_cierre',
                'create_cierre', 'view_limites', 'manage_limites', 'view_reports',
                'download_release',
            ]],
            'agencia' => ['agencia', 17, [
                'view_users', 'create_users', 'edit_users', 'delete_users',
                'view_agencias', 'manage_agencias', 'view_taquillas', 'manage_taquillas',
                'view_juegos', 'view_resultados', 'view_apuestas', 'delete_apuesta',
                'view_cierre', 'create_cierre', 'view_limites', 'view_reports',
                'download_release',
            ]],
            'taquilla' => ['taquilla', 12, [
                'view_users', 'view_juegos', 'view_resultados', 'view_apuestas',
                'create_apuesta', 'delete_apuesta', 'view_pagos', 'create_pago',
                'view_cierre', 'create_cierre', 'view_reports', 'download_release',
            ]],
        ];
    }

    public function test_super_master_sin_permisos_pasa_el_gate_por_bypass(): void
    {
        $super = User::factory()->create();
        $super->assignRole('super_master');

        $this->assertTrue(
            $super->getAllPermissions()->isEmpty(),
            '`super_master` no debe depender de filas: el bypass vive en `Gate::before`.',
        );

        $this->assertTrue(Gate::forUser($super)->allows('view_users'));
        $this->assertTrue(Gate::forUser($super)->allows('manage_comisiones'));
        $this->assertFalse(Gate::forUser($super)->denies('view_users'));
    }

    public function test_bypass_no_altera_abilities_que_no_son_permisos(): void
    {
        $super = User::factory()->create();
        $super->assignRole('super_master');

        $otroSuper = User::factory()->create();
        $otroSuper->assignRole('super_master');

        // `delete` no es un permiso (lo es `delete_users`): la UserPolicy decide.
        $this->assertTrue(
            Gate::forUser($super)->denies('delete', $otroSuper),
            'El bypass no debe anular la UserPolicy para abilities que no son permisos.',
        );

        Gate::define('view', fn () => false);

        $this->assertFalse(
            Gate::forUser($super)->allows('view', new Juego),
            'El bypass no debe autorizar abilities ajenas al catálogo de permisos.',
        );
    }

    public function test_ningun_archivo_de_app_asigna_permisos_directos_a_usuarios(): void
    {
        $patron = '/(?:\$user(?:_[A-Za-z0-9_]+)?|User::|->user)[^;\n]*->(?:givePermissionTo|syncPermissions)\s*\(/';

        // Control positivo: el patrón detecta la forma que se quiere prohibir.
        $this->assertMatchesRegularExpression($patron, '$user->givePermissionTo("view_users");');

        $infractores = [];

        foreach ($this->archivosPhp(app_path()) as $archivo) {
            $contenido = file_get_contents($archivo);

            if ($contenido !== false && preg_match($patron, $contenido) === 1) {
                $infractores[] = $this->rutaRelativa($archivo);
            }
        }

        sort($infractores);

        $this->assertSame(
            [],
            $infractores,
            'Los permisos se asignan solo a roles; asignación directa a usuarios en: '
            .(implode(', ', $infractores) ?: '(ninguno)'),
        );
    }

    /** @return array<int, string> */
    private function archivosPhp(string $directorio): array
    {
        $archivos = [];

        $iterador = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directorio, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $archivo */
        foreach ($iterador as $archivo) {
            if ($archivo->isFile() && $archivo->getExtension() === 'php') {
                $archivos[] = $archivo->getPathname();
            }
        }

        return $archivos;
    }

    private function rutaRelativa(string $ruta): string
    {
        return str_replace('\\', '/', substr($ruta, strlen(base_path()) + 1));
    }
}
