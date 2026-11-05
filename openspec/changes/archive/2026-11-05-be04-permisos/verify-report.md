```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:1657ca478c959d06c038a67809fdb7a12290ed011a61eb61d1a58d62365b3a5f
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 15/15
scenarios: 20/20
test_command: php artisan test --parallel --processes=4
test_exit_code: 0
test_output_hash: sha256:4dc2231e8939c35d5c7475f19e490e8e9760b542b02ce0aaa1164b27f9547f35
build_command: ./vendor/bin/pint --test
build_exit_code: 0
build_output_hash: sha256:cd1a94fc2cf6a965b86e1a4809d6c7fb9148b1ee374e1010ed2ac96ff4876ec2
```

## Verification Report

**Change**: be04-permisos (BE-04)
**Version**: N/A (specs `catalogo-permisos` y `autorizacion-capability`, estado draft)
**Mode**: Standard (Strict TDD no activo)
**Rama**: `be04/wu4-admin-reportes` · **HEAD**: `f2deeec` · **Base del change**: `a229c1d`
**Work units verificadas**: WU-1 `a0fe3f5` · WU-2 `dbdf97a` · WU-3 `cd453bd` · WU-4 `e34a526` (+ fix de test `f2deeec`)

### Resumen ejecutivo

BE-04 cumple sus dos capabilities: el catálogo quedó en **34 permisos guard `api`** con la matriz exacta (master 33, banca 24, grupo 21, agencia 17, taquilla 12, super_master 0), `manage_users`/`manage_apuestas` fueron retirados y `Gate::before` da bypass acotado a `super_master` sin depender de filas. `routes/api.php` y `routes/web.php` tienen **0 `role:`**, el alias `role` se eliminó de `bootstrap/app.php` y las **34 capabilities del catálogo se usan en rutas** (sin permisos huérfanos ni rutas con permisos inexistentes).

La suite completa y Pint están verdes según la evidencia de ejecución del usuario (1501 tests, 8678 assertions, 2 skipped, OK; `pint --test` passed). No se encontraron regresiones de autorización: `agencia` sigue sin tickets ni pagos, `taquilla` conserva reportes y gana releases (cambio deliberado), exchange/`manage_config` quedan solo en master y el scoping de filas (#85) no se tocó. El veredicto es **PASS WITH WARNINGS** por deuda menor de evidencia/prueba (idempotencia del seeder sin test dedicado) y por inconsistencias de documentación/bookkeeping, no por defectos de contrato ni de runtime.

### Completeness

| Metric | Value |
|--------|-------|
| Work units | 4/4 completas (WU-1..WU-4 commiteadas) |
| Tareas de work unit | 22/22 marcadas `[x]` |
| Tareas incompletas | 0 |
| Criterios de aceptación globales | 6/7 marcados; el restante (`composer test` + `pint --test`) está satisfecho por la evidencia del usuario pero sigue `[ ]` en `tasks.md` (bookkeeping) |

### Build & Tests Execution

**Build / formato**: ✅ Passed (evidencia del usuario; el verifier no ejecuta comandos por regla dura).
```text
./vendor/bin/pint --test
{"tool":"pint","result":"passed"}
exit 0
```

**Tests**: ✅ 1501 passed / 0 failed / 2 skipped (suite completa, evidencia del usuario).
```text
php artisan test --parallel --processes=4
1501 tests, 8678 assertions, 2 skipped, OK
exit 0
```

**Filtros por work unit** (evidencia del usuario):

| WU | Filtro | Resultado |
|---|---|---|
| WU-1 | `PermissionCatalogTest` | 10 passed |
| WU-2 | entidades/usuarios/config | 63 passed |
| WU-3 | operación/tickets | 113 passed |
| WU-4 | admin/reportes/releases | 57 passed (tras corregir el test de `/pagos/{id}`) |

**Coverage**: ➖ No disponible (`coverage_threshold: 0` en `openspec/config.yaml`; no se ejecutó medición).

> **Provenance de hashes**: por la regla dura de esta fase el verifier no ejecutó tests, migraciones ni artisan. `test_output_hash` es el SHA-256 del texto exacto de la evidencia de suite (`1501 tests, 8678 assertions, 2 skipped, OK`) y `build_output_hash` el de la salida real de Pint reportada (`{"tool":"pint","result":"passed"}`). `evidence_revision` = SHA-256 de `f2deeec|requirements=15|scenarios=20|test_hash|build_hash`.

### Spec Compliance Matrix

#### `catalogo-permisos` (7 requirements / 10 escenarios)

| Requirement | Scenario | Test / evidencia | Result |
|-------------|----------|------------------|--------|
| Catálogo por capability | Nombres existentes preservados | `PermissionCatalogTest::test_catalogo_exacto_de_34_permisos_con_guard_api` (34 nombres, guard `api`) | ✅ COMPLIANT |
| Catálogo por capability | Permisos muertos ausentes | Mismo test: `manage_users`/`manage_apuestas` con 0 filas; seeder los borra | ✅ COMPLIANT |
| Matriz capability × rol | Matriz documentada | `docs/dev/permisos-capabilities.md` §2–§3 (inspección) + `PermissionCatalogTest::test_matriz_exacta_por_rol` (fuente única 33/24/21/17/12/0) | ✅ COMPLIANT |
| Reconciliación con el acceso efectivo | `agencia` edita su ficha pero no toca tickets ni pagos | `AgenciaApiTest::test_agencia_edita_su_local_pero_no_gestiona` (PUT 200, toggle/delete 403) + `TicketsAuthorizationTest::test_agencia_no_accede_a_ninguna_ruta_de_tickets` + `TaquillaPermisosTest::test_agencia_sin_tickets_ni_pagos` | ✅ COMPLIANT |
| Reconciliación con el acceso efectivo | Exchange rates y configuración solo master | `PermissionCatalogTest::test_matriz_exacta_por_rol` (exchange ausente en banca/grupo/agencia/taquilla) + `ExchangeRateTest` + `TaquillaPermisosTest::test_taquilla_recibe_403_en_exchange_rates` + `ConfiguracionVencimientoTest::test_master_puede_leer_y_editar_la_ventana` / `test_get_rechazado_403_para_rol_taquilla` | ✅ COMPLIANT |
| Bypass total para `super_master` | `super_master` sin permisos asignados | `PermissionCatalogTest::test_super_master_sin_permisos_pasa_el_gate_por_bypass` (0 filas, `Gate::allows('view_users')` y `manage_comisiones` = true) + `test_bypass_no_altera_abilities_que_no_son_permisos` | ✅ COMPLIANT |
| Asignación solo vía roles | Scan prohíbe asignación directa | `PermissionCatalogTest::test_ningun_archivo_de_app_asigna_permisos_directos_a_usuarios` + `RoutePermissionCoverageTest::test_app_no_asigna_permisos_directos_a_usuarios` (control positivo + scan de `app/`) | ✅ COMPLIANT |
| Seeder idempotente | Re-ejecución segura | Evidencia estructural: `firstOrCreate`, borrado de retirados con FK `cascadeOnDelete`, `syncPermissions` y `forgetCachedPermissions()` al inicio y al final. Sin test dedicado de re-ejecución (ver W1) | ✅ COMPLIANT |
| Concesiones puntuales | `master` gestiona configuración | `ConfiguracionVencimientoTest::test_master_puede_leer_y_editar_la_ventana` + `PermissionCatalogTest` (master recibe `manage_config`) | ✅ COMPLIANT |
| Concesiones puntuales | `taquilla` reportes propios y releases | `TaquillaPermisosTest::test_taquilla_accede_a_los_reportes` / `_a_estadisticas` / `_a_releases_latest_y_download` + `ReleaseTest::test_latest_disponible_para_rol_taquilla` | ✅ COMPLIANT |

#### `autorizacion-capability` (8 requirements / 10 escenarios)

| Requirement | Scenario | Test / evidencia | Result |
|-------------|----------|------------------|--------|
| Cero `role:` en recursos | Scan 0 `role:` en grupos de recurso | `RoutePermissionCoverageTest::test_rutas_no_contienen_role_residual` + grep estático: 0 en `routes/api.php` y `routes/web.php` | ✅ COMPLIANT |
| Cobertura de rutas sin permiso | Juegos legibles por cualquier autenticado | `TaquillaPermisosTest::test_taquilla_accede_a_juegos_y_resultados` + `JuegosJsonTest` + `RoutePermissionCoverageTest` (middleware por ruta); la rama 403 sin `view_juegos` no tiene test explícito | ✅ COMPLIANT (estructural) |
| Cobertura de rutas sin permiso | Resultados legibles por cualquier autenticado | `ResultadoAparicionesTest::test_apariciones_200_para_todos_los_roles_del_panel` + `TaquillaPermisosTest`; la rama 403 sin `view_resultados` no tiene test explícito | ✅ COMPLIANT (estructural) |
| `permission:` por método en CRUD mixto | CRUD mixto con granularidad por método | `routes/api.php` splits `view_*` (index/show) vs `manage_*` (store/update/destroy/toggle) + `GestionEntidadesApiTest::test_taquilla_no_puede_actualizar_ni_eliminar_usuarios` + `AgenciaApiTest` | ✅ COMPLIANT |
| Preservación de acceso efectivo | Rol que hoy pasa sigue pasando | `TaquillaPermisosTest`, `GestionEntidadesApiTest`, `AgenciaApiTest`, `CierreCajaTest`, `LimitesApiTest`, `JuegosJsonTest`, `ResultadoAparicionesTest` | ✅ COMPLIANT |
| Preservación de acceso efectivo | Rol que hoy recibe 403 sigue recibiéndolo | `TicketsAuthorizationTest::test_agencia_no_accede_a_ninguna_ruta_de_tickets`, `TaquillaPermisosTest::test_taquilla_recibe_403_en_exchange_rates` / `test_agencia_sin_tickets_ni_pagos` | ✅ COMPLIANT |
| `role:` solo en autenticación | `role:` residual documentado | 0 `role:` en rutas + `docs/dev/permisos-capabilities.md` §7 (allowlist de login/activación/`user`/`logout`) | ✅ COMPLIANT |
| Taquilla reportes propios y releases | Taquilla descarga y reporta | `TaquillaPermisosTest` (reportes 200, releases latest/download 200, filas acotadas por #85) + `ReleaseTest` | ✅ COMPLIANT |
| Tests y scan de autorización | Suite y scan verdes | Suite 1501/8678/2 OK + Pint passed (evidencia del usuario) + scans de `RoutePermissionCoverageTest`/`PermissionCatalogTest` | ✅ COMPLIANT |
| Sin cambios de scoping ni negocio | Alcance intacto | `git diff a229c1d..HEAD -- backend/app` limitado a policies nuevas de BE-04 (`Gate::before`) y limpieza de comentarios; `AlcanceService`/policies sin cambios; `DivergenciaRolTest`, `TicketMasterScopeTest`, `AlcanceServiceScopeTest` verdes | ✅ COMPLIANT |

**Compliance summary**: 20/20 escenarios compliant, 0 failing, 0 untested. El escenario de idempotencia se admite por evidencia estructural; su falta de test dedicado queda como WARNING (W1).

### Correctness (Static Evidence)

| Requirement | Status | Notes |
|------------|--------|-------|
| Catálogo 34 guard `api` | ✅ Implemented | `RolesAndPermissionsSeeder::PERMISSIONS` declara exactamente 34; script de verificación: `used=34`, `catalog=34`, sin diferencias. |
| Nombres existentes preservados | ✅ Implemented | `view_users`, `manage_bancas`, `view_exchange_rates`, etc. presentes con el mismo nombre. |
| 11 permisos faltantes | ✅ Implemented | `view_juegos`, `view_resultados`, `manage_resultados`, `view_agencias`, `manage_agencias`, `view_limites`, `manage_limites`, `view_logs`, `view_comisiones`, `download_release`, `manage_clave_cierre`. |
| Matriz exacta | ✅ Implemented | `MATRIX` = master 33, banca 24, grupo 21, agencia 17, taquilla 12; `super_master.syncPermissions([])` = 0. |
| `manage_users`/`manage_apuestas` ausentes | ✅ Implemented | `RETIRED_PERMISSIONS` + `delete()` con FKs `cascadeOnDelete` (seguro en BD existente). |
| `Gate::before` acotado | ✅ Implemented | `AppServiceProvider` L61-71: `hasRole('super_master')` + `PermissionRegistrar::getPermissions(name, guard api)`; retorna `true`/`null`, nunca `false`; sin `getAllPermissions()`. |
| `canAny()` cubierto por el bypass | ✅ Implemented | `PermissionMiddleware` usa `$user->canAny($permissions)` (Laravel `Authorizable` → `Gate`); Spatie v6 no redefine `canAny`; el `before` aplica a toda ability del catálogo. |
| Sin permisos directos a usuarios | ✅ Implemented | `grep givePermissionTo|syncPermissions app/` = 0; scans con control positivo. |
| Seeder idempotente/caché | ✅ Implemented | `firstOrCreate` + `syncPermissions` + `forgetCachedPermissions()` inicio/fin. Sin test de re-ejecución (ver W1). |
| Cero `role:` en rutas | ✅ Implemented | 0 ocurrencias en `routes/api.php` y `routes/web.php`; 0 referencias a `RoleMiddleware`; alias `role` eliminado de `bootstrap/app.php`. |
| Toda ruta autenticada con `permission:` | ✅ Implemented | Dos grupos `auth:sanctum` (L54, L68); allowlist `/user` y `/logout`; el resto con `permission:`; `RoutePermissionCoverageTest` lo fija. |
| Mapeo ruta→permiso | ✅ Implemented | `/tickets*` → `create_apuesta`; `/pagos` → `create_pago`/`view_pagos`; `/releases/*` → `download_release`; `/comisiones/defaults` → `manage_comisiones` (sin rol); `/comisiones/consulta` → `view_comisiones`; `/exchange-rates*` → view/manage solo master; reportes/estadísticas → `view_reports`. |
| Taquilla 12 permisos | ✅ Implemented | `view_users`, `view_juegos`, `view_resultados`, `view_apuestas`, `create_apuesta`, `delete_apuesta`, `view_pagos`, `create_pago`, `view_cierre`, `create_cierre`, `view_reports`, `download_release`. |
| `agencia` sin tickets/pagos | ✅ Implemented | No recibe `create_apuesta`, `view_pagos` ni `create_pago`; conserva `view_apuestas`, `delete_apuesta`, `manage_agencias`. |
| Row scoping intacto | ✅ Implemented | Diff de producción no toca `AlcanceService` ni policies; solo `AppServiceProvider`, seeder, rutas, `bootstrap`, y limpieza de 2 comentarios. |

### Coherence (Design)

| Decision | Followed? | Notes |
|----------|-----------|-------|
| D1 — `Gate::before` acotado al catálogo (nunca `false`) | ✅ Yes | Implementación literal del contrato; tests de bypass y de ability no-permiso. |
| D2 — Granularidad por método en `apiResource` (visibles en rutas) | ✅ Yes | Splits en `routes/api.php`; sin middleware oculto en controllers. |
| D3 — Prioridad acceso efectivo + concesiones explícitas | ✅ Yes | Matriz reconcilia delete_users, view_users taquilla, manage_agencias agencia, pagos/tickets agencia. |
| D4 — `/tickets` usa `create_apuesta` sin agencia | ✅ Yes | Todas las rutas de tickets bajo `permission:create_apuesta`; agencia 403 probado. |
| D5 — Seeder borra solo retirados (tolera terceros) | ✅ Yes | `whereIn(RETIRED_PERMISSIONS)`; no borra permisos ajenos. |
| D6 — `super_master` sin filas | ✅ Yes | `syncPermissions([])` + bypass. |
| D7 — Eliminar alias `role` | ✅ Yes | Alias e import retirados; se conservan `permission`, `role_or_permission` y `checkrole`. |
| D8 — Despliegue catálogo antes de rutas | ⚠️ Doc only | El runbook existe; `entrypoint.sh` solo siembra en BD nueva, así que el paso manual + `permission:cache-reset` sigue siendo obligatorio. |
| Mapeo diseño: `agencia` en `POST /apuestas` | ❌ Inconsistencia | `design.md` §Mapeo y `docs/dev/permisos-capabilities.md` §7 listan `agencia` para `create_apuesta`, pero la matriz final y el spec lo excluyen (y el seeder lo excluye). El runtime correcto es 403 (ver W2). |

### Conteos verificados

| Conteo | Resultado |
|---|---|
| Permisos del catálogo (guard `api`) | **34** (`PermissionCatalogTest` + inspección del seeder) |
| Permisos distintos usados en `routes/api.php` | **34**; usados ∖ catálogo = ∅; catálogo ∖ usados = ∅ |
| `role:` en `routes/api.php` | **0** (grep) |
| `role:` en `routes/web.php` | **0** (grep) |
| Referencias a `RoleMiddleware` en `app/`, `bootstrap/`, `routes/`, `tests/` | **0** |
| Alias `role` en `bootstrap/app.php` | **0** (eliminado) |
| Usos de `checkrole` / `role_or_permission` en rutas | **0 / 0** (alias registrados pero sin uso) |
| Matriz por rol | master **33**, banca **24**, grupo **21**, agencia **17**, taquilla **12**, super_master **0** |
| `givePermissionTo`/`syncPermissions` en `app/` | **0** |
| Archivos de producción modificados | 7 (`seeder`, `AppServiceProvider`, `routes/api.php`, `routes/web.php`, `bootstrap/app.php`, `GrupoController`, `TaquillaController`) |
| Tests nuevos | 4 (`PermissionCatalogTest`, `RoutePermissionCoverageTest`, `TaquillaPermisosTest`, `TicketsAuthorizationTest`) |
| Tests existentes modificados | 3 (`AgenciaApiTest`, `GestionEntidadesApiTest`, `ReleaseTest` — cambio deliberado de taquilla 403→200) |
| Suite completa (usuario) | 1501 tests, 8678 assertions, 2 skipped, OK |

### Issues Found

**CRITICAL**: None.

**WARNING**:
- **W1 — Escenario de idempotencia sin test dedicado.** `PermissionCatalogTest` siembra una sola vez por test; ningún test re-ejecuta `RolesAndPermissionsSeeder` sobre una BD ya sembrada para fijar “no duplica y refresca caché”. La implementación es estructuralmente idempotente (`firstOrCreate`, `syncPermissions`, `forgetCachedPermissions` inicio/fin, borrado de retirados con `cascadeOnDelete`) y el escenario se admite por esa evidencia, pero no queda protegido por un test en runtime.
- **W2 — Documentación inconsistente en `POST /apuestas`.** `docs/dev/permisos-capabilities.md` §7 y `design.md` §“Mapeo ruta → permiso” listan `agencia` entre los roles que pasan `permission:create_apuesta`, pero la matriz final, el spec y el seeder no le asignan `create_apuesta` (decisión D4). El comportamiento real y probado es 403 para agencia; el documento induce a error.
- **W3 — Bookkeeping pendiente.** El criterio global “`composer test` y `pint --test` en verde; row scoping intacto” sigue `[ ]` en `tasks.md`, y la fila BE-04 de `docs/dev/pendientes-front.md` aún dice “en vuelo / WU-1 en curso” pese a que las cuatro WUs están commiteadas y la suite está verde. Sin impacto de código.
- **W4 — `route:list` no ejecutado.** La regla del usuario impidió ejecutar artisan; el 0 `role:` y la cobertura se verificaron por grep estático + `RoutePermissionCoverageTest`, no por `php artisan route:list`. Riesgo bajo, pero la comprobación de runtime recomendada por el design queda pendiente.

**SUGGESTION**:
- Agregar un test que ejecute el seeder dos veces sobre la misma BD y verifique conteos estables, ausencia de `manage_users`/`manage_apuestas` y ejecución de `forgetCachedPermissions` (cierra W1).
- Agregar un caso 403 explícito para `/juegos` y `/resultados` con un usuario autenticado sin `view_juegos`/`view_resultados` (rol nulo o rol sin esa capability); hoy la rama negativa solo está garantizada por el middleware, no por una request real.
- Corregir la fila de `POST /apuestas` en `docs/dev/permisos-capabilities.md` y `design.md` para excluir a `agencia`, y marcar BE-04 como resuelto en `pendientes-front.md` al archivar.
- Retirar los alias `checkrole` y `role_or_permission` de `bootstrap/app.php` si no se van a usar (0 usos en rutas), o documentar que se conservan a propósito.
- Si se quiere separar `view_tickets` de `create_apuesta` en una change futura, agregar el filtro de `agencia` en `AlcanceService::applyToTickets()` antes de crear el permiso (open question del design).

### Riesgos residuales y follow-ups

- **Deploy del seeder/caché**: `entrypoint.sh` solo siembra cuando la tabla `migrations` no existe; en una BD existente hay que ejecutar `php artisan db:seed --class=RolesAndPermissionsSeeder --force` **antes** de enrutar tráfico a las rutas migradas y luego `permission:cache-reset` (o `optimize:clear`). Si se invierte el orden, las rutas responden 403 masivos (nunca 500) hasta refrescar la caché.
- **`syncPermissions` pisa grants manuales**: la matriz es el contrato; hacer backup de `role_has_permissions` antes del deploy y usar el seeder del tag anterior como rollback.
- **Sin migración de datos**: las tablas Spatie ya existen; el cambio es de contenido. El borrado de `manage_users`/`manage_apuestas` es seguro por `cascadeOnDelete`.
- **Reparenting `taquilla → grupo` / `taquilla → agencia`**: sigue fuera de alcance (#84/#85); BE-04 no lo toca.
- **`view_tickets` futuro**: no existe permiso propio; hoy `/tickets` se gatea con `create_apuesta`. Abrir un permiso nuevo a `agencia` sin filtro en `AlcanceService` repetiría la fuga que D4 evita.
- **Bypass y caché**: `super_master` depende de que el permiso exista en caché; si se despliega antes del seeder, el bypass devuelve `null` y la ruta responde 403 (fail-closed), no 500.
- **Push a GitHub**: diferido al cierre de BE-04; la evidencia de suite es local y el CI solo corre en `main`.

### Verdict

**PASS WITH WARNINGS** — Las 15 requirements y 20/20 escenarios quedan satisfechos; el catálogo, la matriz, el bypass y la migración `role:`→`permission:` coinciden con el spec y el design, y la suite/Pint están verdes. La única deuda es evidencia/documentación: falta un test de re-ejecución del seeder (W1), una fila de doc contradice la matriz en `POST /apuestas` (W2) y queda bookkeeping en `tasks.md`/`pendientes-front.md` (W3); no hay defectos de contrato ni de runtime.
