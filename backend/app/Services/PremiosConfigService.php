<?php

namespace App\Services;

use App\Models\Juego;
use App\Models\JuegoAuditoria;
use App\Support\PremiosOficiales;

/**
 * Configuración de premios de un juego (D1/D2/D3, spec configuracion-premios).
 *
 * Concentra la validación de claves de modalidad y la edición atómica de
 * `config.premios`: merge de alto nivel sobre `config` (preserva
 * `scraper`/`modalidades_permitidas`), sincronización de espejos legacy vía
 * `PremiosOficiales::espejosLegacy()` (fuente única) y auditoría
 * `accion=premios` con before/after y `updated_by`. El endpoint queda delgado.
 */
class PremiosConfigService
{
    public function __construct(private JuegoPluginManager $manager) {}

    /**
     * Claves de modalidad válidas para un juego (D3): unión de las claves que
     * expone el plugin (`obtenerModalidades()['code']`) y las del catálogo
     * oficial (`PremiosOficiales::para($slug)['modalidades']`). No bloquea
     * claves canónicas que el plugin no liste hoy.
     *
     * @return array<int, string>
     */
    public function clavesModalidadValidas(Juego $juego): array
    {
        $claves = [];

        $plugin = $this->manager->getPlugin($juego);
        if ($plugin) {
            foreach ($plugin->obtenerModalidades() as $modalidad) {
                if (! empty($modalidad['code'])) {
                    $claves[] = (string) $modalidad['code'];
                }
            }
        }

        $catalogo = PremiosOficiales::para($juego->slug);
        if ($catalogo !== null) {
            $claves = array_merge($claves, array_keys($catalogo['modalidades'] ?? []));
        }

        return array_values(array_unique($claves));
    }

    /**
     * Edita los premios de un juego de forma atómica (D1): el body ES el
     * objeto `premios` completo. Merge de alto nivel sobre `config` (solo toca
     * `premios` y sus espejos), sincroniza los espejos legacy y registra la
     * auditoría `accion=premios` con before/after y `updated_by`.
     *
     * @param  array<string, mixed>  $premios  {base, modalidades, comodines}
     */
    public function actualizar(Juego $juego, array $premios, int $updatedBy): Juego
    {
        $config = $juego->config ?? [];
        $before = $config['premios'] ?? [];

        // Merge de alto nivel: se preservan scraper/modalidades_permitidas/etc.
        $nuevaConfig = array_merge($config, ['premios' => $premios]);

        // Espejos legacy sincronizados desde la fuente única (D2).
        $nuevaConfig = array_merge($nuevaConfig, PremiosOficiales::espejosLegacy($juego->slug, $premios));

        $juego->update([
            'config' => $nuevaConfig,
            'updated_by' => $updatedBy,
        ]);

        JuegoAuditoria::create([
            'juego_id' => $juego->id,
            'user_id' => $updatedBy,
            'accion' => 'premios',
            'cambios' => [
                'before' => $before,
                'after' => $premios,
            ],
        ]);

        return $juego->fresh();
    }
}
