# Proposal: BE-04 — Catálogo de permisos por capability y migración `role:` → `permission:`

## Intent

La autorización de recursos en `backend/routes/api.php` está atada a **nombres de rol** (`role:`) en lugar de a **capabilities/permisos**. Esto impide administrar capacidades de forma granular, obliga a tocar rutas para cambiar qué puede hacer un rol, deja rutas sin permiso explícito (juegos/resultados) y mantiene un permiso muerto (`manage_users`). BE-04 introduce un catálogo de permisos por capability (view/manage por dominio), un bypass `Gate::before` para `super_master`, y migra **toda** la capa de middleware de `role:` a `permission:`, preservando nombres de permisos existentes y sin tocar el scoping de filas (ya centralizado por #85 en `AlcanceService`).

## Evidencia actual

- **24 grupos de ruta** usan `role:`; **solo 3** usan `permission:` (exchange-rates view/manage y `comisiones/defaults` PUT, este último combinado `role:+permission:`).
- **Rutas sin permiso alguno** (solo `auth:sanctum`+`verify.mac`): `GET /juegos*` (index/show/opciones/horarios/reglas) y `GET /resultados*` (index/show/apariciones) → requieren `view_juegos` y `view_resultados`.
- **6 roles**: `super_master`, `master`, `banca`, `grupo`, `agencia`, `taquilla`.
- **`RolesAndPermissionsSeeder`** define 27 permisos y los sincroniza por rol. Incluye `manage_users` (sin uso en ninguna ruta). **No incluye** los necesarios.
- **Permisos faltantes**: `view_juegos`, `view_resultados`, `manage_resultados`, `view_agencias`, `manage_agencias`, `view_limites`, `manage_limites`, `view_logs`, `view_comisiones`, `download_release`, `manage_clave_cierre`.
- **Hallazgo adicional**: `manage_config` existe en el catálogo pero el rol `master` no lo recibe, pese a que `configuraciones/apuestas-vencimiento` está en `role:super_master|master`.
- **No existe `Gate::before`** en ningún punto del backend (verificado por búsqueda global).
- `TaquillaController`/`GrupoController` tienen el patrón `$this->middleware('permission:...')` **comentado** (histórico); hoy toda la autorización vive en rutas.
- El rol `taquilla` del seeder **no tiene `view_reports`** (latente: hoy la ruta lo deja pasar por `role:`); al migrar a `permission:view_reports` hay que otorgárselo.

## Scope

### In Scope
- Catálogo de permisos por capability (view/manage por dominio), preservando nombres existentes y agregando los faltantes.
- `Gate::before` en `AppServiceProvider` para `super_master` (bypass total, sin depender de permisos asignados).
- Migración total de `role:` → `permission:` en `routes/api.php` (y `permission:` por método en los `apiResource` con CRUD mixto).
- Eliminar `manage_users` del catálogo y del seeder. **No** agregar `manage_apuestas`.
- Taquilla: `view_reports` (reportes propios, alcance ya acotado por #85) y `download_release` (descarga de releases).
- Prohibición de permisos directos a usuarios (`givePermissionTo`/`syncPermissions` sobre `User`); asignación solo vía roles.
- Documentar la matriz capability × rol en `docs/dev/`.

### Out of Scope
- **Row scoping / alcance de filas** (cerrado en #85 vía `AlcanceService`/policies). No se toca.
- Lógica de login/activación/`verify.mac` (no es autorización de recurso; `role:` puede quedar documentado solo ahí si aplica).
- Cambios de comportamiento de negocio, endpoints, o mensajes de denegación de filas.
- Push a GitHub (se pospone al cierre de BE-04).

## Capabilities

> Contrato con sdd-spec: qué specs crear/actualizar.

### New Capabilities
- `catalogo-permisos`: catálogo de permisos por capability, matriz capability × rol, seeder (`RolesAndPermissionsSeeder`), `Gate::before` para `super_master`, y la regla de "sin permisos directos a usuarios".
- `autorizacion-capability`: migración del middleware de `role:` a `permission:` en `routes/api.php`/controllers `apiResource`, cobertura de rutas sin permiso, y concesión de reportes/releases a taquilla.

### Modified Capabilities
- None. `autorizacion-rol-unico` (rol como fuente única) y `arquitectura-alcance` (row scope) no cambian a nivel de spec: BE-04 es una capa nueva por encima.

## Approach

1. **Catálogo + seeder + Gate::before**: definir la matriz capability × rol; actualizar `RolesAndPermissionsSeeder` (quitar `manage_users`, agregar los 11 faltantes, otorgar `view_reports`/`download_release` a `taquilla`, `manage_config` a `master`); `Gate::before` en `AppServiceProvider::boot()` que devuelve `true` para `super_master`.
2. **Middleware/alias**: los alias `role`/`permission` ya existen en `bootstrap/app.php`; no se crean nuevos. Se limpian los `use` de `RoleMiddleware` si dejan de usarse.
3. **Rutas por grupos** (PRs ≤800 líneas): usuarios, entidades (bancas/grupos/agencias/taquillas), juegos+resultados, apuestas/tickets/pagos/cierres, límites+comisiones+exchange-rates, reportes/estadísticas/logs/releases+clave-cierre. Para `apiResource` con CRUD mixto se usa `permission:` por método (patrón ya comentado en `TaquillaController`/`GrupoController`).
4. **Tests/docs**: actualizar/crear tests de autorización por permission (`RoleAuthorizationTest`, `DivergenciaRolTest` no cambian su semántica de scope; se agrega cobertura de la matriz), documentar matriz en `docs/dev/`.

## Matriz capability × rol (propuesta)

> `super_master` = bypass por `Gate::before` (no depende de permisos). `v`=view, `m`=manage.

| Dominio | Permisos | master | banca | grupo | agencia | taquilla |
|---|---|---|---|---|---|---|
| Usuarios | `view_users` `create_users` `edit_users` `delete_users` | v+c+e+d | v+c+e | v+c+e | v+c+e | — |
| Bancas | `view_bancas` `manage_bancas` | v+m | — | — | — | — |
| Grupos | `view_grupos` `manage_grupos` | v+m | v+m | — | — | — |
| Agencias | `view_agencias` `manage_agencias` | v+m | v+m | v+m | v (propio) | — |
| Taquillas | `view_taquillas` `manage_taquillas` | v+m | v+m | v+m | v+m | — |
| Juegos | `view_juegos` `manage_juegos` | v+m | v | v | v | v |
| Resultados | `view_resultados` `manage_resultados` | v+m | v | v | v | v |
| Apuestas | `view_apuestas` `create_apuesta` `delete_apuesta` | v+c+d | v+c+d | v+c+d | v+c+d | v+c+d |
| Pagos | `view_pagos` `create_pago` | v+c | v+c | v+c | v+c | v+c |
| Cierres | `view_cierre` `create_cierre` | v+c | v+c | v+c | v+c | v+c |
| Límites | `view_limites` `manage_limites` | v+m | v+m | v+m | v | — |
| Comisiones | `view_comisiones` `manage_comisiones` | v | — | — | — | — |
| Exchange rates | `view_exchange_rates` `manage_exchange_rates` | v+m | v | v | v | v |
| Reportes | `view_reports` | v | v | v | v | v |
| Logs | `view_logs` | v | — | — | — | — |
| Releases | `download_release` | v | v | v | v | v |
| Clave cierre | `manage_clave_cierre` | m | m | — | — | — |
| Config | `manage_config` | m | — | — | — | — |

Notas de mapeo (refinar en spec): `estadisticas/rendimiento` → `view_reports`; `tickets` sigue el set de apuestas (sin `agencia`); `comisiones/defaults` GET queda en `super_master` (bypass) mientras `consulta` → `view_comisiones` para `master`.

## Affected Areas

| Area | Impact | Descripción |
|------|--------|-------------|
| `backend/database/seeders/RolesAndPermissionsSeeder.php` | Modified | Quitar `manage_users`, agregar faltantes, ajustar matriz por rol |
| `backend/app/Providers/AppServiceProvider.php` | Modified | `Gate::before` para `super_master` |
| `backend/routes/api.php` | Modified | Migración `role:` → `permission:` (24 grupos) |
| `backend/app/Http/Controllers/Api/*Controller.php` | Modified | `permission:` por método en `apiResource` mixtos (users/bancas/grupos/agencias/taquillas) |
| `backend/tests/Feature/RoleAuthorizationTest.php` y nuevos | Modified/New | Cobertura de matriz por permission |
| `docs/dev/` | New | Documentar matriz capability × rol |
| `backend/bootstrap/app.php` | Modified | Limpiar alias/use si `RoleMiddleware` queda sin uso |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Tests que asumen 403 por rol y rompen al migrar a `permission:` | High | Migrar por grupos con suite verde por work unit; actualizar asserts a permission |
| Rutas sin `permission:` tras la migración (agujero de autorización) | Med | Grep final: 0 rutas de recurso con `role:`; todas con `permission:` |
| Seeder en producción: `syncPermissions` pisa permisos manuales | Med | Seeder idempotente (`firstOrCreate` + sync de matriz conocida); documento de rollback |
| Cache de Spatie (`spatie.permission.cache`, 24h) devuelve permisos viejos | Med | `forgetCachedPermissions()` en el seeder y comando de refresco post-deploy |
| Permisos directos a usuarios reintroducidos por copy/paste | Low | Regla documentada + test/scan que prohíbe `givePermissionTo`/`syncPermissions` sobre `User` |
| Concesión de `download_release` a taquilla cambia comportamiento | Med | Deliberado (requisito BE-04); verificar que `serve` (firmado) sigue siendo la vía de instalación |

## Rollback Plan

- Revertir por PR (stacked): cada PR de migración es independiente y reversible con `git revert`.
- La convivencia temporal `role:`/`permission:` (Spatie soporta ambos middlewares a la vez) permite desplegar el catálogo primero sin romper rutas.
- Re-ejecutar `RolesAndPermissionsSeeder` para restaurar la matriz anterior si un cambio de permisos es erróneo.
- `migrate:fresh --seed` en dev para reconstruir roles/permisos limpios; en prod el seeder es idempotente.

## Dependencies

- #84 (alcance jerárquico usuarios) y #85 (centralización de row scope en `AlcanceService`) ya mergeados en `main` local; BE-04 se construye encima sin modificarlos.
- Spatie Permission ya instalado y configurado (`config/permission.php`, aliases en `bootstrap/app.php`).

## Success Criteria

- [ ] 0 rutas de recurso usan `role:`; todas las rutas protegidas usan `permission:`.
- [ ] `Gate::before` devuelve bypass total para `super_master`.
- [ ] `manage_users` eliminado del catálogo y del seeder; `manage_apuestas` ausente.
- [ ] Los 11 permisos faltantes existen y están asignados según la matriz.
- [ ] Taquilla puede ver sus propios reportes (`view_reports`) y descargar releases (`download_release`).
- [ ] Matriz capability × rol documentada en `docs/dev/`.
- [ ] Sin permisos directos a usuarios (solo vía roles); scan/test lo verifica.
- [ ] `composer test` en verde; `pint --test` en verde (CI).

## Relación con #84 / #85 y push final

- #84/#85 están **cerrados y archivados** (row scope). BE-04 no los reabre ni cambia su alcance: la capa de permisos es ortogonal al scoping de filas.
- El push a GitHub de todo el stack de BE-04 (catálogo → rutas → tests/docs) se hace **una sola vez al cierre**, contra `main`, sin tocar GitHub durante el desarrollo.
