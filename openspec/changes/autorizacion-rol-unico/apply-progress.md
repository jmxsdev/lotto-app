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

**Estado**: ⏳ pendiente

**Rama**: `feature/rol-unico-wu2` (base `feature/rol-unico-wu1`)

**Objetivo**: eliminar las escrituras de `users.role`; `store/update` transaccionales con `syncRoles`; `index` filtra por Spatie; migrar 4 `assertDatabaseHas` de tests.

**Tests del usuario**: ver `tasks.md` WU-2. No ejecutados aún.

---

## WU-3 — Auditoría `user.role_changed` (Merge 1)

**Estado**: ⏳ pendiente

## WU-4 — Scoping apuestas/reportes/estadísticas/tickets/pagos/policy (Merge 1)

**Estado**: ⏳ pendiente

## WU-5 — JuegoController / límites (Merge 1)

**Estado**: ⏳ pendiente

## WU-6 — Auth/panel + queries ocultas + divergencia (Merge 1)

**Estado**: ⏳ pendiente

## WU-7 — Contract: drop `users.role` (Merge 2)

**Estado**: ⏳ pendiente
