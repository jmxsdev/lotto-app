# Tasks: retiro-legacy-backend

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1,050–1,100 total |
| Review budget | 800 lines |
| 400-line budget risk | High por conteo; >90% son borrados |
| Chained PRs recommended | Yes |
| Suggested split | WU-1 (#82) → WU-2 (#81 panel) → WU-3 (#81 job) |
| Estimated per WU | WU-1 ~50 · WU-2 ~700 · WU-3 ~350 |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |
| Decision needed before apply | No — preflight ya decidió auto-chain, 800 líneas |

Cada WU queda por debajo de 800 líneas. Los commits son locales; el merge a `main` y el push se hacen al final, después de la suite completa.

## Phase 1 — WU-1: retirar `CheckRole` (#82)

- [x] 1.1 Borrar `backend/app/Http/Middleware/CheckRole.php`.
- [x] 1.2 Quitar de `backend/bootstrap/app.php` el `use App\Http\Middleware\CheckRole;` y `'checkrole' => CheckRole::class,`.
- [x] 1.3 Actualizar `docs/dev/estructura.md` (línea de `Middleware/`) y `docs/dev/arquitectura-backend.md` (alias y fila de `Http/Middleware/`).
- [x] 1.4 Crear `backend/tests/Feature/BackendSurfaceTest.php` con `test_checkrole_alias_is_not_registered`.
- [x] 1.5 Verificar: `php artisan route:list | grep -i checkrole` vacío; `php artisan test --filter=BackendSurfaceTest`; `vendor/bin/pint --test`.
- [x] 1.6 Commit local: `chore(api): retira middleware CheckRole sin uso (#82)`.

**Rollback:** revertir el commit. No hay datos ni contrato afectado.

## Phase 2 — WU-2: retirar panel web legacy y vista raíz (#81)

- [x] 2.1 Borrar `backend/routes/web.php`.
- [x] 2.2 Quitar `web: __DIR__.'/../routes/web.php',` de `backend/bootstrap/app.php`.
- [x] 2.3 Borrar `backend/app/Http/Controllers/Admin/Auth/AuthController.php` y `backend/app/Http/Controllers/Admin/ResultadoController.php`; eliminar los directorios vacíos.
- [x] 2.4 Borrar `backend/resources/views/admin/` completo y `backend/resources/views/welcome.blade.php`.
- [x] 2.5 Extender `BackendSurfaceTest` con raíz 404 JSON (con y sin `Accept`) y ausencia de `admin.login`/`admin.resultados.index`.
- [x] 2.6 Actualizar `docs/dev/estructura.md` (árbol `Admin/`, `admin/`, `web.php`, filas 187/189) y `docs/dev/arquitectura-backend.md` (resources/routes, controllers Admin).
- [x] 2.7 Verificar: `php artisan optimize:clear`; `route:list | grep -E "admin\.|GET\|HEAD / "` vacío; `php artisan test --filter=BackendSurfaceTest`; `vendor/bin/pint --test`; `curl` a `/` con y sin `Accept`.
- [x] 2.8 Commit local: `chore(api): retira panel web legacy y vista raíz (#81)`.

**Rollback:** revertir el commit. Si un consumidor externo usara `/admin`, restaurar la rama anterior.

## Phase 3 — WU-3: retirar `FetchResultsJob` (#81)

- [ ] 3.1 Crear `backend/tests/Feature/AnimalitosScraperResultsTest.php` con los 4 tests de persistencia movidos desde `FetchResultsJobTest`.
- [ ] 3.2 Borrar `backend/tests/Feature/FetchResultsJobTest.php`.
- [ ] 3.3 Borrar `backend/app/Jobs/FetchResultsJob.php`.
- [ ] 3.4 Actualizar `docs/dev/estructura.md`, `docs/dev/arquitectura-backend.md` (§5.1, §8.1, §8.4, §8.5, §8.6, §8.7), `docs/dev/plugins.md` y `docs/dev/auditoria-logs.md`.
- [ ] 3.5 Verificar: `php artisan test --filter="AnimalitosScraperResultsTest|ScrapeSourceJobTest|ScrapeResultsJobTest"`; `vendor/bin/pint --test`; `grep -rn "FetchResultsJob" backend --include="*.php" | grep -v vendor` vacío.
- [ ] 3.6 Commit local: `chore(resultados): retira FetchResultsJob y preserva cobertura del scraper (#81)`.

**Rollback:** revertir el commit. Si hay jobs encolados con la clase, drenar/limpiar antes de desplegar.

## Global Acceptance Criteria

- [ ] `php artisan route:list` no muestra rutas `admin/*`, `GET /` de `web.php` ni alias `checkrole`.
- [ ] `GET /` responde `404` JSON con y sin `Accept: application/json`.
- [ ] `backend/app/Http/Middleware/CheckRole.php`, `backend/routes/web.php`, `backend/app/Http/Controllers/Admin/`, `backend/resources/views/admin/`, `backend/resources/views/welcome.blade.php` y `backend/app/Jobs/FetchResultsJob.php` ya no existen.
- [ ] `composer test` pasa completo (suite verde).
- [ ] `vendor/bin/pint --test` pasa.
- [ ] `grep -rn "CheckRole\|checkrole\|FetchResultsJob" backend --include="*.php" | grep -v vendor` no devuelve nada.
- [ ] Issues #81 y #82 cerrados después del push a `main`.
- [ ] CI/CD de GitHub Actions verde tras el push.

## Out of Scope / Follow-ups

- Retirar Vite/Tailwind/`package.json`/`.npmrc`/`resources/css|js` y ajustar `composer setup`/`dev` → issue aparte.
- Revisar `EnsureFrontendRequestsAreStateful` + excepción CSRF `api/*` + `SANCTUM_STATEFUL_DOMAINS` → decisión aparte.
- Archivar/eliminar `openspec/changes/compatibilidad-dashboard-legacy` (obsoleto: solo `tasks.md`, todo `[x]`, sin proposal/spec) → housekeeping aparte.
