# Apply Progress: retiro-legacy-backend

## Estado de las work units

| WU | Issue | Estado | Commit local | Gate de tests |
|---|---|---|---|---|
| WU-1 | #82 | ✅ Completo | `c814508` | ✅ `BackendSurfaceTest` 1 passed |
| WU-2 | #81 (panel) | ✅ Completo | `f736f2e` | ✅ `BackendSurfaceTest` 4 passed (13 assertions) |
| WU-3 | #81 (job) | ✅ Completo | `0884a96` | ✅ 25 passed, 1 skipped (79 assertions) |

## WU-1 — retirar `CheckRole` (#82)

### Cambios realizados

- Borrado `backend/app/Http/Middleware/CheckRole.php`.
- `backend/bootstrap/app.php`: eliminados `use App\Http\Middleware\CheckRole;` y `'checkrole' => CheckRole::class,`.
- `docs/dev/estructura.md`: retirado `CheckRole` de la línea de `Middleware/`.
- `docs/dev/arquitectura-backend.md`: corregida la lista de alias y la fila de `Http/Middleware/`.
- Creado `backend/tests/Feature/BackendSurfaceTest.php` con `test_checkrole_alias_is_not_registered`.

### Verificación

- `php artisan test --filter=BackendSurfaceTest`: **1 passed (1 assertion)**, 0.74s.
- Gate confirmado por el usuario.

### Commit

`c814508 chore(api): retira middleware CheckRole sin uso (#82)`

## WU-2 — retirar panel web legacy y vista raíz (#81)

### Cambios realizados

- Borrado `backend/routes/web.php`.
- `backend/bootstrap/app.php`: eliminado `web: __DIR__.'/../routes/web.php',` de `withRouting`.
- Borrados `backend/app/Http/Controllers/Admin/Auth/AuthController.php` y `backend/app/Http/Controllers/Admin/ResultadoController.php`; eliminados los directorios `Admin/` y `Admin/Auth/`.
- Borrados `backend/resources/views/admin/` completo y `backend/resources/views/welcome.blade.php`; `resources/views/` queda sin archivos.
- `backend/tests/Feature/BackendSurfaceTest.php`: añadidos tests de raíz 404 JSON (con y sin `Accept`) y de ausencia de rutas `admin.*`.
- `docs/dev/estructura.md`: retirados el árbol de `Admin/`, las vistas `admin/`, `web.php` y las filas de tabla correspondientes.
- `docs/dev/arquitectura-backend.md`: actualizadas las filas de `resources/`, `routes/` y `Http/Controllers/Admin/`.

### Verificación

- `php artisan test --filter=BackendSurfaceTest`: **4 passed (13 assertions)**, 1.27s.
  - `checkrole alias is not registered`
  - `root returns json 404 with accept header`
  - `root returns json 404 without accept header`
  - `legacy admin routes are not registered`
- Gate confirmado por el usuario.

### Commit

`f736f2e chore(api): retira panel web legacy y vista raíz (#81)`

## WU-3 — retirar `FetchResultsJob` (#81)

### Cambios realizados

- Creado `backend/tests/Feature/AnimalitosScraperResultsTest.php` con los 4 tests de persistencia/dedupe movidos desde `FetchResultsJobTest`.
- Borrados `backend/tests/Feature/FetchResultsJobTest.php` y `backend/app/Jobs/FetchResultsJob.php`.
- `backend/tests/Feature/RoutePermissionCoverageTest.php`: ajustado para escanear solo `routes/api.php` (ya no existe `routes/web.php`).
- `docs/dev/estructura.md`: lista de jobs actualizada (sin `FetchResultsJob` ni `CalculateCommissionsJob`).
- `docs/dev/arquitectura-backend.md`: §5.1, §8.1, §8.4, §8.5, §8.6 y §8.7 actualizados; §8.7 ahora documenta el retiro.
- `docs/dev/plugins.md`: eliminada la sección `FetchResultsJob`; scheduler corregido a `ScheduleServiceProvider` + `console.php`; referencia al test actualizada.

### Verificación

- `php artisan test --filter="BackendSurfaceTest|AnimalitosScraperResultsTest|ScrapeSourceJobTest|ScrapeResultsJobTest|RoutePermissionCoverageTest"`: **25 passed, 1 skipped (79 assertions)**, 103.06s.
- `vendor/bin/pint --test`: **PASS**, 395 files.
- `grep -rn "FetchResultsJob" app tests`: sin salida.
- Gate confirmado por el usuario.

El único skip (`ScrapeResultsJobTest::test_scrape_results_job_returns_early_for_non_scraper_game`) es **preexistente**: el test busca un `Juego` con `requires_scraper=false` y todos los seeders del catálogo crean juegos con `requires_scraper=true`, así que ejecuta `markTestSkipped`. No es un fallo ni lo introdujo este cambio.

### Commit

`0884a96 chore(resultados): retira FetchResultsJob y ajusta cobertura de rutas (#81)`

## Verificación final pendiente (la ejecuta el usuario)

```bash
cd backend
php artisan optimize:clear
composer test:parallel   # suite completa (~8 min) o `composer test`
vendor/bin/pint --test
php artisan route:list | grep -E "admin\.|checkrole|GET\|HEAD / " || echo "OK: superficie legacy retirada"
```

Criterio: suite completa verde, Pint verde, `route:list` sin superficie legacy. Después: merge a `main` local, push a `main` de GitHub, cierre de #81/#82 y CI/CD.
