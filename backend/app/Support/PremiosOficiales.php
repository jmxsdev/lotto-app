<?php

namespace App\Support;

/**
 * Catálogo único en código de los premios oficiales de los 21 juegos.
 *
 * Fuente autoritativa: tabla de valores oficiales del spec motor-premios
 * (REQ1–REQ16), transpuesta en la sección §3.2 del design (claves canónicas
 * del vocabulario §3.1). Este catálogo alimenta seeders, la migración de
 * backfill y los tests; `config.premios` en cada juego se escribe desde aquí
 * (D2/D4), de modo que motor, BD y tests comparten una sola verdad.
 *
 * Estructura por juego:
 *   'base'        => int, multiplicador del acierto simple
 *   'modalidades' => array<string, int>, clave canónica → multiplicador
 *   'comodines'   => array<string, array>, clave → {tipo, premio_multiplo, ...}
 *   'active'      => bool, false solo para juegos sin fuente oficial (la-ricachona)
 *
 * La Dupleta queda FUERA de alcance (decisión del cliente): no se configura.
 */
class PremiosOficiales
{
    /**
     * @var array<string, array{base?: int, modalidades: array<string, int>, comodines: array<string, array<string, mixed>>, active: bool}>
     */
    private const CATALOGO = [
        'lotto-activo' => [
            'base' => 30,
            'modalidades' => [],
            'comodines' => [],
            'active' => true,
        ],
        'lotto-activo-rd' => [
            'base' => 30,
            'modalidades' => [],
            'comodines' => [],
            'active' => true,
        ],
        'lotto-activo-rep-dom' => [
            'base' => 30,
            'modalidades' => [],
            'comodines' => [],
            'active' => true,
        ],
        'terminal-activo' => [
            'base' => 60,
            'modalidades' => [],
            'comodines' => [],
            'active' => true,
        ],
        'monje-millonario' => [
            'base' => 50,
            'modalidades' => [],
            'comodines' => [
                'patronus-75' => [
                    'tipo' => 'numero',
                    'premio_multiplo' => 120,
                    'numero' => 75,
                    'nombre' => 'Patronus',
                ],
                'patronus-palabra' => [
                    'tipo' => 'palabra',
                    'premio_multiplo' => 20,
                    'acumulativo' => true,
                    'nombre' => 'PATRONUS',
                ],
            ],
            'active' => true,
        ],
        'trio-activo' => [
            'base' => 600,
            'modalidades' => ['terminal' => 60, 'punta' => 60],
            'comodines' => [],
            'active' => true,
        ],
        'triple-zulia' => [
            'base' => 600,
            'modalidades' => ['terminal' => 60, 'signo_triple' => 6000, 'signo_terminal' => 600],
            'comodines' => [],
            'active' => true,
        ],
        'triple-caliente' => [
            'base' => 600,
            'modalidades' => ['terminal' => 60, 'signo_triple' => 6000, 'signo_terminal' => 600],
            'comodines' => [],
            'active' => true,
        ],
        'triple-chance' => [
            'base' => 600,
            'modalidades' => [
                'triple_a_b' => 200000,
                'solo_a_b' => 150,
                'punta' => 60,
                'terminal' => 60,
                'cruzado' => 3000,
                'cruzado_10' => 10,
                'signo_triple' => 6000,
                'signo_terminal' => 600,
                'signo_solo' => 6,
            ],
            'comodines' => [],
            'active' => true,
        ],
        'triple-tachira' => [
            'base' => 500,
            'modalidades' => ['terminal' => 50, 'signo_triple' => 5000],
            'comodines' => [],
            'active' => true,
        ],
        'triple-facil' => [
            'base' => 700,
            'modalidades' => ['terminal' => 60, 'aproximacion' => 10],
            'comodines' => [],
            'active' => true,
        ],
        'triple-zamorano' => [
            'base' => 600,
            'modalidades' => [
                'terminal' => 60,
                'uña' => 5,
                'signo_triple' => 6000,
                'signo_terminal' => 600,
                'signo_uña' => 60,
            ],
            'comodines' => [],
            'active' => true,
        ],
        'el-arrejuntado' => [
            'base' => 40,
            'modalidades' => [
                'triple_a' => 600,
                'triple_b' => 600,
                'signo_triple' => 6000,
                'arrimao' => 6000,
                'pegadito' => 60000,
            ],
            'comodines' => [],
            'active' => true,
        ],
        'cazaloton' => [
            'base' => 30,
            'modalidades' => ['tripleta' => 200],
            'comodines' => [],
            'active' => true,
        ],
        'loto-chaima' => [
            'base' => 40,
            'modalidades' => ['tripleta' => 50],
            'comodines' => [],
            'active' => true,
        ],
        'el-guacharito' => [
            'base' => 70,
            'modalidades' => [],
            'comodines' => [
                'guacharito-99' => [
                    'tipo' => 'numero',
                    'premio_multiplo' => 150,
                    'numero' => 99,
                    'nombre' => 'Guacharito',
                ],
            ],
            'active' => true,
        ],
        'guacharo-activo' => [
            'base' => 60,
            'modalidades' => [],
            'comodines' => [
                'guacharo-75' => [
                    'tipo' => 'numero',
                    'premio_multiplo' => 120,
                    'numero' => 75,
                    'nombre' => 'Guácharo',
                ],
            ],
            'active' => true,
        ],
        'mega-animal-40' => [
            'base' => 30,
            'modalidades' => [],
            'comodines' => [
                'mega' => [
                    'tipo' => 'flag',
                    'premio_multiplo' => 40,
                    'nombre' => 'MEGA',
                ],
            ],
            'active' => true,
        ],
        'selva-plus' => [
            'base' => 80,
            'modalidades' => [],
            'comodines' => [
                'comodin-a' => [
                    'tipo' => 'letra',
                    'premio_multiplo' => 160,
                    'valor' => 'A',
                    'nombre' => 'Leoncito',
                ],
                'comodin-b' => [
                    'tipo' => 'letra',
                    'premio_multiplo' => 200,
                    'valor' => 'B',
                    'nombre' => 'Selva Plus',
                ],
            ],
            'active' => true,
        ],
        'la-granjita' => [
            'base' => 30,
            'modalidades' => [],
            'comodines' => [],
            'active' => true,
        ],
        'la-ricachona' => [
            // Sin fuente oficial con valores: no tiene premios (REQ7) y no se
            // vende ni se liquida (active=false).
            'modalidades' => [],
            'comodines' => [],
            'active' => false,
        ],
    ];

    /**
     * Catálogo completo: slug → configuración de premios.
     *
     * @return array<string, array{base?: int, modalidades: array<string, int>, comodines: array<string, array<string, mixed>>, active: bool}>
     */
    public static function todos(): array
    {
        return self::CATALOGO;
    }

    /**
     * @return array{base?: int, modalidades: array<string, int>, comodines: array<string, array<string, mixed>>, active: bool}|null
     */
    public static function para(string $slug): ?array
    {
        return self::CATALOGO[$slug] ?? null;
    }

    /**
     * @return array<int, string> Slugs de los 21 juegos con catálogo
     */
    public static function slugs(): array
    {
        return array_keys(self::CATALOGO);
    }

    public static function activo(string $slug): bool
    {
        return self::CATALOGO[$slug]['active'] ?? false;
    }
}
