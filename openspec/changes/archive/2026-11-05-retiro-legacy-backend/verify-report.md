# Verify Report — retiro-legacy-backend

**Change**: `retiro-legacy-backend` (#81, #82)
**Fecha**: 2026-11-05
**Rama**: `feat/retiro-legacy-backend`
**HEAD verificado**: `9235eb3`
**Base**: `ea7d381` (`main`)
**Veredicto**: **PASS** — 0 CRITICAL, 0 WARNING, 0 blockers.

## Cobertura de requisitos

| REQ | Estado | Evidencia |
|---|---|---|
| REQ-1: Superficie API-only | ✅ COMPLIANT | `BackendSurfaceTest::test_legacy_admin_routes_are_not_registered`; `route:list` sin `admin/*` ni `GET /`; `routes/web.php` eliminado. |
| REQ-2: Raíz JSON 404 | ✅ COMPLIANT | `BackendSurfaceTest::test_root_returns_json_404_with_accept_header` y `test_root_returns_json_404_without_accept_header`. |
| REQ-3: Autorización Spatie sin alias legacy | ✅ COMPLIANT | `BackendSurfaceTest::test_checkrole_alias_is_not_registered`; grep sin `CheckRole`/`checkrole` en `app/`, `routes/`, `bootstrap/`; `RoutePermissionCoverageTest` verde. |
| REQ-4: Scraping manual solo por API | ✅ COMPLIANT | `FetchResultsJob` y su test eliminados; `POST /api/v1/resultados/scrape` y `scrape-all` presentes; `AnimalitosScraperResultsTest` verde. |

**Escenarios**: 8/8 COMPLIANT (2 por requisito).

## Evidencia de tests

| Check | Resultado |
|---|---|
| Suite completa (`composer test:parallel`) | **1505 tests, 8689 assertions, 0 failures, 0 errors, 2 skipped**, 16:20.159 |
| Skips | 2 preexistentes: `PluginIntegrationTest` (requiere BD de prueba) y `ScrapeResultsJobTest::test_scrape_results_job_returns_early_for_non_scraper_game` (no hay juegos con `requires_scraper=false` en el seed) |
| Pint (`vendor/bin/pint --test`) | **PASS**, 395 files |
| `route:list` | Sin `admin.*`, sin `checkrole`, sin `GET /` |
| Grep `CheckRole`/`checkrole` | Sin referencias en `app/`, `routes/`, `bootstrap/` |
| Grep `FetchResultsJob` | Sin referencias en `app/`, `tests/` |
| Gate focalizado WU-3 | 25 passed, 1 skipped, 79 assertions |

## Diff

- 22 archivos, **490 inserciones, 871 borrados**.
- 3 work units + 1 fix colateral.
- Cada WU quedó por debajo del presupuesto de 800 líneas; el total es mayor por artefactos SDD y documentación.

## Tests añadidos / ajustados

- `backend/tests/Feature/BackendSurfaceTest.php` — 4 tests, 13 assertions.
- `backend/tests/Feature/AnimalitosScraperResultsTest.php` — 4 tests de persistencia/dedupe movidos desde `FetchResultsJobTest`.
- `backend/tests/Feature/RoutePermissionCoverageTest.php` — ya no lee `routes/web.php`.

## Fix colateral

`ScheduleServiceProvider` ahora captura `QueryException` al registrar la agenda de scrapers y loguea un warning si la BD no está lista durante el boot. Evita que el boot de la app falle en workers de tests paralelos antes de que `RefreshDatabase` migre la BD. Verificado con `ScheduleTimeZoneTest` (11 tests) y `TaquillaStoreTest` (14 tests) verdes.

## Riesgos residuales

- Los 2 skips son preexistentes y no bloquean.
- `docs/dev/auditoria-logs.md` es untracked en el repo principal y conserva una referencia a `FetchResultsJob`; si se versiona, actualizarla.
- Follow-ups fuera de alcance: Vite/Tailwind, CSRF/Sanctum stateful, `compatibilidad-dashboard-legacy` obsoleto.

## Veredicto

**PASS**. Listo para archive, merge a `main` local, push a GitHub y cierre de #81/#82.
