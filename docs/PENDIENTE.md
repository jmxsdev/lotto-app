# Implementaciones Pendientes

## Próximos Sprints

### 1. Costo Mínimo por Juego
- Agregar columna `costo_minimo` a la tabla `juegos` (decimal, moneda configurable)
- Validar en `ApuestaStoreRequest` que el monto apostado no sea menor al mínimo del juego
- Permitir configurar el valor desde el endpoint `PUT /api/juegos/{juego}`

### 2. Historial de Auditoría (juego_auditoria)
- Endpoint `GET /api/juegos/{juego}/auditoria` para consultar el historial
- Incluir datos del usuario que realizó la acción

### 3. Scrapers por Juego
- Los juegos que requieran scraper (`requires_scraper = true`) deben tener su propio job
- Generalizar `ScrapeExchangeRateJob` como ejemplo base

### 4. Plugin de Triple Zulia
- Verificar que el seeder `TripleZuliaSeeder` esté agregado a `DatabaseSeeder`
- Probar el endpoint `GET /api/juegos/triple-zulia/opciones` y reglas

### 5. Cierre de Caja
- Endpoint `POST /api/cierre` para cerrar caja de una taquilla
- Resumen de apuestas del día, pagos, comisiones

### 6. Permisos Finos (Spatie)
- Reemplazar los `role:` middleware con `permission:` middleware
- Crear permisos específicos: `view_juegos`, `manage_juegos`, `view_apuestas`, `manage_apuestas`
- Asignar permisos a roles en seeder

### 7. Pruebas Faltantes
- Tests para `JuegoController::update()`
- Tests para `JuegoController::toggle()` con auditoría
- Tests para el plugin `TripleZulia`

### 8. Bruno Collection
- Actualizar collection con los cambios de rutas de juegos
- Agregar request para `PUT /api/juegos/{juego}`
- Agregar request para `GET /api/juegos/{juego}/auditoria` (cuando exista)

## Pendientes post motor-premios (2026-09-26)

> Registrados al mergear `feat/motor-premios-f3-estados` a `main`. Referencias: `docs/integracion-front-motor-premios.md`, `docs/motor-premios.md`.

### A. Tickets sin ganadores ("perdidos") — ✅ RESUELTA por front 1.0.0 (en main)

> **Resuelta 2026-09-30** (verificada en código): el historial de taquilla deriva el chip `resuelto-sin-ganadores` desde `GET /tickets` (`tiene_ganadores=false` sin apuestas abiertas) en `taquilla/src/utils/estados.ts`; si faltan datos no inventa estado. Introducida en front 1.0.0 (`77f3c19`, PR #33).

- ~~Un ticket cuyas apuestas perdieron todas **queda en `pendiente` para siempre**: no existe estado "perdedor" de ticket (ni en main ni tras el merge).~~
- ~~Front (taquilla/panel): mostrar "perdida" derivando de `GET /tickets` (ya trae `apuestas.estado` + `ganadoras_count`/`tiene_ganadores`); o mini-WU backend que exponga `resuelto`/`estado_display`.~~

### B. Pago de premios de la taquilla (ROTO tambien en main) — ✅ RESUELTA por front 1.0.0 (en main)

> **Resuelta 2026-09-30** (verificada en código): `taquilla/src/utils/pagos.ts` arma el payload exacto `{ apuesta_id, tipo:'egreso', moneda }` con montos OPCIONALES (el backend aplica el premio del motor) y `esPagableApuesta` acepta `ganadora` y `pendiente` con resultado. Introducida en front 1.0.0 (`fa34aa4`, PR #33).

- ~~Payload actual de la taquilla: `{ apuesta_id, amount_bs, amount_usd, tipo:'bs' }` — invalido (el enum es `ingreso|egreso|devolucion`). Fix: `{ apuesta_id, tipo:'egreso', moneda }` (montos OPCIONALES: el backend aplica el premio del motor). Ver `docs/integracion-front-motor-premios.md` seccion 1.3.~~
- ~~Ademas, con el motor mergeado las ganadoras quedan en `ganadora`: el flujo de pago debe aceptar ese estado.~~

### C. Apuestas sin resultado (matching)

- El matching exige juego + fecha + **HORA EXACTA** del sorteo. Tras el merge, `MarcarApuestasVencidasJob` vence a las 24h con catch-up previo; si se confirman mismatches de hora (taquilla vs proveedor), evaluar tolerancia en el matching.

### D. Limites (gaps de panel/API)

- Sin DELETE para limpiar/volver a heredar un limite; la UI habilita a `grupo` pero la API responde 403; filtro `agencia_id` inexistente en `juego_limites`; `LimitesTable.astro` muerto; nav `/limites` solo para super_master.

### E. Comisiones (CICLO APARTE — decision 2026-09-26)

- Hoy no existe nada operativo (ver `docs/integracion-front-motor-premios.md` seccion 5). Decision de producto pendiente: significado (H1/H2/H3) y donde se edita (defaults por juego + override por entidad en la matriz de limites).

### F. Menor

- `figuras[]` de Tripleta: los scrapers aun no la persisten (2a sugerencia del verify-report).

## Tooling (2026-09-30)

- ✅ **Suite backend en paralelo** (`mejoras-tooling`, entregado en main): `composer test:parallel` corre PHPUnit en 4 procesos (~8-10 min vs ~35-50 min secuencial). Prerrequisitos de permisos MySQL y overrides documentados en `docs/runbook-ops.md` (§ Suite en paralelo).
