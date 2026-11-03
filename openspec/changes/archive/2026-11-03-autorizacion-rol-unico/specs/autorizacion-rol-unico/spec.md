---
capability: autorizacion-rol-unico
change: autorizacion-rol-unico
status: draft
---

# autorizacion-rol-unico Specification

## Purpose

Unificar el rol del usuario en una única fuente de verdad — Spatie (`hasRole()`, `hasAnyRole()`, `getRoleNames()`) —, eliminar la columna legacy `users.role`, preservar el contrato de API con un atributo calculado `role` y auditar los cambios de rol (`user.role_changed`). Elimina la divergencia entre columna y Spatie que hoy produce scoping incoherente.

## Requirements

| # | Requirement | Strength | Estado |
|---|-------------|----------|--------|
| REQ-1 | Fuente única de rol en Spatie | MUST | draft |
| REQ-2 | Accessor calculado `role` (compatibilidad API) | MUST | draft |
| REQ-3 | Eliminación de `users.role` (expand/contract) | MUST | draft |
| REQ-4 | Autorización y scoping fail-closed con Spatie | MUST | draft |
| REQ-5 | Contrato de login/API preserva `role` | MUST | draft |
| REQ-6 | Migración de las 6 queries ocultas | MUST | draft |
| REQ-7 | Auditoría `user.role_changed` | MUST | draft |
| REQ-8 | Rollout expand/contract y wipe/reseed en dev | MUST | draft |
| REQ-9 | Cobertura de tests de divergencia y rol | MUST | draft |

### Requirement: REQ-1 — Fuente única de rol en Spatie

Spatie (`roles`/`model_has_roles`) es la única fuente de verdad del rol. `users.role` MUST NOT leerse ni escribirse como fuente de autorización o scoping. Toda lectura de autorización/scoping MUST usar `hasRole`, `hasAnyRole` o scopes Spatie.

#### Scenario: Consulta de rol usa Spatie

- GIVEN un usuario con rol Spatie `master` y columna `users.role` divergente
- WHEN se evalúa su rol o scope
- THEN la autorización usa Spatie (`master`) y no la columna

#### Scenario: Divergencia histórica resuelta

- GIVEN un usuario con `users.role='taquilla'` y Spatie `master`
- WHEN consulta comisiones (`ComisionController::consulta`)
- THEN devuelve el alcance de `master`, no `null`

### Requirement: REQ-2 — Accessor calculado `role` (compatibilidad API)

`User` MUST exponer `role` como atributo calculado no persistido mediante `$appends`, `getRoleAttribute()` y `primaryRole()` (primer rol Spatie). El accessor MUST NOT leer la columna `users.role`.

#### Scenario: Accessor refleja Spatie

- GIVEN un usuario cuyo primer rol Spatie es `agencia`
- WHEN se serializa el usuario (login/`me`)
- THEN `user.role === 'agencia'`

#### Scenario: Usuario sin rol Spatie

- GIVEN un usuario sin rol asignado en Spatie
- WHEN se consulta `role`
- THEN el accessor devuelve un valor seguro (`null`), nunca la columna legacy

### Requirement: REQ-3 — Eliminación de `users.role` (expand/contract)

La columna `users.role` MUST eliminarse en dos merges: Merge 1 (expand) deja la columna física pero muerta (sin lecturas/escrituras); Merge 2 (contract) ejecuta `DROP COLUMN role` y limpia `$fillable`/casts/seeders. La migración destructiva MUST ser idempotente y reversible por migración inversa manual.

#### Scenario: Post-contract

- GIVEN el Merge 2 aplicado
- WHEN se inspecciona el esquema y el código
- THEN `users.role` no existe y ninguna referencia la usa

#### Scenario: Reversión de esquema

- GIVEN la necesidad de revertir el drop
- WHEN se ejecuta la migración inversa manual
- THEN la columna se restaura sin datos de rol (vacía)

### Requirement: REQ-4 — Autorización y scoping fail-closed con Spatie

Toda decisión de autorización y scoping MUST fallar cerrado: si el rol no puede determinarse desde Spatie, el acceso MUST denegarse (403) o el scope MUST quedar vacío. MUST NOT existir fallback global ni lectura de `users.role` como respaldo.

#### Scenario: Usuario sin rol Spatie

- GIVEN un usuario autenticado sin roles Spatie
- WHEN accede a un recurso protegido por rol
- THEN se deniega (403) o el scope queda vacío

#### Scenario: Sin fallback global

- GIVEN un usuario con columna `users.role` poblada pero sin rol Spatie
- WHEN se evalúa su scope
- THEN el resultado es vacío/denegado, sin caer a la columna

### Requirement: REQ-5 — Contrato de login/API preserva `role`

El payload de login y de `me` MUST seguir incluyendo `role` (y `user.role`) con el valor Spatie, sin cambios de shape; el frontend no se modifica.

#### Scenario: Login taquilla

- GIVEN un usuario con rol Spatie `taquilla`
- WHEN hace login
- THEN la respuesta incluye `role: 'taquilla'`

#### Scenario: VerifyMac consistente

- GIVEN el flujo de login que exige MAC
- WHEN se evalúa `hasRole('taquilla')` (Spatie)
- THEN exige MAC solo si Spatie dice `taquilla`, independiente de la columna

### Requirement: REQ-6 — Migración de las 6 queries ocultas

Las 6 queries `where('role',...)` MUST migrarse a Spatie: `CierreService::validarClaveCierre` (169,173,176), `AgenciaController::destroy` (178), `UserController::index` (111), `AgenciasBackfill` (39,95), `E2eSeeder` (80,130,131). Ninguna lectura/escritura residual de `users.role` MUST quedar en `app/`.

#### Scenario: Búsqueda global

- GIVEN el cambio aplicado
- WHEN se busca `where('role',...)` en `app/`
- THEN no quedan resultados

#### Scenario: Scoping por rol

- GIVEN `UserController::index` filtrado por rol
- WHEN se listan usuarios por rol
- THEN el filtro usa `whereHas('roles', ...)` de Spatie

### Requirement: REQ-7 — Auditoría `user.role_changed`

`UserController::update` MUST emitir el evento/registro `user.role_changed` cuando cambia el rol, con actor, target, `before`/`after`, IP y user-agent. Sin cambio de rol, MUST NOT emitirse.

#### Scenario: Cambio de rol registrado

- GIVEN un admin que edita el rol de un usuario
- WHEN persiste un rol distinto
- THEN se registra `user.role_changed` con actor, target, `before`, `after`, IP y user-agent

#### Scenario: Sin cambio de rol

- GIVEN una edición de usuario que no cambia el rol
- WHEN persiste
- THEN no se emite `user.role_changed`

### Requirement: REQ-8 — Rollout expand/contract y wipe/reseed en dev

El rollout MUST ser dos merges coordinados (expand luego contract) con cero downtime. El VPS dev se reconstruye con `migrate:fresh --seed`; no hay backfill permanente en producción.

#### Scenario: Rollback del expand

- GIVEN el Merge 1 desplegado
- WHEN se revierte
- THEN el redeploy de la imagen anterior funciona (columna intacta + código Spatie)

#### Scenario: Reconstrucción dev

- GIVEN el VPS dev
- WHEN se ejecuta `migrate:fresh --seed`
- THEN los roles quedan solo en Spatie sin errores

### Requirement: REQ-9 — Cobertura de tests de divergencia y rol

Los tests MUST cubrir la divergencia resuelta (4 casos del proposal) y la auditoría, y actualizar los que fijan `users.role`. `composer test` MUST quedar en verde.

#### Scenario: Divergencia cubierta

- GIVEN los 4 casos de divergencia (comisiones, apuestas/cierres, reportes, VerifyMac)
- WHEN se ejecutan los tests
- THEN el scope resultante coincide con Spatie

#### Scenario: Suite verde

- GIVEN la suite actualizada
- WHEN se ejecuta `composer test`
- THEN pasa en verde
