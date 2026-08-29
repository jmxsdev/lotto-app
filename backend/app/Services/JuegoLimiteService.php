<?php

namespace App\Services;

use App\Models\Grupo;
use App\Models\JuegoLimite;
use App\Models\Taquilla;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Lógica compartida de persistencia de límites (juego × moneda) para la
 * jerarquía banca → grupo → taquilla.
 *
 * - JuegoController (updateLimites/batchLimites) la usa para los upserts
 *   individuales y masivos.
 * - Los stores de Banca/Grupo/Taquilla la usan para persistir los límites
 *   iniciales en la MISMA transacción que la creación de la entidad
 *   (create mode del panel: configurar límites desde el inicio).
 *
 * Semántica present-fields-only: solo se escriben los campos presentes en
 * el payload. Si algún campo de límite llega explícitamente null, se
 * ELIMINA la fila (volver a heredar del padre).
 */
class JuegoLimiteService
{
    private const CAMPOS = ['limite_minimo', 'limite_maximo', 'porcentaje_pago', 'participacion', 'fraccion', 'limite_tiempo'];

    /**
     * Validar los ítems de límites recibidos (lanza ValidationException → 422).
     * Mismas reglas que POST /api/limites/batch.
     */
    public function validarItems(array $items): void
    {
        Validator::make(
            ['limites' => $items],
            [
                'limites' => ['required', 'array', 'min:1'],
                'limites.*.juego_id' => ['required', 'exists:juegos,id'],
                'limites.*.moneda' => ['required', Rule::in(['bs', 'usd'])],
                'limites.*.limite_minimo' => ['nullable', 'numeric', 'min:0'],
                'limites.*.limite_maximo' => ['nullable', 'numeric', 'min:0'],
                'limites.*.porcentaje_pago' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'limites.*.participacion' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'limites.*.fraccion' => ['boolean'],
                'limites.*.limite_tiempo' => ['nullable', 'integer', 'min:1'],
            ]
        )->validate();
    }

    /**
     * Persistir los límites iniciales de una entidad recién creada, en la
     * misma transacción del store que la invoca.
     *
     * Los ítems NO traen entidades explícitas (banca_id/grupo_id/taquilla_id):
     * la cadena se resuelve desde la entidad (`$tipo` + `$entidadId`) y la
     * restrictividad contra el padre se valida igual que en batchLimites.
     *
     * @param  string  $tipo  'banca' | 'grupo' | 'taquilla'
     * @return array<int, JuegoLimite>
     */
    public function persistirParaEntidad(array $items, string $tipo, int $entidadId): array
    {
        foreach ($items as $item) {
            if (isset($item['banca_id']) || isset($item['grupo_id']) || isset($item['taquilla_id'])) {
                abort(422, 'Al crear con límites, los ítems no deben incluir banca_id, grupo_id ni taquilla_id.');
            }
        }

        $objetivo = ['nivel' => $tipo, 'id' => $entidadId];
        $resultados = [];

        foreach ($items as $item) {
            $item['juego_id'] = (int) $item['juego_id'];
            $this->aplicarItemLimite($item, $objetivo, $resultados);
        }

        return $resultados;
    }

    /**
     * Aplicar un ítem de límite a una entidad objetivo (o a la entidad
     * explícita del ítem en modo legacy).
     *
     * Semántica present-fields-only: solo se escriben los campos presentes
     * en el payload. Si algún campo de límite llega explícitamente null,
     * se ELIMINA la fila (volver a heredar del padre).
     */
    public function aplicarItemLimite(array $item, ?array $objetivo, array &$resultados): void
    {
        // Resolver la cadena de la entidad objetivo
        [$bancaId, $grupoId, $taquillaId] = $this->resolverCadenaObjetivo($objetivo, $item);

        $presentes = [];

        foreach (self::CAMPOS as $campo) {
            if (array_key_exists($campo, $item)) {
                $presentes[$campo] = $item[$campo];
            }
        }

        $clave = [
            'juego_id' => $item['juego_id'],
            'banca_id' => $bancaId,
            'grupo_id' => $grupoId,
            'taquilla_id' => $taquillaId,
            'moneda' => $item['moneda'],
        ];

        // Null explícito en cualquier campo de límite → eliminar fila (heredar)
        $tieneNull = false;
        foreach ($presentes as $valor) {
            if ($valor === null) {
                $tieneNull = true;
                break;
            }
        }

        if ($tieneNull) {
            JuegoLimite::where($clave)->delete();

            return;
        }

        if (empty($presentes)) {
            abort(422, 'Cada ítem de límite debe incluir al menos un campo configurable.');
        }

        // Validar jerarquía (hijo ≤ padre) antes de escribir
        if ($grupoId !== null || $taquillaId !== null) {
            $this->validarRestrictividadLimite(
                $bancaId,
                $item['juego_id'],
                $item['moneda'],
                $grupoId,
                $taquillaId,
                $presentes['limite_minimo'] ?? null,
                $presentes['limite_maximo'] ?? null,
            );
        }

        $resultados[] = JuegoLimite::updateOrCreate($clave, $presentes);
    }

    /**
     * Validar que un límite hijo no sea más permisivo que el padre.
     * Aplica cuando se configura un límite a nivel grupo o taquilla.
     */
    public function validarRestrictividadLimite(
        int $bancaId,
        int $juegoId,
        string $moneda,
        ?int $grupoId,
        ?int $taquillaId,
        $limiteMinimo,
        $limiteMaximo,
    ): void {
        // Determinar el nivel padre
        $parentQuery = JuegoLimite::where('juego_id', $juegoId)
            ->where('banca_id', $bancaId)
            ->where('moneda', $moneda);

        if ($taquillaId) {
            // Padre es el límite del grupo (o banca si no hay grupo)
            $parentQuery->where(function ($q) use ($grupoId) {
                $q->where('grupo_id', $grupoId)->whereNull('taquilla_id');
            });
            if (! $parentQuery->exists()) {
                // Fallback a banca
                $parentQuery = JuegoLimite::where('juego_id', $juegoId)
                    ->where('banca_id', $bancaId)
                    ->where('moneda', $moneda)
                    ->whereNull('grupo_id')
                    ->whereNull('taquilla_id');
            }
        } elseif ($grupoId) {
            // Padre es el límite de la banca
            $parentQuery->whereNull('grupo_id')->whereNull('taquilla_id');
        } else {
            // Es nivel banca, no hay padre que validar
            return;
        }

        $parent = $parentQuery->first();

        if (! $parent) {
            return; // Sin límite padre, no hay restricción que validar
        }

        // validar limite_maximo: hijo ≤ padre
        if ($limiteMaximo !== null && $parent->limite_maximo !== null) {
            if ($limiteMaximo > $parent->limite_maximo) {
                abort(422, "El límite máximo ({$limiteMaximo}) no puede ser mayor que el límite del nivel superior ({$parent->limite_maximo}).");
            }
        }

        // validar limite_minimo: hijo ≥ padre
        if ($limiteMinimo !== null && $parent->limite_minimo !== null) {
            if ($limiteMinimo < $parent->limite_minimo) {
                abort(422, "El límite mínimo ({$limiteMinimo}) no puede ser menor que el límite del nivel superior ({$parent->limite_minimo}).");
            }
        }
    }

    /**
     * Resolver la cadena banca→grupo→taquilla de un objetivo de alcance.
     * En modo legacy el ítem ya trae su banca_id (y opcional grupo/taquilla).
     *
     * @return array{0: int, 1: int|null, 2: int|null}
     */
    private function resolverCadenaObjetivo(?array $objetivo, array $item): array
    {
        if ($objetivo === null) {
            return [
                (int) $item['banca_id'],
                ! empty($item['grupo_id']) ? (int) $item['grupo_id'] : null,
                ! empty($item['taquilla_id']) ? (int) $item['taquilla_id'] : null,
            ];
        }

        if ($objetivo['nivel'] === 'banca') {
            return [$objetivo['id'], null, null];
        }

        if ($objetivo['nivel'] === 'grupo') {
            $grupo = Grupo::find($objetivo['id']);

            return [$grupo ? (int) $grupo->banca_id : 0, $objetivo['id'], null];
        }

        $taquilla = Taquilla::with('grupo')->find($objetivo['id']);

        return [
            $taquilla?->grupo?->banca_id ?? 0,
            $taquilla?->grupo_id,
            $objetivo['id'],
        ];
    }
}
