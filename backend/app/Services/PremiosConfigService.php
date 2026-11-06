<?php

namespace App\Services;

use App\Audit\AuditAction;
use App\Audit\AuditEventData;
use App\Audit\AuditReason;
use App\Audit\AuditRecorder;
use App\Audit\AuditScope;
use App\Models\Juego;
use App\Models\JuegoAuditoria;
use App\Support\PremiosOficiales;
use Illuminate\Support\Facades\DB;

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
    public function __construct(
        private JuegoPluginManager $manager,
        private readonly AuditRecorder $audit,
    ) {}

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
     * La evidencia canónica `game.prizes_updated` es P0 fail-closed y exige
     * motivo: si falta, la transacción revierte premios y auditoría legacy.
     *
     * @param  array<string, mixed>  $premios  {base, modalidades, comodines}
     */
    public function actualizar(
        Juego $juego,
        array $premios,
        int $updatedBy,
        ?string $reasonCode = null,
        ?string $reasonText = null,
    ): Juego {
        $config = $juego->config ?? [];
        $before = $config['premios'] ?? [];

        // Los slots posicionales son catálogo, no configuración editable desde
        // el panel: el controller filtra el payload a base/modalidades/comodines,
        // así que si el body no los trae se conservan los vigentes. Sin esto,
        // editar un premio borraba `posicion_triples` y apagaba la venta
        // posicional del juego.
        $premios['posicion_triples'] ??= $before['posicion_triples'] ?? [];

        // Merge de alto nivel: se preservan scraper/modalidades_permitidas/etc.
        $nuevaConfig = array_merge($config, ['premios' => $premios]);

        // Espejos legacy sincronizados desde la fuente única (D2).
        $nuevaConfig = array_merge($nuevaConfig, PremiosOficiales::espejosLegacy($juego->slug, $premios));

        $reason = $reasonCode !== null ? AuditReason::tryFrom($reasonCode) : null;

        DB::transaction(function () use (
            $juego,
            $premios,
            $updatedBy,
            $before,
            $nuevaConfig,
            $reason,
            $reasonText
        ): void {
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

            $event = (new AuditEventData(
                action: AuditAction::GamePrizesUpdated,
                entityType: 'juego',
                entityId: (int) $juego->id,
                scope: new AuditScope,
                before: ['premios' => $before],
                after: ['premios' => $premios],
            ))->withReason($reason, $reasonText);

            $this->audit->critical(AuditAction::GamePrizesUpdated, $event);
        });

        return $juego->fresh();
    }
}
