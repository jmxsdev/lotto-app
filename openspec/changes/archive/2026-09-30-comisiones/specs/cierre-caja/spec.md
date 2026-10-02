# Delta for cierre-caja

## ADDED Requirements

### Requirement: Desglose de comisión en el cuadre/cierre

El cierre/cuadre MUST exponer un desglose de comisión (bs-equivalente) calculado según la capability `comisiones`. El desglose SHALL ser aditivo y MUST NOT alterar `ventas − egresos`, el arqueo ni la diferencia por moneda existentes.

#### Scenario: Desglose de comisión presente

- GIVEN un cierre/cuadre para un período
- WHEN se calcula
- THEN la respuesta incluye el desglose de comisión bs-equivalente

#### Scenario: Totales existentes intactos

- GIVEN el cálculo de cierre existente
- WHEN se añade el desglose de comisión
- THEN `total_efectivo`, arqueo y faltante/sobrante no cambian
