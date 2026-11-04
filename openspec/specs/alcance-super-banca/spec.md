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

### Requirement: ApuestaPolicy::view/delete acotado para master

`ApuestaPolicy::view` y `ApuestaPolicy::delete` MUST autorizar a un `master` solo cuando la apuesta pertenece a una de sus bancas administradas; para apuestas de banca ajena MUST denegar (403). `super_master` MUST conservar acceso global. Un master sin bancas MUST quedar denegado.

#### Scenario: Master ve apuesta de su banca

- GIVEN un master con banca administrada y una apuesta de esa banca
- WHEN evalúa `view`/`delete`
- THEN se autoriza

#### Scenario: Master ve apuesta de banca ajena

- GIVEN un master y una apuesta de una banca que no administra
- WHEN evalúa `view`/`delete`
- THEN se deniega (403)

#### Scenario: Master sin bancas

- GIVEN un master sin bancas asignadas
- WHEN evalúa `view`/`delete` sobre cualquier apuesta
- THEN se deniega

#### Scenario: Super_master global

- GIVEN un `super_master`
- WHEN evalúa `view`/`delete`
- THEN se autoriza siempre

### Requirement: Alcance master en tickets

`TicketController::index`, `show` y `ganadores` MUST acotar al master a los tickets/apuestas de sus bancas administradas; un master sin bancas MUST ver resultado vacío y `show` de un ticket ajeno MUST devolver 403. `super_master` MUST conservar alcance global.

#### Scenario: Master lista tickets de sus bancas

- GIVEN un master con bancas propias
- WHEN consulta `GET /tickets`
- THEN solo ve tickets de sus bancas

#### Scenario: Master consulta ticket ajeno

- GIVEN un master y un ticket de una banca que no administra
- WHEN consulta `GET /tickets/{id}`
- THEN 403

#### Scenario: Master consulta ganadores de sus bancas

- GIVEN un master con bancas propias
- WHEN consulta `GET /tickets/ganadores`
- THEN solo ve ganadores de sus bancas

#### Scenario: Super_master global en tickets

- GIVEN un `super_master`
- WHEN consulta tickets/ganadores
- THEN ve todo
