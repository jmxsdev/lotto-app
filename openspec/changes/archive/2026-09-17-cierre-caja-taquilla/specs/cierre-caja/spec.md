# cierre-caja Specification

**Estado**: draft

## Purpose

Define el dominio del cierre de caja (diario) de la taquilla: desglose por método de pago, arqueo físico con faltante/sobrante por moneda, reporte semanal derivado de los diarios persistidos, encadenamiento de períodos, política de tasa y autorización por jerarquía. El cierre es una operación de registro/reporte que NO bloquea la venta (D3).

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

### Requirement: Reporte semanal derivado (GET /api/v1/cierre/semanal)

`GET /api/v1/cierre/semanal` MUST devolver un rollup de solo lectura de los `CierreCaja` diarios persistidos y MUST NOT persistir entidad semanal ni columna `tipo` (D1). Acepta `fecha` (ancla de la semana calendario lunes–domingo `America/Caracas`) o `fecha_desde`/`fecha_hasta`; `taquilla_id` opcional para roles administrativos. El alcance jerárquico MUST ser el mismo de `index`. La respuesta MUST incluir totales por moneda y por método, y la ventana cubierta real (rango de `fecha_fin` de los diarios incluidos).

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

### Requirement: Impresión del cierre desde la taquilla

La UI `cierre.astro` MUST permitir imprimir el resultado de un cierre usando la integración de impresión existente (`electron-pos-printer`), con montos formateados (`toLocaleString('es-VE')`).

#### Scenario: Imprimir resultado

- GIVEN un cierre mostrado en la taquilla
- WHEN el cajero solicita imprimir
- THEN se envía a la impresora el resultado con montos formateados
