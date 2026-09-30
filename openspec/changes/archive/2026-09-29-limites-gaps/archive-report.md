# Archive Report — limites-gaps

**Fecha de archivo**: 2026-09-30
**Artefacto store**: hybrid (openspec + engram)
**Worktree**: `/home/gzuz/Documentos/lotto-app-worktrees/limites-gaps` · Rama `feat/limites-gaps` @ `2c857ff` (verify-report) → este commit de archive
**Veredicto del verify**: **PASS WITH WARNINGS** (0 CRITICAL / 0 defectos WARNING / 1 SUGGESTION)
**Merge a main**: **PENDIENTE por decisión del usuario** — el cambio vive en la rama `feat/limites-gaps` @ `2c857ff`, NO merged, NO pusheado. Entrega planificada: PRs encadenados `stacked-to-main` (4 slices, WU1→WU4) — aún NO creados. Este archive no mergea ni hace push (restricción del encargo).

---

## Resumen del cambio

Cierre de gaps de configuración de límites de juego (`juego × moneda`) en la jerarquía banca → grupo → taquilla. Retira los campos dormidos `fraccion`/`limite_tiempo` de API y UI (BD intacta, sin migración), alinea los roles de escritura UI↔API (`super_master|master|banca` escriben; `grupo`/`agencia` solo lectura), añade la acción "Limpiar" por fila (DELETE), elimina `agencia_id` del endpoint legacy `GET /limites/{juego}` (hoy 500 SQL), borra código muerto (`authorizeLimitesWrite`, `LimitesTable.astro`) y fija la nav `/limites` por rol. **Non-goals respetados**: zona premios y `taquilla/*` intactos; `porcentaje_pago`/`participacion` intactos (ciclo COMISIONES); sin migración ni cambios de BD; las columnas `fraccion`/`limite_tiempo` permanecen en BD con `JuegoLimite::$hidden`.

Implementación: 4 work units (WU1 retiro de campos, WU2 `agencia_id` + código muerto, WU3 Limpiar por fila en el panel, WU4 roles/nav/componente muerto). **18/18 tareas completas**.

## Veredicto del verify (estado FINAL al cierre)

Fuente: `verify-report.md` (commit `2c857ff`, observación Engram #466, envelope `gentle-ai.verify-result/v1` `verdict: pass_with_warnings`, `requirements 8/8`, `scenarios 15/15`) + hechos de estado final del orquestador. Los números que siguen son los del cierre, no los de snapshots intermedios.

| Métrica | Valor final |
|---------|-------------|
| Verdict | `pass_with_warnings` (validado: `gentle-ai sdd-verify-validate --requirements 8 --scenarios 15` → valid, verdict pass_with_warnings) |
| Blockers | 0 |
| CRITICAL findings | 0 |
| Suite completa (backend) | **1081 tests / 1079 passed / 0 failed / 2 skipped ambientales / 6432 assertions** (`DB_DATABASE=lotto_test_limites php artisan test`, exit 0, 1887905 ms) |
| Build (panel) | **`pnpm run build` exit 0** — 26 páginas, 3.19s |
| Spec compliance | 8 REQ / 15 escenarios — 7 COMPLIANT por test runtime, 8 PARTIAL (verificación visual del panel) |
| BD de tests | **`lotto_test_limites`** (privada del ciclo; decisión 2026-09-29 por contención con la sesión `comisiones` sobre `lotto_test_motor`; `phpunit.xml` default `lotto_test`) |

Los 2 skipped son ambientales preexistentes, ajenos al cambio: `ScrapeResultsJobTest:141` y `PluginIntegrationTest:28`.

### SUGGESTION (1, no bloqueante)

`collections/Limites/Configurar Limite.yml:24-25` y `collections/Limites/Configurar Limite (Batch).yml:27-28,38-39` aún listan `fraccion`/`limite_tiempo` como ejemplos de payload. Eran la open question del design (fuera de alcance); los payloads siguen funcionando (campos ignorados sin error), pero los ejemplos documentan campos retirados. Actualizarlos en un ciclo futuro o junto con la remediación.

### QA manual pendiente (gates humanos — no hay runner de panel)

1. **WU3 — Limpiar por fila**: (a) fila propia → botón → confirm → `DELETE /api/v1/limites/{id}` → celda "hereda de …"; (b) celda heredada sin botón; (c) create mode sin botón; (d) 403/404 → modal de error (404 recarga).
2. **WU4 — Gates por rol**: grupo/agencia tabla en lectura (inputs `disabled`, sin Guardar/Limpiar) en `/limites` y detalle pages; sm/master/banca operativos; taquilla "Sin acceso".
3. **WU4 — Nav**: `/limites` visible a sm/master/banca; oculta a grupo/agencia/taquilla.

## Decisiones (D1–D5, de proposal.md)

| Decisión | Contenido |
|---|---|
| **D1** | Este ciclo posee `fraccion`/`limite_tiempo`; `porcentaje_pago`/`participacion` son de COMISIONES (no tocar). Cero lectores de negocio de los dormidos. |
| **D2** | Retirar `fraccion`/`limite_tiempo` de UI y API (BD intacta, sin migración); columnas quedan en BD. Elimina de raíz el bug del checkbox (no persiste `false`; 422 por ítem vacío). |
| **D3** | Alinear roles UI↔API: Guardar/Limpiar solo `super_master|master|banca`; `grupo`/`agencia` solo lectura; corregir gates muertos; nav `/limites` a 3 roles. |
| **D4** | Eliminar `agencia_id` del legacy `GET /limites/{juego}` (sin deprecar el endpoint); usos legítimos de taquillas intactos. |
| **D5** | Botón "Limpiar" por fila → `DELETE /api/v1/limites/{limite}` existente; la celda vuelve a heredar del ancestro; no se cambia la semántica de celda vacía ni batch null→borra-fila. |

Design A1–A9: seguidas al 100% (verificado en verify-report, tabla Coherence).

## Task Completion Gate

`tasks.md` archivado: **18/18 casillas `[x]`** (1.1–1.5, 2.1–2.3, 3.1–3.5, 4.1–4.5) — sin tareas de implementación sin marcar. **Nota de traza**: la observación Engram #455 (`sdd/limites-gaps/tasks`) es el snapshot de la fase sdd-tasks (2026-09-29, con casillas `[ ]`); el artefacto vivo es el archivo `tasks.md` actualizado por apply — autoritativo para el gate.

## Review Gate

Sin artefactos de review para este candidato (no existe `openspec/changes/limites-gaps/reviews/`): `reviewGate` estructuralmente ausente → archive procede bajo política ordinaria.

## Specs sincronizadas a `openspec/specs/`

| Capability | Acción | Detalle |
|------------|--------|---------|
| limites | **Creada** (spec principal, capability nueva) | `openspec/changes/limites-gaps/specs/limites/spec.md` → `openspec/specs/limites/spec.md`. Delta solo `ADDED` → no destructivo (no aplica `rules.archive` de aviso). Transformación de framing mecánica vía `sed` (solo 2 líneas: título `# Delta for limites` → `# limites Specification`; `## ADDED Requirements` → `## Requirements`, convención de specs principales p. ej. `motor-premios`); `diff` verbatim muestra ÚNICAMENTE esas 2 líneas, el resto byte-idéntico. 8 requisitos / 15 escenarios. |

Sin requisitos REMOVED ni MODIFIED → sin merge destructivo. `openspec/config.yaml` (`rules.archive`: "avisar antes de fusionar deltas destructivos") no se dispara.

## Movimiento a archive

`openspec/changes/limites-gaps/` → `openspec/changes/archive/2026-09-29-limites-gaps/` vía `git mv` (6 archivos trackeados: design, explore, proposal, specs/limites/spec, tasks, verify-report). Readback mecánico obligatorio: `diff -r` snapshot-pre-move (recursivo) vs tree archivado → **vacío (exit 0, sin diferencias)**, única evidencia de byte-identity. `archive-report.md` (este archivo) es aditivo y quedó excluido de la comparación (no existía en el snapshot).

## Trazabilidad Engram (observaciones leídas)

| Artefacto | Observación Engram |
|-----------|--------------------|
| proposal | #447 (`sdd/limites-gaps/proposal`) |
| spec (delta) | #449 (`sdd/limites-gaps/spec`) |
| design | #452 (`sdd/limites-gaps/design`) |
| tasks (snapshot inicial; stale vs `tasks.md` vivo) | #455 (`sdd/limites-gaps/tasks`) |
| verify-report | #466 (`sdd/limites-gaps/verify-report`) |
| archive-report (este artefacto) | `sdd/limites-gaps/archive-report` |

## Commits de la rama

| Commit | Contenido |
|--------|-----------|
| `65ee9df` | docs(sdd): exploración del cambio limites-gaps |
| `9115673` | docs(sdd): propuesta del cambio limites-gaps |
| `1fc3643` | docs(sdd): specs del cambio limites-gaps |
| `90044d4` | docs(sdd): diseño del cambio limites-gaps |
| `9b813c3` | docs(sdd): tareas del cambio limites-gaps |
| `3f2dfe6` | refactor(limites): retira fraccion y limite_tiempo de la API (WU1) |
| `2cc6693` | fix(limites): ignora agencia_id en el endpoint legacy y elimina authorizeLimitesWrite (WU2) |
| `181fb48` | feat(panel): agregar Limpiar por fila y retirar columnas dormidas en limites (WU3) |
| `9e5cf27` | feat(panel): alinea roles y nav de limites y retira LimitesTable muerto (WU4) |
| `2c857ff` | docs(sdd): verify-report del cambio limites-gaps |
| este commit | docs(sdd): archiva limites-gaps (18/18, verify PASS WITH WARNINGS) |

## Contenido del archive

- proposal.md ✅ (D1–D5)
- specs/limites/spec.md ✅ (delta verbatim)
- design.md ✅ (A1–A9)
- tasks.md ✅ (18/18)
- explore.md ✅ (input de propose)
- verify-report.md ✅ (envelope `pass_with_warnings`)
- archive-report.md ✅ (este archivo, aditivo)

## Pendientes post-archive

1. **QA manual del panel PENDIENTE** (gate humano): 3 gates del verify-report — flujo Limpiar (WU3), gates por rol (WU4), nav por rol (WU4). El verify es PASS WITH WARNINGS con 0 CRITICAL; la confirmación visual humana es el único pendiente.
2. **Entrega planificada NO ejecutada**: PRs encadenados `stacked-to-main` (4 slices WU1→WU4) — aún NO creados; la rama `feat/limites-gaps` no está merged ni pusheada.
3. SUGGESTION: actualizar `collections/Limites/*.yml` (ejemplos con `fraccion`/`limite_tiempo` retirados) — follow-up futuro.
4. Follow-up del design (fuera de alcance): asimetría backend del POST de entidad que aún permite límites iniciales a grupo/agencia.