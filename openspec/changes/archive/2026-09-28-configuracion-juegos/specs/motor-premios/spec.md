# Delta for motor-premios

**Estado**: draft

## Purpose

La liquidación (`verificarGanadores`) y el pago (`PagoController`) pasan a resolver el premio contra el
**snapshot de `config.premios`** persistido al vender (con fallback legacy al `config.premios` actual),
en lugar de leer siempre el `config.premios` vigente.

## MODIFIED Requirements

### Requirement: Premios config-driven por juego

El sistema SHALL liquidar cada apuesta leyendo el premio del esquema `config.premios` del juego (`base`,
`modalidades`, `comodines`). Ningún plugin SHALL hardcodear el multiplicador. Cuando la apuesta trae un
snapshot de premios persistido al vender, el motor SHALL resolver el premio contra ese snapshot (override
de config); en ausencia de snapshot, SHALL usar el `config.premios` actual (fallback legacy).

(Previously: el motor leía siempre el `config.premios` actual del juego, sin snapshot por apuesta.)

#### Scenario: Premio base desde config

- GIVEN una apuesta animalito a `monje-millonario` (base 50×) por Bs. 10
- WHEN sale el animalito apostado
- THEN el premio es `10 × 50 = Bs. 500`

#### Scenario: Multiplicador hardcodeado ausente

- GIVEN un plugin que no consulta `config.premios`
- WHEN se liquida una apuesta
- THEN el premio se calcula desde `config.premios` del juego, no desde una constante del plugin

#### Scenario: Liquidación contra snapshot

- GIVEN una apuesta con snapshot (base 50×) y un `config.premios` actual distinto (base 60×)
- WHEN se liquida
- THEN el premio se resuelve con 50× (snapshot), no 60×

#### Scenario: Fallback legacy sin snapshot

- GIVEN una apuesta sin snapshot
- WHEN se liquida
- THEN el premio se resuelve con el `config.premios` actual

### Requirement: Pago validado contra el motor corregido

`PagoController` SHALL validar el monto contra el motor corregido (config-driven + acentos + terminales
+ comodines), nunca contra un plugin roto. Cuando la apuesta trae snapshot de premios, la validación SHALL
resolverse contra ese snapshot; en ausencia de snapshot, contra el `config.premios` actual (fallback
legacy).

(Previously: la validación usaba siempre el `config.premios` actual del motor corregido, sin snapshot.)

#### Scenario: Pago de animal acentuado

- GIVEN una apuesta ganadora "Delfín" liquidada correctamente
- WHEN el cajero paga el premio del reglamento
- THEN el pago se acepta (coincide con `calcularPremio`)

#### Scenario: Pago contra snapshot

- GIVEN una apuesta ganadora con snapshot (base 50×) y `config.premios` actual distinto
- WHEN el cajero paga
- THEN el monto validado coincide con el snapshot (50×)

#### Scenario: Pago con fallback legacy

- GIVEN una apuesta ganadora sin snapshot
- WHEN el cajero paga
- THEN el monto validado coincide con el `config.premios` actual
