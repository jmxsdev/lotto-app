<?php

namespace App\Http\Controllers\Api;

use App\Audit\AuditAction;
use App\Audit\AuditEventData;
use App\Audit\AuditReason;
use App\Audit\AuditRecorder;
use App\Audit\AuditScopeResolver;
use App\Http\Controllers\Controller;
use App\Models\Banca;
use App\Models\User;
use App\Services\JuegoLimiteService;
use App\Support\Scope\AlcanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class BancaController extends Controller
{
    /**
     * Safe banca attributes captured in audit before/after payloads.
     *
     * Timestamps, the soft-delete marker and internal columns are excluded.
     *
     * @var array<int, string>
     */
    private const BANCA_AUDIT_FIELDS = [
        'name', 'code', 'config', 'monedas_permitidas', 'vigencia_premios',
        'tiempo_eliminacion', 'active', 'created_by', 'master_id', 'rif',
        'email', 'telefono', 'direccion', 'estado', 'municipio',
    ];

    public function __construct(
        private readonly AlcanceService $alcance,
        private readonly AuditRecorder $audit,
        private readonly AuditScopeResolver $scopeResolver,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Banca::query()->withCount('grupos', 'users')->with('master');

        // Scoping jerárquico por rol (fuente única: AlcanceService, ENTIDADES).
        // master ve solo las bancas que administra; sin bancas ve NADA.
        $query = $this->alcance->applyToEntidades(
            $query,
            $user,
            'banca',
            'No tienes permiso para ver bancas.'
        );

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $authUser = $request->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:bancas,code',
            'config' => 'nullable|array',
            'active' => 'boolean',
            'monedas_permitidas' => 'sometimes|array',
            'monedas_permitidas.bs' => 'boolean',
            'monedas_permitidas.usd' => 'boolean',
            'vigencia_premios' => 'nullable|integer|min:1',
            'tiempo_eliminacion' => 'nullable|integer|min:1|max:120',
            'master_id' => ['nullable', 'exists:users,id', function ($attribute, $value, $fail) {
                if ($value !== null && ! User::where('id', $value)->role('master')->exists()) {
                    $fail('El master seleccionado no tiene el rol master.');
                }
            }],
            'rif' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'telefono' => 'nullable|string|max:30',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'nullable|string|max:100',
            'municipio' => 'nullable|string|max:100',
            'user_name' => 'required|string|max:255',
            'user_email' => 'required|email|unique:users,email',
            'user_password' => 'required|string|min:8',
            // Create mode: límites iniciales persistidos en la misma transacción
            'limites' => 'nullable|array',
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        $banca = null;
        $user = null;
        [$reason, $reasonText] = $this->auditReason($request);

        DB::transaction(function () use ($request, $authUser, &$banca, &$user, $reason, $reasonText) {
            $banca = Banca::create([
                'name' => $request->name,
                'code' => $request->code,
                'config' => $request->config,
                'monedas_permitidas' => $request->monedas_permitidas ?? null,
                'vigencia_premios' => $request->vigencia_premios ?? null,
                'tiempo_eliminacion' => $request->tiempo_eliminacion ?? null,
                'active' => $request->active ?? true,
                'created_by' => $authUser->id,
                // Si el creador es un master, la banca queda administrada por él
                // (coherente con el backfill F0: master_id = created_by).
                'master_id' => $authUser->hasRole('master') ? $authUser->id : ($request->master_id ?? null),
                'rif' => $request->rif,
                'email' => $request->email,
                'telefono' => $request->telefono,
                'direccion' => $request->direccion,
                'estado' => $request->estado,
                'municipio' => $request->municipio,
            ]);

            // P1 best-effort: creation evidence never blocks the entity.
            $this->audit->record(AuditAction::BancaCreated, (new AuditEventData(
                action: AuditAction::BancaCreated,
                entityType: 'banca',
                entityId: (int) $banca->id,
                scope: $this->scopeResolver->forModel($banca),
                after: $this->bancaSnapshot($banca),
            ))->withReason($reason, $reasonText));

            $user = User::create([
                'created_by' => $authUser->id,
                'name' => $request->user_name,
                'email' => $request->user_email,
                'password' => Hash::make($request->user_password),
                'banca_id' => $banca->id,
                'active' => $request->active ?? true,
            ]);

            $user->assignRole('banca');

            // P0 fail-closed: the generated operator shares the transaction
            // with the banca, so a failed insert rolls everything back.
            $this->audit->record(AuditAction::UserCreated, (new AuditEventData(
                action: AuditAction::UserCreated,
                entityType: 'user',
                entityId: (int) $user->id,
                scope: $this->scopeResolver->forModel($user),
                after: $this->bancaUserSnapshot($user),
            ))->withReason($reason, $reasonText));

            // Límites iniciales (create mode): atómicos con la entidad.
            // Cualquier error de validación/restrictividad revierte TODO.
            if (! empty($request->limites)) {
                app(JuegoLimiteService::class)->validarItems($request->limites);
                app(JuegoLimiteService::class)->persistirParaEntidad($request->limites, 'banca', $banca->id);
            }
        });

        return response()->json([
            'banca' => $banca,
            'user' => $user->load('roles'),
        ], 201);
    }

    public function show(Banca $banca)
    {
        // Lectura acotada al alcance del rol (fuente única: AlcanceService, ENTIDADES).
        if (! $this->alcance->alcanceDeEntidad(auth()->user(), 'banca', $banca)) {
            return response()->json(['message' => 'No tienes permiso para ver esta banca.'], 403);
        }

        return response()->json($banca->load('grupos', 'users', 'master'));
    }

    public function update(Request $request, Banca $banca)
    {
        // Escritura acotada al alcance de gestión del rol (AlcanceService, ENTIDADES).
        if (! $this->alcance->puedeGestionar(auth()->user(), 'banca', $banca)) {
            return response()->json(['message' => 'No tienes permiso para modificar esta banca.'], 403);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => ['sometimes', 'string', Rule::unique('bancas')->ignore($banca->id)],
            'config' => 'nullable|array',
            'active' => 'boolean',
            'monedas_permitidas' => 'sometimes|array',
            'monedas_permitidas.bs' => 'boolean',
            'monedas_permitidas.usd' => 'boolean',
            'vigencia_premios' => 'nullable|integer|min:1',
            'tiempo_eliminacion' => 'nullable|integer|min:1|max:120',
            'master_id' => ['nullable', 'exists:users,id', function ($attribute, $value, $fail) {
                if ($value !== null && ! User::where('id', $value)->role('master')->exists()) {
                    $fail('El master seleccionado no tiene el rol master.');
                }
            }],
            'rif' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'telefono' => 'nullable|string|max:30',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'nullable|string|max:100',
            'municipio' => 'nullable|string|max:100',
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        $data = $request->only(['name', 'code', 'config', 'active', 'monedas_permitidas', 'vigencia_premios', 'tiempo_eliminacion', 'master_id', 'rif', 'email', 'telefono', 'direccion', 'estado', 'municipio']);
        $before = $this->bancaSnapshot($banca);
        [$reason, $reasonText] = $this->auditReason($request);

        // P1 best-effort: a failed audit write never blocks the update.
        DB::transaction(function () use ($banca, $data, $before, $reason, $reasonText): void {
            $banca->update($data);

            $auditedKeys = array_values(array_intersect(
                array_keys($banca->getChanges()),
                self::BANCA_AUDIT_FIELDS,
            ));

            if ($auditedKeys === []) {
                return;
            }

            $after = $this->bancaSnapshot($banca);

            $event = (new AuditEventData(
                action: AuditAction::BancaUpdated,
                entityType: 'banca',
                entityId: (int) $banca->id,
                scope: $this->scopeResolver->forModel($banca),
                before: Arr::only($before, $auditedKeys),
                after: Arr::only($after, $auditedKeys),
            ))->withReason($reason, $reasonText);

            $this->audit->record(AuditAction::BancaUpdated, $event);
        });

        return response()->json($banca);
    }

    /**
     * Alternar el estado activo de una banca.
     * PATCH /api/bancas/{banca}/toggle
     * No propaga cambios a entidades hijas: el activo efectivo se resuelve en runtime.
     */
    public function toggle(Request $request, Banca $banca)
    {
        // Gestión acotada (fuente única: AlcanceService, ENTIDADES).
        if (! $this->alcance->puedeGestionar(auth()->user(), 'banca', $banca)) {
            return response()->json(['message' => 'No tienes permiso para modificar esta banca.'], 403);
        }

        $request->validate([
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        $activeBefore = (bool) $banca->active;
        $activeAfter = ! $activeBefore;
        [$reason, $reasonText] = $this->auditReason($request);

        // P0 fail-closed: a missing/invalid reason or a write failure rolls the
        // toggle back (the recorder throws AuditValidationException/AuditWriteException).
        DB::transaction(function () use ($banca, $activeBefore, $activeAfter, $reason, $reasonText): void {
            $banca->update(['active' => $activeAfter]);

            $event = (new AuditEventData(
                action: AuditAction::BancaToggled,
                entityType: 'banca',
                entityId: (int) $banca->id,
                scope: $this->scopeResolver->forModel($banca),
                before: ['active' => $activeBefore],
                after: ['active' => $activeAfter],
            ))->withReason($reason, $reasonText);

            $this->audit->critical(AuditAction::BancaToggled, $event);
        });

        return response()->json($banca);
    }

    public function destroy(Request $request, Banca $banca)
    {
        // Gestión acotada (fuente única: AlcanceService, ENTIDADES).
        if (! $this->alcance->puedeGestionar(auth()->user(), 'banca', $banca)) {
            return response()->json(['message' => 'No tienes permiso para eliminar esta banca.'], 403);
        }

        $request->validate([
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        if ($banca->grupos()->count() > 0) {
            return response()->json(['message' => 'No se puede eliminar la banca porque tiene grupos asociados.'], 422);
        }

        if ($banca->users()->count() > 0) {
            return response()->json(['message' => 'No se puede eliminar la banca porque tiene usuarios asociados.'], 422);
        }

        $before = $this->bancaSnapshot($banca);
        $scope = $this->scopeResolver->forModel($banca);
        [$reason, $reasonText] = $this->auditReason($request);

        // P0 fail-closed: a missing/invalid reason or a write failure keeps the
        // banca alive (the soft delete rolls back with the transaction).
        DB::transaction(function () use ($banca, $before, $scope, $reason, $reasonText): void {
            $banca->delete();

            $event = (new AuditEventData(
                action: AuditAction::BancaDeleted,
                entityType: 'banca',
                entityId: (int) $banca->id,
                scope: $scope,
                before: $before,
            ))->withReason($reason, $reasonText);

            $this->audit->critical(AuditAction::BancaDeleted, $event);
        });

        return response()->json(['message' => 'Banca eliminada correctamente.']);
    }

    // --- Helpers de auditoría ---

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
     * Safe banca snapshot: no timestamps, no soft-delete marker, no secrets.
     *
     * @return array<string, mixed>
     */
    private function bancaSnapshot(Banca $banca): array
    {
        return $banca->only(self::BANCA_AUDIT_FIELDS);
    }

    /**
     * Safe snapshot of the operator generated alongside a banca. Roles come
     * from Spatie; the password hash is never read here.
     *
     * @return array<string, mixed>
     */
    private function bancaUserSnapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'active' => (bool) $user->active,
            'banca_id' => $user->banca_id,
            'created_by' => $user->created_by,
            'roles' => $user->getRoleNames()->all(),
        ];
    }
}
