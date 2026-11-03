# Tasks: autorizacion-rol-unico (issue #69)

## Review Workload Forecast

| Campo | Valor |
|-------|-------|
| Líneas estimadas (add+del) | ~1.560 totales; máximo ~280 por WU |
| Presupuesto de review | 800 líneas/PR (fijado por el usuario) |
| Riesgo de presupuesto | Low por WU; un PR único sería High |
| Chained PRs recomendados | Yes |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

```text
Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: Low (cada PR ≤ ~280; un PR único sería High)
```

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Modelo + factory + fixtures | PR 1 | `php artisan test --filter=UserRoleAccessorTest` | `--filter=ApuestaPolicyAnulacionTest` + `--filter=AgenciasBackfillTest` | `User.php`, `UserFactory.php`, fixtures WU-1 (revertir sin tocar controllers) |
| 2 | Escrituras + entidades + seeders + filtro index | PR 2 | `php artisan test --filter=UsuarioRolApiTest` | `--filter=GestionUsuariosTest` + `--filter=MastersPanelTest` + `--filter=RoleAuthorizationTest` | `UserController`/`Banca`/`Grupo`/`Taquilla`/seeders (revertir manteniendo WU-1) |
| 3 | Auditoría `user.role_changed` | PR 3 | `php artisan test --filter=AuditoriaRolTest` | `--filter=GestionUsuariosTest` | `UserController::update` + `AuditoriaRolTest` |
| 4 | Scoping apuestas/reportes/estadística/tickets/pagos/policy | PR 4 | `php artisan test --filter=Apuesta` | `--filter=Reporte`, `--filter=Ticket`, `--filter=PagoDevolucion` | Controllers de scoping + `ApuestaPolicy` |
| 5 | JuegoController / límites | PR 5 | `php artisan test --filter=Limites` | `--filter=Juego`, `--filter=SuperBancaScope` | `JuegoController` + suites límites |
| 6 | Auth/panel + queries ocultas + divergencia | PR 6 | `php artisan test --filter=DivergenciaRol` | `--filter=Comision`, `--filter=Agencia`, `--filter=ClaveCierre` | `Auth*`, `Comision`, `Agencia`, `CierreService`, `AgenciasBackfill` |
| 7 | Contract: drop `users.role` | PR 7 | `composer test` | `vendor/bin/pint --test` | migración drop + limpieza fixtures |

---

## Regla operativa de apply

- `sdd-apply` implementa **un solo WU** por invocación.
- TDD estricto: escribe/actualiza tests primero (RED), luego implementa (GREEN).
- El agente **NO ejecuta tests ni comandos de test**. Solo deja los comandos exactos, se detiene y espera confirmación del usuario (que corrió los tests) antes de avanzar al siguiente WU.
- `migrate:fresh --seed` es **manual** y **no** va en el entrypoint.
- Artefactos `openspec/**/*.md` están bajo `*.md` en `.gitignore`: usar `git add -f` para los archivos nuevos (recomendado, no ampliar el alcance agregando `!openspec/**/*.md`).
- Commits convencionales en español (`feat:`, `test:`, `docs:`, `fix:`), uno por WU.

---

## WU-0 — Pre-flight manual (operaciones; no código)

> Lo ejecuta el usuario en el VPS dev. El agente no corre estos comandos.

- [x] VPS dev: wipe/reseed final ejecutado post-Merge 2 (`php artisan migrate:fresh --seed`, manual; el backup pre-merge se conservó).
- [x] Validar que no existan usuarios sin rol Spatie (debe devolver 0):
  ```sql
  SELECT u.id FROM users u
  LEFT JOIN model_has_roles m
    ON m.model_id = u.id AND m.model_type = 'App\\Models\\User'
  WHERE m.model_id IS NULL;
  ```
- [x] Validar que no existan usuarios multi-rol (debe devolver 0):
  ```sql
  SELECT model_id, COUNT(*) FROM model_has_roles
  WHERE model_type = 'App\\Models\\User'
  GROUP BY model_id HAVING COUNT(*) > 1;
  ```
- [x] Backup/dump previo de `users`, `roles` y `model_has_roles` si aplica.
- [x] Si el primer query devuelve filas, asignar rol Spatie desde `users.role` con un one-off temporal (no backfill permanente).

---

## WU-1 — Modelo + factory + fixtures (Merge 1)

**Objetivo**: accessor calculado `role` en `User`; factory sin rol implícito con `withRole()`; migrar los 5 fixtures que dependen de la columna.

- **Rama**: `feature/rol-unico-wu1` · **Base**: `main`
- **Archivos**:
  - `backend/app/Models/User.php` — `$appends=['role']`, `primaryRole()`, `getRoleAttribute()` (prioridad `super_master > master > banca > grupo > agencia > taquilla`); quitar `role` de `$fillable`.
  - `backend/database/factories/UserFactory.php` — quitar `role` de `definition()`/`forAgencia()`; agregar `withRole()` con `syncRoles`; `forAgencia()` usa `withRole('taquilla')`; sin rol implícito.
  - `backend/tests/Unit/UserRoleAccessorTest.php` (nuevo).

**Tareas**:
- [x] 1.1 Escribir `UserRoleAccessorTest` (RED): accessor devuelve Spatie; ignora columna legacy divergente; `null` sin rol; `primaryRole` por prioridad; `role` en `toArray()`; factory sin rol por defecto; `withRole` asigna un solo rol.
- [x] 1.2 Implementar accessor en `User.php` (GREEN) — `getRoleNames()`/`primaryRole()`, nunca `$this->role`.
- [x] 1.3 Actualizar `UserFactory.php`: `withRole()` + `forAgencia()`; quitar `role` de `definition()`.
- [x] 1.4 Migrar 5 fixtures a `withRole()`: `AgenciaModelTest:30`, `AgenciasBackfillTest:33` y `:141`, `ApuestaPolicyAnulacionTest:287` y helper `:134` (`usuario($role)`); eliminar el resto de `'role' =>` inertes.

**Tests a escribir/actualizar**: `UserRoleAccessorTest` (nuevo); fixtures de `ApuestaPolicyAnulacionTest`, `AgenciasBackfillTest`, `AgenciaModelTest`.

**Comandos** (usuario ejecuta):
```bash
cd backend
vendor/bin/pint --test
php artisan test --filter=UserRoleAccessorTest
php artisan test --filter=ApuestaPolicyAnulacionTest
php artisan test --filter=AgenciasBackfillTest
```

**Criterio de done**: accessor nunca lee la columna; `null` sin rol; factory sin rol implícito; suites existentes verdes.
**Líneas estimadas**: ~260
**Rollback**: revertir `User.php`, `UserFactory.php` y fixtures de WU-1 (sin tocar controllers).

---

## WU-2 — Escrituras UserController + entidades + seeders + filtro index (Merge 1)

**Objetivo**: escribir rol solo vía Spatie (`syncRoles`), en transacción; migrar las 4 aserciones de BD sobre la columna.

- **Rama**: `feature/rol-unico-wu2` · **Base**: `feature/rol-unico-wu1`
- **Archivos**:
  - `backend/app/Http/Controllers/Api/UserController.php` — `store`/`update` en transacción con `syncRoles([$rol])`; `$data` sin `role`; `index` filtra con `role()` (`whereHas('roles')`).
  - `backend/app/Http/Controllers/Api/BancaController.php` — quitar `'role' => 'banca'`.
  - `backend/app/Http/Controllers/Api/GrupoController.php` — quitar `'role' => 'grupo'`.
  - `backend/app/Http/Controllers/Api/TaquillaController.php` — quitar `'role' => 'taquilla'`.
  - `backend/database/seeders/UsersSeeder.php` — quitar 7 `'role' =>`; conservar `assignRole`.
  - `backend/database/seeders/E2eSeeder.php` — quitar `'role' =>`; `syncRoles(['taquilla'])` (escritura).
  - Tests: `UsuarioRolApiTest.php` (nuevo); `GestionUsuariosTest`, `MastersPanelTest`, `RoleAuthorizationTest`.

**Tareas**:
- [x] 2.1 Escribir `UsuarioRolApiTest` (RED): `store` asigna Spatie sin escribir columna; `update` cambia rol en transacción; `index` filtra por `role()`; `login` devuelve `role` Spatie.
- [x] 2.2 Migrar 4 `assertDatabaseHas('users', ['role'=>...])` a `assertTrue(User::find($id)->hasRole($rol))` o `assertDatabaseHas('model_has_roles', [...])`: `GestionUsuariosTest:102/123/145`, `MastersPanelTest:105`.
- [x] 2.3 Implementar `UserController` store/update/index con `syncRoles` en `DB::transaction`.
- [x] 2.4 Quitar `'role' =>` de `BancaController`, `GrupoController`, `TaquillaController`.
- [x] 2.5 Actualizar `UsersSeeder` y `E2eSeeder` (escritura).

**Tests a escribir/actualizar**: `UsuarioRolApiTest` (nuevo); `GestionUsuariosTest`, `MastersPanelTest`, `RoleAuthorizationTest`.

**Comandos** (usuario ejecuta):
```bash
cd backend
vendor/bin/pint --test
php artisan test --filter=UsuarioRolApiTest
php artisan test --filter=GestionUsuariosTest
php artisan test --filter=MastersPanelTest
php artisan test --filter=RoleAuthorizationTest
```

**Criterio de done**: cero escrituras de `role` persistido; store/update transaccionales; filtro index por `role()`; aserciones contra `model_has_roles`.
**Líneas estimadas**: ~220
**Rollback**: revertir controllers + seeders manteniendo WU-1; re-establecer aserciones antiguas si hace falta.

---

## WU-3 — Auditoría de rol `user.role_changed` (Merge 1)

**Objetivo**: auditar cambios de rol en `UserController::update` con `logs` (`action='user.role_changed'`, `details` array).

- **Rama**: `feature/rol-unico-wu3` · **Base**: `feature/rol-unico-wu2`
- **Archivos**:
  - `backend/app/Http/Controllers/Api/UserController.php` — auditoría en `update` (dentro de la transacción).
  - `backend/tests/Feature/AuditoriaRolTest.php` (nuevo).

**Tareas**:
- [x] 3.1 Escribir `AuditoriaRolTest` (RED): cambio registra `user.role_changed`; mismo rol no registra; `details` incluye actor/target/before/after; `details` es array (no JSON string); 403 no registra.
- [x] 3.2 Implementar auditoría en `update`: `$before = getRoleNames()->sort()->values()->all()`; `$cambioRol = $request->filled('role') && $before !== [$request->role]`; `Log::create([...])` con `actor`, `target`, `before`, `after`, `ip`, `user_agent`.

**Tests a escribir/actualizar**: `AuditoriaRolTest` (nuevo); `GestionUsuariosTest`.

**Comandos** (usuario ejecuta):
```bash
cd backend
vendor/bin/pint --test
php artisan test --filter=AuditoriaRolTest
php artisan test --filter=GestionUsuariosTest
```

**Criterio de done**: un log por cambio real; ninguno sin cambio ni en 403; `details` como array.
**Líneas estimadas**: ~170
**Rollback**: revertir bloque de auditoría en `UserController::update` + `AuditoriaRolTest`.

---

## WU-4 — Scoping apuestas/reportes/estadísticas/tickets/pagos/policy (Merge 1)

**Objetivo**: convertir lecturas `->role` a `hasRole`/`hasAnyRole` en el dominio de scoping, fail-closed.

- **Rama**: `feature/rol-unico-wu4` · **Base**: `feature/rol-unico-wu3`
- **Archivos**:
  - `backend/app/Http/Controllers/Api/ApuestaController.php` (15 refs), `ReporteController.php` (10, `buildApuestaQuery`/`buildTicketQuery`), `EstadisticaController.php` (5), `TicketController.php` (12, preservar #84), `PagoController.php` (`hasRole('taquilla')`).
  - `backend/app/Policies/ApuestaPolicy.php` (12 refs).
  - Fixtures de esas suites.

**Tareas**:
- [x] 4.1 Convertir `ApuestaController` (index/historial/resumen) con `hasRole` + fail-closed final.
- [x] 4.2 Convertir `ReporteController` y `EstadisticaController`; denegar si no es `super_master`/rol esperado.
- [x] 4.3 Convertir `TicketController` preservando el alcance global actual de `master`/`agencia` (no agregar ramas nuevas; eso es #84); denegar solo usuario sin rol.
- [x] 4.4 Convertir `PagoController` y `ApuestaPolicy`.
- [x] 4.5 Actualizar fixtures de las suites afectadas (`Apuesta*`, `Reporte*`, `Estadistica*`, `Ticket*`, `Pago*`, `ApuestaPolicy*`).

**Tests a escribir/actualizar**: fixtures de `ApuestaTest`, `PagoDevolucion*`, `ReporteTest`, `CuadreCajaReportTest`, `EstadisticaTest`, `Ticket*`, `ApuestaPolicyAnulacionTest`.

**Comandos** (usuario ejecuta):
```bash
cd backend
vendor/bin/pint --test
php artisan test --filter=Apuesta
php artisan test --filter=PagoDevolucion
php artisan test --filter=Reporte
php artisan test --filter=CuadreCaja
php artisan test --filter=Estadistica
php artisan test --filter=Ticket
php artisan test --filter=ApuestaPolicy
```

**Criterio de done**: 0 `->role` en esos archivos; fail-closed para usuario sin rol; #84 intacto.
**Líneas estimadas**: ~280
**Rollback**: revertir los controllers + policy y fixtures de scoping (independiente de WU-2/WU-3).

---

## WU-5 — JuegoController / límites (Merge 1)

**Objetivo**: convertir las 40 referencias de `JuegoController` y las suites de límites.

- **Rama**: `feature/rol-unico-wu5` · **Base**: `feature/rol-unico-wu4`
- **Archivos**:
  - `backend/app/Http/Controllers/Api/JuegoController.php` — `in_array`→`hasAnyRole`; `match(true)`→`hasRole` por rama; límites equivalentes.
  - Tests: `LimitesApiTest`, `LimitesScopedApiTest`, `JuegoLimiteServiceTest`, `JuegoPremiosApiTest`, `JuegoToggleTest`, `JuegoUpdateTest`, `SuperBancaScopeTest`, `AgenciaMasterScopeTest`.

**Tareas**:
- [x] 5.1 Reescribir `JuegoController` con `hasRole`/`hasAnyRole`, preservando orden y semántica de ramas.
- [x] 5.2 Actualizar fixtures/expectativas de las suites de límites y scopes.

**Tests a escribir/actualizar**: suites de límites (`Limites*`, `Juego*`) y scopes (`SuperBancaScope`, `AgenciaMasterScope`).

**Comandos** (usuario ejecuta):
```bash
cd backend
vendor/bin/pint --test
php artisan test --filter=Limites
php artisan test --filter=Juego
php artisan test --filter=SuperBancaScope
php artisan test --filter=AgenciaMasterScope
```

**Criterio de done**: 40 refs convertidas; suites de límites verdes.
**Líneas estimadas**: ~240
**Rollback**: revertir `JuegoController` + tests de límites (independiente).

---

## WU-6 — Auth/panel + queries ocultas + divergencia (Merge 1)

**Objetivo**: login/panel Spatie; migrar las 6 queries `where('role',...)`; cubrir las 4 divergencias; docs.

- **Rama**: `feature/rol-unico-wu6` · **Base**: `feature/rol-unico-wu5`
- **Archivos**:
  - `backend/app/Http/Controllers/Api/AuthController.php` (8 refs), `backend/app/Http/Controllers/Admin/Auth/AuthController.php`.
  - `backend/app/Http/Controllers/Api/ComisionController.php` (`bancasEnAlcance`: master→ids, super_master→null, resto 403), `AgenciaController.php` (`destroy` con `role('taquilla')`).
  - `backend/app/Services/CierreService.php` (3 `where('role')` → `role()`), `backend/app/Console/Commands/AgenciasBackfill.php` (2 queries → `role()`), `backend/database/seeders/E2eSeeder.php` (3 queries de lectura → `role()`).
  - `backend/tests/Feature/DivergenciaRolTest.php` (nuevo); `ClaveCierreTest`, `ComisionesApiTest`, `ComisionReporteTest`, `Agencia*`, `MastersPanelTest`, `RoleAuthorizationTest`.
  - Docs: `docs/dev/arquitectura-backend.md` y `docs/dev/motor-cierres-comisiones.md` (hallazgo de rol inconsistente, §9 #5).

**Tareas**:
- [x] 6.1 Escribir `DivergenciaRolTest` (RED): comisiones (`taquilla` columna + `master` Spatie ⇒ alcance master); apuestas/cierres misma fuente; reportes (`super_master` columna + `taquilla` Spatie ⇒ alcance taquilla); `VerifyMac` exige MAC solo a `taquilla` Spatie; usuario sin rol ⇒ 403/scope vacío.
- [x] 6.2 Convertir `Api\AuthController` y `Admin\AuthController` a `hasRole`/`hasAnyRole`; respuesta `role => primaryRole()`; sin rol ⇒ 403.
- [x] 6.3 Migrar las 6 queries `where('role',...)` en `CierreService`, `AgenciaController`, `AgenciasBackfill`, `E2eSeeder` (lectura).
- [x] 6.4 Corregir `ComisionController::bancasEnAlcance` (caso 1 del proposal).
- [x] 6.5 Actualizar `docs/dev/arquitectura-backend.md` y `docs/dev/motor-cierres-comisiones.md` (fuente única Spatie).

**Tests a escribir/actualizar**: `DivergenciaRolTest` (nuevo); `ClaveCierreTest`, `ComisionesApiTest`, `ComisionReporteTest`, `Agencia*`, `MastersPanelTest`, `RoleAuthorizationTest`.

**Comandos** (usuario ejecuta):
```bash
cd backend
vendor/bin/pint --test
php artisan test --filter=DivergenciaRol
php artisan test --filter=ClaveCierre
php artisan test --filter=Comision
php artisan test --filter=Agencia
php artisan test --filter=MastersPanel
php artisan test --filter=RoleAuthorization
```

**Criterio de done**: 6 queries ocultas migradas; 4 divergencias cubiertas; login devuelve rol Spatie; grep `where('role',...)` en `app/` sin resultados.
**Líneas estimadas**: ~250
**Rollback**: revertir controllers/services + tests de divergencia (Merge 1 completo; columna sigue intacta).

---

## WU-7 — Contract: drop `users.role` (Merge 2)

**Objetivo**: eliminar físicamente la columna con migración idempotente; limpiar fixtures condicionados.

- **Rama**: `feature/rol-unico-contract` · **Base**: `main` (después de que WU-1..6 estén mergeados y desplegados)
- **Archivos**:
  - `backend/database/migrations/2026_11_10_000001_drop_role_from_users_table.php` (nuevo) — `up()` con `Schema::hasColumn` + `dropColumn`; `down()` re-agrega `string('role')->nullable()->default('taquilla')`.
  - Limpieza de fixtures/pruebas condicionadas a `Schema::hasColumn`.

**Tareas**:
- [x] 7.1 Crear la migración de drop idempotente (fecha ajustada al merge real).
- [x] 7.2 Limpiar fixtures/pruebas condicionadas a la existencia de la columna (escribir columna con `DB::table` solo si `Schema::hasColumn`).
- [x] 7.3 Verificar grep sin `where('role')` ni `User::factory(...)->role` en `app/` y `tests/`.

**Tests a escribir/actualizar**: limpieza de fixtures condicionados; suite completa.

**Comandos** (usuario ejecuta):
```bash
cd backend
vendor/bin/pint --test
composer test
# VPS dev: php artisan migrate:fresh --seed (manual, post-Merge 2)
```

**Criterio de done**: columna ausente en DB fresh; grep limpio; dev con `migrate:fresh --seed` sin errores.
**Líneas estimadas**: ~140
**Rollback**: `down()` restaura columna vacía; revertir código = redeploy imagen M1 (segura). Imagen pre-M1 **no** es segura.

---

## PR slices (stacked-to-main)

| PR | Rama | Base | Contenido | Al mergear a `main` |
|----|------|------|-----------|---------------------|
| 1 | `feature/rol-unico-wu1` | `main` | WU-1 | — |
| 2 | `feature/rol-unico-wu2` | `feature/rol-unico-wu1` | WU-2 | retarget `wu3`→`main` |
| 3 | `feature/rol-unico-wu3` | `feature/rol-unico-wu2` | WU-3 | retarget `wu4`→`main` |
| 4 | `feature/rol-unico-wu4` | `feature/rol-unico-wu3` | WU-4 | retarget `wu5`→`main` |
| 5 | `feature/rol-unico-wu5` | `feature/rol-unico-wu4` | WU-5 | retarget `wu6`→`main` |
| 6 | `feature/rol-unico-wu6` | `feature/rol-unico-wu5` | WU-6 | — |
| 7 | `feature/rol-unico-contract` | `main` (post-Merge 1) | WU-7 | — |

- Cada WU se commitea antes de crear la siguiente rama; si GitHub muestra diffs de WUs previos en un PR hijo, retargetear/rebasar la base a `main` hasta que el diff quede limpio.
- Commits convencionales en español: `feat(rol): ...`, `test(rol): ...`, `docs: ...`.

## Docs / spec

- WU-6 actualiza `docs/dev/arquitectura-backend.md` y `docs/dev/motor-cierres-comisiones.md` (hallazgo de rol inconsistente: CierreService ya no usa `users.role`).
- El spec principal `openspec/specs/jerarquia-agencias/spec.md` y la capability `autorizacion-rol-unico` se actualizan en **sdd-archive**, no en apply.

## Criterios de aceptación globales

- [x] Cero lecturas/escrituras de `users.role` como fuente (Merge 2: columna eliminada).
- [x] Login/UI devuelven `role` idéntico al de Spatie.
- [x] Los 4 casos de divergencia devuelven scope coherente.
- [x] Las 6 queries `where('role',...)` migradas a Spatie.
- [x] Auditoría `user.role_changed` con actor/target/before/after/IP/UA.
- [x] `composer test` en verde (TDD; el usuario corre los tests).
- [x] Deploy en VPS dev con `migrate:fresh --seed` sin errores.

## Riesgos

| Riesgo | Mitigación |
|--------|------------|
| Regresión de scoping (115 refs) | Conversión mecánica por dominio (WU-4/WU-5) + suites + `DivergenciaRolTest` |
| Accessor no gana a la columna en Merge 1 | Test unitario escribe columna vía `DB::table` y exige Spatie |
| Usuarios solo-columna pierden acceso tras M1 | Query pre-M1 de usuarios sin rol Spatie + one-off; en dev, wipe |
| Aserción oculta contra `users.role` en tests | 4 `assertDatabaseHas` migran en WU-2 |
| Doble rol por default del factory | `withRole` usa `syncRoles`; factory sin rol implícito |
| #84 "arreglado" sin querer en `TicketController` | Preservar ramas `agencia`/`master`; denegar solo sin rol |
| Rollback M2→pre-M1 rompe | Revertir solo a imagen M1; dump previo; `down()` restaura columna vacía |
| `.gitignore` ignora `openspec/**/*.md` | `git add -f` en los artefactos nuevos |

## Comandos de test por WU (el usuario los ejecuta)

```bash
cd backend
vendor/bin/pint --test                                   # estilo (CI) — todos los WUs
composer test                                            # suite completa (~35 min) — WU-7 y verificación final
php artisan test --filter=UserRoleAccessorTest           # WU-1
php artisan test --filter=UsuarioRolApiTest              # WU-2
php artisan test --filter=AuditoriaRolTest               # WU-3
php artisan test --filter=DivergenciaRolTest             # WU-6
# Filtros por suite: ver comandos de cada WU.
```
