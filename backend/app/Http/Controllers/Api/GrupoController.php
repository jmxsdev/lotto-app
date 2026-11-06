<?php

namespace App\Http\Controllers\Api;

use App\Audit\AuditAction;
use App\Audit\AuditEventData;
use App\Audit\AuditReason;
use App\Audit\AuditRecorder;
use App\Audit\AuditScopeResolver;
use App\Http\Controllers\Controller;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\User;
use App\Services\JuegoLimiteService;
use App\Support\Scope\AlcanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class GrupoController extends Controller
{
    /**
     * Safe grupo attributes captured in audit before/after payloads.
     *
     * Timestamps, the soft-delete marker and internal columns are excluded.
     *
     * @var array<int, string>
     */
    private const GRUPO_AUDIT_FIELDS = [
        'name', 'code', 'banca_id', 'active', 'monedas_permitidas',
        'vigencia_premios', 'tiempo_eliminacion', 'created_by', 'rif',
        'email', 'telefono', 'direccion', 'estado', 'municipio',
    ];

    public function __construct(
        private readonly AlcanceService $alcance,
        private readonly AuditRecorder $audit,
        private readonly AuditScopeResolver $scopeResolver,
    ) {}

    /**
     * Listar grupos (filtrados según jerarquía)
     */
    public function index(Request $request)
    {
        // Scoping jerárquico por rol (fuente única: AlcanceService, ENTIDADES).
        // master ve solo los grupos de sus bancas; sin bancas ve NADA.
        $query = $this->alcance->applyToEntidades(
            Grupo::query(),
            $request->user(),
            'grupo',
            'No tienes permiso para ver grupos.'
        );

        $grupos = $query->with('banca')->get();

        return response()->json($grupos);
    }

    /**
     * Crear un nuevo grupo
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:grupos,code',
            'banca_id' => 'required|exists:bancas,id',
            'active' => 'boolean',
            'user_name' => 'required|string|max:255',
            'user_email' => 'required|email|unique:users,email',
            'user_password' => 'required|string|min:8',
            'monedas_permitidas' => 'sometimes|array',
            'monedas_permitidas.bs' => 'boolean',
            'monedas_permitidas.usd' => 'boolean',
            'vigencia_premios' => 'nullable|integer|min:1',
            'tiempo_eliminacion' => 'nullable|integer|min:1|max:120',
            'rif' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'telefono' => 'nullable|string|max:30',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'nullable|string|max:100',
            'municipio' => 'nullable|string|max:100',
            // Create mode: límites iniciales persistidos en la misma transacción
            'limites' => 'nullable|array',
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        // Verificar que el usuario tenga acceso a la banca
        $this->authorizeBancaAccess($user, $request->banca_id);

        // Validar monedas_permitidas contra la banca
        if ($request->has('monedas_permitidas')) {
            $banca = Banca::find($request->banca_id);
            $bancaMonedas = $banca?->monedas_permitidas;
            if (! $this->validarMonedasContraParent($bancaMonedas, $request->monedas_permitidas)) {
                return response()->json([
                    'message' => 'El grupo no puede habilitar una moneda que la banca ha deshabilitado. La jerarquía inferior solo puede restringir, no expandir.',
                ], 422);
            }
        }

        // Validar vigencia_premios contra la banca (más restrictivo)
        if ($request->has('vigencia_premios') && $request->vigencia_premios !== null) {
            $banca = Banca::find($request->banca_id);
            $bancaVigencia = $banca?->vigencia_premios;
            if ($bancaVigencia !== null && $request->vigencia_premios > $bancaVigencia) {
                return response()->json([
                    'message' => 'La vigencia de premios del grupo no puede ser mayor que la de la banca ('.$bancaVigencia.' días).',
                ], 422);
            }
        }

        // Validar tiempo_eliminacion contra la banca (más restrictivo: no puede alargar la ventana)
        if ($request->has('tiempo_eliminacion') && $request->tiempo_eliminacion !== null) {
            $banca = Banca::find($request->banca_id);
            $bancaTiempo = $banca?->tiempo_eliminacion ?? 5;
            if ($request->tiempo_eliminacion > $bancaTiempo) {
                return response()->json([
                    'message' => "El tiempo máximo del grupo no puede ser mayor que el de la banca ({$bancaTiempo} minutos). La jerarquía inferior solo puede acortar el plazo.",
                ], 422);
            }
        }

        $grupo = null;
        $userCreado = null;
        [$reason, $reasonText] = $this->auditReason($request);

        DB::transaction(function () use ($request, $user, &$grupo, &$userCreado, $reason, $reasonText) {
            $grupo = Grupo::create([
                'name' => $request->name,
                'code' => $request->code,
                'banca_id' => $request->banca_id,
                'active' => $request->active ?? true,
                'monedas_permitidas' => $request->monedas_permitidas ?? null,
                'vigencia_premios' => $request->vigencia_premios ?? null,
                'tiempo_eliminacion' => $request->tiempo_eliminacion ?? null,
                'created_by' => $user->id,
                'rif' => $request->rif,
                'email' => $request->email,
                'telefono' => $request->telefono,
                'direccion' => $request->direccion,
                'estado' => $request->estado,
                'municipio' => $request->municipio,
            ]);

            // P1 best-effort: creation evidence never blocks the entity.
            $this->audit->record(AuditAction::GrupoCreated, (new AuditEventData(
                action: AuditAction::GrupoCreated,
                entityType: 'grupo',
                entityId: (int) $grupo->id,
                scope: $this->scopeResolver->forModel($grupo),
                after: $this->grupoSnapshot($grupo),
            ))->withReason($reason, $reasonText));

            $userCreado = User::create([
                'created_by' => $user->id,
                'name' => $request->user_name,
                'email' => $request->user_email,
                'password' => Hash::make($request->user_password),
                'banca_id' => $request->banca_id,
                'grupo_id' => $grupo->id,
                'active' => $request->active ?? true,
            ]);

            $userCreado->assignRole('grupo');

            // P0 fail-closed: the generated operator shares the transaction
            // with the grupo, so a failed insert rolls everything back.
            $this->audit->record(AuditAction::UserCreated, (new AuditEventData(
                action: AuditAction::UserCreated,
                entityType: 'user',
                entityId: (int) $userCreado->id,
                scope: $this->scopeResolver->forModel($userCreado),
                after: $this->grupoUserSnapshot($userCreado),
            ))->withReason($reason, $reasonText));

            // Límites iniciales (create mode): atómicos con la entidad.
            // Cualquier error de validación/restrictividad revierte TODO.
            if (! empty($request->limites)) {
                app(JuegoLimiteService::class)->validarItems($request->limites);
                app(JuegoLimiteService::class)->persistirParaEntidad($request->limites, 'grupo', $grupo->id);
            }
        });

        return response()->json([
            'grupo' => $grupo->load('banca'),
            'user' => $userCreado->load('roles'),
        ], 201);
    }

    /**
     * Mostrar un grupo específico
     */
    public function show(Grupo $grupo)
    {
        $user = auth()->user();

        // Lectura acotada al alcance del rol (fuente única: AlcanceService, ENTIDADES).
        $this->authorizeGrupoAccess($user, $grupo);

        return response()->json($grupo->load('banca', 'taquillas'));
    }

    /**
     * Actualizar un grupo
     */
    public function update(Request $request, Grupo $grupo)
    {
        $user = auth()->user();

        // Escritura acotada al alcance de gestión del rol (AlcanceService, ENTIDADES).
        $this->authorizeGestion($user, $grupo);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => ['sometimes', 'string', Rule::unique('grupos')->ignore($grupo->id)],
            'banca_id' => 'sometimes|exists:bancas,id',
            'active' => 'boolean',
            'monedas_permitidas' => 'sometimes|array',
            'monedas_permitidas.bs' => 'boolean',
            'monedas_permitidas.usd' => 'boolean',
            'vigencia_premios' => 'nullable|integer|min:1',
            'tiempo_eliminacion' => 'nullable|integer|min:1|max:120',
            'rif' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'telefono' => 'nullable|string|max:30',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'nullable|string|max:100',
            'municipio' => 'nullable|string|max:100',
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        if ($request->has('banca_id')) {
            $this->authorizeBancaAccess($user, $request->banca_id);
        }

        // Validar monedas_permitidas contra la banca
        if ($request->has('monedas_permitidas')) {
            $bancaMonedas = $grupo->banca?->monedas_permitidas;
            if (! $this->validarMonedasContraParent($bancaMonedas, $request->monedas_permitidas)) {
                return response()->json([
                    'message' => 'El grupo no puede habilitar una moneda que la banca ha deshabilitado. La jerarquía inferior solo puede restringir, no expandir.',
                ], 422);
            }
        }

        // Validar vigencia_premios contra la banca (más restrictivo)
        if ($request->has('vigencia_premios')) {
            $bancaVigencia = $grupo->banca?->vigencia_premios;
            if ($bancaVigencia !== null && $request->vigencia_premios !== null) {
                if ($request->vigencia_premios > $bancaVigencia) {
                    return response()->json([
                        'message' => 'La vigencia de premios del grupo no puede ser mayor que la de la banca ('.$bancaVigencia.' días).',
                    ], 422);
                }
            }
        }

        // Validar tiempo_eliminacion contra la banca (más restrictivo: no puede alargar la ventana)
        if ($request->has('tiempo_eliminacion') && $request->tiempo_eliminacion !== null) {
            $bancaTiempo = $grupo->banca?->tiempo_eliminacion ?? 5;
            if ($request->tiempo_eliminacion > $bancaTiempo) {
                return response()->json([
                    'message' => "El tiempo máximo del grupo no puede ser mayor que el de la banca ({$bancaTiempo} minutos). La jerarquía inferior solo puede acortar el plazo.",
                ], 422);
            }
        }

        $data = $request->only(['name', 'code', 'banca_id', 'active', 'monedas_permitidas', 'vigencia_premios', 'tiempo_eliminacion', 'rif', 'email', 'telefono', 'direccion', 'estado', 'municipio']);
        $before = $this->grupoSnapshot($grupo);
        $bancaIdBefore = $grupo->banca_id !== null ? (int) $grupo->banca_id : null;
        [$reason, $reasonText] = $this->auditReason($request);

        DB::transaction(function () use ($grupo, $data, $before, $bancaIdBefore, $reason, $reasonText): void {
            $grupo->update($data);

            $auditedKeys = array_values(array_intersect(
                array_keys($grupo->getChanges()),
                self::GRUPO_AUDIT_FIELDS,
            ));

            if ($auditedKeys !== []) {
                $after = $this->grupoSnapshot($grupo);

                // P1 best-effort: a failed audit write never blocks the update.
                $this->audit->record(AuditAction::GrupoUpdated, (new AuditEventData(
                    action: AuditAction::GrupoUpdated,
                    entityType: 'grupo',
                    entityId: (int) $grupo->id,
                    scope: $this->scopeResolver->forModel($grupo),
                    before: Arr::only($before, $auditedKeys),
                    after: Arr::only($after, $auditedKeys),
                ))->withReason($reason, $reasonText));
            }

            // P0 fail-closed: moving a grupo to another banca changes access and
            // requires a reason; a missing reason or write failure rolls back.
            if (array_key_exists('banca_id', $grupo->getChanges())) {
                $this->audit->critical(AuditAction::GrupoBancaChanged, (new AuditEventData(
                    action: AuditAction::GrupoBancaChanged,
                    entityType: 'grupo',
                    entityId: (int) $grupo->id,
                    scope: $this->scopeResolver->forModel($grupo),
                    before: ['banca_id' => $bancaIdBefore],
                    after: ['banca_id' => $grupo->banca_id !== null ? (int) $grupo->banca_id : null],
                ))->withReason($reason, $reasonText));
            }
        });

        return response()->json($grupo->load('banca'));
    }

    /**
     * Alternar el estado activo de un grupo.
     * PATCH /api/grupos/{grupo}/toggle
     * No propaga cambios a las agencias hijas: el activo efectivo se resuelve en runtime.
     */
    public function toggle(Request $request, Grupo $grupo)
    {
        $user = auth()->user();

        // Escritura acotada al alcance de gestión del rol (AlcanceService, ENTIDADES).
        $this->authorizeGestion($user, $grupo);

        $request->validate([
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        $activeBefore = (bool) $grupo->active;
        $activeAfter = ! $activeBefore;
        [$reason, $reasonText] = $this->auditReason($request);

        // P0 fail-closed: a missing/invalid reason or a write failure rolls the
        // toggle back (the recorder throws AuditValidationException/AuditWriteException).
        DB::transaction(function () use ($grupo, $activeBefore, $activeAfter, $reason, $reasonText): void {
            $grupo->update(['active' => $activeAfter]);

            $this->audit->critical(AuditAction::GrupoToggled, (new AuditEventData(
                action: AuditAction::GrupoToggled,
                entityType: 'grupo',
                entityId: (int) $grupo->id,
                scope: $this->scopeResolver->forModel($grupo),
                before: ['active' => $activeBefore],
                after: ['active' => $activeAfter],
            ))->withReason($reason, $reasonText));
        });

        return response()->json($grupo->load('banca'));
    }

    /**
     * Eliminar un grupo
     */
    public function destroy(Request $request, Grupo $grupo)
    {
        $user = auth()->user();

        // Escritura acotada al alcance de gestión del rol (AlcanceService, ENTIDADES).
        $this->authorizeGestion($user, $grupo);

        $request->validate([
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        // Verificar que no tenga taquillas asociadas (opcional)
        if ($grupo->taquillas()->count() > 0) {
            return response()->json(['message' => 'No se puede eliminar el grupo porque tiene taquillas asociadas.'], 422);
        }

        $before = $this->grupoSnapshot($grupo);
        $scope = $this->scopeResolver->forModel($grupo);
        [$reason, $reasonText] = $this->auditReason($request);

        // P0 fail-closed: a missing/invalid reason or a write failure keeps the
        // grupo alive (the soft delete rolls back with the transaction).
        DB::transaction(function () use ($grupo, $before, $scope, $reason, $reasonText): void {
            $grupo->delete();

            $this->audit->critical(AuditAction::GrupoDeleted, (new AuditEventData(
                action: AuditAction::GrupoDeleted,
                entityType: 'grupo',
                entityId: (int) $grupo->id,
                scope: $scope,
                before: $before,
            ))->withReason($reason, $reasonText));
        });

        return response()->json(['message' => 'Grupo eliminado correctamente.']);
    }

    // --- Métodos de auditoría ---

    /**
     * @return array{0: AuditReason|null, 1: string|null}
     */
    private function auditReason(Request $request): array
    {
        $code = $request->input('reason_code');
        $text = $request->input('reason_text');

        return [
            is_string($code) ? AuditReason::tryFrom($code) : null,
            is_string($text) ? $text : null,
        ];
    }

    /**
     * Safe grupo snapshot: no timestamps, no soft-delete marker, no secrets.
     *
     * @return array<string, mixed>
     */
    private function grupoSnapshot(Grupo $grupo): array
    {
        return $grupo->only(self::GRUPO_AUDIT_FIELDS);
    }

    /**
     * Safe snapshot of the operator generated alongside a grupo. Roles come
     * from Spatie; the password hash is never read here.
     *
     * @return array<string, mixed>
     */
    private function grupoUserSnapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'active' => (bool) $user->active,
            'banca_id' => $user->banca_id,
            'grupo_id' => $user->grupo_id,
            'created_by' => $user->created_by,
            'roles' => $user->getRoleNames()->all(),
        ];
    }

    // --- Métodos de validación ---

    /**
     * Validar que las monedas del grupo no habiliten una moneda
     * que la banca tiene deshabilitada.
     */
    private function validarMonedasContraParent(?array $bancaMonedas, array $grupoMonedas): bool
    {
        $bancaBs = $bancaMonedas['bs'] ?? true;
        $bancaUsd = $bancaMonedas['usd'] ?? true;
        $grupoBs = $grupoMonedas['bs'] ?? true;
        $grupoUsd = $grupoMonedas['usd'] ?? true;

        // Si la banca tiene BS explícitamente deshabilitado, el grupo no puede habilitarlo
        if (! $bancaBs && $grupoBs) {
            return false;
        }

        // Si la banca tiene USD explícitamente deshabilitado, el grupo no puede habilitarlo
        if (! $bancaUsd && $grupoUsd) {
            return false;
        }

        return true;
    }

    // --- Métodos de autorización ---

    private function authorizeBancaAccess($user, $bancaId)
    {
        // Super Master puede cualquier banca
        if ($user->hasRole('super_master')) {
            return;
        }

        // Master solo puede gestionar sus bancas
        if ($user->hasRole('master')) {
            if (! $user->masterCanAccessBanca((int) $bancaId)) {
                abort(403, 'No tienes acceso a esta banca.');
            }

            return;
        }

        // Banca solo puede gestionar su propia banca
        if ($user->hasRole('banca')) {
            if (! $user->banca_id || $user->banca_id != $bancaId) {
                abort(403, 'No tienes acceso a esta banca.');
            }

            return;
        }

        // Otros roles no pueden
        abort(403, 'No tienes permiso para gestionar esta banca.');
    }

    /**
     * Lectura acotada al alcance del rol (fuente única: AlcanceService, ENTIDADES).
     */
    private function authorizeGrupoAccess($user, Grupo $grupo): void
    {
        if (! $this->alcance->alcanceDeEntidad($user, 'grupo', $grupo)) {
            abort(403, 'No tienes acceso a este grupo.');
        }
    }

    /**
     * Escritura acotada al alcance de gestión del rol (AlcanceService, ENTIDADES).
     */
    private function authorizeGestion($user, Grupo $grupo): void
    {
        if (! $this->alcance->puedeGestionar($user, 'grupo', $grupo)) {
            abort(403, 'No tienes acceso a este grupo.');
        }
    }
}
