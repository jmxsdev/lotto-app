# Delta for limites

> Cierra los casos 6 y 7 del issue #84 en `JuegoController`: el alcance de escritura de `banca` sobre `grupo_id`/`taquilla_id`, y el orden autorización-antes-que-validación en `updateLimites`. El resto de la capability `limites` permanece sin cambios.

## ADDED Requirements

### Requirement: Alcance de escritura de límites para banca

`JuegoController::authorizeEscrituraLimite` para el rol `banca` MUST validar, además de `banca_id`, que el `grupo_id` del payload pertenezca a su banca y que el `taquilla_id` pertenezca a ese grupo. Si el `grupo_id` o el `taquilla_id` apuntan al subárbol de otra banca, la operación MUST denegarse (403). Aplica a `PUT /api/v1/limites/{juego}` y a `POST /api/v1/limites/batch` (comparten el helper).

#### Scenario: Banca escribe en su propio grupo/taquilla

- GIVEN una banca A y un `grupo_id` de su banca con un `taquilla_id` de ese grupo
- WHEN escribe límites (`PUT /limites/{juego}` o batch)
- THEN se autoriza y responde 200

#### Scenario: Banca escribe con grupo_id de otra banca

- GIVEN una banca A y un payload cuyo `grupo_id` pertenece a la banca B
- WHEN escribe límites
- THEN 403 (no inyecta límites en el subárbol de B)

#### Scenario: Banca escribe con taquilla_id de otro grupo

- GIVEN una banca A y un payload cuyo `taquilla_id` no pertenece al `grupo_id` indicado (o pertenece a otra banca)
- WHEN escribe límites
- THEN 403

#### Scenario: Batch comparte el alcance

- GIVEN una banca A y un `batchLimites` con un ítem fuera de su subárbol
- WHEN procesa el batch
- THEN 403 para ese ítem (el helper valida el alcance por ítem)

### Requirement: Autorización antes que validación de restrictividad

`JuegoController::updateLimites` MUST ejecutar la autorización de alcance (`authorizeEscrituraLimite`) ANTES de `validarRestrictividadLimite`. Un actor fuera de alcance MUST recibir 403, nunca 422 con datos del padre ajeno; la restrictividad SHALL seguir validándose después de la autorización.

#### Scenario: Actor fuera de alcance con payload restrictivo inválido

- GIVEN un actor fuera del alcance de la banca objetivo cuyo payload además violaría la restrictividad
- WHEN escribe límites
- THEN 403 (la autorización precede a la validación de restrictividad), nunca 422

#### Scenario: Actor en alcance con restrictividad inválida

- GIVEN un actor dentro del alcance cuyo payload viola la restrictividad (hijo > padre)
- WHEN escribe límites
- THEN 422 (la restrictividad se valida después de autorizar)
