# Permisos y capabilities

> Catálogo de permisos por capability (BE-04), matriz capability × rol y reglas
> de autorización del backend. Complementa a
> [`alcance-jerarquico.md`](alcance-jerarquico.md) (qué **filas** ve cada rol,
> #85): este documento decide qué **acciones** puede ejecutar.
>
> Fuente única en código: `backend/database/seeders/RolesAndPermissionsSeeder.php`
> (catálogo + matriz) y `backend/app/Providers/AppServiceProvider.php`
> (`Gate::before` de `super_master`).

## 1. Modelo

- La autorización de recurso usa `permission:<capability>` (Spatie Permission,
  guard `api`), nunca `role:`.
- Los permisos se asignan **solo a roles**; un usuario hereda los de su rol.
- `super_master` no tiene permisos asignados: su bypass se resuelve en
  `Gate::before` (ver §4).
- El scoping de filas sigue viviendo en `App\Support\Scope\AlcanceService` y en
  las policies. La capa de permisos no lo reemplaza ni lo modifica (#85).

## 2. Catálogo final (36 permisos, guard `api`)

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
| Auditoría | `view_audit` `export_audit` |
| Releases | `download_release` |
| Clave de cierre | `manage_clave_cierre` |
| Configuración | `manage_config` |

Permisos retirados: `manage_users` y `manage_apuestas` (el seeder los elimina si
existen). `manage_comisiones` permanece en el catálogo pero **sin rol asignado**:
hoy solo `super_master` ejercita `PUT /comisiones/defaults` vía bypass. Se
conserva por compatibilidad de nombre.

## 3. Matriz capability × rol

`v`=view, `m`=manage, `c`=create, `e`=edit, `d`=delete. `super_master` pasa
siempre por bypass y no aparece en la matriz.

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
| Auditoría | v,e | — | — | — | — |
| Releases | v | v | v | v | v |
| Clave de cierre | m | m | — | — | — |
| Configuración | m | — | — | — | — |

Conteos por rol: master **35**, banca **24**, grupo **21**, agencia **17**,
taquilla **12**, super_master **0**.

`*` `agencia` recibe `manage_agencias` para editar su propia ficha; el
`AgenciaController` sigue bloqueando `store`, `toggle` y `destroy` para ese rol.

### Celdas reconciliadas con el acceso efectivo

La matriz preserva el comportamiento previo a BE-04 (no el borrador inicial):

| Punto | Decisión | Motivo |
|---|---|---|
| Usuarios | `delete_users` en master, banca, grupo y agencia | Conserva el borrado jerárquico de #84 |
| Usuarios / taquilla | `view_users` | Conserva la lectura de su propia ficha; `store/update/destroy` siguen en 403 |
| Agencias / agencia | `manage_agencias` | Conserva `PUT /agencias/{self}` (#84) |
| Pagos / agencia | sin `view_pagos` ni `create_pago` | La ruta excluye a agencia; se preserva el 403 |
| Exchange rates | solo master | El panel usa `/exchange-rate/active`, que es público |
| Tickets | `create_apuesta`, sin agencia | `AlcanceService` no filtra tickets para agencia; darle `view_apuestas` abriría una fuga |
| Releases / taquilla | `download_release` | Cambio deliberado de BE-04; `releases/serve` firmado no cambia |
| Configuración | `manage_config` a master | Conserva la ruta de ventana de vencimiento |

## 4. Contrato de `Gate::before` (`super_master`)

`AppServiceProvider::boot()` registra:

```php
Gate::before(function (User $user, string $ability): ?bool {
    if (! $user->hasRole('super_master')) {
        return null;
    }

    $esPermisoDelCatalogo = app(PermissionRegistrar::class)
        ->getPermissions(['name' => $ability, 'guard_name' => 'api'])
        ->isNotEmpty();

    return $esPermisoDelCatalogo ? true : null;
});
```

Reglas:

- Devuelve `true` solo si el usuario es `super_master` **y** la ability existe
  como permiso con guard `api`.
- Devuelve `null` en cualquier otro caso; **nunca `false`**. Así las policies
  (`UserPolicy`, `TicketPolicy`, etc.) y `AlcanceService` siguen decidiendo las
  abilities que no son permisos (`view`, `update`, `delete` sobre modelos).
- No usa `getAllPermissions()`: el bypass no depende de filas y cubre permisos
  futuros en cuanto se refresca el caché.
- Si el permiso aún no existe (despliegue antes del seeder), devuelve `null` y
  la ruta responde 403 (fail-closed), no 500.

## 5. Reglas de trabajo

1. **Sin permisos directos a usuarios.** `givePermissionTo()`/`syncPermissions()`
   sobre un `User` está prohibido; se asignan a roles. `PermissionCatalogTest`
   escanea `app/` y falla si aparece esa forma.
2. **Una capability nueva** se agrega al catálogo del seeder, a la matriz y a
   este documento; luego se usa en `routes/api.php` con `permission:`.
3. **Antes de abrir una capability a un rol**, revisar el scoping de
   `AlcanceService`: un permiso nuevo puede despertar una query sin filtro
   (caso `/tickets` + agencia). Si falta el filtro, no se asigna el permiso o se
   agrega el scope en la misma change.
4. **Fail-closed.** Un usuario sin rol no hereda permisos; una ruta con
   `permission:` cuyo permiso no existe responde 403.

## 6. Seeder y operación

`RolesAndPermissionsSeeder` es idempotente:

```
forgetCachedPermissions()
  → firstOrCreate de los 36 permisos (guard api)
  → delete de manage_users / manage_apuestas
  → syncPermissions(matriz) por rol
  → super_master.syncPermissions([])
  → forgetCachedPermissions()
```

`syncPermissions` **reemplaza** la matriz de cada rol: es el contrato del
seeder, no un merge. Los permisos ajenos al catálogo (de terceros) se toleran y
no se borran.

Orden de despliegue (BE-04):

```bash
cd backend
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan permission:cache-reset
```

Ejecutar el seeder **antes** de desplegar las rutas migradas: al ser aditivo,
sembrarlo con las rutas anteriores (`role:`) no cambia quién entra.

Verificación post-seed:

```sql
SELECT COUNT(*) FROM permissions WHERE guard_name = 'api';            -- 36
SELECT COUNT(*) FROM permissions
 WHERE name IN ('manage_users', 'manage_apuestas');                   -- 0
```

Conteos esperados por rol: master 35, banca 24, grupo 21, agencia 17,
taquilla 12, super_master 0. En dev, `php artisan migrate:fresh --seed`
reconstruye el estado limpio.

Ante un despliegue con caché viejo, `php artisan optimize:clear` (o
`permission:cache-reset`) refresca el cache de Spatie (24 h por defecto).

## 7. Mapeo ruta → permiso (final, BE-04 cerrado)

> Estado: **BE-04 cerrado**. `routes/api.php` ya no usa `role:`; la migración
> `role:` → `permission:` cubrió WU-1..WU-4 y el alias `role` se retiró de
> `bootstrap/app.php`. `RoutePermissionCoverageTest` bloquea regresiones
> (toda ruta con `auth:sanctum` exige `permission:`; 0 `role:`).

| Rutas | Método | Middleware | Roles que pasan |
|---|---|---|---|
| `/usuarios/clave-cierre` | GET, PUT | `permission:manage_clave_cierre` | master, banca |
| `/users` (index/show) | GET | `permission:view_users` | master, banca, grupo, agencia, taquilla (taquilla solo self por policy) |
| `/users` | POST | `permission:create_users` | master, banca, grupo, agencia |
| `/users/{user}` | PUT/PATCH | `permission:edit_users` | master, banca, grupo, agencia |
| `/users/{user}` | DELETE | `permission:delete_users` | master, banca, grupo, agencia |
| `/bancas*` | GET / POST/PUT/DELETE/toggle | `view_bancas` / `manage_bancas` | master |
| `/grupos*` | GET / POST/PUT/DELETE/toggle | `view_grupos` / `manage_grupos` | master, banca |
| `/agencias*` | GET / POST/PUT/DELETE/toggle | `view_agencias` / `manage_agencias` | master, banca, grupo, agencia (agencia: solo su ficha) |
| `/taquillas*` | GET / POST/PUT/DELETE/toggle | `view_taquillas` / `manage_taquillas` | master, banca, grupo, agencia |
| `/configuraciones/apuestas-vencimiento` | GET, PUT | `permission:manage_config` | master |
| `/juegos*` (lectura) | GET | `permission:view_juegos` | master, banca, grupo, agencia, taquilla |
| `/juegos/{juego}` (update/premios/toggle) | PUT, PATCH | `permission:manage_juegos` | master |
| `/resultados*` (lectura) | GET | `permission:view_resultados` | master, banca, grupo, agencia, taquilla |
| `/resultados/scrape*` | POST | `permission:manage_resultados` | master |
| `/apuestas*` (lectura) | GET | `permission:view_apuestas` | master, banca, grupo, agencia, taquilla |
| `/apuestas` | POST | `permission:create_apuesta` | master, banca, grupo, taquilla (agencia 403) |
| `/apuestas/{apuesta}` | DELETE | `permission:delete_apuesta` | master, banca, grupo, agencia, taquilla |
| `/tickets*` | GET, POST, DELETE | `permission:create_apuesta` | master, banca, grupo, taquilla |
| `/pagos`, `/pagos/{apuesta}` | POST, GET | `create_pago` / `view_pagos` | master, banca, grupo, taquilla |
| `/cierre*` | POST / GET | `create_cierre` / `view_cierre` | master, banca, grupo, agencia, taquilla |
| `/limites*` (lectura) | GET | `permission:view_limites` | master, banca, grupo, agencia |
| `/limites*` (escritura) | PUT, DELETE, POST | `permission:manage_limites` | master, banca, grupo |
| `/logs` | GET | `permission:view_logs` | master |
| `/audit-events` | GET | `permission:view_audit` | master (scope por banca) |
| `/audit-events/export` | GET | `permission:export_audit` | master (scope por banca) |
| `/comisiones/defaults` | GET, PUT | `permission:manage_comisiones` | solo bypass (`super_master`) |
| `/comisiones/consulta` | GET | `permission:view_comisiones` | master |
| `/reportes/*`, `/estadisticas/rendimiento` | GET | `permission:view_reports` | master, banca, grupo, agencia, taquilla |
| `/exchange-rates*` | GET / POST/PUT | `view_exchange_rates` / `manage_exchange_rates` | master |
| `/releases/latest`, `/releases/download` | GET | `permission:download_release` | master, banca, grupo, agencia, taquilla |
| Públicas / autenticación | — | sin `permission:` | login, activación, dispositivo/verificar, `exchange-rate/active`, `releases/serve` (firmado), `update-check`, `releases/feed`, `/user`, `/logout` |

## 8. Referencias

- Design completo: `openspec/changes/be04-permisos/design.md`.
- Specs: `openspec/changes/be04-permisos/specs/catalogo-permisos/spec.md` y
  `.../autorizacion-capability/spec.md`.
- Scoping de filas: [`alcance-jerarquico.md`](alcance-jerarquico.md).
- Test de contrato del catálogo/matriz: `backend/tests/Feature/PermissionCatalogTest.php`.
- Test de cobertura de rutas: `backend/tests/Feature/RoutePermissionCoverageTest.php`.
- Test de acceso de taquilla: `backend/tests/Feature/TaquillaPermisosTest.php`.
