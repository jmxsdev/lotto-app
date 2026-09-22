# Archive Report: cierre-caja-taquilla

**Change**: cierre-caja-taquilla
**Archived**: 2026-09-17
**Rama**: `feat/cierre-caja-taquilla`
**Base**: `main` @ d250c20
**Verdict final**: PASS WITH WARNINGS
**Evidence revision**: `sha256:a0a9660b1d0b880b7e805ff4dfb4419d215da326a7e64c94f19dbb9844317a2a`

## Resumen del cambio

Implementación del cierre de caja diario de la taquilla (desglose por método de
pago, arqueo físico con faltante/sobrante por moneda, reporte semanal derivado de
los diarios persistidos, encadenamiento de períodos, política de tasa y
autorización por jerarquía) y de la captura/modelado del método de pago
(`metodo_pago`) en cobros de venta y pagos de premio, con reglas de validación,
default de USD a `efectivo` y backfill histórico. El cierre es una operación de
registro/reporte que NO bloquea la venta.

Dos capabilities nuevas: `cierre-caja` (9 requisitos / 24 escenarios) y
`metodo-pago` (5 requisitos / 11 escenarios). Total: **14 requisitos / 35
escenarios**.

## Estado final al cierre

Estado reportado por el orquestador al lanzar el archivo (fuente autoritativa;
anula cualquier snapshot intermedio):

- **Tasks**: 28/28 completadas (`[x]` en `tasks.md`), 0 incompletas.
- **Commits**: 6 work-unit commits sobre `main` @ d250c20:
  `087f7fa` (migración `metodo_pago`+arqueo, modelos), `ce14591` (captura en
  venta/ticket/premio), `1abdf56` (cierre diario: arqueo+desglose+tasa),
  `f8ed93da` (semanal+preview+orden de rutas), `71684f8f` (UI `cierre.astro` +
  IPC `print-cierre`), `5194fc8b` (colecciones Bruno).
- **Verify**: `pass_with_warnings`. Evidencia: Unit 281/281 + Feature 482/484
  (2 skipped pre-existentes ajenos al cambio: `PluginIntegrationTest`,
  `ScrapeResultsJobTest`, mismo baseline del verify 2026-08-29) = 763 passed /
  2 skipped / 3806 assertions, exit 0; build taquilla 8 páginas; Pint passed.
  0 CRITICAL, 0 blockers, 2 WARNING, 3 SUGGESTION. 35/35 escenarios compliant
  vía 53 tests dedicados (CierreCajaTest 34, MetodoPagoTest 14,
  PagoMetodoPagoTest 5) más evidencia manual documentada para impresión.

## Capabilities sincronizadas a specs principales

Ambas capabilities son NUEVAS (no existía spec previa en `openspec/specs/`); el
contenido delta se convierte en la spec base. Merge **aditivo** — no hay deltas
destructivos (0 REMOVED / 0 MODIFIED destructivo). Regla `rules.archive` de
`openspec/config.yaml` ("Avisar antes de fusionar deltas destructivos")
verificada: no aplica aviso; se confirma merge aditivo.

- `openspec/specs/cierre-caja/spec.md` — creada (9 req / 24 escenarios)
- `openspec/specs/metodo-pago/spec.md` — creada (5 req / 11 escenarios)

## Evidencia y trazabilidad

- `openspec/changes/cierre-caja-taquilla/verify-report.md` — veredicto
  `pass_with_warnings`, evidence_revision `sha256:a0a9660b…`, test_output_hash
  `sha256:ea050785…`, build_output_hash `sha256:d58c1876…`.
- Engram `sdd/cierre-caja-taquilla/apply-progress` (obs 249) — snapshot
  intermedio de progreso.
- Engram `sdd/cierre-caja-taquilla/verify-evidence` (obs 287) — evidencia
  runtime cruda (recolectada por el orquestador tras interrupciones del runtime
  en los subagentes de verify).
- Engram `sdd/cierre-caja-taquilla/verify-report` (obs 289) — snapshot del
  reporte de verificación.
- Este reporte: Engram `sdd/cierre-caja-taquilla/archive-report`.

## Items abiertos trasladados (warnings y sugerencias)

Warnings (sin cobertura automatizada; mitigaciones documentadas):

1. **Impresión sin cobertura automatizada**: `print-cierre` (Electron) sin
   runner de tests; verificado por build + smoke de `generateCierreHtml` (8/8)
   + E2E manual. Mitigación sugerida: convertir el smoke test en test
   permanente (viable sin Electron).
2. **Consistencia del desglose ante apuestas huérfanas**: `CierreService` se
   deriva del JOIN `apuestas`→`pagos` ingreso; una apuesta no anulada sin su
   `Pago` ingreso (anomalía/legado) sumaría en totales pero no en el desglose.
   El flujo de producción mantiene el invariante, pero no hay guard explícito.

Sugerencias:

1. **Backfill histórico a `efectivo`**: el desglose histórico previo a la
   migración es aproximado; limitación aceptada y documentada.
2. **`collections/` con URLs pre-v1** (`/api/...`): deuda pre-existente y fuera
   de alcance; el nuevo request `Cierre Semanal` usa `/api/v1`. Un `chore`
   futuro podría migrar todo el árbol.
3. **Doble fuente de "efectivo"**: `CierreService` (ventas − egresos) vs
   reporte `cuadre-caja` (resta Vencidos). Intencionalmente no conciliadas en
   esta iteración; alinear cuando negocio lo defina.

Preguntas de negocio abiertas:

- **`fecha_hasta` inclusiva** (día completo): implementada de forma consistente;
  pendiente de confirmación de negocio (open question del design).
- **`GET /cierre/actual`**: aditivo, no listado en el proposal; documentado como
  open question del design.

## Nota de entrega

**Un solo PR** con `size:exception` aprobado por el maintainer: los slices
stacked planificados se colapsan en una sola rama/PR (`feat/cierre-caja-taquilla`
sobre `main` @ d250c20). Los artefactos openspec están gitignored (`*.md`); el
orquestador los forzará al commit de entrega. Próximo paso recomendado:
`delivery-single-pr`.