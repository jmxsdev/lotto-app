# BE-04 apply-progress (WU-1 + WU-2 + WU-3 + WU-4)

> Consolidado desde Engram `sdd/be04-permisos/apply-progress` (observation #781),
> al cierre del change. Este archivo no existía en la carpeta del change y es
> aditivo respecto del snapshot de archivo.

## Change
be04-permisos — stacked sobre feature/be04-permisos.
WU-1: be04/wu1-catalogo · WU-2: be04/wu2-usuarios-entidades · WU-3: be04/wu3-operacion · WU-4: be04/wu4-admin-reportes (base be04/wu3-operacion).

## Mode
Standard. strict_tdd project config = true, but the user forbids executing tests/migrations/artisan; tests are written first and execution is user-owned.

## Completed Tasks

### WU-1 — catálogo (preserved)
- [x] 1.1 PermissionCatalogTest: catálogo exacto 34 guard api, retirados ausentes, matriz exacta por rol, bypass super_master sin filas, ability no-permiso intacta, scan app/ sin givePermissionTo/syncPermissions sobre User.
- [x] 1.2 RolesAndPermissionsSeeder: 34 permisos firstOrCreate, delete manage_users/manage_apuestas, forgetCachedPermissions inicio/fin.
- [x] 1.3 Matriz: master 33, banca 24, grupo 21, agencia 17, taquilla 12, super_master 0.
- [x] 1.4 Gate::before en AppServiceProvider::boot(): true solo super_master + ability en PermissionRegistrar (guard api); null en cualquier otro caso.
- [x] 1.5 Enmienda specs/catalogo-permisos/spec.md.
- [x] 1.6 docs/dev/permisos-capabilities.md + índices + pendientes-front.

### WU-2 — users/entidades/config/clave-cierre (preserved)
- [x] 2.1 Asserts de autorización de entidades (GestionEntidadesApiTest, AgenciaApiTest; RoleAuthorizationTest/DivergenciaRolTest sin cambio).
- [x] 2.2 routes/api.php users: apiResource split view/create/edit/delete_users.
- [x] 2.3 bancas/grupos/agencias/taquillas: view_* / manage_* (+toggle).
- [x] 2.4 /configuraciones/apuestas-vencimiento → manage_config; /usuarios/clave-cierre → manage_clave_cierre.
- [x] 2.5 Limpieza web.php, GrupoController, TaquillaController. Alias role conservado hasta WU-4.

### WU-3 — juegos/resultados/apuestas/tickets/pagos/cierres/límites (preserved)
- [x] 3.1 TicketsAuthorizationTest (RED): agencia 403 en GET/POST/DELETE /tickets y GET /tickets/ganadores; master/banca/grupo/taquilla 200; solo taquilla crea (201); anulación por alcance.
- [x] 3.2 Juegos view_juegos/manage_juegos; resultados view_resultados/manage_resultados.
- [x] 3.3 Apuestas view_apuestas/create_apuesta (sin agencia)/delete_apuesta; tickets create_apuesta sin agencia.
- [x] 3.4 Pagos create_pago/view_pagos (sin agencia); cierres create_cierre/view_cierre.
- [x] 3.5 Límites view_limites (master/banca/grupo/agencia) / manage_limites (master/banca/grupo).

### WU-4 — reportes/estadísticas/logs/comisiones/exchange/releases + coverage
- [x] 4.1 RED: RoutePermissionCoverageTest (toda ruta auth:sanctum exige permission:; allowlist /user y /logout; 0 role: en api.php/web.php; guarda anti-vacío >30 rutas; scan app/ sin permisos directos a User) y TaquillaPermisosTest (taquilla 200 en reportes/estadísticas/releases/juegos/resultados/users self; 403 exchange-rates; agencia 403 tickets/pagos como referencia WU-3).
- [x] 4.2 /reportes/* y /estadisticas/rendimiento → permission:view_reports.
- [x] 4.3 /logs → permission:view_logs; /comisiones/defaults GET+PUT → permission:manage_comisiones (sin rol asignado ⇒ solo bypass super_master); /comisiones/consulta → permission:view_comisiones. Eliminados los role: residuales.
- [x] 4.4 Exchange: ya estaba en view_exchange_rates/manage_exchange_rates y la matriz solo asigna exchange a master (sin cambios de matriz). /releases/latest y /releases/download → permission:download_release (5 roles, taquilla incluida).
- [x] 4.5 ReleaseTest: taquilla pasa de 403 a 200 en /releases/latest. Se usa la taquilla DEMO con headers X-Device-MAC/X-Device-Fingerprint porque la ruta vive bajo verify.mac (con taquilla@lotto.com sin MAC el 403 venía del middleware MAC, no del permiso).
- [x] 4.6 docs/dev/permisos-capabilities.md final ("BE-04 cerrado", mapeo final, referencias a los tests). Alias/import role eliminados de bootstrap/app.php al quedar 0 role: en rutas. route:list NO ejecutado (regla del usuario); 0 role: verificado por grep estático.

## Files Changed

### WU-4
| File | Action | What |
|---|---|---|
| backend/routes/api.php | Modified | 7 grupos role: restantes migrados a permission: (logs, comisiones defaults/consulta, reportes, estadísticas, releases). Comisiones defaults GET+PUT unificados bajo manage_comisiones. |
| backend/bootstrap/app.php | Modified | Eliminados import y alias 'role' (RoleMiddleware); se conservan permission, role_or_permission, checkrole y verify.mac. |
| backend/tests/Feature/RoutePermissionCoverageTest.php | Created | Cobertura estática de rutas auth:sanctum + 0 role: + scan app/. |
| backend/tests/Feature/TaquillaPermisosTest.php | Created | Acceso efectivo de taquilla y regresión agencia tickets/pagos. |
| backend/tests/Feature/ReleaseTest.php | Modified | taquilla 403 → 200 en /releases/latest (cambio deliberado BE-04). |
| docs/dev/permisos-capabilities.md | Modified | Mapeo final + cierre BE-04 + referencias de tests. |
| openspec/changes/be04-permisos/tasks.md | Modified | WU-4 4.1–4.6 y criterios globales estructurales [x]; queda [ ] solo composer test/pint (lo corre el usuario). |

### WU-3/WU-2/WU-1 (previous)
- WU-3: routes/api.php (operación), TicketsAuthorizationTest creado.
- WU-2: routes/api.php users/entidades/config, routes/web.php, GrupoController, TaquillaController, GestionEntidadesApiTest, AgenciaApiTest.
- WU-1: seeder, AppServiceProvider, PermissionCatalogTest, docs, spec.

## Work Unit Evidence (WU-4)
| Evidence | Value |
|---|---|
| Focused test command and result | cd backend && php artisan test --filter="ReporteTest|ComisionesApiTest|ExchangeRateTest|ReleaseTest|RoutePermissionCoverageTest|TaquillaPermisosTest" — NOT EXECUTED by agent (user runs it). |
| Runtime harness | N/A — agent does not execute artisan/runtime; the user runs the focused filter. |
| Rollback boundary | git revert de routes/api.php, bootstrap/app.php, ReleaseTest, docs y borrar los 2 tests nuevos. Rutas vuelven a role: (seeder/Gate::before y matriz intactos). |

## Effective access preserved (verified by reading, not executing)
- Reportes/estadísticas: view_reports a los 5 roles ⇒ taquilla conserva 200 con scoping de filas.
- Logs: view_logs master + bypass; cualquier otro rol 403 (antes role: super_master|master).
- Comisiones defaults: manage_comisiones sin rol ⇒ solo super_master por bypass; GET y PUT siguen 403 para banca/master/grupo/agencia/taquilla.
- Comisiones consulta: view_comisiones master + bypass; resto 403.
- Exchange rates: sin cambios (solo master en matriz); taquilla 403.
- Releases: download_release a los 5 roles ⇒ taquilla 200 (único cambio deliberado; before 403 por role:).
- Agencia tickets/pagos: sin create_apuesta/view_pagos ⇒ 403 preservado.
- Usuarios/taquilla: view_users ⇒ GET /users 200 devolviendo solo su ficha.

## Deviations from Design
- ReleaseTest taquilla usa demo@lotto.com + headers de dispositivo en lugar de taquilla@lotto.com: el grupo verify.mac exige MAC y la taquilla sembrada TT001 está inactiva. El caso sigue siendo rol taquilla y ahora verifica el permiso real.
- Design §mapeo lista agencia en POST /apuestas; se mantiene la matriz final (agencia sin create_apuesta), como en WU-3.
- route:list no se ejecutó por la regla del usuario; la verificación de 0 role: fue estática (grep).

## Static Checks Performed
- php -l OK: routes/api.php, bootstrap/app.php, RoutePermissionCoverageTest.php, TaquillaPermisosTest.php, ReleaseTest.php.
- 0 role: en routes/ (grep); alias role eliminado de bootstrap/app.php.
- Diff WU-4: ~314 líneas contando tests nuevos (34 tracked insertions/deletions + 253 nuevas) ≤ 800.

## Issues / Risks
- Tests no ejecutados: RED→GREEN sin verificar hasta que el usuario corra el filtro.
- La BD de test debe sembrar roles/permisos (DatabaseSeeder → RolesAndPermissionsSeeder) para que permission: responda.
- Los tests de taquilla bajo verify.mac dependen de la taquilla DEMO activa del seeder (MAC 00:1A:2B:3C:4D:5E).
- Seeder en prod: ejecutar antes de desplegar las rutas migradas y hacer backup de role_has_permissions.

## Remaining Tasks
- Ninguna de implementación. Verificación final a cargo del usuario (filtro WU-4 + composer test + pint --test).

## Next Recommended
sdd-verify para be04-permisos (no lanzado por este ejecutor).
