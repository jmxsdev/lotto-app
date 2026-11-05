# Tasks: BE-04 — Catálogo de permisos por capability y migración `role:` → `permission:`

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1.500 (WU-1 ~525 · WU-2 ~340 · WU-3 ~350 · WU-4 ~310) |
| 800-line budget risk (por PR/candidate) | Low — cada WU ≤ 800 |
| Chained PRs recommended | Yes (local, stacked) |
| Suggested split | WU-1 → WU-2 → WU-3 → WU-4 |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main (local; push único a GitHub al cierre de BE-04) |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

## Regla operativa

- **Un WU por invocación** de `sdd-apply`; al terminar cada WU, **detenerse** y devolver los comandos enfocados.
- **Tests primero** (TDD): RED (test que falla) → GREEN (implementación) → REFACTOR.
- **El agente NO ejecuta tests ni migraciones**: solo escribe código/tests y **devuelve los comandos** para que el usuario los corra.
- **Commit por WU** (convencionales en español). OpenSpec: usar `git add -f` para incluir los archivos del change si están ignorados.
- **No tocar row scoping (#85)**: `AlcanceService`/policies quedan intactos.
- Cierre del ciclo: `composer test` + `vendor/bin/pint --test`.

## Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| WU-1 | Catálogo 34, seeder, `Gate::before`, doc, enmienda spec | be04/wu1-catalogo | `php artisan test --filter=PermissionCatalogTest` | N/A — agente no ejecuta; usuario corre filtro | `git revert`; re-ejecutar seeder anterior (permisos extra inocuos) |
| WU-2 | Migrar users/entidades/config/clave-cierre | be04/wu2-usuarios-entidades | `php artisan test --filter="GestionUsuariosTest\|GestionEntidadesApiTest\|ClaveCierreTest\|AgenciaApiTest\|ConfiguracionVencimientoTest"` | N/A — agente no ejecuta | `git revert`; rutas vuelven a `role:` |
| WU-3 | Migrar juegos/resultados/apuestas/tickets/pagos/cierres/límites | be04/wu3-operacion | `php artisan test --filter="ApuestaTest\|TicketMasterScopeTest\|CierreCajaTest\|LimitesApiTest\|TicketsAuthorizationTest\|JuegosJsonTest"` | N/A — agente no ejecuta | `git revert` |
| WU-4 | Migrar reportes/estadísticas/logs/comisiones/exchange/releases + coverage | be04/wu4-admin-reportes | `php artisan test --filter="ReporteTest\|ComisionesApiTest\|ExchangeRateTest\|ReleaseTest\|RoutePermissionCoverageTest\|TaquillaPermisosTest"` | N/A — agente no ejecuta | `git revert`; re-ejecutar seeder anterior si se revierte matriz |

---

## WU-1 — `be04/wu1-catalogo` (base: `feature/be04-permisos`)

**Objetivo**: catálogo de 34 permisos + seeder reconciliado + `Gate::before` acotado + doc de matriz + enmienda del spec + `PermissionCatalogTest`. **Behavior-neutral**: las rutas siguen en `role:`.

**Archivos**: `backend/database/seeders/RolesAndPermissionsSeeder.php`, `backend/app/Providers/AppServiceProvider.php`, `docs/dev/permisos-capabilities.md`, `docs/dev/pendientes-front.md`, `openspec/changes/be04-permisos/specs/catalogo-permisos/spec.md`, `backend/tests/Feature/PermissionCatalogTest.php`.

**Tareas**:
- [x] 1.1 Test RED: `PermissionCatalogTest` (34 permisos guard `api`; `manage_users`/`manage_apuestas` ausentes; `super_master` sin permisos + bypass; matriz exacta por rol; scan prohíbe `givePermissionTo`/`syncPermissions` sobre `User`).
- [x] 1.2 Seeder: catálogo 34 (`24 − manage_users + 11 faltantes`), `firstOrCreate` + `syncPermissions`, `forgetCachedPermissions()` inicio/fin; quitar `manage_users`, **no** `manage_apuestas`.
- [x] 1.3 Seeder matriz: `view_users` a taquilla; `delete_users` a master/banca/grupo/agencia; `manage_agencias` a agencia; `manage_config` a master; exchange solo master (quitar banca/grupo/agencia/taquilla); pagos sin agencia.
- [x] 1.4 `Gate::before` en `AppServiceProvider::boot()`: `true` solo para `super_master` y ability ∈ catálogo (`PermissionRegistrar` guard `api`); `null` en caso contrario (nunca `false`).
- [x] 1.5 Enmienda spec `catalogo-permisos/spec.md` con la matriz reconciliada (exchange, pagos agencia, tickets, `delete_users`, `view_users` taquilla, `create_apuesta` agencia).
- [x] 1.6 Doc `docs/dev/permisos-capabilities.md` (catálogo, matriz final, mapeo rutas, regla sin permisos directos, operación del seeder); actualizar `pendientes-front.md`.

**Tests**: escribir/actualizar `PermissionCatalogTest`.

**Comandos**: `php artisan test --filter=PermissionCatalogTest`

**Done**: 34 permisos; matriz reconciliada; bypass sin filas; doc + spec coherentes; test verde.

**Líneas estimadas**: ~525. **Rollback**: `git revert`; re-ejecutar seeder del tag anterior.

---

## WU-2 — `be04/wu2-usuarios-entidades` (base: `be04/wu1-catalogo`)

**Objetivo**: migrar `role:` → `permission:` en users, bancas, grupos, agencias, taquillas, config y clave-cierre; limpiar `web.php`/alias `role`/comentarios.

**Archivos**: `backend/routes/api.php`, `backend/routes/web.php`, `backend/bootstrap/app.php`, `backend/app/Http/Controllers/Api/GrupoController.php`, `backend/app/Http/Controllers/Api/TaquillaController.php`.

**Tareas**:
- [x] 2.1 Test RED: asserts de autorización de entidades a `permission:` (taquilla GET self 200, update/delete 403; agencia PUT propia 200, store/toggle/destroy 403; banca/grupo/agencia delete users 200).
- [x] 2.2 `routes/api.php`: users con split por método (`view_users` index/show · `create_users` store · `edit_users` update · `delete_users` destroy).
- [x] 2.3 `routes/api.php`: bancas, grupos, agencias, taquillas (view en index/show, manage en store/update/destroy/toggle).
- [x] 2.4 `routes/api.php`: `/configuraciones/apuestas-vencimiento` → `manage_config`; `/usuarios/clave-cierre` → `manage_clave_cierre`.
- [x] 2.5 Limpiar: quitar grupo vacío `role:` de `web.php`, bloques `middleware('permission:...')` comentados en `GrupoController`/`TaquillaController`. Alias/import `role` de `bootstrap/app.php` se conserva: aún hay grupos `role:` de WU-3/4; se elimina al cerrar la migración.

**Tests**: actualizar `RoleAuthorizationTest`/`DivergenciaRolTest` (semántica de scope intacta), `GestionUsuariosTest`, `GestionEntidadesApiTest`, `AgenciaApiTest`, `ClaveCierreTest`.

**Comandos**: `php artisan test --filter="GestionUsuariosTest|GestionEntidadesApiTest|ClaveCierreTest|AgenciaApiTest|ConfiguracionVencimientoTest"`

**Done**: 0 `role:` en estos grupos; splits por método visibles en `routes/api.php`; comentarios/alias eliminados.

**Líneas estimadas**: ~340. **Rollback**: `git revert` (rutas vuelven a `role:`).

---

## WU-3 — `be04/wu3-operacion` (base: `be04/wu2-usuarios-entidades`)

**Objetivo**: migrar juegos, resultados, apuestas, tickets, pagos, cierres y límites.

**Archivos**: `backend/routes/api.php`, `backend/tests/Feature/TicketsAuthorizationTest.php` + tests de operación existentes.

**Tareas**:
- [ ] 3.1 Test RED: `TicketsAuthorizationTest` — `agencia` 403 en `/tickets` (GET/POST/DELETE); master/banca/grupo/taquilla 200.
- [ ] 3.2 `routes/api.php`: juegos (`view_juegos` en GET, `manage_juegos` en PUT/toggle) y resultados (`view_resultados` GET, `manage_resultados` en scrape POST).
- [ ] 3.3 `routes/api.php`: apuestas (`view_apuestas` GET · `create_apuesta` POST · `delete_apuesta` DELETE); tickets con `create_apuesta` (sin `agencia`).
- [ ] 3.4 `routes/api.php`: pagos (`create_pago` POST · `view_pagos` GET, sin `agencia`); cierres (`create_cierre` POST · `view_cierre` GET).
- [ ] 3.5 `routes/api.php`: límites (`view_limites` GET master/banca/grupo/agencia · `manage_limites` PUT/DELETE/POST master/banca/grupo).

**Tests**: `ApuestaTest`, `TicketMasterScopeTest`, `TicketGanadoresTest`, `CierreCajaTest`, `LimitesApiTest`, `JuegosJsonTest`, `ResultadoAparicionesTest`, nuevo `TicketsAuthorizationTest`.

**Comandos**: `php artisan test --filter="ApuestaTest|TicketMasterScopeTest|CierreCajaTest|LimitesApiTest|TicketsAuthorizationTest|JuegosJsonTest"`

**Done**: juegos/resultados cubiertos (fail-closed); `agencia` sin tickets ni pagos.

**Líneas estimadas**: ~350. **Rollback**: `git revert`.

---

## WU-4 — `be04/wu4-admin-reportes` (base: `be04/wu3-operacion`)

**Objetivo**: migrar reportes, estadísticas, logs, comisiones, exchange y releases; `ReleaseTest` (taquilla 200); coverage final; docs finales.

**Archivos**: `backend/routes/api.php`, `backend/tests/Feature/ReleaseTest.php`, `backend/tests/Feature/RoutePermissionCoverageTest.php`, `backend/tests/Feature/TaquillaPermisosTest.php`, `docs/dev/permisos-capabilities.md`.

**Tareas**:
- [ ] 4.1 Test RED: `RoutePermissionCoverageTest` (toda ruta autenticada con `permission:`; 0 `role:`; allowlist `/user`/`/logout`; scan sin permisos directos) y `TaquillaPermisosTest` (taquilla 200 reportes/releases, 403 exchange).
- [ ] 4.2 `routes/api.php`: reportes/estadísticas → `view_reports` (5 roles).
- [ ] 4.3 `routes/api.php`: logs → `view_logs` (master); comisiones → `view_comisiones` (consulta, master) y `manage_comisiones` (defaults, solo bypass).
- [ ] 4.4 `routes/api.php`: exchange → `view_exchange_rates`/`manage_exchange_rates` (solo master); releases → `download_release` (5 roles).
- [ ] 4.5 Ajustar `ReleaseTest`: `taquilla` pasa de 403 a 200 en `/releases/latest` (cambio deliberado BE-04).
- [ ] 4.6 Cierre: actualizar `docs/dev/permisos-capabilities.md` final; `php artisan route:list` para confirmar 0 `role:`.

**Tests**: `ReporteTest`, `ComisionesApiTest`, `ExchangeRateTest`, `ReleaseTest` (modificado), nuevos `RoutePermissionCoverageTest`/`TaquillaPermisosTest`.

**Comandos**: `php artisan test --filter="ReporteTest|ComisionesApiTest|ExchangeRateTest|ReleaseTest|RoutePermissionCoverageTest|TaquillaPermisosTest"` · luego `composer test` + `vendor/bin/pint --test`

**Done**: suite y coverage verdes; doc final refleja matriz + mapeo.

**Líneas estimadas**: ~310. **Rollback**: `git revert`; re-ejecutar seeder anterior si se revierte la matriz.

---

## Criterios de aceptación globales

- [ ] 0 rutas de recurso con `role:`; todas las protegidas con `permission:`.
- [ ] 34 permisos con guard `api`; `manage_users` y `manage_apuestas` ausentes.
- [ ] `Gate::before` da bypass total a `super_master` (sin permisos asignados).
- [ ] Taquilla ve reportes (`view_reports`) y descarga releases (`download_release`).
- [ ] Sin permisos directos a usuarios (solo vía roles).
- [ ] Matriz capability × rol documentada en `docs/dev/`.
- [ ] `composer test` y `pint --test` en verde; row scoping (#85) intacto.

## Riesgos

- **Seeder en prod**: `syncPermissions` pisa grants manuales → backup de `role_has_permissions` antes del deploy.
- **Caché Spatie (24h)**: permisos viejos → `forgetCachedPermissions()` + `php artisan permission:cache-reset`.
- **Tests 403 por rol**: rompen al migrar → migración por WU con suite verde; solo `ReleaseTest` cambia de expectativa (deliberado).
- **Agencia tickets/pagos**: fuga si recibe `create_apuesta`/`view_pagos` → no asignar; `TicketsAuthorizationTest` lo bloquea.
- **Orden de deploy**: rutas antes del seeder → 403 masivos → sembrar antes de enrutar; verificar 34 permisos post-seed.
