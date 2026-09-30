```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:efdb60c5b73717bf4eb72fe04ca20950b48ea4c221e3b8e828ec1fa9cae3678d
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 3/3
scenarios: 6/6
test_command: composer test:parallel
test_exit_code: 0
test_output_hash: sha256:efdb60c5b73717bf4eb72fe04ca20950b48ea4c221e3b8e828ec1fa9cae3678d
build_command: vendor/bin/pint --test
build_exit_code: 0
build_output_hash: sha256:cd1a94fc2cf6a965b86e1a4809d6c7fb9148b1ee374e1010ed2ac96ff4876ec2
```
# Verify Report: mejoras-tooling (re-verify tras remediación)

**Change**: mejoras-tooling
**Version**: draft (spec `tests-paralelos`, 3 REQ / 6 escenarios)
**Mode**: Strict TDD
**Rama**: `feat/mejoras-tooling` (worktree exclusivo `/home/gzuz/Documentos/lotto-app-worktrees/mejoras-tooling`)
**Commit verificado**: `869521e` (HEAD) — incluye implementación (`f086741`, `f6270d6`, `0e1483d`, `5da7f2b`), verify FAIL previo `b47ca01`, remediación `3c98464` y su registro en apply-progress `869521e`
**Fecha**: 2026-09-30
**Verificación**: SOLO LECTURA sobre la implementación; sin fixes aplicados.
**Trazabilidad**: FAIL previo `b47ca01` (1 CRITICAL: flaky pre-existente `AgenciaScopeTest::test_agencia_reporte_ventas_solo_sus_taquillas` por colisión faker en `TaquillaFactory`) → remediación `3c98464` (`faker->unique()->word()` en TaquillaFactory) → este re-verify sobre `869521e`.

## Resumen ejecutivo

Re-verificación del cambio `mejoras-tooling` (tooling puro: paratest + script `test:parallel` + CI + runbook) tras la remediación del flaky pre-existente. La remediación está presente y es mínima: commit `3c98464`, 1 archivo, 1 línea (`TaquillaFactory` → `'name' => $this->faker->unique()->word().' Taquilla'`), sin tocar ningún archivo bajo `tests/` (diff completo de la rama: 0 archivos de test). **La corrida fresca de `composer test:parallel` de este re-verify pasó completa** (1071 tests, 1069 passed, 2 skipped, 6396 assertions, 485 688 ms ≈ 8.1 min, exit 0) con counts idénticos a las GREEN de apply — valida de forma independiente los 2 greens de la remediación (474 701 ms y 478 549 ms) y confirma la 3.ª corrida consecutiva verde (REQ-2 esc 2 cumplido). `vendor/bin/pint --test` → passed, exit 0 (hash idéntico al previo, determinista). 4/4 tareas verificadas contra el repo. **Verdict: PASS WITH WARNINGS** — el CRITICAL del FAIL previo está resuelto y verificado con evidencia runtime fresca e independiente; REQ-1 y REQ-2 cumplidos (4/6 escenarios COMPLIANT); REQ-3 (2 escenarios) clasificado honestamente como PARTIAL: el RED local reproduce el mecanismo de privilegio exacto de CI (MySQL 8.0, `1044 Access denied ... CREATE DATABASE`) y la estructura del workflow está verificada en el repo; solo el runtime dentro de GitHub Actions queda diferido post-merge (por diseño: el workflow no dispara en PRs).

## Completeness

| Métrica | Valor |
|---------|-------|
| Tasks total | 4 |
| Tasks complete | 4 |
| Tasks incomplete | 0 |

Tareas verificadas contra la realidad del repo (diff `origin/main...HEAD` = tooling + docs + openspec + remediación; 0 archivos bajo `tests/`):

| Tarea | Estado | Evidencia en repo (verificada) |
|-------|--------|-------------------------------|
| 1.1 Dep `brianium/paratest:^7.20.0` + lock | ✅ | `composer.json` require-dev L20; `composer show brianium/paratest` → v7.20.0; `composer.lock` regenerado (+217 líneas) |
| 2.1 Script `test:parallel` (4 procesos) | ✅ | `composer.json` L58-62: array espejo con `Composer\Config::disableProcessTimeout` (deviation documentada) + `config:clear @no_additional_args` + `@php artisan test --parallel --processes=4` |
| 3.1 CI paralelo + root + `MYSQL_ROOT_HOST: "%"` | ✅ | `ci-cd.yml`: L21 `MYSQL_ROOT_HOST: "%"`; paso PHPUnit L62-63 env `DB_USERNAME: root`/`DB_PASSWORD: root`; L64 `php artisan test --parallel --processes=4 --display-warnings --log-junit junit.xml`; timeout 60 min |
| 4.1 Runbook: comando + prerequisito `CREATE DATABASE` | ✅ | `docs/runbook-ops.md` L173-182: `composer test:parallel`, `GRANT ALL PRIVILEGES ON \`lotto_test_test\_%\`.*`, "Sin ese privilegio la corrida falla con `Access denied ... CREATE DATABASE`", override `-- --processes=N`, nota `-p`, persistencia de bases |
| Remediación flaky | ✅ | Commit `3c98464` presente: 1 archivo (`backend/database/factories/TaquillaFactory.php`), +1/-1: `'name' => $this->faker->unique()->word().' Taquilla'`. Sin otros archivos de test alterados (0 bajo `tests/` en el diff de la rama) |

## Build & Tests Execution

**Build (estilo, gate de CI)**: ✅ Passed
```text
$ vendor/bin/pint --test
{"tool":"pint","result":"passed"}   exit 0
```
sha256: `cd1a94fc2cf6a965b86e1a4809d6c7fb9148b1ee374e1010ed2ac96ff4876ec2` (idéntico al reporte previo — determinista)

**Tests**: ✅ 1069 passed / ❌ 0 failed / ⚠️ 2 skipped (pre-existentes, ajenas al cambio)

Corrida fresca del re-verify (`composer test:parallel`, evidencia primaria del envelope):
```text
{"tool":"paratest","result":"passed","tests":1071,"passed":1069,"assertions":6396,"duration_ms":485688,"skipped":2}
```
exit 0 · duración 485 688 ms (~8.1 min) · sha256 (output exacto del comando): `efdb60c5b73717bf4eb72fe04ca20950b48ea4c221e3b8e828ec1fa9cae3678d`

Corridas verdes consecutivas de la remediación (evidencia de apply-progress, sección «Remediación»):
- Corrida 1: 1071 tests, 1069 passed, 2 skipped, 6396 assertions, 474 701 ms, exit 0
- Corrida 2: 1071 tests, 1069 passed, 2 skipped, 6396 assertions, 478 549 ms, exit 0

→ **3 corridas consecutivas verdes** (apply ×2 + este re-verify) con counts idénticos: el flaky está resuelto y REQ-2 esc 2 se cumple con evidencia independiente.

**Cobertura**: ➖ No disponible — sin herramienta de cobertura configurada (`phpunit.xml` sin `<coverage>`; CI usa `coverage: none`). No es fallo; se omite.

## TDD Compliance

| Check | Result | Details |
|-------|--------|---------|
| TDD Evidence reported | ✅ | Tabla "TDD Cycle Evidence" presente en `apply-progress.md` (L14-16) |
| All tasks have tests | ✅ | 4/4 — sujeto = la propia suite (D5 del design: tooling sin lógica nueva; no aplican tests unitarios nuevos) |
| RED confirmed (tests exist) | ✅ | Suite existente (1071 tests) + evidencia runtime de apply (`1044 Access denied ... create database lotto_test_test_1`, 10.8 s) validada contra vendor y runbook (L180) |
| GREEN confirmed (tests pass) | ✅ | Corrida fresca del re-verify: exit 0 (1069 passed + 2 skipped, 6396 assertions) — la suite pasa completa en ejecución |
| Triangulation adequate | ➖ | N/A declarado por apply (tarea estructural de tooling); el "triángulo" es la doble corrida consecutiva → ahora 3 corridas consecutivas verdes |
| Safety Net for modified files | ✅ | 0 archivos de test modificados por el cambio (diff verificado); la remediación toca solo `TaquillaFactory.php` (factory, no test) |

**TDD Compliance**: 6/6 checks passed

## Test Layer Distribution

| Layer | Tests | Files | Tools |
|-------|-------|-------|-------|
| Unit (nuevos en el cambio) | 0 | 0 | — |
| Integration (nuevos en el cambio) | 0 | 0 | — |
| E2E (nuevos en el cambio) | 0 | 0 | — |
| Sujeto verificado (suite existente) | 1071 | suite completa | PHPUnit 12.5.31 + paratest 7.20.0 + MySQL 8.0 (4 workers) |
| **Total** | **1071** | — | — |

El cambio no añade tests (sujeto = la propia suite, D5). Capa de la evidencia runtime: Integración (paratest/MySQL 8.0).

## Changed File Coverage

Coverage analysis skipped — no coverage tool detected (`phpunit.xml` sin config de cobertura; CI `coverage: none`). No es fallo; los archivos cambiados son config/CI/docs/factory (1 línea), sin lógica ejecutable nueva que cubrir.

## Assertion Quality

Auditoría de archivos de test creados/modificados por el cambio: **ninguno** (diff verificado: 0 bajo `tests/`). Auditoría del test que motivó el FAIL previo (`AgenciaScopeTest::test_agencia_reporte_ventas_solo_sus_taquillas`, L605-619): las aserciones verifican comportamiento real (`assertContains`/`assertNotContains` sobre la respuesta JSON del API) — sin tautologías, ghost loops ni smoke tests. La causa del flaky era la **generación de datos de prueba** (`TaquillaFactory` sin `unique()`), ya corregida en `3c98464`; la calidad de las aserciones nunca estuvo en cuestión.

**Assertion quality**: ✅ All assertions verify real behavior

## Quality Metrics

**Linter (Pint)**: ✅ No errors — `vendor/bin/pint --test` exit 0 (proyecto completo, incluye archivos del cambio y la remediación)
**Type Checker**: ➖ No aplica (PHP, sin type checker estático configurado)

## Spec Compliance Matrix

| Requirement | Scenario | Test / Evidencia | Result |
|-------------|----------|------------------|--------|
| REQ-1 | Esc 1: Suite paralela completa y verde | `composer test:parallel` — corrida fresca exit 0 (1069+2, 6396 assertions, 485 688 ms) | ✅ COMPLIANT |
| REQ-1 | Esc 2: Privilegio local insuficiente falla claro | RED apply (`1044 Access denied ... create database lotto_test_test_1`) validado vs vendor + runbook (L180) | ✅ COMPLIANT |
| REQ-2 | Esc 1: Aislamiento sin contaminación entre procesos | 3 corridas paralelas verdes consecutivas (apply ×2 + re-verify); 4 bases `lotto_test_test_1..4` por worker; fallo del FAIL previo root-caused como colisión intra-test (no cross-process), eliminada por `unique()` | ✅ COMPLIANT |
| REQ-2 | Esc 2: Dos corridas consecutivas sin flakiness | 3 corridas consecutivas con counts idénticos (474 701 ms, 478 549 ms, 485 688 ms; todas exit 0) → no aparece ningún test flaky | ✅ COMPLIANT |
| REQ-3 | Esc 1: CI crea las bases por proceso | Mecanismo cubierto por runtime local (3 corridas paralelas verdes con bases worker `lotto_test_test_1..4` creadas y usadas por proceso) + estructura CI verificada (`MYSQL_ROOT_HOST: "%"` + root/root + `--parallel --processes=4`, ci-cd.yml L21, L62-64); runtime dentro de GitHub Actions post-merge (workflow solo dispara en push a main + dispatch; no en PRs) | ⚠️ PARTIAL |
| REQ-3 | Esc 2: CI falla ante privilegio insuficiente | RED local reproduce el fallo exacto del escenario sobre MySQL 8.0 (`1044 Access denied ... create database lotto_test_test_1`); el "no se construye ni publica ninguna imagen" es estructural (`build` `needs: tests`, ci-cd.yml L114): si tests falla, build no corre | ⚠️ PARTIAL |

**Compliance summary**: 4/6 escenarios compliant · 2 partial (REQ-3, runtime CI post-merge) · 0 failing · 0 untested

**Clasificación honesta de REQ-3**: en el reporte previo (FAIL) los 2 escenarios quedaron UNTESTED (runtime) porque el veredicto global era fail por el flaky. Con el CRITICAL resuelto y verificado (corrida fresca verde independiente), la evaluación de REQ-3 mejora a PARTIAL — no COMPLIANT pleno: el mecanismo está demostrado en runtime local (mismo MySQL 8.0, mismo paratest, mismas bases por proceso) y el workflow está verificado estructuralmente, pero la ejecución real dentro de GitHub Actions solo puede ocurrir post-merge (por diseño del proyecto: el workflow no corre en PRs; dispatch en rama prohibido porque el job `build` L112-132 publicaría `:latest` en GHCR sin guarda de ref). Esa corrida CI real queda como evidencia confirmatoria post-merge, documentada en Issues.

## Correctness (Static Evidence)

| Requirement | Status | Notes |
|------------|--------|-------|
| REQ-1 (ejecución paralela) | ✅ Implementado | paratest ^7.20.0 + script `test:parallel` con `--processes=4`; override `-- --processes=N`; `-p` documentada como fallo real |
| REQ-2 (aislamiento por proceso) | ✅ Implementado | paratest TEST_TOKEN → `lotto_test_test_{1..4}` (vendor `TestDatabases`); `phpunit.xml` sin cambios (D3 cumplida); bases persistidas y reutilizadas; colisión de nombres eliminada (`unique()` en TaquillaFactory) |
| REQ-3 (CI habilita bases por proceso) | ✅ Implementado (config) | root + `MYSQL_ROOT_HOST: "%"` + flags paralelos; evidencia runtime pendiente post-merge |

## Coherence (Design)

| Decisión | ¿Seguida? | Notas |
|----------|-----------|-------|
| D1 Credenciales CI: root + `MYSQL_ROOT_HOST: "%"` | ✅ Sí | ci-cd.yml L21 + L62-63 |
| D2 Script array espejo + N=4 + prohibir `-p` | ✅ Sí | `composer.json` L58-62; `-p` verificado que falla ("Test file 2 not found") — deviation ya documentada en apply (design decía "filtra en silencio"; realidad: falla explícita) |
| D3 Bases por proceso sin tocar `phpunit.xml` | ✅ Sí | vendor `TestDatabases` L198-208; `phpunit.xml` intacto |
| D4 Docs runbook | ✅ Sí | L173-182, sección «Entorno local (backend)» tras paso 11 |
| D5 TDD: sujeto = la propia suite | ✅ Sí | RED/GREEN evidenciados sobre la suite; sin tests unitarios nuevos |

Deviation adicional (aplicada por apply, correcta): `Composer\Config::disableProcessTimeout` en el script — sin él composer mataría la corrida a los 300 s; precedente del script `dev` existente.

## Issues Found

**CRITICAL (0)**: None — el CRITICAL del FAIL previo `b47ca01` (flaky por colisión faker en `TaquillaFactory`) está resuelto en `3c98464` y verificado con corrida fresca verde e independiente (3.ª consecutiva).

**WARNING (1)**:
- REQ-3 sin runtime CI: los 2 escenarios quedan PARTIAL (no COMPLIANT pleno). El mecanismo está demostrado en runtime local (mismo MySQL 8.0 y paratest; bases worker creadas y usadas) y el workflow verificado estructuralmente; la corrida real dentro de GitHub Actions es post-merge (el workflow no dispara en PRs; dispatch en rama publicaría `:latest` → prohibido). No blocking — diferido por diseño del proyecto (design.md Verificación: "run post-merge").

**SUGGESTION (2)**:
- Evaluar agregar `pull_request` al trigger del workflow (pregunta abierta del design) para obtener evidencia CI en PRs sin publicar imágenes — con `concurrency` y sin el job `build` en PRs. Cerraría los 2 escenarios REQ-3 con runtime.
- Tras el merge, registrar en el próximo verify/archive la corrida CI real (REQ-3 esc 1) como evidencia definitiva de la suite paralela en GitHub Actions.

### Verdict

**PASS WITH WARNINGS** — el flaky pre-existente que causó el FAIL previo está remediado (`3c98464`, 1 línea) y este re-verify lo confirma con una corrida fresca `composer test:parallel` verde (1069+2, 6396 assertions, 485 688 ms, exit 0): 3 corridas consecutivas verdes con counts idénticos (REQ-2 esc 2 cumplido). 4/4 tareas + remediación verificadas contra el repo, pint limpio, 0 CRITICAL. REQ-1 y REQ-2 PASS (4/6 escenarios COMPLIANT); las WARNINGS son los 2 escenarios REQ-3 en PARTIAL (mecanismo demostrado en runtime local + estructura CI verificada; corrida real post-merge, por diseño del workflow). Reporte archive-ready.