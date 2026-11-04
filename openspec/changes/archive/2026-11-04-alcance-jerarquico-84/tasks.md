# Tasks: Alcance jerárquico uniforme (#84)

> Rutas de código relativas a `backend/` (monorepo: `backend/` es el proyecto Laravel).
> Nota: `openspec/**/*.md` está en `.gitignore`; todo artefacto OpenSpec requiere `git add -f`.

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines (total) | 1100–1530 |
| Review budget por PR | **800 líneas** (override del orquestador sobre el default 400) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1a → PR 1b → PR 2 → PR 3 (encadenados, stacked-to-main) |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Base | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|------|----------------------|-----------------|-------------------|
| 1a | `users.created_by` (migración + modelo + stores + seeders) | `feat/84a-usuarios-created-by` | `main` | `php artisan test --filter=CreatedBy` | `php artisan migrate:fresh --seed` (lo corre el usuario) | Revertir migración `down()`; el modelo/stores/seeders revierten sin tocar datos |
| 1b | `UserPolicy` + refactor de autorización en `UserController` | `feat/84b-user-policy-controller` | rama 1a | `php artisan test --filter=AlcanceJerarquicoUsuariosTest` | `composer test` local (usuario) | Revertir merge de la rama; código aislado en Policy+Controller |
| 2 | Apuesta/Pago/Ticket scope master (casos 2/3/4) | `feat/84c-apuestas-pagos-tickets` | rama 1b | `php artisan test --filter='ApuestaScopePolicyTest\|PagoEgresoScopeTest\|TicketMasterScopeTest'` | `composer test` local (usuario) | Revertir merge; sin cambios de esquema |
| 3 | Límites + ficha propia agencia + regresión caso 5 | `feat/84d-limites-agencia-regresion` | rama 2 | `php artisan test --filter='LimitesEscrituraScopeTest\|AgenciaSelfUpdateTest\|TicketAnulacionRegresionTest'` | `composer test` local (usuario) | Revertir merge; sin cambios de esquema |

## Regla operativa de apply (MANDATORIA)

1. **Un WU por invocación**: `sdd-apply` procesa exactamente UNA work unit y se detiene.
2. **Tests primero**: en cada WU, escribir/actualizar los tests ANTES que el código de producción (RED → GREEN).
3. **NO ejecutar tests ni migraciones**: el agente solo DEJA los comandos; el usuario los corre localmente.
4. **Devolver comandos**: al terminar cada WU, devolver solo los comandos de test enfocados. `composer test` y `pint --test` se corren UNA sola vez al cierre del ciclo (decisión del maintainer: es caro y lento).
5. **Detenerse**: al finalizar un WU, reportar y esperar. NO continuar al siguiente WU sin nueva invocación.
6. **Commit por WU**: commits convencionales en español (`feat`, `fix`, `test`, `chore`). Los artefactos OpenSpec se commitean con `git add -f openspec/...`.

## Fases / Work Units

### WU-1a — `users.created_by`: migración, modelo, stores y seeders

**Rama**: `feat/84a-usuarios-created-by` ← `main`
**Líneas estimadas**: 300–380

- [x] **1a.1** Crear `database/migrations/2026_11_04_000001_add_created_by_to_users_table.php`: columna `created_by` nullable `after('id')`, `foreignId(...)->nullable()->constrained('users')->nullOnDelete()` + índice; `down()` revierte. Sin backfill (existentes quedan `null`).
- [x] **1a.2** `app/Models/User.php`: añadir `created_by` a `$fillable`; añadir relaciones `creator()` (`belongsTo(User::class, 'created_by')`) y `createdUsers()` (`hasMany(User::class, 'created_by')`).
- [x] **1a.3** `app/Http/Controllers/Api/UserController.php` `store()`: añadir `'created_by' => $user->id` al array de `User::create()`. (Solo la línea de datos; el refactor de autorización es 1b.)
- [x] **1a.4** `app/Http/Controllers/Api/BancaController.php` `store()`: añadir `'created_by' => $authUser->id` al `User::create()` (línea ~90).
- [x] **1a.5** `app/Http/Controllers/Api/GrupoController.php` `store()`: añadir `'created_by' => $user->id` al `User::create()`.
- [x] **1a.6** `app/Http/Controllers/Api/TaquillaController.php` `store()`: añadir `'created_by' => $user->id` al `User::create()` (línea ~182).
- [x] **1a.7** `database/seeders/UsersSeeder.php` y `database/seeders/E2eSeeder.php`: encadenar creadores super→master→banca→grupo→agencia/taquillas (`created_by` del actor en cada usuario).

**Tests a escribir/actualizar** (primero):
- [x] **1a.T1** Nuevo `tests/Feature/CreatedByTest.php`: usuario creado por `UserController::store` registra `created_by = auth()->id()`; backfill/migración deja `created_by = null` en existentes; `creator()`/`createdUsers()` devuelven la cadena.
- [x] **1a.T2** Ajustar seeders test (si existe fixture que valida conteo de usuarios) para que no rompa por la nueva columna nullable.

**Comandos (NO ejecutados — los corre el usuario)**:
```bash
cd backend
php artisan migrate
php artisan test --filter=CreatedByTest
php artisan migrate:fresh --seed   # verificación de cadena de creadores en seeders
```

**Done**: `created_by` nullable presente y poblado en los 4 stores y seeders; relaciones expuestas; `CreatedByTest` en verde.
**Rollback**: `php artisan migrate:rollback --step=1` revierte la columna (metadata, sin pérdida de datos funcionales).

---

### WU-1b — `UserPolicy` + autorización jerárquica en `UserController`

**Rama**: `feat/84b-user-policy-controller` ← `feat/84a-usuarios-created-by`
**Líneas estimadas**: 300–400

- [x] **1b.1** Crear `app/Policies/UserPolicy.php` con contrato `view(User, User)`, `update(User, User)`, `delete(User, User)`, `canCreate(User, string, array)`, `canAssign(User, string, array, User)` (todas `bool`).
- [x] **1b.2** Implementar matriz jerárquica en `UserPolicy`: super_master intocable y no borra otro super; master no gestiona super/otros masters/su `created_by`; banca/grupo/agencia gestionan su subárbol + creados, sin pares ni superiores; taquilla solo `view` propio.
- [x] **1b.3** `UserController::authorizeUserAccess` → delegar a `UserPolicy::view/update/delete`; preservar helpers `resolveBancaId/resolveGrupoId/resolveAgenciaId`.
- [x] **1b.4** `UserController::authorizeEntityBinding` → delegar a `UserPolicy::canCreate/canAssign`; bloquear pares (banca→banca, grupo→grupo, agencia→agencia) y autopromoción (`canAssign`).
- [x] **1b.5** `UserController::show/update/destroy`: usar `authorize('view'|'update'|'delete', $user)`; `destroy` reemplaza el guard `hasRole(['super_master','master'])` por la policy (permite borrado por banca/grupo/agencia de subárbol/creados de rango menor).
- [x] **1b.6** `UserController::index`: incluir usuarios creados (`orWhere('created_by', $user->id)`) dentro del alcance ya existente, sin ampliar fugas.
- [x] **1b.7** Registrar `UserPolicy` en `app/Providers/*ServiceProvider.php` (Gate::policy/`policies` del modelo) si no hay auto-discovery.

**Tests a escribir/actualizar** (primero):
- [x] **1b.T1** Nuevo `tests/Feature/AlcanceJerarquicoUsuariosTest.php`: master edita/borra super 403 y password intacto; super no borra super; master no borra su creador; pares 403; taquilla solo `GET` propio; `created_by` del actor; payload ajeno ignorado; index incluye creados; banca/grupo/agencia borran subárbol/creados y no pares.
- [x] **1b.T2** Actualizar `tests/Feature/GestionUsuariosTest.php` y `tests/Feature/RoleAuthorizationTest.php` (expectativas de borrado de usuarios para banca/grupo/agencia).
- [x] **1b.T3** Actualizar `tests/Feature/MastersPanelTest.php` (master ya no ve super/otros masters).

**Comandos (NO ejecutados)**:
```bash
cd backend
php artisan test --filter=AlcanceJerarquicoUsuariosTest
php artisan test --filter='GestionUsuariosTest|RoleAuthorizationTest|MastersPanelTest'
```

**Done**: `UserPolicy` concentra view/create/update/delete; super intocable; pares y superiores prohibidos; `created_by` opera como metadata; tests de jerarquía en verde.
**Rollback**: revertir merge de la rama; código aislado en `UserPolicy` + `UserController`.

---

### WU-2 — `ApuestaPolicy` + `PagoController` + `TicketController` (casos 2/3/4)

**Rama**: `feat/84c-apuestas-pagos-tickets` ← `feat/84b-user-policy-controller`
**Líneas estimadas**: 250–350

- [x] **2.1** `app/Policies/ApuestaPolicy.php` `view()`/`poseeApuesta()`: master acotado a sus bancas (`masterBancaChainScope`/`masterCanAccessBanca`); master sin bancas → `false`; super global; null/sin bancas → `false`.
- [x] **2.2** `app/Http/Controllers/Api/PagoController.php` `store()`: reemplazar check `if ($user->hasRole('taquilla') && ...)` por `$this->authorize('view', $apuesta)` dentro del lock (antes del estado), cubriendo banca/grupo/master/super; mantener 422 para no-egreso/doble-egreso antes de cargar la apuesta.
- [x] **2.3** `app/Http/Controllers/Api/TicketController.php` `index()/show()`: añadir rama `master` usando `masterBancaChainScope` (solo sus bancas; sin bancas → vacío; show ajeno → 403). `agencia` sigue fuera de `/tickets` (middleware actual no la incluye).
- [x] **2.4** `app/Http/Controllers/Api/TicketController.php` `ganadores()`: acotar `master` en ambas queries (sorteo y multi-sorteo) con `masterBancaChainScope`.
- [x] **2.5** `app/Http/Controllers/Api/TicketController.php` `destroy()`: solo test de regresión (ya cubierto por `TicketAnulacionService`); no reimplementar.

**Tests a escribir/actualizar** (primero):
- [x] **2.T1** Nuevo `tests/Feature/ApuestaScopePolicyTest.php`: master propia 200; ajena/sin bancas 403; super global.
- [x] **2.T2** Nuevo `tests/Feature/PagoEgresoScopeTest.php`: cross-tenant 403 sin mutación; propia 201; no-egreso/doble-egreso 422.
- [x] **2.T3** Nuevo `tests/Feature/TicketMasterScopeTest.php`: solo propias; sin bancas vacío; show ajeno 403; super global; ganadores acotados.
- [x] **2.T4** Actualizar `tests/Feature/SuperBancaScopeTest.php`, `tests/Feature/PagoDevolucionRechazosTest.php`, `tests/Feature/PagoDevolucionAnulacionTest.php`, `tests/Feature/EliminacionApuestasTest.php`, `tests/Unit/ApuestaPolicyAnulacionTest.php`, `tests/Feature/TicketGanadoresTest.php` y `tests/Feature/TicketEliminacionTest.php`. Ojo: `EliminacionApuestasTest` (Feature, ventana de anulación) y `ApuestaPolicyAnulacionTest` (Unit, `motivoNoAnulable`/ownership) son archivos distintos; ambos deben quedar en verde.

**Comandos (NO ejecutados)**:
```bash
cd backend
php artisan test --filter='ApuestaScopePolicyTest|PagoEgresoScopeTest|TicketMasterScopeTest'
php artisan test --filter='SuperBancaScopeTest|PagoDevolucion|EliminacionApuestasTest|ApuestaPolicyAnulacionTest|TicketGanadoresTest|TicketEliminacionTest'
```

**Done**: master acotado en apuestas/pagos/tickets; super global; ownership egreso por rol; tests 2/3/4 en verde.
**Rollback**: revertir merge; sin cambios de esquema.

---

### WU-3 — `JuegoController` límites + `AgenciaController` ficha propia + regresión caso 5

**Rama**: `feat/84d-limites-agencia-regresion` ← `feat/84c-apuestas-pagos-tickets`
**Líneas estimadas**: 250–400

- [x] **3.1** `app/Http/Controllers/Api/JuegoController.php` `authorizeEscrituraLimite()` (banca): validar que `grupo_id` pertenezca a su banca y que `taquilla_id` pertenezca a ese grupo; subárbol ajeno → 403.
- [x] **3.2** `app/Http/Controllers/Api/JuegoController.php` `updateLimites()`: mover `authorizeEscrituraLimite` ANTES de `validarRestrictividadLimite` (403 precede a 422).
- [x] **3.3** `app/Http/Controllers/Api/JuegoController.php` `batchLimites()`: reutilizar el helper de alcance por ítem (banca A no inyecta en subárbol de B).
- [x] **3.4** `app/Http/Controllers/Api/AgenciaController.php` `update()`: habilitar agencia a editar su propia ficha (`name/rif/email/telefono/direccion/estado/municipio`); `code/grupo_id/active` inmutables → 403; `store/toggle/destroy` bloqueados para agencia; agencia ajena → 403.
- [x] **3.5** Test de regresión caso 5: sin cambios de producción (ya cubierto por `TicketAnulacionService`).

**Tests a escribir/actualizar** (primero):
- [x] **3.T1** Nuevo `tests/Feature/LimitesEscrituraScopeTest.php`: propia 200; ajenos 403; batch por ítem; restrictivo ajeno 403 / propio 422 (orden 403→422).
- [x] **3.T2** Nuevo `tests/Feature/AgenciaSelfUpdateTest.php`: propia 200; grupo/code/active 403; otra agencia y store/toggle/destroy 403.
- [x] **3.T3** Nuevo `tests/Feature/TicketAnulacionRegresionTest.php`: líneas soft-deleted 422, sin devolución.
- [x] **3.T4** Actualizar `tests/Feature/LimitesScopedApiTest.php` y `tests/Feature/AgenciaScopeTest.php` (sin cambios: sus expectativas siguen vigentes).

**Comandos (NO ejecutados)**:
```bash
cd backend
php artisan test --filter='LimitesEscrituraScopeTest|AgenciaSelfUpdateTest|TicketAnulacionRegresionTest'
php artisan test --filter='LimitesScopedApiTest|AgenciaScopeTest'
```

**Done**: banca no inyecta límites ajenos; 403 precede a 422; agencia edita su ficha limitada; regresión caso 5 verde.
**Rollback**: revertir merge; sin cambios de esquema.

---

## Criterios de aceptación globales

- [x] `ApuestaPolicy::view/delete` niegan apuestas de banca ajena para master (403); super global; master sin bancas denegado.
- [x] `PagoController::store` rechaza egreso cross-tenant para banca/grupo/master (403), sin `Pago` ni cambio de estado.
- [x] `TicketController::index/show/ganadores` acotan master a sus bancas; super global; master sin bancas → vacío.
- [x] `authorizeEscrituraLimite` valida `grupo_id`/`taquilla_id`; `updateLimites` devuelve 403 (no 422) para datos ajenos.
- [x] `users.created_by` nullable, poblado en store/seeders; `creator()`/`createdUsers()`; backfill → `null`.
- [x] `authorizeEntityBinding`/`UserPolicy` rechazan pares (banca→banca, grupo→grupo, agencia→agencia).
- [x] super_master intocable; master no borra otro master/super/creador; banca/grupo/agencia borran subárbol/creados de rango menor.
- [x] Caso 5 cubierto solo por test de regresión.
- [x] `composer test` y `pint --test` en verde al cierre del ciclo (una sola vez, lo ejecuta el usuario).

## Riesgos

- Datos existentes sin `created_by` → columna nullable; backfill `null` (dev wipe/reseed).
- Tests de scope existentes rompen → actualizados en el mismo change (1b.T2, 2.T4, 3.T4).
- `master_id` vs `created_by` → `master_id` define bancas administradas; `created_by` solo metadata de fila.
- Orden 403/422 → autorizar antes en límites; no-egreso/doble-egreso 422 en pagos.
- `code` de agencia inmutable por diseño (documentado en spec/design).
- Mass assignment → `created_by` asignado server-side (nunca del payload) + test.

## Comandos por WU (resumen — usuario los ejecuta)

| WU | Comando enfocado |
|----|------------------|
| 1a | `php artisan test --filter=CreatedByTest` + `php artisan migrate` |
| 1b | `php artisan test --filter=AlcanceJerarquicoUsuariosTest` |
| 2 | `php artisan test --filter='ApuestaScopePolicyTest\|PagoEgresoScopeTest\|TicketMasterScopeTest'` |
| 3 | `php artisan test --filter='LimitesEscrituraScopeTest\|AgenciaSelfUpdateTest\|TicketAnulacionRegresionTest'` |
| Cierre | `composer test` + `pint --test` (una sola vez, lo corre el usuario) |

## Nota de versionado

`openspec/**/*.md` está ignorado por git: commitear con `git add -f openspec/changes/alcance-jerarquico-84/...`.
