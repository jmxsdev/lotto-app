```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:df641540054150fa2442d25ab6818ef1f6989f05449609d1baefc58658097cd2
verdict: fail
blockers: 1
critical_findings: 1
requirements: 1/3
scenarios: 2/6
test_command: composer test:parallel
test_exit_code: 1
test_output_hash: sha256:97107c42410cc62a14eb6fa86a8cfa72f05495a671cdd55e8ce7e5e45b4d7bae
build_command: vendor/bin/pint --test
build_exit_code: 0
build_output_hash: sha256:cd1a94fc2cf6a965b86e1a4809d6c7fb9148b1ee374e1010ed2ac96ff4876ec2
```
# Verify Report: mejoras-tooling

**Change**: mejoras-tooling
**Version**: draft (spec `tests-paralelos`, 3 REQ / 6 escenarios)
**Mode**: Strict TDD
**Rama**: `feat/mejoras-tooling` (worktree exclusivo `/home/gzuz/Documentos/lotto-app-worktrees/mejoras-tooling`)
**Commit verificado**: `c96f522` (HEAD) — `docs(sdd): aplica y verifica mejoras-tooling (tasks 1-4, apply-progress)`; implementación en `f086741` (paratest), `f6270d6` (script), `0e1483d` (CI), `5da7f2b` (runbook)
**Fecha**: 2026-09-30
**Verificación**: SOLO LECTURA sobre la implementación; sin fixes aplicados.

## Resumen ejecutivo

Cambio de tooling puro (paratest + script + CI + runbook), implementado 4/4 tareas según `tasks.md` y con TDD cycle evidence honesto en `apply-progress.md`. El mecanismo paralelo **funciona**: la corrida 2 de `composer test:parallel` pasó completa (1071 tests, 1069 passed, 2 skipped, 6396 assertions, 531.3 s, exit 0) con las 4 bases `lotto_test_test_1..4` por worker, y `vendor/bin/pint --test` pasa limpio. **Sin embargo, la corrida 1 falló** (exit 1) en `AgenciaScopeTest::test_agencia_reporte_ventas_solo_sus_taquillas` por una **colisión de nombres faker pre-existente** (TaquillaFactory usa `faker->word.' Taquilla'` sin `unique()`; P≈0.894 %/corrida medida con 300k muestras sobre 182 palabras únicas). El test y la factory **no fueron tocados por el cambio** (diff de la rama: 0 archivos de test), pero el criterio de éxito del cambio ("sin tests flaky en 2 corridas consecutivas") y el escenario REQ-2 esc 2 ("no aparece ningún test flaky") **no se cumplen**: corrida 1 vs corrida 2 difieren. **Verdict: FAIL** — el reporte no es archive-ready hasta resolver el flaky (fix de factory = cambio aparte; no se aplica aquí).

## Completeness

| Métrica | Valor |
|---------|-------|
| Tasks total | 4 |
| Tasks complete | 4 |
| Tasks incomplete | 0 |

Tareas verificadas contra la realidad del repo (diff `f086741~1...HEAD` = solo tooling: `ci-cd.yml` +7, `composer.json` +6, `composer.lock` +217, `runbook-ops.md` +11, tasks/apply-progress):

| Tarea | Estado | Evidencia en repo (verificada) |
|-------|--------|-------------------------------|
| 1.1 Dep `brianium/paratest:^7.20.0` + lock | ✅ | `composer.json` require-dev L20; `composer.lock` L6593-6594 `v7.20.0`; PHPUnit `12.5.31` L7991 sin conflicto (pin correcto: 7.21+ exige PHP 8.4/PHPUnit 13) |
| 2.1 Script `test:parallel` (4 procesos) | ✅ | `composer.json` L58-62: array espejo con `Composer\Config::disableProcessTimeout` (deviation documentada en apply) + `config:clear @no_additional_args` + `@php artisan test --parallel --processes=4`. `-p 2` verificado de primera mano: `Test file "2" not found` (TestSuiteBuilder L51) |
| 3.1 CI paralelo + root + `MYSQL_ROOT_HOST: "%"` | ✅ | `ci-cd.yml`: L21 `MYSQL_ROOT_HOST: "%"`; paso PHPUnit L62-63 env `DB_USERNAME: root`/`DB_PASSWORD: root`; L64 `php artisan test --parallel --processes=4 --display-warnings --log-junit junit.xml`; timeout 60 min |
| 4.1 Runbook: comando + prerequisito `CREATE DATABASE` | ✅ | `docs/runbook-ops.md` L168-182: `composer test:parallel`, `GRANT ALL PRIVILEGES ON \`lotto_test_test\_%\`.*`, "Sin ese privilegio la corrida falla con `Access denied ... CREATE DATABASE`", override `-- --processes=N`, nota `-p`, persistencia de bases |

## Build & Tests Execution

**Build (estilo, gate de CI)**: ✅ Passed
```text
$ vendor/bin/pint --test
{"tool":"pint","result":"passed"}   exit 0
```
sha256: `cd1a94fc2cf6a965b86e1a4809d6c7fb9148b1ee374e1010ed2ac96ff4876ec2`

**Tests**: ✅ Corrida 2: 1069 passed / ❌ Corrida 1: 1 failed / ⚠️ 2 skipped (pre-existentes, ajenas al cambio)

Corrida 1 (`composer test:parallel`, evidencia primaria del envelope):
```text
{"tool":"paratest","result":"failed","tests":1071,"passed":1068,"assertions":6396,"duration_ms":534342,"failed":1,
 "failures":[{"test":"Tests\\Feature\\AgenciaScopeTest::test_agencia_reporte_ventas_solo_sus_taquillas",
 "file":"backend/tests/Feature/AgenciaScopeTest.php","line":605,
 "message":"Failed asserting that an array does not contain 'perferendis Taquilla'."}],"skipped":2}
```
exit 1 · duración 534 342 ms (~8.9 min) · sha256: `97107c42410cc62a14eb6fa86a8cfa72f05495a671cdd55e8ce7e5e45b4d7bae`

Corrida 2 (`composer test:parallel`, re-corrida de confirmación):
```text
{"tool":"paratest","result":"passed","tests":1071,"passed":1069,"assertions":6396,"duration_ms":531282,"skipped":2}
```
exit 0 · duración 531 282 ms (~8.9 min) · sha256: `d78aac9bbd5190c1b62d7cf0f55dbd4ab943d2928d82fc7157ffe288ccf5d720`

Re-runs del test aislado (`php artisan test --filter test_agencia_reporte_ventas_solo_sus_taquillas`): 4/4 passed (92.0 s, 81.3 s, 77.9 s, 100.0 s) → el fallo NO es determinista.

**Análisis de causa raíz del fallo (corrida 1)**:
- El test crea `taquilla1` y `taquillaAjena` con `TaquillaFactory` (`'name' => $this->faker->word.' Taquilla'`, sin `unique()`). El reporte `ventas-totales?nivel=taquilla` incluye legítimamente a `taquilla1` (en scope del usuario agencia; `buildApuestaQuery` filtra `whereHas('taquilla', agencia_id)`, verificado en `ReporteController` L32-35 y `ApuestaService::ventasTotales` L496+). Si `taquillaAjena` colisiona de nombre con `taquilla1` (ambas 'perferendis Taquilla'), `assertNotContains` falla en falso aunque el scope sea correcto.
- Probabilidad medida (300 000 muestras con el faker del repo): lista de 182 palabras únicas → **P(colisión) = 0.894 % por corrida**. Consistente con 1 fallo en 6 ejecuciones observadas.
- **No es contaminación entre procesos** (REQ-2 esc 1 intacto): el mecanismo es intra-test (misma corrida, mismo proceso); la corrida 2 con las mismas bases persistentes pasó completa con counts idénticos a las GREEN de apply (1069+2, 6396 assertions).
- **Pre-existente**: `git diff f086741~1...HEAD --name-only` → 0 archivos bajo `backend/tests/`, 0 toques a `TaquillaFactory`. El cambio (tooling) no lo introdujo.

**RED (validación de evidencia de apply contra el contrato del spec)**: NO re-ejecutado (recreación confundida: las bases worker `lotto_test_test_1..4` ya existen y el código de vendor solo ejecuta `CREATE DATABASE` cuando la base no existe — `ensureTestDatabaseExists` L96-111 de `vendor/laravel/framework/src/Illuminate/Testing/Concerns/TestDatabases.php`; recrear exigiría dropear bases worker = mutación no-trivial del entorno dev). Validación por triplicado:
1. Evidencia de apply: `SQLSTATE[42000] 1044 Access denied for user 'sdd_red_user'@'%' ... SQL: create database lotto_test_test_1` — cumple el contrato de REQ-1 esc 2 / REQ-2 esc 1 ("error de privilegio explícito").
2. Código de vendor: `testDatabase()` L198-208 `"{$database}_test_{$token}"` → `createDatabase` requiere privilegio CREATE (mecanismo del RED).
3. Runbook L180 documenta el prerrequisito: "Sin ese privilegio la corrida falla con `Access denied ... CREATE DATABASE`".
Sin usuarios residuales en MySQL (`SELECT user FROM mysql.user WHERE user LIKE '%sdd%' OR user LIKE '%verify%'` → vacío): cleanup de apply limpio.

**Cobertura**: ➖ No disponible — sin herramienta de cobertura configurada (`phpunit.xml` sin `<coverage>`; CI usa `coverage: none`). No es fallo; se omite.

## TDD Compliance

| Check | Result | Details |
|-------|--------|---------|
| TDD Evidence reported | ✅ | Tabla "TDD Cycle Evidence" presente en `apply-progress.md` |
| All tasks have tests | ✅ | 4/4 — sujeto = la propia suite (D5 del design: tooling sin lógica nueva; no aplican tests unitarios nuevos) |
| RED confirmed (tests exist) | ✅ | Suite existente (1071 tests) + evidencia runtime de apply (`1044 Access denied ... create database lotto_test_test_1`, 10.8 s) validada contra vendor y runbook |
| GREEN confirmed (tests pass) | ❌ | Corrida 1 falló (flaky pre-existente); corrida 2 pasó (1069 passed). GREEN NO es estable — ver CRITICAL |
| Triangulation adequate | ⚠️ | N/A declarado por apply (tooling estructural); el "triángulo" es la doble corrida consecutiva — la corrida 1 de verify la rompe (flaky) |
| Safety Net for modified files | ✅ | 0 archivos de test modificados por el cambio (diff verificado); suite secuencial previa verde en repo |

**TDD Compliance**: 5/6 checks passed

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

Coverage analysis skipped — no coverage tool detected (`phpunit.xml` sin config de cobertura; CI `coverage: none`). No es fallo; los archivos cambiados son config/CI/docs, sin lógica ejecutable nueva que cubrir.

## Assertion Quality

Auditoría de archivos de test creados/modificados por el cambio: **ninguno** (diff verificado). Auditoría del test involucrado en el fallo (`AgenciaScopeTest::test_agencia_reporte_ventas_solo_sus_taquillas`, L605-619): las aserciones verifican comportamiento real (`assertContains`/`assertNotContains` sobre la respuesta JSON del API) — no hay tautologías, ghost loops ni smoke tests. El defecto está en la **generación de datos de prueba** (`TaquillaFactory` sin `unique()`), no en la calidad de las aserciones.

**Assertion quality**: ✅ All assertions verify real behavior

## Quality Metrics

**Linter (Pint)**: ✅ No errors — `vendor/bin/pint --test` exit 0 (proyecto completo, incluye archivos del cambio)
**Type Checker**: ➖ No aplica (PHP, sin type checker estático configurado)

## Spec Compliance Matrix

| Requirement | Scenario | Test / Evidencia | Result |
|-------------|----------|------------------|--------|
| REQ-1 | Esc 1: Suite paralela completa y verde | `composer test:parallel` — corrida 2 exit 0 (1069+2, 6396 assertions); corrida 1 exit 1 por flaky pre-existente | ⚠️ PARTIAL |
| REQ-1 | Esc 2: Privilegio local insuficiente falla claro | RED apply (`1044 Access denied ... create database`) validado vs vendor + runbook (L180) | ✅ COMPLIANT |
| REQ-2 | Esc 1: Aislamiento sin contaminación entre procesos | 3 corridas paralelas verdes (apply ×2 + verify corrida 2); 4 bases `lotto_test_test_1..4` por worker; fallo de corrida 1 root-caused como colisión intra-test (no cross-process) | ✅ COMPLIANT |
| REQ-2 | Esc 2: Dos corridas consecutivas sin flakiness | Corrida 1 (fail) vs corrida 2 (pass) difieren; flaky demostrado (P≈0.894 %) → "no aparece ningún test flaky" NO se cumple | ❌ FAILING |
| REQ-3 | Esc 1: CI crea las bases por proceso | Estructural: `MYSQL_ROOT_HOST: "%"` + `DB_USERNAME: root`/`root` + `--parallel --processes=4` (L21, L62-64); runtime post-merge (workflow sin trigger PR) | ⚠️ UNTESTED (runtime) |
| REQ-3 | Esc 2: CI falla ante privilegio insuficiente | Sin evidencia runtime (no hay job de fallo); cubierto por RED local validado | ⚠️ UNTESTED (runtime) |

**Compliance summary**: 2/6 escenarios compliant · 1 partial · 1 failing · 2 untested (runtime)

**Nota honesta CI (REQ-3)**: el workflow solo dispara en `push: [main]` + `workflow_dispatch` (L3-6); no corre en PRs. La corrida real es post-merge. El dispatch en rama está **prohibido**: el job `build` (L112-132) publica `:latest` en GHCR sin guarda de ref — dispararlo en `feat/mejoras-tooling` publicaría una imagen de una rama sin revisar.

## Correctness (Static Evidence)

| Requirement | Status | Notes |
|------------|--------|-------|
| REQ-1 (ejecución paralela) | ✅ Implementado | paratest ^7.20.0 + script `test:parallel` con `--processes=4`; override `-- --processes=N`; `-p` documentada como fallo real |
| REQ-2 (aislamiento por proceso) | ✅ Implementado | paratest TEST_TOKEN → `lotto_test_test_{1..4}` (vendor `TestDatabases`); `phpunit.xml` sin cambios (D3 cumplida); bases persistidas y reutilizadas |
| REQ-3 (CI habilita bases por proceso) | ✅ Implementado (config) | root + `MYSQL_ROOT_HOST: "%"` + flags paralelos; evidencia runtime pendiente post-merge |

## Coherence (Design)

| Decisión | ¿Seguida? | Notas |
|----------|-----------|-------|
| D1 Credenciales CI: root + `MYSQL_ROOT_HOST: "%"` | ✅ Sí | L21 + L62-63 del workflow |
| D2 Script array espejo + N=4 + prohibir `-p` | ✅ Sí | `composer.json` L58-62; `-p` verificado que falla ("Test file 2 not found") — deviation ya documentada en apply (design decía "filtra en silencio"; realidad: falla explícita) |
| D3 Bases por proceso sin tocar `phpunit.xml` | ✅ Sí | vendor `TestDatabases` L198-208; `phpunit.xml` intacto |
| D4 Docs runbook | ✅ Sí | L168-182, sección «Entorno local (backend)» tras paso 11 |
| D5 TDD: sujeto = la propia suite | ✅ Sí | RED/GREEN evidenciados sobre la suite; sin tests unitarios nuevos |

Deviation adicional (aplicada por apply, correcta): `Composer\Config::disableProcessTimeout` en el script — sin él composer mataría la corrida a los 300 s; precedente del script `dev` existente.

## Issues Found

**CRITICAL (1)**:
- `AgenciaScopeTest::test_agencia_reporte_ventas_solo_sus_taquillas` (L605) — **test flaky pre-existente**: colisión de nombres en `TaquillaFactory` (`faker->word.' Taquilla'` sin `unique()`; P≈0.894 %/corrida). Evidencia: corrida 1 exit 1 (hash `97107c42…`), corrida 2 exit 0 (hash `d78aac9b…`), 4 re-runs aislados verdes. No introducido por el cambio (0 archivos de test en el diff), pero rompe REQ-2 esc 2 y el criterio de éxito "sin flaky en 2 corridas consecutivas". **Fix sugerido (NO aplicado — read-only): `faker->unique()` en `TaquillaFactory` o nombres explícitos por test; cambio aparte.**

**WARNING (2)**:
- REQ-3 sin evidencia runtime: el workflow no dispara en PRs; la corrida real es post-merge (y el dispatch en rama publicaría `:latest` → prohibido). Evidencia estructural completa; runtime pendiente.
- Expectativa del design D2 corregida por la realidad (`-p 2` no se "ignora en silencio": falla con `Test file "2" not found`); ya documentada como deviation en apply-progress y el runbook es fiel — sin impacto en spec.

**SUGGESTION (3)**:
- Evaluar agregar `pull_request` al trigger del workflow (pregunta abierta del design) para obtener evidencia CI en PRs sin publicar imágenes — con `concurrency` y sin el job `build` en PRs.
- Tras el fix del flaky, agregar una corrida 3 consecutiva como evidencia de estabilidad antes del archive (el criterio de éxito pide 2 corridas consecutivas sin flaky).
- Considerar documentar en el runbook que las bases `lotto_test_motor_test_{1..4}` también se crean por worker (multi-suite), para que el GRANT del patrón cubra ambos prefijos.

### Verdict

**FAIL** — el mecanismo paralelo del cambio está correctamente implementado y demostrado (corrida 2 verde completa, pint limpio, CI configurado según contrato), pero la corrida 1 falló por un test flaky pre-existente (colisión faker, P≈0.894 %), lo que rompe el escenario REQ-2 esc 2 ("sin flakiness") y el criterio de éxito del cambio. Reporte persistible pero **no archive-ready**: se requiere resolver el flaky (fix de factory, cambio aparte) y una corrida paralela consecutiva verde.