```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:a0a9660b1d0b880b7e805ff4dfb4419d215da326a7e64c94f19dbb9844317a2a
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 14/14
scenarios: 35/35
test_command: COMPOSER_PROCESS_TIMEOUT=1800 DB_DATABASE=lotto_test_cierre composer test -- --testsuite=Unit|Feature
test_exit_code: 0
test_output_hash: sha256:ea0507854ef2eaf32b6d8bedbb84bd6b7bd1e494b7dae0d5429fd9a53ac93fd8
build_command: pnpm build (taquilla) && vendor/bin/pint --test (backend)
build_exit_code: 0
build_output_hash: sha256:d58c18767c5ec92a927990244ab8b5d1b281a1ca3c29f0aa48737a0c0ddecd7d
```

# Verify Report: cierre-caja-taquilla

**Change**: cierre-caja-taquilla
**Version**: draft (2 specs nuevas: `cierre-caja`, `metodo-pago`)
**Mode**: Standard — strict TDD en backend
**Rama**: `feat/cierre-caja-taquilla` (6 work-unit commits sobre `main` @ d250c20)
**Commits**: 087f7fa (migración+modelos), ce14591 (captura método), 1abdf56 (cierre diario), f8ed93da (semanal+preview+rutas), 71684f8f (UI taquilla+IPC), 5194fc8b (colecciones)
**Fecha**: 2026-09-17

## Resumen ejecutivo

Verificación del cambio completo. Las **28/28 tareas** de `tasks.md` están `[x]` y el estado nativo reporta `verify: ready` / `apply: all_done` sin `blockedReasons`. Suite completa verde: **Unit 281/281 (937 asserts) + Feature 484 tests / 482 passed / 2 skipped (2869 asserts) = 763 passed / 2 skipped / 3806 assertions**, exit 0 (`COMPOSER_PROCESS_TIMEOUT=1800 DB_DATABASE=lotto_test_cierre composer test`); los 2 skipped son pre-existentes y ajenos al cambio (`PluginIntegrationTest`, `ScrapeResultsJobTest`, mismo baseline del verify 2026-08-29). `pnpm build` de taquilla: **8 páginas**, exit 0. `vendor/bin/pint --test`: passed. **35/35 escenarios compliant** vía 53 tests dedicados (CierreCajaTest 34, MetodoPagoTest 14, PagoMetodoPagoTest 5) más evidencia manual documentada para impresión. **0 CRITICAL, 0 blockers, 2 WARNING, 3 SUGGESTION. Verdict: PASS WITH WARNINGS.**

Nota de ejecución: la evidencia runtime fue recolectada por el orquestador (los subagentes de verify fueron interrumpidos dos veces por el runtime; el ledger nativo exigió el sucesor acotado `verify-evidencia-runtime`, autorizado por el maintainer). Evidencia cruda: Engram `sdd/cierre-caja-taquilla/verify-evidence` (obs 287).

## Completeness

| Métrica | Valor |
|---------|-------|
| Tasks total | 28 |
| Tasks complete | 28 |
| Tasks incomplete | 0 |

| Work unit (commit) | Gate de contrato | Tests enfocados |
|---|---|---|
| PR1 `087f7fa` — migración `metodo_pago`+arqueo, modelos | pass | `PagoMetodoPagoTest` 5/5 |
| PR2 `ce14591` — captura en venta/ticket/premio | pass | `MetodoPagoTest` 14/14 |
| PR3 `1abdf56` — cierre diario (arqueo+desglose+tasa) | pass | `CierreCajaTest` 25/25 |
| PR4 `f8ed93da` — semanal+preview+orden de rutas | pass | `CierreCajaTest` 34/34 |
| PR5 `71684f8f` — UI `cierre.astro` + IPC `print-cierre` | pass | build 8 páginas + smoke `generateCierreHtml` 8/8 |
| PR6 `5194fc8b` — colecciones Bruno | pass | YAML 5/5 |

## Build & Tests Execution

**Tests (suite completa, secuencial)**: ✅ 763 passed / ❌ 0 failed / ⚠️ 2 skipped (pre-existentes)
```text
{"tool":"phpunit","result":"passed","tests":281,"passed":281,"assertions":937,"duration_ms":185737}
{"tool":"phpunit","result":"passed","tests":484,"passed":482,"assertions":2869,"duration_ms":1252193,"skipped":2}
```

**Pint (backend)**: ✅ passed
```text
{"tool":"pint","result":"passed"}
```

**Build (taquilla)**: ✅ 8 páginas
```text
[build] 8 page(s) built in 6.82s
[build] Complete!
```
Los warnings de prerender (`unknown scheme` al resolver `api://` en `MainLayout`) son pre-existentes en todas las páginas, no del cambio.

**Coverage**: ➖ No configurada (umbral 0 en `openspec/config.yaml`); verificación por escenarios.

## Spec Compliance Matrix

Total: **14 requirements / 35 scenarios** (contados de los specs reales).

### Spec 1: cierre-caja (9 req / 24 escenarios)

| Requirement | Esc. | Evidencia |
|---|---|---|
| Cierre diario con arqueo físico | 4 | `CierreCajaTest`: arqueo persistido + diferencia, faltante, sobrante, sin arqueo (nulos); arqueo negativo 422 |
| Desglose por método de pago | 3 | `test_desglose_por_metodo_suma_el_total_bs`, `test_usd_se_contabiliza_integro_en_efectivo`, `test_desglose_excluye_apuesta_anulada` |
| Reporte semanal derivado | 4 | `test_semanal_rollup_semana_completa`, `test_semanal_semana_vacia_devuelve_ceros`, `test_semanal_semana_incompleta_expone_ventana_real`, `test_semanal_alcance_taquilla_y_403` (+ `test_semanal_respeta_fecha_desde_hasta`/XOR) |
| Política de tasa (snapshot + fallback) | 3 | `test_fallback_usa_ultima_tasa_historica`, `test_sin_tasa_alguna_responde_422`; snapshot `exchange_rate_cierre` en cada cierre |
| Encadenamiento de períodos | 3 | Tests existentes de `CierreCajaTest` (último cierre → primera apuesta → now) |
| Autorización por jerarquía | 4 | Tests existentes (rol taquilla/403/422 admin) + alcance del semanal |
| El cierre no bloquea la venta | 1 | `test_venta_posterior_al_cierre_se_registra_normalmente` |
| Historial de cierres | 1 | `test_index_incluye_arqueo_desglose_y_diferencia` |
| Impresión del cierre | 1 | ⚠️ build + smoke `generateCierreHtml` 8/8 + E2E manual documentado (ver WARNING 1) |

### Spec 2: metodo-pago (5 req / 11 escenarios)

| Requirement | Esc. | Evidencia |
|---|---|---|
| Captura en cobro (ingreso) | 3 | `MetodoPagoTest`: VES `transferencia`, USD⇒`efectivo`, omitido⇒`efectivo` |
| Captura en premio (egreso/devolucion) | 3 | `MetodoPagoTest`: `pago_movil`, USD⇒`efectivo`, inválido⇒422 |
| Reglas de validación | 2 | `Rule::in` + `Pago::METODOS_PAGO`; tests 422 |
| Método único en pagos `mixto` | 1 | `MetodoPagoTest` mixto (un método; componente USD fuerza `efectivo`) |
| Default y backfill histórico | 2 | Migración con backfill `whereNull`→`efectivo`; `PagoMetodoPagoTest` |

### Decisiones de diseño (AD-1…AD-11)

Implementadas y verificadas por spot-check: ENUM `pagos.metodo_pago` + default/backfill (AD-1), arqueo/faltante persistidos (AD-2), `desglose_metodos` JSON de shape fijo (AD-3), desglose de ventas vía `apuestas` JOIN `pagos` ingreso con USD normalizado a `efectivo` (AD-4), ventana semanal calendario lunes–domingo `America/Caracas` (AD-5), `resolverTasa()` con fallback a última tasa (AD-6), guard MySQL del ENUM (AD-7), preview read-only `GET /cierre/actual` (AD-8), IPC `print-cierre` (AD-9), contrato de captura aditivo (AD-10), orden de rutas `/cierre/actual|semanal` antes de `/cierre/{cierre}` verificado en `backend/routes/api.php:169-171` (AD-11).

## Findings

### CRITICAL — 0

Ninguno.

### WARNING — 2

1. **Impresión sin cobertura automatizada**: `print-cierre` (Electron) no tiene runner de tests; verificado por build + smoke test de `generateCierreHtml` (8/8) + E2E manual documentado. Riesgo de regresión si cambia el HTML/escape. Mitigación sugerida: convertir el smoke test en test permanente (es viable sin Electron: `require('electron')` devuelve un path y permite testear las funciones puras).
2. **Consistencia del desglose ante apuestas huérfanas**: el desglose de ventas (`CierreService::calcularTotales`, líneas 117-125) se deriva del JOIN `apuestas`→`pagos` ingreso; si existiera una apuesta no anulada sin su `Pago` ingreso (anomalía/legado), sumaría en totales pero no en el desglose. El flujo de producción mantiene el invariante (cada apuesta crea su ingreso), pero no hay guard explícito. Mitigación sugerida: chequeo de reconciliación o test con dato anómalo que documente el comportamiento.

### SUGGESTION — 3

1. **Backfill histórico a `efectivo`** (OQ5): el desglose histórico previo a la migración es aproximado; limitación aceptada y documentada.
2. **`collections/` con URLs pre-v1** (`/api/...`): deuda pre-existente y fuera de alcance; el nuevo request `Cierre Semanal` usa `/api/v1`. Un `chore` futuro podría migrar todo el árbol.
3. **Doble fuente de "efectivo"**: `CierreService` (ventas − egresos) vs reporte `cuadre-caja` (resta Vencidos). Intencionalmente no conciliadas en esta iteración; documentar o alinear cuando negocio lo defina.

## Limitaciones y notas

- Semántica de `fecha_hasta` inclusiva (día completo) implementada de forma consistente; pendiente de confirmación de negocio (open question del design).
- `GET /cierre/actual` es aditivo (no estaba en el proposal; documentado como open question del design).
- 2 skipped pre-existentes: `PluginIntegrationTest` y `ScrapeResultsJobTest` (mismo baseline del verify 2026-08-29).
- Evidencia cruda y comandos: Engram `sdd/cierre-caja-taquilla/verify-evidence` (obs 287) y `sdd/cierre-caja-taquilla/apply-progress` (obs 249).
