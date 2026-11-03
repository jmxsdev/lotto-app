# Design: autorizacion-rol-unico

## 1. Contexto y objetivos

El backend tiene dos fuentes de rol que divergen: la columna `users.role` (default `taquilla`, sin índice ni FK, creada en `2026_07_10_035612_add_roles_and_relations_to_users_table.php`) alimenta el scoping de controllers/policies, y Spatie (`roles`/`model_has_roles`, guard `api`) alimenta el middleware de rutas (`role:`/`permission:`), `VerifyMac` y 106 usos de `hasRole`/`hasAnyRole` ya existentes.

Inventario verificado en este worktree:

| Evidencia | Cantidad / ubicación |
|---|---|
| Referencias `->role` en `app/` | 115 (JuegoController 40, ApuestaController 15, TicketController 12, ApuestaPolicy 12, ReporteController 10, Api\AuthController 8, UserController 6, EstadisticaController 5, BancaController 2, Admin\AuthController 2, PagoController 1, ComisionController 1, AgenciasBackfill 1). Las 2 de `BancaController` ya son el scope Spatie `role()`: 113 lecturas de columna a migrar |
| Queries `where('role', ...)` | `CierreService` 169/173/176, `AgenciaController::destroy` 178, `UserController::index` 111, `AgenciasBackfill` 39/95, `E2eSeeder` 80/130/131 |
| Escrituras de `role` | `UserController::store/update`, `Banca/Grupo/TaquillaController`, `UsersSeeder` (7), `E2eSeeder`, `UserFactory` |
| Fixtures de test con `'role' =>` | 84 en 30 archivos; 31 usan rol distinto de `taquilla`; 0 lecturas `->role` en tests |
| Tabla de auditoría | `logs` ya existe: `user_id`, `action`, `details` (json, cast `array`), `ip`, `user_agent` |

Objetivo: Spatie como única fuente de verdad, accessor calculado `role` para no romper API/panel, auditoría `user.role_changed`, y eliminación de la columna en dos merges (expand/contract). Fuera de alcance: #84 (scoping incompleto de `TicketController`), #85 (ScopeService), #89 (auditoría completa), BE-04 (permission middleware) y frontend.

## 2. Decisiones de arquitectura

| # | Decisión | Alternativas consideradas | Elección | Razón |
|---|---|---|---|---|
| D1 | Fuente de verdad | Columna + Spatie sincronizadas; solo columna; solo Spatie | **Solo Spatie** | Elimina la divergencia de raíz; coincide con middleware, `VerifyMac` y permisos |
| D2 | Compatibilidad API | Cambiar contrato y adaptar frontend; accessor | **Accessor `role` no persistido** (`$appends`, `getRoleAttribute()`) | Cero cambios de frontend; `login`, `me`, `users` conservan shape y valor Spatie |
| D3 | `primaryRole()` | `getRoleNames()->first()` (orden de BD); último asignado; prioridad fija | **Prioridad fija**: `super_master > master > banca > grupo > agencia > taquilla`; fallback ordenado para roles fuera de catálogo | Determinista y coherente con la jerarquía; el dato actual es mono-rol, así que no cambia el comportamiento |
| D4 | Usuario sin rol Spatie | Default `taquilla`; lanzar excepción; `null` | **`null` + fail-closed** (403 o scope vacío) | No se inventan privilegios; cumple REQ-2/REQ-4 |
| D5 | Conversión de lecturas | Refactor a `match` por rol; accessor en todas partes | **`hasRole`/`hasAnyRole`/`role()` por rama, preservando orden y semántica** | Diff mecánico y revisable; el accessor queda solo para serialización/contrato |
| D6 | `$fillable` | Dejar `role` hasta el drop | **Quitar `role` en Merge 1** | La columna queda realmente muerta: ninguna escritura accidental; el atributo de request sigue validándose y se aplica vía Spatie |
| D7 | Escritura de rol | `assignRole`; `syncRoles` | **`syncRoles([$rol])` dentro de `DB::transaction`** | Idempotente y garantiza el invariante mono-rol del dominio |
| D8 | Auditoría | Evento/queue; tabla nueva; `logs` existente | **`logs` con `action='user.role_changed'` y `details` array** | Reutiliza infraestructura y patrón de `PagoController`; atómica con la transacción |
| D9 | Migración Merge 1 | Migración no-op; backfill | **Ninguna migración** | Expand es solo código; evita acoplar deploy y esquema |
| D10 | Migración Merge 2 | `dropColumn` directo; renombrar columna | **`dropColumn` idempotente (`Schema::hasColumn`) + `down` que re-agrega nullable/default** | Reintentable; el rollback de esquema no revienta si se corre dos veces |
| D11 | Factory | Default `taquilla`; rol obligatorio | **Sin rol por defecto (fail-closed) + `withRole()` con `syncRoles`** | Si el factory asignara `taquilla`, los ~46 tests que hacen `assignRole()` después quedarían con doble rol (`taquilla` + `master`). Sin default, solo 5 fixtures dependían de la columna y se migran explícitamente |
| D12 | Delivery | Un PR único; WUs secuenciales | **7 WUs apilados; WU-1..6 = Merge 1, WU-7 = Merge 2** | Cada PR ≤ ~280 líneas, revisable y con rollback propio; presupuesto 800 líneas |

### Contratos no obvios

Accessor sin recursión ni lectura de columna:

```php
protected $appends = ['role'];

private const ROLE_PRIORITY = [
    'super_master', 'master', 'banca', 'grupo', 'agencia', 'taquilla',
];

public function primaryRole(): ?string
{
    $names = $this->getRoleNames(); // Spatie; nunca $this->role

    foreach (self::ROLE_PRIORITY as $role) {
        if ($names->contains($role)) {
            return $role;
        }
    }

    return $names->sort()->first(); // rol fuera de catálogo: no se inventa
}

public function getRoleAttribute(): ?string
{
    return $this->primaryRole();
}
```

Auditoría (detalle exacto; `details` es array, nunca `json_encode`):

```php
$before = $user->getRoleNames()->sort()->values()->all();
$cambioRol = $request->filled('role') && $before !== [$request->role];

DB::transaction(function () use ($request, $currentUser, $user, $data, $cambioRol, $before) {
    $user->update($data); // $data sin 'role'

    if ($cambioRol) {
        $user->syncRoles([$request->role]);

        Log::create([
            'user_id'    => $currentUser->id,           // actor
            'action'     => 'user.role_changed',
            'details'    => [
                'actor_id'     => $currentUser->id,
                'target_id'    => $user->id,
                'target_email' => $user->email,
                'before'       => $before,              // string[]
                'after'        => $user->getRoleNames()->sort()->values()->all(),
            ],
            'ip'         => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
        ]);
    }
});
```

Criterio de "cambió": comparar el set completo (`before !== [$nuevo]`), no `hasRole($nuevo)`. Así también se audita el colapso de un usuario multi-rol. Sin `role` en el request, no hay log.

Factory: sin rol implícito y asignación única:

```php
public function withRole(string $role): static
{
    return $this->afterCreating(fn (User $user) => $user->syncRoles([$role]));
}

public function forAgencia(?Taquilla $taquilla = null): static
{
    // bindings taquilla/grupo/banca/agencia
    return $this->state(...)->withRole('taquilla');
}
```

`definition()` no incluye `role`; `configure()` no asigna nada. Un usuario creado por factory sin `withRole`/`assignRole` queda sin rol y cualquier autorización lo deniega (fail-closed).

Migración Merge 2:

```php
// 2026_11_10_000001_drop_role_from_users_table.php (ajustar a la fecha real del merge)
public function up(): void
{
    if (Schema::hasColumn('users', 'role')) {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('role'));
    }
}

public function down(): void
{
    if (! Schema::hasColumn('users', 'role')) {
        Schema::table('users', fn (Blueprint $t) => $t->string('role')->nullable()->default('taquilla'));
    }
}
```

## 3. Flujo de datos

```
Request HTTP
   │
   ├─► Sanctum ─► Spatie RoleMiddleware / PermissionMiddleware (rutas role:/permission:)
   │
   └─► Controller / Policy
            │
            ├─ lectura: hasRole() / hasAnyRole() / primaryRole() / User::role()
            │              │
            │              ▼
            │      model_has_roles ──► roles (guard api)   ◄── ÚNICA FUENTE
            │              │
            │              ▼
            │      scoping: whereHas / masterBanca*Scope / whereRaw('1=0')
            │
            └─ escritura (solo UserController/entidades/seeders):
                   DB::transaction
                     ├─ User::create/update  (sin 'role'; columna no escribible)
                     ├─ syncRoles([$rol])
                     └─ Log 'user.role_changed' (solo si cambió)

Serialización de User:
   $appends['role'] ─► getRoleAttribute() ─► primaryRole() ─► getRoleNames()
   (la columna legacy, si existe en Merge 1, nunca se consulta)
```

Regla de precedencia en cadenas `if/elseif`: se conserva el orden actual de cada controller. Con datos mono-rol el resultado es idéntico; con multi-rol gana la primera rama, igual que hoy con la columna.

## 4. Cambios por archivo

| Archivo | Acción | Cambio |
|---|---|---|
| `backend/app/Models/User.php` | Modificar | `$appends=['role']`; `primaryRole()`, `getRoleAttribute()`; quitar `role` de `$fillable` |
| `backend/database/factories/UserFactory.php` | Modificar | Quitar `role` de `definition()`/`forAgencia()`; `withRole()` con `syncRoles`; `forAgencia()` usa `withRole('taquilla')`; sin rol implícito |
| `backend/app/Http/Controllers/Api/UserController.php` | Modificar | `store/update` en transacción con `syncRoles`; `$data` sin `role`; filtro `index` con `role()`; auditoría; `finalRole` nulo ⇒ 403 |
| `backend/app/Http/Controllers/Api/BancaController.php` | Modificar | Quitar `'role' => 'banca'` de `User::create` |
| `backend/app/Http/Controllers/Api/GrupoController.php` | Modificar | Quitar `'role' => 'grupo'` |
| `backend/app/Http/Controllers/Api/TaquillaController.php` | Modificar | Quitar `'role' => 'taquilla'` |
| `backend/app/Http/Controllers/Api/AuthController.php` | Modificar | 8 refs a `hasRole`/`hasAnyRole`; respuesta `role => primaryRole()`; sin rol ⇒ 403 |
| `backend/app/Http/Controllers/Admin/Auth/AuthController.php` | Modificar | `hasAnyRole(['super_master','master'])`; sesión con `primaryRole()` |
| `backend/app/Http/Controllers/Api/ApuestaController.php` | Modificar | 15 refs; `index/historial/resumen` con `hasRole` + fail-closed final |
| `backend/app/Http/Controllers/Api/ReporteController.php` | Modificar | 10 refs en `buildApuestaQuery`/`buildTicketQuery`; fail-closed si no es `super_master` |
| `backend/app/Http/Controllers/Api/EstadisticaController.php` | Modificar | 5 refs; mismo patrón |
| `backend/app/Http/Controllers/Api/TicketController.php` | Modificar | 12 refs; conserva el alcance actual de `agencia`/`master` (#84); deniega solo usuario sin rol |
| `backend/app/Http/Controllers/Api/PagoController.php` | Modificar | `hasRole('taquilla')` |
| `backend/app/Http/Controllers/Api/JuegoController.php` | Modificar | 40 refs: `in_array`→`hasAnyRole`, `match(true)`→`hasRole`, ramas de límites equivalentes |
| `backend/app/Http/Controllers/Api/ComisionController.php` | Modificar | `bancasEnAlcance`: `master`⇒ids, `super_master`⇒null, resto 403 (corrige caso 1) |
| `backend/app/Http/Controllers/Api/AgenciaController.php` | Modificar | `destroy`: `users()->role('taquilla')->update(...)` |
| `backend/app/Policies/ApuestaPolicy.php` | Modificar | 12 refs a `hasRole`/`hasAnyRole` |
| `backend/app/Services/CierreService.php` | Modificar | 3 `where('role')` → `role()` |
| `backend/app/Console/Commands/AgenciasBackfill.php` | Modificar | 2 queries → `role()`; `! $creador->hasRole('master')` |
| `backend/database/seeders/UsersSeeder.php` | Modificar | Quitar 7 `'role' =>`; conservar `assignRole` |
| `backend/database/seeders/E2eSeeder.php` | Modificar | Quitar `'role' =>`; `syncRoles(['taquilla'])`; 3 queries → `role()` |
| `backend/database/migrations/2026_11_10_000001_drop_role_from_users_table.php` | Crear (WU-7) | Drop idempotente + `down` |
| `backend/tests/*` | Modificar/Crear | Fixtures `withRole`; 4 archivos nuevos de test |

## 5. Plan de migración expand/contract

| Fase | Código | Esquema | Deploy | Rollback |
|---|---|---|---|---|
| **Merge 1 (expand)**: WU-1..6 | Spatie única fuente; accessor; escrituras con `syncRoles`; queries migradas | `users.role` intacta, sin lecturas ni escrituras | Sin migración nueva; `entrypoint.sh` corre `migrate --force` sin cambios | Redeploy de imagen pre-M1: el esquema sigue igual. Riesgo: la columna queda obsoleta si hubo cambios de rol post-M1 ⇒ re-sincronizar antes de revertir (ver §9) |
| **Merge 2 (contract)**: WU-7 | Sin referencias a `role` persistido; accessor se mantiene | `DROP COLUMN role` | La migración se aplica sola en el arranque; el contenedor nuevo migra antes de servir | Imagen M1: segura (nunca leyó la columna). Imagen pre-M1: **no segura**. `down()` restaura columna vacía; los datos requieren dump/restore |

Validación de datos previa al Merge 1 y al Merge 2 (VPS dev y prod):

```sql
-- Usuarios sin rol Spatie (deben ser 0)
SELECT u.id FROM users u
LEFT JOIN model_has_roles m
  ON m.model_id = u.id AND m.model_type = 'App\\Models\\User'
WHERE m.model_id IS NULL;

-- Usuarios con más de un rol (deben ser 0; syncRoles colapsaría)
SELECT model_id, COUNT(*) FROM model_has_roles
WHERE model_type = 'App\\Models\\User'
GROUP BY model_id HAVING COUNT(*) > 1;
```

**Precondición del Merge 1**: si el primer query devuelve usuarios, tras el deploy quedarán sin acceso (fail-closed). Antes de M1 hay que asignarles el rol Spatie desde `users.role` con un one-off (script temporal, no backfill permanente) o aceptar la denegación. En VPS dev se resuelve con el wipe. Si el segundo query devuelve filas, revisar el rol esperado antes de que `syncRoles` los colapse.

Dev: `migrate:fresh --seed` manual **antes de Merge 1** para partir de datos consistentes (seeders actuales escriben columna + Spatie) y **después de Merge 2** para validar el seed sin columna. No hay backfill permanente.

## 6. Plan de tests

| Capa | Archivo | Casos |
|---|---|---|
| Unit | `tests/Unit/UserRoleAccessorTest.php` (nuevo) | accessor devuelve Spatie; ignora columna legacy divergente (escribe la columna con `DB::table` solo si `Schema::hasColumn`); `null` sin rol; `primaryRole` por prioridad; `role` presente en `toArray`; factory sin rol por defecto; `withRole` asigna exactamente un rol |
| Feature | `tests/Feature/UsuarioRolApiTest.php` (nuevo) | `store` asigna Spatie y no escribe columna; `update` cambia rol en transacción; `index` filtra por `role()`; `login` devuelve `role` Spatie |
| Feature | `tests/Feature/AuditoriaRolTest.php` (nuevo) | cambio registra `user.role_changed`; mismo rol no registra; `details` incluye actor/target/before/after; `details` es array (no string JSON); 403 no registra |
| Feature | `tests/Feature/DivergenciaRolTest.php` (nuevo, WU-6) | comisiones: columna `taquilla` + Spatie `master` ⇒ alcance master; apuestas/cierres con misma fuente; reportes con columna `super_master` + Spatie `taquilla` ⇒ alcance taquilla; `VerifyMac` solo exige MAC a `taquilla` Spatie; usuario sin rol ⇒ 403/scope vacío |
| Regresión | Suites existentes | `GestionUsuariosTest`, `RoleAuthorizationTest`, `ApuestaTest`, `EliminacionApuestasTest`, `PagoDevolucion*`, `ReporteTest`, `CuadreCajaReportTest`, `EstadisticaTest`, `Ticket*`, `Limites*`, `Juego*`, `ClaveCierreTest`, `ComisionesApiTest`, `ComisionReporteTest`, `Agencia*`, `MastersPanelTest`, `SuperBancaScopeTest`, `ApuestaPolicyAnulacionTest` |

Nombres de los tests nuevos:

- `UserRoleAccessorTest`: `test_accessor_devuelve_rol_spatie`, `test_accessor_ignora_columna_legacy_divergente`, `test_accessor_devuelve_null_sin_rol`, `test_primary_role_respeta_prioridad`, `test_role_se_serializa_en_to_array`, `test_factory_sin_rol_por_defecto`, `test_factory_with_role_asigna_un_solo_rol`.
- `UsuarioRolApiTest`: `test_store_asigna_rol_spatie`, `test_update_cambia_rol_y_audita`, `test_index_filtra_por_rol_spatie`, `test_login_devuelve_role_de_spatie`.
- `AuditoriaRolTest`: `test_cambio_de_rol_registra_user_role_changed`, `test_mismo_rol_no_registra_log`, `test_log_incluye_actor_target_before_after`, `test_details_es_array_sin_doble_json`, `test_403_no_registra_log`.
- `DivergenciaRolTest`: `test_comision_consulta_usa_spatie_master`, `test_apuestas_y_cierres_comparten_scope`, `test_reportes_usan_spatie_super_master`, `test_verify_mac_usa_spatie_taquilla`, `test_usuario_sin_rol_spatie_recibe_403`.

Migración de fixtures (verificado con script sobre 66 llamadas a `User::factory()`): 62 pasan `role` (61 literal + 1 dinámico) y la mayoría ya llama `assignRole()` después. Solo **5 fixtures dependen de la columna** y se migran a `withRole()` en WU-1: `AgenciaModelTest:30` (`taquilla`), `AgenciasBackfillTest:33` (`taquilla`), `AgenciasBackfillTest:141` (`grupo`), `ApuestaPolicyAnulacionTest:287` (`agencia`) y el helper `ApuestaPolicyAnulacionTest:134` (`usuario($role)`, usado con 6 roles y sin `assignRole`). El resto de los `'role' =>` se elimina como atributo inerte; el rol efectivo sigue viniendo de `assignRole()` explícito. Esto evita el doble rol que produciría un default `taquilla`.

Además hay **4 aserciones de BD sobre la columna** que el grep de `->role` no detecta: `GestionUsuariosTest:102/123/145` y `MastersPanelTest:105` usan `assertDatabaseHas('users', ['role' => ...])`. Se reemplazan por `assertTrue(User::find($id)->hasRole($rol))` o `assertDatabaseHas('model_has_roles', ['role_id' => Role::where('name', $rol)->value('id'), 'model_id' => $id, 'model_type' => User::class])`.

Comandos (el usuario los ejecuta; el agente no):

```bash
cd backend
vendor/bin/pint --test                 # estilo (CI)
composer test                          # suite completa (~35 min secuencial)
php artisan test --filter=UserRoleAccessorTest
php artisan test --filter=UsuarioRolApiTest
php artisan test --filter=AuditoriaRolTest
php artisan test --filter=DivergenciaRolTest
```

## 7. Work units / PR slices

Presupuesto: 800 líneas por PR; estimaciones en líneas add+del. WU-1..6 forman el **Merge 1**; WU-7 es el **Merge 2**.

| WU | Merge | Objetivo y archivos | Líneas | Depende | Tests | Done |
|---|---|---|---|---|---|---|
| WU-1 | 1 | Modelo + factory + fixtures: `User.php`, `UserFactory.php`, limpieza de `'role' =>` en los factory calls, 5 `withRole` (incl. helper dinámico), `UserRoleAccessorTest.php` | ~260 | — | `pint --test` (rutas tocadas); `--filter=UserRoleAccessorTest`; `--filter=ApuestaPolicyAnulacionTest`; `--filter=AgenciasBackfillTest` | Accessor nunca lee columna; `null` sin rol; factory sin rol implícito; suite existente verde |
| WU-2 | 1 | Escrituras: `UserController` (store/update/index), `Banca/Grupo/TaquillaController`, `UsersSeeder`, `E2eSeeder` (escritura), `GestionUsuariosTest` (incl. 3 `assertDatabaseHas` con `role`), `MastersPanelTest` (1), `RoleAuthorizationTest`, `UsuarioRolApiTest` | ~220 | WU-1 | `--filter=UsuarioRolApiTest`, `--filter=GestionUsuariosTest`, `--filter=MastersPanelTest`, `--filter=RoleAuthorizationTest` | Cero escrituras de `role`; store/update transaccionales; filtro `role()`; assertions contra `model_has_roles` |
| WU-3 | 1 | Auditoría: `UserController::update`, `AuditoriaRolTest` | ~170 | WU-2 | `--filter=AuditoriaRolTest`, `--filter=GestionUsuariosTest` | Un log por cambio real; ninguno sin cambio/403; `details` array |
| WU-4 | 1 | Scoping apuestas/reportes/estadísticas/tickets/pagos: `ApuestaController`, `ReporteController`, `EstadisticaController`, `TicketController`, `PagoController`, `ApuestaPolicy`, fixtures de esas suites | ~280 | WU-1 | `--filter=Apuesta`, `--filter=PagoDevolucion`, `--filter=Reporte`, `--filter=CuadreCaja`, `--filter=Estadistica`, `--filter=Ticket`, `--filter=ApuestaPolicy` | 0 `->role` en esos archivos; fail-closed; #84 intacto |
| WU-5 | 1 | Juego/límites: `JuegoController`, `LimitesApiTest`, `LimitesScopedApiTest`, `JuegoLimiteServiceTest`, `JuegoPremiosApiTest`, `JuegoToggleTest`, `JuegoUpdateTest`, `SuperBancaScopeTest`, `AgenciaMasterScopeTest` | ~240 | WU-1 | `--filter=Limites`, `--filter=Juego`, `--filter=SuperBancaScope`, `--filter=AgenciaMasterScope` | 40 refs convertidas; suites de límites verdes |
| WU-6 | 1 | Login/panel + queries ocultas: `Api\AuthController`, `Admin\AuthController`, `ComisionController`, `AgenciaController`, `CierreService`, `AgenciasBackfill`, `E2eSeeder` (lectura), `DivergenciaRolTest`, `ClaveCierreTest`, `ComisionesApiTest`, `ComisionReporteTest`, `Agencia*`, `MastersPanelTest` | ~250 | WU-2, WU-4 | `--filter=DivergenciaRol`, `--filter=ClaveCierre`, `--filter=Comision`, `--filter=Agencia`, `--filter=MastersPanel`, `--filter=RoleAuthorization` | 6 queries ocultas migradas; 4 divergencias cubiertas; login Spatie |
| WU-7 | 2 | Contract: migración drop, limpieza de fixtures/pruebas condicionadas, `composer test` completo | ~140 | WU-1..6 mergeados y desplegados | `pint --test`; `composer test` | Columna ausente en DB fresh; grep sin `where('role')`/`User::factory` con `role`; dev con `migrate:fresh --seed` |

Mecánica de PR (stacked-to-main): WU-1 nace de `main`; WU-2 nace de WU-1; WU-3 de WU-2; y así hasta WU-6. Cada PR N apunta a la rama de WU-(N-1) mientras el anterior está abierto; al mergear WU-(N-1) a `main`, se retargetea WU-N a `main`. WU-7 (contract) nace de `main` después de que WU-1..6 estén mergeados y desplegados. Cada WU pausa para que el usuario corra sus tests; el agente no ejecuta tests.

## 8. Riesgos y mitigaciones

| Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|
| Regresión de scoping en 115 refs | Alta | Alto | Conversión mecánica por controller, un WU por dominio, suites existentes + divergencia |
| El accessor no gana a la columna en Merge 1 | Media | Alto | Test unitario que escribe la columna con `DB::table` y exige `$user->role` Spatie + `toArray()` Spatie |
| Fixture que dependía de la columna queda sin rol | Baja | Medio | Solo son 5 (verificado); pasan a `withRole` en WU-1; el resto ya usa `assignRole` |
| Usuarios solo-columna pierden acceso tras M1 | Media | Alto | Query pre-M1 de usuarios sin rol Spatie; one-off desde `users.role`; en dev, wipe |
| Aserción oculta contra `users.role` en tests | Media | Medio | 4 `assertDatabaseHas` migran a `model_has_roles`/`hasRole` en WU-2; el grep de fixtures no las detecta |
| Doble rol por default del factory | Media | Alto | El factory no asigna rol por defecto; `withRole` usa `syncRoles`, nunca `assignRole` acumulativo |
| `JuegoController` (40 refs) cambia semántica | Media | Alto | WU dedicado; `match(true)` reescrito con ramas explícitas; suites de límites completas |
| #84 se "arregla" sin querer en `TicketController` | Media | Medio | Preservar ramas actuales; fail-closed solo para usuario sin rol; documentar `agencia`/`master` globales |
| Rollback M1→pre-M1 con columna obsoleta | Media | Medio | Ventana corta; antes de revertir, re-sincronizar `users.role` desde Spatie con script ad-hoc |
| Rollback M2→pre-M1 rompe (columna ausente) | Baja | Alto | Revertir solo a imagen M1; dump previo de `users`/`roles`/`model_has_roles`; `down()` solo restaura esquema vacío |
| Multi-rol legacy colapsado por `syncRoles` | Baja | Medio | SQL de validación pre-M2; auditar `before` completo; datos actuales mono-rol |
| Cache de Spatie obsoleto | Baja | Bajo | `optimize:clear` ya corre en `entrypoint.sh`; `permission:cache-reset` si hay anomalías |
| N+1 del accessor al serializar listas | Baja | Bajo | `UserController::index/show/store/update` ya cargan `roles`; nuevos endpoints deben eager-load |

## 9. Impacto CI/CD y rollback

- **CI** (`.github/workflows/ci-cd.yml`): corre solo en `push` a `main` y `workflow_dispatch`; las ramas `feature/*` no gatean. Por eso cada WU exige `pint --test` + filtros locales antes del merge; la suite completa corre en `tests` al mergear a `main` (`php artisan test --parallel --processes=4`).
- **Deploy**: `deploy` depende de `build` (`needs: build`) y solo corre en `main`; healthcheck con `/api/v1/juegos` y rollback automático a la imagen `PREV` (tag `rollback`). `e2e` corre en paralelo sin `needs`: **no gatea** el deploy.
- **Migraciones**: `backend/entrypoint.sh` ejecuta `php artisan migrate --force` antes de levantar FrankenPHP; el contenedor nuevo migra antes de servir. Merge 1 no agrega migraciones. Merge 2 agrega el drop y se aplica solo al desplegar.
- **Pre-deploy Merge 1**: ejecutar la query de usuarios sin rol Spatie y resolver los casos solo-columna; de lo contrario esos usuarios quedan en 403 tras el deploy (fail-closed).
- **Merge 2**: hacer dump previo de `users`, `roles` y `model_has_roles`; ejecutar el SQL de validación de §5; desplegar en ventana de bajo tráfico. `migrate:fresh --seed` es manual y solo en VPS dev.
- **Rollback**: M1→pre-M1 requiere re-sincronizar la columna antes de revertir; M2→M1 es seguro; M2→pre-M1 no. El rollback de imagen no revierte esquema: para eso existe `down()` más restore de datos.

## 10. Threat Matrix

N/A — el cambio no toca routing, shell, subprocesos, automatización VCS/PR, clasificación de ejecutables ni integración de procesos. Solo lógica de autorización y esquema de datos.

## 11. Open Questions

Ninguna bloqueante.

- El accessor `role` se mantiene como contrato público después del Merge 2 hasta que el frontend migre (fuera de alcance).
- Auditar `store` (alta de usuario) queda fuera de este change; solo `update` emite `user.role_changed`.
- #84 (`TicketController` sin ramas `agencia`/`master`) se preserva deliberadamente y se resuelve en su propio issue.
