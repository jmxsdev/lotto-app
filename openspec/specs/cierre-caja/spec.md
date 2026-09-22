# cierre-caja Specification

**Estado**: draft

## Purpose

Define el dominio del cierre de caja de la taquilla: desglose por método de pago, arqueo físico con faltante/sobrante por moneda, un cierre por día calendario con re-cierre idempotente autorizado por clave, reporte por rango derivado de los diarios persistidos, período abierto explícito, encadenamiento de períodos, política de tasa y autorización por jerarquía. El cierre es una operación de registro/reporte que NO bloquea la venta (D3).

## Requirements

### Requirement: Cierre diario con arqueo físico

`POST /api/v1/cierre` MUST aceptar el arqueo opcional del cajero (`arqueo_efectivo_bs`, `arqueo_efectivo_usd`, decimal nullable) y persistirlo junto al cierre. El sistema MUST calcular la diferencia por moneda `faltante_sobrante_X = arqueo_efectivo_X − total_efectivo_X`; la diferencia negativa es **faltante** y la positiva **sobrante**. El arqueo SHALL ser opcional: si se omite, la diferencia queda ausente.

#### Scenario: Cierre con arqueo conciliado

- GIVEN una taquilla con ventas y egresos en el período
- WHEN el cajero ejecuta el cierre enviando el efectivo contado (`arqueo_efectivo_bs`, `arqueo_efectivo_usd`)
- THEN el cierre persiste el contado y la diferencia por moneda
- AND la respuesta 201 incluye `total_efectivo_*`, `arqueo_efectivo_*` y `faltante_sobrante_*`

#### Scenario: Faltante

- GIVEN contado menor que el efectivo calculado
- WHEN se ejecuta el cierre
- THEN la diferencia es negativa (faltante)

#### Scenario: Sobrante

- GIVEN contado mayor que el efectivo calculado
- WHEN se ejecuta el cierre
- THEN la diferencia es positiva (sobrante)

#### Scenario: Cierre sin arqueo

- GIVEN el cajero no registra el contado
- WHEN se ejecuta el cierre
- THEN el cierre se crea sin arqueo (campos nulos) y sin diferencia

### Requirement: Desglose por método de pago

El cierre MUST desglosar `total_ventas` y `total_egresos` (y el efectivo derivado) por `metodo_pago` (`efectivo`, `transferencia`, `pago_movil`, `punto_venta`) y por moneda. El desglose MUST derivarse del `Pago.metodo_pago` capturado en origen; la suma de métodos MUST igualar el total de la moneda. El USD SHALL contabilizarse íntegro bajo `efectivo`.

#### Scenario: VES con múltiples métodos

- GIVEN pagos VES registrados con distintos `metodo_pago`
- WHEN se ejecuta el cierre
- THEN el desglose por método suma el total VES y cada método refleja su monto

#### Scenario: USD siempre efectivo

- GIVEN pagos USD
- WHEN se ejecuta el cierre
- THEN todo el USD queda bajo `efectivo`

#### Scenario: Consistencia del desglose

- GIVEN el desglose por método de una moneda
- WHEN se comparan las sumas por método con el total de esa moneda
- THEN son iguales

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

### Requirement: Reporte semanal derivado (GET /api/v1/cierre/semanal)

`GET /api/v1/cierre/semanal` MUST devolver un rollup de solo lectura de los `CierreCaja` diarios persistidos y MUST NOT persistir entidad semanal ni columna `tipo` (D1). Acepta `fecha` (ancla de la semana calendario lunes–domingo `America/Caracas`) o `fecha_desde`/`fecha_hasta`; `taquilla_id` opcional para roles administrativos. El alcance jerárquico MUST ser el mismo de `index`. La respuesta MUST incluir totales por moneda y por método, y la ventana cubierta real (rango de `fecha_fin` de los diarios incluidos). El listado `cierres[]` MUST incluir el shape completo de cada cierre (desglose por método, arqueo y faltante/sobrante) para su impresión (Q3); los totales y `ventana_cubierta` SHALL permanecer sin cambios.

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

### Requirement: Política de tasa de cambio (snapshot + fallback)

Cada cierre diario MUST conservar `exchange_rate_cierre` como snapshot de la tasa al momento del cierre. Si no hay tasa activa, el cierre MUST usar la última tasa por `reference_date` como fallback en lugar de fallar con 422 (OQ2). El reporte semanal SHALL NOT requerir tasa viva (usa snapshots persistidos). Si no existe ninguna tasa (activa ni histórica), el cierre MUST responder 422.

#### Scenario: Tasa activa disponible

- GIVEN una tasa activa
- WHEN se ejecuta el cierre
- THEN persiste `exchange_rate_cierre` con la tasa activa

#### Scenario: Fallback sin tasa activa

- GIVEN ninguna tasa activa pero tasas históricas por `reference_date`
- WHEN se ejecuta el cierre
- THEN usa la última tasa histórica y persiste el snapshot (no 422)

#### Scenario: Sin tasa alguna

- GIVEN ninguna tasa activa ni histórica
- WHEN se ejecuta el cierre
- THEN responde 422 con mensaje claro

### Requirement: Encadenamiento de períodos

El período de un cierre MUST ser `[fecha_inicio, fecha_fin)` con `fecha_fin = now()`. `fecha_inicio` MUST ser el `fecha_fin` del último cierre de la taquilla; si no existe, la `fecha_hora` de su primera apuesta; si no hay actividad, `now()`. El encadenamiento MUST impedir solapes entre períodos de una misma taquilla.

#### Scenario: Encadena desde el último cierre

- GIVEN un cierre previo en la taquilla
- WHEN se ejecuta un nuevo cierre
- THEN `fecha_inicio` iguala el `fecha_fin` del cierre previo

#### Scenario: Sin cierre previo

- GIVEN una taquilla sin cierres
- WHEN se ejecuta el primer cierre
- THEN `fecha_inicio` es la `fecha_hora` de su primera apuesta

#### Scenario: Sin actividad

- GIVEN una taquilla sin cierres ni apuestas
- WHEN se ejecuta el cierre
- THEN el período queda vacío (`fecha_inicio = fecha_fin = now()`)

### Requirement: Autorización por jerarquía

El cierre y su consulta MUST respetar los roles actuales. `taquilla` cierra SOLO su propia taquilla (si envía otra → 403); `super_master` cualquier taquilla; `master` solo sus bancas; `banca`/`grupo`/`agencia` dentro de su alcance y MUST enviar `taquilla_id` (422 si falta). Violaciones de alcance MUST responder 403. No se introduce un permission específico nuevo (OQ3).

#### Scenario: Taquilla cierra su caja

- GIVEN un usuario rol `taquilla` con `taquilla_id`
- WHEN ejecuta el cierre sin `taquilla_id`
- THEN crea el cierre de su propia taquilla (201)

#### Scenario: Taquilla intenta cerrar otra

- GIVEN un usuario rol `taquilla`
- WHEN envía `taquilla_id` de otra taquilla
- THEN recibe 403

#### Scenario: Admin sin taquilla_id

- GIVEN un rol administrativo
- WHEN ejecuta el cierre sin `taquilla_id`
- THEN recibe 422 (validación)

#### Scenario: Admin fuera de alcance

- GIVEN un rol administrativo con `taquilla_id` fuera de su jerarquía
- WHEN ejecuta el cierre
- THEN recibe 403

### Requirement: El cierre no bloquea la venta

El cierre MUST ser solo registro/reporte y MUST NOT bloquear la venta ni cambiar el estado operativo de la taquilla (D3). No existe candado de venta.

#### Scenario: Venta durante el cierre

- GIVEN un cierre ejecutado
- WHEN se registra una nueva apuesta después del cierre
- THEN la apuesta se registra con normalidad

### Requirement: Historial de cierres

`GET /api/v1/cierre` MUST listar cierres con alcance jerárquico, paginado (default 20), ordenado por `fecha_fin` descendente, incluyendo `taquilla.grupo.banca` y `creador`.

#### Scenario: Listado

- GIVEN cierres existentes dentro del alcance del rol
- WHEN se consulta `GET /api/v1/cierre`
- THEN devuelve la página con los cierres más recientes primero

### Requirement: Impresión del cierre y del reporte desde la taquilla

La UI `cierre.astro` MUST permitir imprimir el resultado de un cierre usando la integración de impresión existente (`electron-pos-printer`), con montos formateados (`toLocaleString('es-VE')`). La sección "Reportes por rangos" MUST permitir imprimir el reporte del rango (totales + listado con desglose) vía IPC `print-reporte` y `generateReporteHtml()`.

#### Scenario: Imprimir resultado

- GIVEN un cierre mostrado en la taquilla
- WHEN el cajero solicita imprimir
- THEN se envía a la impresora el resultado con montos formateados

#### Scenario: Imprimir reporte por rango

- GIVEN un reporte por rango mostrado en la taquilla
- WHEN el cajero solicita imprimir el reporte
- THEN se envía a la impresora el reporte con totales y el listado de cierres con desglose, con montos formateados
