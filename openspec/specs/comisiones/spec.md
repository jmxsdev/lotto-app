# comisiones Specification

## Purpose

Comisión = participación (%) de un nivel (banca/grupo/taquilla) sobre sus ventas, liquidada como filas del ledger `comisiones` (estado `pendiente`→`pagado`). Banca, Grupo y Taquilla cobran su propia comisión; la suma de las tasas liquidables de la cadena MUST ser ≤ 100% de las ventas (suma cero). La tasa es `juego_limites.porcentaje_pago`; el monto es bs-equivalente desde `apuestas.total_bs_equivalent`.

## Requirements

### Requirement: Resolución de tasa efectiva

La tasa efectiva para (entidad, juego, moneda) MUST resolverse por cascada `taquilla → grupo → banca` sobre `juego_limites.porcentaje_pago`; si ningún nivel define valor, MUST usar el default global del sistema para esa moneda. Una fila con `porcentaje_pago` NULL o ausente SHALL tratarse como "no definida en este nivel" y ceder al siguiente. La **tasa propia** de un nivel SHALL ser su `porcentaje_pago` configurado directamente (NULL/ausente = 0). La **tasa liquidable** — usada para liquidar — SHALL ser la tasa efectiva ajustada por el tope acumulado (ver "Independencia entre niveles y tope acumulado"), calculada por (entidad, moneda).

| Prioridad | Nivel | Fallback |
|---|---|---|
| 1 | taquilla | grupo |
| 2 | grupo | banca |
| 3 | banca | default global (moneda) |

#### Scenario: Override de taquilla gana

- GIVEN filas con `porcentaje_pago` para taquilla y grupo del mismo (juego, moneda)
- WHEN se resuelve la tasa para esa taquilla
- THEN se usa el valor de taquilla

#### Scenario: NULL cede al siguiente nivel

- GIVEN la fila de taquilla tiene `porcentaje_pago` NULL y la de grupo tiene valor
- WHEN se resuelve la tasa
- THEN se usa el valor de grupo

#### Scenario: Caída al default global

- GIVEN ningún nivel define `porcentaje_pago` para (juego, moneda)
- WHEN se resuelve la tasa
- THEN se usa el default global de esa moneda

### Requirement: Superficie de configuración

El default global MUST ser una matriz de 2 filas (una por moneda bs/usd), editable solo por `super_master` en la página de límites como bloque aditivo, y SHALL aplicar a todos los juegos. Los overrides por juego/entidad SHALL usar la matriz de límites existente. Los roles de escritura de la matriz MUST ser `super_master`/`master`/`banca`/`grupo`; el `grupo` SHALL escribir solo dentro de su subárbol (su grupo y sus taquillas) y MUST recibir 403 fuera de alcance. Los endpoints nuevos MUST reutilizar el permiso `manage_comisiones` (ya asignado a `super_master` y `master`; sin cambio de seeder). La ubicación de almacenamiento del default global es decisión de diseño (no especificada aquí).

#### Scenario: Default global por moneda

- GIVEN un `super_master` en la página de límites
- WHEN edita el bloque global de comisiones (bs y usd)
- THEN persisten 2 filas de default aplicables a todos los juegos

#### Scenario: Override por juego/entidad

- GIVEN un rol con escritura (`super_master`/`master`/`banca`/`grupo`)
- WHEN define `porcentaje_pago` en la matriz para un juego/entidad dentro de su alcance
- THEN ese override pisa el default global solo para esa combinación

#### Scenario: Grupo escribe la matriz de sus taquillas

- GIVEN un usuario `grupo` y una taquilla de su grupo
- WHEN define `porcentaje_pago`/mín/máx para esa taquilla
- THEN la fila persiste (sin 403)

#### Scenario: Grupo fuera de su alcance

- GIVEN un usuario `grupo` y una taquilla de otro grupo (o el nivel banca)
- WHEN intenta escribir esa fila
- THEN responde 403

#### Scenario: Permiso de comisiones

- GIVEN un endpoint de escritura de comisiones
- WHEN lo invoca un rol sin `manage_comisiones`
- THEN responde 403

### Requirement: Cálculo de comisión

La comisión MUST ser `SUM(apuestas.total_bs_equivalent)` de las ventas NO anuladas en el rango, multiplicada por la **tasa liquidable** (post-tope), redondeada a 2 decimales. El bs-equivalente MUST usar el snapshot `exchange_rate_applied` por venta. El rango de fechas MUST ser genérico con inicio y fin inclusivos.

#### Scenario: Comisión de un período

- GIVEN ventas no anuladas con `total_bs_equivalent` en el rango [desde, hasta] inclusive
- WHEN se calcula la comisión con una tasa liquidable
- THEN el resultado es `round(SUM × tasa, 2)`

#### Scenario: Excluye anuladas

- GIVEN ventas anuladas dentro del rango
- WHEN se calcula la comisión
- THEN las anuladas no contribuyen a la suma

#### Scenario: Rango inclusivo

- GIVEN una venta exactamente en `fecha_inicio` y otra en `fecha_fin`
- WHEN se calcula el rango [fecha_inicio, fecha_fin]
- THEN ambas ventas se incluyen

#### Scenario: Rango vacío

- GIVEN un rango sin ventas
- WHEN se calcula la comisión
- THEN el resultado es 0.00

### Requirement: Liquidación en el ledger

La liquidación MUST escribir filas en `comisiones` para `Banca`, `Grupo` y `Taquilla` (todos los niveles cobran su propia comisión), con `monto_comision decimal(12,2)` en bs-equivalente y `estado` `pendiente`→`pagado`. La fila de cada nivel MUST quedar identificada por su columna de entidad (`banca_id`, `grupo_id` o `taquilla_id`) con las demás en NULL. Al liquidar, el monto MUST quedar congelado (no retroactivo): ediciones posteriores de `porcentaje_pago` MUST NOT alterar filas ya liquidadas. Rangos que se solapan MUST NOT contar la misma venta dos veces (el solapamiento se evalúa sobre los tres niveles).

#### Scenario: Filas para Banca, Grupo y Taquilla

- GIVEN una liquidación sobre un rango
- WHEN se ejecuta
- THEN crea filas `comisiones` para Banca, Grupo y Taquilla (una por entidad con ventas)

#### Scenario: Transición pendiente→pagado

- GIVEN una fila de comisión `pendiente`
- WHEN se marca como pagada
- THEN `estado` pasa a `pagado`

#### Scenario: Congelado al liquidar

- GIVEN una fila ya liquidada
- WHEN se edita `porcentaje_pago` después
- THEN el `monto_comision` de la fila no cambia

#### Scenario: Sin doble conteo

- GIVEN dos liquidaciones con rangos solapados
- WHEN se calculan
- THEN una venta no se cuenta dos veces

### Requirement: Independencia entre niveles y tope acumulado

`porcentaje_pago` SHALL ser independiente por nivel (un hijo MAY exceder el valor del padre; sin guarda hijo≤padre). Cuando la suma acumulada de porcentajes a lo largo de la cadena NO supera 100%, cada nivel SHALL conservar su propia tasa sin cambios. Cuando la suma acumulada SÍ supera 100%, MUST aplicar el tope acumulado: **siempre se respeta el porcentaje del mayor** — el porcentaje del padre tiene prioridad y la tasa liquidable del hijo se topa al remanente. La suma de las tasas liquidables de la cadena (banca + grupo + taquilla) MUST ser ≤ 100% de las ventas (suma cero). La banca no tiene ancestros con tasa: su liquidable SHALL ser su propia tasa (validada ≤ 100).

**Ejemplo oficial**: banca 10% y su única taquilla 100% ⇒ la taquilla liquida 90%.

Reglas confirmadas (2026-09-30; banca beneficiaria 2026-10-01):

1. El tope aplica **por moneda**: cada cadena de moneda se topa de forma independiente.
2. La banca cobra su propia comisión: sin ancestros con tasa, su liquidable = su tasa propia (validada ≤ 100) y recibe fila en el ledger. La suma de las tasas liquidables de la cadena no supera 100% (suma cero).
3. Σ ancestros = suma de las tasas **propias** configuradas de los ancestros (NULL/ausente = 0), con piso en 0; tasa liquidable del hijo = `min(tasa resuelta del hijo, max(0, 100 − Σ tasas propias de ancestros))`.
4. Cada nivel MUST aplicar además el tope por tipo de juego: `topePorTipo(type)` = animalitos 16, tripletas 25, otro/desconocido 100. La tasa liquidable SHALL ser `min(tasaEfectiva, max(0, 100 − Σ ancestros), topePorTipo(type))`; `tasaEfectiva` MUST NOT clamp. Las filas legacy se clampa en lectura, sin migración.

#### Scenario: Hijo excede al padre con tope

- GIVEN una banca con 10% y su única taquilla con 100% (misma moneda)
- WHEN se resuelve la tasa liquidable de la taquilla
- THEN la taquilla liquida 90%

#### Scenario: Banca sin ancestros cobra su propia tasa

- GIVEN una banca con 10% propia y ventas de su subárbol
- WHEN se resuelve la tasa liquidable de la banca
- THEN liquida 10% (sin tope de ancestros)

#### Scenario: Suma cero con tres niveles

- GIVEN banca 10%, grupo 20% y taquilla 100% (misma moneda)
- WHEN se resuelven las tasas liquidables de la cadena
- THEN banca 10%, grupo 20% y taquilla 70% (suma 100%)

#### Scenario: Suma acumulada ≤ 100 conserva tasas

- GIVEN banca 20% y taquilla 40% (suma 60% ≤ 100%)
- WHEN se resuelve la tasa liquidable
- THEN la taquilla conserva 40% (sin tope ni error de restrictividad)

#### Scenario: Tope independiente por moneda

- GIVEN la misma cadena con bs y usd cuyas sumas acumuladas difieren
- WHEN se resuelve la tasa liquidable por moneda
- THEN cada moneda se topa de forma independiente

#### Scenario: NULL/ausente y herencia

- GIVEN banca propia 60%, grupo NULL y taquilla NULL (misma moneda)
- WHEN se resuelve la tasa de la taquilla
- THEN la taquilla resuelve 60% (cascada) y liquida 40%

#### Scenario: animalitos legacy se clampa

- GIVEN un `animalitos` con config legacy 40%
- WHEN se resuelve la liquidable
- THEN liquida 16% (tope por tipo) y la fila queda intacta

#### Scenario: tripletas al tope

- GIVEN un `tripletas` con config 25%
- WHEN se resuelve la liquidable
- THEN liquida 25%
- AND con config 30% liquida 25% (clamp)

#### Scenario: otros tipos sin tope

- GIVEN un `terminales` con config hasta 100%
- WHEN se resuelve la liquidable
- THEN liquida su tasa efectiva (sin tope por tipo)

#### Scenario: tope por tipo a nivel independiente

- GIVEN un `animalitos` con banca 16%, grupo 16% y taquilla 16%
- WHEN se resuelven las liquidables
- THEN cada nivel liquida 16% (cadena 48%)

### Requirement: Validación de tope por tipo en configuración

El default global MUST ser ≤ 16. `juego_limites.porcentaje_pago` por juego MUST ser ≤ `topePorTipo(juego.type)`. Escribir por encima SHALL responder 422 en las cuatro superficies: límites por juego, batch/scoped, stores de entidad y default global.

#### Scenario: default > 16

- GIVEN un default global de 17
- WHEN se guarda
- THEN responde 422

#### Scenario: límite > tope

- GIVEN un `animalitos` con 17 (o `tripletas` con 26)
- WHEN se guarda el límite
- THEN responde 422

#### Scenario: límite ≤ tope

- GIVEN un `animalitos` con 16 (o `tripletas` con 25)
- WHEN se guarda el límite
- THEN responde 200/201

### Requirement: Sincronía single/bulk de liquidación

La tasa liquidable MUST ser idéntica en el path single (`tasaLiquidable`) y bulk (`tasasLiquidablesBulk`); ambos SHALL aplicar `topePorTipo`. Reportes, preview y liquidación MUST tomar el monto liquidado (post-tope).

#### Scenario: single y bulk coinciden

- GIVEN la misma (entidad, juego, moneda) con tope por tipo
- WHEN se calcula single y bulk
- THEN ambas producen la misma liquidable

#### Scenario: reportes usan liquidado

- GIVEN una fila `animalitos` clampada a 16% (config 40%)
- WHEN se genera reporte o cierre
- THEN el monto usa la tasa liquidada (16%), no la config

### Requirement: Exposición de type en payloads de juegos

Los payloads de juegos que consume el panel MUST exponer `juego.type`.

#### Scenario: payload incluye type

- GIVEN un listado o consulta de juegos
- WHEN el panel pide los juegos
- THEN cada juego incluye su `type`

### Requirement: Seguridad de regresión

`limite_minimo`/`limite_maximo` MUST seguir funcionando sin cambios; la comisión MUST NOT alterar `participacion`, `fraccion` ni `limite_tiempo`; MUST NOT cambiar el cálculo de premios/pagos.

#### Scenario: Límites de venta intactos

- GIVEN una venta validada por `limite_minimo`/`limite_maximo`
- WHEN se introduce el lector de comisión
- THEN la validación de límites permanece idéntica

#### Scenario: Premios sin cambios

- GIVEN el cálculo de premios existente
- WHEN se añade comisión
- THEN el premio calculado no cambia
