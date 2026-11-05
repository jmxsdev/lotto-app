# Delta for comisiones

## MODIFIED Requirements

### Requirement: Independencia entre niveles y tope acumulado

`porcentaje_pago` SHALL ser independiente por nivel (un hijo MAY exceder el valor del padre; sin guarda
hijo≤padre). Cuando la suma acumulada de porcentajes a lo largo de la cadena NO supera 100%, cada nivel
SHALL conservar su propia tasa sin cambios. Cuando la suma acumulada SÍ supera 100%, MUST aplicar el
tope acumulado: **siempre se respeta el porcentaje del mayor** — el porcentaje del padre tiene prioridad
y la tasa liquidable del hijo se topa al remanente. La suma de las tasas liquidables de la cadena
(banca + grupo + taquilla) MUST ser ≤ 100% de las ventas (suma cero). La banca no tiene ancestros con
tasa: su liquidable SHALL ser su propia tasa (validada ≤ 100).

**Ejemplo oficial**: banca 10% y su única taquilla 100% ⇒ la taquilla liquida 90%.

Reglas confirmadas (2026-09-30; banca beneficiaria 2026-10-01):

1. El tope aplica **por moneda**: cada cadena de moneda se topa de forma independiente.
2. La banca cobra su propia comisión: sin ancestros con tasa, su liquidable = su tasa propia (validada
   ≤ 100) y recibe su monto liquidable en el cálculo y los reportes (sin persistencia). La suma de las
   tasas liquidables de la cadena no supera 100% (suma cero).
3. Σ ancestros = suma de las tasas **propias** configuradas de los ancestros (NULL/ausente = 0), con piso
   en 0; tasa liquidable del hijo = `min(tasa resuelta del hijo, max(0, 100 − Σ tasas propias de ancestros))`.
4. Cada nivel MUST aplicar además el tope por tipo de juego: `topePorTipo(type)` = animalitos 16,
   tripletas 25, otro/desconocido 100. La tasa liquidable SHALL ser
   `min(tasaEfectiva, max(0, 100 − Σ ancestros), topePorTipo(type))`; `tasaEfectiva` MUST NOT clamp. Las
   filas legacy se clampa en lectura, sin migración. La jugada de terminal se vende bajo un juego
   `tripletas`, por lo que su tope es **25%** (no existe el tipo `terminales`).

(Previously: el escenario "otros tipos sin tope" ejemplificaba el tope 100% con el tipo `terminales`;
terminal ahora es modalidad de `tripletas`, con tope 25%.)

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

#### Scenario: Terminal bajo tripletas topa a 25%

- GIVEN una jugada de terminal vendida bajo un juego `tripletas` con config 40%
- WHEN se resuelve la tasa liquidable
- THEN liquida 25% (tope por tipo `tripletas`)

#### Scenario: otros tipos sin tope

- GIVEN un tipo de juego desconocido (p. ej. `bingo`) con config hasta 100%
- WHEN se resuelve la liquidable
- THEN liquida su tasa efectiva (sin tope por tipo)

#### Scenario: tope por tipo a nivel independiente

- GIVEN un `animalitos` con banca 16%, grupo 16% y taquilla 16%
- WHEN se resuelven las liquidables
- THEN cada nivel liquida 16% (cadena 48%)
