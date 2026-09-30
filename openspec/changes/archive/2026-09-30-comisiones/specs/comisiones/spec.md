# comisiones Specification

## Purpose

Comisión = participación (%) de un nivel (banca/grupo/taquilla) sobre sus ventas, liquidada como filas del ledger `comisiones` (estado `pendiente`→`pagado`). La tasa es `juego_limites.porcentaje_pago`; el monto es bs-equivalente desde `apuestas.total_bs_equivalent`.

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

El default global MUST ser una matriz de 2 filas (una por moneda bs/usd), editable solo por `super_master` en la página de límites como bloque aditivo, y SHALL aplicar a todos los juegos. Los overrides por juego/entidad SHALL usar la matriz de límites existente. Los roles de escritura MUST permanecer (`super_master`/`master`/`banca`). Los endpoints nuevos MUST reutilizar el permiso `manage_comisiones` (ya asignado a `super_master` y `master`; sin cambio de seeder). La ubicación de almacenamiento del default global es decisión de diseño (no especificada aquí).

#### Scenario: Default global por moneda

- GIVEN un `super_master` en la página de límites
- WHEN edita el bloque global de comisiones (bs y usd)
- THEN persisten 2 filas de default aplicables a todos los juegos

#### Scenario: Override por juego/entidad

- GIVEN un rol con escritura (`super_master`/`master`/`banca`)
- WHEN define `porcentaje_pago` en la matriz para un juego/entidad
- THEN ese override pisa el default global solo para esa combinación

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

La liquidación MUST escribir filas en `comisiones` SOLO para `Grupo` y `Taquilla` (la `banca` queda excluida), con `monto_comision decimal(12,2)` en bs-equivalente y `estado` `pendiente`→`pagado`. Al liquidar, el monto MUST quedar congelado (no retroactivo): ediciones posteriores de `porcentaje_pago` MUST NOT alterar filas ya liquidadas. Rangos que se solapan MUST NOT contar la misma venta dos veces.

#### Scenario: Filas para Grupo y Taquilla

- GIVEN una liquidación sobre un rango
- WHEN se ejecuta
- THEN crea filas `comisiones` para Grupo y Taquilla, y NINGUNA para banca

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

`porcentaje_pago` SHALL ser independiente por nivel (un hijo MAY exceder el valor del padre; sin guarda hijo≤padre). Cuando la suma acumulada de porcentajes a lo largo de la cadena NO supera 100%, cada nivel SHALL conservar su propia tasa sin cambios. Cuando la suma acumulada SÍ supera 100%, MUST aplicar el tope acumulado: **siempre se respeta el porcentaje del mayor** — el porcentaje del padre tiene prioridad y la tasa liquidable del hijo se topa al remanente.

**Ejemplo oficial**: banca 10% y su única taquilla 100% ⇒ la taquilla liquida 90%.

ASUNCIONES a confirmar antes de `apply`:

1. El tope aplica **por moneda**: cada cadena de moneda se topa de forma independiente.
2. La banca permanece excluida de las filas del ledger (decisión existente): su tasa actúa como retención/tope sobre los pagos de sus descendientes, no como fila del ledger.
3. Σ ancestros = suma de las tasas **propias** configuradas de los ancestros (NULL/ausente = 0), con piso en 0; tasa liquidable del hijo = `min(tasa resuelta del hijo, max(0, 100 − Σ tasas propias de ancestros))`.

#### Scenario: Hijo excede al padre con tope

- GIVEN una banca con 10% y su única taquilla con 100% (misma moneda)
- WHEN se resuelve la tasa liquidable de la taquilla
- THEN la taquilla liquida 90%

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
