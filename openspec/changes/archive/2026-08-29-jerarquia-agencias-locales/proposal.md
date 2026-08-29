# Propuesta: Jerarquía de agencias (locales)

## Intent

Modelar la jerarquía real de 6 niveles (`super_master` → `master` → `banca` → `grupo` → **agencia** → **taquilla`). Hoy no existe el local físico: `agencia` ≡ `taquilla` (la máquina se muestra como "Agencia" en toda la UI) y el `master` ve TODAS las entidades (global). El cliente cerró 4 decisiones: (1) AGENCIA = local físico (nuevo nivel entre grupo y taquilla); (2) TAQUILLA = máquina que genera tickets (entidad actual); (3) SUPER BANCA (master) ve SOLO sus propias entidades; (4) rol AGENCIA entra al panel (crea sus taquillas y opera en su scope), rol TAQUILLA solo app de taquilla (como hoy).

## Scope

### In Scope
- Tabla `agencias` (identidad: `name`, `code` único, `grupo_id` NOT NULL cascade, `active`, `created_by`, `fiscal`, softDeletes) — **sin** configuración propia (monedas/vigencia/tiempo/límites).
- `taquillas.agencia_id` y `users.agencia_id` (nullable, FK `set null`); `taquillas.grupo_id` se mantiene (no se migran joins).
- `bancas.master_id` nullable (FK users) + backfill desde `created_by`.
- Rol `agencia` en **ambas** fuentes (columna `users.role` + rol Spatie) con permisos de scope propio.
- Ramas de alcance `agencia`: login X-Panel, `TaquillaController`, `UserController`, `ApuestaPolicy`, `buildApuestaQuery` ×2, `ApuestaController`, `CierreController`, `JuegoController::limites` GET.
- Alcance super banca: `whereIn('banca_id', $masterBancas)` en los ~10 queries globales + UI de bancas (elegir master).
- Cadena de activación con agencia (`ActivacionEfectivaService` + `mensajeCadenaInactiva`): taquilla → agencia → grupo → banca.
- Reportes por local (`nivel=agencia` → tabla `agencias`) y labels.
- Panel: login, sidebar, `ROLE_LABELS`, usuarios, reportes/cuadre, dashboard.
- Backfill idempotente (comando artisan, no seeder) + revisión del cliente.
- Tests: actualizar los que fijan `agencia`≡`taquilla` + nuevos de alcance.

### Out of Scope
- Configuración propia del local (monedas/vigencia/tiempo/límites) — fase posterior; `juego_limites` y `getEffective*` quedan intactos.
- `juego_limites.agencia_id` (exige recrear índice único — destructivo).
- Cambios en `taquilla/` (app de la máquina).
- Migrar `taquillas.grupo_id` a agencia (se mantiene para no romper joins).
- Rol taquilla en panel (sigue solo app de taquilla).

## Decisiones cerradas

| # | Decisión |
|---|----------|
| 1 | AGENCIA = local físico; TAQUILLA = máquina (entidades y nomenclatura separadas) |
| 2 | `agencias` = solo identidad/agrupación (passthrough); no configura límites/monedas/vigencia/tiempo |
| 3 | Master↔bancas vía columna `bancas.master_id` (FK users), backfill desde `created_by` |
| 4 | Enfoque A: tabla mínima nullable + `agencia_id` en taquillas/users + rama agencia en scopes + `whereIn banca_id` (master) + labels UI + backfill 1 local/grupo + actualizar tests en el mismo cambio |

## Capabilities

> Contrato con sdd-spec. Investigado `openspec/specs/`: **vacío** — no existen specs principales de dominio; todo es nuevo.

### New Capabilities
- `jerarquia-agencias`: entidad agencia (local), rol + permisos, login X-Panel, creación de taquillas scoped, cadena de activación, alcance en users/taquillas/apuestas/cierre/límites y backfill idempotente.
- `alcance-super-banca`: `bancas.master_id` y restricción del master a sus propias bancas en los ~10 queries globales.
- `reportes-agencia`: agrupación de reportes por local (`nivel=agencia`) y semántica de labels (Agencia=local, Taquilla=máquina).
- `panel-jerarquia`: navegación, `ROLE_LABELS`, login y formularios del panel para la jerarquía de 6 niveles.

### Modified Capabilities
- None — no hay specs principales previas; el comportamiento de dominio vive solo en código.

## Approach

1. **Esquema + backfill (F0, prerequisito)**: migraciones aditivas (`agencias`, `taquillas.agencia_id`, `users.agencia_id`, `bancas.master_id`); seeder rol `agencia` + permisos (`view_taquillas, manage_taquillas, view_apuestas, create_apuesta, delete_apuesta, create_pago, view_pagos, view_reports, create_cierre, view_cierre`); factories (`AgenciaFactory`, ajustes `TaquillaFactory`/`UserFactory`).
2. **Alcance super banca (~10 queries)**: `whereIn('banca_id', $masterBancas)` en `BancaController`, `GrupoController` (authorize*), `TaquillaController`, `ReporteController::buildApuestaQuery/buildTicketQuery`, `EstadisticaController::buildApuestaQuery`, `ApuestaController::index`, `CierreController` (index/resolve/authorize), `JuegoController::limites*` y panel (`AdminLayout`, dashboard, tasas, logs). Sin backfill, masters existentes verían todo vacío.
3. **Login agencia (X-Panel)**: añadir `'agencia'` al branch X-Panel de `AuthController::login`; `mensajeCadenaInactiva` con rama agencia; `login.astro` `ROLES_PERMITIDOS` + `utils/api.ts` `ROLES` + `agencia`; payload `user` expone `agencia_id` (`User::fillable` + relación `agencia()`). `VerifyMac` no aplica.
4. **Creación de taquillas por agencia**: ruta `taquillas` acepta `agencia`; `store` deriva `agencia_id = user.agencia_id` y `grupo_id` de la agencia (no editable); nueva `authorizeAgenciaAccess`; usuario creado incluye `agencia_id`; `index` rama agencia `where('agencia_id', ...)`.
5. **Cadena de activación**: añadir agencia a `ActivacionEfectivaService::estadoTaquilla` y `mensajeCadenaInactiva` — desactivar un local pausa sus taquillas.
6. **Reportes por local**: `ventasTotales`/`cuadreCaja` `nivel=agencia` → agrupar por `agencias` (joins `taquillas→agencias→grupos→bancas`); `rendimientoTaquillas` label "Taquilla"; `relacionTickets`/`vencidos` `Agencia`=local + `Taquilla`=máquina. Corrige bug latente de `ventasTotales` (hoy `nivel=agencia` cae a banca).
7. **Renames UI**: `AdminLayout` "Agencias"→/taquillas pasa a "Taquillas"; nueva entrada "Agencias"→/agencias (locals); `ROLE_LABELS` `agencia:'Agencia'`, `taquilla:'Taquilla'`; `usuarios` (select agencia), `reportes/ventas`, `cuadre`, `dashboard`, `limites`.
8. **Migración de datos**: comando artisan idempotente (no seeder) — 1 local por grupo (`name "{grupo} - Local"`); `taquillas.agencia_id`/`users.agencia_id` derivados; ejecutable con `--force` + log; **revisión posterior del cliente** para renombrar/crear locals reales.

## Affected Areas

| Área | Impacto | Descripción |
|------|---------|-------------|
| `backend/database/migrations/*` | New | `agencias`, `agencia_id` (taquillas/users), `master_id` (bancas) |
| `backend/database/seeders/` | Modified | `RolesAndPermissionsSeeder` + `DatabaseSeeder`/`UsersSeeder` (rol agencia) |
| `backend/app/Models/` | New/Modified | `Agencia` (nuevo), `User`, `Taquilla` (+agencia) |
| `backend/app/Http/Controllers/Api/` | Modified | `AuthController`, `TaquillaController`, `UserController`, `BancaController`, `GrupoController`, `ApuestaController`, `CierreController`, `JuegoController`, `ReporteController`, `EstadisticaController` |
| `backend/app/Policies/ApuestaPolicy.php` | Modified | Rama rol agencia |
| `backend/app/Services/` | Modified | `ApuestaService` (reportes), `ActivacionEfectivaService` |
| `backend/routes/api.php` | Modified | Rol `agencia` en `taquillas` y `limites` GET |
| `backend/tests/Feature/` | Modified/New | `TerminologiaTest`, `RoleAuthorizationTest`, `CuadreCajaReportTest`, `ReporteTest`, `EstadisticaTest`, `ActivacionEntidadesTest`, `GestionEntidadesApiTest` + tests nuevos agencia/master-scope |
| `panel/src/` | Modified | `login.astro`, `utils/api.ts`, `AdminLayout.astro`, `usuarios.astro`, `taquillas.astro`(+detalle), `bancas/detalle.astro`, `grupos/detalle.astro`, `dashboard.astro`, `cuadre.astro`, `reportes/*`, `limites.astro` |
| `taquilla/` | Unchanged | Sin cambios esperados |

## Risks

| Riesgo | Probabilidad | Mitigación |
|--------|--------------|------------|
| Master↔banca sin modelo: sin backfill, masters existentes ven todo vacío (pérdida funcional real) | Alta | `bancas.master_id` + backfill desde `created_by` en el mismo cambio; verificar masters del seeder |
| Tests existentes fijan `agencia`≡`taquilla` → suite roja | Alta | Actualizar `TerminologiaTest`, `CuadreCajaReportTest`, `ReporteTest`, login X-Panel, `RoleAuthorizationTest` en el mismo cambio |
| Bug latente `ventasTotales` (`nivel=agencia` cae a banca) | Media | Añadir rama `agencia` al introducir el local |
| Backfill en producción inventa datos (1 local/grupo ≠ realidad física) | Media | Comando idempotente + `--force` + log + revisión del cliente |
| Local desactivado no pausa sus taquillas (fuga de control) | Media | Añadir agencia a `ActivacionEfectivaService` |
| Doble fuente de rol: olvidar `users.role` o rol Spatie rompe auth por separado | Media | Registrar rol agencia en ambas fuentes (columna + seeder Spatie) |

## Rollback Plan

- Migraciones **aditivas** (tablas/columnas nuevas, todas nullable); `rollback` de las migraciones restaura el esquema sin pérdida de datos existentes (`taquillas.grupo_id` intacto).
- Backfill idempotente y reversible: no destruye datos; se puede re-ejecutar o revertir con `agencia_id = null`.
- Alcance super banca se revierte quitando el `whereIn` (vuelve a global).
- UI/panel: revertir commits de labels/navegación; el backend no rompe con el panel previo (columna `agencia_id` nullable).

## Dependencies

- Ninguna externa. Dependencias internas: F0 (datos) prerequisito de F1–F4; F2 (super banca) es ortogonal y puede adelantarse.

## Success Criteria

- [ ] `composer test` verde (suite actualizada + nuevos tests de alcance agencia y master-scope).
- [ ] Migraciones y backfill idempotente corren en dev y producen 1 local por grupo; taquillas y usuarios con `agencia_id`.
- [ ] Rol `agencia` entra al panel (X-Panel) y crea taquillas SOLO para su local; no accede a bancas/grupos/tasas.
- [ ] Master ve SOLO sus bancas (y descendientes) en todos los queries globales; super_master sigue viendo todo.
- [ ] Desactivar un local pausa sus taquillas (cadena de activación).
- [ ] Reportes: `nivel=agencia` agrupa por local; labels correctos en panel (Agencia=local, Taquilla=máquina).
- [ ] Cliente revisa y aprueba el backfill (rename/creación de locals reales).
