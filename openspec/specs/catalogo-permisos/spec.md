# catalogo-permisos Specification

**Estado**: draft

## Purpose

Define el catálogo de permisos por capability (view/manage por dominio), la matriz capability × rol (6 roles) como fuente única de asignación, el seeder `RolesAndPermissionsSeeder` idempotente y el bypass `Gate::before` para `super_master`. Establece que los permisos se otorgan solo vía roles, nunca directamente a usuarios. No altera el scoping de filas (#85). Este cambio (#89) añade los permisos de auditoría `view_audit` y `export_audit`.

## Requirements

### Requirement: Catálogo por capability

El sistema MUST mantener un catálogo de permisos organizado por dominio con granularidad view/manage (o create/edit/delete donde el CRUD lo exige). Los nombres existentes MUST preservarse. Además de los 11 de BE-04, este cambio MUST agregar `view_audit` y `export_audit` (dominio Auditoría).

| Dominio | Permisos |
|---|---|
| Usuarios | `view_users` `create_users` `edit_users` `delete_users` |
| Bancas | `view_bancas` `manage_bancas` |
| Grupos | `view_grupos` `manage_grupos` |
| Agencias | `view_agencias` `manage_agencias` |
| Taquillas | `view_taquillas` `manage_taquillas` |
| Juegos | `view_juegos` `manage_juegos` |
| Resultados | `view_resultados` `manage_resultados` |
| Apuestas | `view_apuestas` `create_apuesta` `delete_apuesta` |
| Pagos | `view_pagos` `create_pago` |
| Cierres | `view_cierre` `create_cierre` |
| Límites | `view_limites` `manage_limites` |
| Comisiones | `view_comisiones` `manage_comisiones` |
| Exchange rates | `view_exchange_rates` `manage_exchange_rates` |
| Reportes | `view_reports` |
| Logs | `view_logs` |
| Auditoría | `view_audit` `export_audit` |
| Releases | `download_release` |
| Clave cierre | `manage_clave_cierre` |
| Config | `manage_config` |

#### Scenario: Nombres existentes preservados

- GIVEN el catálogo vigente con `view_users`, `manage_bancas`, `view_exchange_rates`
- WHEN se aplica BE-04 y #89
- THEN esos nombres permanecen idénticos y `view_audit`/`export_audit` existen con guard `api`

#### Scenario: Permisos muertos ausentes

- GIVEN el catálogo aplicado
- WHEN se inspeccionan los permisos
- THEN `manage_users` está ausente y `manage_apuestas` no existe

### Requirement: Matriz capability × rol

La matriz capability × rol MUST documentarse en `docs/dev/` y ser la fuente única del seeder. Define qué recibe cada rol (`super_master` queda fuera por bypass).

| Dominio | master | banca | grupo | agencia | taquilla |
|---|---|---|---|---|---|
| Usuarios | v,c,e,d | v,c,e,d | v,c,e,d | v,c,e,d | v |
| Bancas | v,m | — | — | — | — |
| Grupos | v,m | v,m | — | — | — |
| Agencias | v,m | v,m | v,m | v,m* | — |
| Taquillas | v,m | v,m | v,m | v,m | — |
| Juegos | v,m | v | v | v | v |
| Resultados | v,m | v | v | v | v |
| Apuestas | v,c,d | v,c,d | v,c,d | v,d | v,c,d |
| Pagos | v,c | v,c | v,c | — | v,c |
| Cierres | v,c | v,c | v,c | v,c | v,c |
| Límites | v,m | v,m | v,m | v | — |
| Comisiones | v | — | — | — | — |
| Exchange rates | v,m | — | — | — | — |
| Reportes | v | v | v | v | v |
| Logs | v | — | — | — | — |
| Auditoría | v,e | — | — | — | — |
| Releases | v | v | v | v | v |
| Clave cierre | m | m | — | — | — |
| Config | m | — | — | — | — |

`super_master` queda fuera de la matriz (bypass). Conteos: master 35, banca 24,
grupo 21, agencia 17, taquilla 12, super_master 0.

`*` `agencia` recibe `manage_agencias` para editar su propia ficha; `store`,
`toggle` y `destroy` siguen bloqueados por el controller para ese rol.

`view_audit` y `export_audit` se asignan solo a `master` (super_master vía
bypass). El alcance de lectura lo acota `AlcanceService` a las bancas que el
master administra; la capability no amplía el scope de filas (#85).

`/tickets*` no tiene permiso propio: usa `create_apuesta`, que **no** se asigna
a `agencia`. `AlcanceService` no filtra tickets por agencia, así que otorgarle
`view_apuestas` abriría una fuga de tickets de todo el sistema.

#### Scenario: Matriz documentada

- GIVEN #89 cerrado
- WHEN un desarrollador consulta `docs/dev/`
- THEN encuentra la matriz capability × rol actualizada con Auditoría (v,e solo master)

#### Scenario: Auditoría solo master

- GIVEN usuarios `banca`, `grupo`, `agencia` y `taquilla`
- WHEN consultan `/audit-events`
- THEN reciben 403 (solo `master` tiene `view_audit`, y `super_master` por bypass)

### Requirement: Reconciliación con el acceso efectivo

La matriz MUST preservar el acceso efectivo previo a BE-04 salvo las
concesiones explícitas. En concreto:

- `view_users` MUST asignarse a `taquilla` para conservar la lectura de su
  propia ficha.
- `delete_users` MUST asignarse a `master`, `banca`, `grupo` y `agencia`
  (borrado jerárquico de #84).
- `manage_agencias` MUST asignarse a `agencia` para editar su propia ficha.
- `agencia` MUST NOT recibir `view_pagos`, `create_pago` ni `create_apuesta`.
- `view_exchange_rates` MUST asignarse solo a `master`.
- `manage_config` MUST asignarse a `master`.
- `view_audit` y `export_audit` MUST asignarse solo a `master`.

#### Scenario: agencia edita su ficha pero no toca tickets ni pagos

- GIVEN un usuario `agencia`
- WHEN actualiza su propia agencia
- THEN pasa por `manage_agencias`
- AND al consultar tickets o pagos recibe 403 (no tiene `create_apuesta` ni
  `view_pagos`/`create_pago`)

#### Scenario: exchange rates y configuración solo master

- GIVEN usuarios `banca`, `grupo`, `agencia` y `taquilla`
- WHEN consultan `/exchange-rates`
- THEN reciben 403 (solo `master` tiene `view_exchange_rates`)
- AND solo `master` pasa `permission:manage_config`

### Requirement: Bypass total para super_master

`super_master` MUST disponer de bypass total vía `Gate::before`, sin depender de permisos asignados. El bypass MUST cubrir cualquier permiso presente o futuro (incluidos `view_audit` y `export_audit`).

#### Scenario: super_master sin permisos asignados

- GIVEN un usuario con rol `super_master` sin permisos de matriz
- WHEN accede a un recurso protegido por `permission:`
- THEN el gate autoriza (true) sin evaluar permisos

### Requirement: Asignación solo vía roles

Los permisos MUST asignarse únicamente a roles. `givePermissionTo` y `syncPermissions` sobre un `User` MUST NOT usarse; todo permiso de usuario llega por su rol.

#### Scenario: Scan prohíbe asignación directa

- GIVEN el código aplicado
- WHEN un test/scan busca `givePermissionTo`/`syncPermissions` sobre `User`
- THEN no encuentra ocurrencias en `app/`

### Requirement: Seeder idempotente

`RolesAndPermissionsSeeder` MUST ser idempotente: `Permission::firstOrCreate` + `syncPermissions` de la matriz conocida (incluidos `view_audit`/`export_audit`), y MUST invocar `forgetCachedPermissions()` para invalidar el cache de Spatie.

#### Scenario: Re-ejecución segura

- GIVEN el seeder ya ejecutado
- WHEN se re-ejecuta (deploy o rollback)
- THEN no duplica permisos/roles y el cache de Spatie se refresca

### Requirement: Concesiones puntuales

`master` MUST recibir `manage_config` (la ruta de configuraciones hoy lo permite por rol). `taquilla` MUST recibir `view_reports` (reportes propios, alcance ya acotado por #85) y `download_release` (descarga de releases).

#### Scenario: master gestiona configuración

- GIVEN un usuario `master`
- WHEN lee o edita `configuraciones/apuestas-vencimiento`
- THEN pasa por `permission:manage_config`

#### Scenario: taquilla reportes propios y releases

- GIVEN un usuario `taquilla`
- WHEN descarga un release o consulta sus reportes
- THEN pasa por `download_release` y `view_reports`, con filas acotadas por #85
