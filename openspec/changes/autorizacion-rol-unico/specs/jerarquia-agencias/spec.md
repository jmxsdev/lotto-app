# Delta for jerarquia-agencias

> Solo se modifica el requirement "Rol agencia en ambas fuentes". El resto de la capability `jerarquia-agencias` permanece sin cambios.

## RENAMED Requirements

### Requirement: Rol agencia en ambas fuentes → Rol agencia en Spatie

(Reason: la columna `users.role` deja de ser fuente de verdad del rol; el rol vive únicamente en Spatie.)
(Migration: las referencias y tests que exigían `users.role = 'agencia'` pasan a verificar `hasRole('agencia')` / `getRoleNames()`.)

## MODIFIED Requirements

### Requirement: Rol agencia en Spatie

El sistema MUST registrar el rol `agencia` únicamente en Spatie (`roles`/`model_has_roles`), con permisos `view_taquillas, manage_taquillas, view_apuestas, create_apuesta, delete_apuesta, create_pago, view_pagos, view_reports, create_cierre, view_cierre`. La columna `users.role` MUST NOT ser fuente ni destino del rol.
(Previously: el rol `agencia` debía registrarse en AMBAS fuentes — columna `users.role` y rol Spatie.)

#### Scenario: Registro único en Spatie

- GIVEN un usuario rol agencia
- WHEN se consulta su rol
- THEN posee el rol Spatie `agencia` con los permisos listados, sin depender de `users.role`

#### Scenario: Divergencia con la columna legacy

- GIVEN un usuario con Spatie `agencia` y columna `users.role` distinta
- WHEN accede a un recurso protegido
- THEN la autorización usa Spatie (accede según `agencia`), ignorando la columna
