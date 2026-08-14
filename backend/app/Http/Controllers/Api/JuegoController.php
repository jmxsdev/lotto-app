<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoAuditoria;
use App\Models\JuegoLimite;
use App\Models\PluginJuego;
use App\Models\Taquilla;
use App\Services\JuegoPluginManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
     * Matriz completa de límites de una entidad (banca, grupo o taquilla).
     * GET /api/limites?banca_id=|grupo_id=|taquilla_id=
     *
     * Devuelve todos los juegos activos y sus límites en UNA sola respuesta,
     * indexados por "juego_id:moneda", sin consultas por juego. Para las
     * celdas sin fila propia se resuelve el origen heredado del nivel
     * superior (taquilla → grupo → banca). El alcance del rol se aplica
     * primero: los filtros intersectan, nunca amplían.
     *
     * Modo scope: GET /api/limites?scope=bancas|grupos|taquillas (XOR con
     * los filtros de entidad) devuelve la matriz de TODAS las entidades del
     * tipo visibles para el rol, indexada por "entidad_id:juego_id:moneda",
     * con `mixto` para marcar juego×moneda donde las entidades difieren en
     * si tienen fila propia. Sin origen en este modo.
     */
    public function listarLimites(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['super_master', 'master', 'banca', 'grupo'])) {
            return response()->json(['message' => 'No tienes permiso para ver límites.'], 403);
        }

        $filtros = $request->validate([
            'banca_id' => 'nullable|integer|exists:bancas,id',
            'grupo_id' => 'nullable|integer|exists:grupos,id',
            'taquilla_id' => 'nullable|integer|exists:taquillas,id',
            'scope' => 'nullable|in:bancas,grupos,taquillas',
        ]);

        $scope = $filtros['scope'] ?? null;

        // XOR: modo scope (un tipo) vs modo entidad (un filtro)
        $entidad = array_filter([
            'banca_id' => $filtros['banca_id'] ?? null,
            'grupo_id' => $filtros['grupo_id'] ?? null,
            'taquilla_id' => $filtros['taquilla_id'] ?? null,
        ], fn ($valor) => $valor !== null);

        if ($scope !== null) {
            if (count($entidad) > 0) {
                abort(422, 'No puede combinar el parámetro scope con banca_id, grupo_id o taquilla_id.');
            }

            return $this->listarLimitesPorScope($user, $scope);
        }

        if (count($entidad) !== 1) {
            abort(422, 'Debe indicar exactamente uno de banca_id, grupo_id o taquilla_id.');
        }

        $tipo = str_replace('_id', '', (string) array_key_first($entidad));
        $entidadId = (int) $entidad[array_key_first($entidad)];

        $juegos = Juego::where('active', true)->orderBy('id')->get(['id', 'name', 'slug']);
        $claves = collect($juegos)
            ->flatMap(fn ($juego) => [$juego->id . ':bs', $juego->id . ':usd'])
            ->all();

        $limites = array_fill_keys($claves, null);
        $origenes = array_fill_keys($claves, null);

        // Alcance del rol primero: fuera de la jerarquía del usuario la
        // intersección es vacía (se responde la matriz sin valores, nunca
        // se amplía el alcance).
        if (!$this->entidadDentroDelAlcance($user, $tipo, $entidadId)) {
            return response()->json([
                'data' => [
                    'juegos' => $juegos,
                    'limites' => $limites,
                    'origen' => $origenes,
                ],
            ]);
        }

        [$filas, $filasPadre] = $this->filasDeEntidad($tipo, $entidadId);

        foreach ($filas as $fila) {
            $clave = $fila->juego_id . ':' . $fila->moneda;
            $limites[$clave] = $this->serializarLimite($fila);
        }

        // Origen: solo para celdas SIN fila propia en esta entidad; se
        // resuelve el ancestro más cercano. La banca no tiene padre.
        if ($tipo !== 'banca') {
            $porGrupo = [];
            $porBanca = [];
            foreach ($filasPadre as $fila) {
                $clave = $fila->juego_id . ':' . $fila->moneda;
                if ($fila->grupo_id !== null) {
                    $porGrupo[$clave] = $fila;
                } else {
                    $porBanca[$clave] = $fila;
                }
            }

            foreach ($claves as $clave) {
                if ($limites[$clave] !== null) {
                    continue; // fila propia: sin origen que heredar
                }

                $padre = $porGrupo[$clave] ?? $porBanca[$clave] ?? null;
                if (!$padre) {
                    continue;
                }

                $origenes[$clave] = [
                    'nivel' => $padre->grupo_id !== null ? 'grupo' : 'banca',
                    'entidad_id' => (int) ($padre->grupo_id ?? $padre->banca_id),
                    'valor' => $this->valoresPresentes($padre),
                ];
            }
        }

        return response()->json([
            'data' => [
                'juegos' => $juegos,
                'limites' => $limites,
                'origen' => $origenes,
            ],
        ]);
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
     * ¿La entidad consultada está dentro de la jerarquía visible del rol?
     * super_master/master ven todo; banca solo su propia cadena; grupo solo
     * su propio grupo (y la banca de la que cuelga).
     */
    private function entidadDentroDelAlcance($user, string $tipo, int $entidadId): bool
    {
        if (in_array($user->role, ['super_master', 'master'])) {
            return true;
        }

        if ($user->role === 'banca') {
            if ($tipo === 'banca') {
                return $user->banca_id == $entidadId;
            }

            if ($tipo === 'grupo') {
                return Grupo::whereKey($entidadId)->where('banca_id', $user->banca_id)->exists();
            }

            return Taquilla::whereKey($entidadId)
                ->whereHas('grupo', fn ($q) => $q->where('banca_id', $user->banca_id))
                ->exists();
        }

        if ($user->role === 'grupo') {
            if ($tipo === 'grupo') {
                return $user->grupo_id == $entidadId;
            }

            if ($tipo === 'banca') {
                return $user->banca_id == $entidadId;
            }

            return Taquilla::whereKey($entidadId)
                ->where('grupo_id', $user->grupo_id)
                ->exists();
        }

        return false;
    }

    /**
     * Filas propias de la entidad y filas de sus ancestros en consultas
     * constantes (sin N+1 por juego). Devuelve [filasPropias, filasPadre].
     */
    private function filasDeEntidad(string $tipo, int $entidadId): array
    {
        if ($tipo === 'banca') {
            return [
                JuegoLimite::where('banca_id', $entidadId)
                    ->whereNull('grupo_id')
                    ->whereNull('taquilla_id')
                    ->get(),
                collect(),
            ];
        }

        if ($tipo === 'grupo') {
            $grupo = Grupo::with('banca')->find($entidadId);

            return [
                JuegoLimite::where('grupo_id', $entidadId)->whereNull('taquilla_id')->get(),
                $grupo?->banca_id
                    ? JuegoLimite::where('banca_id', $grupo->banca_id)
                        ->whereNull('grupo_id')
                        ->whereNull('taquilla_id')
                        ->get()
                    : collect(),
            ];
        }

        // taquilla: ancestros = filas de su grupo y de su banca (una consulta)
        $taquilla = Taquilla::with('grupo.banca')->find($entidadId);
        $grupoId = $taquilla?->grupo_id;
        $bancaId = $taquilla?->grupo?->banca_id;

        $filasPadre = collect();
        if ($bancaId) {
            $filasPadre = JuegoLimite::where('banca_id', $bancaId)
                ->whereNull('taquilla_id')
                ->where(function ($q) use ($grupoId) {
                    $q->where('grupo_id', $grupoId)->orWhereNull('grupo_id');
                })
                ->get();
        }

        return [
            JuegoLimite::where('taquilla_id', $entidadId)->get(),
            $filasPadre,
        ];
    }

    /**
     * Modo scope: matriz de límites de TODAS las entidades del tipo visibles
     * para el rol, indexada por "entidad_id:juego_id:moneda". Las filas se
     * traen en UNA consulta whereIn sobre la columna del nivel (sin N+1 por
     * entidad); las celdas sin fila propia quedan null. `mixto` marca cada
     * juego×moneda donde unas entidades tienen fila propia y otras no.
     */
    private function listarLimitesPorScope($user, string $scope): JsonResponse
    {
        $tipo = substr($scope, 0, -1); // bancas → banca, grupos → grupo, taquillas → taquilla
        $entidades = $this->entidadesVisiblesPorTipo($user, $tipo);
        $ids = $entidades->pluck('id');

        $juegos = Juego::where('active', true)->orderBy('id')->get(['id', 'name', 'slug']);
        $clavesJuego = collect($juegos)
            ->flatMap(fn ($juego) => [$juego->id . ':bs', $juego->id . ':usd'])
            ->all();

        $limites = [];
        $filasPorClave = [];

        foreach ($entidades as $entidad) {
            $prefijo = $entidad->id . ':';
            foreach ($clavesJuego as $clave) {
                $limites[$prefijo . $clave] = null;
            }
        }

        if ($entidades->isNotEmpty()) {
            $query = JuegoLimite::whereIn($tipo . '_id', $ids);
            if ($tipo === 'banca') {
                $query->whereNull('grupo_id')->whereNull('taquilla_id');
            } elseif ($tipo === 'grupo') {
                $query->whereNull('taquilla_id');
            }

            foreach ($query->get() as $fila) {
                $clave = $fila->juego_id . ':' . $fila->moneda;
                $limites[$fila->{$tipo . '_id'} . ':' . $clave] = $this->serializarLimite($fila);
                $filasPorClave[$clave] = ($filasPorClave[$clave] ?? 0) + 1;
            }
        }

        // mixto: true solo cuando las entidades del alcance NO coinciden en
        // si tienen fila propia para ese juego×moneda (unas sí, otras no).
        $total = $entidades->count();
        $mixto = [];
        foreach ($clavesJuego as $clave) {
            $conFila = $filasPorClave[$clave] ?? 0;
            $mixto[$clave] = $conFila > 0 && $conFila < $total;
        }

        return response()->json([
            'data' => [
                'juegos' => $juegos,
                'entidades' => $entidades->map(fn ($entidad) => [
                    'id' => (int) $entidad->id,
                    'name' => $entidad->name,
                    'tipo' => $tipo,
                ])->values(),
                'limites' => $limites,
                'mixto' => $mixto,
            ],
        ]);
    }

    /**
     * Entidades del tipo consultado visibles para el rol: super_master y
     * master ven todas; banca solo las de su propia cadena (su banca, sus
     * grupos y sus agencias); grupo solo su grupo, su banca y sus agencias.
     */
    private function entidadesVisiblesPorTipo($user, string $tipo): Collection
    {
        $query = match ($tipo) {
            'banca' => Banca::query(),
            'grupo' => Grupo::query(),
            'taquilla' => Taquilla::query(),
        };

        if ($user->role === 'banca') {
            if ($tipo === 'banca') {
                $query->whereKey($user->banca_id);
            } elseif ($tipo === 'grupo') {
                $query->where('banca_id', $user->banca_id);
            } else {
                $query->whereHas('grupo', fn ($q) => $q->where('banca_id', $user->banca_id));
            }
        } elseif ($user->role === 'grupo') {
            if ($tipo === 'grupo') {
                $query->whereKey($user->grupo_id);
            } elseif ($tipo === 'banca') {
                $query->whereKey($user->banca_id);
            } else {
                $query->where('grupo_id', $user->grupo_id);
            }
        }

        return $query->orderBy('id')->get(['id', 'name']);
    }

    /**
     * Serializar una fila de límite con valores numéricos (la bd guarda
     * decimales; el cast decimal:2 los expone como string).
     */
    private function serializarLimite(JuegoLimite $limite): array
    {
        return [
            'id' => (int) $limite->id,
            'limite_minimo' => $limite->limite_minimo !== null ? (float) $limite->limite_minimo : null,
            'limite_maximo' => $limite->limite_maximo !== null ? (float) $limite->limite_maximo : null,
            'porcentaje_pago' => $limite->porcentaje_pago !== null ? (float) $limite->porcentaje_pago : null,
            'participacion' => $limite->participacion !== null ? (float) $limite->participacion : null,
            'fraccion' => (bool) $limite->fraccion,
            'limite_tiempo' => $limite->limite_tiempo !== null ? (int) $limite->limite_tiempo : null,
        ];
    }

    /**
     * Solo los campos no nulos de una fila padre, para el origen heredado.
     */
    private function valoresPresentes(JuegoLimite $limite): array
    {
        $valores = [];

        foreach (['limite_minimo', 'limite_maximo', 'porcentaje_pago', 'participacion', 'limite_tiempo'] as $campo) {
            if ($limite->{$campo} !== null) {
                $valores[$campo] = (float) $limite->{$campo};
            }
        }

        if ($limite->fraccion !== null) {
            $valores['fraccion'] = (bool) $limite->fraccion;
        }

        return $valores;
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
