# Tasks: Cierre de Caja (diario + semanal) para la taquilla

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1150 (backend 250, tests 350, taquilla 250, electron 80, collections 80, migration 60, models 40) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 → PR 2 → PR 3 → PR 4 → PR 5 → PR 6 |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

> Delivery update (maintainer 2026-09-17): entrega final = un solo PR con size:exception aprobado; los slices quedan como work units de commits, no como PRs separados.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Migración + modelos (`metodo_pago`, arqueo, desglose) | PR 1 | `composer test --filter PagoMetodoPagoTest` | `php artisan migrate` + `migrate:rollback` | `migrate:rollback` dropea columnas; revertir `Pago.php`/`CierreCaja.php` |
| 2 | Captura `metodo_pago` en venta/premio | PR 2 | `composer test --filter MetodoPagoTest` | `composer test` (MySQL 8.0) | revertir `ApuestaService`/`PagoController`/`TicketController`/`ApuestaStoreRequest` + borrar `MetodoPagoTest` |
| 3 | Cierre diario: arqueo + desglose + tasa fallback | PR 3 | `composer test --filter CierreCajaTest` | `composer test` | revertir `CierreService`/`CierreController` + tests de arqueo/tasa |
| 4 | Semanal + preview + orden de rutas | PR 4 | `composer test --filter CierreCajaTest` | `composer test` | quitar rutas `/cierre/actual|semanal` + métodos (aditivos) |
| 5 | Taquilla UI + Electron IPC `print-cierre` | PR 5 | `npm run build` (taquilla) | E2E manual en Electron (sin runner) | revertir `cierre.astro` a placeholder + quitar `print-cierre` |
| 6 | Colecciones Bruno | PR 6 | N/A (colecciones) | Ejecución manual en Bruno | revertir `*.yml` |

## PR Slices (stacked-to-main)

- **PR 1 — Fundación**: migración `2026_09_17_000001_add_metodo_pago_and_arqueo.php`, `Pago.php`, `CierreCaja.php`, `Unit/PagoMetodoPagoTest.php`. Verificación: `composer test --filter PagoMetodoPagoTest`. Rollback: `migrate:rollback` + revertir modelos.
- **PR 2 — Captura método**: `ApuestaService.php`, `PagoController.php`, `TicketController.php`, `ApuestaStoreRequest.php`, `Feature/MetodoPagoTest.php`. Base: main. Verificación: `composer test --filter MetodoPagoTest`. Rollback: revertir 4 archivos + borrar test.
- **PR 3 — Cierre diario**: `CierreService.php` (`resolverTasa`, `calcularTotales`, `crearCierre`), `CierreController::store`, `Feature/CierreCajaTest.php` (arqueo/desglose/tasa/no-bloqueo). Verificación: `composer test --filter CierreCajaTest`. Rollback: revertir service/controller + tests.
- **PR 4 — Semanal/preview/rutas**: `CierreService` (`reporteSemanal`, `previsualizar`), `CierreController` (`semanal`, `actual`), `routes/api.php`. Base: PR 3. Verificación: `composer test --filter CierreCajaTest`. Rollback: quitar rutas + métodos.
- **PR 5 — Taquilla**: `taquilla/src/pages/cierre.astro`, `taquilla/electron/main/ipcHandlers.cjs`, `taquilla/electron/preload/preload.cjs`. Base: PR 4 (backend live). Verificación: `npm run build` + E2E manual `print-cierre`. Rollback: revertir `cierre.astro` + quitar canal.
- **PR 6 — Colecciones**: `collections/Cierre de Caja/*.yml`, `collections/Pagos/*.yml`. Base: main. Verificación: manual Bruno. Rollback: revertir yml.

## Phase 1: Foundation (migración + modelos)

- [x] 1.1 Crear migración `backend/database/migrations/2026_09_17_000001_add_metodo_pago_and_arqueo.php`: `pagos.metodo_pago` ENUM nullable default `efectivo` + backfill `whereNull`; `cierres_caja` arqueo/faltante (decimal 12,2 nullable), `desglose_metodos` JSON, índice `(taquilla_id,fecha_fin)`; guard MySQL `MODIFY`; `down()` reversa.
- [x] 1.2 `backend/app/Models/Pago.php`: `fillable` + const `METODOS_PAGO` + `resolverMetodoPago($metodo,$moneda,$amountUsd)` (default/`efectivo`, USD⇒`efectivo`).
- [x] 1.3 RED→GREEN `backend/tests/Unit/PagoMetodoPagoTest.php`: `resolverMetodoPago()` (default, USD forzado, método válido).
- [x] 1.4 `backend/app/Models/CierreCaja.php`: `fillable`/`casts` (`arqueo_efectivo_bs|usd`, `faltante_sobrante_bs|usd` decimal:2, `desglose_metodos` array).

## Phase 2: Captura de método de pago

- [x] 2.1 RED `backend/tests/Feature/MetodoPagoTest.php`: venta VES `transferencia`; USD⇒`efectivo`; omitido⇒`efectivo`; inválido⇒422; premio VES `pago_movil`; premio USD⇒`efectivo`; `mixto` un método.
- [x] 2.2 `backend/app/Http/Requests/ApuestaStoreRequest.php`: regla `metodo_pago` (`nullable|in:efectivo,transferencia,pago_movil,punto_venta`).
- [x] 2.3 `backend/app/Services/ApuestaService.php` `createApuesta`: persiste `metodo_pago` en el `Pago` ingreso vía `Pago::resolverMetodoPago()`.
- [x] 2.4 `backend/app/Http/Controllers/Api/TicketController.php`: valida `metodo_pago` a nivel ticket e inyecta en cada línea.
- [x] 2.5 `backend/app/Http/Controllers/Api/PagoController.php` `store`: valida `metodo_pago` (`Rule::in`), persiste; USD⇒`efectivo`; `mixto` un método. GREEN (pasa 2.1).

## Phase 3: Cierre diario (arqueo + desglose + tasa)

- [x] 3.1 RED `CierreCajaTest`: `test_cierre_con_arqueo_persiste_contado_y_diferencia`, `test_faltante_es_negativo`, `test_sobrante_es_positivo`, `test_cierre_sin_arqueo_deja_campos_nulos`, `test_desglose_por_metodo_suma_el_total_bs`, `test_usd_se_contabiliza_integro_en_efectivo`, `test_desglose_excluye_apuesta_anulada`.
- [x] 3.2 `CierreService::resolverTasa()`: activa → última `orderByDesc(reference_date)` → 422 si no existe ninguna.
- [x] 3.3 `CierreService::calcularTotales()`: ventas `apuestas` no anuladas + desglose JOIN `pagos` ingreso; egresos `pagos` egreso/devolucion + desglose por `metodo_pago`.
- [x] 3.4 `CierreService::crearCierre()`: acepta arqueo, calcula `faltante_sobrante_X = arqueo_X − total_efectivo_X`, persiste `desglose_metodos`.
- [x] 3.5 `CierreController::store()`: validación arqueo (`nullable|numeric|min:0`), pasa arqueo al service. GREEN.
- [x] 3.6 UPDATE `test_cierre_sin_tasa_activa_responde_422` → fallback ahora responde 201 (no duplicar).
- [x] 3.7 `test_venta_posterior_al_cierre_se_registra_normalmente` (no bloquea venta).

## Phase 4: Reporte semanal + preview + rutas

- [x] 4.1 RED `CierreCajaTest`: `test_semanal_rollup_semana_completa`, `test_semanal_semana_vacia_devuelve_ceros`, `test_semanal_semana_incompleta_expone_ventana_real`, `test_semanal_alcance_taquilla_y_403`, `test_semanal_respeta_fecha_desde_hasta`.
- [x] 4.2 `CierreService::reporteSemanal()`: rollup diarios `fecha_fin ∈ [desde, hasta+1d)`, suma totales + merge `desglose_metodos`, `ventana_cubierta` (min/max `fecha_fin`).
- [x] 4.3 `CierreService::previsualizar()`: reutiliza `calcularTotales()` para `GET /cierre/actual`.
- [x] 4.4 `CierreController::semanal()` + `::actual()`: misma autorización/alcance que `index`; `assertTaquillaEnAlcance` (403). GREEN.
- [x] 4.5 `backend/routes/api.php`: declarar `GET /cierre/actual` y `GET /cierre/semanal` **ANTES** de `GET /cierre/{cierre}` (AD-11).
- [x] 4.6 Test de orden: feature `semanal` debe fallar (404) si se reordena tras `{cierre}`.

## Phase 5: Taquilla UI + Electron IPC

- [x] 5.1 `taquilla/src/pages/cierre.astro`: resumen (`GET actual`), arqueo + ejecutar (confirm `showModal`), diferencia en vivo, resultado con badges, historial (`GET /cierre`), reporte semanal, manejo de errores (`ApiError.kind`).
- [x] 5.2 `taquilla/electron/main/ipcHandlers.cjs`: canal `print-cierre` + `generateCierreHtml` (HTML 80mm, escapa `referencia`/`concepto`, montos `toLocaleString('es-VE')`, fallback diálogo).
- [x] 5.3 `taquilla/electron/preload/preload.cjs`: exponer `printCierre`.
- [x] 5.4 E2E manual documentado de `print-cierre` (taquilla sin runner de tests).
  - Nota: `taquilla/` no tiene runner de tests; la verificación del slice 5 es `pnpm build` (exit 0) + smoke test de `generateCierreHtml` (escapado de texto libre, montos `es-VE`, desglose 4 métodos/moneda) + E2E manual documentado en apply-progress.

## Phase 6: Colecciones Bruno

- [x] 6.1 Actualizar `collections/Cierre de Caja/Crear Cierre (Token required).yml` (arqueo) + nuevo `Cierre Semanal (Token required).yml`.
- [x] 6.2 Actualizar `collections/Pagos/*.yml` con `metodo_pago`.
