# Apply Progress: retiro-legacy-backend

## Estado de las work units

| WU | Issue | Estado | Commit local | Gate de tests |
|---|---|---|---|---|
| WU-1 | #82 | ✅ Completo | `c814508` | ✅ `BackendSurfaceTest` 1 passed |
| WU-2 | #81 (panel) | 🟡 En curso | — | pendiente |
| WU-3 | #81 (job) | Pendiente | — | — |

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

### Cambios en curso

- Borrar `backend/routes/web.php`.
- Quitar `web: __DIR__.'/../routes/web.php',` de `backend/bootstrap/app.php`.
- Borrar `backend/app/Http/Controllers/Admin/` (2 controllers).
- Borrar `backend/resources/views/admin/` (3 Blade) y `backend/resources/views/welcome.blade.php`.
- Extender `BackendSurfaceTest` con raíz 404 JSON y ausencia de rutas `admin.*`.
- Actualizar `docs/dev/estructura.md` y `docs/dev/arquitectura-backend.md`.

### Verificación pendiente (la ejecuta el usuario)

```bash
cd backend
php artisan optimize:clear
php artisan route:list | grep -E "admin\.|GET\|HEAD / " || echo "OK: sin rutas legacy"
php artisan test --filter=BackendSurfaceTest
vendor/bin/pint --test
```

### Criterio para avanzar

- `route:list` sin `admin.*` ni `GET /`.
- `BackendSurfaceTest` verde (raíz 404 JSON + alias ausente).
- Pint verde.

Si pasa: commit local `chore(api): retira panel web legacy y vista raíz (#81)` y arranca WU-3.
