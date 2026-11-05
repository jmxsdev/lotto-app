# Design: BE-04 — Catálogo de permisos por capability y migración `role:` → `permission:`

## Enfoque técnico

BE-04 sustituye la autorización de recursos por nombre de rol por autorización por capability. El seeder `RolesAndPermissionsSeeder` pasa a ser la fuente única del catálogo (34 permisos con guard `api`) y de la matriz capability × rol; `AppServiceProvider` registra un `Gate::before` que da bypass total a `super_master` **solo para abilities del catálogo**; y `routes/api.php` migra sus 24 grupos `role:` a `permission:` (por grupo o por método en CRUD mixto). El row scoping no se toca: sigue viviendo en `AlcanceService`/policies.

La migración es de **middleware**, no de modelo de datos. Cada work unit deja la suite verde y es reversible. El catálogo se despliega antes que las rutas: al ser aditivo, correr el seeder con las rutas viejas no cambia quién entra.

## Flujo de autorización

```
Request
  → auth:sanctum
  → verify.mac
  → permission:<capability>            (Spatie PermissionMiddleware → $user->canAny)
       → Gate::before (BE-04)          super_master + ability ∈ catálogo → true
       → Gate::before (Spatie)         checkPermissionTo (permiso asignado al rol)
  → Controller
       → Policy / $this->authorize     (acción puntual: view/update/delete)
       → AlcanceService                (scoping de filas, sin cambios)
  → Response (403 JSON si deniega)
```

Seeder:

```
forgetCachedPermissions()
  → firstOrCreate de los 34 permisos
  → delete de manage_users / manage_apuestas
  → syncPermissions(matriz) por rol (super_master → [])
  → forgetCachedPermissions()
```

## Decisiones de arquitectura

| # | Decisión | Opciones consideradas | Elección y razón |
|---|---|---|---|
| D1 | Bypass de `super_master` | (a) `Gate::before` global; (b) `role:super_master` residual; (c) `Gate::before` acotado al catálogo | **(c)**. Un `before` global también saltaría `UserPolicy`, `AlcanceService` y `Gate::denies('view', $apuesta)` de `PagoController`, alterando el scoping de #85. Se consulta `PermissionRegistrar::getPermissions(['name'=>$ability,'guard_name'=>'api'])`; si la ability no es un permiso, devuelve `null` y el gate sigue su curso. Cubre permisos futuros en cuanto el caché se refresca. |
| D2 | Granularidad por método en `apiResource` mixtos | (a) middleware en constructor del controller; (b) `role_or_permission`; (c) dividir `apiResource` con `only()` + `middleware()` | **(c)**. Mantiene la superficie de autorización visible en `routes/api.php`, que hoy es la fuente única; evita autorización oculta en controllers. El patrón comentado de `GrupoController`/`TaquillaController` se elimina. |
| D3 | Prioridad ante conflicto matriz vs acceso efectivo | (a) aplicar la matriz del spec; (b) preservar el acceso actual | **(b)**, con las concesiones explícitas de BE-04. El requisito de “cero cambio de comportamiento” es más fuerte que las celdas del borrador. Ver tabla de reconciliación. |
| D4 | Permiso para `/tickets` | (a) `view_apuestas`/`create_apuesta`; (b) permiso nuevo `view_tickets`/`manage_tickets` (catálogo 36); (c) guard de rol en controller | **(a) con ajuste**: todas las rutas de tickets usan `permission:create_apuesta`, y `create_apuesta` **no** se asigna a `agencia`. Motivo: `AlcanceService::applyToTickets()` no filtra filas para `agencia` (paridad con `autorizarScope`, que hoy es código dormido porque la ruta la excluye). Darle `view_apuestas` abriría una fuga de tickets de todo el sistema. `agencia` ya recibe 403 en `POST /apuestas` por `taquilla_id` nulo, así que retirarle `create_apuesta` no cambia ningún status efectivo. |
| D5 | Seeder: alcance de la reconciliación | (a) borrar todo permiso no listado; (b) borrar solo los retirados | **(b)**. Se eliminan explícitamente `manage_users` y `manage_apuestas`; permisos desconocidos de terceros se toleran (no se destruyen datos). `syncPermissions` sí reemplaza la matriz de cada rol: es el contrato. |
| D6 | Permisos de `super_master` en BD | (a) asignar todos; (b) dejarlos vacíos | **(b)**. El bypass no depende de filas; el escenario del spec exige que funcione “sin permisos asignados”. Además evita que un permiso futuro quede disponible solo si se re-ejecuta el seeder. |
| D7 | `role:` residual | (a) conservar alias `role`; (b) eliminarlo junto con el grupo vacío de `web.php` | **(b)**. Tras la migración no queda ningún `role:` en rutas; login/activación/`verify.mac` usan checks de código (`hasRole`) y no el middleware. Menos superficie de regresión. |
| D8 | Orden de despliegue | (a) todo junto; (b) catálogo primero y rutas después | **(b)**. El entrypoint solo siembra cuando la tabla `migrations` no existe; en una BD existente hay que ejecutar el seeder a mano. Sembrar antes de las rutas evita 403 masivos por permisos inexistentes. |

## Reconciliación matriz × acceso efectivo

`super_master` se omite (bypass). “Hoy” es el acceso efectivo con el código actual (middleware + controller/policy), no solo la ruta.

| Punto | Matriz borrador | Acceso efectivo hoy | Decisión final | Efecto |
|---|---|---|---|---|
| Usuarios / taquilla | — | `GET /users` y `/users/{self}` 200 (self), resto 403 | `view_users` a taquilla | Preserva lectura propia; `store/update/destroy` siguen 403 por no tener `create/edit/delete_users` |
| Usuarios / master | v,c,e | destroy 200 (rol lo dejaba pasar, policy lo permitía) | `delete_users` a master | Preserva #84 |
| Usuarios / banca, grupo, agencia | v,c,e | destroy 200 (#84) | `delete_users` a los tres | Evita romper #84 |
| Agencias / agencia | v | `PUT /agencias/{self}` 200 (#84) | `manage_agencias` a agencia | `store/toggle/destroy` siguen 403 por `authorizeGestion`; `update` ya está acotado al propio local |
| Pagos / agencia | v,c | 403 (ruta excluye agencia) | Sin `view_pagos`/`create_pago` para agencia | Preserva 403 |
| Exchange rates / banca, grupo, agencia, taquilla | v | 403 (solo master tiene `view_exchange_rates`) | Sin `view_exchange_rates` para esos roles | Preserva 403; el panel usa `/exchange-rate/active` público |
| Tickets / agencia | “apuestas sin agencia” | 403 (ruta excluye agencia) | `create_apuesta` retirado de agencia; tickets usan ese permiso | Preserva 403 y evita fuga sin scoping (D4) |
| Releases / taquilla | `download_release` | 403 | `download_release` a taquilla | Cambio deliberado de BE-04; `serve` firmado no cambia |
| Reportes / taquilla | `view_reports` | 200 (rol) | `view_reports` a taquilla | Preserva acceso con filas de #85 |
| Config / master | `manage_config` | 200 (rol) | `manage_config` a master | Preserva |
| Comisiones / master | v | GET defaults 403; PUT defaults 403; consulta 200 | `view_comisiones` a master; `manage_comisiones` sin rol | Preserva 403 en defaults y 200 en consulta |
| Juegos/resultados GET | todos | 200 a cualquier autenticado (sin middleware) | `view_juegos`/`view_resultados` a los 5 roles | Preserva; usuarios sin rol pasan a fail-closed |

> Estas celdas corrigen el borrador del spec; al implementar hay que reflejarlas en `specs/catalogo-permisos/spec.md` y en `docs/dev/permisos-capabilities.md`.

## Catálogo final (34 permisos, guard `api`)

El seeder vigente declara **24** permisos (no 27: verificado por conteo). `24 − manage_users + 11 faltantes = 34`.

| Dominio | Permisos |
|---|---|
| Usuarios | `view_users` `create_users` `edit_users` `delete_users` |
| Bancas | `view_bancas` `manage_bancas` |
| Grupos | `view_grupos` `manage_grupos` |
| Agencias | `view_agencias` `manage_agencias` |
| Taquillas | `view_taquillas` `manage_taquillas` |
| Juegos | `view_juegos` `manage_juegos` |
| Resultados | `view_resultados` `manage_resultados` |
| Apuestas | `view_apuestas` `create_apuesta` `delete_apuesta` |
| Pagos | `view_pagos` `create_pago` |
| Cierres | `view_cierre` `create_cierre` |
| Límites | `view_limites` `manage_limites` |
| Comisiones | `view_comisiones` `manage_comisiones` |
| Exchange rates | `view_exchange_rates` `manage_exchange_rates` |
| Reportes | `view_reports` |
| Logs | `view_logs` |
| Releases | `download_release` |
| Clave cierre | `manage_clave_cierre` |
| Config | `manage_config` |

`manage_comisiones` permanece en catálogo pero **sin rol asignado**: hoy solo `super_master` ejercita `PUT /comisiones/defaults` vía bypass. Se conserva por compatibilidad de nombre.

## Matriz final capability × rol

`v`=view, `m`=manage, `c`=create, `e`=edit, `d`=delete. `super_master` = bypass (sin filas).

| Dominio | master | banca | grupo | agencia | taquilla |
|---|---|---|---|---|---|
| Usuarios | v,c,e,d | v,c,e,d | v,c,e,d | v,c,e,d | v |
| Bancas | v,m | — | — | — | — |
| Grupos | v,m | v,m | — | — | — |
| Agencias | v,m | v,m | v,m | v,m* | — |
| Taquillas | v,m | v,m | v,m | v,m | — |
| Juegos | v,m | v | v | v | v |
| Resultados | v,m | v | v | v | v |
| Apuestas | v,c,d | v,c,d | v,c,d | v,d | v,c,d |
| Pagos | v,c | v,c | v,c | — | v,c |
| Cierres | v,c | v,c | v,c | v,c | v,c |
| Límites | v,m | v,m | v,m | v | — |
| Comisiones | v | — | — | — | — |
| Exchange rates | v,m | — | — | — | — |
| Reportes | v | v | v | v | v |
| Logs | v | — | — | — | — |
| Releases | v | v | v | v | v |
| Clave cierre | m | m | — | — | — |
| Config | m | — | — | — | — |

`*` `agencia` recibe `manage_agencias` para editar su propia ficha; el controller bloquea `store`, `toggle` y `destroy` para ese rol.

Asignación exacta para el seeder (además de `super_master` sin permisos):

- **master (33)**: users v/c/e/d; bancas v/m; grupos v/m; agencias v/m; taquillas v/m; juegos v/m; resultados v/m; apuestas v/c/d; pagos v/c; cierres v/c; límites v/m; comisiones v; exchange v/m; reportes v; logs v; releases v; clave m; config m.
- **banca (24)**: users v/c/e/d; grupos v/m; agencias v/m; taquillas v/m; juegos v; resultados v; apuestas v/c/d; pagos v/c; cierres v/c; límites v/m; reportes v; releases v; clave m.
- **grupo (21)**: users v/c/e/d; agencias v/m; taquillas v/m; juegos v; resultados v; apuestas v/c/d; pagos v/c; cierres v/c; límites v/m; reportes v; releases v.
- **agencia (17)**: users v/c/e/d; agencias v/m; taquillas v/m; juegos v; resultados v; apuestas v/d; cierres v/c; límites v; reportes v; releases v.
- **taquilla (12)**: users v; juegos v; resultados v; apuestas v/c/d; pagos v/c; cierres v/c; reportes v; releases v.

## Mapeo ruta → permiso

`super_master` pasa siempre por bypass y se omite en la columna de roles.

| Rutas | Método | Middleware | Roles que pasan |
|---|---|---|---|
| `/usuarios/clave-cierre` | GET, PUT | `permission:manage_clave_cierre` | master, banca |
| `/users` | GET (index) | `permission:view_users` | master, banca, grupo, agencia, taquilla |
| `/users/{user}` | GET (show) | `permission:view_users` | ídem (taquilla solo self por policy) |
| `/users` | POST | `permission:create_users` | master, banca, grupo, agencia |
| `/users/{user}` | PUT/PATCH | `permission:edit_users` | master, banca, grupo, agencia |
| `/users/{user}` | DELETE | `permission:delete_users` | master, banca, grupo, agencia |
| `/bancas` | GET | `permission:view_bancas` | master |
| `/bancas`, `/bancas/{banca}` | POST, PUT/PATCH, DELETE | `permission:manage_bancas` | master |
| `/bancas/{banca}/toggle` | PATCH | `permission:manage_bancas` | master |
| `/grupos` | GET | `permission:view_grupos` | master, banca |
| `/grupos`, `/grupos/{grupo}` | POST, PUT/PATCH, DELETE | `permission:manage_grupos` | master, banca |
| `/grupos/{grupo}/toggle` | PATCH | `permission:manage_grupos` | master, banca |
| `/agencias` | GET | `permission:view_agencias` | master, banca, grupo, agencia |
| `/agencias`, `/agencias/{agencia}` | POST, PUT/PATCH, DELETE | `permission:manage_agencias` | master, banca, grupo, agencia |
| `/agencias/{agencia}/toggle` | PATCH | `permission:manage_agencias` | master, banca, grupo, agencia |
| `/taquillas` | GET | `permission:view_taquillas` | master, banca, grupo, agencia |
| `/taquillas`, `/taquillas/{taquilla}` | POST, PUT/PATCH, DELETE | `permission:manage_taquillas` | master, banca, grupo, agencia |
| `/taquillas/{taquilla}/toggle` | PATCH | `permission:manage_taquillas` | master, banca, grupo, agencia |
| `/configuraciones/apuestas-vencimiento` | GET, PUT | `permission:manage_config` | master |
| `/juegos`, `/juegos/{juego}`, `/juegos/{juego}/opciones|horarios|reglas` | GET | `permission:view_juegos` | master, banca, grupo, agencia, taquilla |
| `/juegos/{juego}`, `/juegos/{juego}/premios` | PUT | `permission:manage_juegos` | master |
| `/juegos/{juego}/toggle` | PATCH | `permission:manage_juegos` | master |
| `/resultados`, `/resultados/{resultado}`, `/resultados/{resultado}/apariciones` | GET | `permission:view_resultados` | master, banca, grupo, agencia, taquilla |
| `/resultados/scrape`, `/resultados/scrape-all` | POST | `permission:manage_resultados` | master |
| `/apuestas`, `/apuestas/historial`, `/apuestas/resumen`, `/apuestas/{apuesta}` | GET | `permission:view_apuestas` | master, banca, grupo, agencia, taquilla |
| `/apuestas` | POST | `permission:create_apuesta` | master, banca, grupo, agencia, taquilla |
| `/apuestas/{apuesta}` | DELETE | `permission:delete_apuesta` | master, banca, grupo, agencia, taquilla |
| `/tickets`, `/tickets/{ticket}`, `/tickets/ganadores` | GET | `permission:create_apuesta` | master, banca, grupo, taquilla |
| `/tickets` | POST | `permission:create_apuesta` | master, banca, grupo, taquilla |
| `/tickets/{ticket}` | DELETE | `permission:create_apuesta` | master, banca, grupo, taquilla |
| `/pagos` | POST | `permission:create_pago` | master, banca, grupo, taquilla |
| `/pagos/{apuesta}` | GET | `permission:view_pagos` | master, banca, grupo, taquilla |
| `/cierre` | POST | `permission:create_cierre` | master, banca, grupo, agencia, taquilla |
| `/cierre`, `/cierre/actual`, `/cierre/semanal`, `/cierre/{cierre}` | GET | `permission:view_cierre` | master, banca, grupo, agencia, taquilla |
| `/limites`, `/limites/{juego}` | GET | `permission:view_limites` | master, banca, grupo, agencia |
| `/limites/{juego}`, `/limites/{limite}`, `/limites/batch` | PUT, DELETE, POST | `permission:manage_limites` | master, banca, grupo |
| `/logs` | GET | `permission:view_logs` | master |
| `/comisiones/defaults` | GET, PUT | `permission:manage_comisiones` | solo bypass (`super_master`) |
| `/comisiones/consulta` | GET | `permission:view_comisiones` | master |
| `/reportes/*` | GET | `permission:view_reports` | master, banca, grupo, agencia, taquilla |
| `/estadisticas/rendimiento` | GET | `permission:view_reports` | master, banca, grupo, agencia, taquilla |
| `/exchange-rates`, `/exchange-rates/{id}` | GET | `permission:view_exchange_rates` | master |
| `/exchange-rates`, `/exchange-rates/scrape`, `/exchange-rates/{id}`, `/exchange-rates/{id}/set-active` | POST, PUT | `permission:manage_exchange_rates` | master |
| `/releases/latest`, `/releases/download` | GET | `permission:download_release` | master, banca, grupo, agencia, taquilla |
| públicas/autenticación | — | sin `permission:` | login, activar, dispositivo/verificar, exchange-rate/active, releases/serve (signed), update-check, releases/feed, user, logout |

Patrón de migración para CRUD mixto (no usar middleware en controllers):

```php
Route::apiResource('users', UserController::class)
    ->only(['index', 'show'])->middleware('permission:view_users');
Route::apiResource('users', UserController::class)
    ->only(['store'])->middleware('permission:create_users');
Route::apiResource('users', UserController::class)
    ->only(['update'])->middleware('permission:edit_users');
Route::apiResource('users', UserController::class)
    ->only(['destroy'])->middleware('permission:delete_users');
```

## Contrato de `Gate::before`

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

Gate::before(function (User $user, string $ability) {
    if (! $user->hasRole('super_master')) {
        return null;
    }

    $esPermisoApi = app(PermissionRegistrar::class)
        ->getPermissions(['name' => $ability, 'guard_name' => 'api'])
        ->isNotEmpty();

    return $esPermisoApi ? true : null;
});
```

Reglas del contrato:

- Devuelve `true` o `null`; **nunca `false`**, para no denegar policies cuando la ability no es un permiso.
- No usa `$user->getAllPermissions()`: el bypass no depende de asignaciones.
- Si el permiso aún no está en caché (despliegue antes del seeder), devuelve `null` y la ruta responde 403, no 500: `PermissionMiddleware` usa `canAny()`, no `hasPermissionTo()`.
- No interfiere con `Gate::denies('view', $apuesta)` de `PagoController` ni con `UserPolicy`/`AlcanceService`.

## Cambios por archivo

| Archivo | Acción | Descripción |
|---|---|---|
| `backend/database/seeders/RolesAndPermissionsSeeder.php` | Modificar | Constantes de catálogo/matriz; 34 permisos; borrar `manage_users`/`manage_apuestas`; `syncPermissions` por rol; `super_master` sin permisos; `forgetCachedPermissions()` al inicio y al final |
| `backend/app/Providers/AppServiceProvider.php` | Modificar | `Gate::before` acotado al catálogo |
| `backend/routes/api.php` | Modificar | 24 grupos `role:` → `permission:`; splits por método en users/bancas/grupos/agencias/taquillas; cobertura de juegos/resultados/tickets |
| `backend/routes/web.php` | Modificar | Eliminar el grupo vacío con `role:super_master|master` |
| `backend/bootstrap/app.php` | Modificar | Quitar import y alias `role` (queda sin uso); conservar `permission` y `role_or_permission` |
| `backend/app/Http/Controllers/Api/GrupoController.php` | Modificar | Eliminar bloque `middleware('permission:...')` comentado |
| `backend/app/Http/Controllers/Api/TaquillaController.php` | Modificar | Eliminar bloque comentado |
| `backend/tests/Feature/PermissionCatalogTest.php` | Crear | Catálogo, matriz, bypass, prohibición de permisos directos |
| `backend/tests/Feature/RoutePermissionCoverageTest.php` | Crear | Toda ruta autenticada tiene `permission:`; 0 `role:` |
| `backend/tests/Feature/TicketsAuthorizationTest.php` | Crear | `agencia` 403 en tickets; 4 roles operativos 200 |
| `backend/tests/Feature/TaquillaPermisosTest.php` | Crear | Reportes propios, releases, límites de acceso |
| `backend/tests/Feature/ReleaseTest.php` | Modificar | `taquilla` pasa de 403 a 200 en `/releases/latest` (cambio deliberado) |
| `docs/dev/permisos-capabilities.md` | Crear | Catálogo, matriz final, mapeo de rutas, reglas y operación del seeder |
| `docs/dev/pendientes-front.md` | Modificar | Corregir fila BE-04 (sin `manage_apuestas`; enlazar al doc nuevo) |
| `openspec/changes/be04-permisos/specs/catalogo-permisos/spec.md` | Modificar | Enmienda de las celdas reconciliadas (exchange, pagos, agencia, tickets, delete_users, view_users taquilla) |

## Fases / PRs (≤800 líneas cada uno)

Todos los WU se desarrollan sobre `feature/be04-permisos`; si se publican como stack, cada uno targetea al anterior y el push final se hace una sola vez al cierre contra `main` (según el proposal). Los tests los corre el usuario por WU.

| WU | Rama (base) | Alcance | Archivos | Rollback | Líneas est. |
|---|---|---|---|---|---|
| WU-1 | `be04/wu1-catalogo` (`feature/be04-permisos`) | Catálogo, seeder, `Gate::before`, doc de matriz, enmienda del spec, tests de catálogo | seeder, provider, `docs/dev/permisos-capabilities.md`, spec, `PermissionCatalogTest` | `git revert`; re-ejecutar seeder anterior; los permisos extra son inocuos | ~525 |
| WU-2 | `be04/wu2-usuarios-entidades` (WU-1) | Migrar users, bancas, grupos, agencias, taquillas, config y clave-cierre; limpiar `web.php`/alias/comentarios; tests de entidades | `routes/api.php`, `routes/web.php`, `bootstrap/app.php`, 2 controllers, tests | `git revert`; las rutas vuelven a `role:` | ~340 |
| WU-3 | `be04/wu3-operacion` (WU-2) | Migrar juegos, resultados, apuestas, tickets, pagos, cierres y límites; tests de operación y tickets | `routes/api.php`, tests | `git revert` | ~350 |
| WU-4 | `be04/wu4-admin-reportes` (WU-3) | Migrar reportes, estadísticas, logs, comisiones, exchange y releases; `ReleaseTest`; coverage test final; doc final | `routes/api.php`, `ReleaseTest`, tests nuevos, docs | `git revert`; re-ejecutar seeder anterior si se revierte la matriz | ~310 |

WU-1 es **behavior-neutral**: las rutas siguen en `role:`, por lo que los cambios de matriz no alteran el acceso. WU-2..4 migran grupos completos con suite verde entre cada uno.

## Testing Strategy

| Capa | Qué se prueba | Enfoque |
|---|---|---|
| Catálogo (Feature) | 34 permisos con guard `api`; nombres exactos; `manage_users`/`manage_apuestas` ausentes; `super_master` sin permisos y con bypass; matriz exacta por rol | `PermissionCatalogTest` con `RefreshDatabase` + `RolesAndPermissionsSeeder`; data provider rol→permisos |
| Arquitectura (Feature) | 0 `role:` en `routes/api.php`/`web.php`; toda ruta con `auth:sanctum`/`verify.mac` tiene al menos un `permission:` (allowlist: `/user`, `/logout`); sin `givePermissionTo`/`syncPermissions` sobre `User` en `app/` | `RoutePermissionCoverageTest` iterando `Route::getRoutes()->gatherMiddleware()` + scan de archivos |
| Autorización (Feature) | `agencia` 403 en `/tickets` (regresión de fuga); `taquilla` 200 en reportes/releases; `taquilla` 403 en `/exchange-rates`; `agencia` 403 en `/pagos`; `agencia` PUT su agencia 200 y store/toggle/destroy 403; `taquilla` GET self 200, update/delete 403; banca/grupo/agencia delete users 200 | Tests existentes + `TicketsAuthorizationTest`/`TaquillaPermisosTest`; usar datos sembrados y `actingAs(..., 'sanctum')` |
| Regresión | Suite completa; `ReleaseTest` ajustado; `DivergenciaRolTest`/`RoleAuthorizationTest` sin cambios de semántica de scoping | `composer test`, `vendor/bin/pint --test`, `php artisan route:list` |
| No frágiles | Evitar asserciones sobre conteos globales de permisos en tests ajenos; el conteo 34 vive en un solo test; preferir `assertStatus` y nombres de permiso a cuerpos completos | — |

Pruebas de aceptación mínimas: `super_master` sin permisos entra a `permission:view_users`; `taquilla` ve solo su usuario y sus reportes; `agencia` no ve tickets ni pagos; `master` borra usuarios de su alcance; `banca/grupo/agencia` conservan el borrado de #84.

## Threat Matrix

| Boundary | Casos mínimos | Aplicabilidad | Respuesta de diseño | RED tests |
|---|---|---|---|---|
| Documentation-like paths | `requirements.txt`, `CMakeLists.txt`, Markdown ejecutable, `README.sh` | N/A — BE-04 no clasifica ni ejecuta archivos | — | — |
| Git repository selection | `git -C`, rutas relativas/absolutas | N/A — no hay comandos git en el cambio | — | — |
| Commit state | staged, `commit -a`, índice vacío | N/A — no hay automatización de commits | — | — |
| Push state | tracking branch, primer push, refspec | N/A — el push se hace manual al cierre | — | — |
| PR commands | `--head`, prefijos de entorno, comandos compuestos | N/A — no hay automatización de PRs | — | — |
| HTTP route authorization (boundary real de BE-04) | ruta autenticada sin `permission:`; `role:` residual; ruta que despierta un scope dormido (`agencia` en tickets) | **Applicable** | Fail-closed 403; toda ruta autenticada con `permission:`; tickets no se asignan a `agencia` | `RoutePermissionCoverageTest` (cobertura + 0 `role:`), `TicketsAuthorizationTest` (agencia 403) |

## Migración / Rollout

- **Sin migración de datos**: las tablas de Spatie ya existen; solo cambia el contenido de permisos/roles.
- **Orden**: desplegar WU-1 → ejecutar `php artisan db:seed --class=RolesAndPermissionsSeeder --force` → verificar 34 permisos → desplegar WU-2..4. El entrypoint actual solo siembra si la BD es nueva; no confiar en él para una BD existente.
- **Refresco de caché**: el seeder llama `forgetCachedPermissions()`; tras el deploy ejecutar `php artisan permission:cache-reset` (o `php artisan optimize:clear`, que ya corre al arrancar el contenedor).
- **Dev**: `php artisan migrate:fresh --seed` reconstruye roles/permisos limpios.
- **Verificación post-seed**: `SELECT COUNT(*) FROM permissions WHERE guard_name='api'` = 34; `manage_users`/`manage_apuestas` = 0 filas; cada rol con el conteo esperado (master 33, banca 24, grupo 21, agencia 17, taquilla 12, super_master 0).
- **Ventana de convivencia**: Spatie acepta `role:` y `permission:` a la vez; si se despliega todo junto y el seeder tarda, las rutas nuevas responden 403 (nunca 500), y se recuperan al refrescar el caché.

## Riesgos

| Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|
| Desplegar rutas antes del seeder → 403 masivos | Media | Alto | Runbook: seeder manual + verificación de conteos antes de habilitar tráfico; `optimize:clear` |
| `agencia` recibe un permiso que despierta `/tickets` sin scoping | Baja | Alto (fuga) | `create_apuesta` no se asigna a agencia; test de regresión `agencia` 403; documentar que un futuro `view_tickets`/`manage_tickets` requiere filtro en `AlcanceService` |
| Tests que asumen 403 por rol | Alta | Medio | Migración por WU con suite verde; solo `ReleaseTest` cambia de expectativa (deliberado) |
| Ruta autenticada olvidada sin `permission:` | Media | Alto | `RoutePermissionCoverageTest` en WU-4 + `php artisan route:list` |
| `syncPermissions` pisa grants manuales en prod | Media | Medio | Matriz es la fuente; backup de `role_has_permissions` antes del deploy; rollback con seeder anterior |
| Caché de Spatie (24 h) con permisos viejos | Media | Medio | `forgetCachedPermissions()` + `permission:cache-reset`; `optimize:clear` en arranque |
| Código futuro use `hasPermissionTo()` y rompa el bypass sin filas | Baja | Medio | Regla documentada: usar `can()`/Gate; test de catálogo verifica bypass sin permisos |
| Usuarios sin rol en rutas de juegos/resultados | Baja | Bajo | Fail-closed deliberado (consistente con login); documentado |
| `permission:` sin permiso creado responde 403 (no 500) | Baja | Bajo | Verificado en el contrato de `Gate::before`; test de catálogo cubre |
| `manage_comisiones` sin rol | Baja | Bajo | Solo `super_master` por bypass; si se asigna a master, cambiaría defaults; documentado |

## Rollback

- **Por WU**: `git revert` del merge; las rutas vuelven a `role:` y los permisos extra quedan inertes. WU-1 revertido restaura el seeder anterior; si ya se borró `manage_users`, recrearlo con `Permission::firstOrCreate` o re-ejecutar el seeder del tag anterior.
- **Prod**: desplegar la imagen anterior; re-ejecutar el seeder del tag anterior si la matriz quedó inconsistente. Snapshot previo de `permissions`, `roles`, `role_has_permissions`, `model_has_permissions`.
- **Dev**: `migrate:fresh --seed`.
- **Hotfix**: si una ruta queda sin permiso, se puede restaurar temporalmente su `role:` sin revertir todo el WU; el test de cobertura lo detecta.

## CI/CD

- **CI**: sin cambios de workflow. El job `tests` ya corre `vendor/bin/pint --test` y `php artisan test --parallel`; los tests nuevos entran solos. El push se pospone al cierre, así que la verificación por WU es local (`composer test` + `pint --test`).
- **CD**: el pipeline actual construye imagen y despliega; `backend/entrypoint.sh` solo siembra cuando la BD es nueva. Agregar al runbook de release: contenedor one-off con la imagen nueva → `php artisan db:seed --class=RolesAndPermissionsSeeder --force` → `php artisan permission:cache-reset` → verificar conteos → cambiar tráfico. Si se publica en un solo deploy, ejecutar el seeder antes de enrutar tráfico a las rutas nuevas.
- **Post-deploy**: smoke test con `GET /api/v1/juegos` y `GET /api/v1/reportes/ventas-totales` como taquilla; `GET /api/v1/tickets` como agencia debe ser 403; `GET /api/v1/users` como taquilla debe ser 200 con solo su usuario.

## Open Questions

- [ ] ¿Se acepta el uso de `create_apuesta` como gate de `/tickets` (catálogo 34), o se prefiere un `view_tickets`/`manage_tickets` en una change posterior (catálogo 36) con filtro de `agencia` en `AlcanceService`?
- [ ] Confirmar que `download_release` cubre también `/releases/latest` para taquilla (hoy `ReleaseTest` espera 403); el diseño asume que sí.
- [ ] Confirmar la enmienda del borrador del spec en las celdas reconciliadas (exchange rates, pagos agencia, `create_apuesta` agencia, `delete_users`, `view_users` taquilla).
- [ ] Confirmar con operaciones el paso manual del seeder en el deploy (el entrypoint no siembra BD existentes).
