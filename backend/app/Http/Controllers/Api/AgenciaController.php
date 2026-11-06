<?php

namespace App\Http\Controllers\Api;

use App\Audit\AuditAction;
use App\Audit\AuditEventData;
use App\Audit\AuditReason;
use App\Audit\AuditRecorder;
use App\Audit\AuditScopeResolver;
use App\Http\Controllers\Controller;
use App\Models\Agencia;
use App\Models\Grupo;
use App\Support\Scope\AlcanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CRUD de agencias (locales físicos) con alcance jerárquico por rol.
 *
 * super_master/master: todas; banca: agencias de su banca; grupo: agencias
 * de su grupo; agencia: solo su propio local. La agencia edita su propia
 * ficha (datos de contacto/fiscales) pero no gestiona identidad ni
 * pertenencia: code/grupo_id/active son inmutables y store/toggle/destroy
 * siguen bloqueados (403).
 */
class AgenciaController extends Controller
{
    /**
     * Safe agencia attributes captured in audit before/after payloads.
     *
     * Timestamps, the soft-delete marker and internal columns are excluded.
     *
     * @var array<int, string>
     */
    private const AGENCIA_AUDIT_FIELDS = [
        'name', 'code', 'grupo_id', 'active', 'created_by',
        'rif', 'email', 'telefono', 'direccion', 'estado', 'municipio',
    ];

    public function __construct(
        private readonly AlcanceService $alcance,
        private readonly AuditRecorder $audit,
        private readonly AuditScopeResolver $scopeResolver,
    ) {}

    /**
     * Listar agencias según el alcance del rol.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Scoping jerárquico por rol (fuente única: AlcanceService, ENTIDADES).
        // master ve solo los locales de sus bancas; sin bancas ve NADA.
        $query = $this->alcance->applyToEntidades(
            Agencia::query(),
            $user,
            'agencia',
            'No tienes permiso para ver agencias.'
        );

        return response()->json($query->with('grupo.banca')->orderBy('name')->get());
    }

    /**
     * Crear una agencia (local) dentro del alcance del rol.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $this->authorizeGestion($user);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:agencias,code',
            'grupo_id' => 'required|exists:grupos,id',
            'active' => 'boolean',
            'rif' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'telefono' => 'nullable|string|max:30',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'nullable|string|max:100',
            'municipio' => 'nullable|string|max:100',
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        $this->authorizeGrupoAccess($user, $validated['grupo_id']);

        [$reason, $reasonText] = $this->auditReason($request);

        $agencia = DB::transaction(function () use ($validated, $user, $reason, $reasonText): Agencia {
            $agencia = Agencia::create([
                'name' => $validated['name'],
                'code' => $validated['code'],
                'grupo_id' => $validated['grupo_id'],
                'active' => $validated['active'] ?? true,
                'created_by' => $user->id,
                'rif' => $validated['rif'] ?? null,
                'email' => $validated['email'] ?? null,
                'telefono' => $validated['telefono'] ?? null,
                'direccion' => $validated['direccion'] ?? null,
                'estado' => $validated['estado'] ?? null,
                'municipio' => $validated['municipio'] ?? null,
            ]);

            // P1 best-effort: creation evidence never blocks the entity.
            $this->audit->record(AuditAction::AgenciaCreated, (new AuditEventData(
                action: AuditAction::AgenciaCreated,
                entityType: 'agencia',
                entityId: (int) $agencia->id,
                scope: $this->scopeResolver->forModel($agencia),
                after: $this->agenciaSnapshot($agencia),
            ))->withReason($reason, $reasonText));

            return $agencia;
        });

        return response()->json($agencia->load('grupo.banca'), 201);
    }

    /**
     * Mostrar una agencia dentro del alcance del rol.
     */
    public function show(Request $request, Agencia $agencia)
    {
        $this->authorizeAgenciaAccess($request->user(), $agencia);

        return response()->json($agencia->load('grupo.banca'));
    }

    /**
     * Actualizar una agencia dentro del alcance del rol.
     *
     * La agencia solo edita su PROPIA ficha y solo campos de contacto/
     * fiscales; code/grupo_id/active son inmutables (403). Los demás roles
     * conservan la gestión completa dentro de su alcance.
     */
    public function update(Request $request, Agencia $agencia)
    {
        $user = $request->user();

        $this->authorizeAgenciaAccess($user, $agencia);

        if ($user->hasRole('agencia')) {
            // Identidad y pertenencia no se reasignan desde la ficha propia.
            if ($request->hasAny(['code', 'grupo_id', 'active'])) {
                abort(403, 'No puedes modificar code, grupo_id ni active de tu agencia.');
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'rif' => 'nullable|string|max:20',
                'email' => 'nullable|email',
                'telefono' => 'nullable|string|max:30',
                'direccion' => 'nullable|string|max:255',
                'estado' => 'nullable|string|max:100',
                'municipio' => 'nullable|string|max:100',
                'reason_code' => 'nullable|string|max:64',
                'reason_text' => 'nullable|string|max:500',
            ]);

            $data = Arr::except($validated, ['reason_code', 'reason_text']);
            $before = $this->agenciaSnapshot($agencia);
            [$reason, $reasonText] = $this->auditReason($request);

            // P1 best-effort: a failed audit write never blocks the self-service
            // update, but it still shares the entity transaction.
            DB::transaction(function () use ($agencia, $data, $before, $reason, $reasonText): void {
                $agencia->update($data);

                $this->recordAgenciaUpdated($agencia, $before, $reason, $reasonText);
            });

            return response()->json($agencia->load('grupo.banca'));
        }

        $this->authorizeGestion($user, $agencia);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => ['sometimes', 'string', Rule::unique('agencias')->ignore($agencia->id)],
            'grupo_id' => 'sometimes|exists:grupos,id',
            'active' => 'boolean',
            'rif' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'telefono' => 'nullable|string|max:30',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'nullable|string|max:100',
            'municipio' => 'nullable|string|max:100',
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        if (isset($validated['grupo_id'])) {
            $this->authorizeGrupoAccess($user, $validated['grupo_id']);
        }

        $data = Arr::except($validated, ['reason_code', 'reason_text']);
        $before = $this->agenciaSnapshot($agencia);
        $grupoIdBefore = $agencia->grupo_id !== null ? (int) $agencia->grupo_id : null;
        $bancaIdBefore = $this->scopeResolver->forModel($agencia)->bancaId;
        [$reason, $reasonText] = $this->auditReason($request);

        DB::transaction(function () use ($agencia, $data, $before, $grupoIdBefore, $bancaIdBefore, $reason, $reasonText): void {
            $agencia->update($data);

            // P1 best-effort: a failed audit write never blocks the update.
            $this->recordAgenciaUpdated($agencia, $before, $reason, $reasonText);

            // P0 fail-closed: moving an agencia to another grupo changes its
            // banca chain and requires a reason; a missing reason or write
            // failure rolls the whole update back.
            if (array_key_exists('grupo_id', $agencia->getChanges())) {
                $agencia->unsetRelation('grupo');
                $scopeAfter = $this->scopeResolver->forModel($agencia);

                $this->audit->critical(AuditAction::AgenciaBancaChanged, (new AuditEventData(
                    action: AuditAction::AgenciaBancaChanged,
                    entityType: 'agencia',
                    entityId: (int) $agencia->id,
                    scope: $scopeAfter,
                    before: ['grupo_id' => $grupoIdBefore, 'banca_id' => $bancaIdBefore],
                    after: [
                        'grupo_id' => $agencia->grupo_id !== null ? (int) $agencia->grupo_id : null,
                        'banca_id' => $scopeAfter->bancaId,
                    ],
                ))->withReason($reason, $reasonText));
            }
        });

        return response()->json($agencia->load('grupo.banca'));
    }

    /**
     * Alternar el estado activo de una agencia (local).
     */
    public function toggle(Request $request, Agencia $agencia)
    {
        $user = $request->user();

        $this->authorizeGestion($user, $agencia);
        $this->authorizeAgenciaAccess($user, $agencia);

        $request->validate([
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        $activeBefore = (bool) $agencia->active;
        $activeAfter = ! $activeBefore;
        [$reason, $reasonText] = $this->auditReason($request);

        // P0 fail-closed: a missing/invalid reason or a write failure rolls the
        // toggle back (the recorder throws AuditValidationException/AuditWriteException).
        DB::transaction(function () use ($agencia, $activeBefore, $activeAfter, $reason, $reasonText): void {
            $agencia->update(['active' => $activeAfter]);

            $this->audit->critical(AuditAction::AgenciaToggled, (new AuditEventData(
                action: AuditAction::AgenciaToggled,
                entityType: 'agencia',
                entityId: (int) $agencia->id,
                scope: $this->scopeResolver->forModel($agencia),
                before: ['active' => $activeBefore],
                after: ['active' => $activeAfter],
            ))->withReason($reason, $reasonText));
        });

        return response()->json($agencia->load('grupo.banca'));
    }

    /**
     * Eliminar (soft delete) un local con CASCADA (decisión del cliente tras
     * el WARNING de verify: la taquilla SIEMPRE tiene local asignado, nunca
     * se deja con agencia_id null):
     * - Taquillas del local: SOFT-DELETE (conservan agencia_id; el historial
     *   de apuestas/pagos/cierres queda intacto y trazable en reportes).
     * - Usuarios rol taquilla del local: se DESACTIVAN (active=false) y
     *   conservan agencia_id (User no usa SoftDeletes; se conserva el registro).
     * - El local se soft-deletea. Todo en una transacción.
     * Permisos intactos: authorizeGestion + authorizeAgenciaAccess ya acotan
     * quién puede borrar el local (roles sin permiso → 403).
     */
    public function destroy(Request $request, Agencia $agencia)
    {
        $user = $request->user();

        $this->authorizeGestion($user, $agencia);
        $this->authorizeAgenciaAccess($user, $agencia);

        $request->validate([
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        $before = $this->agenciaSnapshot($agencia);
        $scope = $this->scopeResolver->forModel($agencia);
        [$reason, $reasonText] = $this->auditReason($request);

        DB::transaction(function () use ($agencia, $before, $scope, $reason, $reasonText): void {
            // CASCADA: soft-delete de las taquillas del local (conservan agencia_id)
            $agencia->taquillas()->delete();

            // Usuarios rol taquilla del local: se desactivan (no se borran)
            $agencia->users()->role('taquilla')->update(['active' => false]);

            $agencia->delete();

            // P0 fail-closed: a missing/invalid reason or a write failure rolls
            // the whole cascade back (taquillas, users and the agencia).
            $this->audit->critical(AuditAction::AgenciaDeleted, (new AuditEventData(
                action: AuditAction::AgenciaDeleted,
                entityType: 'agencia',
                entityId: (int) $agencia->id,
                scope: $scope,
                before: $before,
            ))->withReason($reason, $reasonText));
        });

        return response()->json(['message' => 'Agencia eliminada correctamente.']);
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
     * Record the P1 `agencia.updated` event for the fields that actually
     * changed, when at least one auditable field changed.
     */
    private function recordAgenciaUpdated(
        Agencia $agencia,
        array $before,
        ?AuditReason $reason,
        ?string $reasonText,
    ): void {
        $auditedKeys = array_values(array_intersect(
            array_keys($agencia->getChanges()),
            self::AGENCIA_AUDIT_FIELDS,
        ));

        if ($auditedKeys === []) {
            return;
        }

        $agencia->unsetRelation('grupo');

        $this->audit->record(AuditAction::AgenciaUpdated, (new AuditEventData(
            action: AuditAction::AgenciaUpdated,
            entityType: 'agencia',
            entityId: (int) $agencia->id,
            scope: $this->scopeResolver->forModel($agencia),
            before: Arr::only($before, $auditedKeys),
            after: Arr::only($this->agenciaSnapshot($agencia), $auditedKeys),
        ))->withReason($reason, $reasonText));
    }

    /**
     * Safe agencia snapshot: no timestamps, no soft-delete marker, no secrets.
     *
     * @return array<string, mixed>
     */
    private function agenciaSnapshot(Agencia $agencia): array
    {
        return $agencia->only(self::AGENCIA_AUDIT_FIELDS);
    }

    // --- Autorización ---

    /**
     * La agencia (local) no gestiona agencias: solo lectura de su propio local.
     * Los demás roles gestionan solo dentro de su alcance (AlcanceService).
     */
    private function authorizeGestion($user, ?Agencia $agencia = null): void
    {
        if ($user->hasRole('agencia')) {
            abort(403, 'No tienes permisos para gestionar agencias.');
        }

        if ($agencia !== null && ! $this->alcance->puedeGestionar($user, 'agencia', $agencia)) {
            abort(403, 'No tienes acceso a esta agencia.');
        }
    }

    private function authorizeGrupoAccess($user, int $grupoId): void
    {
        $grupo = Grupo::find($grupoId);
        if (! $grupo) {
            abort(404, 'Grupo no encontrado.');
        }

        if ($user->hasRole('super_master')) {
            return;
        }

        // Master solo accede a grupos de sus bancas
        if ($user->hasRole('master')) {
            if ($grupo->banca_id === null || ! $user->masterCanAccessBanca((int) $grupo->banca_id)) {
                abort(403, 'No tienes acceso a este grupo.');
            }

            return;
        }

        if ($user->hasRole('banca')) {
            if (! $user->banca_id || $user->banca_id != $grupo->banca_id) {
                abort(403, 'No tienes acceso a este grupo.');
            }

            return;
        }

        if ($user->hasRole('grupo')) {
            if (! $user->grupo_id || $user->grupo_id != $grupo->id) {
                abort(403, 'No tienes acceso a este grupo.');
            }

            return;
        }

        abort(403, 'No tienes permiso para acceder a este grupo.');
    }

    private function authorizeAgenciaAccess($user, Agencia $agencia): void
    {
        // Lectura acotada al alcance del rol (fuente única: AlcanceService, ENTIDADES).
        if (! $this->alcance->alcanceDeEntidad($user, 'agencia', $agencia)) {
            abort(403, 'No tienes acceso a esta agencia.');
        }
    }
}
