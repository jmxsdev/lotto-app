# Design: retiro-legacy-backend

## Context

El backend ya es API-only en su capa de arranque, pero conserva una superficie web legacy y un middleware de roles sin uso. El cambio es una retirada de código muerto, no un rediseño: el contrato `/api/v1`, los permisos Spatie, las policies y el scoping no se tocan.

## Decisions

| # | Decisión | Opciones | Elección | Razón |
|---|---|---|---|---|
| 1 | Respuesta de `GET /` | `404` JSON / `200 {"status":"ok"}` | `404` JSON | Coherente con API-only; `/up` ya es el healthcheck y producción usa `/api/v1/juegos`. |
| 2 | Momento de retirar `FetchResultsJob` | En #81 / en otro cambio | En #81 (WU-3) | Solo lo usa `Admin\ResultadoController::scrape`; dejarlo sería un job huérfano. |
| 3 | Cobertura de `AnimalitosScraper` | Borrar el test legacy / mover los tests útiles | Mover los 4 tests de persistencia | El test actual prueba el scraper, no el job; moverlo preserva dedupe y `saveResults`. |
| 4 | Excepción CSRF `api/*` | Retirar / mantener | Mantener | `EnsureFrontendRequestsAreStateful` y `SANCTUM_STATEFUL_DOMAINS` siguen configurados; retirarla sin revisar Sanctum stateful puede producir 419. |
| 5 | Vite/Tailwind | Incluir / diferir | Diferir a follow-up | Solo sirven a `welcome`; la retirada toca `composer setup/dev` y no aporta al objetivo. |
| 6 | Forma de entrega | Un PR / 3 WUs encadenadas | 3 WUs locales, commits por WU | Cada slice queda por debajo de 800 líneas y #82 se revisa aislado. |
| 7 | Orden de las WUs | #81 primero / #82 primero | #82 primero | Es independiente, diminuta y elimina el alias antes de tocar el routing web. |

## File Map

### Delete

| Archivo | WU |
|---|---|
| `backend/app/Http/Middleware/CheckRole.php` | WU-1 |
| `backend/routes/web.php` | WU-2 |
| `backend/app/Http/Controllers/Admin/Auth/AuthController.php` | WU-2 |
| `backend/app/Http/Controllers/Admin/ResultadoController.php` | WU-2 |
| `backend/resources/views/admin/auth/login.blade.php` | WU-2 |
| `backend/resources/views/admin/layouts/app.blade.php` | WU-2 |
| `backend/resources/views/admin/resultados/index.blade.php` | WU-2 |
| `backend/resources/views/welcome.blade.php` | WU-2 |
| `backend/app/Jobs/FetchResultsJob.php` | WU-3 |
| `backend/tests/Feature/FetchResultsJobTest.php` | WU-3 |

Directorios que quedan vacíos: `backend/app/Http/Controllers/Admin/` (con `Auth/`), `backend/resources/views/admin/` (con `auth/`, `layouts/`, `resultados/`) y `backend/resources/views/` tras borrar `welcome`.

### Modify

| Archivo | WU | Cambio |
|---|---|---|
| `backend/bootstrap/app.php` | WU-1, WU-2 | Quitar import y alias `CheckRole`; quitar `web:` de `withRouting`. |
| `docs/dev/estructura.md` | WU-1, WU-2, WU-3 | Quitar `CheckRole`, `Admin/`, `FetchResultsJob`, `admin/`, `web.php` y filas de tabla. |
| `docs/dev/arquitectura-backend.md` | WU-1, WU-2, WU-3 | Ajustar alias, middleware, controllers, resources, routes, jobs y §8.7. |
| `docs/dev/plugins.md` | WU-3 | Quitar sección `FetchResultsJob` y corregir referencia al test. |
| `docs/dev/auditoria-logs.md` | WU-3 | Quitar la escritura de `FetchResultsJob`. |

### Create

| Archivo | WU | Contenido |
|---|---|---|
| `backend/tests/Feature/BackendSurfaceTest.php` | WU-1, WU-2 | Alias ausente; raíz 404 JSON; sin rutas `admin.*`. |
| `backend/tests/Feature/AnimalitosScraperResultsTest.php` | WU-3 | Tests movidos de persistencia/dedupe de `AnimalitosScraper`. |

## Verification

```bash
cd backend
php artisan optimize:clear
php artisan route:list | grep -E "admin\.|checkrole|GET\|HEAD / "   # vacío
php artisan test --filter=BackendSurfaceTest
php artisan test --filter="AnimalitosScraperResultsTest|ScrapeSourceJobTest|ScrapeResultsJobTest"
vendor/bin/pint --test
curl -i -H 'Accept: application/json' http://localhost:8000/       # 404 JSON
curl -i http://localhost:8000/                                     # 404 JSON
composer test                                                      # suite completa
```

## Risks

- **Consumidor externo de `/` o `/admin`**: no hay referencias en el repo; el healthcheck de producción no usa la raíz.
- **Job encolado con clase borrada**: `FetchResultsJob` usa `dispatchSync`; revisar `jobs`/Horizon antes del deploy.
- **Caché de rutas/vistas**: `optimize:clear` antes de verificar.
- **Pérdida de cobertura**: mover los 4 tests de persistencia antes de borrar `FetchResultsJobTest`.

## Rollback

Revertir los commits de las WUs. Sin migraciones ni datos. En producción, redeploy de la imagen anterior.
