# arquitectura-alcance Specification

**Estado**: draft

## Purpose

Define el contrato **arquitectónico** del refactor de alcance jerárquico (#85), no un cambio de comportamiento funcional. Esta capability fija la invariante de no-regresión: centralizar el recorrido `taquilla → grupo → banca` y `taquilla → agencia → grupo → banca` en `App\Support\Scope\AlcanceService`, delegar las policies por modelo en él y garantizar que los endpoints migrados preserven status, body y mensajes de denegación exactos. No introduce endpoints, capacidades ni reglas de negocio nuevas; describe el contrato de refactor y la invariante de no-regresión.

## Requirements

### Requirement: Capa única de alcance

`AlcanceService` MUST ser la única implementación del recorrido jerárquico `taquilla → grupo → banca` y `taquilla → agencia → grupo → banca`. Las policies por modelo MUST delegar el scoping en `AlcanceService`, sin reimplementar la jerarquía.

#### Scenario: Capa aditiva no altera baseline

- GIVEN `AlcanceService` y las policies creadas pero aún no consumidas por controllers
- WHEN se ejecuta la suite de scope vigente
- THEN los tests de scope existentes pasan sin modificarse

### Requirement: Controllers sin lógica de filas por rol

Ningún controller de `Api/` MUST comparar roles (`hasRole`/`hasAnyRole`) para decidir qué filas ve. Un controller MAY conservar autorización de acción puntual (create/update/delete) vía policy. Las decisiones de filas (`applyTo*`, `entidadesVisibles`, `idsPorTipo`, `bancasParaComisiones`) viven en `AlcanceService`.

#### Scenario: Controller sin comparaciones de rol para filas

- GIVEN un controller migrado
- WHEN construye un listado o detalle
- THEN no evalúa roles para filtrar filas, sino que delega en `AlcanceService`/policy

### Requirement: Cero cambio observable

Los endpoints migrados MUST preservar status, body y mensajes de denegación. Los tests de scope existentes MUST pasar sin modificarse.

#### Scenario: Migración de un controller preserva contrato

- GIVEN un controller con escalera `rol → columna` y mensajes de denegación propios
- WHEN se sustituye por `AlcanceService`/policy
- THEN el SQL generado y los status/mensajes devueltos son idénticos

### Requirement: `masterBancaIds()` único

MUST quedar una sola definición de `masterBancaIds()` en `User`. Los call sites MUST resolverse vía `AlcanceService` o los helpers de `User`: ningún controller puede invocarla y, fuera de `User`, el único consumidor MUST ser `AlcanceService`. La invariante se verifica con el scan estático de `ArquitecturaAlcanceTest`; `grep -c "masterBancaIds()"` no es el criterio porque también cuenta la definición y los helpers internos de `User`.

#### Scenario: `masterBancaIds()` único

- GIVEN 14 usos iniciales (1 definición en `User` + 13 llamadas dispersas)
- WHEN cierran las oleadas de migración
- THEN queda 1 definición en `User`, 0 usos en `app/Http/Controllers` y un único archivo consumidor externo (`AlcanceService`)

### Requirement: Denegación unificada

La capa de alcance MUST usar un solo mecanismo de denegación: `AlcanceService::denegar()` (`abort(403, $mensaje)`), preservando el mensaje y el status exactos de cada endpoint. La autorización de acción puntual (create/update/delete o chequeos sobre la relación padre) MAY conservar su `abort(403)`/`json(..., 403)` local cuando el status y el body sean idénticos a los previos; esas ramas no deciden qué filas se ven.

#### Scenario: Denegación de filas con mensaje preservado

- GIVEN un endpoint con denegación manual (`json(..., 403)` o `abort(403, ...)`) en una decisión de filas
- WHEN se migra a la capa de alcance
- THEN el status y el texto del mensaje de denegación son idénticos al original

#### Scenario: Autorización de acción puntual conserva su contrato

- GIVEN un endpoint con `abort(403)` en un chequeo de acción puntual
- WHEN se migra el filtrado de filas a la capa de alcance
- THEN el chequeo local permanece con el mismo status y mensaje, sin convertirse en decisión de filas

### Requirement: Documentación

El modelo de alcance y el orden de precedencia (`taquilla → agencia → grupo → banca` y `taquilla → grupo → banca`) MUST documentarse en `docs/dev/`.

#### Scenario: Docs del modelo de alcance

- GIVEN el refactor cerrado
- WHEN un desarrollador consulta `docs/dev/`
- THEN encuentra el modelo de alcance y el orden de precedencia documentados
