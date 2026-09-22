# Tasks: Ajustes al Cierre de Caja (período abierto, rangos, un cierre por día, clave)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~870 total (backend ~235, tests ~310, taquilla ~130, electron ~85, panel ~82, collections ~30) |
| 400-line budget risk | High |
| Chained PRs recommended | No |
| Suggested split | Single PR #12 — work-unit commits (no slices) |
| Delivery strategy | exception-ok (size:exception aprobado por maintainer) |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Commit | Focused test | Runtime harness | Rollback boundary |
|------|------|--------|--------------|-----------------|-------------------|
| WU1 | Migración + modelos | `feat(backend): migra clave_cierre y auditoria de re-cierre` | `composer test` (suite existente verde tras migrar) | `php artisan migrate` + `php artisan migrate:rollback` | `down()` (drop columnas) |
| WU2 | Clave self-service | `feat(backend): endpoints self-service de clave de cierre` | `composer test --filter=ClaveCierreTest` | `GET`/`PUT /usuarios/clave-cierre` vía HTTP | Quitar grupo de rutas + controller |
| WU3 | Re-cierre idempotente | `feat(backend): re-cierre diario idempotente con clave` | `composer test --filter=CierreCajaTest` | `POST /cierre` (crear 201 / re-cierre 200) | Quitar rama de actualización |
| WU4 | Shape completo `cierres[]` | `feat(backend): shape completo de cierres en reporte por rango` | `composer test --filter=CierreCajaTest` | `GET /cierre/semanal?fecha_desde&fecha_hasta` | Revertir map de `cierres[]` |
| WU5 | Taquilla UI + Electron | `feat(taquilla): reportes por rangos, periodo abierto y prompt de clave` | `cd taquilla && npm run build` | E2E manual (imprimir reporte) | Revertir `cierre.astro`/`MainLayout.astro`/IPC |
| WU6 | Panel clave | `feat(panel): pagina de configuracion de clave de cierre` | `cd panel && npm run build` | E2E manual (configurar clave) | Borrar `clave-cierre.astro` + 1 línea nav |
| WU7 | Colecciones | `docs(collections): clave de cierre y reporte por rango` | N/A (docs) | Inspección manual de YAML | Revertir YAML |

TDD estricto: cada WU escribe su test RED antes del código GREEN (mismo commit). Full run: `DB_DATABASE=lotto_test_cierre COMPOSER_PROCESS_TIMEOUT=1800 composer test`.

## Phase 1: Migración y modelos (WU1)

- [x] 1.1 Crear `backend/database/migrations/2026_09_21_000001_add_clave_cierre_and_reclose_audit.php`: `users.clave_cierre` (string nullable), `cierres_caja.reclosed_by` (FK users, `nullOnDelete`) y `reclosed_at` (timestamp nullable); `down()` reversible.
- [x] 1.2 `backend/app/Models/User.php`: `$fillable` += `clave_cierre`; `$hidden` += `clave_cierre`.
- [x] 1.3 `backend/app/Models/CierreCaja.php`: `$fillable` += `reclosed_by`, `reclosed_at`; cast `reclosed_at => datetime`.

## Phase 2: Backend — clave de cierre (WU2)

- [x] 2.1 **Decisión `clave_nueva_confirma` (carry-over)**: la confirmación es UI-only; NO forma parte del contrato API. `PUT` valida solo `clave_nueva` (`digits_between:4,8`) y `clave_actual` (si aplica). NO añadir regla `same:clave_nueva` server-side.
- [x] 2.2 RED: crear `backend/tests/Feature/ClaveCierreTest.php` (hash/hidden, primera config, cambio exige `clave_actual`, formato 422, estado booleano, 403 rol, self-service aislado).
- [x] 2.3 GREEN: crear `backend/app/Http/Controllers/Api/ClaveCierreController.php` (`show`/`update` sobre `$request->user()`, `Hash::make`/`Hash::check`).
- [x] 2.4 `backend/routes/api.php`: grupo `role:super_master|master|banca` con `GET`/`PUT /usuarios/clave-cierre`.
- [x] 2.5 `backend/app/Services/CierreService.php`: método `validarClaveCierre($taquillaId, $clave)` — cadena banca/master/super_master, `Hash::check` OR, `RuntimeException` sin candidatos.

## Phase 3: Backend — re-cierre idempotente (WU3 + WU4)

- [x] 3.1 RED: extender `backend/tests/Feature/CierreCajaTest.php` (segundo cierre actualiza, idempotencia, auditoría, límite de día, sin clave 422, clave incorrecta 422, sin candidatos 422, cadena banca/master/super_master, otra banca rechazada, demo multi-fila).
- [x] 3.2 GREEN: `CierreService::resolveCierreHoy()` (rango `[startOfDay, +1d)` sobre `fecha_fin`, `America/Caracas`) + bifurcación crear/actualizar en `crearCierre()` (transacción, conserva `fecha_inicio`/`created_by`).
- [x] 3.3 `backend/app/Http/Controllers/Api/CierreController.php::store()`: acepta `clave_cierre`, mapea 201 (`reclosed:false`) / 200 (`reclosed:true`).
- [x] 3.4 `CierreService::previsualizar()`: campo aditivo `cierre_hoy` (`{id, fecha_inicio, fecha_fin}` | null).
- [x] 3.5 `CierreService::reporteSemanal()`: `cierres[]` con shape completo (desglose, arqueo, faltante); totales/`ventana_cubierta` sin cambios.

## Phase 4: Tests — cobertura y regresión

- [x] 4.1 Completar cobertura de `CierreCajaTest` + `ClaveCierreTest` según tabla de Testing Strategy del design. (Auditado: 48 + 16 tests cubren la matriz; sin gaps.)
- [x] 4.2 **Regresión heredada (carry-over)**: escenarios MODIFIED sin cambios (rollup semana completa/vacía/incompleta/alcance jerárquico) siguen verdes. (Full Feature run verde.)
- [x] 4.3 **Regresión 403 (carry-over)**: ruta de autorización `POST /cierre` fuera de alcance sigue 403. (Full Feature run verde.)
- [x] 4.4 Full run: `DB_DATABASE=lotto_test_cierre COMPOSER_PROCESS_TIMEOUT=1800 composer test` → Unit 281/281 (937 asserts) + Feature 512/514 (2 skipped pre-existentes, 3059 asserts) = 793 passed / 3996 asserts, exit 0.

## Phase 5: Taquilla UI (WU5)

- [x] 5.1 `taquilla/src/layouts/MainLayout.astro`: `showModal` variante `type:'input'` (Enter confirma, Escape/overlay cancela → `string|null`).
- [x] 5.2 `taquilla/src/pages/cierre.astro`: copy "Período ABIERTO" + rango visible + badge; aviso si `preview.cierre_hoy`.
- [x] 5.3 `cierre.astro`: sección "Reportes por rangos" — dos calendarios (`reporte-desde`/`reporte-hasta`), listado con desglose expandible, totales.
- [x] 5.4 `cierre.astro`: prompt de clave (`type:'input'`, `inputType:'password'`) solo si `cierre_hoy`; `body.clave_cierre`; copy confirm distingue crear/re-cierre.

## Phase 6: Electron — impresión (WU5)

- [x] 6.1 `taquilla/electron/main/ipcHandlers.cjs`: `generateReporteHtml(reporteData)` con `escapeHtml` y montos `toLocaleString('es-VE')` (carry-over).
- [x] 6.2 `ipcHandlers.cjs` + `taquilla/electron/preload/preload.cjs`: IPC `print-reporte` + `printReporte(data)` (reutiliza `printHtml`).
- [x] 6.3 **E2E manual documentado (carry-over)**: sin runner en `taquilla/`; verificar impresión de reporte por rango (POS/diálogo) manualmente. E2E en apply-progress: `pnpm dev` → abrir Cierre de Caja → rango hoy→hoy → Consultar → 🖨️ Imprimir reporte (POS o diálogo) → verificar ticket 80mm con totales y desglose; re-cierre: con cierre de hoy, confirm → prompt de clave → clave 4–8 dígitos.

## Phase 7: Panel — clave de cierre (WU6)

- [x] 7.1 Crear `panel/src/pages/clave-cierre.astro` (GET estado, form `clave_actual`/`clave_nueva`/confirmación UI-only, PUT, `showModal` éxito/error). NO tocar `panel/src/utils/api.ts`.
- [x] 7.2 `panel/src/layouts/AdminLayout.astro`: 1 línea `addLink('Clave de Cierre', '/clave-cierre', '🔑')` (roles elegibles).

## Phase 8: Colecciones (WU7)

- [x] 8.1 `collections/Cierre de Caja/Crear Cierre (Token required).yml`: + `clave_cierre` y semántica re-cierre.
- [x] 8.2 Renombrar "Cierre Semanal" → "Reporte por Rango" (`fecha_desde`/`fecha_hasta`).
- [x] 8.3 Nueva `Configurar Clave de Cierre` (GET/PUT `/usuarios/clave-cierre`).
