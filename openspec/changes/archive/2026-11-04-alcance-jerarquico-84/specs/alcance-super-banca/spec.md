# Delta for alcance-super-banca

> Se acota al master en `ApuestaPolicy::view/delete` (caso 2) y en `TicketController::index/show/ganadores` (caso 4). El resto de la capability `alcance-super-banca` permanece sin cambios.

## ADDED Requirements

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
