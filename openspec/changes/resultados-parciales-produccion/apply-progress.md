# Apply Progress — Slice 1 (`resultados-parciales-produccion`)

**Estado**: Slice 1 COMPLETO (tareas 1.1–1.10). Slices 2–4 NO implementados (sweep, scheduler service, alertas).

**Modo**: STRICT TDD. Runner: `php artisan test` (canonical; CI usa `php artisan test --display-warnings`).

**Base**: `d250c20` — rama `feat/resultados-parciales-produccion` en el worktree
`lotto-app-worktrees/resultados-parciales-produccion`. Nada fue pusheado ni mergeado.

## Archivos cambiados (Slice 1)

| Archivo | Acción | Qué |
|---|---|---|
| `backend/app/Services/ScraperSource.php` | Crear | DTO `ScraperSource{key, scraperClass, slug, juegoIds}` (readonly). |
| `backend/app/Services/ScraperSourceResolver.php` | Crear | `sources()`, `sourceOf(Juego)`, `sourceByKey(key)`, `scraperFor(Juego)`, `scraperClassFor(Juego)`, `instantiateClass(clase, Juego)`. Consolidación: feed `animalitos` → `lottoactivo-animalitos`; trio/terminal → `lottoactivo-trio_activo`/`lottoactivo-terminal_activo`; resto 1 juego por fuente (key = slug). Contrato de clase idéntico al anterior `resolveScraper` (scraper_class > URL > convención). |
| `backend/app/Jobs/ScrapeSourceJob.php` | Crear | `ScrapeSourceJob(sourceKey, fecha, horaObjetivo)`. Guarda: ejecuta solo si ≥1 miembro programado a `horaObjetivo` sin fila `(juego,fecha,hora)`; `horaObjetivo=null` (manual) siempre ejecuta. 1 fetch → `saveResults` por grupo de `juego_id` (upsert intacto) → log `total` + `por_juego` → `verificarGanadores` itera miembros. |
| `backend/app/Jobs/ScrapeResultsJob.php` | Modificar | `handle()` usa `ScraperSourceResolver::scraperFor(Juego)`; `resolveScraper`/`instantiateScraper` quedan como delegados finos (compatibilidad con tests previos por reflexión). |
| `backend/app/Providers/ScheduleServiceProvider.php` | Modificar | Registra por fuente y hora las pasadas `scrape_{sourceKey}_{H:i}[+15|+30|+45]` con `withoutOverlapping(5)`, solo consola. |
| `backend/app/Http/Controllers/Api/ResultadoController.php` | Modificar | `scrapeAll`/`scrape` sin `juego_id` agrupan por fuente → 1 `ScrapeSourceJob::dispatch` por fuente; respuesta JSON desglosada por juego (shape intacto). Path individual con `juego_id` sin cambios (`ScrapeResultsJob`). |
| `backend/tests/Unit/ScraperSourceResolverTest.php` | Crear | 16 tests del resolver (familia, trio/terminal, 1:1, sourceOf, scraperFor, fallbacks, instanciación). |
| `backend/tests/Feature/ScrapeSourceJobTest.php` | Crear | 7 tests del job por fuente (stub `AnimalitosScraperFake` corta solo el fetch; parse y saveResults reales). |
| `backend/tests/Feature/ScheduleTimeZoneTest.php` | Modificar | 5 tests: hora local sin doble conversión, pasadas +15/+30/+45 por fuente, +45 del último sorteo 23:00 → 23:45, familia consolidada (4 pasadas, no 16). |
| `backend/tests/Feature/ResultadoControllerScrapeAllTest.php` | Crear | 3 tests: scrape-all despacha 1 job por fuente (18 en el seed, no 21), scrape sin juego_id igual, 401 sin auth. |
| `openspec/changes/resultados-parciales-produccion/tasks.md` | Modificar | Tasks 1.1–1.10 marcadas `[x]`. |
| `openspec/changes/resultados-parciales-produccion/apply-progress.md` | Crear | Este artefacto. |

## TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| 1.1 | `tests/Unit/ScraperSourceResolverTest.php` | Unit | N/A (nuevo) | ✅ 16 errores (clase no existe) | ✅ 16/16 | ✅ 16 casos (familia, trio/terminal, 1:1, fallbacks) | ✅ `fuenteKeyDe` deduplicado contra `buildSource` |
| 1.2 | (mismo archivo) | Unit | N/A | ✅ (1.1 cubre) | ✅ 16/16 (34 asserts) | ➖ cubierto por 1.1 | ✅ Pint |
| 1.3 | `tests/Feature/ScrapeSourceJobTest.php` | Feature | N/A (nuevo) | ✅ 7 errores (`ScrapeSourceJob` no existe) | ✅ 7/7 | ✅ 7 casos (familia, log por juego, re-run, skip, sin miembros, manual, fuente inexistente) | ✅ fix de doble de contenedor (bind closure vs instance) |
| 1.4 | (mismo archivo) | Feature | N/A | ✅ (1.3 cubre) | ✅ 7/7 (23 asserts) | ➖ cubierto por 1.3 | ✅ Pint |
| 1.5 | `ScraperResolverTest` + `ScrapeResultsJobTest` | Unit/Feature | ✅ 20/21 | ➖ refactor con approval (tests existentes = approval) | ✅ 34/35 (1 skip pre-existente) | ➖ contrato idéntico preservado | ✅ delegación a resolver, -46 líneas |
| 1.6 | `tests/Feature/ScheduleTimeZoneTest.php` | Feature | ✅ 20/21 (previo) | ✅ 4 fallos (provider viejo) | ✅ 5/5 | ✅ 5 casos (hora local, +15/30/45, 23:00+45, familia) | ➖ none |
| 1.7 | (mismo archivo) | Feature | N/A | ✅ (1.6 cubre) | ✅ 5/5 (12 asserts) | ➖ cubierto por 1.6 | ✅ Pint |
| 1.8 | `tests/Feature/ResultadoControllerScrapeAllTest.php` | Feature | N/A (nuevo) | ✅ 0 despachos vs 18 esperados | ✅ 3/3 | ✅ 3 casos (scrape-all, scrape sin id, 401) | ✅ URL corregida a `/api/v1` |
| 1.9 | (mismo archivo) | Feature | N/A | ✅ (1.8 cubre) | ✅ 3/3 (58 asserts) | ➖ cubierto por 1.8 | ✅ Pint |
| 1.10 | suite completa | All | ✅ | — | ✅ 760 tests / 758 passed / 2 skipped pre-existentes / 0 fails | — | ✅ `pint --test` limpio |

## Evidencia de ejecución (comandos + resultados)

- Safety net inicial (files a modificar): `php artisan test --filter='ScraperResolverTest|ScrapeResultsJobTest|ScheduleTimeZoneTest|ResultadoAparicionesTest|JuegoScraperClassTest'` → 35 passed / 1 skipped.
- RED 1.1: `php artisan test --filter=ScraperSourceResolverTest` → 16 errores `Target class [App\Services\ScraperSourceResolver] does not exist`.
- GREEN 1.2: mismo filtro → 16/16 passed (34 assertions).
- RED 1.3: `php artisan test --filter=ScrapeSourceJobTest` → 7 errores `Class "App\Jobs\ScrapeSourceJob" not found`.
- GREEN 1.4: mismo filtro → 7/7 passed (23 assertions).
- GREEN 1.5: `php artisan test --filter='ScraperResolverTest|ScrapeResultsJobTest'` → 18 passed / 1 skipped (skip pre-existente).
- RED 1.6: `php artisan test --filter=ScheduleTimeZoneTest` → 1 passed / 4 failed (provider viejo).
- GREEN 1.7: mismo filtro → 5/5 passed (12 assertions).
- RED 1.8: `php artisan test --filter=ResultadoControllerScrapeAllTest` → `ScrapeSourceJob pushed 0 times instead of 18`.
- GREEN 1.9: mismo filtro → 3/3 passed (58 assertions).
- **Suite completa (1.10)**: `php -d memory_limit=1536M artisan test` → `passed, tests 760, passed 758, assertions 3742, skipped 2` (skips pre-existentes: ApuestaServiceTest condicional y PluginIntegrationTest), exit 0. Tiempo ~21 min (máquina compartida, load alto).
- **Pint**: `vendor/bin/pint --test` → passed (repo backend completo).

## Commits (Slice 1)

| SHA | Mensaje |
|---|---|
| `8221e7e` | feat(backend): consolida fuentes de scrape por feed con ScraperSourceResolver |
| `27f7cf9` | feat(backend): job por fuente ScrapeSourceJob con guarda y upsert por juego |
| `c68cbf9` | refactor(backend): delega la resolucion de scraper de ScrapeResultsJob al resolver |
| `a6ad8e4` | feat(backend): agenda por fuente con pasadas retardadas +15/+30/+45 |
| `b7cd52f` | feat(api): scrape y scrape-all sin juego_id despachan un job por fuente |
| (siguiente) | docs(sdd): progreso de apply del slice 1 de resultados-parciales-produccion (tasks + apply-progress) |

Nada fue pusheado ni mergeado; integración a main es user-gated tras verificación.

## Desviaciones del diseño (documentadas)

1. **`Http::fake` no aplica en este código**: `BaseScraper` (y `AnimalitosScraper::fetch`) usan Guzzle directo, no la facade `Http` de Laravel; `Http::fake()` solo intercepta la facade. Hacerlo funcionar exigiría tocar `AnimalitosScraper::fetch` (prohibido: "AnimalitosScraper stays as-is"). Sustituto: doble `AnimalitosScraperFake` que corta SOLO `execute()` (fetch de red) y conserva parse + `saveResults` reales, inyectado vía binding del contenedor. Cubre todos los comportamientos requeridos (1 fetch→familia, guardados por juego, sin duplicados, skip) y cuenta fetches.
2. **`ScraperSource` en archivo propio** (`app/Services/ScraperSource.php`): el design lista solo `ScraperSourceResolver.php` como archivo nuevo pero define el DTO en Interfaces; PSR-4 exige una clase por archivo.
3. **Controller despacha async** (`ScrapeSourceJob::dispatch`, no `handle()`): el test de tarea exige "despacha 1 job por fuente" (verificable con `Bus::fake`). El endpoint manual pasa a fire-and-forget (mejor para latencia; 18+ fetches síncronos arriesgaban timeout HTTP); la respuesta mantiene el shape y `ganadoras_detectadas` se computa del estado actual de DB. Path con `juego_id` sigue síncrono e intacto.
4. **`withoutOverlapping(5)` y `tries=1`** en `ScrapeSourceJob`: las pasadas +15/+30/+45 son el mecanismo de retry (el design descartó ampliar tries/backoff).

## Work Unit Evidence (por work unit)

| Work unit | Focused test command y resultado | Runtime harness | Rollback boundary |
|---|---|---|---|
| Resolver (1.1–1.2) | `php artisan test --filter=ScraperSourceResolverTest` → 16/16 | N/A (lógica pura; sin frontera runtime) | Revertir `ScraperSourceResolver.php` + `ScraperSource.php` + test |
| ScrapeSourceJob (1.3–1.4) | `php artisan test --filter=ScrapeSourceJobTest` → 7/7 | N/A (fetch stubbeado; la red se verifica en rollout) | Revertir `ScrapeSourceJob.php` + test |
| Delegación (1.5) | `php artisan test --filter='ScraperResolverTest\|ScrapeResultsJobTest'` → 18/19 (1 skip pre-existente) | N/A | Revertir `ScrapeResultsJob.php` |
| Agenda (1.6–1.7) | `php artisan test --filter=ScheduleTimeZoneTest` → 5/5 | N/A (la agenda no vive hasta slice 3: `schedule:list`) | Revertir `ScheduleServiceProvider.php` + test |
| Controller (1.8–1.9) | `php artisan test --filter=ResultadoControllerScrapeAllTest` → 3/3 | N/A | Revertir `ResultadoController.php` + test |
| Suite (1.10) | `php -d memory_limit=1536M artisan test` → 760/758 (2 skips pre-existentes) + `pint --test` passed | N/A | N/A |

Rollback global slice 1: revertir resolver + `ScrapeSourceJob` + provider + controller + tests → vuelve `dailyAt` por juego y dispatch manual por juego.

## Hallazgos

- `app()->make($class, $params)` con parámetros NO vacíos ignora bindings por `instance()` (devuelve instancia nueva); sí invoca closures de `bind()`. Por eso el doble usa `bind(AnimalitosScraper::class, fn () => $stub)`.
- Rutas API versionadas: `/api/v1/resultados/scrape-all` (prefijo `v1` en `routes/api.php`).
- La máquina está compartida (otros worktrees corren `php artisan test` en paralelo con `DB_DATABASE=lotto_test_motor`); el suite completo tarda ~21 min bajo load. No hay colisión de DB (este worktree usa `lotto_test` por `phpunit.xml`).
- Seed del `DatabaseSeeder`: 21 juegos con `requires_scraper` → 18 fuentes (familia 4→1).

## Siguiente paso

Slice 2: sweep (`DrawReconciliationService` + `ReconciliarSorteos` + registros sweep/cierre) con `ReconciliarSorteosCommandTest` (`Bus::fake`), flags `--grace/--window/--max-sources/--day-close`.