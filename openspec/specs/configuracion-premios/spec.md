# configuracion-premios Specification

**Estado**: draft

## Purpose

Permite editar los premios de un juego desde el panel (`PUT /api/v1/juegos/{juego}/premios`) con
reemplazo atómico de `config.premios`, merge seguro sobre `config`, sincronización de espejos legacy y
auditoría. Incluye snapshot de premios por apuesta (sin retroactividad), fix del toggle del juego y
re-export del catálogo.

## Nota de ubicación de requisitos

Los requisitos de toggle y de export del catálogo viven en esta capability (`configuracion-premios`), no
en `catalogo-juegos` ni `motor-premios`.

## Requirements

### Requirement: Edición atómica de premios con merge seguro

`PUT /api/v1/juegos/{juego}/premios` SHALL reemplazar `config.premios` de forma atómica: el body ES el
objeto `premios` completo (`base`, `modalidades`, `comodines`) y el sistema SHALL hacer merge de alto
nivel sobre `config` (solo toca `premios` y sus espejos), preservando el resto de claves (`scraper`,
`modalidades_permitidas`, etc.). El endpoint SHALL estar limitado a los roles `super_master|master`.

#### Scenario: Edición completa de premios

- GIVEN un juego con `config` que incluye `scraper` y `modalidades_permitidas`
- WHEN un `master` hace `PUT /juegos/{juego}/premios` con `{base, modalidades, comodines}` completo
- THEN `config.premios` se reemplaza con el body y `scraper`/`modalidades_permitidas` se preservan

#### Scenario: Reemplazo atómico (no merge por subclave)

- GIVEN un body que solo envía `base`
- WHEN se procesa
- THEN el resultado reemplaza el objeto `premios` completo, sin mezclar subclaves con las anteriores

#### Scenario: Rol sin permiso

- GIVEN un usuario con rol distinto de `super_master|master`
- WHEN intenta `PUT /juegos/{juego}/premios`
- THEN recibe 403

### Requirement: Validación del payload de premios

El endpoint SHALL validar el esquema: `premios` `required|array`; `base` `required|integer|min:1`;
`modalidades` array (vacío permitido) con claves `string` y valores `integer|min:1`; `comodines` array
(vacío permitido) con `tipo in:flag,letra,numero,palabra`, `premio_multiplo|integer|min:1` y `acumulativo`
solo válido con `tipo=palabra`. Las claves de modalidad SHALL ser válidas en `plugin->obtenerModalidades()`
∪ catálogo oficial (`PremiosOficiales`), sin bloquear claves canónicas que el plugin no liste.
`la-ricachona` (sin `base` oficial) MUST responder 422. Payloads inválidos MUST responder 422.

#### Scenario: base no entero

- GIVEN un body con `base` no entero o menor que 1
- WHEN se envía
- THEN responde 422

#### Scenario: modalidad con clave inválida

- GIVEN una modalidad cuya clave no existe en plugin ∪ catálogo oficial
- WHEN se envía
- THEN responde 422

#### Scenario: modalidad con valor inválido

- GIVEN una modalidad con valor no entero o menor que 1
- WHEN se envía
- THEN responde 422

#### Scenario: comodín con tipo inválido

- GIVEN un comodín con `tipo` fuera de `flag|letra|numero|palabra`
- WHEN se envía
- THEN responde 422

#### Scenario: acumulativo sin palabra

- GIVEN un comodín con `acumulativo=true` y `tipo != palabra`
- WHEN se envía
- THEN responde 422

#### Scenario: clave canónica no listada por el plugin

- GIVEN una modalidad con clave válida en el catálogo oficial que el plugin no lista hoy
- WHEN se envía
- THEN se acepta (200)

#### Scenario: la-ricachona sin base oficial

- GIVEN `la-ricachona` (sin `base` oficial)
- WHEN se intenta editar sus premios
- THEN responde 422 con mensaje claro

### Requirement: Sincronización de espejos legacy

Tras editar, el sistema SHALL sincronizar los espejos legacy: `premio_multiplo` = `base`;
`config.modalidades` = espejo legacy (mapa `ESPEJO_MODALIDADES` + `ESPEJO_EXTRA`); `config.comodines` =
`premios.comodines`. La sincronización SHALL reutilizar la lógica de `PremiosOficiales::configPara()`.

#### Scenario: premio_multiplo igual a base

- GIVEN una edición con `base=50`
- WHEN se guarda
- THEN `config.premio_multiplo` es 50

#### Scenario: modalidades espejo sincronizadas

- GIVEN una edición con `modalidades` nuevas
- WHEN se guarda
- THEN `config.modalidades` refleja el espejo legacy correspondiente

### Requirement: Auditoría de edición de premios

Cada edición SHALL registrar una fila en `JuegoAuditoria` con `accion=premios`, `cambios.before`
(premios previos) y `cambios.after` (premios nuevos), más `updated_by`.

#### Scenario: before/after registrados

- GIVEN una edición de premios exitosa
- WHEN se guarda
- THEN se crea auditoría `accion=premios` con `before` y `after`

### Requirement: Reflejo en las reglas del juego

`GET /juegos/{id}/reglas` SHALL reflejar los premios nuevos tras la edición (vía el motor `reglas`).

#### Scenario: reglas refleja los nuevos premios

- GIVEN una edición de premios persistida
- WHEN se consulta `GET /juegos/{id}/reglas`
- THEN el campo `premios` refleja los valores nuevos

### Requirement: Snapshot de premios por apuesta (sin retroactividad)

Al vender una apuesta, el sistema SHALL persistir un snapshot de `config.premios` vigente en
`detalle_apuestas`. La liquidación (`verificarGanadores`) y el pago SHALL resolver el premio contra ese
snapshot cuando exista; una edición posterior de premios MUST NOT alterar el premio de apuestas ya
vendidas. Para apuestas sin snapshot (vendidas antes de la feature), el sistema SHALL usar el
`config.premios` actual como fallback legacy.

#### Scenario: Snapshot persistido al vender

- GIVEN una venta de apuesta con `config.premios` vigente
- WHEN se crea la apuesta
- THEN se persiste el snapshot de `premios` en el detalle

#### Scenario: Edición posterior no altera apuestas vendidas

- GIVEN una apuesta vendida con snapshot (base 50×) y una edición posterior que cambia base a 60×
- WHEN se liquida la apuesta
- THEN el premio se calcula con 50× (snapshot), no 60×

#### Scenario: Pago usa el snapshot

- GIVEN una apuesta ganadora con snapshot
- WHEN el cajero paga
- THEN el monto validado coincide con el snapshot (no con el config actual)

#### Scenario: Fallback legacy sin snapshot

- GIVEN una apuesta vendida antes de la feature (sin snapshot)
- WHEN se liquida o paga
- THEN se usa el `config.premios` actual

### Requirement: Toggle del juego

El panel SHALL enviar `{active: bool}` en `PATCH /juegos/{id}/toggle`; el backend SHALL validar
`active|boolean` (422 si falta) y persistir + auditar. El panel SHALL mostrar un error visible ante fallo
(sin `catch` silencioso). `vendible` SHALL seguir siendo espejo derivado de `active` (sin columna ni
semántica nueva).

#### Scenario: Toggle envía active y persiste

- GIVEN el panel con un juego
- WHEN el usuario togglea
- THEN se envía `PATCH /juegos/{id}/toggle` con `{active: bool}` y el estado persiste

#### Scenario: Error de toggle visible

- GIVEN un fallo en el toggle (p. ej. body ausente)
- WHEN ocurre
- THEN el panel muestra un mensaje de error visible

#### Scenario: vendible espejo de active

- GIVEN un juego con `active` cambiado
- WHEN se exporta
- THEN `vendible` es igual a `active`

### Requirement: Export del catálogo tras edición

Tras editar premios, el sistema SHALL re-exportar `docs/juegos.json` para que refleje los valores nuevos
(base, modalidades, comodines, espejos). Se SHALL registrar una nota de coordinación para actualizar la
copia bundled de la taquilla (`taquilla/src/data/juegos.json`), sin cambios de código en `taquilla/`.

#### Scenario: docs/juegos.json refleja los nuevos premios

- GIVEN una edición de premios
- WHEN se re-exporta el catálogo
- THEN `docs/juegos.json` refleja base/modalidades/comodines nuevos

#### Scenario: Nota de coordinación de la taquilla

- GIVEN una edición de premios re-exportada
- WHEN se documenta
- THEN queda nota de coordinación para re-copiar la copia bundled de la taquilla (sin tocar su código)
