# Proposal: Alcance jerárquico uniforme (issue #84)

## Problema

El alcance jerárquico (super_master → master → banca → grupo → agencia → taquilla) no se aplica de forma uniforme. Seis puntos permiten fuga cross-tenant o autorización insuficiente, y el CRUD de usuarios/entidades no tiene una matriz de permisos coherente por rol. Este change cierra esas brechas de seguridad/comportamiento.

## Evidencia actual

| # | Caso | Evidencia (código) |
|---|------|--------------------|
| 1 | Master edita/borra contraseña de super_master | `UserController::authorizeUserAccess` (master) omite el check si `resolveBancaId($target)` es `null`; `update()` acepta `password` sin restricción de rol |
| 2 | `ApuestaPolicy::view/delete` global para master | `view()` devuelve `true` para `super_master|master` sin acotar por banca; `show`/`destroy` de apuestas quedan cross-tenant |
| 3 | `PagoController::store` valida ownership solo para taquilla | línea 98: `if ($user->hasRole('taquilla') && ...)`; banca/grupo/master pagan apuestas ajenas |
| 4 | `TicketController::index/show/ganadores` sin rama master | `autorizarScope()` admite master/agencia pero el filtrado solo cubre taquilla/grupo/banca → master ve TODOS los tickets. **Matiz:** la ruta `/tickets` no incluye `agencia` (middleware) |
| 5 | `TicketController::destroy` sin verificación sin líneas vivas | **YA CORREGIDO** por `TicketAnulacionService` (rechaza tickets sin líneas vivas y valida policy por línea). No reimplementar; solo test de regresión y actualizar alcance |
| 6 | `JuegoController::authorizeEscrituraLimite` (banca) solo compara `banca_id` | no valida `grupo_id`/`taquilla_id` del payload → banca A inyecta límites en subárbol de B |
| 7 | `JuegoController::updateLimites` valida restrictividad antes de autorizar | filtra 422 con datos ajenos en vez de 403 (orden invertido) |

## Objetivos

- Aplicar el alcance jerárquico de forma uniforme en apuestas, pagos, tickets, límites y CRUD de usuarios/entidades.
- Definir y hacer cumplir una matriz CRUD por rol (pares y superiores prohibidos).
- Proteger a `super_master` como intocable.
- Añadir `users.created_by` (metadata de fila) para soportar la política "el rol gestiona lo que creó".

## No-objetivos

- #85 (ScopeService/refactor de policies) — va después.
- BE-04 (catálogo de permisos, `permission:`, `Gate::before`, taquilla reports/release download) — va después.
- #82, #89, taquilla release descarga, reportes de taquilla.

## Alcance (in scope)

- **6 casos vigentes** (1, 2, 3, 4-matizado, 6, 7) + caso 5 como test de regresión.
- **`users.created_by`**: columna nullable FK; setear en `UserController::store`, `BancaController::store`, `GrupoController::store`, `TaquillaController::store` y seeders. Backfill: usuarios existentes sin creador → `null` (dev ya fue wipe/reseed). Relaciones `User::creator()` / `User::createdUsers()`.
- **Jerarquía CRUD** (matriz completa en Decisiones).
- **Super protección** y **agencia propia ficha**.

## Capabilities

### New Capabilities
- `alcance-jerarquico`: matriz CRUD jerárquica uniforme, protección `super_master` y `users.created_by`.

### Modified Capabilities
- `pagos`: ownership de `egreso` para banca/grupo/master (caso 3).
- `alcance-super-banca`: master acotado en `ApuestaPolicy::view/delete` y en `TicketController` (casos 2 y 4).
- `jerarquia-agencias`: agencia edita su propia ficha y CRUD de taquillas de su local.

## Decisiones de producto y diseño

**Matriz CRUD jerárquica** (pares y superiores prohibidos):

| Rol | Usuarios que gestiona | Entidades que gestiona | Prohibido |
|-----|----------------------|------------------------|-----------|
| super_master | todos | todas | nadie lo borra; no borra otro super_master |
| master | usuarios de sus bancas + los que creó | bancas que administra, grupos, agencias, taquillas de su árbol | super_masters, otros masters, su propio creador |
| banca | usuarios de su banca + los que creó | grupos, agencias, taquillas de su banca | bancas, masters, super_masters; no crea otro banca |
| grupo | usuarios de su grupo + los que creó | agencias, taquillas de su grupo | grupos, bancas, masters, super_masters; no crea otro grupo |
| agencia | usuarios de su agencia + los que creó | su propia ficha + taquillas de su agencia | agencias ajenas, grupos, bancas, masters, super_masters; no crea otra agencia |
| taquilla | ninguno | ninguna (solo lectura de su usuario) | — |

- **`authorizeEntityBinding`** debe impedir que banca cree banca, grupo cree grupo y agencia cree agencia (hoy solo bloquea roles superiores, no pares).
- **`users.created_by` es metadata/policy de fila, NO un permiso de capability** (no es BE-04). Es comportamiento/seguridad.
- **Orden de autorización** en `updateLimites`: autorizar ANTES de validar restrictividad (403 precede a 422).

## Approach

- Consolidar las ramas de scope faltantes usando los helpers existentes (`masterBancaChainScope`, `masterCanAccessBanca`, `resolveBancaId`) en `ApuestaPolicy`, `TicketController` y `PagoController`.
- Corregir `UserController::authorizeUserAccess`/`authorizeEntityBinding` para super protección, pares prohibidos y `created_by`.
- Migración `users.created_by` + relaciones + seeders.
- Tests de regresión por caso (feature tests) + actualización de tests de scope existentes.

## Affected Areas

| Area | Impacto | Descripción |
|------|---------|-------------|
| `app/Http/Controllers/Api/UserController.php` | Modified | super protección, matriz CRUD, `created_by` |
| `app/Policies/ApuestaPolicy.php` | Modified | `view`/`delete` acotado para master |
| `app/Http/Controllers/Api/PagoController.php` | Modified | ownership egreso por rol |
| `app/Http/Controllers/Api/TicketController.php` | Modified | rama master en index/show/ganadores |
| `app/Http/Controllers/Api/JuegoController.php` | Modified | `authorizeEscrituraLimite` + orden autorización |
| `app/Models/User.php` | Modified | `creator()` / `createdUsers()`, fillable |
| `app/Http/Controllers/Api/{Banca,Grupo,Taquilla}Controller.php` | Modified | `created_by` al crear usuarios |
| `database/migrations/` | New | `users.created_by` |
| `database/seeders/` | Modified | `created_by` en seeders |
| `tests/Feature/` | New/Modified | regresión por caso + scope |

## Risks

| Riesgo | Probabilidad | Mitigación |
|--------|-------------|------------|
| Datos existentes sin `created_by` | Med | columna nullable; backfill → null; dev wipe/reseed |
| Compatibilidad de contratos API | Med | `role` calculado se preserva; sin cambios de shape |
| Tests de scope existentes rompen | Med | actualizar en el mismo change; suite verde |
| `master_id` vs `created_by` ambigüedad | Baja | `created_by` es metadata; `master_id` sigue definiendo bancas administradas |
| Regresión de anulación (caso 5) | Baja | solo test de regresión, no reimplementar |

## Rollback Plan

- Revertir la imagen del contenedor (`docker compose` con `IMAGE_TAG` previo; el deploy ya guarda `PREV` y aplica rollback automático ante healthcheck).
- Las migraciones son aditivas (`add users.created_by` nullable): reversibles por migración inversa manual sin pérdida de datos.
- Si un caso rompe el front (panel/taquilla), el contrato JSON no cambia; el redeploy de la imagen anterior restaura comportamiento.

## Dependencies

- Ninguna externa. Requiere que `#69` (autorizacion-rol-unico) ya esté en main (lo está, base `0c4cc38`).
- #85 y BE-04 son dependientes FUTUROS de este change, no bloqueantes.

## Success Criteria

- [ ] `ApuestaPolicy::view/delete` deniegan apuestas de banca ajena para master (403).
- [ ] `PagoController::store` rechaza egreso cross-tenant para banca/grupo/master (403).
- [ ] `TicketController::index/show/ganadores` acotan master a sus bancas; super_master global.
- [ ] `authorizeEscrituraLimite` valida `grupo_id`/`taquilla_id`; banca A no inyecta en subárbol de B.
- [ ] `updateLimites` devuelve 403 (no 422) para datos ajenos.
- [ ] `users.created_by` nullable se puebla en store/seeders; relaciones `creator()`/`createdUsers()`.
- [ ] `authorizeEntityBinding` rechaza pares (banca→banca, grupo→grupo, agencia→agencia).
- [ ] super_master intocable; master no borra otro master/super_master ni a su creador.
- [ ] Caso 5 cubierto solo con test de regresión.
- [ ] `composer test` en verde (sin ejecutarlo aquí; lo ejecuta el usuario).

## Impacto CI/CD

- **CI solo en `main`**: los tests no corren en la rama de feature; el usuario ejecuta `composer test` localmente por work unit.
- **Deploy migra automático**: `docker compose up` corre migraciones al arrancar (aditivas, sin downtime).
- **Rollback por imagen**: revertir `latest`/`IMAGE_TAG` restaura el binario; migraciones aditivas no bloquean el rollback.

## Relación con #85 / BE-04

- **#85 (ScopeService/policies refactor)**: este change corrige los casos puntuales sin refactorizar; el refactor posterior consolida en un solo servicio/policy.
- **BE-04 (catálogo de permisos)**: `users.created_by` aquí es metadata de fila, no una capability; BE-04 introducirá `permission:`/`Gate::before` después, sobre esta base.
