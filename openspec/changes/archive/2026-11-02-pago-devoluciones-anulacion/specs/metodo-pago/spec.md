# Delta for metodo-pago

## MODIFIED Requirements

### Requirement: Captura de método en el pago de premio (egreso/devolucion)

`PagoController::store` MUST accept and validate `metodo_pago` when registering `egreso` and persist it in the `Pago` (USD forced `efectivo`; default `efectivo` if omitted). A refund (`devolucion`) is now created only by ticket annulment: its `metodo_pago` MUST mirror the sale `ingreso`'s `metodo_pago` by default, an explicit operator override MAY be accepted, and USD MUST be forced to `efectivo` (D12).
(Previously: `devolucion` accepted a free-form `metodo_pago` on `PagoController::store` with no derivation from the sale.)

#### Scenario: Pago de premio con método

- GIVEN un premio VES a pagar con `metodo_pago = pago_movil`
- WHEN se registra el pago
- THEN el `Pago` persiste `metodo_pago = pago_movil`

#### Scenario: Premio USD

- GIVEN un premio en USD
- WHEN se registra el pago
- THEN `metodo_pago = efectivo` (aunque se envíe otro valor)

#### Scenario: Método inválido

- GIVEN `metodo_pago` fuera del enum
- WHEN se registra el pago
- THEN responde 422 con errores de validación

#### Scenario: Refund mirrors sale method

- GIVEN a sale whose `ingreso` `metodo_pago = pago_movil` and a valid pre-draw refund
- WHEN the refund is recorded
- THEN the `devolucion` `Pago` persists `metodo_pago = pago_movil`

#### Scenario: Refund override allowed

- GIVEN a valid refund and an explicit `metodo_pago` override
- WHEN the refund is recorded
- THEN the explicit method is used (still subject to USD forcing)

#### Scenario: Refund USD forced efectivo

- GIVEN a USD sale and a valid refund (with or without an override)
- WHEN the refund is recorded
- THEN `metodo_pago = efectivo`
