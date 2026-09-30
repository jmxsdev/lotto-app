# Apply Progress: `mejoras-tooling` — ParallelTesting de la suite backend

## Estado de tareas

| Tarea | Estado | Evidencia |
|---|---|---|
| 1.1 Dep `brianium/paratest:^7.20.0` + lock | ✅ | `composer show brianium/paratest` → v7.20.0; lock +217 líneas; PHPUnit 12.5.31 sin conflicto |
| 2.1 Script `test:parallel` (4 procesos) | ✅ | Override `-- --processes=2` verificada (creó solo `lotto_test_test_1/2`); `-p 2` falla con "Test file 2 not found" (no se ignora en silencio) |
| 3.1 CI paralelo + root + `MYSQL_ROOT_HOST: "%"` | ✅ | RED: `Access denied ... create database lotto_test_test_1` (1044, 1009 errors); GREEN ×2 verdes; junit 0 `<warning` |
| 4.1 Runbook: comando + prerequisito `CREATE DATABASE` | ✅ | Nota insertada tras paso 11 (L170-180) con `GRANT ALL ON \`lotto_test_test\_%\`.*` |

## TDD Cycle Evidence

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| 1.1–4.1 (sujeto = la propia suite; sin tests unitarios nuevos) | N/A (suite existente) | Integración (MySQL 8.0, paratest 4 workers) | ✅ Suite secuencial ya verde en repo (1069+2 previos) | ✅ Usuario MySQL temporal restringido → `SQLSTATE[42000] 1044 Access denied ... SQL: create database \`lotto_test_test_1\`` (failed, 1009 errors, 10.8 s) | ✅ `composer test:parallel` ×2: 1071 tests, 1069 passed, 2 skipped, 6396 assertions, exit 0 ambas | ✅ Dos corridas consecutivas idénticas (620.9 s y 605.7 s) — sin flakiness (REQ-2 esc 3) | ✅ `vendor/bin/pint --test` → passed |

Triangulación: N/A — tarea puramente de configuración/herramienta (D5 del design: el sujeto es la propia suite, no hay lógica nueva que triangular). Nota explícita: **Triangulation skipped: tarea estructural de tooling; el "triangulo" es la doble corrida consecutiva**.

## Test Summary

- **Total tests ejecutados**: 1071 por corrida (1069 passed, 2 skipped, 6396 assertions)
- **Corridas verdes consecutivas**: 2 (REQ-1 esc 1, REQ-2 esc 2/3)
- **Corrida 1 (GREEN)**: 620.9 s (~10.3 min), exit 0 — vs ~50 min secuencial (≈5x más rápida)
- **Corrida 2 (GREEN)**: 605.7 s (~10.1 min), exit 0 — sin flakiness
- **RED**: 10.8 s hasta fallo claro `Access denied ... CREATE DATABASE`
- **Pint**: `vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}`
- **Layers**: Integración (paratest/MySQL 8.0). Unit tests nuevos: 0 (sujeto = suite existente)
- **Approval tests**: None — sin refactoring
- **Pure functions**: None — sin lógica nueva

## Files Changed

| File | Acción | Detalle |
|---|---|---|
| `backend/composer.json` | Modificado | dep `brianium/paratest:^7.20.0` en require-dev + script `test:parallel` |
| `backend/composer.lock` | Modificado (generado) | +217 líneas; paratest 7.20.0 + deps |
| `.github/workflows/ci-cd.yml` | Modificado | service mysql +`MYSQL_ROOT_HOST: "%"`; env `DB_USERNAME: root`/`DB_PASSWORD: root`; PHPUnit +`--parallel --processes=4` |
| `docs/runbook-ops.md` | Modificado | sección paralelo tras paso 11: comando, override, `GRANT` para `lotto_user`, nota `-p`, persistencia de bases |
| `openspec/changes/mejoras-tooling/tasks.md` | Modificado | 1.1–4.1 `[x]` + evidencia |
| `openspec/changes/mejoras-tooling/apply-progress.md` | Creado | este artefacto |

## Commits (rama `feat/mejoras-tooling`, sin push)

1. `chore(backend): agrega brianium/paratest ^7.20.0`
2. `chore(backend): agrega script composer test:parallel`
3. `ci: paraleliza tests y habilita root en MySQL`
4. `docs(runbook): documenta test:parallel y privilegio CREATE DATABASE`

## Deviations del design

- **Script `test:parallel` con `Composer\Config::disableProcessTimeout`** (tasks.md 2.1 literal no lo incluía): necesario — composer mata procesos a los 300 s por defecto y la corrida dura ~10 min; el script `dev` existente ya lo usa (precedente del repo).
- **`-p 2` NO se ignora en silencio** (design D2 decía "Collision la filtra en silencio"): paratest la interpreta como `--processes=2` + path `2` → falla con `Test file "2" not found`. El runbook ya lo documenta como "NO funciona ... y falla", así que la doc es fiel; se corrige la expectativa del design, no el código.

## Issues

- Ninguno. El RED inicial con usuario solo-`USAGE` falló antes en el boot (ScheduleServiceProvider consulta `lotto_db`) — se resolvió otorgando acceso a `lotto_db.*`/`lotto_test.*` y solo `DROP` sobre el patrón para aislar el fallo en `CREATE DATABASE`.
- Hallazgo: `GRANT ALL ON \`lotto_test_test\_%\`.*` SÍ permite a MySQL 8.0 crear las bases del patrón (por eso el usuario dev local ya funcionaba sin CREATE global).

## Verificación adicional

- RED con usuario temporal `sdd_red_user` (creado y eliminado tras la prueba): `SQLSTATE[42000] 1044 Access denied for user 'sdd_red_user'@'%' ... SQL: create database \`lotto_test_test_1\``.
- CI real (REQ-3): no corre en PRs (solo push a main + dispatch) — evidencia post-merge, según design Verificación.

---

## Remediación (flaky fix)

**Origen**: verify-report `b47ca01` — CRITICAL: `AgenciaScopeTest::test_agencia_reporte_ventas_solo_sus_taquillas` flaky preexistente por colisión de nombres en `TaquillaFactory` (`$this->faker->word.' Taquilla'` sin `unique()`; P≈0.894 %/corrida). El reporte `ventas-totales?nivel=taquilla` agrupa por `taquillas.name` (`Entidad` = `taquillas.name`, `ApuestaService::ventasTotales`), y si `taquillaAjena` colisiona de nombre con `taquilla1`, `assertNotContains` falla en falso.

**Fix aplicado (aprobado por el usuario, alcance SOLO este)**: `backend/database/factories/TaquillaFactory.php` → `'name' => $this->faker->unique()->word().' Taquilla'`.

**Revisión del MISMO patrón en factories que alimentan el test/flujo** (verificado, NO expandido):
- `AgenciaFactory` (`word.' Local'`) y `GrupoFactory` (`word.' Group'`) comparten el patrón `faker->word` sin `unique()` y alimentan el flujo (`jerarquiaLocal()`), pero **ninguna aserción del flujo compara sus nombres**: `AgenciaScopeTest` asevera IDs/emails/status (nunca nombres de agencia/grupo) y `AgenciaDestroyCascadaTest` usa nombres explícitos (`"Local {$prefijo}"`, `"Taquilla {$prefijo}"`). `SuperBancaScopeTest`/`CuadreCajaReportTest`/`ReporteTest` aseveran nombres explícitos creados en el test, no faker. Por eso el arreglo NO se expande a más factories (regla "NO expandas a más lugares").
- Riesgo de overflow del pool `unique()` (182 palabras) descartado: el Generator es singleton con `unique(true)` en cada resolución de app, y cada test method refresca la app (PHPUnit instancia nueva + `refreshApplication`) → el pool se resetea por test; máximo 2 `Taquilla::factory` por test method directo (+ las de `jerarquiaLocal`, ≤ 5-6 por test) vs 182 palabras. Sin riesgo de `OverflowException`.

**Evidencia (todas VERDES tras el fix)**:
- Test aislado 2×+ (mínimo exigido 2): `DB_DATABASE=lotto_test_motor php artisan test --filter='AgenciaScopeTest'` → 3/3 pasadas: 23 tests, 23 passed, 55 assertions (185.0 s, 165.9 s, 159.2 s).
- **2 corridas consecutivas `composer test:parallel` VERDES** (criterio REQ-2 esc 2 del spec):
  - Corrida 1: 1071 tests, 1069 passed, 2 skipped, 6396 assertions, **474 701 ms** (~7.9 min), exit 0.
  - Corrida 2: 1071 tests, 1069 passed, 2 skipped, 6396 assertions, **478 549 ms** (~8.0 min), exit 0.
  - Counts idénticos a las GREEN previas (apply ×2 y verify corrida 2) → sin flakiness; el fix elimina la colisión de nombres.
- `vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}` (proyecto completo).

**Commit**: `3c98464` — `fix(tests): nombre unico en TaquillaFactory (flaky preexistente)` (rama `feat/mejoras-tooling`, sin push). Único archivo: `backend/database/factories/TaquillaFactory.php` (1 línea). Sin cambios en `tasks.md` ni en `panel/`/`taquilla/`; worktree limpio al final.