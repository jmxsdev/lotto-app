<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Juego;
use App\Models\JuegoAuditoria;
use App\Models\JuegoLimite;
use App\Models\PluginJuego;
use App\Services\JuegoPluginManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JuegoController extends Controller
{
    public function index()
    {
        $juegos = Juego::with('pluginJuego')->get();
        return response()->json($juegos);
    }

    public function show(Juego $juego)
    {
        return response()->json($juego->load('pluginJuego', 'auditoria.user'));
    }

    public function toggle(Request $request, Juego $juego)
    {
        $request->validate([
            'active' => 'required|boolean',
        ]);

        $user = $request->user();
        $oldActive = $juego->active;
        $newActive = $request->active;

        $juego->update([
            'active' => $newActive,
            'updated_by' => $user->id,
        ]);

        if ($juego->pluginJuego) {
            $juego->pluginJuego->update([
                'active' => $newActive,
                'updated_by' => $user->id,
            ]);
        }

        JuegoAuditoria::create([
            'juego_id' => $juego->id,
            'user_id' => $user->id,
            'accion' => $newActive ? 'activar' : 'desactivar',
            'cambios' => [
                'before' => ['active' => $oldActive],
                'after' => ['active' => $newActive],
            ],
        ]);

        return response()->json($juego->load('pluginJuego', 'updatedByUser'));
    }

    public function update(Request $request, Juego $juego)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'config' => 'nullable|array',
        ]);

        $changes = $request->only(['name', 'config']);

        if (empty($changes)) {
            return response()->json($juego->load('pluginJuego'));
        }

        $before = $juego->only(['name', 'config']);
        $juego->update(array_merge($changes, ['updated_by' => $user->id]));
        $after = $juego->only(['name', 'config']);

        JuegoAuditoria::create([
            'juego_id' => $juego->id,
            'user_id' => $user->id,
            'accion' => 'actualizar',
            'cambios' => [
                'before' => $before,
                'after' => $after,
            ],
        ]);

        return response()->json($juego->load('pluginJuego'));
    }

    public function opciones(Juego $juego)
    {
        $opciones = $juego->opciones()->get();

        if ($opciones->isEmpty()) {
            $plugin = app(JuegoPluginManager::class)->getPlugin($juego);
            if ($plugin) {
                return response()->json($plugin->obtenerOpciones());
            }
        }

        return response()->json($opciones);
    }

    public function horarios(Juego $juego)
    {
        $horarios = $juego->horarios()->get();

        if ($horarios->isEmpty()) {
            $plugin = app(JuegoPluginManager::class)->getPlugin($juego);
            if ($plugin) {
                $pluginHorarios = $plugin->obtenerHorarios();
                return response()->json(array_map(fn ($h) => ['hora' => $h], $pluginHorarios));
            }
        }

        return response()->json($horarios);
    }

    public function reglas(Juego $juego)
    {
        $plugin = app(JuegoPluginManager::class)->getPlugin($juego);

        if (!$plugin) {
            return response()->json(['message' => 'No hay plugin registrado para este juego.'], 404);
        }

        $reglas = $plugin->obtenerReglas();
        $modalidades = $plugin->obtenerModalidades();
        $config = $juego->config;
        if (is_array($config) && !empty($config['modalidades_permitidas'])) {
            $modalidades = array_filter($modalidades, fn ($m) => in_array($m['code'], $config['modalidades_permitidas']));
        }
        $reglas['modalidades'] = array_values($modalidades);

        return response()->json($reglas);
    }

    // ==================================================
    // LÍMITES POR JUEGO
    // ==================================================

    /**
     * Listar límites de un juego, filtrados por jerarquía del usuario
     * y opcionalmente por banca, grupo o taquilla.
     * GET /api/limites/{juego}?banca_id=&grupo_id=&taquilla_id=
     */
    public function limites(Request $request, Juego $juego)
    {
        $user = $request->user();

        if (!in_array($user->role, ['super_master', 'master', 'banca', 'grupo'])) {
            return response()->json(['message' => 'No tienes permiso para ver límites.'], 403);
        }

        $filtros = $request->validate([
            'banca_id' => 'nullable|integer|exists:bancas,id',
            'grupo_id' => 'nullable|integer|exists:grupos,id',
            'taquilla_id' => 'nullable|integer|exists:taquillas,id',
        ]);

        $query = JuegoLimite::where('juego_id', $juego->id)
            ->with(['banca', 'grupo', 'taquilla']);

        // Filtrar por jerarquía del usuario
        if ($user->role === 'super_master' || $user->role === 'master') {
            // Sin filtro: ven todos los límites
        } elseif ($user->role === 'banca') {
            $query->where('banca_id', $user->banca_id);
        } elseif ($user->role === 'grupo') {
            $query->where(function ($q) use ($user) {
                $q->where('grupo_id', $user->grupo_id)
                  ->orWhere(function ($q2) use ($user) {
                      $q2->whereNull('grupo_id')->whereNull('taquilla_id')
                         ->whereHas('grupo', fn ($g) => $g->where('banca_id', $user->banca_id));
                  });
            });
        }

        // Filtros explícitos por banca, grupo o taquilla (validados previamente).
        // Se aplican DESPUÉS del alcance jerárquico: intersectan, nunca amplían.
        if (isset($filtros['banca_id'])) {
            $query->where('banca_id', $filtros['banca_id']);
        }

        if (isset($filtros['grupo_id'])) {
            $query->where('grupo_id', $filtros['grupo_id']);
        }

        if (isset($filtros['taquilla_id'])) {
            $query->where('taquilla_id', $filtros['taquilla_id']);
        }

        $limites = $query->get();

        return response()->json($limites);
    }

    /**
     * Upsert de límites para un juego.
     * PUT /api/limites/{juego}
     */
    public function updateLimites(Request $request, Juego $juego)
    {
        $user = $request->user();

        if (!in_array($user->role, ['super_master', 'master', 'banca'])) {
            return response()->json(['message' => 'No tienes permiso para configurar límites.'], 403);
        }

        $request->validate([
            'banca_id' => 'required|exists:bancas,id',
            'grupo_id' => 'nullable|exists:grupos,id',
            'taquilla_id' => 'nullable|exists:taquillas,id',
            'moneda' => ['required', Rule::in(['bs', 'usd'])],
            'limite_minimo' => 'nullable|numeric|min:0',
            'limite_maximo' => 'nullable|numeric|min:0',
            'porcentaje_pago' => 'nullable|numeric|min:0|max:100',
            'participacion' => 'nullable|numeric|min:0|max:100',
            'fraccion' => 'boolean',
            'limite_tiempo' => 'nullable|integer|min:1',
        ]);

        // Validar jerarquía de restricción: hijo ≤ padre
        if ($request->grupo_id || $request->taquilla_id) {
            $this->validarRestrictividadLimite(
                $request->banca_id,
                $request->juego_id ?? $juego->id,
                $request->moneda,
                $request->grupo_id,
                $request->taquilla_id,
                $request->limite_minimo,
                $request->limite_maximo,
            );
        }

        // Verificar acceso a la banca
        $this->authorizeBancaLimitAccess($user, $request->banca_id);

        $limite = JuegoLimite::updateOrCreate(
            [
                'juego_id' => $juego->id,
                'banca_id' => $request->banca_id,
                'grupo_id' => $request->grupo_id,
                'taquilla_id' => $request->taquilla_id,
                'moneda' => $request->moneda,
            ],
            $request->only([
                'limite_minimo', 'limite_maximo', 'porcentaje_pago',
                'participacion', 'fraccion', 'limite_tiempo',
            ])
        );

        $isNew = $limite->wasRecentlyCreated;

        return response()->json($limite, $isNew ? 201 : 200);
    }

    /**
     * Upsert masivo atómico de límites.
     * POST /api/limites/batch
     */
    public function batchLimites(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['super_master', 'master'])) {
            return response()->json(['message' => 'No tienes permiso para configuración masiva de límites.'], 403);
        }

        $request->validate([
            'limites' => 'required|array|min:1',
            'limites.*.juego_id' => 'required|exists:juegos,id',
            'limites.*.banca_id' => 'required|exists:bancas,id',
            'limites.*.grupo_id' => 'nullable|exists:grupos,id',
            'limites.*.taquilla_id' => 'nullable|exists:taquillas,id',
            'limites.*.moneda' => ['required', Rule::in(['bs', 'usd'])],
            'limites.*.limite_minimo' => 'nullable|numeric|min:0',
            'limites.*.limite_maximo' => 'nullable|numeric|min:0',
            'limites.*.porcentaje_pago' => 'nullable|numeric|min:0|max:100',
            'limites.*.participacion' => 'nullable|numeric|min:0|max:100',
            'limites.*.fraccion' => 'boolean',
            'limites.*.limite_tiempo' => 'nullable|integer|min:1',
        ]);

        $resultados = [];

        DB::transaction(function () use ($request, $user, &$resultados) {
            foreach ($request->limites as $item) {
                // Verificar acceso
                $this->authorizeBancaLimitAccess($user, $item['banca_id']);

                $limite = JuegoLimite::updateOrCreate(
                    [
                        'juego_id' => $item['juego_id'],
                        'banca_id' => $item['banca_id'],
                        'grupo_id' => $item['grupo_id'] ?? null,
                        'taquilla_id' => $item['taquilla_id'] ?? null,
                        'moneda' => $item['moneda'],
                    ],
                    [
                        'limite_minimo' => $item['limite_minimo'] ?? null,
                        'limite_maximo' => $item['limite_maximo'] ?? null,
                        'porcentaje_pago' => $item['porcentaje_pago'] ?? null,
                        'participacion' => $item['participacion'] ?? null,
                        'fraccion' => $item['fraccion'] ?? false,
                        'limite_tiempo' => $item['limite_tiempo'] ?? null,
                    ]
                );

                $resultados[] = $limite;
            }
        });

        return response()->json($resultados, 201);
    }

    /**
     * Eliminar un límite configurado.
     * DELETE /api/limites/{limite}
     *
     * super_master/master eliminan cualquier límite; banca solo los de su banca.
     * El modelo se resuelve por binding implícito (404 si no existe).
     */
    public function destroyLimite(Request $request, JuegoLimite $limite)
    {
        $user = $request->user();

        if (!in_array($user->role, ['super_master', 'master', 'banca'])) {
            return response()->json(['message' => 'No tienes permiso para eliminar límites.'], 403);
        }

        if ($user->role === 'banca' && (!$user->banca_id || $user->banca_id != $limite->banca_id)) {
            return response()->json(['message' => 'No tienes acceso a los límites de esta banca.'], 403);
        }

        $limite->delete();

        return response()->json(['message' => 'Límite eliminado correctamente.']);
    }

    // ==================================================
    // MÉTODOS PRIVADOS DE AUTORIZACIÓN Y VALIDACIÓN
    // ==================================================

    /**
     * Solo super_master y master pueden escribir límites.
     */
    private function authorizeLimitesWrite($user): void
    {
        if (!in_array($user->role, ['super_master', 'master'])) {
            abort(403, 'No tienes permiso para configurar límites.');
        }
    }

    /**
     * Verificar que el usuario tenga acceso a la banca.
     */
    private function authorizeBancaLimitAccess($user, int $bancaId): void
    {
        if (in_array($user->role, ['super_master', 'master'])) {
            return;
        }

        if ($user->role === 'banca' && $user->banca_id == $bancaId) {
            return;
        }

        abort(403, 'No tienes acceso a esta banca.');
    }

    /**
     * Validar que un límite hijo no sea más permisivo que el padre.
     * Aplica cuando se configura un límite a nivel grupo o taquilla.
     */
    private function validarRestrictividadLimite(
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
            if (!$parentQuery->exists()) {
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

        if (!$parent) {
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
}
