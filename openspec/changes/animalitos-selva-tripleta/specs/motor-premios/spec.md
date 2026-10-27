---
capability: motor-premios
change: animalitos-selva-tripleta
status: draft
---

# motor-premios Specification (delta)

## Purpose

Corregir dos formas de acierto que hoy no liquidan con datos reales: el comodín A/B de Selva Plus y la
tripleta multi-sorteo de Cazalotón / Loto Chaima. Retirar `premio_posible` del contrato de venta porque no
se imprime, no lo consume ningún front y no interviene en el pago.

## Requirements

| # | Requirement | Strength | Estado |
|---|------------|----------|--------|
| REQ-1 | Comodín Selva como figura apostable | MUST | draft |
| REQ-2 | Tripleta multi-sorteo todo o nada | MUST | draft |
| REQ-3 | Sin `premio_posible` en la venta | MUST | draft |
| REQ-4 | Modalidad declarada validada en la venta | MUST | draft |

### Requirement: REQ-1 — Comodín Selva como figura apostable

En un sorteo de Selva Plus cuyo resultado es `comodin:"A"` o `comodin:"B"`, el sistema SHALL liquidar
como ganadora **solo** la apuesta cuya selección sea el comodín correspondiente (`comodin-a` /
`comodin-b`, o su nombre oficial `Leoncito` / `Selva Plus`). El comodín MUST pagar 160× (A) o 200× (B)
desde `config.premios.comodines`. Una apuesta a un animal MUST perder en ese sorteo. Una apuesta al
comodín MUST perder contra un resultado numérico.

La comparación MUST normalizar acentos, mayúsculas y separadores para aceptar `comodin-a`,
`Comodín A, Leoncito`, `Leoncito` y variantes equivalentes. La fuente no envía `nombre_animal` en un
sorteo con comodín; el sistema MUST NOT inventarlo.

#### Scenario: Comodín A paga 160×

- GIVEN una apuesta de Bs. 10 a `comodin-a` en Selva Plus
- WHEN el resultado del sorteo es `{comodin:"A", comodin_nombre:"Leoncito"}`
- THEN la apuesta es ganadora y el premio es `10 × 160 = Bs. 1.600`

#### Scenario: Comodín B paga 200×

- GIVEN una apuesta de Bs. 10 a `comodin-b` en Selva Plus
- WHEN el resultado del sorteo es `{comodin:"B", comodin_nombre:"Selva Plus"}`
- THEN la apuesta es ganadora y el premio es `10 × 200 = Bs. 2.000`

#### Scenario: Animal pierde en sorteo con comodín

- GIVEN una apuesta de Bs. 10 a `perro` en Selva Plus
- WHEN el resultado del sorteo es `{comodin:"A", comodin_nombre:"Leoncito"}`
- THEN la apuesta es perdedora (premio 0)

#### Scenario: Comodín pierde contra resultado numérico

- GIVEN una apuesta de Bs. 10 a `comodin-a` en Selva Plus
- WHEN el resultado del sorteo es `{numero:5, nombre_animal:"León"}`
- THEN la apuesta es perdedora (premio 0)

### Requirement: REQ-2 — Tripleta multi-sorteo todo o nada

La tripleta de Cazalotón (200×) y Loto Chaima (50×) SHALL liquidarse como **una sola apuesta todo o
nada**: las tres figuras apostadas MUST ser distintas y MUST aparecer en **tres sorteos distintos** del
mismo juego y del mismo día. El mismo sorteo MUST NOT satisfacer dos selecciones. Un resultado de otro
juego MUST NOT contar. Si solo aparecen una o dos figuras, la apuesta MUST perder (no hay pago parcial).

La apuesta MUST NOT atarse a un único `sorteo_hora`; se vende como jugada de día. El settlement SHALL
evaluar la apuesta contra todos los resultados elegibles del día y SHALL marcarla ganadora cuando exista
la tripleta de coincidencias. La evidencia de los tres resultados ganadores SHALL persistirse para
auditoría y pago. La última extracción elegible del día SHALL marcar como perdidas las tripletas que
sigan pendientes.

#### Scenario: Tres figuras en tres sorteos pagan 200×

- GIVEN una apuesta de Bs. 10 a la tripleta `perro, gato, león` en Cazalotón
- WHEN `perro` sale en el sorteo 09:00, `gato` en el 10:00 y `león` en el 11:00 del mismo día
- THEN la apuesta es ganadora y el premio es `10 × 200 = Bs. 2.000`
- AND se guardan los IDs de los tres resultados como evidencia

#### Scenario: Dos figuras en el mismo sorteo no cuentan

- GIVEN una apuesta a la tripleta `perro, gato, león`
- WHEN un solo sorteo publica resultados que permiten asociar `perro` y `gato`
- THEN la apuesta sigue pendiente; ese sorteo no satisface dos selecciones

#### Scenario: Un solo acierto pierde

- GIVEN una apuesta a la tripleta `perro, gato, león`
- WHEN solo sale `perro` en el día
- THEN la apuesta es perdedora (premio 0), sin pago parcial

#### Scenario: Tripleta de otro juego no cuenta

- GIVEN una apuesta a la tripleta de Cazalotón
- WHEN las figuras aparecen en resultados de Loto Chaima
- THEN la apuesta de Cazalotón no se liquida con esos resultados

#### Scenario: Figuras repetidas se rechazan en la venta

- GIVEN una tripleta `perro, perro, gato`
- WHEN se intenta vender
- THEN la venta se rechaza con 422

### Requirement: REQ-3 — Sin `premio_posible` en la venta

El sistema MUST NOT calcular, persistir ni exponer `premio_posible` / `premio_posible_usd`. La venta
SHALL persistir únicamente la combinación, el monto, la tasa y el snapshot de premios. Los tests y la
documentación que asumían `premio_posible > 0` MUST retirarse o reescribirse para validar la aceptación
de la venta, no un premio potencial.

#### Scenario: Venta sin premio posible

- GIVEN una apuesta válida de cualquier modalidad
- WHEN se crea el ticket
- THEN la respuesta es 201 y `detalle_apuestas` no contiene `premio_posible`
- AND el ticket no imprime premio posible

### Requirement: REQ-4 — Modalidad declarada validada en la venta

Como `premioPosible` era el guard que rechazaba modalidades declaradas no soportadas, `ApuestaService`
SHALL validar que una `combinacion.modalidad` distinta de `base` exista en
`config.premios.modalidades` del juego. En caso contrario MUST rechazar la venta. La tripleta MUST
rechazarse en juegos sin la modalidad `tripleta` (por ejemplo Selva Plus).

#### Scenario: Modalidad no soportada se rechaza

- GIVEN una apuesta con `modalidad:"tripleta"` a Selva Plus (sin modalidad tripleta)
- WHEN se intenta vender
- THEN la venta se rechaza con 422

#### Scenario: Modalidad soportada se acepta

- GIVEN una apuesta con `modalidad:"tripleta"` a Cazalotón (200× en `modalidades`)
- WHEN se intenta vender
- THEN la venta se acepta (201)
