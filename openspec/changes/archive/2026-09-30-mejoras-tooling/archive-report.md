# Archive Report — mejoras-tooling

**Fecha de archivo**: 2026-09-30
**Artefacto store**: hybrid (openspec + engram)
**Worktree**: `/home/gzuz/Documentos/lotto-app-worktrees/mejoras-tooling` · Rama `feat/mejoras-tooling` @ `9015ddd`
**Veredicto del verify (final)**: **PASS WITH WARNINGS** (0 CRITICAL / 1 WARNING / 2 SUGGESTION)
**Ledger `gentle-ai sdd-attempt`**: settled final `complete`
**Merge a main**: **PENDIENTE por decisión del usuario** — el cambio vive en la rama `feat/mejoras-tooling` @ `9015ddd` (14 commits sobre `origin/main`, sin push). Este archive no mergea ni hace push (restricción del encargo).

---

## Resumen del cambio

Tooling puro, sin dominio nuevo: paralelización de la suite de tests del backend con
`brianium/paratest:^7.20.0` (única versión compatible con PHP 8.3 + PHPUnit 12.5.31; v7.21+ exige
PHP 8.4/PHPUnit 13). Cambio aditivo: dependencia + script `composer test:parallel` (array espejo de
`test` con `config:clear` + `php artisan test --parallel --processes=4`; override `-- --processes=N`;
`-p` documentada como fallo real "Test file 2 not found"), CI paralelo en `.github/workflows/ci-cd.yml`
(`MYSQL_ROOT_HOST: "%"` + `DB_USERNAME: root`/`DB_PASSWORD: root` + paso PHPUnit con
`--parallel --processes=4 --display-warnings --log-junit junit.xml`), y nota de uso/prerrequisito
`CREATE DATABASE` en `docs/runbook-ops.md` (con `GRANT ALL PRIVILEGES ON \`lotto_test_test\_%\`.*`).

**Impacto medido**: `composer test:parallel` ~8 min por corrida vs ~35-50 min secuencial (~5× más
rápida), distribuyendo el costo dominante de migrate + seed (52 llamadas a `DatabaseSeeder` encadenando
25 seeders) entre 4 workers (`TEST_TOKEN` → bases `lotto_test_test_1..4`).

Fases implementadas: **1** dependencia, **2** script, **3** CI, **4** runbook. 4/4 tareas completas.

## Veredicto del verify (estado FINAL al cierre)

Fuente: hechos de estado final del orquestador (más recientes) + `verify-report.md` final (commit
`9015ddd`, observación Engram #470, envelope `gentle-ai.verify-result/v1` `verdict: pass_with_warnings`,
`requirements 3/3`, `scenarios 6/6`). Los números que siguen son los del cierre, no los de snapshots
intermedios.

| Métrica | Valor final |
|---------|-------------|
| Verdict | `pass_with_warnings` (envelope validado: `requirements 3/3`, `scenarios 6/6`, `blockers 0`, `critical_findings 0`) |
| Corrida fresca (re-verify final) | **1071 tests / 1069 passed / 2 skipped / 6396 assertions**, 485 688 ms (~8.1 min), exit 0, hash `efdb60c5…` |
| Corridas previas verdes | apply ×2 (474 701 ms y 478 549 ms) + remediación ×2 (620.9 s y 605.7 s en apply inicial) |
| Consecutivas verdes | **3 consecutivas sin flakiness** (remediación ×2 + re-verify final), counts idénticos → REQ-2 esc 2 cumplido |
| Build | `vendor/bin/pint --test` → passed, exit 0 (hash `cd1a94fc…`, determinista) |
| Coverage | No disponible (sin tool de coverage configurada; CI `coverage: none`) |
| Spec compliance | 4/6 escenarios COMPLIANT (REQ-1 ×2, REQ-2 ×2) · 2/6 PARTIAL (REQ-3, runtime CI post-merge) · 0 failing · 0 untested |
| TDD compliance | 6/6 checks |
| Tasks | 4/4 verificadas contra el repo |

**Remediación incluida (flaky PREEXISTENTE)**: commit `3c98464` — `fix(tests): nombre unico en
TaquillaFactory (flaky preexistente)` — `'name' => $this->faker->unique()->word().' Taquilla'` en
`backend/database/factories/TaquillaFactory.php` (1 archivo, 1 línea). Erradica la colisión de nombres
de faker (P≈0.894 %/corrida) que causó el FAIL previo `b47ca01` (`AgenciaScopeTest::test_agencia_reporte_ventas_solo_sus_taquillas`
agrupa por `taquillas.name`). Beneficia a TODO el repo, no solo a este cambio; sin tocar archivos bajo
`tests/` (diff de la rama: 0 archivos de test).

### WARNING (1, no bloqueante)

- **REQ-3 sin runtime CI** (2 escenarios PARTIAL): el mecanismo está demostrado en runtime local (mismo
  MySQL 8.0 + paratest; bases worker `lotto_test_test_1..4` creadas y usadas) y la estructura del
  workflow verificada en el repo (`MYSQL_ROOT_HOST: "%"` L21, root/root L62-63, `--parallel --processes=4`
  L64; `build` `needs: tests` L114 — si tests falla, no se construye ni publica imagen). La corrida real
  dentro de GitHub Actions es **post-merge**: el workflow no dispara en PRs, y el dispatch en rama está
  prohibido porque el job `build` publicaría `:latest` en GHCR sin guarda de ref. Diferido por diseño del
  proyecto (design.md Verificación: "run post-merge"). Nota: el cambio quedó archivado en una rama sin
  pushear; la evidencia CI (REQ-3) se completa post-merge.

### SUGGESTIONs (2)

1. Evaluar agregar `pull_request` al trigger del workflow (pregunta abierta del design) con `concurrency`
   y sin el job `build` en PRs — cerraría los 2 escenarios REQ-3 con runtime.
2. Tras el merge, registrar en el próximo verify/archive la corrida CI real (REQ-3 esc 1) como evidencia
   definitiva de la suite paralela en GitHub Actions.

## Task Completion Gate

`tasks.md` archivado: **4/4 casillas `[x]`** (1.1, 2.1, 3.1, 4.1) — sin tareas de implementación sin
marcar. apply-progress y verify-report confirman las 4 contra el código real. **Nota de traza**: la
observación Engram #450 (`sdd/mejoras-tooling/tasks`) es el snapshot de la fase tasks (2026-09-29, sin
casillas); el artefacto vivo es el archivo `tasks.md` actualizado por apply — autoritativo para el gate.

## Review Gate

Sin artefactos de review para este candidato (`reviewGate` estructuralmente ausente): archive procede
bajo política ordinaria del repositorio. Ledger `gentle-ai sdd-attempt` settled final `complete`.
**Entrega (PR/merge) PENDIENTE por decisión del usuario.**

## Specs sincronizadas a `openspec/specs/`

| Capability | Acción | Detalle |
|------------|--------|---------|
| tests-paralelos | **Creada** (spec completa, capability NUEVA) | Copia mecánica (`cp` a temp + `diff -r` vacío + `mv`) desde `openspec/changes/mejoras-tooling/specs/tests-paralelos/spec.md` → `openspec/specs/tests-paralelos/spec.md` — 3 REQ (MUST) / 6 escenarios Given/When/Then, byte-idéntica |

Sin requisitos REMOVED/MODIFIED → sin merge destructivo (no aplica `rules.archive` de aviso).

## Movimiento a archive

`openspec/changes/mejoras-tooling/` → `openspec/changes/archive/2026-09-30-mejoras-tooling/` vía
`git mv` (7 archivos trackeados). Readback mecánico obligatorio: `diff -r` snapshot-pre-move vs tree
archivado → **vacío (sin diferencias)**, única evidencia de byte-identity. `archive-report.md` es
aditivo y quedó excluido de la comparación. El directorio de cambios activos ya no contiene
`mejoras-tooling`.

## Trazabilidad Engram (observaciones leídas)

| Artefacto | Observación Engram |
|-----------|--------------------|
| explore | #431 (`sdd/mejoras-tooling/explore`) |
| proposal | #434 (`sdd/mejoras-tooling/proposal`) |
| spec (delta specs) | #439 (`sdd/mejoras-tooling/spec`) |
| design | #445 (`sdd/mejoras-tooling/design`) |
| tasks (snapshot inicial; stale vs `tasks.md` vivo) | #450 (`sdd/mejoras-tooling/tasks`) |
| apply-progress (incluye Remediación) | #467 (`sdd/mejoras-tooling/apply-progress`) |
| verify-report (final) | #470 (`sdd/mejoras-tooling/verify-report`) |
| archive-report (este artefacto) | `sdd/mejoras-tooling/archive-report` |

## Contenido del archive

- proposal.md ✅
- specs/tests-paralelos/spec.md ✅ (delta verbatim, 3 REQ / 6 escenarios)
- design.md ✅
- tasks.md ✅ (4/4)
- apply-progress.md ✅ (incluye sección «Remediación (flaky fix)»)
- explore.md ✅ (input de propose)
- verify-report.md ✅ (envelope `pass_with_warnings`)

## Cadena de commits (14 sobre `origin/main`, sin push)

`c6d2a5c` explore → `e28de41` proposal → `1217c32` specs → `a7245fe` design → `04f5b78` tasks →
`f086741` paratest → `f6270d6` script → `0e1483d` CI → `5da7f2b` runbook → `c96f522` cierre apply →
`b47ca01` verify FAIL → `3c98464` fix flaky → `869521e` remediación → `9015ddd` verify final.

## Pendientes post-archive

1. **Merge a main PENDIENTE por decisión del usuario**: el cambio vive en `feat/mejoras-tooling` @
   `9015ddd` (sin push); merge y push quedan fuera de este archive.
2. **Evidencia CI (REQ-3) post-merge**: registrar la corrida real en GitHub Actions como evidencia
   definitiva (WARNING 1 / SUGGESTION 2).
3. SUGGESTION 1: evaluar trigger `pull_request` sin job `build` para evidencia CI en PRs.