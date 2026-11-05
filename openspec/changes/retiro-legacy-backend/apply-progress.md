# Apply Progress: retiro-legacy-backend

## Estado de las work units

| WU | Issue | Estado | Commit local | Gate de tests |
|---|---|---|---|---|
| WU-1 | #82 | ✅ Completo | `c814508` | ✅ `BackendSurfaceTest` 1 passed |
| WU-2 | #81 (panel) | ✅ Completo | `f736f2e` | ✅ `BackendSurfaceTest` 4 passed (13 assertions) |
| WU-3 | #81 (job) | 🟡 En curso | — | pendiente |

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

### Cambios en curso

- Crear `backend/tests/Feature/AnimalitosScraperResultsTest.php` con los 4 tests de persistencia movidos desde `FetchResultsJobTest`.
- Borrar `backend/tests/Feature/FetchResultsJobTest.php`.
- Borrar `backend/app/Jobs/FetchResultsJob.php`.
- Actualizar `docs/dev/estructura.md`, `docs/dev/arquitectura-backend.md`, `docs/dev/plugins.md` y `docs/dev/auditoria-logs.md`.

### Verificación pendiente (la ejecuta el usuario)

```bash
cd backend
php artisan optimize:clear
php artisan test --filter="AnimalitosScraperResultsTest|ScrapeSourceJobTest|ScrapeResultsJobTest"
vendor/bin/pint --test
grep -rn "FetchResultsJob" app tests || echo "OK: sin FetchResultsJob"
```

### Criterio para avanzar

- `AnimalitosScraperResultsTest` verde (4 tests).
- `ScrapeSourceJobTest` y `ScrapeResultsJobTest` verdes.
- Pint verde.
- Sin referencias a `FetchResultsJob` en `app/` ni `tests/`.

Si pasa: commit local `chore(resultados): retira FetchResultsJob y preserva cobertura del scraper (#81)` y pasamos a verificación final.
