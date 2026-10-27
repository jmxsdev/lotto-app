---
capability: liquidacion-multisorteo
change: animalitos-selva-tripleta
status: draft
---

# liquidacion-multisorteo Specification

## Purpose

Definir el settlement de apuestas que dependen de más de un resultado del día, empezando por la tripleta
de animalitos. La apuesta se mantiene pendiente mientras llegan los sorteos, gana solo cuando se cumple
la combinación completa y pierde al agotarse la ventana del día. La evidencia queda persistida.

## Requirements

| # | Requirement | Strength | Estado |
|---|------------|----------|--------|
| REQ-1 | Evaluación por día | MUST | draft |
| REQ-2 | Evidencia de resultados ganadores | MUST | draft |
| REQ-3 | Pago con evidencia | MUST | draft |
| REQ-4 | Vencimiento y pérdida al cierre del día | MUST | draft |
| REQ-5 | Idempotencia | MUST | draft |

### Requirement: REQ-1 — Evaluación por día

`ApuestaService::verificarGanadores` SHALL separar las apuestas de un solo resultado de las apuestas
multi-sorteo. Para una tripleta, en cada resultado nuevo del juego/día SHALL cargar los resultados
elegibles del día (mismo `juego_id`, misma fecha, hora igual o posterior a la venta), evaluar la
combinación completa y mantener la apuesta `pendiente` mientras no se cumpla. La apuesta MUST NOT
marcarse perdida en el primer sorteo.

#### Scenario: Pendiente hasta completar la tripleta

- GIVEN una tripleta vendida a las 08:00
- WHEN llegan los resultados de 09:00 y 10:00 con una y dos figuras
- THEN la apuesta sigue `pendiente` y sin `resultado_id`

#### Scenario: Gana al llegar el tercer sorteo

- GIVEN una tripleta con dos figuras ya acertadas en sorteos distintos
- WHEN llega el tercer sorteo con la figura restante
- THEN la apuesta pasa a `ganadora` y se registra la evidencia

### Requirement: REQ-2 — Evidencia de resultados ganadores

La apuesta SHALL persistir los IDs de los tres resultados que completaron la tripleta en
`apuestas.resultado_ids` (JSON) y SHALL mantener `resultado_id` apuntando al último resultado que
completó la combinación, para compatibilidad con el flujo de pago existente. La evidencia MUST permitir
reconstruir la combinación y auditar el pago.

#### Scenario: Evidencia persistida

- GIVEN una tripleta ganadora
- WHEN se consulta la apuesta
- THEN `resultado_ids` contiene exactamente tres IDs de resultados del mismo juego/día
- AND `resultado_id` contiene el último de ellos

### Requirement: REQ-3 — Pago con evidencia

`PagoController::calcularPremio` SHALL detectar una apuesta multi-sorteo, cargar los resultados de
`resultado_ids` del mismo juego, re-evaluar la combinación con el plugin y calcular el premio con el
multiplicador de la modalidad (`tripleta`) y el snapshot de premios de la apuesta. Si la evidencia no
existe o no completa la tripleta, el premio MUST ser 0 y el pago MUST rechazarse.

#### Scenario: Pago de tripleta ganadora

- GIVEN una tripleta ganadora con `resultado_ids` persistidos
- WHEN el cajero paga el premio
- THEN el monto validado es `amount × 200` (Cazalotón) o `amount × 50` (Chaima)

#### Scenario: Pago sin evidencia

- GIVEN una apuesta marcada como tripleta sin `resultado_ids`
- WHEN se intenta pagar
- THEN el premio calculado es 0 y el pago se rechaza

### Requirement: REQ-4 — Vencimiento y pérdida al cierre del día

Cuando llegue la última extracción elegible del juego/día, las tripletas que sigan pendientes SHALL
marcarse `perdida` (no `vencido`) porque ya fueron evaluadas y no completaron la combinación. Si el
último resultado nunca llega, la apuesta queda sujeta al vencimiento por falta de resultado existente
(24 h configurables) y se marca `vencido`.

#### Scenario: Pérdida al cierre del día

- GIVEN una tripleta con dos aciertos y la última extracción del día ya publicada
- WHEN se procesa el último resultado
- THEN la apuesta pasa a `perdida` con premio 0

#### Scenario: Resultado faltante

- GIVEN una tripleta pendiente y la última extracción del día no llegó
- WHEN vence la ventana de 24 h
- THEN la apuesta pasa a `vencido` (no se inventa un resultado)

### Requirement: REQ-5 — Idempotencia

El settlement multi-sorteo MUST ser idempotente: una apuesta ya liquidada (`resultado_id` no nulo) MUST
NOT reprocesarse ni duplicar el premio del ticket. El reprocesamiento del mismo resultado MUST NOT
incrementar dos veces `tickets.premio_total_*`.

#### Scenario: Reprocesar el mismo resultado no duplica

- GIVEN una tripleta ya marcada `ganadora`
- WHEN se vuelve a ejecutar el settlement para el mismo día
- THEN el premio del ticket no se incrementa otra vez
