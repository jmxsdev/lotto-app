# Proposal: retiro-legacy-backend — retirar el panel web legacy (#81) y el middleware CheckRole (#82)

## Intent

El backend de lotto-app es API-only: `bootstrap/app.php` fuerza JSON en todas las respuestas, `redirectGuestsTo` lanza `AuthenticationException` (401) y la autenticación real vive en Sanctum (`POST /api/v1/login`). Sin embargo, todavía conserva una superficie web legacy que no aporta funcionalidad: rutas `admin/*` con sesión, controladores y vistas Blade, la vista default `welcome.blade.php` sirviendo `GET /`, y el middleware `CheckRole`, que duplica lo que ya hacen Spatie `permission:`/`role_or_permission:` y las policies.

Este cambio retira esa superficie muerta y deja una sola regla explícita: el backend solo expone API JSON y endpoints operativos. No cambia comportamiento de negocio, no toca la base de datos y no modifica el contrato de `/api/v1`.

## Evidencia actual

- `backend/routes/web.php` (25 líneas): `GET /` → `view('welcome')`, login/logout por sesión y `admin/resultados` + `admin/resultados/scrape`.
- `backend/app/Http/Controllers/Admin/Auth/AuthController.php` (58 líneas): login con `Auth::login()` y `session()->regenerate()`.
- `backend/app/Http/Controllers/Admin/ResultadoController.php` (49 líneas): listado y `FetchResultsJob::dispatchSync()`.
- `backend/resources/views/admin/` (3 Blade) y `backend/resources/views/welcome.blade.php` (223 líneas).
- `backend/app/Http/Middleware/CheckRole.php` (27 líneas) y alias `checkrole` en `bootstrap/app.php`.
- `grep -rn "checkrole\|CheckRole" backend --include="*.php"` solo devuelve la clase y el alias; ninguna ruta lo usa.
- `grep` en `backend/tests/`, `panel/`, `taquilla/` y `collections/` no encuentra consumidores de `/admin`, `welcome` ni `CheckRole`.
- `docker-compose.prod.yml` usa `/api/v1/juegos` como healthcheck; Laravel expone `/up`; la raíz no se consume.
- `docs/dev/arquitectura-backend.md` §8.7 ya documenta que `FetchResultsJob` solo lo usa el panel web legacy y que debe retirarse junto con #81.
- `be04-permisos` (archivado 2026-11-05) migró `role:` → `permission:` y eliminó el alias `role`, pero dejó `checkrole`: #82 es el residuo directo de ese ciclo.

## Scope

### In Scope

- **#82**: eliminar `CheckRole.php` y el alias `checkrole` de `bootstrap/app.php`.
- **#81**: eliminar `routes/web.php`, el registro `web:` de `withRouting`, `app/Http/Controllers/Admin/` completo, `resources/views/admin/` completo y `resources/views/welcome.blade.php`.
- **#81**: eliminar `FetchResultsJob` y su test, preservando la cobertura de persistencia/dedupe de `AnimalitosScraper` en un test propio.
- **#81**: definir `GET /` como `404` JSON, coherente con API-only.
- Tests: nuevo `BackendSurfaceTest` (raíz 404 JSON, sin rutas `admin.*`, sin alias `checkrole`) y `AnimalitosScraperResultsTest` (tests movidos).
- Documentación de estado actual: `docs/dev/estructura.md`, `docs/dev/arquitectura-backend.md`, `docs/dev/plugins.md`, `docs/dev/auditoria-logs.md`.

### Out of Scope

- Retirar Vite/Tailwind/`package.json`/`.npmrc`/`resources/css|js`: solo sirven a `welcome`; toca `composer setup/dev` y merece issue aparte.
- Retirar la excepción CSRF `api/*` o `EnsureFrontendRequestsAreStateful`: `SANCTUM_STATEFUL_DOMAINS` sigue configurado y la decisión requiere revisar auth por cookie.
- Cambios de dominio, endpoints `/api/v1`, permisos, policies, scoping o base de datos.
- Reescribir `docs/dev/planificacion.md` (log histórico de sprints).

## Decisions

| # | Decisión | Elección | Razón |
|---|---|---|---|
| 1 | Respuesta de `GET /` | `404` JSON | API-only; `/up` es healthcheck y producción usa `/api/v1/juegos`. Un `200 {"status":"ok"}` añadiría un endpoint artificial. |
| 2 | `FetchResultsJob` | Retirar en este cambio | Solo lo usa `Admin\ResultadoController::scrape`; el camino canónico es `ScrapeSourceJob`/`ScrapeResultsJob`. |
| 3 | Test de `FetchResultsJob` | Mover los 4 tests de persistencia a `AnimalitosScraperResultsTest` | El test actual prueba `AnimalitosScraper` (parse/save/dedupe), no el job; borrarlo perdería cobertura real. |
| 4 | Excepción CSRF `api/*` | Mantener | `EnsureFrontendRequestsAreStateful` sigue activo; retirarla sin revisar Sanctum stateful puede producir 419. |
| 5 | Vite/Tailwind | Fuera de alcance | Solo sirven a `welcome`; la retirada toca scripts de Composer y CI, y no aporta al objetivo de #81/#82. |
| 6 | Entrega | 3 work units, commits locales, merge final a `main` | Permite revisar #82 aislado y mantiene cada slice por debajo del presupuesto de 800 líneas. |

## Capabilities

### New Capabilities

- `superficie-backend`: contrato de superficie API-only (rutas, raíz JSON, autorización Spatie, scraping manual por API) y ausencia de UI web legacy.

### Modified Capabilities

- Ninguna. `autorizacion-capability`, `catalogo-permisos` y `arquitectura-alcance` no cambian: este cambio retira código muerto, no rediseña autorización ni scoping.

## Approach

1. **WU-1 (#82)**: borrar `CheckRole`, limpiar `bootstrap/app.php` y docs; test de alias ausente.
2. **WU-2 (#81 panel)**: borrar rutas web, controllers Admin, vistas Blade y `welcome`; quitar `web:` del routing; test de raíz 404 JSON y ausencia de rutas `admin.*`.
3. **WU-3 (#81 job)**: mover tests de persistencia de Animalitos, borrar `FetchResultsJob` y su test, actualizar docs de scraping.
4. Verificación final: `route:list`, suite completa, Pint y `curl` a `/`.
5. Merge local a `main`, verificación del usuario y push a GitHub.

## Risks

- **Consumidor externo desconocido de `/` o `/admin`**: no hay referencias en el repo; el healthcheck de producción no usa la raíz. Mitigación: revisar access logs antes del deploy si están disponibles.
- **Job encolado con clase borrada**: `FetchResultsJob` se usa con `dispatchSync`, no debería haber instancias en cola. Mitigación: revisar `jobs`/Horizon antes del deploy.
- **Pérdida de cobertura del scraper**: mitigado moviendo los 4 tests de persistencia antes de borrar el test viejo.
- **Caché de rutas/vistas**: `route:list` puede mostrar rutas cacheadas. Mitigación: `php artisan optimize:clear` después de borrar.

## Rollback

Revertir los commits de las WUs. No hay migraciones, datos ni cambios de contrato. Si el problema aparece en producción, redeploy de la imagen anterior.
