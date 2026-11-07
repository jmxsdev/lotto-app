<?php

namespace App\Http\Controllers\Api;

use App\Audit\AuditAction;
use App\Audit\AuditEventData;
use App\Audit\AuditReason;
use App\Audit\AuditRecorder;
use App\Audit\AuditScope;
use App\Audit\AuditScopeResolver;
use App\Http\Controllers\Controller;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Models\Taquilla;
use App\Services\ComisionService;
use App\Services\JuegoLimiteService;
use App\Services\JuegoPluginManager;
use App\Services\PremiosConfigService;
use App\Services\PremiosEngine;
use App\Support\PremiosOficiales;
use App\Support\Scope\AlcanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JuegoController extends Controller
{
    /**
     * Audit summary bounds for the batch endpoint: the batch can touch
     * hundreds of rows, so the evidence stores a bounded sample.
     */
    private const BATCH_AUDIT_MAX_KEYS = 500;

    private const BATCH_AUDIT_SAMPLE = 20;

    public function __construct(
        private JuegoLimiteService $limites,
        private readonly AlcanceService $alcance,
        private readonly AuditRecorder $audit,
        private readonly AuditScopeResolver $scopeResolver,
    ) {}

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
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $oldActive = (bool) $juego->active;
        $newActive = (bool) $request->active;

        $reasonRaw = $request->input('reason_code');
        $reason = is_string($reasonRaw) ? AuditReason::tryFrom($reasonRaw) : null;
        $reasonTextRaw = $request->input('reason_text');
        $reasonText = is_string($reasonTextRaw) ? $reasonTextRaw : null;

        // P0 fail-closed: el toggle y el plugin se revierten si la evidencia
        // de auditoría no se puede persistir.
        DB::transaction(function () use (
            $juego,
            $user,
            $oldActive,
            $newActive,
            $reason,
            $reasonText
        ): void {
            $juego->update([
                'active' => $newActive,
                'updated_by' => $user->id,
            ]);

            // Sincroniza el plugin en AMBOS sentidos: la relación `pluginJuego`
            // filtra por active=true (devuelve null al reactivar), así que se
            // actualiza por la relación sin filtro (pluginJuegos).
            $juego->pluginJuegos()->update([
                'active' => $newActive,
                'updated_by' => $user->id,
            ]);

            $event = (new AuditEventData(
                action: AuditAction::GameToggled,
                entityType: 'juego',
                entityId: (int) $juego->id,
                scope: new AuditScope,
                before: ['active' => $oldActive],
                after: ['active' => $newActive],
            ))->withReason($reason, $reasonText);

            $this->audit->critical(AuditAction::GameToggled, $event);
        });

        return response()->json($juego->load('pluginJuego', 'updatedByUser'));
    }

    public function update(Request $request, Juego $juego)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'config' => 'nullable|array',
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        $changes = $request->only(['name', 'config']);

        if (empty($changes)) {
            return response()->json($juego->load('pluginJuego'));
        }

        $before = $juego->only(['name', 'config']);

        $reasonRaw = $request->input('reason_code');
        $reason = is_string($reasonRaw) ? AuditReason::tryFrom($reasonRaw) : null;
        $reasonTextRaw = $request->input('reason_text');
        $reasonText = is_string($reasonTextRaw) ? $reasonTextRaw : null;

        // P1 best-effort: una caída de auditoría no bloquea la actualización.
        DB::transaction(function () use (
            $juego,
            $user,
            $changes,
            $before,
            $reason,
            $reasonText
        ): void {
            $juego->update(array_merge($changes, ['updated_by' => $user->id]));
            $after = $juego->only(['name', 'config']);

            $event = (new AuditEventData(
                action: AuditAction::GameUpdated,
                entityType: 'juego',
                entityId: (int) $juego->id,
                scope: new AuditScope,
                before: $before,
                after: $after,
            ))->withReason($reason, $reasonText);

            $this->audit->record(AuditAction::GameUpdated, $event);
        });

        return response()->json($juego->load('pluginJuego'));
    }

    /**
     * Edición atómica de premios (D1/D2/D3, spec configuracion-premios):
     * PUT /api/v1/juegos/{juego}/premios.
     *
     * El body ES el objeto `premios` completo ({base, modalidades, comodines}).
     * Validación estricta del esquema + claves de modalidad en plugin ∪
     * catálogo oficial; el servicio hace el merge seguro, los espejos legacy
     * y la evidencia canónica `game.prizes_updated`. Solo roles
     * super_master|master (ruta).
     */
    public function updatePremios(Request $request, Juego $juego)
    {
        $user = $request->user();

        // REQ7/D3: la-ricachona (y cualquier juego sin base oficial) no tiene
        // premios configurables → 422 con mensaje claro.
        $oficial = PremiosOficiales::para($juego->slug);
        if ($oficial === null || ! isset($oficial['base'])) {
            return response()->json(['message' => 'Este juego no tiene premios oficiales configurables.'], 422);
        }

        $request->validate([
            'base' => 'required|integer|min:1',
            'modalidades' => 'sometimes|array',
            'modalidades.*' => 'integer|min:1',
            'comodines' => 'sometimes|array',
            'comodines.*.tipo' => 'required|in:flag,letra,numero,palabra',
            'comodines.*.premio_multiplo' => 'required|integer|min:1',
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        // Reemplazo atómico: omitir modalidades/comodines los deja vacíos.
        $premios = $request->only(['base', 'modalidades', 'comodines']);
        $premios['modalidades'] ??= [];
        $premios['comodines'] ??= [];

        // D3: claves de modalidad válidas en plugin->obtenerModalidades() ∪
        // catálogo oficial, sin bloquear claves canónicas que el plugin no liste.
        $service = app(PremiosConfigService::class);
        $clavesValidas = $service->clavesModalidadValidas($juego);
        foreach (array_keys($premios['modalidades']) as $clave) {
            if (! in_array($clave, $clavesValidas, true)) {
                return response()->json(['message' => "La modalidad [{$clave}] no es válida para este juego."], 422);
            }
        }

        // `acumulativo` solo es válido con tipo=palabra (spec).
        foreach ($premios['comodines'] as $clave => $comodin) {
            if (! empty($comodin['acumulativo']) && ($comodin['tipo'] ?? null) !== 'palabra') {
                return response()->json(['message' => "El comodín [{$clave}] solo puede ser acumulativo con tipo 'palabra'."], 422);
            }
        }

        $reasonRaw = $request->input('reason_code');
        $reasonCode = is_string($reasonRaw) ? $reasonRaw : null;
        $reasonTextRaw = $request->input('reason_text');
        $reasonText = is_string($reasonTextRaw) ? $reasonTextRaw : null;

        $juego = $service->actualizar($juego, $premios, $user->id, $reasonCode, $reasonText);

        return response()->json($juego->load('pluginJuego', 'updatedByUser'));
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

        if (! $plugin) {
            return response()->json(['message' => 'No hay plugin registrado para este juego.'], 404);
        }

        $reglas = $plugin->obtenerReglas();
        $modalidades = $plugin->obtenerModalidades();
        $config = $juego->config;
        if (is_array($config) && ! empty($config['modalidades_permitidas'])) {
            $modalidades = array_filter($modalidades, fn ($m) => in_array($m['code'], $config['modalidades_permitidas']));
        }
        $reglas['modalidades'] = array_values($modalidades);

        // F1d (D10/§3.3): expone `premios` del motor ({base, modalidades,
        // comodines} desde config.premios) de forma ADITIVA sobre las reglas
        // del plugin. El contrato previo no se rompe.
        $reglas['premios'] = app(PremiosEngine::class)->reglas($juego);

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

        if (! $user->hasAnyRole(['super_master', 'master', 'banca', 'grupo', 'agencia'])) {
            return response()->json(['message' => 'No tienes permiso para ver límites.'], 403);
        }

        $filtros = $request->validate([
            'banca_id' => 'nullable|integer|exists:bancas,id',
            'grupo_id' => 'nullable|integer|exists:grupos,id',
            'taquilla_id' => 'nullable|integer|exists:taquillas,id',
        ]);

        $query = JuegoLimite::where('juego_id', $juego->id)
            ->with(['banca', 'grupo', 'taquilla']);

        // Alcance de filas por jerarquía del usuario (contrato LIMITES del
        // servicio: super_master > master > banca > agencia > grupo, primer
        // match gana).
        $query = $this->alcance->applyToEntidades(
            $query,
            $user,
            'limite',
            'No tienes permiso para ver límites.'
        );

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

        if (! $user->hasAnyRole(['super_master', 'master', 'banca', 'grupo', 'agencia'])) {
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
            // Modo scope con raíz opcional (mini-alcance de pestañas):
            // scope=grupos&banca_id=X | scope=taquillas&banca_id=X | scope=taquillas&grupo_id=Y
            $raiz = null;
            if (count($entidad) === 1) {
                if ($scope === 'bancas') {
                    abort(422, 'El alcance de bancas no admite filtro de entidad raíz.');
                }
                $raiz = [
                    'tipo' => str_replace('_id', '', (string) array_key_first($entidad)),
                    'id' => (int) reset($entidad),
                ];
            } elseif (count($entidad) > 1) {
                abort(422, 'Solo puede combinar scope con un único filtro de entidad raíz.');
            }

            return $this->listarLimitesPorScope($user, $scope, $raiz);
        }

        if (count($entidad) !== 1) {
            abort(422, 'Debe indicar exactamente uno de banca_id, grupo_id o taquilla_id.');
        }

        $tipo = str_replace('_id', '', (string) array_key_first($entidad));
        $entidadId = (int) $entidad[array_key_first($entidad)];

        $juegos = Juego::where('active', true)->orderBy('id')->get(['id', 'name', 'slug', 'type']);
        $claves = collect($juegos)
            ->flatMap(fn ($juego) => [$juego->id.':bs', $juego->id.':usd'])
            ->all();

        $limites = array_fill_keys($claves, null);
        $origenes = array_fill_keys($claves, null);

        // Alcance del rol primero: fuera de la jerarquía del usuario la
        // intersección es vacía (se responde la matriz sin valores, nunca
        // se amplía el alcance).
        if (! $this->entidadDentroDelAlcance($user, $tipo, $entidadId)) {
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
            $clave = $fila->juego_id.':'.$fila->moneda;
            $limites[$clave] = $this->serializarLimite($fila);
        }

        // Origen: solo para celdas SIN fila propia en esta entidad; se
        // resuelve el ancestro más cercano. La banca no tiene padre.
        if ($tipo !== 'banca') {
            $porGrupo = [];
            $porBanca = [];
            foreach ($filasPadre as $fila) {
                $clave = $fila->juego_id.':'.$fila->moneda;
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
                if (! $padre) {
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
     *
     * super_master/master/banca escriben según su alcance; el rol grupo
     * solo dentro de su subárbol (su grupo y sus taquillas). Un campo de
     * límite con `null` explícito elimina la fila (heredar del padre).
     *
     * La escritura y `game.limits_updated` (P0) comparten transacción: si la
     * evidencia no se persiste, el límite no cambia.
     */
    public function updateLimites(Request $request, Juego $juego)
    {
        $user = $request->user();

        if (! $user->hasAnyRole(['super_master', 'master', 'banca', 'grupo'])) {
            return response()->json(['message' => 'No tienes permiso para configurar límites.'], 403);
        }

        $request->validate([
            'banca_id' => 'required|exists:bancas,id',
            'grupo_id' => 'nullable|exists:grupos,id',
            'taquilla_id' => 'nullable|exists:taquillas,id',
            'moneda' => ['required', Rule::in(['bs', 'usd'])],
            'limite_minimo' => 'nullable|numeric|min:0',
            'limite_maximo' => 'nullable|numeric|min:0',
            'porcentaje_pago' => ['nullable', 'numeric', 'min:0', function ($attribute, $value, $fail) use ($juego) {
                $tope = ComisionService::topePorTipo((string) $juego->type);

                if ($value !== null && (float) $value > $tope) {
                    $fail("El % de pago para {$juego->type} no puede superar {$tope}%.");
                }
            }],
            'participacion' => 'nullable|numeric|min:0|max:100',
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        // Verificar alcance de escritura ANTES de validar restrictividad:
        // un actor fuera de alcance recibe 403 y nunca 422 con datos del
        // padre ajeno (spec limites, "Autorización antes que validación").
        $this->authorizeEscrituraLimite(
            $user,
            (int) $request->banca_id,
            $request->grupo_id !== null ? (int) $request->grupo_id : null,
            $request->taquilla_id !== null ? (int) $request->taquilla_id : null,
        );

        // Validar jerarquía de restricción: hijo ≤ padre
        if ($request->grupo_id || $request->taquilla_id) {
            $this->limites->validarRestrictividadLimite(
                $request->banca_id,
                $request->juego_id ?? $juego->id,
                $request->moneda,
                $request->grupo_id,
                $request->taquilla_id,
                $request->limite_minimo,
                $request->limite_maximo,
            );
        }

        $reasonRaw = $request->input('reason_code');
        $reason = is_string($reasonRaw) ? AuditReason::tryFrom($reasonRaw) : null;
        $reasonTextRaw = $request->input('reason_text');
        $reasonText = is_string($reasonTextRaw) ? $reasonTextRaw : null;

        // Clave natural de la fila (juego × entidad × moneda): identifica el
        // límite antes y después del upsert.
        $clave = [
            'juego_id' => $juego->id,
            'banca_id' => (int) $request->banca_id,
            'grupo_id' => $request->grupo_id !== null ? (int) $request->grupo_id : null,
            'taquilla_id' => $request->taquilla_id !== null ? (int) $request->taquilla_id : null,
            'moneda' => $request->moneda,
        ];

        $antes = JuegoLimite::where($clave)->first();
        $before = $this->snapshotLimite($antes);

        // P0 fail-closed: el upsert/borrado del límite se revierte si la
        // evidencia de auditoría no se puede persistir.
        [$limite, $isNew] = DB::transaction(function () use (
            $clave,
            $request,
            $antes,
            $before,
            $reason,
            $reasonText
        ): array {
            $item = array_merge($clave, $request->only([
                'limite_minimo', 'limite_maximo', 'porcentaje_pago', 'participacion',
            ]));

            $resultados = [];
            $this->limites->aplicarItemLimite($item, null, $resultados);

            // Un null explícito elimina la fila (heredar del padre): se
            // vuelve a leer para reflejar el estado final.
            $despues = JuegoLimite::where($clave)->first();
            $after = $this->snapshotLimite($despues);
            $modelo = $despues ?? $antes;

            if ($modelo !== null) {
                $event = (new AuditEventData(
                    action: AuditAction::GameLimitsUpdated,
                    entityType: 'juego_limite',
                    // Si la fila se eliminó, entity_id conserva el id de la
                    // fila borrada (último id conocido) y `context.deleted`
                    // marca que ya no existe.
                    entityId: (int) $modelo->id,
                    scope: $this->scopeResolver->forModel($modelo),
                    before: $before,
                    after: $after,
                    context: $despues === null && $antes !== null ? ['deleted' => true] : [],
                ))->withReason($reason, $reasonText);

                $this->audit->critical(AuditAction::GameLimitsUpdated, $event);
            }

            return [$despues ?? $antes, $antes === null && $despues !== null];
        });

        return response()->json($limite, $isNew ? 201 : 200);
    }

    /**
     * Upsert masivo atómico de límites.
     * POST /api/limites/batch
     */
    public function batchLimites(Request $request)
    {
        $user = $request->user();

        if (! $user->hasAnyRole(['super_master', 'master', 'banca', 'grupo'])) {
            return response()->json(['message' => 'No tienes permiso para configuración masiva de límites.'], 403);
        }

        $request->validate([
            'scope' => 'nullable|array',
            'scope.tipo' => 'required_with:scope|in:banca,grupo,taquilla,bancas,grupos,taquillas',
            'scope.id' => 'nullable|integer|min:1',
            'limites' => 'required|array|min:1',
            'limites.*.juego_id' => 'required|exists:juegos,id',
            'limites.*.banca_id' => 'nullable|exists:bancas,id',
            'limites.*.grupo_id' => 'nullable|exists:grupos,id',
            'limites.*.taquilla_id' => 'nullable|exists:taquillas,id',
            'limites.*.moneda' => ['required', Rule::in(['bs', 'usd'])],
            'limites.*.limite_minimo' => 'nullable|numeric|min:0',
            'limites.*.limite_maximo' => 'nullable|numeric|min:0',
            'limites.*.porcentaje_pago' => 'nullable|numeric|min:0|max:100',
            'limites.*.participacion' => 'nullable|numeric|min:0|max:100',
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        $scope = $request->scope;
        $objetivos = null;

        if ($scope) {
            // Modo scope por tipo (plural): sin id = todas las entidades del tipo
            // visibles para el rol; con id = intersección por raíz (mini-alcance):
            //   grupos+id(banca) → grupos de esa banca
            //   taquillas+id(banca) → taquillas de esa banca
            //   taquillas+id(grupo) → taquillas de ese grupo
            // Modo scope con raíz singular (banca/grupo/taquilla + id): fan-out
            // de esa entidad hacia sus descendientes (banca → grupos+taquillas).
            if (in_array($scope['tipo'], ['bancas', 'grupos', 'taquillas'])) {
                $objetivos = $this->expandirTipoAlcance($user, $scope['tipo'], ! empty($scope['id']) ? (int) $scope['id'] : null);
            } else {
                if (empty($scope['id'])) {
                    return response()->json([
                        'message' => 'El scope con tipo singular requiere un id de entidad.',
                    ], 422);
                }
                $objetivos = $this->expandirAlcance($user, $scope['tipo'], (int) $scope['id']);
            }

            // Modo scope: los ítems NO llevan entidades explícitas
            foreach ($request->limites as $item) {
                if (isset($item['banca_id']) || isset($item['grupo_id']) || isset($item['taquilla_id'])) {
                    return response()->json([
                        'message' => 'Cuando se usa scope, los ítems no deben incluir banca_id, grupo_id ni taquilla_id.',
                    ], 422);
                }
            }

            // El rol grupo solo expande dentro de su subárbol (su grupo y
            // sus taquillas); cualquier objetivo fuera ⇒ 403.
            if ($user->hasRole('grupo')) {
                $this->assertObjetivosDentroDelGrupo($user, $objetivos);
            }
        } else {
            // Modo legacy: autorizar el alcance de CADA ítem antes de
            // cualquier validación de datos (tope/restrictividad). Así un
            // ítem de otro subárbol recibe 403 y nunca 422.
            foreach ($request->limites as $item) {
                $this->authorizeEscrituraLimite(
                    $user,
                    (int) ($item['banca_id'] ?? 0),
                    ! empty($item['grupo_id']) ? (int) $item['grupo_id'] : null,
                    ! empty($item['taquilla_id']) ? (int) $item['taquilla_id'] : null,
                );
            }
        }

        // Tope por tipo de juego (animalitos 16, tripletas 25): valida cada
        // ítem antes de persistir, después de autorizar el alcance.
        $this->limites->validarItems($request->limites);

        $reasonRaw = $request->input('reason_code');
        $reason = is_string($reasonRaw) ? AuditReason::tryFrom($reasonRaw) : null;
        $reasonTextRaw = $request->input('reason_text');
        $reasonText = is_string($reasonTextRaw) ? $reasonTextRaw : null;

        // Resumen acotado antes/después para la evidencia P1: el batch puede
        // tocar cientos de filas, así que se guarda un conteo y una muestra,
        // nunca el lote completo.
        $resumen = $this->clavesAfectadasBatch($request->limites, $objetivos);
        $claves = $resumen['claves'];
        $totalClaves = $resumen['total'];
        $truncado = $resumen['truncado'];

        $juegoIds = collect($request->limites)->pluck('juego_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $bancaIds = collect($claves)->pluck('banca_id')->unique()->values()->all();
        $antes = $this->resumenLimites($claves, $juegoIds, $bancaIds);

        $resultados = [];

        DB::transaction(function () use ($request, $objetivos, &$resultados) {
            foreach ($request->limites as $item) {
                $item['juego_id'] = (int) $item['juego_id'];

                if ($objetivos === null) {
                    // Modo legacy: el alcance ya se autorizó por ítem antes
                    // de la validación; aquí solo queda restrictividad.
                    if (! empty($item['grupo_id']) || ! empty($item['taquilla_id'])) {
                        $this->limites->validarRestrictividadLimite(
                            (int) $item['banca_id'],
                            $item['juego_id'],
                            $item['moneda'],
                            ! empty($item['grupo_id']) ? (int) $item['grupo_id'] : null,
                            ! empty($item['taquilla_id']) ? (int) $item['taquilla_id'] : null,
                            $item['limite_minimo'] ?? null,
                            $item['limite_maximo'] ?? null,
                        );
                    }

                    $this->limites->aplicarItemLimite($item, null, $resultados);

                    continue;
                }

                // Modo scope: aplicar a cada entidad expandida (padre-primero)
                foreach ($objetivos as $objetivo) {
                    $this->limites->aplicarItemLimite($item, $objetivo, $resultados);
                }
            }
        });

        // P1 best-effort: una caída de auditoría no bloquea el batch ya
        // aplicado.
        $despues = $this->resumenLimites($claves, $juegoIds, $bancaIds);

        $event = (new AuditEventData(
            action: AuditAction::GameLimitsBatchUpdated,
            entityType: 'juego_limite',
            entityId: null,
            scope: $this->scopeDeBatch($scope),
            before: $antes,
            after: $despues,
            context: [
                'count' => $totalClaves,
                'tracked' => count($claves),
                'truncated' => $truncado,
                'scope' => ! empty($scope) ? [
                    'tipo' => (string) ($scope['tipo'] ?? ''),
                    'id' => isset($scope['id']) ? (int) $scope['id'] : null,
                ] : null,
            ],
        ))->withReason($reason, $reasonText);

        $this->audit->record(AuditAction::GameLimitsBatchUpdated, $event);

        return response()->json($resultados, 201);
    }

    /**
     * Expandir un alcance por tipo (plural). Sin id: TODAS las entidades del
     * tipo visibles para el rol. Con id (raíz): intersección por raíz para el
     * mini-alcance de las pestañas de entidad:
     *   grupos + id(banca) → grupos de esa banca
     *   taquillas + id(banca) → taquillas de esa banca
     *   taquillas + id(grupo) → taquillas de ese grupo
     *
     * @return array<int, array{nivel: string, id: int}>
     */
    private function expandirTipoAlcance($user, string $tipo, ?int $raizId = null): array
    {
        $singular = rtrim($tipo, 's'); // bancas→banca, grupos→grupo, taquillas→taquilla

        if ($raizId !== null) {
            // Intersección por raíz: primero el alcance de rol, luego la raíz.
            $entidades = $this->entidadesVisiblesPorTipo($user, $singular, $this->resolverRaiz($singular, $tipo, $raizId));

            return $this->objetivosDesdeEntidades($entidades, $singular);
        }

        $ids = $this->alcance->idsPorTipo($user, $singular);

        $objetivos = collect($ids)
            ->filter()
            ->map(fn ($id) => ['nivel' => $singular, 'id' => (int) $id])
            ->values()
            ->all();

        if (count($objetivos) > 500) {
            abort(422, 'El alcance supera las 500 entidades. Reduzca el nivel de configuración.');
        }

        return $objetivos;
    }

    /**
     * Resolver la raíz de un alcance plural con id: para grupos la raíz es una
     * banca; para taquillas puede ser una banca o un grupo (se infiere).
     */
    private function resolverRaiz(string $singular, string $tipo, int $raizId): ?array
    {
        if ($singular === 'grupo') {
            return ['tipo' => 'banca', 'id' => $raizId];
        }

        if ($singular === 'taquilla') {
            if (Banca::whereKey($raizId)->exists()) {
                return ['tipo' => 'banca', 'id' => $raizId];
            }
            if (Grupo::whereKey($raizId)->exists()) {
                return ['tipo' => 'grupo', 'id' => $raizId];
            }
            abort(422, 'La raíz del alcance de taquillas debe ser una banca o un grupo.');
        }

        return null;
    }

    /**
     * Convertir entidades visibles en objetivos {nivel, id} con guard de escala.
     */
    private function objetivosDesdeEntidades($entidades, string $singular): array
    {
        $objetivos = $entidades
            ->map(fn ($entidad) => ['nivel' => $singular, 'id' => (int) $entidad->id])
            ->values()
            ->all();

        if (count($objetivos) > 500) {
            abort(422, 'El alcance supera las 500 entidades. Reduzca el nivel de configuración.');
        }

        return $objetivos;
    }

    /**
     * Expandir un alcance a la lista de entidades objetivo (padre-primero).
     * banca → banca + grupos + taquillas; grupo → grupo + taquillas; taquilla → taquilla.
     *
     * @return array<int, array{nivel: string, id: int}>
     */
    private function expandirAlcance($user, string $tipo, int $id): array
    {
        if (! $this->entidadDentroDelAlcance($user, $tipo, $id)) {
            $this->alcance->denegar('No tienes acceso a la entidad raíz del alcance.');
        }

        $objetivos = [['nivel' => $tipo, 'id' => $id]];

        if ($tipo === 'banca') {
            $grupos = Grupo::where('banca_id', $id)->pluck('id');
            foreach ($grupos as $gid) {
                $objetivos[] = ['nivel' => 'grupo', 'id' => (int) $gid];
            }
            $taquillas = Taquilla::whereHas('grupo', fn ($q) => $q->where('banca_id', $id))->pluck('id');
            foreach ($taquillas as $tid) {
                $objetivos[] = ['nivel' => 'taquilla', 'id' => (int) $tid];
            }
        } elseif ($tipo === 'grupo') {
            $taquillas = Taquilla::where('grupo_id', $id)->pluck('id');
            foreach ($taquillas as $tid) {
                $objetivos[] = ['nivel' => 'taquilla', 'id' => (int) $tid];
            }
        }

        if (count($objetivos) > 500) {
            abort(422, 'El alcance supera las 500 entidades. Reduzca el nivel de configuración.');
        }

        return $objetivos;
    }

    /**
     * Eliminar un límite configurado.
     * DELETE /api/limites/{limite}
     *
     * super_master/master eliminan cualquier límite; banca solo los de su
     * banca; grupo solo los de su grupo o sus taquillas.
     * El modelo se resuelve por binding implícito (404 si no existe).
     */
    public function destroyLimite(Request $request, JuegoLimite $limite)
    {
        $user = $request->user();

        if (! $user->hasAnyRole(['super_master', 'master', 'banca', 'grupo'])) {
            return response()->json(['message' => 'No tienes permiso para eliminar límites.'], 403);
        }

        if ($user->hasRole('master') && ! $user->masterCanAccessBanca((int) $limite->banca_id)) {
            return response()->json(['message' => 'No tienes acceso a los límites de esta banca.'], 403);
        }

        if ($user->hasRole('banca') && (! $user->banca_id || $user->banca_id != $limite->banca_id)) {
            return response()->json(['message' => 'No tienes acceso a los límites de esta banca.'], 403);
        }

        if ($user->hasRole('grupo') && ! $this->limiteDentroDelGrupo($user, $limite)) {
            return response()->json(['message' => 'No tienes acceso a los límites de esta entidad.'], 403);
        }

        $request->validate([
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        // Snapshot y scope ANTES del borrado: la fila ya no existirá para
        // reconstruir la evidencia.
        $before = $this->snapshotLimite($limite);
        $scope = $this->scopeResolver->forModel($limite);
        $entityId = (int) $limite->id;

        $limite->delete();

        // P1 best-effort: una caída de auditoría no bloquea el borrado.
        // `game.limit_deleted` exige motivo; hasta que el panel lo capture
        // (WU-11a), la ausencia se registra como `unspecified` para no perder
        // la evidencia.
        $reasonRaw = $request->input('reason_code');
        $reason = is_string($reasonRaw)
            ? (AuditReason::tryFrom($reasonRaw) ?? AuditReason::Unspecified)
            : AuditReason::Unspecified;
        $reasonTextRaw = $request->input('reason_text');
        $reasonText = is_string($reasonTextRaw) ? $reasonTextRaw : null;

        $event = (new AuditEventData(
            action: AuditAction::GameLimitDeleted,
            entityType: 'juego_limite',
            entityId: $entityId,
            scope: $scope,
            before: $before,
            after: null,
        ))->withReason($reason, $reasonText);

        $this->audit->record(AuditAction::GameLimitDeleted, $event);

        return response()->json(['message' => 'Límite eliminado correctamente.']);
    }

    // ==================================================
    // MÉTODOS PRIVADOS DE AUTORIZACIÓN Y VALIDACIÓN
    // ==================================================

    /**
     * Verificar que el usuario tenga acceso a la banca.
     */
    private function authorizeBancaLimitAccess($user, int $bancaId): void
    {
        if ($user->hasRole('super_master')) {
            return;
        }

        if ($user->hasRole('master')) {
            if (! $user->masterCanAccessBanca($bancaId)) {
                abort(403, 'No tienes acceso a esta banca.');
            }

            return;
        }

        if ($user->hasRole('banca') && $user->banca_id == $bancaId) {
            return;
        }

        abort(403, 'No tienes acceso a esta banca.');
    }

    /**
     * Verificar el alcance de ESCRITURA de un límite para el rol.
     *
     * - super_master: todo.
     * - master: bancas que administra.
     * - banca: su propia banca y, dentro de ella, solo sus grupos y las
     *   taquillas de esos grupos (subárbol propio).
     * - grupo: su propio grupo y sus taquillas (nunca nivel banca ni otro
     *   grupo/taquilla).
     */
    private function authorizeEscrituraLimite($user, int $bancaId, ?int $grupoId, ?int $taquillaId): void
    {
        if ($user->hasRole('super_master')) {
            return;
        }

        if ($user->hasRole('master')) {
            $this->authorizeBancaLimitAccess($user, $bancaId);

            // El grupo/taquilla del payload deben colgar de la MISMA banca
            // autorizada; de lo contrario el límite se persistiría
            // incoherente y afectaría al tenant ajeno.
            $this->assertLimiteChainWithinBanca($bancaId, $grupoId, $taquillaId);

            return;
        }

        if ($user->hasRole('banca')) {
            if ((int) $user->banca_id !== $bancaId) {
                abort(403, 'No tienes acceso a esta banca.');
            }

            $this->assertLimiteChainWithinBanca($bancaId, $grupoId, $taquillaId);

            return;
        }

        if ($user->hasRole('grupo')) {
            if ((int) $user->banca_id !== $bancaId || (int) $user->grupo_id !== $grupoId) {
                abort(403, 'No tienes acceso a esta entidad.');
            }

            if ($taquillaId !== null) {
                $enGrupo = Taquilla::whereKey($taquillaId)
                    ->where('grupo_id', $user->grupo_id)
                    ->exists();

                if (! $enGrupo) {
                    abort(403, 'No tienes acceso a esta taquilla.');
                }
            }

            return;
        }

        abort(403, 'No tienes acceso a esta banca.');
    }

    /**
     * Verificar que el grupo/taquilla del payload cuelguen exactamente de la
     * banca autorizada. Evita que un actor con acceso a una banca persista
     * un límite con grupo/taquilla de otra banca (cadena incoherente).
     */
    private function assertLimiteChainWithinBanca(int $bancaId, ?int $grupoId, ?int $taquillaId): void
    {
        // El grupo del payload debe pertenecer a la banca.
        if ($grupoId !== null) {
            $grupoPropio = Grupo::whereKey($grupoId)
                ->where('banca_id', $bancaId)
                ->exists();

            if (! $grupoPropio) {
                abort(403, 'No tienes acceso a este grupo.');
            }
        }

        // La taquilla debe colgar de la banca y, si se indicó grupo,
        // pertenecer exactamente a ese grupo.
        if ($taquillaId !== null) {
            $taquillaPropia = Taquilla::whereKey($taquillaId)
                ->whereHas('grupo', fn ($q) => $q->where('banca_id', $bancaId))
                ->when($grupoId !== null, fn ($q) => $q->where('grupo_id', $grupoId))
                ->exists();

            if (! $taquillaPropia) {
                abort(403, 'No tienes acceso a esta taquilla.');
            }
        }
    }

    /**
     * ¿La fila de límite pertenece al subárbol del rol grupo (su grupo o
     * una de sus taquillas)? El nivel banca queda fuera.
     */
    private function limiteDentroDelGrupo($user, JuegoLimite $limite): bool
    {
        if ($limite->grupo_id !== null) {
            return (int) $limite->grupo_id === (int) $user->grupo_id;
        }

        if ($limite->taquilla_id !== null) {
            return Taquilla::whereKey($limite->taquilla_id)
                ->where('grupo_id', $user->grupo_id)
                ->exists();
        }

        return false;
    }

    /**
     * El rol grupo solo expande dentro de su subárbol: su propio grupo y
     * sus taquillas. Cualquier objetivo a nivel banca u otra entidad ⇒ 403.
     *
     * @param  array<int, array{nivel: string, id: int}>  $objetivos
     */
    private function assertObjetivosDentroDelGrupo($user, array $objetivos): void
    {
        foreach ($objetivos as $objetivo) {
            if ($objetivo['nivel'] === 'grupo' && (int) $objetivo['id'] === (int) $user->grupo_id) {
                continue;
            }

            if ($objetivo['nivel'] === 'taquilla') {
                continue; // se valida en bloque abajo
            }

            abort(403, 'No tienes acceso a la entidad raíz del alcance.');
        }

        $taquillaIds = collect($objetivos)
            ->where('nivel', 'taquilla')
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        if ($taquillaIds->isEmpty()) {
            return;
        }

        $propias = Taquilla::whereIn('id', $taquillaIds)
            ->where('grupo_id', $user->grupo_id)
            ->count();

        if ($propias !== $taquillaIds->count()) {
            abort(403, 'No tienes acceso a la entidad raíz del alcance.');
        }
    }

    /**
     * ¿La entidad consultada está dentro de la jerarquía visible del rol?
     *
     * Delega en el contrato de visibilidad por entidad del servicio
     * (`alcanceDeEntidad`). El corto-circuito de `super_master` preserva la
     * paridad con ids inexistentes (batch scope): antes resolvía `true` sin
     * consultar la entidad.
     */
    private function entidadDentroDelAlcance($user, string $tipo, int $entidadId): bool
    {
        if ($user->hasRole('super_master')) {
            return true;
        }

        $modelo = match ($tipo) {
            'banca' => Banca::find($entidadId),
            'grupo' => Grupo::find($entidadId),
            default => Taquilla::find($entidadId),
        };

        return $modelo !== null
            && $this->alcance->alcanceDeEntidad($user, $tipo, $modelo);
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
                    ->deJuegosActivos()
                    ->get(),
                collect(),
            ];
        }

        if ($tipo === 'grupo') {
            $grupo = Grupo::with('banca')->find($entidadId);

            return [
                JuegoLimite::where('grupo_id', $entidadId)->whereNull('taquilla_id')->deJuegosActivos()->get(),
                $grupo?->banca_id
                    ? JuegoLimite::where('banca_id', $grupo->banca_id)
                        ->whereNull('grupo_id')
                        ->whereNull('taquilla_id')
                        ->deJuegosActivos()
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
                ->deJuegosActivos()
                ->get();
        }

        return [
            JuegoLimite::where('taquilla_id', $entidadId)->deJuegosActivos()->get(),
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
    private function listarLimitesPorScope($user, string $scope, ?array $raiz = null): JsonResponse
    {
        $tipo = substr($scope, 0, -1); // bancas → banca, grupos → grupo, taquillas → taquilla
        $entidades = $this->entidadesVisiblesPorTipo($user, $tipo, $raiz);
        $ids = $entidades->pluck('id');

        $juegos = Juego::where('active', true)->orderBy('id')->get(['id', 'name', 'slug', 'type']);
        $clavesJuego = collect($juegos)
            ->flatMap(fn ($juego) => [$juego->id.':bs', $juego->id.':usd'])
            ->all();

        $limites = [];
        $filasPorClave = [];

        foreach ($entidades as $entidad) {
            $prefijo = $entidad->id.':';
            foreach ($clavesJuego as $clave) {
                $limites[$prefijo.$clave] = null;
            }
        }

        if ($entidades->isNotEmpty()) {
            $query = JuegoLimite::whereIn($tipo.'_id', $ids)->deJuegosActivos();
            if ($tipo === 'banca') {
                $query->whereNull('grupo_id')->whereNull('taquilla_id');
            } elseif ($tipo === 'grupo') {
                $query->whereNull('taquilla_id');
            }

            foreach ($query->get() as $fila) {
                $clave = $fila->juego_id.':'.$fila->moneda;
                $limites[$fila->{$tipo.'_id'}.':'.$clave] = $this->serializarLimite($fila);
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
     * Entidades del tipo consultado visibles para el rol (`JUEGO_VIS`).
     *
     * Delega en `AlcanceService::entidadesVisibles`; el orden y las ramas
     * viven en el servicio, no en el controller.
     */
    private function entidadesVisiblesPorTipo($user, string $tipo, ?array $raiz = null): Collection
    {
        return $this->alcance->entidadesVisibles($user, $tipo, $raiz);
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
        ];
    }

    /**
     * Solo los campos no nulos de una fila padre, para el origen heredado.
     */
    private function valoresPresentes(JuegoLimite $limite): array
    {
        $valores = [];

        foreach (['limite_minimo', 'limite_maximo', 'porcentaje_pago', 'participacion'] as $campo) {
            if ($limite->{$campo} !== null) {
                $valores[$campo] = (float) $limite->{$campo};
            }
        }

        return $valores;
    }

    // ==================================================
    // HELPERS DE AUDITORÍA DE LÍMITES
    // ==================================================

    /**
     * Snapshot seguro de una fila de límite para evidencia: solo campos de
     * negocio (sin id ni timestamps) con los decimales como float.
     *
     * @return array<string, mixed>|null
     */
    private function snapshotLimite(?JuegoLimite $limite): ?array
    {
        if ($limite === null) {
            return null;
        }

        return [
            'juego_id' => (int) $limite->juego_id,
            'banca_id' => $limite->banca_id !== null ? (int) $limite->banca_id : null,
            'grupo_id' => $limite->grupo_id !== null ? (int) $limite->grupo_id : null,
            'taquilla_id' => $limite->taquilla_id !== null ? (int) $limite->taquilla_id : null,
            'moneda' => $limite->moneda,
            'limite_minimo' => $limite->limite_minimo !== null ? (float) $limite->limite_minimo : null,
            'limite_maximo' => $limite->limite_maximo !== null ? (float) $limite->limite_maximo : null,
            'porcentaje_pago' => $limite->porcentaje_pago !== null ? (float) $limite->porcentaje_pago : null,
            'participacion' => $limite->participacion !== null ? (float) $limite->participacion : null,
        ];
    }

    /**
     * Claves afectadas por un batch, derivadas de los ítems y de los
     * objetivos expandidos. Acotada para no crecer sin control en lotes
     * grandes (el conteo total se conserva aparte).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array{nivel: string, id: int}>|null  $objetivos
     * @return array{claves: array<string, array<string, mixed>>, total: int, truncado: bool}
     */
    private function clavesAfectadasBatch(array $items, ?array $objetivos): array
    {
        $cadenas = $this->cadenasDeObjetivos($objetivos);
        $claves = [];
        $total = 0;

        foreach ($items as $item) {
            $juegoId = (int) ($item['juego_id'] ?? 0);
            $moneda = (string) ($item['moneda'] ?? '');

            if ($objetivos === null) {
                $cadenaItem = [
                    'banca_id' => (int) ($item['banca_id'] ?? 0),
                    'grupo_id' => ! empty($item['grupo_id']) ? (int) $item['grupo_id'] : null,
                    'taquilla_id' => ! empty($item['taquilla_id']) ? (int) $item['taquilla_id'] : null,
                ];
                $cadenasItem = [$cadenaItem];
            } else {
                $cadenasItem = $cadenas;
            }

            foreach ($cadenasItem as $cadena) {
                $clave = $juegoId.'|'.$cadena['banca_id'].'|'.($cadena['grupo_id'] ?? '').'|'.($cadena['taquilla_id'] ?? '').'|'.$moneda;

                if (isset($claves[$clave])) {
                    continue;
                }

                $total++;

                if (count($claves) >= self::BATCH_AUDIT_MAX_KEYS) {
                    continue;
                }

                $claves[$clave] = [
                    'juego_id' => $juegoId,
                    'banca_id' => (int) $cadena['banca_id'],
                    'grupo_id' => $cadena['grupo_id'],
                    'taquilla_id' => $cadena['taquilla_id'],
                    'moneda' => $moneda,
                ];
            }
        }

        return [
            'claves' => $claves,
            'total' => $total,
            'truncado' => $total > count($claves),
        ];
    }

    /**
     * Cadena banca/grupo/taquilla de cada objetivo del alcance, resuelta en
     * bloque (dos consultas) para no hacer N+1.
     *
     * @param  array<int, array{nivel: string, id: int}>|null  $objetivos
     * @return array<int, array{banca_id: int, grupo_id: int|null, taquilla_id: int|null}>
     */
    private function cadenasDeObjetivos(?array $objetivos): array
    {
        if ($objetivos === null) {
            return [];
        }

        $objetivos = collect($objetivos);

        $bancasPorGrupo = Grupo::whereIn('id', $objetivos->where('nivel', 'grupo')->pluck('id')->all())
            ->pluck('banca_id', 'id');

        $taquillas = Taquilla::with('grupo:id,banca_id')
            ->whereIn('id', $objetivos->where('nivel', 'taquilla')->pluck('id')->all())
            ->get(['id', 'grupo_id'])
            ->keyBy('id');

        $cadenas = [];

        foreach ($objetivos as $objetivo) {
            $id = (int) $objetivo['id'];

            if ($objetivo['nivel'] === 'banca') {
                $cadenas[] = ['banca_id' => $id, 'grupo_id' => null, 'taquilla_id' => null];

                continue;
            }

            if ($objetivo['nivel'] === 'grupo') {
                $cadenas[] = [
                    'banca_id' => (int) $bancasPorGrupo->get($id, 0),
                    'grupo_id' => $id,
                    'taquilla_id' => null,
                ];

                continue;
            }

            $taquilla = $taquillas->get($id);
            $cadenas[] = [
                'banca_id' => (int) ($taquilla?->grupo?->banca_id ?? 0),
                'grupo_id' => $taquilla?->grupo_id !== null ? (int) $taquilla->grupo_id : null,
                'taquilla_id' => $id,
            ];
        }

        return $cadenas;
    }

    /**
     * Resumen acotado de las filas afectadas: conteo y una muestra de
     * snapshots (nunca el lote completo).
     *
     * @param  array<string, array<string, mixed>>  $claves
     * @param  array<int, int>  $juegoIds
     * @param  array<int, int>  $bancaIds
     * @return array{count: int, items: array<int, array<string, mixed>>}
     */
    private function resumenLimites(array $claves, array $juegoIds, array $bancaIds): array
    {
        if ($claves === [] || $juegoIds === [] || $bancaIds === []) {
            return ['count' => 0, 'items' => []];
        }

        $filas = JuegoLimite::whereIn('juego_id', $juegoIds)
            ->whereIn('banca_id', $bancaIds)
            ->get();

        $encontradas = [];

        foreach ($filas as $fila) {
            $grupoId = $fila->grupo_id !== null ? (int) $fila->grupo_id : '';
            $taquillaId = $fila->taquilla_id !== null ? (int) $fila->taquilla_id : '';

            $clave = (int) $fila->juego_id.'|'.(int) $fila->banca_id.'|'.$grupoId.'|'.$taquillaId.'|'.$fila->moneda;

            if (isset($claves[$clave])) {
                $encontradas[$clave] = $this->snapshotLimite($fila);
            }
        }

        return [
            'count' => count($encontradas),
            'items' => array_slice(array_values($encontradas), 0, self::BATCH_AUDIT_SAMPLE),
        ];
    }

    /**
     * Scope de la evidencia del batch: raíz del request cuando el alcance es
     * singular (banca/grupo/taquilla); vacío para alcances plurales.
     */
    private function scopeDeBatch(?array $scope): AuditScope
    {
        $tipo = $scope['tipo'] ?? null;
        $id = isset($scope['id']) ? (int) $scope['id'] : null;

        if (! is_string($tipo) || $id === null) {
            return new AuditScope;
        }

        $grupo = $tipo === 'grupo' ? Grupo::find($id) : null;
        $taquilla = $tipo === 'taquilla' ? Taquilla::with('grupo:id,banca_id')->find($id) : null;

        return new AuditScope(
            bancaId: match ($tipo) {
                'banca' => $id,
                'grupo' => $grupo?->banca_id,
                'taquilla' => $taquilla?->grupo?->banca_id,
                default => null,
            },
            grupoId: $tipo === 'grupo' ? $id : $taquilla?->grupo_id,
            taquillaId: $tipo === 'taquilla' ? $id : null,
        );
    }
}
