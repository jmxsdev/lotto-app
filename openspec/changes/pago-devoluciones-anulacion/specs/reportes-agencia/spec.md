# Delta for reportes-agencia

> **Open decision (proposed, owner review)**: D10 — reversal-pair exclusion rule (Option A, explore §5.2). Spec written for the proposed behavior.

## ADDED Requirements

### Requirement: Reversal-pair exclusion in cuadreCaja and timeSeriesData

`cuadreCaja` and `timeSeriesData` MUST treat an annulled sale and its refund as a reversal pair so no double subtraction occurs. A `devolucion` outflow MUST be excluded when its sale is already excluded from the same window — i.e. the sale apuesta is `anulada` or soft-deleted AND its `fecha_hora` falls inside the report window. Refunds of sales outside the window, and untied refunds with no `apuesta_id`, MUST remain real outflows (D6, D10).

#### Scenario: same-window pair nets to zero

- GIVEN a sale of 1000 bs at 10:00 and its 1000 bs refund at 10:03, both inside the report window
- WHEN `cuadreCaja` is computed
- THEN `Venta = 0`, `Devoluciones = 0`, `Efectivo = 0`

#### Scenario: cross-window refund stays an outflow

- GIVEN a sale annulled outside the window and its refund inside the window
- WHEN `cuadreCaja` is computed
- THEN the refund counts as a real outflow (no reversal pair)

#### Scenario: untied refund still counts

- GIVEN a `devolucion` with `apuesta_id = null`
- WHEN `cuadreCaja` is computed
- THEN it still counts as an outflow

#### Scenario: timeSeriesData saldo nets to zero

- GIVEN a same-day sale + refund pair of 1000 bs
- WHEN `timeSeriesData` is computed
- THEN `ventas = 0`, `devolucion = 0`, `saldo = 0`, and the six series keys are unchanged

#### Scenario: annulled sale with no refund

- GIVEN an annulled sale with no refund
- WHEN `cuadreCaja` is computed
- THEN `Venta` excludes the sale and `Devoluciones = 0` (no regression)
