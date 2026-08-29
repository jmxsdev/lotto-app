# Design: Jerarquía de agencias (locales)

## Technical Approach

Enfoque A (tabla mínima nullable): **agencia = local físico passthrough** entre grupo y taquilla (solo identidad; no configura monedas/vigencia/tiempo/límites). Migraciones aditivas, backfill idempotente por comando artisan, rama `agencia` en scopes/policies, `whereIn banca_id` para master, labels UI y actualización de tests en el mismo cambio. Cubre las 4 specs (`jerarquia-agencias`, `alcance-super-banca`, `reportes-agencia`, `panel-jerarquia`).

## Architecture Decisions

| # | Decisión | Opciones | Elección | Razón |
|---|----------|----------|----------|-------|
| D1 | Modelo de agencia | A) mínima nullable · B) full configurable · C) solo renaming | **A** | Passthrough: `getEffective*`/`juego_limites`/índice único intactos; B exige drop/recreate destructivo y tocar el corazón de apuestas |
| D2 | Vínculo master↔banca | `bancas.master_id` FK users · `created_by` · pivot | **`master_id` nullable + backfill desde `created_by`** | Limpio y explícito; `created_by` es frágil (super puede crear bancas de un master); sin backfill los masters ven todo vacío |
| D3 | Backfill | Comando artisan · Seeder | **Comando `agencias:backfill`** | Idempotente, `--force` en prod, log y revisión del cliente; no re-inventa datos en cada migrate |
| D4 | `taquillas.grupo_id` | Migrar a agencia · Conservar | **Conservar** | No rompe joins existentes; agencia deriva grupo |
| D5 | Rol agencia | Solo columna · Solo Spatie | **Ambas fuentes** | Policies/queries leen `users.role`; rutas leen Spatie; una sola fuente rompe auth |
| D6 | Config del local | En esta iteración · Posterior | **Posterior** | Evita `juego_limites.agencia_id` + índice único destructivo; fase aditiva futura |
| D7 | Rendimiento por local | Solo máquinas · Nuevo reporte · | **`rendimientoTaquillas` acepta `nivel` (`taquilla` default, `agencia`)** | Un solo endpoint, agrupa por `agencias` con join; label máquina = "Taquilla" |

## Data Flow

```
login X-Panel (rol agencia) ──→ payload user.agencia_id
      │
      ├─→ POST /taquillas: agencia_id = user.agencia_id (derivado, no editable)
      │                    grupo_id = agencia.grupo_id ──→ usuario taquilla creado con agencia_id
      │
      └─→ consultas scoped (Apuesta/Reporte/Cierre/Límites): whereHas taquilla.agencia_id
                                              │
   master: bancas.master_id → whereIn banca_id (×~10 queries globales)
                                              │
   cadena de activación: taquilla → agencia → grupo → banca   (ActivacionEfectivaService)
                                              │
   reportes nivel=agencia: joins taquillas→agencias→grupos→bancas → groupBy agencias
```

## File Changes

| Archivo | Acción | Descripción |
|---------|--------|-------------|
| `backend/database/migrations/2026_08_28_000001_create_agencias_table.php` | Create | `agencias`: id, name, code único, grupo_id (FK cascade, NOT NULL), active (default true), created_by (FK set null), fiscal (rif/email/telefono/direccion/estado/municipio), timestamps, softDeletes; índices: unique(code), index(grupo_id) |
| `backend/database/migrations/2026_08_28_000002_add_agencia_id_to_taquillas_table.php` | Create | `agencia_id` nullable FK agencias `onDelete('set null')` + index. Down: drop FK + columna |
| `backend/database/migrations/2026_08_28_000003_add_agencia_id_to_users_table.php` | Create | Ídem para `users.agencia_id` |
| `backend/database/migrations/2026_08_28_000004_add_master_id_to_bancas_table.php` | Create | `master_id` nullable FK users `set null` + index |
| `backend/app/Models/Agencia.php` | Create | Fillable, casts active, SoftDeletes, `grupo()`, `taquillas()`, `users()`, `creator()` |
| `backend/app/Models/Taquilla.php`, `User.php`, `Grupo.php`, `Banca.php` | Modify | +`agencia_id`/`agencia()`; Grupo +`agencias()`; Banca +`master_id`/`master()`; User +`masterBancaIds()` (pluck Banca donde `master_id = user.id`) |
| `backend/app/Console/Commands/AgenciasBackfill.php` | Create | Ver Interfaces |
| `backend/database/seeders/RolesAndPermissionsSeeder.php` | Modify | Rol `agencia` + permisos `view_taquillas, manage_taquillas, view_apuestas, create_apuesta, delete_apuesta, create_pago, view_pagos, view_reports, create_cierre, view_cierre` |
| `backend/database/seeders/UsersSeeder.php` | Modify | Usuario demo `agencia@lotto.com` (rol agencia, agencia del Grupo Test) |
| `backend/database/factories/AgenciaFactory.php` | Create | code unique `A###`; TaquillaFactory/UserFactory +`agencia_id` null default (+ estado `forAgencia()`) |
| `backend/app/Http/Controllers/Api/AgenciaController.php` | Create | CRUD scoped (ver Interfaces) |
| `backend/app/Http/Controllers/Api/{AuthController,TaquillaController,UserController,BancaController,GrupoController,ApuestaController,CierreController,JuegoController,ReporteController,EstadisticaController}.php` | Modify | Ramas `agencia` y/o `whereIn master` (ver Interfaces) |
| `backend/app/Policies/ApuestaPolicy.php` | Modify | `viewAny`+`agencia`; `view`/`delete` rama agencia (`taquilla.agencia_id === user.agencia_id`) |
| `backend/app/Services/ApuestaService.php` | Modify | `ventasTotales`/`cuadreCaja` nivel `agencia`=agencias + nivel `taquilla`=máquinas; `pagosCuadrePorNivel` joins por nivel; `rendimientoTaquillas` nivel `agencia`; `relacionTickets`/`vencidos`: `Agencia`=local, `Taquilla`=máquina |
| `backend/app/Services/ActivacionEfectivaService.php` | Modify | `estadoTaquilla` + agencia (causa `'agencia'`); `mensajeCadenaInactiva` rama agencia |
| `backend/routes/api.php` | Modify | `role:` +`agencia` en `taquillas` y `limites` GET; `apiResource('agencias')` |
| `backend/app/Http/Middleware/VerifyMac.php` | Modify | Mensajes: "agencia"→"taquilla" (solo aplica a rol taquilla) |
| `backend/tests/Feature/*` | Modify/Create | Ver Testing Strategy |
| `panel/src/pages/{login.astro,agencias.astro,agencias/detalle.astro}` | Create/Modify | Login +agencia; CRUD locals |
| `panel/src/utils/api.ts`, `layouts/AdminLayout.astro` | Modify | ROLES +agencia; sidebar "Taquillas"→`/taquillas`, nueva "Agencias"→`/agencias` |
| `panel/src/pages/{usuarios.astro,taquillas.astro,taquillas/detalle.astro,bancas.astro,bancas/detalle.astro,grupos/detalle.astro,dashboard.astro,cuadre.astro,reportes/*,limites.astro,rendimiento.astro}` | Modify | ROLE_LABELS `{agencia:'Agencia', taquilla:'Taquilla'}`; select agencia; nivel local vs máquina; master select en banca; stats locales+taquillas |

## Interfaces / Contracts

**Backfill** — `php artisan agencias:backfill {--force} {--dry-run}`:
1. Por cada grupo sin agencias: crea 1 (`name: "{grupo.name} - Local"`, `code: grupo.code+"-L01"` con sufijo si colisiona, `created_by: super_master`).
2. `taquillas.agencia_id` = agencia de su grupo (skip si ya asignado); `users.agencia_id` = agencia de su taquilla (rol taquilla).
3. `bancas.master_id` = `created_by` cuando el creador es rol `master` y `master_id` es null.
4. Idempotente (skip donde ya hay valor); en `production` exige `--force`; log de resumen (creadas/asignadas/skipeadas).

**Scope master (helper)** — `User::masterBancaIds(): Collection<int>`; en cada punto global: `$query->whereHas('taquilla.grupo.banca', fn($b) => $b->whereIn('banca_id', $ids))` (o `whereIn('banca_id', ...)` directo); **lista vacía ⇒ `whereRaw('1=0')`** (nunca global). Puntos: BancaController (index/show/update/toggle/destroy + `authorizeBancaAccess`), GrupoController (index + authorize*), TaquillaController, `ReporteController::buildApuestaQuery/buildTicketQuery`, `EstadisticaController::buildApuestaQuery`, `ApuestaController::index`, `CierreController` (index/resolve/authorize), `JuegoController::limites/listarLimites`, `UserController::index` (patrón actual banca_id → whereIn por cadena).

**Rama agencia** (patrón en los queries):
```php
} elseif ($user->role === 'agencia') {
    $query->whereHas('taquilla', fn ($t) => $t->where('agencia_id', $user->agencia_id));
}
```

**TaquillaController::store (agencia)**: `agencia_id` nullable `exists:agencias,id`; si el autenticado es `agencia` → `agencia_id = user.agencia_id`, `grupo_id = agencia.grupo_id` (no editables); nueva `authorizeAgenciaAccess` (agencia solo su local; grupo sus locales; banca su banca; super/master todo); usuario creado incluye `agencia_id`; `index` rama `where('agencia_id', ...)`. `JuegoController::limites` GET rama agencia: filas `banca_id` de su banca + `grupo_id` de su grupo + `taquilla_id` de sus taquillas. `UserController`: validar `agencia_id`, derivar cadena desde agencia, `validateRoleBindings` (rol agencia exige `agencia_id`), `authorizeEntityBinding` rama agencia.

**AgenciaController**: `index` scoped (super/master todas; banca por `grupo.banca_id`; grupo `grupo_id`; agencia solo su id), `store/update` (validar `code` unique, `grupo_id` exists + `authorizeGrupoAccess` del patrón TaquillaController), `toggle`, `destroy` (soft delete; `agencia_id` en taquillas/users queda null vía FK `set null`).

**Login**: X-Panel permite `['super_master','master','banca','grupo','agencia']`; taquilla → 403 "Las taquillas deben usar la app de escritorio."; `mensajeCadenaInactiva` rama agencia ("Tu cuenta está pausada porque tu local está desactivado." → grupo → banca). Payload expone `agencia_id` (fillable). `VerifyMac` no aplica a agencia.

**ActivacionEfectivaService::estadoTaquilla**: tras `active` propio y antes del grupo: `$taquilla->agencia && !$taquilla->agencia->active ⇒ ['active'=>false,'causa'=>'agencia']`.

## Testing Strategy

| Capa | Qué probar | Enfoque |
|------|-----------|---------|
| Unit | Backfill idempotente; cadena activación con agencia | `AgenciasBackfillTest` (2ª ejecución no duplica; --force en prod); `ActivacionEntidadesTest` extendido |
| Integration (Feature) | CRUD agencias scoped; agencia crea taquilla solo en su local; master ve solo sus bancas; reportes nivel agencia=locals/taquilla=máquinas | Nuevos `AgenciaApiTest`, `AgenciaScopeTest`, `SuperBancaScopeTest` |
| Regression | Tests que fijan agencia≡taquilla | Actualizar: `TerminologiaTest` (claves `Agencia`=local/`Taquilla`=máquina; mensajes "Las taquillas deben usar la app de escritorio.", "La taquilla está desactivada.", "Taquilla eliminada correctamente.", "Solo las taquillas pueden crear apuestas/tickets.", "Taquilla activada exitosamente."), `CuadreCajaReportTest` (nivel agencia=locals, +nivel taquilla), `ReporteTest`, `EstadisticaTest`, `RoleAuthorizationTest` (agencia), login X-Panel, `GestionEntidadesApiTest` (filtro `agencia_id`), `LimitesScopedApiTest` (GET límites agencia) |
| E2E | Login agencia en panel, sidebar, CRUD local | Manual (panel no tiene suite e2e automatizada) |

## Threat Matrix

N/A — no hay routing/shell/subprocess, VCS/PR automation, clasificación de ejecutables ni integración de procesos. El comando artisan solo escribe en la BD local (sin `exec`/shell).

## Migration / Rollout

- **F0 datos** (prerequisito): migraciones → modelos → seeder rol → factories → comando backfill. Despliegue: `migrate` → `agencias:backfill` → verificar counts.
- **F1 scope agencia**: login, policies, Taquilla/User/Cierre/Juego/Apuesta/Reporte/Estadistica + activación + rutas. Depende de F0.
- **F2 super banca** (ortogonal a F1, solo depende de F0): `whereIn master` en los ~10 puntos + select master en `bancas.astro`.
- **F3 reportes**: `ApuestaService` niveles/labels. Depende de F1.
- **F4 panel**: login, utils, sidebar, usuarios, taquillas, agencias, reportes, cuadre, dashboard, límites. Depende de F1+F3.
- **F5 endurecer**: TerminologiaTest, limpieza de mensajes, `composer test` + `pint --test`.
- **Rollback**: `down` de migraciones restaura esquema (columnas nullable); backfill reversible (`agencia_id=null`); master scope se revierte quitando `whereIn`; panel revierte por commit. Panel previo no rompe con backend nuevo (todo nullable).

## Open Questions

- [ ] Ninguna bloqueante. Pendientes de confirmación del cliente post-backfill: renombrar/crear locals reales; y si el local necesitará configuración propia (implica D6 fase posterior).
