# Propuesta: autorizacion-rol-unico

## Problema y evidencia

El backend tiene **dos fuentes de verdad** para el rol del usuario, y divergen sin control:

1. `users.role` (columna legacy, default `taquilla`) — la usan la mayoría de controllers para *scoping* y el login/UI.
2. Spatie (`roles`/`model_has_roles`) — la usa el middleware de rutas `role:`, `permission:` y `hasRole()`.

Cuando divergen, la autorización diverge. Casos verificados en el código:

| Caso | Divergencia | Consecuencia |
|------|-------------|--------------|
| `ComisionController::consulta` (`bancasEnAlcance`, línea 131) | columna `taquilla` + Spatie `master` | `$user->role === 'master'` es falso → devuelve `null` → **comisiones globales** |
| `ApuestaController` vs `CierreController` | columna `master` + Spatie `taquilla` | scope master (2 apuestas) vs scope taquilla (1 cierre) |
| Reportes | columna `super_master` + Spatie `taquilla` | reportes globales |
| `VerifyMac` (línea 23) | usa `hasRole('taquilla')` (Spatie) | exige MAC solo si Spatie dice `taquilla`, ignorando la columna |

Evidencia estructural:
- 115 referencias `->role` en `app/` (controllers `AuthController`, `UserController`, `ReporteController`, `EstadisticaController`, `ComisionController`, `ApuestaController`, `TicketController`, `PagoController`, `JuegoController`, `ApuestaPolicy`, y más).
- 6 queries `where('role', ...)` ocultas: `CierreService::validarClaveCierre` (169, 173, 176), `AgenciaController::destroy` (178), `UserController::index` (111), `AgenciasBackfill` (39, 95), `E2eSeeder` (80, 130, 131).
- El middleware de rutas es Spatie (`RoleMiddleware`/`PermissionMiddleware` alias `role`/`permission` en `bootstrap/app.php`), pero el scoping en controllers usa la columna.
- La spec vigente `openspec/specs/jerarquia-agencias/spec.md` (requirement "Rol agencia en ambas fuentes") **exige ambas fuentes**, lo que institucionaliza la divergencia.

## Objetivos y no-objetivos

### Objetivos
- Unificar el rol en **una única fuente de verdad: Spatie** (`hasRole()`/`getRoleNames()`).
- Eliminar `users.role` como dato persistido (físico en merge 1 → drop en merge 2).
- Mantener compatibilidad de API/panel con un atributo calculado `role` en `User` (no persistido).
- Auditar cambios de rol (`user.role_changed`).

### No-objetivos
- No incluir #84 (scoping incompleto), #85 (ScopeService), #89 (auditoría completa) ni BE-04 (permission middleware).
- No migrar el frontend (el accessor preserva el shape del payload).
- No backfill permanente en producción (wipe + reseed en el VPS dev).

## Alcance

### In scope
- **Backend**: migrar lecturas/escrituras de rol a Spatie; accessor `role` (`$appends`, `getRoleAttribute()`, `primaryRole()`); auditoría `user.role_changed`; migración de las 6 queries `where('role',...)`; ajuste de seeders/backfill.
- **Tests**: actualizar los que fijan `users.role`; cobertura de divergencia resuelta y auditoría.
- **Spec/docs**: modificar `jerarquia-agencias` (requirement "ambas fuentes") y nueva capability `autorizacion-rol-unico`.

### Out of scope
- **Frontend** (panel/taquilla): sin cambios; el accessor preserva `user.role` y `role` en el login.
- #84, #85, #89, BE-04 (listados arriba).

## Decisiones de producto/diseño y tradeoffs

| Decisión | Elección | Tradeoff |
|----------|----------|----------|
| Fuente única | Spatie | Pierde la columna simple para queries; gana coherencia con el middleware de rutas |
| Compatibilidad API | Atributo calculado `role` (no persistido) | Evita romper el frontend; N+1/calculo por request, no es espejo persistido |
| Eliminación | Merge 1 (expand): columna física pero muerta; Merge 2 (contract): drop | Cero downtime; requiere 2 merges coordinados |
| Datos VPS dev | `migrate:fresh --seed` | Simple; pierde datos dev (aceptado) |
| Auditoría | `user.role_changed` con actor, target, before/after, IP/UA | Más superficie; requisito de trazabilidad |

## Capabilities (contrato con sdd-spec)

### New Capabilities
- `autorizacion-rol-unico`: rol de fuente única (Spatie), accessor calculado `role`, auditoría `user.role_changed`.

### Modified Capabilities
- `jerarquia-agencias`: reemplazar "Rol agencia en ambas fuentes" → rol registrado **solo en Spatie**; `users.role` deja de ser fuente.

## Approach

1. **Merge 1 (expand)**: migrar lecturas (`$user->role` → `hasRole`/accessor) y escrituras (`role` → `syncRoles`) a Spatie; agregar accessor `role`; las 6 queries `where('role',...)` pasan a Spatie; dejar `users.role` física pero muerta; sin migración destructiva.
2. **Merge 2 (contract)**: dropear `users.role` (migración destructiva) y limpiar `$fillable`/casts/seeders.

## Affected Areas

| Area | Impacto | Descripción |
|------|---------|-------------|
| `backend/app/Models/User.php` | Modified | `$appends`, `getRoleAttribute()`, `primaryRole()`; quitar `role` de `$fillable` (merge 2) |
| `backend/app/Http/Controllers/Api/*` (Auth, User, Reporte, Estadistica, Comision, Apuesta, Ticket, Pago, Juego) | Modified | `->role` → Spatie/accessor |
| `backend/app/Policies/ApuestaPolicy.php` | Modified | `->role` → Spatie |
| `backend/app/Services/CierreService.php` | Modified | `where('role',...)` → Spatie |
| `backend/app/Http/Controllers/Api/AgenciaController.php` | Modified | `where('role','taquilla')` → Spatie |
| `backend/app/Console/Commands/AgenciasBackfill.php`, `database/seeders/E2eSeeder.php`, `UsersSeeder.php` | Modified | queries/seeds de rol |
| `backend/database/migrations/` | Added/Modified | migración drop `users.role` (merge 2) |
| `backend/tests/` | Modified | cobertura de divergencia + auditoría |
| `openspec/specs/jerarquia-agencias/spec.md` | Modified | requirement "ambas fuentes" |

## Criterios de éxito

- [ ] Cero lecturas/escrituras de `users.role` como fuente (merge 2: columna eliminada).
- [ ] Login/UI devuelven `role` idéntico al de Spatie (compatibilidad).
- [ ] Los 4 casos de divergencia verificados devuelven scope coherente.
- [ ] `composer test` en verde (TDD).
- [ ] Auditoría `user.role_changed` registra actor/target/before/after/IP/UA.
- [ ] Deploy en VPS dev con `migrate:fresh --seed` sin errores.

## Riesgos y mitigaciones

| Riesgo | Probabilidad | Mitigación |
|--------|--------------|------------|
| Regresión de scoping (115 refs) | Alta | Migración mecánica por controller + tests de divergencia; rollout en 2 merges |
| Rotura del frontend por `role` faltante | Media | Accessor calculado preserva el shape; sin cambios en frontend |
| `where('role',...)` ocultas sin cubrir | Media | Inventario de las 6 queries; reemplazo explícito y test |
| Divergencia en datos durante la transición (columna viva vs muerta) | Media | Merge 1 sincroniza lecturas/escrituras; columna muerta antes del drop |
| Regresión de `VerifyMac` (MAC exigido) | Baja | Mantiene `hasRole('taquilla')`; test de login taquilla |

## Impacto en CI/CD

- **CI** solo corre en `main` (`branches: [main]`): las ramas `feature/*` no gatean; la verificación real ocurre al mergear.
- **Deploy** migra automáticamente (`entrypoint.sh` ejecuta `php artisan migrate --force` en cada arranque): la migración destructiva (merge 2) se aplica sola.
- **Rollback** solo por imagen (`deploy.sh`: `docker compose up -d <imagen-anterior>`); no hay rollback de esquema → la migración destructiva debe ser idempotente y reversible en código.

## Rollback Plan

- **Merge 1 (expand)**: sin cambios de esquema destructivos; revertir = redeploy de la imagen anterior (el accessor y las lecturas Spatie conviven con la columna intacta).
- **Merge 2 (contract)**: antes del drop, el código ya no lee `users.role`; revertir código = redeploy de imagen anterior. Para revertir el esquema, restaurar la columna con una migración inversa manual (no automática).

## Dependencies

- Spatie Permission ya instalado y operativo (`roles`/`permissions` con guard `api`).
- `RolesAndPermissionsSeeder` ya define los 6 roles; sin cambios de permisos en este change.
