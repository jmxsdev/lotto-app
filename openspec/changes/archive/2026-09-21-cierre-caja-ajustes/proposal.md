# Proposal: Ajustes al Cierre de Caja (período abierto, rangos, un cierre por día, clave de cierre)

## Intent

Ajustar operativamente el cierre de caja ya implementado (`cierre-caja-taquilla`, PR #12, sin release): comunicar explícitamente el **período abierto**, habilitar **reportes por rangos arbitrarios** (dos calendarios, listado + impresión), imponer **un solo cierre por día calendario** con re-cierre idempotente que **actualiza** la fila del día, y exigir una **clave de cierre** configurable desde el panel admin. Todo sobre la misma rama/PR.

## Scope

### In Scope

- Período abierto explícito en "Resumen del período actual" (rango visible: último cierre → ahora).
- Reportes por rangos: sección renombrada, dos date pickers (`desde`/`hasta`), rangos día/semana/custom, listado de cierres del rango + totales, imprimible desde la taquilla.
- Un cierre por día calendario `America/Caracas`: primer cierre libre; segundo cierre del mismo día **actualiza** la fila (extiende `fecha_fin`, recalcula totales/desglose/faltante-sobrante desde el `fecha_inicio` original); requiere `clave_cierre`; idempotente; auditoría `reclosed_by`/`reclosed_at` (nunca se guarda la clave).
- Clave de cierre: roles `super_master|master|banca`; validación contra cualquier usuario elegible de la **cadena arriba de la taquilla** (banca → master → super_master).
- Panel UI: página autónoma `panel/src/pages/clave-cierre.astro` + 1 línea de nav en `AdminLayout.astro` (roles elegibles).
- Migración, backend, taquilla, electron, colecciones y tests descritos en Affected Areas.

### Out of Scope

- Cambios al panel más allá de la página nueva + 1 línea de nav.
- Renombrar endpoints (se mantiene `GET /cierre/semanal`; sin alias `/cierre/reporte`).
- Backfill de datos demo con múltiples cierres por día.
- Edición de `panel/src/utils/api.ts`.
- Cola offline / reintento automático.

## Capabilities

### New Capabilities

- `clave-cierre`: almacenamiento hasheado por usuario, endpoint self-service de configuración (`GET`/`PUT /usuarios/clave-cierre`), formato (PIN numérico), validación de cadena jerárquica y no-serialización del hash.

### Modified Capabilities

- `cierre-caja`: un cierre por día calendario (re-cierre idempotente con auditoría), reporte por rango con listado completo de cierres (`cierres[]` con desglose), período abierto explícito y clave obligatoria en re-cierre.

## Approach

Restringida por las decisiones vinculantes; referencias a opciones de `explore.md`:

- **O1-A** (`CierreService`): helper `resolveCierreHoy()` (rango `[startOfDay, +1d)` sobre `fecha_fin`, timezone Caracas) bifurca crear (201, `reclosed:false`) vs actualizar conservando `fecha_inicio` (200, `reclosed:true`).
- **O2-A** (columna `users.clave_cierre` hash bcrypt + `Hash::check`): candidatos de la cadena = banca de la taquilla, master de esa banca, todos los `super_master`; `validarClaveCierre($taquillaId, $clave)` en el service.
- **O3-A** (endpoint self-service `PUT /usuarios/clave-cierre` + `GET` estado; roles `super_master|master|banca`; `$request->user()`; exige `clave_actual`).
- **O4-A**: se mantiene `GET /cierre/semanal` como único endpoint de rango; el renombre es solo copy UI y colecciones.
- **O5-A** (impresión por rango): ampliar `cierres[]` con shape completo (desglose incluido) + `generateReporteHtml()` + IPC `print-reporte` + `printReporte` en preload.
- **O6-A** (panel): página nueva autónoma + 1 línea de nav; NO tocar `api.ts`.
- **O7-A** (taquilla): extender `showModal` con `type:'input'`; `GET /cierre/actual` devuelve `cierre_hoy` aditivo para decidir el copy del confirm.

## Binding Decisions

| # | Decisión (aprobada por el owner) | Efecto en el diseño |
|---|---|---|
| **A1** | "Resumen del período actual" comunica el período ABIERTO con rango visible. | Copy + render en `cierre.astro` con `fecha_inicio`→`fecha_fin` etiquetado como abierto. |
| **A2** | Reportes por rangos: dos date pickers, rangos arbitrarios, listado + totales, imprimible. | Sección renombrada "Reportes por rangos"; UI usa `fecha_desde`/`fecha_hasta`; botón imprimir. |
| **A3** | Un cierre por día calendario; segundo cierre actualiza la fila (idempotente). | Bifurcación crear/actualizar en `CierreService`; auditoría `reclosed_by`/`reclosed_at`. |
| **A4** | Clave obligatoria solo en re-cierre; se valida contra la cadena de esa taquilla. | `validarClaveCierre()`; 422 si falta/incorrecta en re-cierre; primer cierre libre. |
| **A5** | Panel incluido: página nueva + 1 nav; roles `super_master|master|banca`; no tocar `api.ts`. | Página `clave-cierre.astro` + 1 link en `AdminLayout.astro`. |
| **A6** | Misma rama/PR #12; work units como commits; single-PR con `size:exception`. | Sin cambios de entrega; commits convencionales en español por work unit. |

### Decisiones de las preguntas abiertas (Q1–Q6)

| Pregunta | Decisión adoptada |
|---|---|
| **Q1** | Cambiar la clave exige la clave actual (self-service seguro). |
| **Q2** | Sin candidato con clave configurada → bloqueo del re-cierre con 422. |
| **Q3** | `cierres[]` del rango incluye el desglose completo (para imprimir). |
| **Q4** | Se mantiene `GET /cierre/semanal` como único endpoint de rango (sin alias). |
| **Q5** | Formato PIN numérico 4–8 dígitos (default 6). *Pendiente de confirmación de negocio.* |
| **Q6** | La clave se valida siempre contra la cadena de **esa** taquilla (regla única), incluso si un admin ejecuta el cierre. |

## Open Items (con recomendación)

1. **Q5 — formato de clave**: PIN numérico 4–8 dígitos (default 6). *Recomendación*: 6 dígitos numéricos; confirmar con negocio antes de fijar el spec.
2. **Datos demo con múltiples cierres el mismo día**: sin release → sin backfill. *Recomendación*: el query de "cierre de hoy" toma el último por `fecha_fin`; el update conserva su `fecha_inicio` (comportamiento correcto hacia adelante).

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `backend/database/migrations/*` | New | `users.clave_cierre` (string nullable) + `cierres_caja.reclosed_by`/`reclosed_at` (nullable). |
| `backend/app/Models/User.php` | Modified | `fillable` + `$hidden` incluye `clave_cierre` (no serializar). |
| `backend/app/Models/CierreCaja.php` | Modified | `fillable`/`casts` para `reclosed_by`/`reclosed_at`. |
| `backend/app/Services/CierreService.php` | Modified | `resolveCierreHoy()`, `validarClaveCierre()`, bifurcación crear/actualizar, `previsualizar()` con `cierre_hoy`, `reporteSemanal()` amplía `cierres[]`. |
| `backend/app/Http/Controllers/Api/CierreController.php` | Modified | Acepta `clave_cierre` en `store`; 422 en re-cierre sin/incorrecta clave. |
| `backend/routes/api.php` | Modified | Rutas self-service `GET`/`PUT /usuarios/clave-cierre` (roles `super_master|master|banca`). |
| `backend/tests/Feature/CierreCajaTest.php` (+nuevos) | Modified | Re-cierre mismo día, idempotencia, clave 422/200, cadena, formato; tests de clave. |
| `taquilla/src/pages/cierre.astro` | Modified | Copy período abierto, sección "Reportes por rangos" (dos calendarios + listado + imprimir), prompt de clave en re-cierre. |
| `taquilla/src/layouts/MainLayout.astro` | Modified | `showModal` variante `type:'input'`. |
| `taquilla/electron/main/ipcHandlers.cjs` + `preload` | Modified | `generateReporteHtml()` + IPC `print-reporte` + `printReporte`. |
| `panel/src/pages/clave-cierre.astro` | New | Página autónoma de configuración de clave. |
| `panel/src/layouts/AdminLayout.astro` | Modified | 1 línea de nav (roles elegibles). |
| `collections/Cierre de Caja/*.yml` | Modified | `clave_cierre` + semántica re-cierre; renombrar "Cierre Semanal" → "Reporte por Rango"; nueva "Configurar Clave de Cierre". |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Coordinación con worktree del panel (`AdminLayout.astro`/`api.ts` compartidos) | Med | Página nueva + 1 línea de nav; NO tocar `api.ts`; cambio mínimo y localizado. |
| Hashing/validación de clave (fuga o debilidad) | Med | bcrypt obligatorio; `$hidden` incluye `clave_cierre`; nunca guardar la clave; `Hash::check` por cadena. |
| Límite de día con timezone (comparar valor UTC crudo) | Med | Rango explícito `[startOfDay, +1d)` sobre `fecha_fin` en `America/Caracas` (no `whereDate`). |
| Datos demo con múltiples cierres el mismo día | Low | Sin release → sin backfill; actualizar conserva `fecha_inicio` original. |
| Churn de renombrar endpoint | Low | Mantener `GET /cierre/semanal`; renombre solo copy UI/colecciones. |

## Rollback Plan

- **Backend**: migración con `down()` reversible (drop `users.clave_cierre`, `cierres_caja.reclosed_by`/`reclosed_at`); rutas self-service aditivas (quitar las rutas las revierte); rollback de imagen Docker. La bifurcación de re-cierre es reversible quitando la rama de actualización (vuelve a crear fila).
- **Frontend**: revertir `cierre.astro` (copy de período/Reportes por rangos), `MainLayout.astro` (variante input) y `clave-cierre.astro`; quitar `print-reporte` y el link de nav.
- Sin escritura destructiva: columnas nuevas nullable; el re-cierre solo actualiza una fila existente.

## Dependencies

- PR #12 (base `cierre-caja-taquilla`) abierto en la misma rama.
- Confirmación de negocio del formato de clave (Q5) antes de fijar el spec.

## Success Criteria

- [ ] El "Resumen del período actual" muestra el período abierto con rango visible.
- [ ] La sección "Reportes por rangos" permite `desde`/`hasta`, lista los cierres del rango con totales y desglose, y permite imprimir.
- [ ] Un segundo cierre del mismo día actualiza la fila existente (extiende `fecha_fin`, recalcula desde `fecha_inicio` original) y registra `reclosed_by`/`reclosed_at`.
- [ ] El re-cierre exige clave válida contra la cadena de la taquilla (422 si falta/incorrecta); primer cierre libre.
- [ ] El panel permite configurar la clave (exigiendo la actual) y el hash nunca se serializa.
- [ ] Tests (`composer test`) cubren re-cierre, idempotencia, clave, cadena y rango.
