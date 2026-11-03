# Delta for comisiones

## MODIFIED Purpose

Comisión = participación (%) de un nivel (banca/grupo/taquilla) sobre sus ventas, calculada de forma **consultiva**: el sistema MUST computar y mostrar cuánto recibiría cada entidad (banca/grupo/taquilla) en un rango de fechas, sin persistir un ledger de liquidación, sin transicionar a `pagado` y sin ejecutar un flujo de pago. Banca, Grupo y Taquilla cobran su propia comisión; la suma de las tasas liquidables de la cadena MUST ser ≤ 100% de las ventas (suma cero). La tasa es `juego_limites.porcentaje_pago`; el monto es bs-equivalente desde `apuestas.total_bs_equivalent`. El snapshot de cierre `cierres_caja.comision_bs_equivalent` y `comision_defaults` permanecen.

(Previously: "liquidada como filas del ledger `comisiones` (estado `pendiente`→`pagado`)".)

## REMOVED Requirements

### Requirement: Liquidación en el ledger

(Reason: Model A hace las comisiones consultivas. Ya no existe el ledger `comisiones` como store activo: se archiva (`comisiones_archivo_2026`) y se elimina la tabla. La liquidación, la transición `pendiente`→`pagado` y la escritura retroactiva-congelada desaparecen.)

(Migration: La superficie de lectura pasa a `GET /api/v1/comisiones/consulta` (ver ADDED "Consulta de comisión por rango"); el cálculo, los reportes y el snapshot de cierre permanecen sin cambios. Las filas históricas se preservan en `comisiones_archivo_2026`.)

## MODIFIED Requirements

### Requirement: Independencia entre niveles y tope acumulado

`porcentaje_pago` SHALL ser independiente por nivel (un hijo MAY exceder el valor del padre; sin guarda hijo≤padre). Cuando la suma acumulada de porcentajes a lo largo de la cadena NO supera 100%, cada nivel SHALL conservar su propia tasa sin cambios. Cuando la suma acumulada SÍ supera 100%, MUST aplicar el tope acumulado: **siempre se respeta el porcentaje del mayor** — el porcentaje del padre tiene prioridad y la tasa liquidable del hijo se topa al remanente. La suma de las tasas liquidables de la cadena (banca + grupo + taquilla) MUST ser ≤ 100% de las ventas (suma cero). La banca no tiene ancestros con tasa: su liquidable SHALL ser su propia tasa (validada ≤ 100).

**Ejemplo oficial**: banca 10% y su única taquilla 100% ⇒ la taquilla liquida 90%.

Reglas confirmadas (2026-09-30; banca beneficiaria 2026-10-01):

1. El tope aplica **por moneda**: cada cadena de moneda se topa de forma independiente.
2. La banca cobra su propia comisión: sin ancestros con tasa, su liquidable = su tasa propia (validada ≤ 100) y recibe su monto liquidable en el cálculo y los reportes (sin persistencia). La suma de las tasas liquidables de la cadena no supera 100% (suma cero).
3. Σ ancestros = suma de las tasas **propias** configuradas de los ancestros (NULL/ausente = 0), con piso en 0; tasa liquidable del hijo = `min(tasa resuelta del hijo, max(0, 100 − Σ tasas propias de ancestros))`.
4. Cada nivel MUST aplicar además el tope por tipo de juego: `topePorTipo(type)` = animalitos 16, tripletas 25, otro/desconocido 100. La tasa liquidable SHALL ser `min(tasaEfectiva, max(0, 100 − Σ ancestros), topePorTipo(type))`; `tasaEfectiva` MUST NOT clamp. Las filas legacy se clampa en lectura, sin migración.

(Previously: regla 2 decía "recibe fila en el ledger".)

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

### Requirement: Sincronía single/bulk de cálculo

La tasa liquidable MUST ser idéntica en el path single (`tasaLiquidable`) y bulk (`tasasLiquidablesBulk`); ambos SHALL aplicar `topePorTipo`. Reportes y consulta MUST tomar el monto liquidado (post-tope).

(Previously: "Sincronía single/bulk de liquidación" — "Reportes, preview y liquidación MUST tomar el monto liquidado".)

#### Scenario: single y bulk coinciden

- GIVEN la misma (entidad, juego, moneda) con tope por tipo
- WHEN se calcula single y bulk
- THEN ambas producen la misma liquidable

#### Scenario: reportes y consulta usan liquidado

- GIVEN una fila `animalitos` clampada a 16% (config 40%)
- WHEN se genera reporte, consulta o cierre
- THEN el monto usa la tasa liquidada (16%), no la config

## ADDED Requirements

### Requirement: Consulta de comisión por rango

`GET /api/v1/comisiones/consulta?desde=YYYY-MM-DD&hasta=YYYY-MM-DD[&banca_ids[]=...]` MUST devolver, por entidad con ventas, una fila con `nivel` (banca/grupo/taquilla), `entidad_id`, `entidad` y `monto_comision` (bs-equivalente, 2 decimales). El endpoint MUST ser de solo lectura: MUST NOT persistir nada, MUST NOT exponer `estado`/`pendiente`/`pagado`, MUST NOT aceptar acciones de pago y MUST NOT devolver listas de conflictos del ledger. El rango de fechas MUST ser inclusivo y usar el mismo motor de cálculo y topes que los reportes; las entidades con base 0 (sin ventas) SHALL omitirse. Autorización: `super_master` global; `master` limitado a `masterBancaIds()`; `banca`/`grupo`/`taquilla`/`agencia` MUST NOT acceder (403).

#### Scenario: Tres niveles en una consulta

- GIVEN ventas en un rango para una cadena banca→grupo→taquilla
- WHEN un `super_master` consulta `GET /api/v1/comisiones/consulta?desde=...&hasta=...`
- THEN devuelve una fila por nivel (banca, grupo, taquilla) con `nivel`, `entidad_id`, `entidad` y `monto_comision` (2 decimales)

#### Scenario: Sin ventas en el rango

- GIVEN un rango sin ventas
- WHEN se consulta el endpoint
- THEN devuelve una lista vacía (las entidades con base 0 se omiten)

#### Scenario: Alcance del master

- GIVEN un usuario `master`
- WHEN consulta el endpoint
- THEN solo recibe filas de entidades bajo sus bancas (`masterBancaIds()`); entidades fuera de alcance no aparecen

#### Scenario: Rol no autorizado

- GIVEN un usuario `banca`/`grupo`/`taquilla`/`agencia`
- WHEN consulta `GET /api/v1/comisiones/consulta`
- THEN responde 403

#### Scenario: Sin persistencia (ledger eliminado)

- GIVEN la tabla `comisiones` archivada/eliminada (ya no es store activo)
- WHEN se consulta el endpoint
- THEN no se escribe ninguna fila en `comisiones` ni se registra acción de liquidación/pago

## Notes

`cierre-caja` (`Desglose de comisión en el cuadre/cierre`) y `reportes-agencia` (`Columna de comisión en ventasTotales`) referencian la **capability** `comisiones` ("calculado según la capability `comisiones`"), no el ledger. Como el motor de cálculo y los topes permanecen intactos, **no se requiere delta** para ninguna de las dos capabilities.
