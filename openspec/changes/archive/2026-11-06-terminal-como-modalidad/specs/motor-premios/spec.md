# Delta for motor-premios

> Nota no normativa: al archivar, la fila "Terminal Trío | 60×" de la tabla "Valores oficiales
> (autoritativos)" se elimina: Terminal Trío es la modalidad `terminal` del Trío Activo, ya
> representada en la fila "Trío Activo | 600× | Terminal 60× · Punta 60×".

## MODIFIED Requirements

### Requirement: Liquidación de la modalidad terminal (tripletas)

El sistema SHALL liquidar la jugada de `terminal` como modalidad de un juego `tripletas`, no como un
juego independiente. El motor SHALL comparar las 2 últimas cifras del triple vendido (identificado por
`combinacion.triple` = `a`|`b`|`c`, mapeado a `triple_a`|`triple_b`|`triple_c`) y SHALL pagar 60× o el
multiplicador configurado en `premios.modalidades.terminal` del juego. No existe el juego
`terminal-activo`: la variante comercial *Terminal Activo / Terminal Trío* es la modalidad `terminal`
del Trío Activo (60×).

(Previously: liquidaba `terminal-activo` como juego independiente, comparando contra la clave `numero`
que persisten los scrapers y pagando 60×.)

#### Scenario: Terminal paga 60× sobre el triple vendido

- GIVEN una apuesta `terminal` "10" por Bs. 10 sobre el triple `a` de `trio-activo` y el resultado con `triple_a=310`
- WHEN se liquida
- THEN el premio es `10 × 60 = Bs. 600` (las 2 últimas cifras de 310 son 10)

#### Scenario: Terminal no paga por desajuste

- GIVEN una apuesta `terminal` "11" sobre el triple `a` de `trio-activo` y el resultado con `triple_a=310`
- WHEN se liquida
- THEN la apuesta es perdedora (el terminal de 310 es 10, no 11)

#### Scenario: Terminal no paga contra otro slot

- GIVEN una apuesta `terminal` "10" sobre el triple `a` y el resultado con `triple_a=310` y `triple_b=510`
- WHEN se liquida
- THEN la apuesta es perdedora (compara SOLO contra el triple vendido `a`; el terminal 10 de `triple_b` no paga)

#### Scenario: No existe juego terminal-activo

- GIVEN el catálogo de juegos
- WHEN se busca un juego con slug `terminal-activo` o tipo `terminales`
- THEN no existe: la jugada terminal vive como modalidad de `tripletas`
