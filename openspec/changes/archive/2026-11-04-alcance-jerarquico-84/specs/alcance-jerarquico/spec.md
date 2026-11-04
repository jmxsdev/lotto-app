# alcance-jerarquico Specification

**Estado**: draft

## Purpose

Aplica el alcance jerárquico (super_master → master → banca → grupo → agencia → taquilla) de forma uniforme al CRUD de usuarios y entidades, protege al `super_master` como intocable y añade `users.created_by` (metadata de fila) para soportar la política "el rol gestiona lo que creó". Cierra las brechas de fuga cross-tenant y de autorización insuficiente del issue #84.

## Requirements

### Requirement: Matriz CRUD jerárquica uniforme

El sistema MUST autorizar el CRUD de usuarios y entidades según la matriz por rol. Un rol SHALL gestionar únicamente su subárbol y lo que creó; los pares y superiores MUST quedar prohibidos.

| Rol | Usuarios que gestiona | Entidades que gestiona | Prohibido |
|-----|----------------------|------------------------|-----------|
| super_master | todos | todas | nadie lo borra; no borra otro super_master |
| master | usuarios de sus bancas + los que creó | bancas que administra, grupos, agencias, taquillas de su árbol | super_masters, otros masters, su propio creador |
| banca | usuarios de su banca + los que creó | grupos, agencias, taquillas de su banca | bancas, masters, super_masters; no crea otro banca |
| grupo | usuarios de su grupo + los que creó | agencias, taquillas de su grupo | grupos, bancas, masters, super_masters; no crea otro grupo |
| agencia | usuarios de su agencia + los que creó | su propia ficha + taquillas de su agencia | agencias ajenas, grupos, bancas, masters, super_masters; no crea otra agencia |
| taquilla | ninguno | ninguna (solo lectura de su usuario) | — |

#### Scenario: Rol acotado a su subárbol

- GIVEN un usuario con rol `grupo` vinculado a su grupo
- WHEN intenta gestionar una entidad de otro grupo
- THEN se deniega (403)

#### Scenario: Banca borra usuario de su subárbol

- GIVEN una banca con un usuario de grupo/agencia/taquilla creado por ella o dentro de su banca
- WHEN la banca lo borra
- THEN la operación se permite (200) si el usuario es de rango menor

#### Scenario: Banca no borra un par

- GIVEN una banca A y un usuario con rol `banca` de otra banca
- WHEN la banca A intenta borrarlo
- THEN se deniega (403)

#### Scenario: Taquilla solo lectura

- GIVEN un usuario rol `taquilla`
- WHEN intenta crear, editar o borrar usuarios o entidades
- THEN se deniega (403) y solo puede leer su propio usuario

### Requirement: Prohibición de pares y superiores

`authorizeEntityBinding` MUST impedir que un rol cree o gestione entidades de su mismo nivel (par) o superior: `banca` MUST NOT crear banca, `grupo` MUST NOT crear grupo, `agencia` MUST NOT crear agencia, y ningún rol inferior MUST NOT asignar roles superiores.

#### Scenario: Banca crea banca

- GIVEN un usuario rol `banca`
- WHEN intenta crear un usuario o entidad de rol `banca`
- THEN se deniega (403)

#### Scenario: Grupo crea grupo

- GIVEN un usuario rol `grupo`
- WHEN intenta crear un usuario o entidad de rol `grupo`
- THEN se deniega (403)

#### Scenario: Agencia crea agencia

- GIVEN un usuario rol `agencia`
- WHEN intenta crear un usuario o entidad de rol `agencia`
- THEN se deniega (403)

### Requirement: users.created_by (metadata de fila)

El sistema MUST añadir `users.created_by` (FK nullable a `users`), exponer `User::creator()` y `User::createdUsers()`, y poblarlo en `UserController::store`, `BancaController::store`, `GrupoController::store`, `TaquillaController::store` y seeders. Los usuarios existentes sin creador MUST quedar con `created_by = null`. `created_by` es metadata/policy de fila, no una capability.

#### Scenario: Usuario creado registra su creador

- GIVEN un rol autorizado que crea un usuario
- WHEN persiste el usuario
- THEN `created_by` referencia al usuario autenticado

#### Scenario: Backfill de usuarios existentes

- GIVEN usuarios existentes sin creador
- WHEN se aplica la migración/backfill
- THEN `created_by` queda `null` (no se inventa un creador)

### Requirement: Super_master intocable y no borra otro super_master

Ningún rol inferior MUST poder editar, borrar o cambiar la contraseña de un `super_master`; la operación MUST denegarse (403) antes de cualquier cambio. Un `super_master` MUST NOT borrar a otro `super_master`.

#### Scenario: Master edita contraseña de super_master

- GIVEN un usuario rol `master`
- WHEN intenta editar la contraseña de un `super_master`
- THEN se deniega (403) y la contraseña no cambia

#### Scenario: Master borra super_master

- GIVEN un usuario rol `master`
- WHEN intenta eliminar a un `super_master`
- THEN se deniega (403)

#### Scenario: Super_master borra otro super_master

- GIVEN un `super_master`
- WHEN intenta eliminar a otro `super_master`
- THEN se deniega (403)

### Requirement: Master no borra a su creador

Un `master` MUST NOT borrar a su `created_by` (su creador); la operación MUST denegarse (403).

#### Scenario: Master borra a su creador

- GIVEN un `master` cuyo `created_by` referencia a otro usuario
- WHEN intenta eliminar a ese usuario creador
- THEN se deniega (403)

### Requirement: Regresión de anulación (caso 5)

El rechazo de anulación de tickets sin líneas vivas ya está cubierto por `TicketAnulacionService` (rechaza tickets sin líneas vivas y valida policy por línea). Este cambio MUST añadir únicamente un test de regresión, sin reimplementar la lógica.

#### Scenario: Ticket sin líneas vivas rechazado

- GIVEN un ticket cuyas líneas fueron soft-deleted (sin líneas vivas)
- WHEN se intenta anular (`DELETE /tickets/{id}`)
- THEN se rechaza y no se registra devolución
