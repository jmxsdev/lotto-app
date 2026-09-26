# Archive Report — motor-premios

**Fecha de archivo**: 2026-09-26
**Artefacto store**: hybrid (openspec + engram)
**Worktree**: `/home/gzuz/Documentos/lotto-app-worktrees/investigacion-produccion` · Rama `feat/motor-premios-f3-estados` @ `6ecce9f`
**Veredicto del verify**: **PASS** (0 CRITICAL / 0 WARNING / 3 SUGGESTION)
**Ledger `gentle-ai sdd-attempt`**: settled `complete`
**Merge a main**: **PENDIENTE por decisión del usuario** — el cambio vive en la rama `feat/motor-premios-f3-estados` @ `6ecce9f`, NO merged, NO pusheado. Este archive no mergea ni hace push (restricción del encargo).

---

## Resumen del cambio

Motor de premios **config-driven por juego**: `config.premios` (`{base, modalidades, comodines}`) reemplaza
los multiplicadores hardcodeados de los plugins; normalización de acentos (`Texto::normalizar`),
liquidación de terminales (clave `numero`, 60×), signos por label/sigla, tipo estricto de tripletas,
comodines (MEGA/Selva/Guacharito/Guácharo/Patronus —incluido Patronus 75 + palabra = 140×—), juegos sin
fuente deshabilitados (`la-ricachona` `active=false`), redondeo único a 2 decimales, modalidades de un
solo sorteo con `selecciones[]` same-draw (Dupleta fuera de alcance), `premio_posible` real, estados de
apuesta (`ganadora`/`perdida`/`vencido`) con vencimiento 24 h configurable (roles `super_master`/`master`)
y reintento de búsqueda previo (catch-up `ScrapeResultsJob`), dedupe de resultados pre-H22 y contrato de
catálogo `premios`/`active`/`vendible` en `docs/juegos.json`.

Fases implementadas: **F1** núcleo (1.1–1.17), **F2** modalidades single-draw (2.1–2.2), **F3** estados/
vencimiento (3.1–3.5). 24/24 tareas completas.

## Veredicto del verify (estado FINAL al cierre)

Fuente: `verify-report.md` (2026-09-26, observación Engram #354, envelope `gentle-ai.verify-result/v1` `verdict: pass`) + hechos de estado final del orquestador. Los números que siguen son los del cierre, no los de snapshots intermedios.

| Métrica | Valor final |
|---------|-------------|
| Verdict | `pass` (envelope validado: `requirements 18/18`, `scenarios 29/29`) |
| Blockers | 0 |
| CRITICAL findings | 0 |
| Suite completa | **922 passed / 2 skipped / 0 failed** — 924 tests, 4201 assertions (`DB_DATABASE=lotto_test_motor php artisan test`, exit 0, hash `19f28706…`) |
| Regresión enfocada | **107/107 passed**, 1286 assertions |
| Build | `vendor/bin/pint --test` exit 0 (nunca se ejecutó `pint` sin `--test`) |
| Coverage | No disponible (sin tool de coverage configurada) |
| Spec compliance | 18 REQ / 29 escenarios mapeados — 29/29 COMPLIANT (motor-premios 23/23, catalogo-juegos 2/2, integracion-juego-incremental 4/4) |
| TDD compliance | 6/6 checks |

**Fix propio del cierre**: commit `6ecce9f` — "fix(motor-premios): excluye limites de juegos inactivos en la matriz y ajusta conteos (REQ7)" — excluye los límites de juegos inactivos vía scope `JuegoLimite::deJuegosActivos()` con conteos 20/40/80; `LimitesScopedApiTest` 30/30. Antes del fix la suite tenía 916 passed + 6 failed (REQ7, la-ricachona inactiva); ahora 922 passed = 916+6. Confirmado en el verify-report (al cierre, no en snapshots).

### SUGGESTIONs (3, ninguna bloqueante)

1. `ScrapeResultsJobTest::test_scrape_results_job_returns_early_for_non_scraper_game` (línea 147) usa `assertTrue(true)` — patrón smoke **PRE-EXISTENTE** (commit base `d250c20`, ajeno al cambio).
2. Persistencia de las 3 figuras de Tripleta (`figuras[]`) por los scrapers **fuera de alcance** (deviation F2 #4): el contrato de liquidación está definido y testeado, pero ningún scraper persiste ese shape hoy.
3. `composer test` excede el timeout de proceso de Composer (300 s) en esta máquina — el pipeline debe usar `php artisan test` directo o ajustar `COMPOSER_PROCESS_TIMEOUT`.

## Task Completion Gate

`tasks.md` archivado: **24/24 casillas `[x]`** (1.1–1.17, 2.1–2.2, 3.1–3.5) — sin tareas de implementación sin marcar. Cierre de Fases 2–3 en commit `2223d29` ("docs(sdd): marca F3 (3.1-3.5) y cierra las 24 tareas"). El verify-report confirma 24/24 contra `tasks.md` y el código real. **Nota de traza**: la observación Engram #258 (`sdd/motor-premios/tasks`) es el snapshot de la fase tasks (2026-09-17, Fases 2–3 sin marcar); el artefacto vivo es el archivo `tasks.md` actualizado por apply — autoritativo para el gate.

## Review Gate

Sin artefactos de review para este candidato (no existe `openspec/changes/motor-premios/reviews/`): `reviewGate` estructuralmente ausente → archive procede bajo política ordinaria. Ledger `gentle-ai sdd-attempt` settled `complete`.

## Specs sincronizadas a `openspec/specs/`

| Capability | Acción | Detalle |
|------------|--------|---------|
| motor-premios | **Creada** (spec completa, capability nueva) | Copia mecánica (`cp` + `diff -r` vacío) desde `openspec/changes/motor-premios/specs/motor-premios/spec.md` → `openspec/specs/motor-premios/spec.md` |
| catalogo-juegos | **Actualizada** (spec existente) | Delta `ADDED`: +1 requisito "Contrato de premiación en el catálogo" (2 escenarios) append al final de Requirements; "Lista maestra de juegos" preservado intacto |
| integracion-juego-incremental | **Actualizada** (spec existente) | Delta `MODIFIED`: requisito "Seeder por juego" reemplazado por la versión `config.premios` (4 escenarios, nota `(Previously: …)` conservada); los otros 3 requisitos preservados intactos |

Sin requisitos REMOVED → sin merge destructivo (no aplica `rules.archive` de aviso). `openspec/config.yaml` no está trackeado ni presente en este worktree (solo en el checkout principal); sus reglas de archive ("avisar antes de fusionar deltas destructivos") no se disparan.

## Movimiento a archive

`openspec/changes/motor-premios/` → `openspec/changes/archive/2026-09-26-motor-premios/` vía `git mv` (archivos trackeados). Readback mecánico obligatorio: `diff -r` snapshot-pre-move vs tree archivado → **vacío (sin diferencias)**, única evidencia de byte-identity. `archive-report.md` es aditivo y quedó excluido de la comparación.

## Trazabilidad Engram (observaciones leídas)

| Artefacto | Observación Engram |
|-----------|--------------------|
| proposal | #247 (`sdd/motor-premios/proposal`) |
| spec (delta specs) | #252 (`sdd/motor-premios/spec`) |
| design | #254 (`sdd/motor-premios/design`) |
| tasks (snapshot inicial; estale vs `tasks.md` vivo) | #258 (`sdd/motor-premios/tasks`) |
| verify-report | #354 (`sdd/motor-premios/verify-report`) |
| archive-report (este artefacto) | `sdd/motor-premios/archive-report` |

## Contenido del archive

- proposal.md ✅
- specs/{catalogo-juegos, integracion-juego-incremental, motor-premios}/spec.md ✅ (deltas verbatim)
- design.md ✅
- tasks.md ✅ (24/24)
- apply-progress.md ✅ (slices F1a–F1d, F2; F3 en obs Engram #266)
- explore.md ✅ (input de propose)
- verify-report.md ✅ (envelope `pass`)

## Pendientes post-archive

1. **Merge a main PENDIENTE por decisión del usuario**: el cambio vive en `feat/motor-premios-f3-estados` @ `6ecce9f`; merge y push quedan fuera de este archive.
2. SUGGESTION 1: reemplazar el `assertTrue(true)` pre-existente de `ScrapeResultsJobTest:147` por una aserción de estado (ajeno al cambio; puede hacerse en otro ciclo).
3. SUGGESTION 2: persistir `figuras[]` de Tripleta en los scrapers cuando la fuente provea el shape (fuera de alcance del cambio).
4. SUGGESTION 3: usar `php artisan test` en CI o ajustar `COMPOSER_PROCESS_TIMEOUT` (infraestructura).