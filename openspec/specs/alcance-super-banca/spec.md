# alcance-super-banca Specification

**Estado**: draft

## Purpose

Restringe al master (super banca) a sus propias bancas y descendientes, e incorpora el rol agencia al panel (X-Panel). Introduce `bancas.master_id` y acota los ~10 queries globales de master.

## Requirements

### Requirement: Asociación master↔banca

El sistema MUST persistir `bancas.master_id` (nullable, FK a users) y rellenarla desde `created_by` (backfill). Un master sin bancas asignadas MUST NOT ver entidades ajenas.

#### Scenario: Backfill de master

- GIVEN una banca creada por un master
- WHEN se ejecuta el backfill
- THEN `master_id` referencia al master

#### Scenario: Master sin bancas

- GIVEN un master sin bancas asignadas
- WHEN consulta entidades
- THEN no ve entidades de otras bancas

### Requirement: Alcance master en entidades

El master MUST ver solo sus bancas y sus grupos/taquillas/usuarios en los queries de entidades (bancas, grupos, taquillas, usuarios).

#### Scenario: Listado scoped

- GIVEN un master con bancas propias
- WHEN lista bancas/grupos/taquillas/usuarios
- THEN solo ve sus bancas y descendientes

#### Scenario: Super master global

- GIVEN un super_master
- WHEN lista entidades
- THEN sigue viendo todo

### Requirement: Alcance master en reportes y estadísticas

El master MUST quedar acotado a sus bancas en reportes (ventas, cuadre, rendimiento) y estadísticas (time series), en lugar de ser global.

#### Scenario: Reporte scoped

- GIVEN un master
- WHEN consulta un reporte
- THEN solo ve datos de sus bancas

#### Scenario: Serie temporal scoped

- GIVEN un master
- WHEN consulta estadísticas de serie temporal
- THEN el alcance se limita a sus bancas

### Requirement: Alcance master en apuestas, cierre y límites

El master MUST quedar acotado a sus bancas en apuestas, cierre de caja y límites.

#### Scenario: Consultas scoped

- GIVEN un master
- WHEN lista apuestas/cierres/límites
- THEN solo ve los de sus bancas

#### Scenario: Sin bancas

- GIVEN un master sin bancas asignadas
- WHEN consulta apuestas/cierres/límites
- THEN obtiene resultado vacío (no global)

### Requirement: Login X-Panel admite rol agencia

El login con `X-Panel` MUST admitir el rol `agencia` y aplicar la rama agencia en el mensaje de cadena inactiva.

#### Scenario: Agencia admitida

- GIVEN un usuario rol agencia activo
- WHEN inicia sesión con `X-Panel`
- THEN accede al panel

#### Scenario: Local inactivo

- GIVEN un usuario rol agencia con local inactivo
- WHEN inicia sesión con `X-Panel`
- THEN recibe el mensaje de cadena inactiva (agencia/grupo/banca)

#### Scenario: Taquilla rechazada

- GIVEN un usuario rol taquilla
- WHEN inicia sesión con `X-Panel`
- THEN se rechaza (debe usar la app de escritorio)
