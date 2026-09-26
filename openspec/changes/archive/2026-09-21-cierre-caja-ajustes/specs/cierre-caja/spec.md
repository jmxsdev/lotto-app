# Delta for cierre-caja

**Estado**: draft

## Purpose

Ajustes al cierre de caja: un solo cierre por día calendario con re-cierre idempotente que actualiza la fila del día (con clave), reporte por rango con listado completo (`cierres[]` con desglose), período abierto explícito, indicador `cierre_hoy` en el preview, y sección "Reportes por rangos" con impresión en la taquilla.

## ADDED Requirements

### Requirement: Un cierre por día calendario (re-cierre idempotente con clave)

El sistema MUST permitir a lo sumo UN cierre por día calendario `America/Caracas` por taquilla. El primer cierre del día MUST crear la fila (201, `reclosed:false`) y SHALL ser libre de clave. Un cierre posterior del mismo día MUST actualizar la fila existente (200, `reclosed:true`) en lugar de crear otra: extiende `fecha_fin` a `now()`, recalcula totales, desglose y faltante/sobrante desde el `fecha_inicio` original, y registra `reclosed_by`/`reclosed_at` (la clave MUST NOT guardarse). El re-cierre MUST exigir `clave_cierre` válida contra la cadena de ESA taquilla (capability `clave-cierre`); su ausencia o invalidez MUST responder 422. La detección del día MUST usar el rango `[startOfDay, +1d)` sobre `fecha_fin` en `America/Caracas` (no `whereDate`). El re-cierre MUST ser idempotente: repetir con clave válida re-actualiza `fecha_fin` a `now()` sin crear fila adicional. La autorización MUST seguir la jerarquía existente (403 fuera de alcance).

#### Scenario: Primer cierre del día crea

- GIVEN una taquilla sin cierre en el día calendario `America/Caracas`
- WHEN el cajero ejecuta el cierre sin `clave_cierre`
- THEN crea la fila con 201 y `reclosed:false`

#### Scenario: Segundo cierre del mismo día actualiza

- GIVEN un cierre ya registrado hoy
- WHEN se ejecuta otro cierre con `clave_cierre` válida
- THEN se actualiza la fila existente (200, `reclosed:true`)
- AND `fecha_fin` se extiende a `now()` y totales/desglose/faltante-sobrante se recalculan desde el `fecha_inicio` original

#### Scenario: Re-cierre idempotente

- GIVEN la fila del día ya re-cerrada
- WHEN se repite el re-cierre con la misma clave válida
- THEN se re-actualiza `fecha_fin` a `now()` y sigue habiendo una sola fila

#### Scenario: Re-cierre sin clave

- GIVEN un cierre ya registrado hoy
- WHEN se ejecuta otro cierre sin `clave_cierre`
- THEN responde 422

#### Scenario: Re-cierre con clave incorrecta

- GIVEN un cierre ya registrado hoy
- WHEN se ejecuta otro cierre con una clave que no coincide con la cadena
- THEN responde 422

#### Scenario: Sin candidato con clave configurada

- GIVEN un cierre ya registrado hoy y ningún usuario de la cadena con clave configurada
- WHEN se ejecuta otro cierre con `clave_cierre`
- THEN responde 422 (bloqueo del re-cierre)

#### Scenario: Límite de día

- GIVEN el último cierre fue ayer y `now()` es después de medianoche `America/Caracas`
- WHEN se ejecuta un cierre
- THEN crea una fila nueva (201), no un re-cierre

#### Scenario: Datos existentes con múltiples cierres el mismo día

- GIVEN filas demo con varios cierres el mismo día
- WHEN se detecta el cierre de hoy o se actualiza
- THEN se toma el último por `fecha_fin` y la actualización conserva su `fecha_inicio` original

### Requirement: Preview del período con indicador del cierre de hoy (`cierre_hoy`)

`GET /api/v1/cierre/actual` MUST exponer el campo aditivo `cierre_hoy`: `null` si no existe cierre para el día calendario `America/Caracas` de la taquilla, o el cierre del día (id y fechas) si existe. La UI MUST usar este campo para decidir el copy del confirm y solicitar la clave en re-cierre. El resto del preview SHALL permanecer igual (read-only, sin persistir).

#### Scenario: Sin cierre hoy

- GIVEN una taquilla sin cierre en el día
- WHEN se consulta `GET /api/v1/cierre/actual`
- THEN `cierre_hoy` es `null` y el confirm no pide clave

#### Scenario: Con cierre hoy

- GIVEN una taquilla con cierre registrado hoy
- WHEN se consulta `GET /api/v1/cierre/actual`
- THEN `cierre_hoy` trae el cierre del día y la UI muestra "Cierre del día X ya registrado" pidiendo clave

### Requirement: Período abierto explícito en la taquilla

La card "Resumen del período actual" de `cierre.astro` MUST comunicar que el período está ABIERTO y mostrar el rango visible (desde el último cierre → ahora), etiquetado como abierto, en lugar de parecer una ventana fija.

#### Scenario: Rango visible como período abierto

- GIVEN la card "Resumen del período actual"
- WHEN se renderiza
- THEN muestra el período abierto con rango visible (último cierre → ahora)

### Requirement: Reportes por rangos en la taquilla

La sección "Reporte semanal" MUST renombrarse a "Reportes por rangos" y ofrecer DOS selectores de fecha (`fecha_desde`/`fecha_hasta`) para consultar rangos arbitrarios (día/semana/custom) contra `GET /api/v1/cierre/semanal`, listar los cierres del rango con totales y desglose, y permitir imprimir el reporte. El endpoint del backend SHALL permanecer `GET /api/v1/cierre/semanal` (sin alias).

#### Scenario: Consulta de rango con dos fechas

- GIVEN la sección "Reportes por rangos"
- WHEN el cajero elige `fecha_desde` y `fecha_hasta`
- THEN consulta `GET /api/v1/cierre/semanal` con ambos parámetros

#### Scenario: Listado de cierres del rango

- GIVEN cierres persistidos dentro del rango elegido
- WHEN se carga el reporte
- THEN lista los cierres del rango con totales y desglose

#### Scenario: Impresión del reporte del rango

- GIVEN un reporte por rango cargado
- WHEN el cajero solicita imprimir
- THEN se envía el reporte del rango a la impresora

## MODIFIED Requirements

### Requirement: Reporte semanal derivado (GET /api/v1/cierre/semanal)

`GET /api/v1/cierre/semanal` MUST devolver un rollup de solo lectura de los `CierreCaja` diarios persistidos y MUST NOT persistir entidad semanal ni columna `tipo` (D1). Acepta `fecha` (ancla de la semana calendario lunes–domingo `America/Caracas`) o `fecha_desde`/`fecha_hasta`; `taquilla_id` opcional para roles administrativos. El alcance jerárquico MUST ser el mismo de `index`. La respuesta MUST incluir totales por moneda y por método, y la ventana cubierta real (rango de `fecha_fin` de los diarios incluidos). El listado `cierres[]` MUST incluir el shape completo de cada cierre (desglose por método, arqueo y faltante/sobrante) para su impresión (Q3); los totales y `ventana_cubierta` SHALL permanecer sin cambios.

(Previously: `cierres[]` solo exponía `id`, `taquilla_id`, `fecha_inicio`, `fecha_fin`, `total_ventas_bs` y `total_efectivo_bs`.)

#### Scenario: Rollup de semana completa

- GIVEN diarios persistidos cuyos `fecha_fin` caen en la semana
- WHEN se consulta `/api/v1/cierre/semanal?fecha=...`
- THEN devuelve totales agregados por moneda y por método y la ventana cubierta

#### Scenario: Semana sin cierres diarios

- GIVEN una semana sin diarios persistidos
- WHEN se consulta el semanal
- THEN devuelve rollup vacío (totales cero) con ventana cubierta vacía

#### Scenario: Semana incompleta

- GIVEN solo algunos días de la semana con diario
- WHEN se consulta el semanal
- THEN agrega solo los diarios existentes y expone las fechas reales incluidas (ventana cubierta)

#### Scenario: Alcance jerárquico

- GIVEN un rol `taquilla`
- WHEN consulta el semanal
- THEN solo ve su propia taquilla; un rol administrativo fuera de alcance recibe 403

#### Scenario: Cierres del rango con shape completo

- GIVEN diarios persistidos dentro del rango consultado
- WHEN se consulta `/api/v1/cierre/semanal?fecha_desde=...&fecha_hasta=...`
- THEN `cierres[]` incluye por cada cierre el desglose por método, arqueo y faltante/sobrante
- AND los totales agregados y `ventana_cubierta` permanecen iguales

### Requirement: Impresión del cierre y del reporte desde la taquilla

La UI `cierre.astro` MUST permitir imprimir el resultado de un cierre usando la integración de impresión existente (`electron-pos-printer`), con montos formateados (`toLocaleString('es-VE')`). La sección "Reportes por rangos" MUST permitir imprimir el reporte del rango (totales + listado con desglose) vía IPC `print-reporte` y `generateReporteHtml()`.

(Previously: solo se imprimía el resultado de un cierre individual.)

#### Scenario: Imprimir resultado

- GIVEN un cierre mostrado en la taquilla
- WHEN el cajero solicita imprimir
- THEN se envía a la impresora el resultado con montos formateados

#### Scenario: Imprimir reporte por rango

- GIVEN un reporte por rango mostrado en la taquilla
- WHEN el cajero solicita imprimir el reporte
- THEN se envía a la impresora el reporte con totales y el listado de cierres con desglose, con montos formateados
