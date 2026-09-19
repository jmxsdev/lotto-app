# Apply Progress — motor-premios (Slices F1a + F1b, tasks 1.1–1.10)

**Change**: motor-premios · **Ramas**: F1a `feat/motor-premios-f1a-engine` (base `feat/motor-premios`) + F1b `feat/motor-premios-f1b-plugins` (base F1a, worktree investigacion-produccion) · **Modo**: Strict TDD · **Fechas**: F1a 2026-09-17 · F1b 2026-09-19

> Merge: F1a proviene del topic Engram `sdd/motor-premios/apply-progress` (obs #266). F1b es este batch (continuación del slice F1b interrumpido: 1.9 y 1.10).

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
- [ ] 1.14 `JuegosJsonTest` + `JuegoCatalogoService` (slice F1d)
- [ ] 1.15–1.16 (slice F1c: migraciones + seeders)
- [ ] 1.17 `MotorPremiosRegresionTest` (slice F1d)
- [ ] Fase 2 (2.1–2.2) y Fase 3 (3.1–3.5)

## Status

10/17 tareas del cambio completadas (slices F1a y F1b íntegros). Ready for next batch (F1c/F1d).

Session: ses_f4f7a75d2ffe1V5gJLiamGKc4I · Project: lotto-app · Scope: project