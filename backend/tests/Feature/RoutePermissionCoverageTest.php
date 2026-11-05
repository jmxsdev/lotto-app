<?php

namespace Tests\Feature;

use FilesystemIterator;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Cobertura final de autorización por capability (BE-04, WU-4).
 *
 * - Toda ruta con `auth:sanctum` debe declarar al menos un `permission:`.
 * - `routes/api.php` no debe contener `role:`.
 * - `app/` no asigna permisos directamente a usuarios (solo a roles).
 */
class RoutePermissionCoverageTest extends TestCase
{
    /** Rutas autenticadas que no representan recursos: sesión del usuario. */
    private const ALLOWLIST = [
        'api/v1/user',
        'api/v1/logout',
    ];

    public function test_toda_ruta_autenticada_tiene_middleware_permission(): void
    {
        $sinPermiso = [];
        $autenticadas = 0;

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            $esAutenticada = collect($middleware)->contains(
                fn ($m) => is_string($m) && str_starts_with($m, 'auth:sanctum'),
            );

            if (! $esAutenticada) {
                continue;
            }

            $autenticadas++;

            if (in_array($route->uri(), self::ALLOWLIST, true)) {
                continue;
            }

            $tienePermiso = collect($middleware)->contains(
                fn ($m) => is_string($m) && str_starts_with($m, 'permission:'),
            );

            if (! $tienePermiso) {
                $sinPermiso[] = $route->methods()[0].' '.$route->uri();
            }
        }

        // Guarda anti-vacío: el api.php tiene decenas de rutas autenticadas.
        $this->assertGreaterThan(30, $autenticadas, 'La inspección de rutas autenticadas parece vacía.');

        sort($sinPermiso);

        $this->assertSame(
            [],
            $sinPermiso,
            'Rutas autenticadas sin `permission:` (fail-closed BE-04): '.implode(', ', $sinPermiso),
        );
    }

    public function test_rutas_no_contienen_role_residual(): void
    {
        // `role:` como middleware; `role_or_permission` y `$filtros['role']` no matchean.
        $patron = '/(?<![A-Za-z0-9_])role\s*:/';

        foreach (['routes/api.php'] as $archivo) {
            $contenido = file_get_contents(base_path($archivo));

            $this->assertNotFalse($contenido, "No se pudo leer {$archivo}.");
            $this->assertDoesNotMatchRegularExpression(
                $patron,
                $contenido,
                "{$archivo} todavía contiene middleware `role:`; BE-04 usa solo `permission:`.",
            );
        }
    }

    public function test_app_no_asigna_permisos_directos_a_usuarios(): void
    {
        $patron = '/(?:\$user(?:_[A-Za-z0-9_]+)?|User::|->user)[^;\n]*->(?:givePermissionTo|syncPermissions)\s*\(/';

        // Control positivo: el patrón detecta la forma prohibida.
        $this->assertMatchesRegularExpression($patron, '$user->givePermissionTo("view_users");');

        $infractores = [];

        foreach ($this->archivosPhp(app_path()) as $archivo) {
            $contenido = file_get_contents($archivo);

            if ($contenido !== false && preg_match($patron, $contenido) === 1) {
                $infractores[] = str_replace('\\', '/', substr($archivo, strlen(base_path()) + 1));
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
}
