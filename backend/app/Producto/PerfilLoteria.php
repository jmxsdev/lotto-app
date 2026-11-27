<?php

namespace App\Producto;

use Lotto\Kernel\Autorizacion\Domain\CatalogoCapacidades;
use Lotto\Kernel\Autorizacion\Domain\MatrizRolCapacidad;
use Lotto\Kernel\Autorizacion\Domain\PerfilProducto;
use Lotto\Kernel\Jerarquia\Domain\CatalogoNiveles;

/**
 * Perfil del producto lotería.
 *
 * Conserva exactamente los 36 permisos y la matriz actual del
 * RolesAndPermissionsSeeder. La matriz es la fuente única del seeder; el
 * perfil solo la representa fuera del framework.
 */
final class PerfilLoteria
{
    public const PERMISOS = [
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
        'view_comisiones', 'manage_comisiones',
        'view_exchange_rates', 'manage_exchange_rates',
        'view_reports',
        'view_logs',
        'view_audit', 'export_audit',
        'download_release',
        'manage_clave_cierre',
        'manage_config',
    ];

    public const MATRIZ = [
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
            'view_audit', 'export_audit',
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

    public static function crear(): PerfilProducto
    {
        $capacidades = CatalogoCapacidades::desde(self::PERMISOS);

        return new PerfilProducto(
            codigo: 'loteria',
            niveles: CatalogoNiveles::seisNiveles(),
            capacidades: $capacidades,
            matriz: MatrizRolCapacidad::desde($capacidades, self::MATRIZ),
            superMasterBypass: true,
        );
    }
}
