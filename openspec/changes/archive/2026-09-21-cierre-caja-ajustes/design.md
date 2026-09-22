# Design: Ajustes al Cierre de Caja (período abierto, rangos, un cierre por día, clave)

## Technical Approach

Se extiende el cierre existente sin entidad nueva ni endpoint de dominio nuevo: el mismo `POST /cierre` bifurca crear (201, `reclosed:false`) o actualizar la fila del día (200, `reclosed:true`) dentro de la transacción ya existente de `CierreService::crearCierre()`; el re-cierre exige `clave_cierre` validada contra la cadena jerárquica de ESA taquilla. La clave se persiste hasheada en `users.clave_cierre` (bcrypt + `$hidden`) y se configura self-service vía `GET`/`PUT /api/v1/usuarios/clave-cierre` desde una página nueva del panel. El rango se mantiene en `GET /cierre/semanal` (Q4, sin alias): solo se amplía `cierres[]` con el shape completo para listar e imprimir por IPC. La taquilla gana la sección "Reportes por rangos" (dos calendarios + listado + imprimir) y el prompt de clave (`showModal` variante input). El copy del "Resumen del período actual" pasa a comunicar el período ABIERTO (último cierre → ahora) y, si hay `cierre_hoy`, avisa que un nuevo cierre lo actualizará.

Puntos verificados en código que condicionan el diseño: `verify.mac` solo exige MAC a rol `taquilla` (roles `super_master|master|banca` pasan); `UserController` filtra por columna `role` y su CRUD admin no toca campos fuera de `$request->only(...)`; `now()`/`Carbon::parse` ya operan en `America/Caracas` (`config/app.php:68`).

## Architecture Decisions

### Re-cierre del día

| # | Área | Opciones | Elección | Razón |
|---|------|----------|----------|-------|
| AD-1 | Punto de bifurcación crear/actualizar | (a) helper `resolveCierreHoy()` en `CierreService::crearCierre()`; (b) en `CierreController::store()` | (a) | Un solo punto de negocio; la transacción ya existe; testeable por HTTP sin duplicar lógica; el controller solo mapea status/flag (O1-A) |
| AD-2 | Detección del "cierre de hoy" | (a) rango explícito `[now()->startOfDay(), +1d)` sobre `fecha_fin`; (b) `whereDate('fecha_fin', today())` | (a) con `orderByDesc('fecha_fin')->orderByDesc('id')->first()` | `whereDate` compara el valor crudo UTC y desplaza el día en Caracas (UTC−4); el rango usa la zona de la app. Demo con varias filas hoy: se toma la última por `fecha_fin` (Q-abierta del proposal) |
| AD-3 | Mecánica del update | Dentro de la misma transacción: validar clave → recalcular desde `cierreHoy->fecha_inicio` original hasta `now()` → `update()` de la fila | Se extiende `fecha_fin` a `now()`, totales/desglose/faltante recalculados, `exchange_rate_cierre` con la tasa vigente, `reclosed_by`/`reclosed_at`; `fecha_inicio` y `created_by` INTACTOS | Idempotente por construcción (siempre 1 fila); recalcular desde el inicio original evita perder ventas entre el primer cierre y el re-cierre |
| AD-4 | Contrato de respuesta | (a) `crearCierre()` devuelve `array{cierre, reclosed}` y el controller decide `201`/`200` + flag; (b) siempre 200; (c) siempre 201 | (a) | El spec exige 201 crear / 200 re-cierre; el flag permite copy distinto en la UI; los tests existentes de primer cierre siguen en 201. Único caller: `CierreController::store()` |
| AD-5 | Arqueo en re-cierre | (a) misma semántica que crear: si se omite → `null` (y diferencia nula); (b) conservar el arqueo previo | (a) | Un cierre es una foto del momento; el cajero vuelve a contar. Evita mezclar arqueos de momentos distintos |

### Clave de cierre

| # | Área | Opciones | Elección | Razón |
|---|------|----------|----------|-------|
| AD-6 | Almacenamiento | (a) `users.clave_cierre` string nullable + `Hash::make`/`Hash::check`; (b) tabla `claves_cierre` | (a) bcrypt + `$hidden` += `clave_cierre` + `$fillable` += `clave_cierre` | Un hash por usuario; patrón `password` del repo; sin join; `$hidden` corta la serialización en TODA respuesta. `UserController` usa `$request->only(...)`, así que el CRUD admin no puede setear el hash; el único escritor es el endpoint self-service (O2-A) |
| AD-7 | Resolución de la cadena (Q6) | (a) `validarClaveCierre($taquillaId, $clave)` por cadena de esa taquilla; (b) validar contra la cadena del usuario que ejecuta | (a): candidatos = usuarios `role=banca` con `banca_id` = `taquilla->grupo->banca_id`; usuario `id` = `bancas.master_id` de esa banca; todos los `role=super_master`; filtrar `whereNotNull('clave_cierre')`; `Hash::check` OR | Regla única (Q6): el admin usa su clave solo si pertenece a esa cadena (el master ES candidato de su cadena). Guard clauses si no hay banca/master. Lanza `RuntimeException` → el patrón `catch → 422` ya existe |
| AD-8 | Sin candidato con clave (Q2) | (a) bloquear con 422; (b) permitir sin clave | (a) `RuntimeException('No hay una clave de cierre configurada para esta taquilla.')` | Decisión Q2: sin clave no hay re-cierre; mensaje claro para que la cadena configure su clave |
| AD-9 | Endpoint self-service | (a) `ClaveCierreController` dedicado en `/api/v1/usuarios/clave-cierre` (ruta del spec) + middleware `role:super_master\|master\|banca`; (b) métodos en `UserController`/`apiResource('users')` | (a) | Separa self-service del CRUD admin; `$request->user()` elimina IDOR; ruta aditiva con prefijo `usuarios` (sin colisión con `users`); roles inelegibles → 403 por middleware (O3-A) |
| AD-10 | Reglas de la clave (Q1, Q5) | PIN numérico `digits_between:4,8`; cambio exige `clave_actual` (`Hash::check` → 422 si no coincide); primera configuración directa | GET devuelve solo `{clave_configurada: bool}`; PUT responde `{clave_configurada: true}` | Q1 self-service seguro; Q5 formato PIN (longitud por defecto 6 sugerida en UI, rango 4–8 vigente hasta confirmación de negocio) |

### Reporte, impresión y UI

| # | Área | Opciones | Elección | Razón |
|---|------|----------|----------|-------|
| AD-11 | Shape de `cierre_hoy` en el preview | (a) `{id, fecha_inicio, fecha_fin}` \| null; (b) incluir totales | (a) aditivo | Mínimo para el copy del confirm; no duplica cálculos ni payload; el preview sigue read-only (Q del spec: "id y fechas") |
| AD-12 | Shape completo de `cierres[]` (Q3) | (a) incluir desglose/arqueo/faltante por cierre; (b) solo totales | (a): `id, taquilla_id, fecha_inicio, fecha_fin, total_ventas_bs, total_ventas_usd, total_ventas_bs_equivalent, total_egresos_bs, total_egresos_usd, total_efectivo_bs, total_efectivo_usd, arqueo_efectivo_bs, arqueo_efectivo_usd, faltante_sobrante_bs, faltante_sobrante_usd, desglose_metodos, exchange_rate_cierre` | Requerido para imprimir con desglose; `reclosed_*` queda fuera (auditoría ya disponible en `GET /cierre/{id}`) para acotar payload. Sanidad: ≈1 KB por cierre (24 números de desglose + 17 escalares); 31 cierres ≈ 31 KB, 366 ≈ 400 KB; el rollup no paginan y la UI consulta rangos operativos (día/semana) |
| AD-13 | Impresión del reporte | (a) `generateReporteHtml()` + IPC `print-reporte` + `printReporte` en preload, reutilizando `printHtml()`; (b) reutilizar `print-cierre` por cierre | (a) | Un solo canal nuevo imprime totales + desglose fusionado + listado; reutiliza detección POS y fallback a diálogo; el payload sale del estado en memoria (`ultimoReporte`), sin re-fetch (O5-A) |
| AD-14 | Variante input de `showModal` | (a) `showModal({message, type:'input', inputType, placeholder, minLength, maxLength, pattern})` que resuelve `string\|null`; (b) modal dedicado en `cierre.astro` | (a) en `MainLayout.astro` | Reusable; Enter confirma, Escape/overlay/botón cancelan → `null`; `type:'confirm'` sigue resolviendo `bool` y los tipos actuales no cambian (retrocompatible). La clave se pide con `inputType:'password'` + `inputmode:'numeric'` (O7-A) |
| AD-15 | Panel de la clave | (a) página nueva autónoma `panel/src/pages/clave-cierre.astro` + 1 línea `addLink` en `AdminLayout.astro` (tras "Usuarios", ~línea 287), roles `super_master\|master\|banca`; (b) modal en `usuarios.astro` | (a) | Archivo nuevo (cero fricción con el worktree del panel); la página importa `apiFetch` de `utils/api.ts` y `showModal` de `utils/modal.ts` SIN editarlos; URL directa de rol inelegible → 403 del API → estado de error (O6-A) |

## Data Flow

**1. Re-cierre del mismo día (crear vs actualizar)**

```
cierre.astro
  │ GET /cierre/actual ──────────────► previsualizar(): resolveCierreHoy() → cierre_hoy {id, fechas}|null
  │ POST /cierre {arqueo_*, clave_cierre?}
  ▼
CierreController::store() → resolveTaquillaParaCierre() (rol/jerarquía, 403 fuera de alcance)
  ▼
CierreService::crearCierre(taquilla, user, arqueoBs, arqueoUsd, clave)   [DB::transaction]
  ├─ resolverTasa()
  ├─ resolveCierreHoy(taquilla): fecha_fin ∈ [startOfDay, +1d)  (America/Caracas)
  │    ├─ null ──► CREATE: resolveFechaInicio() → calcularTotales(inicio, now)
  │    │              → CierreCaja::create(...)  ──► {reclosed:false} ──► 201
  │    └─ fila ──► validarClaveCierre(taquilla, clave)
  │                  ├─ clave ausente → RuntimeException ──────────────────► 422
  │                  ├─ sin candidatos con clave → RuntimeException ───────► 422
  │                  ├─ ninguna coincide → RuntimeException ───────────────► 422
  │                  └─ ok ──► calcularTotales(cierreHoy.fecha_inicio, now)
  │                              → update(fecha_fin, totales, desglose, arqueo,
  │                                       reclosed_by, reclosed_at)
  │                              → {reclosed:true} ────────────────────────► 200
  │                              (fecha_inicio y created_by intactos)
  └─ UI: 201 → "Cierre ejecutado"; 200 → "Cierre del día actualizado (re-cierre)"
```

**2. Validación de clave en cadena**

```
clave ──► validarClaveCierre(taquillaId, clave)
  taquilla.grupo.banca_id ──► candidato 1: User role=banca, banca_id = banca del grupo
  bancas.master_id        ──► candidato 2: User id = master de esa banca
  (global)                ──► candidato 3: User role=super_master
        │  (guard: sin banca/master → se omiten esas ramas)
        ▼
  whereNotNull('clave_cierre') ──► ¿vacío? ──► 422 "No hay una clave de cierre configurada..."
        ▼
  Hash::check(clave, candidato.clave_cierre) por cada uno
        ├─ alguna true ──► continúa el re-cierre
        └─ ninguna      ──► 422 "Clave de cierre incorrecta."
```

**3. Reporte por rango + impresión**

```
cierre.astro (reporte-desde / reporte-hasta)
  │ GET /cierre/semanal?fecha_desde&fecha_hasta
  ▼
CierreController::semanal(): alcance jerárquico + assertTaquillaEnAlcance (403)
  → resolveVentanaSemanal(): [desde, hasta + 1 día)
  → CierreService::reporteSemanal(): totales + desglose fusionado + ventana_cubierta + cierres[] (shape completo)
  ◄── response ──► renderReporteRango() → ultimoReporte (estado en memoria)
                      │ [🖨️ Imprimir reporte]
                      ▼
                   window.electron.printReporte({reporteData: ultimoReporte})
                      └─ IPC 'print-reporte' → generateReporteHtml() → printHtml() [POS | diálogo sistema]
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `backend/database/migrations/2026_09_21_000001_add_clave_cierre_and_reclose_audit.php` | Create | `users.clave_cierre`, `cierres_caja.reclosed_by`/`reclosed_at` + `down()` |
| `backend/app/Models/User.php` | Modify | `$fillable` + `$hidden` con `clave_cierre` |
| `backend/app/Models/CierreCaja.php` | Modify | `$fillable` + cast `reclosed_at` |
| `backend/app/Services/CierreService.php` | Modify | `resolveCierreHoy()`, `validarClaveCierre()`, bifurcación en `crearCierre()`, `previsualizar()` + `cierre_hoy`, `reporteSemanal()` amplía `cierres[]` |
| `backend/app/Http/Controllers/Api/CierreController.php` | Modify | `store()` acepta `clave_cierre`, mapea 201/200 + `reclosed` |
| `backend/app/Http/Controllers/Api/ClaveCierreController.php` | Create | `show()`/`update()` self-service sobre `$request->user()` |
| `backend/routes/api.php` | Modify | Grupo `role:super_master\|master\|banca` con `GET`/`PUT /usuarios/clave-cierre` |
| `backend/tests/Feature/CierreCajaTest.php` | Modify | Re-cierre, clave, `cierre_hoy`, shape del reporte |
| `backend/tests/Feature/ClaveCierreTest.php` | Create | Hash, hidden, self-service, cadena, 403 |
| `taquilla/src/layouts/MainLayout.astro` | Modify | `showModal` variante `type:'input'` |
| `taquilla/src/pages/cierre.astro` | Modify | Período abierto, "Reportes por rangos", prompt de clave, imprimir reporte |
| `taquilla/electron/main/ipcHandlers.cjs` | Modify | `generateReporteHtml()` + handler `print-reporte` |
| `taquilla/electron/preload/preload.cjs` | Modify | `printReporte(data)` |
| `panel/src/pages/clave-cierre.astro` | Create | Página autónoma GET/PUT con estados |
| `panel/src/layouts/AdminLayout.astro` | Modify | 1 línea `addLink('Clave de Cierre', '/clave-cierre', '🔑')` |
| `collections/Cierre de Caja/*.yml` | Modify | `clave_cierre` + re-cierre; renombrar semanal → rango; nueva configurar clave |
| `panel/src/utils/api.ts` | **Untouched** | Se importa tal cual |

## Interfaces / Contracts

**`POST /api/v1/cierre`** — request (+`clave_cierre` opcional) y respuestas:

```json
// request
{ "taquilla_id": 1, "arqueo_efectivo_bs": 1450.00, "arqueo_efectivo_usd": 30.00, "clave_cierre": "123456" }
// 201 Created (primer cierre del día — sin clave)
{ "id": 12, "taquilla_id": 1, "fecha_inicio": "2026-09-21T08:00:00-04:00", "fecha_fin": "2026-09-21T12:00:00-04:00",
  "total_ventas_bs": 1500.00, "total_efectivo_bs": 1460.00, "desglose_metodos": { "bs": { "efectivo": { "ventas": 1000, "egresos": 30, "efectivo": 970 }, "...": {} }, "usd": {} },
  "reclosed": false }
// 200 OK (re-cierre — misma fila, fecha_inicio intacta)
{ "id": 12, "fecha_inicio": "2026-09-21T08:00:00-04:00", "fecha_fin": "2026-09-21T15:30:00-04:00",
  "reclosed": true, "reclosed_by": 3, "reclosed_at": "2026-09-21T15:30:00-04:00" }
// 422: sin clave / clave incorrecta / sin candidatos / formato inválido / sin tasa
{ "message": "La clave de cierre es obligatoria para re-cerrar el día." }
```

Reglas: `clave_cierre: nullable|digits_between:4,8`; solo se valida si existe cierre hoy; 403 por alcance sin cambios.

**`GET`/`PUT /api/v1/usuarios/clave-cierre`** (roles `super_master|master|banca`; otros → 403):

```json
// GET → 200
{ "clave_configurada": true }
// PUT (primera vez) → 200
{ "clave_nueva": "123456", "clave_nueva_confirma": "123456" }
// PUT (cambio, Q1) → 200
{ "clave_actual": "123456", "clave_nueva": "654321" }
// respuesta PUT → 200
{ "clave_configurada": true }
// 422 (formato / clave actual incorrecta / falta clave_actual con clave previa)
{ "message": "La clave actual no coincide." }
```

Reglas: `clave_nueva: required|digits_between:4,8`; `clave_actual: nullable|digits_between:4,8`; si `clave_configurada` → `clave_actual` obligatoria y verificada con `Hash::check` antes de persistir; nunca se devuelve ni se guarda en claro.

**`GET /api/v1/cierre/actual`** — campo aditivo (resto sin cambios):

```json
{ "taquilla_id": 1, "fecha_inicio": "2026-09-21T08:00:00-04:00", "fecha_fin": "2026-09-21T12:00:00-04:00",
  "exchange_rate": 36.5, "total_ventas_bs": 1500.00, "desglose_metodos": { "...": {} },
  "cierre_hoy": { "id": 12, "fecha_inicio": "2026-09-21T08:00:00-04:00", "fecha_fin": "2026-09-21T11:00:00-04:00" } }
```

**`GET /api/v1/cierre/semanal?fecha_desde=...&fecha_hasta=...`** — `cierres[]` (Q3):

```json
{ "fecha_desde": "2026-09-15", "fecha_hasta": "2026-09-21",
  "total_ventas_bs": 5000.00, "ventana_cubierta": { "desde": "...", "hasta": "...", "cierres_incluidos": 5 },
  "cierres": [ { "id": 12, "taquilla_id": 1, "fecha_inicio": "...", "fecha_fin": "...",
    "total_ventas_bs": "1500.00", "total_ventas_usd": "0.00", "total_ventas_bs_equivalent": "1500.00",
    "total_egresos_bs": "40.00", "total_egresos_usd": "0.00", "total_efectivo_bs": "1460.00", "total_efectivo_usd": "0.00",
    "arqueo_efectivo_bs": "1450.00", "arqueo_efectivo_usd": null, "faltante_sobrante_bs": "-10.00", "faltante_sobrante_usd": null,
    "desglose_metodos": { "bs": { "efectivo": { "ventas": 1000, "egresos": 30, "efectivo": 970 }, "...": {} }, "usd": {} },
    "exchange_rate_cierre": "36.5000" } ] }
```

## UI Design

| Superficie | Cambio |
|---|---|
| `cierre.astro` — Resumen | Encabezado `Período ABIERTO: <fecha_inicio> → ahora (<fecha_fin>)` + badge "ABIERTO"; si `preview.cierre_hoy` → aviso "Cierre del día <fecha_fin> ya registrado: un nuevo cierre lo actualizará (requiere clave)" |
| `cierre.astro` — Reportes por rangos | Card renombrada; inputs `reporte-desde`/`reporte-hasta` (default hoy, `todayCaracas()`), botón "Consultar"; valida `desde ≤ hasta` antes de llamar; render: rango + cierres incluidos + totales + desglose fusionado + listado de cierres con desglose expandible (ids prefijados `data-rango-*` para no colisionar con el historial); botón "🖨️ Imprimir reporte" (habilitado al cargar) |
| `cierre.astro` — Ejecutar | `body.clave_cierre` solo si `preview.cierre_hoy`; `showModal({type:'input', inputType:'password', ...})` → `null` cancela; copy del confirm distingue crear/re-cierre; resultado muestra badge `reclosed` |
| `MainLayout.astro` | `showModal` variante `type:'input'`: input + botones Aceptar/Cancelar, Enter confirma, Escape/overlay cancela → `string|null`; tipos `info/success/error/confirm` intactos |
| `ipcHandlers.cjs` / `preload.cjs` | `generateReporteHtml(reporteData)` (escape `escapeHtml` de nombres libres) + `ipcMain.handle('print-reporte')` + `printReporte` |
| `panel/clave-cierre.astro` | Estado (`GET`): "Clave configurada" / "Sin clave configurada"; form con `clave_actual` (solo si configurada) + `clave_nueva` + confirmación (`type=password`, `inputmode=numeric`, 4–8 dígitos); `PUT`; éxito → `showModal` + recarga estado; 403/422 → `showModal` error |

## Testing Strategy

TDD estricto: cada work unit escribe sus tests RED antes del código. Reutiliza helpers de `CierreCajaTest` (`superUser`, `masterUser`, `bancaUser`, `crearTaquilla`, `crearCierre`, `Carbon::setTestNow`); `actingAs($user, 'sanctum')` + `getJson/postJson`.

| Requisito | Test RED | Asserts clave |
|---|---|---|
| Primer cierre del día crea | `test_primer_cierre_del_dia_crea_con_reclosed_false` | 201, `reclosed=false`, 1 fila |
| Segundo cierre actualiza | `test_segundo_cierre_del_dia_actualiza_la_fila` | 200, `reclosed=true`, mismo `id`, 1 fila, `fecha_fin` extendido, totales recalculados desde `fecha_inicio` original (apuesta posterior al primer cierre incluida), `fecha_inicio`/`created_by` intactos |
| Idempotencia | `test_re_cierre_es_idempotente` | Repetir sigue con 1 fila y `fecha_fin`/`reclosed_at` re-extendidos |
| Auditoría | `test_re_cierre_audita_reclosed_by_y_reclosed_at` | `reclosed_by` = ejecutor, `reclosed_at` = `now()` |
| Límite de día | `test_cierre_en_dia_siguiente_crea_fila_nueva` | Cierre ayer 23:50 Caracas, now hoy 00:10 → 201, 2 filas |
| Re-cierre sin clave | `test_re_cierre_sin_clave_responde_422` | 422 y fila sin cambios |
| Clave incorrecta | `test_re_cierre_con_clave_incorrecta_responde_422` | 422 (candidatos con clave) |
| Sin candidatos con clave (Q2) | `test_re_cierre_sin_candidatos_con_clave_responde_422` | 422 + mensaje de bloqueo |
| Clave de banca/master/super_master | `test_re_cierre_con_clave_de_banca_master_y_super_master` | Tres escenarios → 200 cada uno |
| Clave de otra banca | `test_re_cierre_rechaza_clave_de_otra_banca` | 422 |
| Demo multi-fila del día | `test_cierre_de_hoy_toma_el_ultimo_por_fecha_fin` | Update conserva el `fecha_inicio` de la última |
| `cierre_hoy` sin cierre | `test_actual_expone_cierre_hoy_null_sin_cierre` | `cierre_hoy` null |
| `cierre_hoy` con cierre | `test_actual_expone_cierre_hoy_con_cierre` | `id`/`fecha_inicio`/`fecha_fin` |
| `cierres[]` shape completo | `test_semanal_cierres_incluye_shape_completo` | Desglose, arqueo y faltante por cierre; totales y `ventana_cubierta` sin cambios (regresión) |
| Clave: primera config | `test_put_primera_configuracion_guarda_hash` | Columna ≠ texto plano, `Hash::check` true |
| Clave: cambio con actual | `test_put_cambio_exige_clave_actual` | Actual errónea → 422 sin cambios; correcta → nueva vigente |
| Clave: formato | `test_put_formato_invalido_responde_422` | `"123"`, `"abcdef"` → 422 |
| Clave: estado | `test_get_estado_booleano` | false/true y payload sin `clave_cierre` |
| Clave: hidden | `test_hash_no_serializado` | `assertJsonMissingPath('clave_cierre')` en `GET /user` y `GET /users` |
| Clave: 403 por rol | `test_endpoint_restringe_roles_no_elegibles` | `taquilla`/`grupo`/`agencia` → 403 en GET y PUT |
| Clave: self-service | `test_self_service_solo_afecta_al_autenticado` | PUT no altera la clave de otro usuario |

Sin runner en `taquilla/` ni `panel/`: IPC de impresión, `showModal` input y página del panel se verifican con `npm run build` + E2E manual documentado (`generateReporteHtml` exportado para inspección ad-hoc).

## Threat Matrix

| Boundary | Applicability | Reason / expected behavior | Verification |
|---|---|---|---|
| Network routing | Applicable | `GET`/`PUT /api/v1/usuarios/clave-cierre` nuevas dentro de `auth:sanctum` + `verify.mac` + `role:super_master\|master\|banca`; prefijo `usuarios` sin colisión con `apiResource('users')`. `verify.mac` no exige MAC a roles no-taquilla (comportamiento verificado). Si se quita el middleware de rol: 403 esperados fallan → test RED lo protege | `ClaveCierreTest` (403 por rol, 200 elegibles) |
| Shell commands | N/A | El cambio no invoca shell | — |
| Subprocesses | N/A | Sin spawn de subprocesos | — |
| VCS/PR automation | N/A | Sin git/PR en el cambio | — |
| Executable-file classification | N/A | Sin clasificación de ejecutables | — |
| Process integration — Electron IPC `print-reporte` | Applicable | Renderer→main con `reporteData` JSON (números/fechas + nombres de taquilla); `main` valida objeto no-array, escapa strings libres (`escapeHtml`) y reutiliza `printHtml` (POS → fallback diálogo). Fuera de Electron → `showModal`; sin impresora → `{success:false}` | E2E manual + `npm run build` (sin runner en `taquilla/`) |

## Migration / Rollout

**Migración** `2026_09_21_000001_add_clave_cierre_and_reclose_audit.php` (misma rama; SQLite para tests, MySQL en prod):

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('clave_cierre')->nullable()->after('password');
    });
    Schema::table('cierres_caja', function (Blueprint $table) {
        $table->foreignId('reclosed_by')->nullable()->after('created_by')
            ->constrained('users')->nullOnDelete();
        $table->timestamp('reclosed_at')->nullable()->after('reclosed_by');
    });
}

public function down(): void
{
    Schema::table('cierres_caja', function (Blueprint $table) {
        $table->dropForeign(['reclosed_by']);   // MySQL: drop FK; SQLite: rebuild (Laravel 13)
    });
    Schema::table('cierres_caja', function (Blueprint $table) {
        $table->dropColumn(['reclosed_by', 'reclosed_at']);
    });
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('clave_cierre');
    });
}
```

Notas: `nullOnDelete` preserva el historial si se borra el usuario auditor (no cascade); ambas columnas son nullable → el código viejo sigue funcionando tras migrar. `RefreshDatabase` usa `migrate:fresh` (no ejecuta `down()`); el `down()` se valida en el rollback de staging.

**Work units (commits, TDD: tests en el mismo commit que su código)**

| # | Commit | Contenido |
|---|---|---|
| WU1 | `feat(backend): migra clave_cierre y auditoria de re-cierre` | Migración + `User`/`CierreCaja` (fillable/hidden/casts) |
| WU2 | `feat(backend): endpoints self-service de clave de cierre` | `ClaveCierreController` + rutas + `ClaveCierreTest` (RED→GREEN) |
| WU3 | `feat(backend): re-cierre diario idempotente con clave` | `resolveCierreHoy`/`validarClaveCierre`/bifurcación + `store` + `cierre_hoy` + tests de `CierreCajaTest` |
| WU4 | `feat(backend): shape completo de cierres en reporte por rango` | `reporteSemanal()` + tests de regresión/expansión |
| WU5 | `feat(taquilla): reportes por rangos, periodo abierto y prompt de clave` | `cierre.astro` + `MainLayout` input + `generateReporteHtml`/IPC/preload |
| WU6 | `feat(panel): pagina de configuracion de clave de cierre` | `clave-cierre.astro` + 1 línea de nav |
| WU7 | `docs(collections): clave de cierre y reporte por rango` | YAML de colecciones |

Orden de despliegue: migración (aditiva nullable, compatible hacia atrás) → backend → taquilla → panel → colecciones. No hay feature flags ni datos demo a corregir.

**Rollback**: `php artisan migrate:rollback` revierte WU1 (`down()` arriba); WU2 (rutas aditivas) se revierte quitando el grupo de rutas; WU3 basta con quitar la rama de actualización (vuelve a crear fila) o revertir el bloque; WU5/WU6 revierten los archivos de UI y el canal IPC; colecciones son documentación. Sin operaciones destructivas: solo columnas nuevas nullable y updates de una fila existente.

## Open Questions

- [ ] Q5: longitud/confirmación del PIN (rango 4–8 implementado, default 6 sugerido en UI). Si negocio lo fija, cambia solo la regla `digits_between` en 2 puntos y el `maxlength` de las UIs.
- [ ] Rangos muy largos en `GET /cierre/semanal`: sin paginación (rollup completo); fuera de alcance optimizar. Si negocio pide reportes anuales frecuentes, evaluar límite/paginación.
