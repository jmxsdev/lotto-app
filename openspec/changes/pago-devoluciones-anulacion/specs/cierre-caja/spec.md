# Delta for cierre-caja

> **Open decision (proposed, owner review)**: D10 — reversal-pair exclusion rule. Spec written for the proposed behavior.

## ADDED Requirements

### Requirement: Reversal-pair exclusion in cierre totals

`CierreService::calcularTotales` MUST treat an annulled sale and its refund as a reversal pair: the sale is excluded from `total_ventas_*` and its refund from `total_egresos_*`, so `total_efectivo_*` is unchanged and the pair nets to zero (D6). `desglose_metodos` MUST reconcile with `total_ventas_*`: the method breakdown built from `pagos.tipo='ingreso'` MUST exclude the same annulled sales the totals exclude.

#### Scenario: pair nets in cierre

- GIVEN a period with a 1000 bs sale and its same-period refund, both annulled
- WHEN `calcularTotales` runs
- THEN `total_ventas_bs` excludes the sale and `total_egresos_bs` excludes the refund
- AND `total_efectivo_bs` is unchanged (net zero)

#### Scenario: desglose reconciles

- GIVEN an annulled sale whose `ingreso` `Pago` appears in the method breakdown
- WHEN the cierre is computed
- THEN `desglose_metodos` excludes that sale's method amount
- AND the method sums equal `total_ventas_bs`

#### Scenario: cross-window refund stays

- GIVEN a refund whose sale is outside the closing window
- WHEN `calcularTotales` runs
- THEN the refund remains an egreso (outflow)
