# Apply Progress: autorizacion-rol-unico

## WU-1 — Modelo + factory + fixtures (Merge 1)

**Estado**: ✅ completado (tests ejecutados por el usuario: verdes)

**Rama**: `feature/rol-unico-wu1` (base `main` 97a2733)

**Cambios**:
- `backend/app/Models/User.php`: `$appends=['role']`, `primaryRole()` con prioridad fija, `getRoleAttribute()` Spatie; `role` fuera de `$fillable`.
- `backend/database/factories/UserFactory.php`: sin rol implícito; `withRole()` con `syncRoles`; `forAgencia()` asigna `taquilla`.
- `backend/tests/Unit/UserRoleAccessorTest.php`: 7 tests nuevos (accessor Spatie, columna legacy divergente, null sin rol, prioridad, `toArray`, factory sin rol, `withRole`).
- 5 fixtures dependientes de la columna migrados a `withRole()`: `AgenciaModelTest`, `AgenciasBackfillTest` (2), `ApuestaPolicyAnulacionTest` (helper + caso agencia).
- 57 claves `'role' =>` inertes eliminadas de arrays de `User::factory()` en 26 archivos de test.

**Tests ejecutados por el usuario**:
- `UserRoleAccessorTest` — verde.
- `ApuestaPolicyAnulacionTest` — verde.
- `AgenciasBackfillTest` — verde (5 passed, 28 assertions).
- `AgenciaModelTest` — verde (4 passed, 14 assertions).
- `vendor/bin/pint --test` — verde.

**Evidencia**:
- `evidence-revision`: `62467e91c896ba629dad1565fa3d0e944a535a26d0e3d7e1ec00122350ad7859`.
- Sin ejecución de tests/migraciones por el agente (regla del usuario).

**Notas transicionales**:
- El accessor ya devuelve Spatie, pero las queries crudas `where('role', ...)` siguen en `UserController::index`, `CierreService`, `AgenciaController`, `AgenciasBackfill` y `E2eSeeder` hasta WU-2/WU-6.
- Se esperan rojos transicionales en `ClaveCierreTest`, `GestionUsuariosTest` y `MastersPanelTest` hasta WU-2/WU-6.

**Próximo**: WU-2 — escrituras UserController + entidades + seeders + filtro index.

---

## WU-2 — Escrituras UserController + entidades + seeders + filtro index (Merge 1)

**Estado**: ✅ completado (tests ejecutados por el usuario: verdes)

**Rama**: `feature/rol-unico-wu2` (base `feature/rol-unico-wu1`)

**Cambios**:
- `UserController::store`: `$data` sin `role`; `User::create` + `syncRoles` en `DB::transaction`.
- `UserController::update`: `$data` sin `role`; `$finalRole = input('role', primaryRole())`; 403 si null; `syncRoles` en la misma transacción.
- `UserController::index`: filtro por scope Spatie `role()`.
- `Banca/Grupo/TaquillaController`: eliminadas las escrituras `'role' => ...`.
- `UsersSeeder`: eliminados los 7 `'role' => ...`; se conservan `assignRole`.
- `E2eSeeder`: sin escritura de `role`; `syncRoles(['taquilla'])`.
- `UsuarioRolApiTest` (6 tests), `GestionUsuariosTest` (3 aserciones), `MastersPanelTest` (1 aserción).

**Tests ejecutados por el usuario**:
- `UsuarioRolApiTest` — verde.
- `GestionUsuariosTest` — verde.
- `MastersPanelTest` — verde (8 passed, 32 assertions).
- `RoleAuthorizationTest` — verde (9 passed, 21 assertions).
- `vendor/bin/pint --test` — verde.

**Evidencia**:
- `evidence-revision`: `2d0eb21458dbdad7871a48b8290e68410bcbffd89e3e3c525e5645210c0b9d1c`.
- El ledger registró 1230 líneas porque contó artefactos SDD de WU-1; reset autorizado por el maintainer para corregir el conteo. Diff real de WU-2: ~240 líneas.

**Notas transicionales**:
- `users.role` sigue existiendo pero ya no se escribe desde la app/seeders.
- `CierreService`, `AgenciaController`, `AgenciasBackfill` y `E2eSeeder` todavía tienen lecturas legacy hasta WU-6.

**Próximo**: WU-3 — auditoría `user.role_changed`.

---

## WU-3 — Auditoría `user.role_changed` (Merge 1)

**Estado**: ✅ completado (tests ejecutados por el usuario: verdes)

**Rama**: `feature/rol-unico-wu3` (base `feature/rol-unico-wu2`)

**Cambios**:
- `UserController::update`: `$before` con `getRoleNames()->sort()->values()->all()`; `$cambioRol` por set completo; `Log::create` dentro de la transacción con `action='user.role_changed'`, actor (`user_id` + `details.actor_id`), target, email, before/after, IP y UA.
- `AuditoriaRolTest` (5 tests): cambio registra log; mismo rol/sin `role` no registra; actor/target/before/after; `details` array sin doble JSON; 403 no registra.

**Tests ejecutados por el usuario**:
- `AuditoriaRolTest` — verde (5 passed, 27 assertions).
- `GestionUsuariosTest` — verde (16 passed, 60 assertions).
- `vendor/bin/pint --test` — verde.

**Evidencia**:
- `evidence-revision`: `a4b7b4e0cdbc64a21de3a5c4e2ccb67da9942de91739915f1ae288d0de0e0c67`.

**Próximo**: WU-4 — scoping de apuestas/reportes/estadísticas/tickets/pagos/policy.

## WU-4 — Scoping apuestas/reportes/estadísticas/tickets/pagos/policy (Merge 1)

**Estado**: ✅ completado (tests ejecutados por el usuario: verdes)

**Rama**: `feature/rol-unico-wu4` (base `feature/rol-unico-wu3`)

**Cambios**:
- `ApuestaController` (15 refs), `ReporteController` (10), `EstadisticaController` (5): escaleras convertidas a `hasRole`, rama explícita `super_master` y `else abort(403)` fail-closed.
- `TicketController` (12): `hasRole`; `autorizarScope()` deniega solo usuario sin rol/fuera de catálogo; `master`/`agencia` conservan alcance global actual (#84).
- `PagoController` (1): `hasRole('taquilla')`; cross-tenant de banca intacto (#84).
- `ApuestaPolicy` (12): `hasRole`/`hasAnyRole`; `master` global preservado (#84).

**Tests ejecutados por el usuario**:
- `ApuestaPolicyAnulacionTest` — verde (17 passed, 43 assertions).
- Suites `Apuesta`, `PagoDevolucion`, `Reporte`, `CuadreCaja`, `Estadistica`, `Ticket` — verdes.
- `vendor/bin/pint --test` — verde.

**Evidencia**:
- `evidence-revision`: `54160ef9b4a5b4fcbbb84476302661bc62b97fd08cbd8860d6412f561e8fa15e`.
- 0 referencias `->role` en los 6 archivos de WU-4.

**Notas**:
- WU-4 es semánticamente neutro para usuarios mono-rol; el accessor Spatie de WU-1 ya resolvía las lecturas. El cambio deja explícito el uso de Spatie y agrega fail-closed.
- #84 queda intacto deliberadamente.

**Próximo**: WU-5 — `JuegoController` / límites.

## WU-5 — JuegoController / límites (Merge 1)

**Estado**: ✅ completado (tests ejecutados por el usuario: verdes)

**Rama**: `feature/rol-unico-wu5` (base `feature/rol-unico-wu4`)

**Cambios**:
- `JuegoController`: 40 referencias `->role` convertidas a `hasRole`/`hasAnyRole`.
- `expandirTipoAlcance`: `match(true)` reemplazado por `if/elseif`, preservando orden y override de `grupo`.
- `entidadesVisiblesPorTipo`: rama explícita `super_master` sin filtro; rol ausente/fuera de catálogo → colección vacía.
- `limites`, `listarLimites`, `updateLimites`, `destroyLimite`, `batchLimites`, helpers de autorización y `entidadDentroDelAlcance` convertidos.
- Sin cambios de tests, seeders, migraciones ni otros controllers.

**Tests ejecutados por el usuario**:
- `Limites` — 64 passed, 543 assertions.
- `Juego` — 111 passed, 1 skipped, 2673 assertions.
- `SuperBancaScope` — 12 passed, 46 assertions.
- `AgenciaMasterScope` — 8 passed, 17 assertions.
- `vendor/bin/pint --test` — verde.

**Evidencia**:
- `evidence-revision`: `235336f25bd12e9841cdc735b1aaf00077f2f6beecc559c3a8f412175cab0629`.
- 0 referencias `->role` en `JuegoController`.

**Nota**: el fail-closed nuevo para rol fuera de catálogo en `entidadesVisiblesPorTipo` no es alcanzable por ruta, pero evita el default global.

**Próximo**: WU-6 — auth/panel + queries ocultas + `DivergenciaRolTest`.

## WU-6 — Auth/panel + queries ocultas + divergencia (Merge 1)

**Estado**: ✅ completado (tests ejecutados por el usuario: verdes)

**Rama**: `feature/rol-unico-wu6` (base `feature/rol-unico-wu5`)

**Cambios**:
- `Api\AuthController`: 8 refs convertidas (`hasRole`/`hasAnyRole`); guard fail-closed `primaryRole() === null` → 403 antes de emitir token; respuesta `role => primaryRole()`.
- `Admin\Auth\AuthController`: `hasAnyRole(['super_master','master'])`; `session('user_role')` con `primaryRole()`.
- `ComisionController::bancasEnAlcance`: `master` → ids; `super_master` → `null`; cualquier otro → `abort(403)`.
- `AgenciaController::destroy`: `users()->role('taquilla')->update(...)`.
- `CierreService::validarClaveCierre`: candidatos con `role('banca')`, `role('master')`, `role('super_master')` (misma lógica de `Hash::check`).
- `AgenciasBackfill`: `role('super_master')`, `role('taquilla')`, `$creador->hasRole('master')`.
- `E2eSeeder`: lecturas `role('super_master')` / `role('banca')`.
- `DivergenciaRolTest` (nuevo, 8 tests): comisiones master, apuestas/cierres compartidos, reportes taquilla, VerifyMac Spatie, sin rol → 403, clave de cierre por Spatie, login taquilla y login sin rol.
- Docs: `docs/dev/motor-cierres-comisiones.md` (fuente única + hallazgo de rol marcado FIXED) y `docs/dev/arquitectura-backend.md` (gotcha de rol único). La referencia original a `doc/backend-architecture/src/documento.md` se corrigió: ese árbol no existe en el repo.

**Tests ejecutados por el usuario**:
- `DivergenciaRolTest` — 8 passed, 21 assertions.
- `ClaveCierreTest` — 16 passed, 67 assertions.
- `Comision*` — 75 passed, 228 assertions.
- `Agencia*` — 95 passed, 376 assertions.
- `MastersPanelTest` — 8 passed, 32 assertions.
- `RoleAuthorizationTest` — 9 passed, 21 assertions.
- `vendor/bin/pint --test` — verde.

**Evidencia**:
- `evidence-revision`: `7b28370da420349ce62a5c7cb70bafd113d1713cff034e6a94691b816f4cf2fc`.
- Sin ejecución de tests/pint por el agente (regla del usuario).

**Notas transicionales**:
- `DivergenciaRolTest` fija el contrato de fuente única; el RED real de este WU estaba en la query de `CierreService` y en las suites de auth/agencias/comisiones.
- `users.role` sigue existiendo físicamente, pero ya no se lee ni se escribe como fuente. WU-7 la elimina.

## WU-7 — Contract: drop `users.role` (Merge 2)

**Estado**: ⏳ pendiente
