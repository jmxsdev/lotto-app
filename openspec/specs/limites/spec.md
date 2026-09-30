# limites Specification

**Estado**: draft

## Purpose

Cierra los gaps de configuración de límites de juego (`juego × moneda`) en la jerarquía banca → grupo → taquilla. Retira los campos dormidos `fraccion` y `limite_tiempo` de API y UI (BD intacta, sin migración), alinea los roles de escritura UI↔API (`super_master|master|banca` escriben; `grupo`/`agencia` solo lectura), añade la acción "Limpiar" por fila (DELETE), elimina `agencia_id` del endpoint legacy `GET /limites/{juego}`, borra código muerto y fija la nav. NO toca `porcentaje_pago`/`participacion` (ciclo COMISIONES), BD/migraciones, zona premios ni `taquilla/*`.

## Requirements

### Requirement: API sin campos dormidos

La serialización (`serializarLimite`, `valoresPresentes`), los validadores (PUT, batch, `JuegoLimiteService::validarItems`), `only([...])` y `JuegoLimiteService::CAMPOS` MUST NOT exponer ni aceptar `fraccion` ni `limite_tiempo`. Un payload que los envíe MUST ser ignorado sin error (200). El guard existente de "ítem sin campos configurables" (422, `JuegoLimiteService.php:125-127`) SHALL permanecer: un ítem que, tras ignorar los campos dormidos, quede sin ningún campo configurable, MUST seguir rechazándose.

#### Scenario: Serialización sin campos

- GIVEN una fila de límite persistida
- WHEN se serializa (`serializarLimite`/`valoresPresentes`)
- THEN la respuesta no incluye `fraccion` ni `limite_tiempo`

#### Scenario: Payload con campos dormidos ignorado

- GIVEN un PUT o batch que incluye `fraccion` y `limite_tiempo` junto a campos vigentes
- WHEN se procesa
- THEN los campos dormidos se ignoran sin error (200) y no se persisten

### Requirement: UI sin columnas dormidas

La tabla de límites del panel (`CAMPOS` en `utils/limites.ts`) MUST NOT mostrar las columnas "Fracción" ni "T. Límite". El bug del checkbox (no persistía `false` y podía dejar un ítem vacío → 422) MUST quedar eliminado por el retiro.

#### Scenario: Tabla sin columnas dormidas

- GIVEN la tabla de límites renderizada
- WHEN se muestran las columnas
- THEN no aparecen "Fracción" ni "T. Límite"

#### Scenario: Sin 422 por ítem vacío del checkbox

- GIVEN un usuario toca solo el campo de un ítem
- WHEN guarda
- THEN el ítem no queda vacío por `fraccion` (bug eliminado)

### Requirement: Roles de escritura

La API MUST restringir PUT/DELETE/batch a `super_master|master|banca` (sin cambios). La UI MUST mostrar Guardar y Limpiar solo a esos roles; `grupo` y `agencia` quedan solo lectura. `puedeConfigurar` MUST excluir `grupo` y las páginas de detalle MUST usar `canEditLimites`.

#### Scenario: Solo lectura para grupo/agencia

- GIVEN un usuario rol `grupo` o `agencia`
- WHEN ve la tabla de límites
- THEN no ve Guardar ni Limpiar (solo lectura)

#### Scenario: Escritura para roles admin

- GIVEN un usuario `super_master`, `master` o `banca`
- WHEN ve la tabla de límites
- THEN ve Guardar y Limpiar y puede escribir

#### Scenario: API rechaza escritura de grupo/agencia

- GIVEN un PUT/DELETE/batch de un rol `grupo` o `agencia`
- WHEN se envía
- THEN responde 403

### Requirement: Nav /limites por rol

`/limites` MUST ser visible en `AdminLayout` para `super_master|master|banca` (hoy solo super_master).

#### Scenario: Nav visible para roles admin

- GIVEN un usuario `super_master`, `master` o `banca`
- WHEN ve la navegación
- THEN ve la entrada `/limites`

#### Scenario: Nav oculta para otros roles

- GIVEN un usuario `grupo` o `agencia`
- WHEN ve la navegación
- THEN no ve `/limites`

### Requirement: Limpiar/heredar por fila

En filas propias, la UI MUST mostrar "Limpiar" que llama `DELETE /api/v1/limites/{limite}`; tras borrar, la celda MUST heredar del ancestro más cercano. Las celdas heredadas (sin fila propia) MUST NOT mostrar la acción. La semántica present-fields-only y el batch null→borra-fila SHALL permanecer.

#### Scenario: Limpiar fila propia

- GIVEN una fila propia con límite
- WHEN el usuario pulsa "Limpiar"
- THEN se llama DELETE y la celda vuelve a heredar del ancestro más cercano

#### Scenario: Celda heredada sin acción

- GIVEN una celda sin fila propia (heredada)
- WHEN se renderiza
- THEN no muestra la acción "Limpiar"

### Requirement: agencia_id ignorado en GET /limites/{juego}

`GET /limites/{juego}` MUST NOT validar ni filtrar por `agencia_id`; enviarlo MUST ser ignorado (200, sin filtro, sin 500). Los usos legítimos de `agencia_id` de taquillas MUST permanecer intactos.

#### Scenario: Sin filtro por agencia_id

- GIVEN una consulta a `GET /limites/{juego}`
- WHEN se incluye `agencia_id`
- THEN responde 200 sin filtrar y sin error 500

### Requirement: Eliminación de código muerto

`panel/src/components/LimitesTable.astro` y `authorizeLimitesWrite()` MUST ser eliminados.

#### Scenario: Código muerto retirado

- GIVEN el código del panel y el controlador
- WHEN se revisa
- THEN `LimitesTable.astro` y `authorizeLimitesWrite` no existen

### Requirement: Cobertura de tests (SHOULD)

Los tests `CrearEntidadConLimitesTest` y `LimitesScopedApiTest` SHOULD actualizarse (quitar `fraccion`); SHOULD añadirse cobertura de `agencia_id` ignorado y serialización sin los 2 campos, y SHOULD considerarse cobertura propia de `JuegoLimiteService`.

#### Scenario: Suite actualizada

- GIVEN los tests actualizados
- WHEN se ejecuta `composer test`
- THEN pasan en verde

#### Scenario: Cobertura nueva

- GIVEN los nuevos tests
- WHEN se ejecutan
- THEN cubren `agencia_id` ignorado y serialización sin campos
