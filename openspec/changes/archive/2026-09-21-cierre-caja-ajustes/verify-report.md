```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:fb2fe2bcdc8b1eb50be414eb68c9e19e8ae5a862e5f2b11ba0abb9ec573d5455
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 10/10
scenarios: 35/35
test_command: COMPOSER_PROCESS_TIMEOUT=1800 DB_DATABASE=lotto_test_cierre composer test -- --testsuite=Unit|Feature
test_exit_code: 0
test_output_hash: sha256:cf2438e9583c5fca8b540e6408837aee4a7dc0a32c9608153a393e1554f72ade
build_command: pnpm build (taquilla) + pnpm build (panel) + vendor/bin/pint --test (backend)
build_exit_code: 0
build_output_hash: sha256:3bd8e35124254120d1ee621d53478e5e9151990d354bef92e1e12914230aa1d0
```

# Verify Report: cierre-caja-ajustes

**Change**: cierre-caja-ajustes (sucesor de `cierre-caja-taquilla`; misma rama `feat/cierre-caja-taquilla`, PR #12)
**Version**: draft (delta `cierre-caja` + nueva `clave-cierre`)
**Mode**: strict TDD en backend; builds + E2E manual en taquilla/panel
**Commits del ciclo (sobre el base d250c20)**: 0b360a2e, b05af10, bd8cea9e, ffa02b12, ef5b2daf, b0c5cd7, 1491640
**Fecha**: 2026-09-21

## Resumen ejecutivo

Verificación del ciclo de ajustes. **29/29 tareas**; estado nativo `apply: all_done` → `verify: ready`. Suite completa verde: **Unit 281/281 (937 asserts) + Feature 514 tests / 512 passed / 2 skipped pre-existentes (3059 asserts) = 793 passed / 3996 asserts**, exit 0. Builds: taquilla 8 páginas y panel 25 páginas (incluye `/clave-cierre`) exit 0; `vendor/bin/pint --test` (backend completo) passed. **35/35 escenarios compliant** con suites dedicadas (CierreCajaTest 48, ClaveCierreTest 16) y regresiones. **0 CRITICAL, 0 blockers, 2 WARNING, 4 SUGGESTION. Verdict: PASS WITH WARNINGS.**

Nota de ejecución: la evidencia runtime y este reporte se completaron inline por el orquestador (fallos de red interrumpieron los lanzamientos delegados; el ledger nativo exigió sucesores acotados `cc-ajustes-final-evidence`/`cc-ajustes-verify`, autorizados por el maintainer).

## Completeness

| Métrica | Valor |
|---------|-------|
| Tasks total | 29 |
| Tasks complete | 29 |
| Tasks incomplete | 0 |

| Work unit (commit) | Gate | Evidencia |
|---|---|---|
| WU1 `0b360a2e` — migración + modelos | pass | migrate/rollback round-trip en `lotto_test_cierre`; suite 34/34 |
| WU2 `b05af10` — clave self-service + cadena | pass | `ClaveCierreTest` 16/16 |
| WU3 `bd8cea9e` — re-cierre idempotente + `cierre_hoy` | pass | `CierreCajaTest` 47/47 |
| WU4 `ffa02b12` — shape completo `cierres[]` | pass | `CierreCajaTest` 48/48 |
| WU5 `ef5b2daf` — UI taquilla + IPC `print-reporte` | pass | build 8 páginas; smoke `generateReporteHtml` 10/10; E2E manual |
| WU6 `b0c5cd7` — panel `clave-cierre` + 1 nav | pass | build 25 páginas; `api.ts` intacto; E2E manual |
| WU7 `1491640` — colecciones | pass | YAML 4/4; rename preservado |

## Build & Tests Execution

**Tests (suite completa, secuencial)**: ✅ 793 passed / ❌ 0 failed / ⚠️ 2 skipped (pre-existentes)
```text
{"tool":"phpunit","result":"passed","tests":281,"passed":281,"assertions":937,"duration_ms":156056}
{"tool":"phpunit","result":"passed","tests":514,"passed":512,"assertions":3059,"duration_ms":1389353,"skipped":2}
```

**Pint (backend completo)**: ✅ passed
```text
{"tool":"pint","result":"passed"}
```

**Builds**: ✅ taquilla 8 páginas (`pnpm build`, exit 0) · ✅ panel 25 páginas con `/clave-cierre/index.html` (`pnpm build`, exit 0)

**Coverage**: ➖ No configurada (umbral 0); verificación por escenarios.

## Spec Compliance Matrix

Total: **10 requirements / 35 scenarios** (4/14 `clave-cierre` + 6/21 delta `cierre-caja`).

### Spec clave-cierre (4 req / 14 escenarios)

| Requirement | Esc. | Evidencia |
|---|---|---|
| Almacenamiento hasheado (`$hidden`, nunca claro) | 2 | `ClaveCierreTest`: hash ≠ texto plano + `Hash::check`; sin `clave_cierre` en `GET /user` y `GET /users` |
| Formato PIN 4–8 | 2 | 422 con 3 dígitos / no numérico; 6 dígitos aceptado |
| Endpoint self-service | 5 | primera config; cambio exige `clave_actual`; actual incorrecta → 422 sin cambios; `{clave_configurada}`; 403 por rol no elegible |
| Validación en cadena (Q2/Q6) | 5 | claves de banca/master/super_master OK; clave de otra banca rechazada; sin candidatos con clave → 422 |

### Spec cierre-caja — delta (4 ADDED + 2 MODIFIED / 21 escenarios)

| Requirement | Esc. | Evidencia |
|---|---|---|
| Un cierre por día (re-cierre idempotente con clave) | 8 | primer cierre 201 `reclosed:false`; segundo 200 `reclosed:true` (misma fila, `fecha_fin` extendido, totales/desglose/faltante recalculados desde `fecha_inicio` original); idempotencia; auditoría `reclosed_by`/`reclosed_at`; límite de día → fila nueva; sin clave / incorrecta / sin candidatos → 422; multi-fila demo toma la última por `fecha_fin` |
| `cierre_hoy` en el preview | 2 | `null` sin cierre; `{id, fecha_inicio, fecha_fin}` con cierre |
| Período abierto explícito (UI) | 1 | `cierre.astro`: "Período ABIERTO: <inicio> → ahora" + badge; build + E2E manual |
| Reportes por rangos (UI, dos calendarios + listado + imprimir) | 3 | inputs `reporte-desde`/`reporte-hasta`, validación `desde ≤ hasta`, listado con desglose expandible, botón imprimir; E2E manual documentado |
| Reporte por rango con `cierres[]` shape completo | 5 | escenarios semanal existentes verdes + `test_semanal_cierres_incluye_shape_completo` (17 campos: desglose, arqueo, faltante, rate); totales y `ventana_cubierta` sin cambios |
| Impresión del cierre y del reporte | 2 | `print-cierre` previo; nuevo `print-reporte`/`generateReporteHtml` (smoke 10/10; E2E manual POS/fallback) |

### Decisiones AD-1…AD-15

Implementadas según el design y verificadas por los validadores de WU + spot-check final: bifurcación crear/actualizar en `CierreService` (AD-1/3/4), detección `[startOfDay, +1d)` Caracas (AD-2), arqueo con semántica de crear (AD-5), `users.clave_cierre` hash + `$hidden` (AD-6), cadena de la taquilla (AD-7/8), endpoints self-service con roles (AD-9/10), `cierre_hoy` aditivo (AD-11), shape completo `cierres[]` (AD-12), IPC `print-reporte` (AD-13), `showModal` input retrocompatible (AD-14), página del panel autónoma (AD-15).

## Findings

### CRITICAL — 0

Ninguno.

### WARNING — 2

1. **Q5 pendiente de negocio**: formato/longitud del PIN implementado como 4–8 dígitos (default 6 sugerido). Un cambio de negocio toca `digits_between` en 2 puntos y los `maxlength`/`pattern` de las 2 UIs.
2. **Cobertura manual-only en taquilla/panel**: `print-reporte`, la variante input de `showModal` y la página del panel se verifican por build + E2E manual documentado (sin runner en esos paquetes). Riesgo de regresión; pasos de E2E en apply-progress.

### SUGGESTION — 4

1. Las rutas `GET/PUT /usuarios/clave-cierre` quedaron fuera de `verify.mac` (el threat matrix del design las listaba dentro del grupo con MAC); funcionalmente equivalente porque `verify.mac` solo exige MAC al rol `taquilla` (no elegible). Alinear la nota del design al archivar.
2. El ejemplo PUT del design incluye `clave_nueva_confirma` en el body, pero la decisión 2.1 y `ClaveCierreController` lo excluyen (UI-only). Corregir el ejemplo en el archive.
3. `collections/` arrastra URLs pre-v1 en `Listar Cierres`/`Ver Cierre` (deuda pre-existente y fuera de alcance; los archivos de este ciclo usan `/api/v1`).
4. Rangos muy largos sin paginación en `GET /cierre/semanal` (open question del design; payload ≈1 KB por cierre, la UI consulta rangos operativos).

## Limitaciones y notas

- 2 skipped pre-existentes: `PluginIntegrationTest`, `ScrapeResultsJobTest`.
- Los 7 commits del ciclo están locales; se pushean al PR #12 tras el archive.
- Evidencia cruda: Engram `sdd/cierre-caja-ajustes/final-evidence` (full run + cobertura) y `sdd/cierre-caja-ajustes/apply-progress` (WU1–WU7).
