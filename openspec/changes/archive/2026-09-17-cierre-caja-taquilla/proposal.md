# Proposal: Cierre de Caja (diario + semanal) para la taquilla

## Intent

Cubrir la brecha operativa del cierre de caja: hoy `POST /api/v1/cierre` calcula totales por moneda pero **no desglosa por método de pago**, **no registra el arqueo físico**, y **no ofrece cierre semanal**. La taquilla (`cierre.astro`) es un placeholder sin UI. El cliente necesita conciliar efectivo (faltante/sobrante), desglosar VES por método (efectivo/transferencia/pago móvil/punto de venta) y consultar un reporte semanal, **sin** que el cierre bloquee la venta.

## Scope

### In Scope

- Captura de `metodo_pago` (`efectivo | transferencia | pago_movil | punto_venta`) en todo cobro: `ingreso` (`ApuestaService::crearApuesta`) y `egreso`/`devolucion` (`PagoController::store`). USD por defecto `efectivo`.
- Desglose por método de pago en los totales del cierre.
- Arqueo físico: campos de efectivo contado por moneda y diferencia (faltante/sobrante).
- Cierre semanal como **reporte** derivado de los diarios persistidos (`GET /api/v1/cierre/semanal`).
- UI completa `taquilla/src/pages/cierre.astro`: resumen, ejecutar cierre + arqueo, confirmación, resultado, historial, impresión.
- Actualización de colecciones `collections/Cierre de Caja/*.yml` y `collections/Pagos/*.yml`.
- Tests: extender `CierreCajaTest` y agregar cobertura de método de pago + arqueo + semanal.

### Out of Scope

- Persistir una entidad de cierre semanal o columna `tipo` en `cierres_caja` (D1).
- Bloquear la taquilla para vender (D3).
- Cambios al reporte `cuadre-caja` (`GET /reportes/cuadre-caja`).
- Panel admin (página de cierres / reporte semanal) — **diferido**; evaluar en fase posterior.
- UI de captura de método en el flujo de venta (`dashboard.astro`): **coordinado** con `taquilla-venta-agil`, no implementado aquí.
- Cola offline / reintento de cierre sin conectividad.

## Capabilities

### New Capabilities

- `cierre-caja`: dominio del cierre — arqueo físico (contado + faltante/sobrante), desglose por método de pago, reporte semanal derivado de diarios, autorización por jerarquía y política de tasa.
- `metodo-pago`: captura y modelado del método de pago (`metodo_pago`) en cobros de venta y pagos de premio; reglas de default (USD→efectivo) y manejo de `mixto`.

### Modified Capabilities

None — no existe spec previa de `pagos` ni de `cierre`; ambas son capacidades nuevas.

## Approach

Basado en las opciones de `explore.md`, restringido por D1–D4:

- **Backend**:
  - Migración única: `metodo_pago` (enum, nullable, default `efectivo`) en `pagos`; `arqueo_efectivo_bs` / `arqueo_efectivo_usd` (decimal(12,2), nullable) en `cierres_caja`. ENUM con guard de driver (patrón `alter_pagos_tipo_add_devolucion`).
  - `CierreService::crearCierre`: agrega desglose por método (SUM por `metodo_pago`) y acepta arqueo; calcula faltante/sobrante por moneda (= contado − efectivo calculado). Sin columna `tipo`.
  - `GET /api/v1/cierre/semanal?taquilla_id=&fecha=` (o `fecha_desde/hasta`): rollup de los `CierreCaja` diarios del período; solo lectura; alcance jerárquico igual a `index`.
  - `PagoController::store` y `ApuestaService::crearApuesta`: persisten `metodo_pago` validado; USD fuerza `efectivo`; `mixto` lleva un único método para todo el pago (limitación documentada).
- **Taquilla** (opción 6 de explore): `cierre.astro` completo con patrones existentes (`apiFetch`, `MainLayout`, `showModal`, `toLocaleString('es-VE')`, `electron-pos-printer`).
- **Panel**: diferido (opción 7).

## Binding Decisions

| # | Decisión (confirmada por el owner) | Efecto en el diseño |
|---|---|---|
| **D1** | Cierre semanal es un **reporte** derivado de diarios persistidos. NO persistir entidad semanal ni columna `tipo`. | Se implementa solo `GET /cierre/semanal`; sin migración de `tipo`. |
| **D2** | Captura de `metodo_pago` **en alcance** en todo cobro; el cierre desglosa por método. USD puede default `efectivo` (a confirmar). | Migración `metodo_pago` + captura en venta y premio; coordinación obligatoria con `taquilla-venta-agil`. |
| **D3** | El cierre solo registra/reporta; **no** bloquea la venta. | Sin candado de venta; sin cambio de estado operativo de taquilla. |
| **D4** | Arqueo **en alcance**: cajero cuenta efectivo; sistema muestra diferencia (faltante/sobrante) por moneda. | Campos de contado + diferencia en `cierres_caja` y en la UI. |

## Open Questions (con recomendación)

1. **Ventana semanal** — ¿calendario (lunes–domingo `America/Caracas`) o 7 días rodantes?
   *Recomendación*: calendario lunes–domingo; el reporte agrega los diarios cuyo `fecha_fin` cae en la semana. Determinista y auditable.
2. **Política de tasa** — ¿tasa activa del momento o por transacción? ¿fallback sin tasa activa (hoy 422)?
   *Recomendación*: cada cierre diario conserva su `exchange_rate_cierre` snapshot; el semanal (solo lectura) no requiere tasa viva. Para el diario, si no hay tasa activa usar la última tasa por `reference_date` en lugar de 422; el 422 actual rompe el flujo operativo.
3. **Rol ejecutor** — ¿permission específico o roles jerárquicos actuales?
   *Recomendación*: mantener roles actuales (taquilla cierra su caja; admins cierran por alcance). Opcional: permission Spatie `ejecutar_cierre` para admins. Sin confirmación de supervisor en esta iteración.
4. **Pago `mixto`** — método distinto por moneda en un mismo pago.
   *Recomendación*: un único `metodo_pago` por `Pago` (default `efectivo`); `mixto` queda con un solo método para todo el pago. Método-por-moneda se difiere como refinamiento.
5. **Datos históricos sin método** — pagos/cierres previos sin `metodo_pago`.
   *Recomendación*: backfill `metodo_pago = efectivo` por defecto (migración); desglose histórico aproximado, sin backfill manual. Documentar como limitación.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `backend/database/migrations/*` | New | Migración: `pagos.metodo_pago` + `cierres_caja.arqueo_*`; backfill default. |
| `backend/app/Models/Pago.php` | Modified | `fillable`/`casts` para `metodo_pago`. |
| `backend/app/Models/CierreCaja.php` | Modified | `fillable`/`casts` para arqueo. |
| `backend/app/Services/CierreService.php` | Modified | Desglose por método, arqueo, diferencia, método `reporteSemanal()`. |
| `backend/app/Http/Controllers/Api/CierreController.php` | Modified | Endpoint semanal + aceptar arqueo en `store`. |
| `backend/app/Http/Controllers/Api/PagoController.php` | Modified | Capturar `metodo_pago` en `egreso`/`devolucion`. |
| `backend/app/Services/ApuestaService.php` | Modified | Capturar `metodo_pago` en `ingreso` (cobro). |
| `backend/routes/api.php` | Modified | Ruta `GET /api/v1/cierre/semanal`. |
| `backend/tests/Feature/CierreCajaTest.php` (+nuevos) | Modified | Tests semanal, arqueo, desglose por método. |
| `taquilla/src/pages/cierre.astro` | Modified | UI completa (placeholder → funcional). |
| `collections/Cierre de Caja/*.yml`, `collections/Pagos/*.yml` | Modified | Nuevos endpoints/campos. |
| `taquilla/src/pages/dashboard.astro` | Coordinated | Captura de método en cobro — **NO en este worktree** (`taquilla-venta-agil`). |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Conflicto con `taquilla-venta-agil` en `dashboard.astro` / `ApuestaService` (flujo de venta) | High | Contrato de API de `metodo_pago` documentado aquí; coordinación explícita antes de apply; este cambio no edita `dashboard.astro`. |
| Migración ENUM en MySQL (SQLite no valida enum en tests) | Med | Guard de driver (patrón `alter_pagos_tipo_add_devolucion`); validar enum en app layer; probar en MySQL/CI. |
| Sin tasa activa → cierre imposible (422) | Med | Fallback a última tasa por `reference_date` (política propuesta en OQ2). |
| Datos históricos sin `metodo_pago` | Med | Backfill default `efectivo`; limitación documentada. |
| Semana incompleta si faltan cierres diarios | Med | El reporte agrega solo diarios existentes y expone la ventana cubierta (fechas reales de los diarios incluidos). |
| Doble fuente de verdad de "efectivo" (`CierreService` vs `cuadreCaja`) | Med | Documentar diferencia de fórmula; no intentar cuadrar con `cuadre-caja` en esta iteración. |
| Sin cola offline: cierre exige conectividad | Low | `apiFetch` ya reporta desconexión; UX de reintento manual; sin cola (out of scope). |

## Rollback Plan

- **Backend**: migración con `down()` reversible (drop column `metodo_pago`, drop column `arqueo_*`); rollback de imagen Docker a la versión previa. `GET /cierre/semanal` es aditivo — quitar la ruta lo revierte sin pérdida de datos.
- **Frontend**: revertir `cierre.astro` a placeholder; sin persistencia de estado.
- No hay escritura destructiva: el cierre es solo lectura sobre datos existentes; los campos nuevos son nullable.

## Dependencies

- Coordinación con `taquilla-venta-agil` (contrato `metodo_pago` en cobro de venta, `dashboard.astro`).
- Tasa de cambio activa (o fallback) disponible al ejecutar cierre diario.

## Success Criteria

- [ ] El cierre diario persiste desglose por método de pago y arqueo (contado + faltante/sobrante por moneda).
- [ ] `GET /api/v1/cierre/semanal` devuelve rollup de diarios con totales por moneda y por método, con alcance jerárquico.
- [ ] La taquilla puede ejecutar cierre + arqueo y ver historial desde `cierre.astro` sin bloquear ventas.
- [ ] `metodo_pago` se captura en venta (`ingreso`) y premio (`egreso`/`devolucion`); USD por defecto `efectivo`.
- [ ] Tests (semanal, arqueo, desglose, método) pasan con `composer test`.
