# Delta for reportes-agencia

## ADDED Requirements

### Requirement: Columna de comisión en ventasTotales

`ventasTotales` MUST exponer una columna de comisión (bs-equivalente) por fila de reporte, calculada según la capability `comisiones`. La columna SHALL ser aditiva y MUST NOT alterar la agrupación existente por `nivel` (agencia=local, taquilla=máquina).

#### Scenario: Columna de comisión presente

- GIVEN un reporte `ventasTotales` para un rango
- WHEN se calcula
- THEN cada fila incluye la comisión bs-equivalente del período

#### Scenario: Agrupación intacta

- GIVEN `nivel=agencia`
- WHEN se calcula `ventasTotales` con comisión
- THEN sigue agrupando por local (semántica existente sin cambios)
