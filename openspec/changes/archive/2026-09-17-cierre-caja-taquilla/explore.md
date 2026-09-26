# Exploración — Cierre de Caja (diario + semanal) para la taquilla

Cambio: `cierre-caja-taquilla` — implementar el cierre de caja (diario y semanal) para la app de taquilla.
Cliente: sistema multi-moneda USD / VES (Bs); pagos USD generalmente en EFECTIVO; pagos VES pueden ser efectivo, transferencia, pago móvil o punto de venta.

## Current State (backend)

### 1. Modelo `CierreCaja` y tabla `cierres_caja`

Migración `2026_07_10_035540_create_cierres_caja_table.php` + `backend/app/Models/CierreCaja.php`:

| Campo | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `taquilla_id` | FK taquillas (cascade) | El cierre es POR MÁQUINA (taquilla), no por agencia/banca |
| `fecha_inicio` | timestamp | Inicio del período |
| `fecha_fin` | timestamp nullable | Fin del período (= now() al cerrar) |
| `total_ventas_bs` | decimal(12,2) | Suma `apuestas.amount_bs` (estado != 'anulada') |
| `total_ventas_usd` | decimal(12,2) | Suma `apuestas.amount_usd` |
| `total_ventas_bs_equivalent` | decimal(12,2) | Suma `apuestas.total_bs_equivalent` |
| `total_egresos_bs` / `total_egresos_usd` | decimal(12,2) | Suma `pagos` tipo `egreso` + `devolucion` |
| `total_efectivo_bs` / `total_efectivo_usd` | decimal(12,2) | = ventas − egresos (por moneda) |
| `exchange_rate_cierre` | decimal(10,4) | Tasa activa al momento del cierre (snapshot) |
| `created_by` | FK users (cascade) | Quién ejecutó el cierre |
| timestamps | | |

**No existe** ningún campo de tipo de cierre (diario/semanal), ni desglose por método de pago, ni arqueo físico.

### 2. `CierreService` (`backend/app/Services/CierreService.php`)

- `crearCierre(int $taquillaId, int $userId): CierreCaja` — dentro de `DB::transaction`:
  1. Toma `ExchangeRate::where('is_active', true)->first()`; si no hay tasa activa lanza `RuntimeException` → el controller responde **422** ("No hay tasa de cambio activa para realizar el cierre.").
  2. Período `[fecha_inicio, fecha_fin)` con `fecha_fin = now()` y `fecha_inicio = resolveFechaInicio()`: `fecha_fin` del último cierre de la taquilla → si no, `fecha_hora` de su primera apuesta → si no, `now()`.
  3. Ventas: `Apuesta` por `fecha_hora` en el rango, `estado != 'anulada'`, `SUM(amount_bs)`, `SUM(amount_usd)`, `SUM(total_bs_equivalent)`.
  4. Egresos: `Pago` por `created_at` en el rango, `tipo IN ('egreso','devolucion')`, `SUM(amount_bs)`, `SUM(amount_usd)`. Los `ingreso` (cobro de apuestas) NO cuentan como egreso.
  5. `total_efectivo_X = total_ventas_X − total_egresos_X`.
  6. Persiste `CierreCaja` con `exchange_rate_cierre = tasaActiva->rate`.
- `listarCierres($query, $perPage)` — paginado con `taquilla.grupo.banca` y `creador`, orden por `fecha_fin` desc.
- `resolveFechaInicio()` — privado (último cierre → primera apuesta → now).
- **Solo existe cierre por período variable (equivalente a "diario" en la práctica si se cierra cada día). NO hay lógica semanal en ningún lado** (historial: 3 commits; `git log` confirma solo creación del servicio, Pint y docblocks). El reporte semanal hoy sería simplemente consultar varios cierres.
- Sin tests propios directos del servicio (cubierto vía `CierreCajaTest` y `CuadreCajaReportTest::test_post_cierre_sigue_intacto`).

### 3. `CierreController` (`backend/app/Http/Controllers/Api/CierreController.php`) y rutas

Rutas en `backend/routes/api.php` (grupo `auth:sanctum` + `role:super_master|master|banca|grupo|taquilla|agencia`):

- `POST /api/v1/cierre` → `store` — ejecuta el cierre. `resolveTaquillaParaCierre()`: rol `taquilla` cierra SOLO su propia taquilla (`taquilla_id` del user; si envía otro → 403); `super_master` cualquier taquilla; `master` solo taquillas de sus bancas (`masterCanAccessBanca`); `banca`/`grupo`/`agencia` dentro de su alcance. Roles administrativos deben mandar `taquilla_id` (validación 422 si falta).
- `GET /api/v1/cierre` → `index` — alcance jerárquico por rol (super_master todo; master vía `masterBancaChainScope`; banca/grupo/agencia/taquilla acotados), paginado (default 20).
- `GET /api/v1/cierre/{cierre}` → `show` — `authorizeCierreAccess()` por jerarquía.

Autorización por **rol** (Spatie `role:` middleware), sin permission específico de cierre (a diferencia de tasas que usan `view_exchange_rates` / `manage_exchange_rates`).

### 4. `Pago` y registro de pagos (dominio actual)

Migraciones: `2026_07_10_035532_create_pagos_table`, `2026_07_25_231500_add_moneda_to_pagos_table`, `2026_08_12_000003_alter_pagos_tipo_add_devolucion` (ALTER de ENUM solo MySQL; SQLite trata enum como varchar).

Campos relevantes: `taquilla_id`, `apuesta_id` (nullable), `amount_bs`, `amount_usd`, `exchange_rate_applied`, `tipo` enum **`ingreso|egreso|devolucion`**, `moneda` enum **`bs|usd|mixto`**, `concepto`, `referencia` (string libre), `created_by`.

Dónde se crean los `Pago`:
- **`ingreso` (cobro de apuesta)**: `ApuestaService::crearApuesta` (línea ~465) al registrar cada apuesta; `moneda` derivada (`bs|usd|mixto`), concepto "Compra de ticket". Se crea 1 pago por apuesta, no por ticket.
- **`egreso` / `devolucion` (pago de premio)**: `PagoController::store` — valida contra el premio calculado por el plugin del juego (`calcularPremio`), marca la apuesta `pagada`, cascada al ticket, log de auditoría.

**GAP CRÍTICO — no existe método de pago**: hoy NO se guarda si un pago VES fue efectivo, transferencia, pago móvil o punto de venta. `referencia` es texto libre (puede contener el número de transferencia) pero no hay campo estructurado. Sin este dato, un cierre con desglose por método de pago es **imposible** sin cambio de esquema.

### 5. Tasa de cambio (`ExchangeRate`)

- `backend/app/Models/ExchangeRate.php` — `rate`, `base_currency` (siempre 'USD': es la tasa USD→VES), `reference_date`, `set_by` (nullable), `notes`, `is_active`. Una única tasa activa a la vez.
- Fuentes:
  1. **Scraper BCV**: `ScrapeExchangeRateJob` (Guzzle + DomCrawler sobre `https://www.bcv.org.ve/`, selector `#dolar`, fallback `.centrado strong`), desactiva la anterior y crea la nueva activa. Schedule: `routes/console.php` → `everySixHours()`. También disparable por API: `POST /api/v1/exchange-rates/scrape` (permiso `manage_exchange_rates`).
  2. **Manual**: `ExchangeRateController::store/update/setActive` (permiso `manage_exchange_rates`).
- Pública: `GET /api/v1/exchange-rate/active` (sin auth; usada por el header de la taquilla).
- `exchange_rate_applied` se snapshotea por apuesta y por pago; `exchange_rate_cierre` snapshot al cerrar.

### 6. Reporte existente: cuadre de caja (`GET /api/v1/reportes/cuadre-caja`)

`ReporteController::cuadreCaja` + `ApuestaService::cuadreCaja` — reporte READ-ONLY agrupado por entidad (nivel: `banca` default, `grupo`, `agencia`=local, `taquilla`=máquina) con columnas `Venta, Pagados, Devoluciones, Vencidos, Efectivo, PesoVenta, Participación`, filtros `fecha_desde/hasta`, `tipo_juego`, `moneda` (bs/usd/mixto). Fórmula de `Efectivo`: `Venta − Pagados − Devoluciones − Vencidos` (en BS-equivalente o por moneda según filtro). **Diferencias clave vs `CierreService`**: (a) es reporte, no operación; (b) agrega por ENTIDAD (no por taquilla individual con rango continuo); (c) resta `Vencidos`; (d) el "Efectivo" no distingue moneda salvo filtro. UI: `panel/src/pages/cuadre.astro` (panel admin). Es la referencia conceptual más cercana a un "cierre semanal" como reporte.

### 7. Jerarquía de roles (contexto)

Cadena: `super_master → master → banca → grupo → agencia (local) → taquilla (máquina)`. `User` tiene `masterBancaChainScope()` etc. El alcance del cierre ya respeta esta cadena (sección 3).

## Current State (taquilla app — Astro + Electron)

- `taquilla/src/pages/cierre.astro` es un **placeholder** ("Módulo de cierre de caja próximamente"), pero el nav ya existe: `MainLayout.astro` tiene el enlace 💰 "Cierre de Caja" con `currentPage === 'cierre'`.
- Patrón de UI a replicar (todas las páginas): Astro page con `<MainLayout currentPage="...">` + `<script>` inline con `apiFetch` + CSS global por página. Componentes en `src/components/` (`apuesta/`, `ui/`). `historial.astro` muestra: cards/tablas, badges de estado, filtros, paginación, botones de acción con confirmación, `window.showModal` (confirm/success/error, definido en MainLayout). Formato de montos: `toLocaleString('es-VE', { minimumFractionDigits: 2 })`.
- **API client**: `src/config/api.ts` → `API_BASE = 'api:///api/v1'`; `src/utils/apiFetch.ts` — wrapper único: Bearer token desde `localStorage('auth_token')`, headers de dispositivo (`X-Device-Fingerprint`, `X-Device-MAC` vía `getApiMac`), timeout 10s, clasifica errores (`network|timeout|unauthorized|forbidden|rate-limited|http`), 401 limpia sesión y redirige a `/login`.
- **Auth**: login (`/login`) obtiene token Sanctum y lo guarda en localStorage. MainLayout muestra tasa activa (refresh cada 60s) y estado de conexión (online/offline listeners).
- **Electron**: proxy `api:///api/v1` → upstream (`taquilla/electron/main/upstream.cjs`): empaquetado → `https://lotto.gzuz.dev`; dev → whitelist (`localhost:8000` / prod). Impresora: `electron-pos-printer`.
- **NO hay cola offline**: `apiFetch` lanza error de red y el UI muestra "Desconectado"; no existe queue/IndexedDB de operaciones pendientes. Un cierre requiere conectividad en el momento (relevante para decidir UX de reintento).
- `dashboard.astro` (flujo de apuestas, ~1400 líneas) es donde se cobra la venta y donde se crearían los pagos `ingreso` — **otro agente lo está modificando en paralelo** (cambio `taquilla-venta-agil`); cualquier captura de método de pago en la venta requiere coordinación.

## Gap Analysis vs Necesidades del Cliente

| Necesidad del cliente | Estado hoy | Brecha |
|---|---|---|
| Cierre DIARIO | Existe `POST /cierre` (período desde el último cierre) | Funcional; falta UI en taquilla y confirmar semántica "día calendario" vs "período continuo" |
| Cierre SEMANAL | **NO existe** | No hay `tipo` en `cierres_caja`, ni agregación semanal (rollup de diarios ni reporte aparte) |
| Multi-moneda USD/VES | Parcial | Totales por moneda + BS-equivalente existen; falta definir presentación del desglose y tasa a usar |
| USD = efectivo | Implícito | No se modela método; si el negocio lo asume, USD podría fijarse `metodo=efectivo` automáticamente |
| VES: efectivo/transferencia/pago móvil/punto | **NO capturado** | Falta `metodo_pago` en `pagos` (+ captura en venta `ingreso` y en pago de premio `egreso/devolucion`); sin esto no hay desglose por método |
| Tasa de cambio al cierre | Snapshot `exchange_rate_cierre` | Existe; preguntas: tasa del día del cierre vs tasas por transacción; qué pasa si no hay tasa activa (hoy 422) |
| Quién ejecuta el cierre | Roles jerárquicos OK | Falta decidir: ¿permission específico? ¿cierre semanal solo admin? ¿confirmación de agencia? |
| Qué hace operativamente el cierre | Solo registra/reporta (no bloquea) | ¿Bloquea la taquilla (no más apuestas)? ¿Es solo reporte? ¿Reinicia contadores? |
| Desglose por método de pago en el cierre | NO existe | Depende 100% de capturar `metodo_pago` en origen (brecha de datos) |

## Approaches (opciones)

### Backend — cierre semanal

1. **Cierre semanal como REPORTE derivado de los diarios (sin persistencia)** — `GET /api/v1/cierre/semanal?taquilla_id=&fecha=` suma los `CierreCaja` de la semana (o de `fecha_desde/hasta`), devuelve totales agregados.
   - Pros: cero migración; reutiliza diarios ya persistidos; simple.
   - Contras: no captura el "momento" del cierre semanal (tasa del día, quién lo ejecutó); frágil si los diarios no se hicieron (ventana incompleta); no hay auditoría de "se cerró la semana".
   - Esfuerzo: Bajo.

2. **Persistir cierres semanales con `tipo` en `cierres_caja`** — migración agrega `tipo` enum(`diario`,`semanal`) (default `diario`); `CierreService` calcula sobre el rango [inicio semana, now) igual que el diario pero marcado semanal; el siguiente cierre diario continúa desde el `fecha_fin` del semanal.
   - Pros: auditoría completa (quién/cuándo/tasa); consistente con el modelo actual (misma tabla, mismo algoritmo); el semanal puede coexistir con diarios sin solaparse (encadena por `fecha_fin`).
   - Contras: migración con default en MySQL y SQLite; definir ventana (¿lunes a domingo en `America/Caracas`? ¿7 días rodantes?); riesgo de solape si alguien cierra diario y semanal el mismo día.
   - Esfuerzo: Medio.

3. **Cierre semanal a nivel agencia/banca (agregación multi-taquilla)** — nueva entidad/tabla o `CierreCaja` con `taquilla_id` nullable + nivel.
   - Pros: responde a "reporte de la semana del negocio", no de la máquina.
   - Contras: rompe el modelo actual (FK `taquilla_id` NOT NULL, cascade); duplica lógica del cuadre por entidad (`cuadreCaja` ya agrega por nivel); scope grande.
   - Esfuerzo: Alto. (Se recomienda NO; el cuadre por entidad ya cubre la vista agregada.)

### Backend — desglose por método de pago

4. **Agregar `metodo_pago` a `pagos`** — enum(`efectivo`,`transferencia`,`pago_movil`,`punto_venta`) nullable (default `efectivo`); captura en `PagoController::store` (egreso/devolución) y en `ApuestaService::crearApuesta` (ingreso, en el cobro de la venta); cierre con columnas/JSON por método (p. ej. columnas `total_ventas_bs_efectivo`, `..._transferencia`, etc., o columna JSON `desglose_metodos`).
   - Pros: habilita el desglose que pide el cliente; dato capturado en origen (auditable).
   - Contras: toca el flujo de VENTA (dashboard.astro, agente paralelo `taquilla-venta-agil`) → coordinación obligatoria; migración ENUM en MySQL (patrón ya usado en `alter_pagos_tipo_add_devolucion`); decide si `mixto` admite método por moneda (¿un pago mixto = método para BS y otro para USD?).
   - Esfuerzo: Medio-Alto.

5. **No capturar método; el cierre solo desglosa por moneda (estado actual)** — documentar que el desglose por método requiere decisión del cliente.
   - Pros: cero cambios de esquema; rápido.
   - Contras: NO cumple la necesidad explícita del cliente.
   - Esfuerzo: Bajo.

### Frontend

6. **Taquilla: reemplazar placeholder `cierre.astro`** — vista con: resumen del período actual (totales por moneda + equivalente + tasa), botón "Ejecutar cierre diario" (+ "Ejecutar cierre semanal" si aplica) con `window.showModal` de confirmación, pantalla de resultado (totales del cierre creado), historial de cierres (`GET /cierre`, paginado estilo `historial.astro`), e impresión del cierre con `electron-pos-printer`.
   - Pros: cumple el requerimiento visible del cliente; patrones existentes.
   - Contras: la captura de método de pago en la venta (si se adopta opción 4) vive en `dashboard.astro` (fuera de este cambio o coordinado).
   - Esfuerzo: Medio.

7. **Panel: página de cierres/cierre semanal para roles admin** — listado `GET /cierre` con alcance jerárquico + acción "cerrar taquilla X" (los roles admin ya pueden vía API) + reporte semanal.
   - Pros: cierre operativo desde la oficina (banca/grupo/agencia).
   - Contras: expande el alcance; el panel es dominio de otro agente en otros cambios; puede diferirse.
   - Esfuerzo: Medio (opcional/diferible).

## Open Questions (negocio — para el cliente)

1. **Semántica del cierre semanal**: ¿rollup de los 7 cierres diarios (reporte) o un cierre semanal PERSISTIDO propio con totales y tasa del día? ¿A nivel máquina (taquilla) o agregado por local/agencia/banca?
2. **Ventana semanal**: ¿semana calendario (lunes–domingo, zona `America/Caracas`) o 7 días rodantes desde el último cierre semanal?
3. **Desglose por método de pago**: ¿es REQUISITO en el cierre? Si sí: ¿se captura el método en la VENTA (cobro de la apuesta) y en el PAGO DE PREMIO? ¿Qué pasa con los pagos mixtos (método distinto por moneda)? ¿El USD se asume siempre efectivo?
4. **Tasa de cambio**: ¿el cierre usa la tasa activa del momento (actual) o la tasa de cada transacción? En un cierre semanal con tasa cambiante, ¿qué tasa se reporta? ¿Qué debe pasar si no hay tasa activa (hoy el cierre falla con 422)?
5. **Rol que ejecuta**: ¿cualquier cajero de la taquilla puede cerrar, o solo usuarios autorizados (permission específico)? ¿El cierre semanal es solo admin (agencia/banca)? ¿Requiere confirmación de un supervisor?
6. **Efecto operativo del cierre**: ¿bloquea la taquilla (no acepta más apuestas hasta reabrir) o es SOLO reporte/registro? ¿El cierre diario "reinicia" algo (contadores del día)?
7. **Arqueo físico**: ¿el cajero debe registrar el efectivo contado (arqueo) para conciliar contra el total calculado, o el cierre es 100% calculado?

## Recommended Scope Boundaries

**Backend (núcleo del cambio):**
- Migración: `tipo` en `cierres_caja` (si se elige opción 2) y/o `metodo_pago` en `pagos` (si se elige opción 4).
- `CierreService`: soporte semanal + desglose por método (según respuestas).
- `PagoController::store` y `ApuestaService::crearApuesta`: captura de `metodo_pago` (requiere coordinación con `taquilla-venta-agil` para el cobro en la venta).
- Tests: extender `CierreCajaTest` (semanal, encadenamiento, desglose), `PagoTipoTest`/nuevo test de método de pago.
- Actualizar colecciones `collections/Cierre de Caja/*.yml` y `collections/Pagos/*.yml`.

**Taquilla frontend (este cambio):**
- `taquilla/src/pages/cierre.astro` completo: resumen, ejecutar cierre (diario/semanal), confirmación, resultado, historial, impresión.
- `MainLayout.astro`: sin cambios (nav ya existe).

**Panel (opcional / diferible):** página de cierres y reporte semanal para admin; se recomienda evaluar en `sdd-propose` según respuestas del cliente (¿quién ve el cierre semanal?).

**Fuera de alcance recomendado:** el flujo de venta (`dashboard.astro`) salvo la captura mínima de método de pago acordada; cambios al reporte `cuadre-caja`.

## Risks

- **Dependencia del flujo de venta**: capturar método de pago en el cobro (`ApuestaService` + `dashboard.astro`) cruza con el cambio paralelo `taquilla-venta-agil` (mismo archivo dashboard). Riesgo de conflicto/merge alto si no se coordina el contrato.
- **Migraciones ENUM en MySQL**: `pagos.tipo` y nuevos enums requieren ALTER con guard de driver (patrón existente en `alter_pagos_tipo_add_devolucion`); SQLite no valida enum (los tests pasan, prod no).
- **Encadenamiento de períodos**: si coexisten diarios y semanales, el `fecha_inicio` del siguiente cierre depende del último `fecha_fin` — riesgo de solapes/huecos si el orden de cierres no es estricto (falta un cierre, doble cierre el mismo día).
- **Sin tasa activa = cierre imposible** (422): en un semanal, ventana más larga ⇒ más exposición a que la tasa no exista/cambie; decidir política (fallback a última tasa vs bloquear).
- **Datos históricos sin `tipo`/`metodo_pago`**: los `pagos` existentes y `cierres_caja` previos no tienen los campos nuevos; los totales históricos del cierre quedarán sin desglose por método (aceptar como limitación o backfill manual).
- **Cierre semanal como reporte vs persistido**: elegir reporte puro sin persistir genera "semana incompleta" si faltan diarios; elegir persistido requiere definir ventana y política de reapertura (¿se puede cerrar la semana dos veces? ¿se puede anular?).
- **Sin cola offline en la taquilla**: ejecutar el cierre exige conectividad; si la red cae, el cajero no puede cerrar (decidir UX: reintento, validación de conectividad previa).
- **Doble fuente de verdad "efectivo"**: `CierreService` (ventas − egresos) vs `cuadreCaja` (Venta − Pagados − Devoluciones − Vencidos) difieren (restan vencidos). Un cierre semanal que intente "cuadrar" con el reporte puede confundir; alinear fórmulas o documentar la diferencia.
- **Multi-agente**: otros agentes trabajan `panel/` y `taquilla/` en worktrees paralelos; cualquier contrato nuevo de API debe acordarse para no romper frontends.

## Ready for Proposal

Sí — con CONDICIÓN: `sdd-propose` debe primero resolver con el cliente las Open Questions (semántica semanal, desglose por método, rol ejecutor, efecto operativo, tasa). Sin esas respuestas, el diseño (opción 2 vs 1, opción 4 vs 5) queda a ciegas. Recomendación técnica preliminar: opción 2 (semanal persistido con `tipo`) + opción 4 (captura de `metodo_pago` con default `efectivo`) si el cliente confirma el desglose por método; taquilla opción 6; panel diferible.