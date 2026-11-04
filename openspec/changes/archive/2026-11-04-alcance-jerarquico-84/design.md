# Design: Alcance jerárquico uniforme (#84)

## Enfoque

`UserPolicy` concentra la autorización de usuarios; apuestas, pagos y tickets reutilizan los helpers master. `users.created_by` es metadata de fila, no capability ni sustituto de `master_id`. Sin refactor de #85.

## Decisiones

| Decisión | Alternativa | Razón |
|----------|-------------|-------|
| `UserPolicy` reemplaza `authorizeUserAccess`: rango estricto, subárbol, `created_by` y super protección | Helpers en controller | Fuente única; cierra pares/superiores; reutiliza #85. |
| `created_by` FK nullable, fillable, relaciones; asignación server-side | Derivar de `master_id` | Metadata; evita mass assignment. |
| Apuestas `view`/`delete`: master por banca; null/sin bancas → `false`; super global. Pago usa `view` en el lock, antes del estado | `if` por rol | Casos 2/3; evita doble egreso. |
| Tickets (`index`/`show`/`ganadores`): master scoped; agencia fuera | Agregar agencia | Caso 4 sin ampliar contrato. |
| Límites: coherencia `grupo→banca`/`taquilla→grupo` + alcance; autorizar antes de restrictividad | Solo `banca_id` | Casos 6/7; 403 antes de 422; batch reutiliza helper. |
| Agencia edita `name`/`rif`/`email`/`telefono`/`direccion`/`estado`/`municipio`; `grupo_id`/`code`/`active` → 403; `store`/`toggle`/`destroy` bloqueados | Bloquear todo | Ficha propia sin reasignar identidad ni grupo. |
| Borrado de usuarios: `super_master`/`master` global en su alcance; `banca`/`grupo`/`agencia` solo usuarios de su subárbol o creados por ellos, siempre de rango menor | CRUD superior | Decisión de producto confirmada; super_master intocable. |

Reglas: super todo salvo borrar super/self; master subárbol+creados, sin super/otros masters/creador; banca/grupo/agencia su entidad+creados, sin pares/superiores; taquilla solo `view` propio. `update` permite autoedición (salvo taquilla); `canAssign` impide autopromoción.

## Flujo

```
Request → middleware role → Controller
  Usuarios → UserPolicy
  Apuesta/Pago/Ticket → ApuestaPolicy + helpers master
  Juego → authorizeEscrituraLimite (403) → restrictividad (422) → upsert
  Agencia → authorizeAgenciaAccess → guard de campos → update
```

## Archivos

| Archivo | Acción | Cambio |
|---------|--------|--------|
| `app/Policies/UserPolicy.php`, `UserController.php`, `Models/User.php` | Crear/Modificar | Policy; controller setea `created_by`; modelo relaciones/fillable. |
| `database/migrations/2026_11_04_000001_add_created_by_to_users_table.php` | Crear | FK nullable + índice. |
| `app/Http/Controllers/Api/{Banca,Grupo,Taquilla}Controller.php` | Modificar | `created_by` del actor. |
| `database/seeders/{UsersSeeder,E2eSeeder}.php` | Modificar | Cadena de creadores. |
| `app/Policies/ApuestaPolicy.php`, `PagoController.php` | Modificar | Alcance master; ownership en lock. |
| `app/Http/Controllers/Api/TicketController.php` | Modificar | Ramas master. |
| `app/Http/Controllers/Api/JuegoController.php` | Modificar | Alcance; orden 403→422. |
| `app/Http/Controllers/Api/AgenciaController.php` | Modificar | `update` propio limitado. |
| `tests/Feature/` | Crear/Modificar | 7 tests nuevos; actualizar `GestionUsuariosTest`. |

## Contrato

`UserPolicy`: `view(User, User)`, `update(User, User)`, `delete(User, User)`, `canCreate(User, string, array)`, `canAssign(User, string, array, User)`; todas retornan `bool`.

## Migración

`users.created_by` nullable, `after('id')`, `constrained('users')->nullOnDelete()` + índice; `down()` revierte. Sin backfill: existentes quedan `null`. Seeders: super→master→banca→grupo→taquillas/agencia. Deploy aditivo.

## Tests

| Caso | Archivo | Assertions |
|------|---------|------------|
| 1 | `AlcanceJerarquicoUsuariosTest` | master edita/borra super 403; password intacto; super no borra super. |
| Matriz | idem | pares 403; taquilla solo `GET` propio; `created_by` del actor; payload ajeno ignorado; index con creados; banca/grupo/agencia borran usuarios de su subárbol/creados y no pares. |
| 2 | `ApuestaScopePolicyTest` | master propia 200; ajena/sin bancas 403; super global. |
| 3 | `PagoEgresoScopeTest` | cross-tenant 403; propia 201; no-egreso/doble egreso 422. |
| 4 | `TicketMasterScopeTest` | solo propias; sin bancas vacío; show ajeno 403; super global. |
| 6/7 | `LimitesEscrituraScopeTest` | propia 200; ajenos 403; batch por ítem; restrictivo ajeno 403, propio 422. |
| Agencia | `AgenciaSelfUpdateTest` | propia 200; grupo/code/active 403; otra agencia y store/toggle/destroy 403. |
| 5 | `TicketAnulacionRegresionTest` | líneas soft-deleted 422, sin devolución. |

Comandos (no ejecutados): `php artisan test --filter=...` por WU; `composer test`; `pint --test`.

## WUs / PRs

| # | Rama ← base | Alcance | Líneas |
|---|-------------|---------|--------|
| 1 | `feat/84a-usuarios-created-by` ← `feature/alcance-jerarquico-84` | Migración, modelo, stores, seeders, policy/controller, tests | 650–780 |
| 2 | `feat/84b-apuestas-pagos-tickets` ← rama 1 | ApuestaPolicy, Pago, Ticket, tests 2/3/4 | 250–350 |
| 3 | `feat/84c-limites-agencia-regresion` ← rama 2 | Juego, Agencia, tests 5/6/7 | 250–400 |

Dependencias 2←1, 3←2. Rollback: revertir merge; WU1 revierte migración (metadata, no datos); WU2/3 código. WU1 >800 → 1a (migración/modelo/stores/seeders) + 1b (policy/controller/tests). PRs encadenados; retarget si hay slices previos.

## Riesgos

| Riesgo | Mitigación |
|--------|------------|
| Usuarios sin `created_by` | Nullable; subárbol sigue. |
| `master_id` vs `created_by` | `master_id` define bancas; `created_by` solo fila. |
| Tests de scope | Actualizar expectativas de borrado de usuarios para banca/grupo/agencia; preservar super/master. |
| Orden 403/422 | Autorizar antes en límites; no-egreso 422 en pagos. |
| `code` de agencia | Inmutable por diseño; documentado en spec/design. |
| Mass assignment | Asignación explícita + test. |

## CI/CD

- CI solo en `main`; `composer test` local por WU.
- Deploy: migraciones aditivas al arrancar; sin downtime.
- Rollback por `IMAGE_TAG`/`PREV`; migración reversible sin pérdida funcional.

## Threat Matrix

N/A — sin routing, shell, subprocesos, VCS/PR ni integración de procesos.

## Open Questions

- Ninguna bloqueante. Decisiones asumidas: `code` de agencia inmutable; agencia no accede a `/pagos` ni `/tickets` (rutas actuales no la incluyen); banca/grupo/agencia pueden borrar usuarios de su subárbol/creados de rango menor.
