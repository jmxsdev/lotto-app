# Archive Report — retiro-legacy-backend

**Change**: `retiro-legacy-backend` (#81, #82)
**Archivado a**: `openspec/changes/archive/2026-11-05-retiro-legacy-backend/`
**Fecha de archivo**: 2026-11-05
**Estado final**: **PASS** — 1505 tests, 8689 assertions, 0 failures, 0 errors, 2 skips preexistentes.
**Rama de trabajo**: `feat/retiro-legacy-backend`
**HEAD al archivar**: `9235eb3`
**Base del change**: `ea7d381` (`main`)

## 1. Resumen del cambio

Retirada de superficie legacy del backend API-only:

- **#82**: eliminado `CheckRole` y el alias `checkrole`; la autorización queda solo en Spatie `permission:`/`role_or_permission:` y policies.
- **#81**: eliminados `routes/web.php`, el registro `web:` de `withRouting`, los controllers/vistas del panel admin, `welcome.blade.php` y `FetchResultsJob`.
- `GET /` ahora responde `404` JSON, coherente con API-only.
- La cobertura de persistencia/dedupe de `AnimalitosScraper` se preservó en `AnimalitosScraperResultsTest`.
- `RoutePermissionCoverageTest` se ajustó para no leer `routes/web.php`.
- Fix colateral: `ScheduleServiceProvider` captura `QueryException` si la BD no está lista durante el boot, evitando fallos de workers paralelos antes de `RefreshDatabase`.

## 2. Commits del ciclo

| WU | Implementación | Docs/registro |
|---|---|---|
| Planificación | — | `70420b1` |
| WU-1 (#82) | `c814508` | `ff1f705` |
| WU-2 (#81 panel) | `f736f2e` | `9d8d9c8` |
| WU-3 (#81 job) | `0884a96` | `95b9991` |
| Fix colateral | `9235eb3` | — |

## 3. Verificación final

| Check | Resultado |
|---|---|
| Suite completa (`composer test:parallel`) | **1505 tests, 8689 assertions, 0 failures, 0 errors, 2 skipped**, 16:20.159 |
| Skips | 2 preexistentes (`PluginIntegrationTest`, `ScrapeResultsJobTest::test_scrape_results_job_returns_early_for_non_scraper_game`) |
| Pint | **PASS**, 395 files |
| `route:list` | Sin `admin.*`, sin `checkrole`, sin `GET /` |
| Greps | Sin `CheckRole`/`checkrole` en `app/`, `routes/`, `bootstrap/`; sin `FetchResultsJob` en `app/`, `tests/` |
| Diff | 22 archivos, 490 inserciones, 871 borrados |

## 4. Spec merge

- Nueva capability canónica: `openspec/specs/superficie-backend/spec.md`.
- Requisitos REQ-1..REQ-4 y 8 escenarios, todos COMPLIANT.

## 5. Tasks

- WU-1: 6/6.
- WU-2: 8/8.
- WU-3: 7/7.
- Criterios globales de código/tests: completos.
- Criterios de cierre (issues y CI/CD): pendientes al momento del archive; se ejecutan después del merge y push.

## 6. Follow-ups

- Retirar Vite/Tailwind/`package.json`/`.npmrc`/`resources/css|js` y ajustar `composer setup`/`dev` → issue aparte.
- Revisar `EnsureFrontendRequestsAreStateful` + excepción CSRF `api/*` + `SANCTUM_STATEFUL_DOMAINS` → decisión aparte.
- Archivar/eliminar `openspec/changes/compatibilidad-dashboard-legacy` (obsoleto: solo `tasks.md`, todo `[x]`, sin proposal/spec) → housekeeping aparte.
- `docs/dev/auditoria-logs.md` (untracked en el repo principal) conserva una referencia a `FetchResultsJob`; actualizarla si se versiona.
- Los 2 skips preexistentes no bloquean; el de `ScrapeResultsJobTest` se eliminaría creando un `Juego` con `requires_scraper=false` dentro del test.

## 7. Cierre

- Merge local a `main`: se ejecuta a continuación.
- Push a `origin/main`: se ejecuta a continuación.
- Issues #81/#82: se cierran después del push.
- CI/CD: se dispara con el push y se verifica el run.
