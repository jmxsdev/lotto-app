# Delta for cierre-caja

## MODIFIED Requirements

### Requirement: Impresión del cierre y del reporte desde la taquilla

The `cierre.astro` UI MUST print a close result through the native ESC/POS path (capability `impresion-escpos`), with deterministically formatted amounts (dot thousands, comma decimals, es-VE visual style). The "Reportes por rangos" section MUST print the range report (totals + per-close list with breakdown) via the `print-reporte` IPC channel routed to the native layout builder, not `generateReporteHtml()`.
(Previously: mandated `electron-pos-printer` + `toLocaleString('es-VE')` + `generateReporteHtml()`.)

#### Scenario: Imprimir resultado

- GIVEN a close shown in the taquilla
- WHEN the operator requests printing
- THEN the close result is sent to the native ESC/POS path with formatted amounts
- AND the `print-cierre` channel returns `{ success, message }`

#### Scenario: Imprimir reporte por rango

- GIVEN a range report shown in the taquilla
- WHEN the operator requests printing the report
- THEN the report is sent to the native ESC/POS path with totals and the per-close list with breakdown
- AND the `print-reporte` channel returns `{ success, message }`
