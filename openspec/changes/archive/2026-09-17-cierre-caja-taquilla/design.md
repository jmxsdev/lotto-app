# Design: Cierre de Caja (diario + semanal) para la taquilla

## Technical Approach

Se extiende el cierre existente sin entidad semanal ni columna `tipo` (propuesta D1) y sin bloquear la venta (propuesta D3). Cuatro piezas: (1) captura de `pagos.metodo_pago` en origen (venta `ingreso` y premio `egreso`/`devolucion` — propuesta D2); (2) `POST /cierre` acepta arqueo, persiste contado + diferencia (propuesta D4) y un `desglose_metodos` JSON por moneda/método; (3) `GET /cierre/semanal` es un rollup de solo lectura de los diarios (semana calendario lunes–domingo `America/Caracas`); (4) `cierre.astro` consume `GET /cierre/actual` (preview aditivo), `POST /cierre`, `GET /cierre/semanal` y `GET /cierre`.

Nombres reales verificados en código: el método de venta es `ApuestaService::createApuesta` (no `crearApuesta`); el periodo se encadena por `fecha_fin`; las anulaciones son **soft delete** de `Apuesta` (el `Pago` ingreso NO se borra), dato clave para el desglose.

## Architecture Decisions

### Datos

| # | Área | Opciones | Elección | Razón |
|---|------|----------|----------|-------|
| AD-1 | Almacenar `metodo_pago` | (a) columna ENUM en `pagos`; (b) JSON; (c) tabla normalizada | (a) `pagos.metodo_pago` ENUM(`efectivo`,`transferencia`,`pago_movil`,`punto_venta`) NULL DEFAULT `efectivo` | Un método por `Pago`; ENUM es el patrón del repo (`tipo`, `moneda`); filtrable en agregaciones; (b)/(c) no aportan y complican la captura |
| AD-2 | Arqueo y faltante/sobrante | (a) persistir 4 columnas; (b) calcular diferencia on read | (a) `arqueo_efectivo_bs/_usd` y `faltante_sobrante_bs/_usd` decimal(12,2) NULL | El spec exige persistir contado y diferencia; inmutable para historial/auditoría; sin arqueo → nulas |
| AD-3 | Desglose en el cierre | (a) JSON `desglose_metodos`; (b) columnas por método (24); (c) tabla `cierre_metodo_totales` | (a) JSON nullable, shape fijo `{bs:{metodo:{ventas,egresos,efectivo}}, usd:{...}}` | 4 métodos × 2 monedas × 3 montos = 24 columnas si se aplana; el semanal suma ≤7 JSON en PHP; no hay consulta SQL por método que justifique (c) |
| AD-4 | Fuente del desglose de **ventas** | (a) `pagos` ingreso sueltos; (b) `apuestas` JOIN `pagos` ingreso con los filtros de la venta | (b) | `total_ventas_*` sale de `apuestas` (excluye anuladas/soft-deleted); sumar `pagos` sueltos dejaría ingresos huérfanos y rompería "suma de métodos == total de la moneda". Solo el bucket *ventas* normaliza a `efectivo`; el neto es `total_efectivo_usd = total_ventas_usd − total_egresos_usd` y `faltante_sobrante_usd = arqueo_efectivo_usd − total_efectivo_usd` (consistente con el ejemplo del contrato) |
| AD-5 | Ventana semanal | (a) `fecha` ancla calendario lunes–domingo; (b) 7 días rodantes; (c) rango obligatorio | (a) `fecha` (default hoy Caracas) XOR `fecha_desde`+`fecha_hasta`; ventana = lunes 00:00 → lunes siguiente 00:00; rango ad-hoc: `fecha_desde` = inicio de día **inclusivo** y `fecha_hasta` = fin de día **inclusivo** (interno `[desde, fecha_hasta + 1 día)`) | OQ1: determinista y auditable; el rango cubre reportes ad-hoc; `ventana_cubierta` expone semanas incompletas |

### Proceso y contratos

| # | Área | Opciones | Elección | Razón |
|---|------|----------|----------|-------|
| AD-6 | Fallback de tasa | (a) mantener 422; (b) última por `reference_date`; (c) tasa por transacción | (b) en `CierreService::resolverTasa()`: activa → última `orderByDesc(reference_date)->orderByDesc(id)` → 422 si no existe ninguna | OQ2: el 422 actual rompe la operación; el semanal usa solo snapshots persistidos; (c) queda fuera (no lo usa el cierre) |
| AD-7 | Migración ENUM MySQL | (a) Blueprint `enum()`; (b) string + validación app; (c) ALTER crudo con guard | (a) + backfill explícito + (c) como re-aserción MySQL-only del ENUM final y guard simétrico | Blueprint da el tipo correcto por driver (patrón `add_moneda_to_pagos_table`); el guard MySQL (patrón `alter_pagos_tipo_add_devolucion`) fija el ENUM exacto en prod; `Rule::in`/`Pago::METODOS_PAGO` validan en la capa de app (detalle de driver en el plan de migración) |
| AD-8 | Resumen pre-cierre en UI | (a) `GET /cierre/actual` read-only; (b) UI sin resumen; (c) componer con endpoints existentes | (a), misma autorización que `store`, no persiste | El arqueo necesita el efectivo esperado antes de confirmar; extrae la lógica a `calcularTotales()` (**nuevo**) como única fuente de verdad; (c) no agrega egresos ni periodo encadenado |
| AD-9 | Impresión del cierre | (a) IPC `print-cierre` + `generateCierreHtml`; (b) reutilizar `print-ticket` | (a) | `print-ticket` está cableado al HTML "LOTTO TICKET" y rechaza `lines` vacío; (a) reutiliza detección de impresora POS y fallback a diálogo del sistema |
| AD-10 | Contrato de captura (handoff `taquilla-venta-agil`) | (a) `metodo_pago` opcional aditivo; (b) obligatorio | (a) opcional, default `efectivo`, forzado a `efectivo` si hay USD | Retrocompatible: omitirlo no cambia el comportamiento actual; desacopla el cierre del flujo de venta |
| AD-11 | Orden de rutas | declarar `/cierre/semanal` y `/cierre/actual` después de `/cierre/{cierre}` | declararlas **antes** | `{cierre}` (route-model binding) capturaría "semanal"/"actual" y respondería 404 |

## Data Flow

**1. Cierre diario con arqueo**

```
Cajero (cierre.astro)
  │ GET  /cierre/actual ────────────────► CierreController@actual
  │ POST /cierre {arqueo_efectivo_*} ────► resolveTaquillaParaCierre() (rol/jerarquía)
  │                                          ▼
  │                                       CierreService::crearCierre(taquilla, user, arqueoBs, arqueoUsd)
  │                                         ├─ resolverTasa(): activa → última reference_date → 422
  │                                         ├─ resolveFechaInicio(): último cierre → 1ª apuesta → now
  │                                         ├─ ventas = SUM apuestas (no anuladas) + desglose JOIN pagos.ingreso
  │                                         ├─ egresos = SUM pagos (egreso|devolucion) + desglose por metodo_pago
  │                                         ├─ faltante_sobrante_X = arqueo_X − total_efectivo_X
  │                                         └─ CierreCaja::create(…, desglose_metodos JSON)
  └◄─ 201 {totales, arqueo, faltante_sobrante, desglose_metodos}
```

**2. Captura de método en venta y premio**

```
dashboard.astro (taquilla-venta-agil)                historial.astro (premio)
  POST /apuestas | /tickets {metodo_pago}              POST /pagos {metodo_pago}
        │                                                    │
  ApuestaStoreRequest|TicketController (Rule::in)      PagoController::store (Rule::in)
        ▼                                                    ▼
  ApuestaService::createApuesta                       Pago::create(egreso|devolucion)
        └─ Pago::create(ingreso, metodo_pago = Pago::resolverMetodoPago($metodo, $moneda, $amountUsd))
```

**3. Rollup semanal (solo lectura)**

```
cierre.astro → GET /cierre/semanal?fecha=|fecha_desde&fecha_hasta&taquilla_id?
  → CierreController@semanal: alcance jerárquico (mismo que index) + assertTaquillaEnAlcance (403)
  → CierreService::reporteSemanal(query, desde, hasta)
       cierres_caja WHERE fecha_fin ∈ [desde, fecha_hasta + 1 día)   // extremos inclusivos
       SUM totales + merge desglose_metodos + min/max(fecha_fin)
  → {totales, desglose_metodos, ventana_cubierta{desde,hasta,cierres_incluidos}, cierres[]}
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `backend/database/migrations/2026_09_17_000001_add_metodo_pago_and_arqueo.php` | Create | Columnas nuevas + backfill + índice compuesto |
| `backend/app/Models/Pago.php` | Modify | `fillable`, const `METODOS_PAGO`, `resolverMetodoPago()` |
| `backend/app/Models/CierreCaja.php` | Modify | `fillable`/`casts` (arqueo, faltante, `desglose_metodos` array) |
| `backend/app/Services/CierreService.php` | Modify | **NEW** (creados/extraídos por este cambio): `resolverTasa()`, `calcularTotales()` (ventas+desglose, egresos+desglose), `previsualizar()`, `reporteSemanal()`; arqueo+desglose dentro del existente `crearCierre()`. Hoy solo existen `crearCierre`, `listarCierres`, `resolveFechaInicio` |
| `backend/app/Http/Controllers/Api/CierreController.php` | Modify | Validación arqueo en `store`; `actual`; `semanal`; extraer alcance de `index` y `assertTaquillaEnAlcance()` |
| `backend/app/Http/Controllers/Api/PagoController.php` | Modify | Valida y persiste `metodo_pago` |
| `backend/app/Http/Controllers/Api/TicketController.php` | Modify | Valida `metodo_pago` a nivel ticket y lo inyecta en cada línea |
| `backend/app/Http/Requests/ApuestaStoreRequest.php` | Modify | Regla `metodo_pago` (`nullable|in:…`) |
| `backend/app/Services/ApuestaService.php` | Modify | `createApuesta`: persiste `metodo_pago` en el `Pago` ingreso |
| `backend/routes/api.php` | Modify | `GET /cierre/actual` y `GET /cierre/semanal` **antes** de `/cierre/{cierre}` |
| `backend/tests/Feature/CierreCajaTest.php` | Modify | Arqueo, desglose, semanal, tasa fallback, no-bloqueo |
| `backend/tests/Feature/MetodoPagoTest.php` | Create | Captura ingreso/egreso, USD forzado, 422 |
| `backend/tests/Unit/PagoMetodoPagoTest.php` | Create | `resolverMetodoPago()` (sin DB) |
| `taquilla/src/pages/cierre.astro` | Modify | UI completa (placeholder → funcional) |
| `taquilla/electron/main/ipcHandlers.cjs`, `taquilla/electron/preload/preload.cjs` | Modify | Canal `print-cierre` + `generateCierreHtml` (aditivo, sin shell) |
| `collections/Cierre de Caja/*.yml`, `collections/Pagos/*.yml` | Modify/Create | Arqueo, semanal (nuevo request), `metodo_pago` |

Fuera de este worktree: `taquilla/src/pages/dashboard.astro` (lo edita `taquilla-venta-agil`).

## Migration Plan

```php
// up() — la migración lleva la lista literal (autocontenida), no la const del modelo
$metodos = ['efectivo', 'transferencia', 'pago_movil', 'punto_venta'];

Schema::table('pagos', fn (Blueprint $t) => $t
    ->enum('metodo_pago', $metodos)->nullable()->default('efectivo')->after('moneda'));

DB::table('pagos')->whereNull('metodo_pago')->update(['metodo_pago' => 'efectivo']); // backfill OQ5 (histórico aproximado)

if (DB::connection()->getDriverName() === 'mysql') {   // guard patrón alter_pagos_tipo_add_devolucion
    DB::statement("ALTER TABLE pagos MODIFY COLUMN metodo_pago "
        ."ENUM('efectivo','transferencia','pago_movil','punto_venta') NULL DEFAULT 'efectivo'");
}

Schema::table('cierres_caja', function (Blueprint $t) {
    $t->decimal('arqueo_efectivo_bs', 12, 2)->nullable()->after('total_efectivo_usd');
    $t->decimal('arqueo_efectivo_usd', 12, 2)->nullable()->after('arqueo_efectivo_bs');
    $t->decimal('faltante_sobrante_bs', 12, 2)->nullable()->after('arqueo_efectivo_usd');
    $t->decimal('faltante_sobrante_usd', 12, 2)->nullable()->after('faltante_sobrante_bs');
    $t->json('desglose_metodos')->nullable()->after('faltante_sobrante_usd');
    $t->index(['taquilla_id', 'fecha_fin'], 'cierres_caja_taquilla_fecha_fin_index');
});

// down(): drop del índice y de las 5 columnas de cierres_caja; drop de pagos.metodo_pago
```

**Notas de driver (única nota autoritativa).** Tests y CI corren sobre **MySQL 8.0** (`backend/phpunit.xml:26`; servicio `mysql:8.0` en `.github/workflows/ci-cd.yml`), así que el ENUM se ejercita en tests y CI. En dev con SQLite, la gramática de Laravel 13 emite `CHECK` para `enum()`, y la validación de app (`Rule::in` + `Pago::METODOS_PAGO`) es la garantía portable. El `MODIFY` MySQL corre tras el `ADD COLUMN` (metadata-only, mismo ENUM) y fija la lista canónica en prod. Backfill idempotente (`whereNull` + default). Columnas nuevas nullable/JSON → aditivas y reversibles. Sin índices nuevos en `pagos` (existe el índice del FK `taquilla_id`; se evita amplificación de escritura en la tabla más caliente); el compuesto `(taquilla_id, fecha_fin)` acelera `resolveFechaInicio` y el semanal por taquilla.

## Interfaces / Contracts

```jsonc
// POST /api/v1/cierre  (request)
{ "taquilla_id": 1,                    // requerido para roles admin (422); rol taquilla: omitido o propio (403 si es otro)
  "arqueo_efectivo_bs": 1450.00,       // nullable|numeric|min:0 — opcional
  "arqueo_efectivo_usd": 30.00 }
// 201 (response; nuevos campos en contexto de totales ya existentes)
{ "id": 12, "taquilla_id": 1,
  "fecha_inicio": "…-04:00", "fecha_fin": "…-04:00",
  "total_ventas_bs": 1500.00, "total_ventas_usd": 30.00, "total_ventas_bs_equivalent": 2595.00,
  "total_egresos_bs": 40.00, "total_egresos_usd": 3.00,
  "total_efectivo_bs": 1460.00, "total_efectivo_usd": 27.00,
  "arqueo_efectivo_bs": 1450.00, "arqueo_efectivo_usd": 30.00,
  "faltante_sobrante_bs": -10.00, "faltante_sobrante_usd": 3.00,
  "desglose_metodos": {
    "bs": { "efectivo": {"ventas":1000,"egresos":30,"efectivo":970},
            "transferencia": {"ventas":300,"egresos":10,"efectivo":290},
            "pago_movil": {"ventas":150,"egresos":0,"efectivo":150},
            "punto_venta": {"ventas":50,"egresos":0,"efectivo":50} },
    "usd": { "efectivo": {"ventas":30,"egresos":3,"efectivo":27},
             "transferencia": {"ventas":0,"egresos":0,"efectivo":0},
             "pago_movil": {"ventas":0,"egresos":0,"efectivo":0},
             "punto_venta": {"ventas":0,"egresos":0,"efectivo":0} } },
  "exchange_rate_cierre": 36.5000, "created_by": 3, "taquilla": { "…": "…" } }
// 422: {"message":"No hay tasa de cambio activa para realizar el cierre."}  (solo si NO existe ninguna tasa: activa ni histórica)

// GET /api/v1/cierre/actual?taquilla_id=  (preview, mismo auth que store; no persiste)
{ "taquilla_id":1, "fecha_inicio":"…-04:00", "fecha_fin":"…-04:00", "exchange_rate":36.5,
  "total_ventas_bs":…, "total_ventas_usd":…, "total_ventas_bs_equivalent":…,
  "total_egresos_bs":…, "total_egresos_usd":…, "total_efectivo_bs":…, "total_efectivo_usd":…,
  "desglose_metodos": { /* misma forma */ } }

// GET /api/v1/cierre/semanal?fecha=YYYY-MM-DD            (ancla lunes–domingo America/Caracas)
//                    |fecha_desde=YYYY-MM-DD&fecha_hasta=YYYY-MM-DD   (ambos requeridos si se usa)
//                    &taquilla_id=  (opcional para admin; fuera de alcance → 403; rol taquilla → propia)
{ "fecha_desde":"…", "fecha_hasta":"…",
  "ventana_cubierta": { "desde":"…fecha_fin min…", "hasta":"…fecha_fin max…", "cierres_incluidos":4 },
  "total_ventas_bs":…, "total_ventas_usd":…, "total_ventas_bs_equivalent":…,
  "total_egresos_bs":…, "total_egresos_usd":…, "total_efectivo_bs":…, "total_efectivo_usd":…,
  "arqueo_efectivo_bs":…, "arqueo_efectivo_usd":…, "faltante_sobrante_bs":…, "faltante_sobrante_usd":…,
  "desglose_metodos": { /* suma de JSON */ },
  "cierres": [ { "id":12, "taquilla_id":1, "fecha_inicio":"…", "fecha_fin":"…",
                 "total_ventas_bs":…, "total_efectivo_bs":… } ] }
// Semana sin diarios: totales en 0, arqueo/faltante null, ventana {null,null,0}, cierres []

// Captura de método (contrato para taquilla-venta-agil) — aditivo, retrocompatible
POST /api/v1/apuestas → "metodo_pago": "transferencia"        // opcional; default "efectivo"; amount_usd>0 ⇒ "efectivo"
POST /api/v1/tickets  → "metodo_pago": "pago_movil"           // nivel ticket: aplica a todos los ingreso generados
POST /api/v1/pagos    → "metodo_pago": "punto_venta"          // opcional; default "efectivo"; moneda "usd" o amount_usd>0 ⇒ "efectivo"
// valor fuera del enum ⇒ 422 { errors: { metodo_pago: [...] } }
```

`GET /api/v1/cierre` no cambia su contrato (alcance, `per_page` default 20, orden `fecha_fin` desc); los campos nuevos aparecen por serialización del modelo. El semanal ordena `cierres[]` por `fecha_fin` asc.

## Frontend Design — `taquilla/src/pages/cierre.astro`

| Sección / estado | Fuente | Comportamiento |
|---|---|---|
| Resumen del período actual | `GET /cierre/actual` al cargar y al refrescar | Período (`fecha_inicio`→`fecha_fin`), tasa, ventas/egresos/efectivo por moneda y mini-tabla de desglose por método |
| Arqueo + ejecutar | inputs `number step=0.01 min=0` (`arqueo_efectivo_bs/_usd`) | Diferencia en vivo (arqueo − efectivo del preview) con color faltante/sobrante; "Ejecutar cierre" → `showModal({type:'confirm'})` con resumen → `POST /cierre` |
| Errores | `ApiError.kind` de `apiFetch` | `network`/`timeout` → modal "Sin conexión…" (sin cola offline, out of scope); `http` 422 → `message` del backend (tasa); 403 → modal; `rate-limited` → reintentar luego |
| Resultado | 201 | Totales, arqueo, `faltante_sobrante_*` con badges (faltante rojo, sobrante verde, conciliado neutro), desglose; botones Imprimir y Refrescar |
| Historial | `GET /cierre?per_page=10&page=N` | Lista estilo `historial.astro` con paginación; fila expandible con desglose/arqueo/diferencia; Imprimir por cierre (`GET /cierre/{id}`) |
| Reporte semanal | `GET /cierre/semanal?fecha=` | Date input (default hoy) + "Consultar semana"; muestra `ventana_cubierta` y aviso si `cierres_incluidos < 7`; tabla por método y diarios incluidos |
| Impresión | `window.electron?.printCierre({ cierreData })` | IPC `print-cierre` (HTML 80mm estilo `generateTicketHtml`, montos `toLocaleString('es-VE')`, fechas `es-VE`); fallback `showModal` fuera de Electron |

Sin cambios en `MainLayout.astro` (nav ya existe). No se edita `dashboard.astro`.

## Testing Strategy

| Requerimiento | Test (archivo) | Notas |
|---|---|---|
| Cierre con arqueo conciliado / faltante / sobrante / sin arqueo | `CierreCajaTest`: `test_cierre_con_arqueo_persiste_contado_y_diferencia`, `test_faltante_es_negativo`, `test_sobrante_es_positivo`, `test_cierre_sin_arqueo_deja_campos_nulos` | Asserts sobre 201 y BD; decimal como string |
| Desglose por método y consistencia | `CierreCajaTest`: `test_desglose_por_metodo_suma_el_total_bs`, `test_usd_se_contabiliza_integro_en_efectivo`, `test_desglose_excluye_apuesta_anulada` | La anulada (soft delete) con `Pago` ingreso huérfano NO debe aparecer |
| Semanal completo / vacío / incompleto / alcance | `CierreCajaTest`: `test_semanal_rollup_semana_completa`, `test_semanal_semana_vacia_devuelve_ceros`, `test_semanal_semana_incompleta_expone_ventana_real`, `test_semanal_alcance_taquilla_y_403`, `test_semanal_respeta_fecha_desde_hasta` | `Carbon::setTestNow` (patrón existente); ventana en `America/Caracas` |
| Tasa: activa / fallback / sin ninguna | `CierreCajaTest`: `test_fallback_usa_ultima_tasa_historica`, `test_sin_tasa_alguna_responde_422` | **`test_cierre_sin_tasa_activa_responde_422` cambia de semántica**: desactivar la tasa ahora usa el fallback (201). Actualizar, no duplicar |
| Encadenamiento | Tests existentes de `CierreCajaTest` | El semanal no altera el encadenamiento (solo lectura) |
| Autorización | Tests existentes (rol taquilla / 403 / 422 admin) + semanal | Reutiliza `index` + `assertTaquillaEnAlcance` |
| No bloquea venta | `CierreCajaTest::test_venta_posterior_al_cierre_se_registra_normalmente` | `POST /apuestas` tras cierre → 201 |
| Historial con campos nuevos | `CierreCajaTest::test_index_incluye_arqueo_desglose_y_diferencia` | `assertJsonStructure` sobre `data[0]` |
| Captura ingreso / USD forzado / default / inválido | `MetodoPagoTest` (nuevo): venta VES `transferencia`, USD ⇒ `efectivo`, omitido ⇒ `efectivo`, `metodo_pago` inválido ⇒ 422 | Vía API (`POST /apuestas`, `POST /tickets`) |
| Captura egreso/devolucion / premio USD / inválido | `MetodoPagoTest`: premio VES `pago_movil`, USD ⇒ `efectivo`, inválido ⇒ 422, `mixto` un solo método | `POST /pagos` con `PagoTipoTest` como referencia de setup |
| Regla de normalización | `Unit/PagoMetodoPagoTest`: `resolverMetodoPago()` (default, USD⇒efectivo, método válido) | Sin DB; TDD rápido |
| Impresión | Manual/E2E en Electron | `taquilla/` no tiene runner de tests; verificación manual documentada |

## Threat Matrix

| Boundary | Applicability | Reason / expected behavior | Verification |
|---|---|---|---|
| Network routing | Applicable | Dos rutas GET nuevas (`/cierre/actual`, `/cierre/semanal`) declaradas antes de `/cierre/{cierre}` (AD-11) para que el route-model binding no capture `actual`/`semanal`; misma auth/alcance que `index`. Si se reordena: 404 por captura de `{cierre}` | Tests de feature de `semanal` (404 al romper el orden) |
| Shell commands | N/A | El cambio no invoca shell (la impresión va por IPC en proceso) | — |
| Subprocesses | N/A | Sin spawn de subprocesos | — |
| VCS/PR automation | N/A | Sin git/PR en el cambio | — |
| Executable-file classification | N/A | Sin clasificación de ejecutables | — |
| Process integration — Electron IPC `print-cierre` | Applicable | Renderer→main con `cierreData` JSON (solo números/texto del backend); `main` arma el HTML 80mm y escapa strings libres (`referencia`/`concepto`) antes de interpolar. Fallo (sin impresora POS o detección) → fallback a diálogo del sistema; fuera de Electron → `showModal` | E2E manual documentado (`taquilla/` sin runner de tests) |

## Migration / Rollout

1. **Orden de despliegue**: migración (aditiva, nullable) → backend (captura + cierre + semanal + preview) → taquilla (cierre.astro + IPC print) → colecciones. Los clientes viejos que omiten `metodo_pago` obtienen `efectivo` (retrocompatible); el frontend nuevo exige endpoints nuevos, por eso backend primero. Coordinar con `taquilla-venta-agil` el contrato AD-10 antes de implementar la captura en la venta.
2. **Rollback**: revertir `cierre.astro` al placeholder y quitar `print-cierre`; quitar rutas `/cierre/semanal` y `/cierre/actual` (aditivas, sin datos); `migrate:rollback` dropea columnas — única pérdida: arqueo/diferencia ya capturados (documentar). Rollback de imagen Docker como respaldo.
3. **No hay escritura destructiva**: el cierre solo agrega datos existentes; los campos nuevos son nullable.

## Open Questions

- [ ] Confirmar el preview `GET /api/v1/cierre/actual` (aditivo, read-only): es la única pieza no listada en el proposal y existe para habilitar el resumen y la diferencia en vivo del arqueo. Si se rechaza, la UI muestra el resumen solo después de cerrar.
- [ ] Ventana semanal ad-hoc: se define `fecha_desde` inclusivo (inicio de día) y `fecha_hasta` inclusivo (fin de día; interno `[desde, fecha_hasta + 1 día)`, AD-5); confirmar si el negocio espera fin exclusivo.
