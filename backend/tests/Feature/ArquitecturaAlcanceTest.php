<?php

namespace Tests\Feature;

use App\Support\Scope\AlcanceService;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Tests\TestCase;

/**
 * Invariantes arquitectónicas del alcance jerárquico (issue #85).
 *
 * Scan estático conservador: no valida comportamiento, sino que la jerarquía
 * `taquilla → grupo → banca` y `taquilla → agencia → grupo → banca` siga
 * centralizada en `App\Support\Scope\AlcanceService` y que ningún controller
 * vuelva a dispersar `masterBancaIds()`.
 *
 * No prohíbe `hasRole`/`hasAnyRole` en controllers: la autorización de acción
 * puntual es legítima (ver docs/dev/alcance-jerarquico.md §7).
 */
class ArquitecturaAlcanceTest extends TestCase
{
    /**
     * Archivos donde está permitido invocar `->masterBancaIds()`.
     *
     * `User` la define y la usa en sus propios helpers; `AlcanceService` es el
     * único consumidor externo. Cualquier otro archivo es dispersión.
     *
     * @var array<int, string>
     */
    private const ARCHIVOS_CON_LLAMADAS_A_MASTER_BANCA_IDS = [
        'app/Models/User.php',
        'app/Support/Scope/AlcanceService.php',
    ];

    /**
     * Archivos donde está permitido invocar los helpers de master de `User`.
     *
     * @var array<int, string>
     */
    private const ARCHIVOS_CON_HELPERS_DE_MASTER = [
        'app/Support/Scope/AlcanceService.php',
    ];

    public function test_alcance_service_existe_y_es_final(): void
    {
        $this->assertFileExists(app_path('Support/Scope/AlcanceService.php'));

        $reflexion = new ReflectionClass(AlcanceService::class);

        $this->assertTrue(
            $reflexion->isFinal(),
            'AlcanceService debe ser final: es la única implementación del alcance jerárquico.',
        );
    }

    public function test_ningun_controller_usa_master_banca_ids(): void
    {
        $infractores = $this->archivosQueContienen(app_path('Http/Controllers'), 'masterBancaIds');

        $this->assertSame(
            [],
            $infractores,
            'Ningún controller debe usar masterBancaIds(); delega en AlcanceService: '.implode(', ', $infractores),
        );
    }

    public function test_master_banca_ids_tiene_una_sola_definicion_en_user(): void
    {
        $archivos = $this->archivosQueContienen(app_path(), 'function masterBancaIds');

        $this->assertSame(
            ['app/Models/User.php'],
            $archivos,
            'Debe existir una sola definición de masterBancaIds() y debe vivir en User.',
        );
    }

    public function test_master_banca_ids_solo_se_invoca_desde_la_capa_de_alcance(): void
    {
        $archivos = $this->archivosQueContienen(app_path(), '->masterBancaIds()');

        $this->assertSame(
            self::ARCHIVOS_CON_LLAMADAS_A_MASTER_BANCA_IDS,
            $archivos,
            'masterBancaIds() solo puede invocarse desde User y AlcanceService; '
            .'un tercer archivo vuelve a dispersar la jerarquía.',
        );
    }

    public function test_los_helpers_de_master_solo_se_invocan_desde_el_servicio(): void
    {
        $llamadas = [
            '->masterBancaScope(',
            '->masterBancaChainScope(',
            '->masterBancaGroupScope(',
        ];

        foreach ($llamadas as $llamada) {
            $archivos = array_values(array_diff(
                $this->archivosQueContienen(app_path(), $llamada),
                ['app/Models/User.php'],
            ));

            $this->assertSame(
                self::ARCHIVOS_CON_HELPERS_DE_MASTER,
                $archivos,
                sprintf(
                    'El helper %s solo puede invocarse desde AlcanceService; encontrado en: %s',
                    $llamada,
                    implode(', ', $archivos) ?: '(ninguno)',
                ),
            );
        }
    }

    /**
     * Archivos PHP que contienen el fragmento indicado.
     *
     * @return array<int, string> rutas relativas a la raíz del repo, ordenadas
     */
    private function archivosQueContienen(string $directorio, string $aguja): array
    {
        $encontrados = [];

        foreach ($this->archivosPhp($directorio) as $ruta) {
            $contenido = file_get_contents($ruta);

            if ($contenido !== false && str_contains($contenido, $aguja)) {
                $encontrados[] = $this->rutaRelativa($ruta);
            }
        }

        sort($encontrados);

        return $encontrados;
    }

    /**
     * @return array<int, string>
     */
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
