# Apply Progress — motor-premios (Slices F1a + F1b + F1c, tasks 1.1–1.10 + 1.14-export + 1.15–1.16)

**Change**: motor-premios · **Ramas**: F1a `feat/motor-premios-f1a-engine` (base `feat/motor-premios`) + F1b `feat/motor-premios-f1b-plugins` (base F1a, worktree investigacion-produccion) + F1c `feat/motor-premios-f1c-migraciones` (base F1b) · **Modo**: Strict TDD · **Fechas**: F1a 2026-09-17 · F1b 2026-09-19 · F1c 2026-09-21

> Merge: F1a proviene del topic Engram `sdd/motor-premios/apply-progress` (obs #266). F1b es este batch (continuación del slice F1b interrumpido: 1.9 y 1.10). F1c es este batch (slice F1c: migraciones + seeders + export; run previo interrumpido solo creó la rama).

## TDD Cycle Evidence

### Slice F1a (obs #266, preservado)

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1 | `tests/Unit/TextoTest.php` | Unit | N/A (nuevo) | ✅ Escrito (6 errores clase inexistente) | ✅ 6/6 · 8 aserciones | ✅ 6 casos | ➖ Ninguno |
| 1.2 | `tests/Unit/PremiosOficialesTest.php` | Unit | N/A (nuevo) | ✅ Escrito (21 errores clase inexistente) | ✅ 21/21 · 108 aserciones | ✅ 21 juegos | ➖ Ninguno |
| 1.3 | `tests/Unit/PremiosEngineTest.php` | Unit | N/A (nuevo) | ✅ Escrito (21 errores clase inexistente) | ✅ 21/21 · 22 aserciones | ✅ 21 casos | ✅ Pint |

### Slice F1b (este batch)

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.4 | `JuegoInterface` (contrato, sin test propio; 3 plugins lo implementan) | — | ✅ 36/36 plugin tests | N/A (contrato, commits previos 1.4–1.8) | ✅ | ✅ vía 1.5–1.7 | ✅ |
| 1.5 | `tests/Unit/AnimalitosPluginTest.php` | Unit | N/A (commit previo) | ✅ (commit previo) | ✅ | ✅ acentos×2, comodines×6, modalidad×2, validar×2, dinero×2 | ✅ |
| 1.6 | `tests/Unit/TerminalesPluginTest.php` | Unit | N/A (commit previo) | ✅ (commit previo) | ✅ | ✅ numero/padding/fallback/modalidad/validar/dinero | ✅ |
| 1.7 | `tests/Unit/TripletasPluginTest.php` | Unit | N/A (commit previo) | ✅ (commit previo) | ✅ | ✅ tipo estricto, signo label/sigla, dinero | ✅ |
| 1.8 | `tests/Unit/AnimalitosScraperTest.php` | Unit | N/A (commit previo) | ✅ (commit previo) | ✅ | ✅ mapper `patronus` | ✅ |
| 1.9 | `tests/Feature/ScrapeResultsJobTest.php` (+168) · `tests/Unit/BaseScraperHelpersTest.php` (+53) | Feature+Unit | ✅ 12 pre-existentes pasando | ✅ (tests escritos en run previo; verificados RED→GREEN) | ✅ 5/5 nuevos · 16/16 archivo | ✅ 3 dedupe (fila completa, desempate, 1 evaluación) + 2 hora (12h/24h, segundos) | ✅ Pint |
| 1.10 | `tests/Unit/JuegoPluginManagerTest.php` (nuevo, +7) | Unit (BD) | ✅ 36/36 plugin tests + 98/98 ResultsTest | ✅ Escrito (5 errores: getMultiplicador×3, validar zoo×2) | ✅ 7/7 | ✅ base config≠plugin ×3, zoo propio×2, fallback×1, sin plugin×1 | ✅ Pint |

## Test Summary (F1b)

- Tests escritos en este batch: **12** (5 de 1.9 + 7 de 1.10) · Pasando: 12 · Aserciones nuevas: 47
- Focused 1.4–1.10 + F1a + ScraperResolver:
  `php artisan test --filter='TextoTest|PremiosOficialesTest|PremiosEngineTest|AnimalitosPluginTest|TerminalesPluginTest|TripletasPluginTest|AnimalitosScraperTest|ScrapeResultsJobTest|BaseScraperHelpersTest|JuegoPluginManagerTest|ScraperResolverTest'`
  → **passed: 133 tests, 351 assertions, 1 skipped** (skip pre-existente legacy condicional en `ScrapeResultsJobTest`)
- Regresión legacy: `--filter='ResultsTest'` → **98/98 passed, 451 assertions** (los `*ResultsTest` pasan por call sites legacy de F1d; se actualizan en F1c/F1d con los valores del reglamento, design §8)
- Pint: `--test` sobre todos los archivos del slice → passed (1 pase EOF aplicado en el test nuevo)

### Slice F1c (este batch)

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.16 (fuente única) | `tests/Unit/PremiosOficialesTest.php` (+8) | Unit | ✅ 74/74 baseline | ✅ Escrito (8 errores: `configPara()` inexistente) | ✅ 8/8 nuevos · 30/30 archivo | ✅ 8 casos: trio, zulia, zamorano, chance (H23), monje, terminal-activo (espejo extra), ricachona, slug desconocido | ✅ Pint |
| 1.14-export | `tests/Feature/JuegosJsonTest.php` (schema `premios`/`active`/`vendible` + valores reglamento) | Feature (RefreshDatabase) | ✅ 74/74 baseline | ✅ Escrito (campo `premios` ausente en export + valores legacy desactualizados) | ✅ 3/3 · 717 aserciones | ✅ lotto-activo/monje/el-arrejuntado/loto-chaima/ricachona/terminal-activo/triple-chance/cazaloton/mega/selva | ✅ Pint |
| 1.16 (seeders) | `tests/Feature/*ResultsTest` (6 actualizados) + `JuegosJsonTest` | Feature | ✅ 74/74 | ✅ (asserts de valores nuevos, ver 1.14-export) | ✅ 83/83 seeders+export · 145/145 ResultsTest | ✅ 21 seeders vía `configPara` | ✅ Pint |
| 1.15 (migraciones) | Harness: `migrate` ×2 + `migrate:rollback --step=3` + re-`migrate` (BD `lotto_test_motor`) | Runtime | N/A (harness) | N/A — las migraciones se prueban por harness, no por RED clásico | ✅ up ×2 sin error · down reversible · re-up | ✅ dedupe con duplicados reales (2 filas → 1, fusión de claves, hora normalizada, sorteo distinto intacto) | ✅ Pint |

## Test Summary (F1c)

- Tests escritos/actualizados en este batch: **8 unit (configPara)** + **1 feature (JuegosJsonTest)** + **6 ResultsTest alineados**
- Focused slice F1c:
  `php artisan test --filter='ElArrejuntadoResultsTest|LotoChaimaResultsTest|LaRicachonaResultsTest|MegaAnimal40ResultsTest|TripleChanceResultsTest|CazalotonResultsTest|VerificacionOriginalesTest|JuegosJsonTest|PremiosOficialesTest'`
  → **passed: 83 tests, 1110 assertions**
- Regresión completa F1a–F1c: `--filter='TextoTest|PremiosOficialesTest|PremiosEngineTest|AnimalitosPluginTest|TerminalesPluginTest|TripletasPluginTest|AnimalitosScraperTest|ScrapeResultsJobTest|BaseScraperHelpersTest|JuegoPluginManagerTest|ScraperResolverTest|JuegosJsonTest'` → **passed: 145 tests, 1118 assertions, 1 skip legacy**
- Todos los `*ResultsTest` + VerificacionOriginalesTest + ScheduleTimeZoneTest + FetchResultsJobTest → **passed: 145 tests, 1401 assertions**
- Pint: `--test` sobre todos los archivos del slice → passed (3 migraciones formateadas)
- Evidencia migraciones (BD `lotto_test_motor`): `migrate` (3 DONE) → `migrate` ("Nothing to migrate") → `migrate:rollback --step=3` (down: ENUM sin `ganadora`, `premios` retirado, ricachona/plugin reactivados) → `migrate` (re-up) → `migrate` ("Nothing to migrate")
- Evidencia dedupe (integración): 2 filas duplicadas del mismo sorteo (hora `01:00 PM` + `13:00:00`, claves distintas) + 1 fila de otro sorteo → tras `migrate`: 2 filas; el superviviente conserva la hora normalizada `13:00` y las claves fusionadas `{"numero":5,"animalito":"Delfin"}`; el sorteo `14:00` intacto.
- `migrate:fresh --seed` → verde (las 3 migraciones + 21 seeders conviven; `docs/juegos.json` regenerado con `premios`/`active`/`vendible`)

## Files Changed (F1c)

| File | Acción | Qué |
|------|--------|-----|
| `backend/app/Support/PremiosOficiales.php` | Modificar | `configPara()` fuente única: `premios` canónico + espejos legacy (`premio_multiplo`, `modalidades`, `comodines`) + `ESPEJO_MODALIDADES`/`ESPEJO_EXTRA` (D2/D4) |
| `backend/app/Services/JuegoCatalogoService.php` | Modificar | Export `premios`, `active`, `vendible` (=active) aditivo (D10); `comodines`/`modalidades` legacy conservados (null si vacíos) |
| `backend/database/migrations/2026_09_17_000001_add_ganadora_to_apuestas_estado.php` | Crear | ENUM `ganadora` (REQ13/D5); `down` reubica a `pendiente` + restaura ENUM |
| `backend/database/migrations/2026_09_17_000002_backfill_premios_config_juegos.php` | Crear | Merge `config.premios`+espejos desde `PremiosOficiales` ×21; ricachona `active=false`+plugin inactivo (REQ7/D4); `down` retira `premios`+reactiva |
| `backend/database/migrations/2026_09_17_000003_dedupe_resultados_sorteo_duplicado.php` | Crear | Normaliza hora + dedupe por `(juego_id, DATE(fecha), hora)` conservando la fila más completa y fusionando claves (REQ14/D6-a); `down` no-op documentado |
| `backend/database/seeders/*` (21) | Modificar | `config` desde `PremiosOficiales::configPara()` (`updateOrCreate`); `LaRicachonaSeeder` inactiva sin premios; cazaloton sin dupleta; valores reglamento (monje 50, arrejuntado/chaima 40, chance 150/6.000) |
| `backend/tests/Unit/PremiosOficialesTest.php` | Modificar | +8 tests `configPara` (mapeo canónico→legacy, H23, espejo extra terminal-activo, ricachona, slug desconocido) |
| `backend/tests/Feature/JuegosJsonTest.php` | Modificar | Schema +`premios`/`active`/`vendible`; asserts nuevos por juego; asserts legacy a valores del reglamento (canónicos por orden de claves JSON de MySQL) |
| `backend/tests/Feature/{ElArrejuntado,LotoChaima,LaRicachona,MegaAnimal40,TripleChance,Cazaloton}ResultsTest.php` | Modificar | Valores de seeders alineados (40×, 150/6.000, `tipo` en comodines, ricachona inactiva+plugin inactivo, sin dupleta) |
| `docs/juegos.json` | Modificar | Regenerado con `premios`/`active`/`vendible` |
| `openspec/changes/motor-premios/tasks.md` | Modificar | 1.15–1.16 `[x]`; nota de export de 1.14 (reglas → F1d) |

## Work Unit Evidence (F1c)

| Evidence | Valor |
|---|---|
| Focused test (1.16+1.14-export) | `--filter='ElArrejuntadoResultsTest\|LotoChaimaResultsTest\|LaRicachonaResultsTest\|MegaAnimal40ResultsTest\|TripleChanceResultsTest\|CazalotonResultsTest\|VerificacionOriginalesTest\|JuegosJsonTest\|PremiosOficialesTest'` → passed: 83 tests, 1110 assertions |
| Runtime harness (1.15) | `DB_DATABASE=lotto_test_motor php artisan migrate` ×2 (idempotente) + `migrate:rollback --step=3` (down reversible) + re-`migrate` → verde; dedupe probado con duplicados reales |
| Runtime harness (1.16) | `DB_DATABASE=lotto_test_motor php artisan migrate:fresh --seed` → verde (21 juegos, ricachona inactiva); `juegos:export` regenera `docs/juegos.json` (21 juegos, contrato `premios`/`active`/`vendible`) |
| Rollback boundary | `git revert` de los commits del slice F1c (migraciones, seeders, service, tests, docs/juegos.json) + `migrate:rollback --step=3`; nada de F1d tocado |

## Commits (rama `feat/motor-premios-f1c-migraciones`)

- 7ba158d feat(motor-premios): PremiosOficiales::configPara como fuente unica de premios y espejos legacy (1.16, D2/D4)
- 30e9d5e feat(motor-premios): export con premios/active/vendible y 21 seeders alineados al catalogo (1.14-export/1.16, D10)
- c15a2c0 feat(motor-premios): migraciones ganadora, backfill premios y dedupe de resultados (1.15, REQ7/REQ13/REQ14, D5/D6)
- 12f39ff docs(sdd): marca F1c (1.14-export/1.15/1.16) y registra apply-progress del slice

## Deviations (F1c)

1. **`la-ricachona` sin `premio_multiplo`**: REQ7 la deja sin premios; el espejo legacy de base también se retira del config (export `premio_multiplo: null`). Design §7 solo decía "sin premios"; se interpreta que el multiplicador legacy (30, sin fuente) tampoco se publica.
2. **Espejo legacy de `triple-chance`**: se alinea al vocabulario canónico (se retiran las claves legacy sin contraparte canónica `triple`, `aproximacion`, `terminal_a_b`, `terminal_a_o_b` del export; el reglamento §3.2 es la autoridad). `triple_a_o_b` 100→150 y `triple_c_signo` 5.000→6.000 (H23).
3. **Espejo extra `terminal-activo`**: el catálogo canónico no define modalidades (el motor paga por base), pero el contrato histórico del export mostraba `{terminal: 60}`; se conserva vía `ESPEJO_EXTRA` con el valor derivado de `base` (no duplicado a mano).
4. **`down` de 000002 no restaura los espejos legacy previos** (monje queda 50×, etc.): sigue el design §7 ("retira `premios` y reactiva ricachona"); el rollback deja la base actualizada, que es el contrato vigente del motor.
5. **JuegosJsonTest**: asserts de arrays completos pasan a `assertEqualsCanonicalizing` (MySQL JSON reordena claves); el orden de claves JSON no es contrato.
6. **`*ResultsTest` actualizados en F1c** (no en F1d): sus asserts de config validan los seeders; los flujos de liquidación legacy siguen intactos (call sites sin tocar, F1d).

## Issues (F1c)

- Ninguno funcional. Nota operativa: `docs/juegos.json` se regeneró contra `lotto_test_motor` sembrada (no contra la BD vacía tras el rollback de RefreshDatabase — primer intento escribió un catálogo vacío y se rehízo con `migrate:fresh --seed` + `juegos:export`).

## Files Changed (F1b)

| File | Acción | Qué |
|------|--------|-----|
| `backend/app/Plugins/Contracts/JuegoInterface.php` | Modificar (commit 1.4, bf90668) | +`evaluarAcierto`, `modalidadDe`, firma `validarApuesta(data,?opciones)` |
| `backend/app/Plugins/Juegos/Animalitos.php` | Modificar (commit 1.5) | Acentos (H13/N10), comodines superset, `modalidadDe` |
| `backend/app/Plugins/Juegos/Terminales.php` | Modificar (commit 1.6) | Clave `numero` + padding (N1/N9), `modalidadDe` |
| `backend/app/Plugins/Juegos/Tripletas.php` | Modificar (commit 1.7) | Signo label/sigla, tipo estricto (N2/N3), `modalidadDe` |
| `backend/app/Plugins/Scrapers/AnimalitosScraper.php` | Modificar (commit 1.8) | Mapper `patronus` (H14) |
| `backend/app/Jobs/ScrapeResultsJob.php` | Modificar (commit 1.9, 3f72a00) | Guard `dedupeResultadosDelDia` (N6/D6-b) |
| `backend/app/Plugins/Scrapers/BaseScraper.php` | Modificar (commit 1.9) | `saveResults` normaliza hora + `updateOrCreate` (D6-c) |
| `backend/tests/Feature/ScrapeResultsJobTest.php` | Modificar (commit 1.9) | +3 tests dedupe (168 líneas) |
| `backend/tests/Unit/BaseScraperHelpersTest.php` | Modificar (commit 1.9) | +2 tests normalización hora (53 líneas) |
| `backend/app/Services/JuegoPluginManager.php` | Modificar (commit 1.10, 77f86dc) | `getMultiplicador`→engine (REQ1); `validarApuesta` con `juego_opciones` (REQ15/N12) |
| `backend/tests/Unit/JuegoPluginManagerTest.php` | Crear (commit 1.10) | +7 tests del manager |
| `openspec/changes/motor-premios/tasks.md` | Modificar | 1.4–1.10 marcadas `[x]` |

## Work Unit Evidence (F1b)

| Evidence | Valor |
|---|---|
| Focused test (1.9) | `php artisan test --filter='ScrapeResultsJobTest\|BaseScraperHelpersTest'` → passed: 16 tests, 39 assertions, 1 skip legacy |
| Focused test (1.10) | `php artisan test --filter='JuegoPluginManagerTest'` → passed: 7/7 |
| Focused completo slice | filter 1.4–1.10+F1a+ScraperResolver → passed: 133 tests, 351 assertions, 1 skip legacy |
| Runtime harness | N/A — unidad de servicios/plugins sin frontera de runtime nueva (el flujo de red de scrapers se prueba vía Feature con fakes; la migración de call sites es F1d) |
| Rollback boundary | `git revert` de los commits del slice F1b (3f72a00, 77f86dc) + revert de bf90668..7f8d317 (1.4–1.8) si se requiere; solo plugins/manager/job/tests; nada de F1c/F1d tocado |

## Commits (rama `feat/motor-premios-f1b-plugins`)

F1b (5 commits previos del slice, preservados):

- bf90668 feat(motor-premios): JuegoInterface extiende contrato de adaptadores y motor activado (1.4, §3.3)
- c0805da feat(motor-premios): plugin Animalitos con adaptador evaluarAcierto y acentos (1.5, H13/N10)
- b0c3608 feat(motor-premios): plugin Terminales liquida contra clave numero con padding (1.6, N1/N9)
- b5b9969 feat(motor-premios): plugin Tripletas con signo label/sigla y tipo estricto (1.7, N2/N3, REQ4/REQ5)
- 7f8d317 feat(motor-premios): mapper patronus en AnimalitosScraper (1.8, H14)

Este batch (2 commits nuevos):

- 3f72a00 feat(motor-premios): dedupe del dia en job y normalizacion de hora en upsert (1.9, N6/D6)
- 77f86dc feat(motor-premios): manager delega dinero en engine y valida con opciones reales (1.10, REQ15/N12/D1)

## Deviations

1. **1.9 tests llegaron escritos de un run interrumpido** (RED→GREEN ya resuelto en ese run): los verifiqué verdes (16/16) y commiteé. Sin cambios de diseño.
2. **Sin tests legacy actualizados en F1b**: los `*ResultsTest` (regresión por juego) pasan por call sites legacy (`ApuestaService::createApuesta` → `$plugin->calcularPremio`) que NO se tocan hasta F1d (1.11–1.13). Su actualización con valores del reglamento (150/6.000, 40×, `active=false`) depende de los seeders de F1c, fuera de este slice. Design §8 lo confirma: "El resto de los `*ResultsTest` asserta campos legacy que se conservan".
3. **`getMultiplicador` sin plugin**: antes devolvía 1; ahora devuelve `base` desde config (0 si no hay config). Ningún caller productivo usa este método (verificado por grep), solo el test nuevo. Es el contrato REQ1 (el multiplicador sale de config, no del plugin).

## Issues

- Ninguno funcional. Nota operativa: la BD `lotto_test` es compartida con el otro agente; en caso de colisión usar `DB_DATABASE=lotto_test_motor` (sin commitear .env). En este batch no hubo colisión.

## Remaining Tasks

- [ ] 1.11–1.13 (slice F1d: call sites — ApuestaService, PagoController, TicketController)
- [ ] 1.14 `JuegoController::reglas` (parte restante, slice F1d)
- [ ] 1.17 `MotorPremiosRegresionTest` (slice F1d)
- [ ] Fase 2 (2.1–2.2) y Fase 3 (3.1–3.5)

## Status

13/17 tareas del cambio completadas (slices F1a, F1b y F1c íntegros + export de 1.14). Ready for next batch (F1d).

Session: ses_f4f7a75d2ffe1V5gJLiamGKc4I · Project: lotto-app · Scope: project