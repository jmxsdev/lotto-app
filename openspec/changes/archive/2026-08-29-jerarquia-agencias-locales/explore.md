# Exploración: jerarquia-agencias-locales

Cambio: jerarquía de 6 niveles (`super_master` → `master` → `banca` → `grupo` → `agencia` → `taquilla`), donde AGENCIA = local físico (nuevo nivel intermedio entre grupo y taquilla) y TAQUILLA = máquina que genera tickets (entidad actual). Decisiones de negocio del cliente YA cerradas (ver memoria `negocio/jerarquias-6-niveles`): (1) agencia = local físico; (2) taquilla = máquina; (3) super banca (master) ve SOLO sus propias entidades (no global); (4) el rol AGENCIA entra al panel (crea sus taquillas y opera en su scope); el rol TAQUILLA solo entra a la app de taquilla (como hoy).

## Estado actual (verificado en código, no asumido)

### Esquema
- `users`: `role` (string, default `taquilla`), `banca_id`, `grupo_id`, `taquilla_id`, `active`. SIN `agencia_id`. (migración `2026_07_10_035612`)
- `bancas`: `name, code, config, monedas_permitidas, vigencia_premios, tiempo_eliminacion, active, created_by,` fiscal, softDeletes. **NO existe columna que asocie master↔bancas** (ni `master_id` ni pivot). El único vínculo posible hoy es `created_by`.
- `grupos`: `name, code, banca_id, monedas_permitidas, vigencia_premios, tiempo_eliminacion, active, created_by,` fiscal, softDeletes.
- `taquillas`: `name, code, grupo_id (NOT NULL, cascade), mac_address, activation_code, device_fingerprint, active, last_connection_at, vigencia_premios, tiempo_eliminacion, created_by,` fiscal, softDeletes. SIN `agencia_id`.
- `juego_limites`: `juego_id, banca_id (NOT NULL), grupo_id (NULL), taquilla_id (NULL), moneda(bs|usd), limite_minimo/maximo, porcentaje_pago, participacion, fraccion, limite_tiempo` + índice único `(juego_id, moneda, banca_id, COALESCE(grupo_id,4294967295), COALESCE(taquilla_id,4294967295))`. SIN `agencia_id`.
- `apuestas`: solo `taquilla_id` (NOT NULL) + `juego_id` + montos; no tiene columnas de grupo/banca. `pagos`: `taquilla_id, tipo(egreso|devolucion)`. `comisiones`: `banca_id, grupo_id, taquilla_id`. `cierres_caja`: `taquilla_id`.
- Factory `TaquillaFactory` exige `grupo_id` (crea grupo automáticamente).

### Roles y permisos
- `RolesAndPermissionsSeeder`: 5 roles (`super_master`, `master`, `banca`, `grupo`, `taquilla`). NO existe rol `agencia`. Permisos relevantes: `manage_taquillas`, `view_taquillas`, `view_reports`, `create_cierre`, `view_cierre`, `view_apuestas`, `create_apuesta`, `delete_apuesta`, `create_pago`, `view_pagos`. No existen permisos `manage_agencias`/`view_agencias`.
- Doble fuente: columna `users.role` (autoritativa en policies/queries) + rol Spatie (rutas `role:` y `hasRole`). El rol agencia debe registrarse en AMBAS.

### Rutas (`routes/api.php`, 17 grupos con `role:`)
- `users`: `super_master|master|banca|grupo|taquilla`
- `bancas` (CRUD+toggle): `super_master|master` — la ruta NO es global-hijo: el controlador da 403 a otros
- `grupos` (CRUD+toggle): `super_master|master|banca`
- `taquillas` (CRUD+toggle): `super_master|master|banca|grupo` ← **falta `agencia`**
- `juegos` update/toggle: `super_master|master`; GET: cualquier autenticado
- `resultados` scrape: `super_master|master`
- `apuestas`, `tickets`, `pagos`, `cierre`, `reportes`, `estadisticas`: los 5 roles
- `logs`: `super_master|master`
- `limites` GET: `super_master|master|banca|grupo` ← **falta `agencia`**; PUT/DELETE/batch: `super_master|master|banca`
- `exchange-rates`: por permission (`view_exchange_rates`/`manage_exchange_rates`)

### Alcance por rol (patrones verificados)
- `UserController::index` (37-43): master → `banca_id` si lo tiene, si no SOLO a sí mismo (hoy el master del seeder no tiene `banca_id` → ve solo su propio usuario; es decir, el alcance "super banca" YA está parcialmente limitado en usuarios). banca → `banca_id` + cadena taquilla; grupo → `grupo_id` + cadena taquilla; taquilla → solo self.
- `BancaController::index`: super_master/master → TODAS las bancas (global; sin dueño).
- `GrupoController::index`: super_master/master → todos; banca → su banca; grupo → propio.
- `TaquillaController::index`: super_master/master → todas; banca → `whereHas grupo.banca_id`; grupo → `grupo_id`; otro → 403 "No tienes permiso para ver agencias."
- `ReporteController::buildApuestaQuery` y `buildTicketQuery` (23-54 / 59-89): taquilla → `taquilla_id`; grupo → `whereHas taquilla.grupo`; banca → `whereHas taquilla.grupo.banca`; **master → SIN filtro (global)**.
- `EstadisticaController::buildApuestaQuery` (22-45): idéntico; master global.
- `ApuestaController::index` (25-44): idéntico; master/super_master globales.
- `CierreController::index` / `resolveTaquillaParaCierre` / `authorizeCierreAccess`: master global; banca/grupo por cadena; taquilla solo self.
- `JuegoController::limites` (158-207): super_master/master globales; banca → `banca_id`; grupo → propio + herencia banca. `destroyLimite`: super_master/master/banca.
- `ApuestaPolicy`: `view` (banca → `apuesta.taquilla.grupo.banca_id === banca_id`; grupo → `grupo_id`; taquilla → `taquilla_id`), `create` (solo taquilla), `delete` (rama por rol + `getEffectiveTiempoEliminacion`). `viewAny` lista los 5 roles.

### Login / panel
- `Api/AuthController::login` (53-61): con header `X-Panel: true` permite `['super_master','master','banca','grupo']`; taquilla → 403 "Las agencias deben usar la app de escritorio." Sin `X-Panel` → solo `taquilla`. `mensajeCadenaInactiva` (89-137) cubre taquilla→grupo→banca (sin agencia).
- `panel/src/pages/login.astro` (53, 111-115): `ROLES_PERMITIDOS = ['super_master','master','banca','grupo']` + mensaje 403 "Las agencias deben usar la app de escritorio."
- `panel/src/utils/api.ts` (40): `ROLES = ['super_master','master','banca','grupo']`.
- `VerifyMac` middleware: solo rol taquilla; mensajes usan "agencia" para la máquina ("Usuario sin agencia asociada", "Agencia no encontrada", "La agencia está desactivada.").
- `ActivacionEfectivaService::estadoTaquilla` (62-79): cadena taquilla → grupo → banca. Sin nivel agencia: una agencia (local) desactivada NO pausaría sus taquillas.

### Panel (labels y navegación)
- `AdminLayout.astro` (169-171): "Bancas" (super/master), "Grupos" (super/master/banca), **"Agencias" → `/taquillas`** (super/master/banca/grupo). Cuadre de Caja: super/master/banca/grupo. Límites: solo super_master. Usuarios: todos. Logs/Tasas: super/master.
- `ROLE_LABELS` en `usuarios.astro`, `bancas/detalle.astro`, `grupos/detalle.astro`, `taquillas/detalle.astro`: `taquilla: 'Agencia'` — la máquina se muestra como "Agencia" en toda la UI.
- `usuarios.astro`: `ROLE_ORDER` y `assignableRoles()` (start map `{super_master:0, master:1, banca:2, grupo:3}`); select de entidad `taquilla_id` rotulado "Agencia"; sin select de agencia.
- `reportes/ventas.astro` (20-23): `<option value="taquilla">Agencia</option>` → envía `nivel=taquilla` rotulado "Agencia".
- `cuadre.astro` (21-23): `<option value="agencia">Agencia</option>` → envía `nivel=agencia`, que el backend agrupa por TAQUILLAS.
- `dashboard.astro` (11): stat "Agencias" cuenta taquillas.
- `limites.astro`: "Todas las agencias" = scope taquillas; `taquillas/detalle.astro`: "la agencia no configura monedas propias" (monedas SIEMPRE heredadas del grupo).

## Puntos específicos investigados

### a) Creación de taquillas hoy y qué cambia para AGENCIA
Hoy: `POST /api/v1/taquillas` (TaquillaController::store, 58-128) exige `grupo_id`; `authorizeGrupoAccess` (super_master/master todo; banca su banca; grupo su propio grupo); valida `vigencia_premios`/`tiempo_eliminacion` contra grupo (+banca fallback); crea taquilla con `grupo_id` y un usuario rol `taquilla` con `banca_id/grupo_id/taquilla_id` derivados; `assignRole('taquilla')`.
Para que una AGENCIA cree sus taquillas:
- La ruta `taquillas` debe aceptar el rol `agencia` (routes 69-72).
- El store debe aceptar `agencia_id` (nullable, `exists:agencias,id`) o derivarlo: si el autenticado es agencia → `agencia_id = user.agencia_id`, `grupo_id` derivado de la agencia (nunca editable por la agencia).
- Nueva `authorizeAgenciaAccess` (agencia solo su local; grupo sus locals; banca los de su banca; master/super todo).
- El usuario creado debe incluir `agencia_id` (como hoy deriva la cadena).
- Validaciones de vigencia/tiempo pasan a contrastar contra la agencia (si el local llegara a configurarlos) o quedan igual si el local es passthrough (ver enfoques).
- `TaquillaController::index` (26-53) necesita rama agencia: `where('agencia_id', $user->agencia_id)`.

### b) Alcance de super banca (master) en queries — cantidad de queries globales
Patrón de referencia: `UserController:37-43` (master → `banca_id` o solo self). HOY **no existe asociación master→bancas en esquema** (bancas solo tiene `created_by`; sin `master_id` ni pivot). Queries/endpoints donde master es GLOBAL (≈10):
1. `BancaController::index` (y show/update/toggle/destroy)
2. `GrupoController::index` + `authorizeBancaAccess`/`authorizeGrupoAccess`
3. `TaquillaController::index`/`store`/`authorizeTaquillaAccess`/`authorizeGrupoAccess`
4. `ReporteController::buildApuestaQuery` (master sin filtro)
5. `ReporteController::buildTicketQuery`
6. `EstadisticaController::buildApuestaQuery`
7. `ApuestaController::index`
8. `CierreController::index`/`resolveTaquillaParaCierre`/`authorizeCierreAccess`
9. `JuegoController::limites`/`listarLimites`/`batchLimites`/`updateLimites`/`destroyLimite`
10. Panel: `AdminLayout.astro:169-171` (master ve todas las bancas/grupos/agencias) + dashboard + tasas + logs.
El atajo "whereIn banca_id" requiere DECIDIR de dónde sale el conjunto de bancas del master: opciones (i) `bancas.created_by = master_id` (barato, frágil si super crea bancas de un master), (ii) nueva columna `bancas.master_id` nullable (limpia; backfill desde `created_by`; UI al crear banca permite elegir master), (iii) pivot `master_banca`. Recomendación: (ii), con backfill y manejo de masters existentes sin banca asignada (sin backfill verían TODO vacío — riesgo).

### c) Login/panel para agencia
- `AuthController::login` (53-61): añadir `'agencia'` al `in_array` del branch `X-Panel`.
- `mensajeCadenaInactiva` (89-137): nueva rama agencia (agencia inactiva / grupo / banca).
- `login.astro` ROLES_PERMITIDOS + `utils/api.ts` ROLES + 'agencia'; ajustar mensaje 403.
- Payload de `user` (login y `GET /user`) debe exponer `agencia_id` → `User::fillable` + relación `agencia()`.
- `VerifyMac` NO aplica (solo rol taquilla). La agencia no envía fingerprint.
- El rol agencia entra a rutas: `taquillas`, `users` (para verse y ver sus taquillas), `apuestas`/`tickets`/`pagos`/`cierre`/`reportes`/`estadisticas` (scope), `juegos` GET, `resultados` GET. NO a `bancas`/`grupos`/`logs`/`tasas`/`juegos` PUT (rutas ya lo excluyen — solo falta `taquillas` y `limites` GET).
- `limites` GET (rutas 153-158) debe admitir `agencia` (para que el panel de la agencia pueda ver límites de sus taquillas en modo entidad/scope; PUT/DELETE se mantienen super/master/banca).

### d) Reportes que hoy agrupan por taquilla con label "agencia"
- `ventasTotales` (ApuestaService:489-562): niveles `taquilla`|`grupo`|default `banca`. **NO tiene nivel `agencia`** (si el panel enviara `nivel=agencia` caería a banca — bug latente). Panel envía `nivel=taquilla` con label "Agencia".
- `cuadreCaja` (ApuestaService:693-811): `nivel='agencia'` → agrupa por `taquillas.id/name` (label "Agencia" = máquina). Panel /cuadre envía `nivel=agencia`.
- `rendimientoTaquillas` (ApuestaService:625-677): siempre por taquilla; clave `'Agencia'` = nombre de taquilla. Panel "Rendimiento Agencias" → `/reportes/taquillas`.
- `relacionTickets` (570-617) y `vencidos` (ReporteController:214-248): `Agencia = taquilla.name`.
Con el local intermedio: `nivel='agencia'` debe agrupar por la tabla `agencias` (locals) y `nivel='taquilla'` por taquillas (máquinas). Cambios: joins `taquillas→agencias→grupos→bancas` en ventasTotales/cuadreCaja/pagosCuadrePorNivel; `rendimientoTaquillas` (¿mantener por máquina con label "Taquilla" o nuevo reporte por local?); `relacionTickets`/`vencidos` (Agencia = local; añadir Taquilla = máquina).

### e) Cascada de límites/monedas/vigencia/tiempo y el nivel agencia
- `getEffectiveLimit` (153-182): JuegoLimite `taquilla > grupo > banca` (COALESCE en 1 query).
- `getEffectiveMonedas` (117-129): intersección grupo ∩ banca (la UI de taquilla/detalle confirma: "la agencia no configura monedas propias").
- `getEffectiveVigencia` (190-204): `taquilla.vigencia_premios ?? grupo ?? banca ?? null`.
- `getEffectiveTiempoEliminacion` (212-226): `taquilla ?? grupo ?? banca ?? 5`.
- Validaciones de creación: `GrupoController::store` valida monedas/vigencia/tiempo contra banca; `TaquillaController::store` contra grupo (+banca).
- Insertar el nivel agencia con configuración propia implicaría: columna `juego_limites.agencia_id` + **recrear el índice único** (drop/recreate destructivo), rama agencia en `getEffectiveLimit`/`getEffectiveMonedas`/`getEffectiveVigencia`/`getEffectiveTiempoEliminacion`, validaciones `agencia ≤ grupo` en todos los stores y UI de configuración del local. En el **modelo mínimo** (local solo identidad, sin config) la cascada queda intacta: agencia es passthrough (valores null → hereda igual que hoy). Recomendación: modelo mínimo → NO tocar juego_limites ni getEffective* en fase 1; añadir `agencia_id` a juego_limites solo si el cliente pide configuración de local (fase posterior, migración propia).

### f) Datos existentes que condicionan el backfill
- Desarrollo (seeders): 1 banca (BT001), 1 grupo (GT001), 2 taquillas (TT001 + DEMO01), usuarios super/master/banca/grupo/taquilla/demo. Backfill trivial: 1 local por grupo → GT001-Local; TT001 y DEMO01 → agencia_id de ese local; usuarios rol taquilla → agencia_id.
- Producción (VPS real): volumen de entidades desconocido — el backfill DEBE ser idempotente, ejecutable con `--force` y revisable (1 local por grupo puede no reflejar la realidad física; el cliente deberá renombrar/crear locals reales después). Riesgo de datos inventados: mitigar con backfill por comando artisan dedicado + log.
- `TerminalesSeeder` crea JuegoLimite a nivel banca (grupo_id/taquilla_id null) — sin impacto.

### g) Tests que fijan el modelo (dimensionamiento)
~21 archivos Feature + 5 Unit. Los que asumen el modelo de 5 roles / agencia≡taquilla:
- `RoleAuthorizationTest`: master lista users; banca crea grupos solo en su banca; **grupo crea taquillas solo en su grupo** (POST /taquillas); límite grupo ≤ banca (422).
- `TerminologiaTest`: fija `'Agencia'` = taquilla en rendimiento/relacion-tickets/vencidos; mensajes "Las agencias deben usar la app de escritorio.", "La agencia está desactivada.", "Agencia eliminada correctamente."; identificadores internos intactos (rol taquilla, tabla taquillas).
- `GestionEntidadesApiTest`: filtros `banca_id/grupo_id/taquilla_id` en /users (intersección con alcance).
- `LimitesScopedApiTest` (865 líneas) + `LimitesApiTest`: matriz de límites, modo entidad/scope, herencia, alcance por rol.
- `CuadreCajaReportTest`: nivel `agencia` = taquillas.
- `ReporteTest`/`EstadisticaTest`: scope por rol en reportes.
- `ApuestaTest`/`EliminacionApuestasTest`: cascadas efectivas (monedas/limite/vigencia/tiempo 5 min).
- `ActivacionEntidadesTest`: cadena de activación banca→grupo→taquilla.
- `CierreCajaTest`, `PagoTipoTest`, `GestionUsuariosTest`, `InformacionFiscalTest`, `ExchangeRateTest`.
Actualizar: TerminologiaTest (semántica Agencia), CuadreCajaReportTest/ReporteTest (nivel agencia = local), tests de login X-Panel (agencia aceptada), RoleAuthorizationTest (agencia crea taquilla). Añadir: alcance agencia (reportes/taquillas/usuarios/cierre), master con scope (solo sus bancas), activación cadena con agencia.

## Enfoques

| Enfoque | Pros | Contras | Esfuerzo |
|---|---|---|---|
| **A. Tabla mínima nullable (recomendado)** — `agencias` con identidad (name, code, grupo_id, active, created_by, fiscal, softDeletes); `taquillas.agencia_id` nullable; `users.agencia_id` nullable; backfill 1 local por grupo; rol agencia + rama agencia en scopes; agencia NO configura monedas/vigencia/tiempo/límites (passthrough) | Barato; cascadas getEffective* intactas; juego_limites intacto; riesgo bajo; cubre las 4 decisiones; migración posterior a "local configurable" es aditiva | La agencia no configura límites/monedas propios (solo hereda) — si el cliente lo exige, fase posterior | Medio |
| **B. Full model (local configurable)** — agencias con monedas/vigencia/tiempo + `juego_limites.agencia_id` + rama agencia en getEffective* + validaciones agencia≤grupo + UI de configuración del local | Modelo completo y consistente con banca/grupo | Recrear índice único de juego_limites (destructivo); tocar el corazón de apuestas (getEffective*) con alto riesgo de regresión; duplicar UI de detalle; mucho más trabajo de tests | Alto |
| **C. Solo renaming (sin tabla)** — mantener agencia≡taquilla y renombrar labels | Casi cero | NO cumple la decisión 1 (local ≠ máquina) ni 3 (agencia con scope en panel); descartado | Bajo (insuficiente) |

**Recomendación: Enfoque A**, con estas decisiones de diseño para la propuesta:
1. `agencias` = local físico: `name, code (único), grupo_id (NOT NULL, cascade), active (default true), created_by, fiscal (rif/email/telefono/direccion/estado/municipio, nullable), timestamps + softDeletes`. SIN monedas/vigencia/tiempo en fase 1.
2. `taquillas.agencia_id` nullable FK `set null` + `users.agencia_id` nullable FK `set null`. `taquillas.grupo_id` se MANTIENE (no se migra) para no romper joins existentes; agencia deriva grupo.
3. Backfill: 1 agencia por grupo (`name = "{grupo} - Local"`, code derivado); `taquillas.agencia_id` = local del grupo; `users.agencia_id` = `taquilla.agencia_id` para rol taquilla. Comando artisan idempotente, no seeder.
4. Rol `agencia` en seeder con permisos: `view_taquillas, manage_taquillas, view_apuestas, create_apuesta, delete_apuesta, create_pago, view_pagos, view_reports, create_cierre, view_cierre` (≈ banca/grupo sin gestionar grupos). + columna `role` = 'agencia' en users.
5. Master scope: `bancas.master_id` nullable + backfill desde `created_by`; `whereIn('banca_id', $masterBancas)` en los ~10 queries globales; UI de bancas permite elegir master.
6. Ramas agencia: AuthController (X-Panel + mensajeCadenaInactiva), ApuestaPolicy, buildApuestaQuery ×2, ApuestaController.index, TaquillaController (index/store/update/authorize), UserController (index/store/derive/validate/resolve + authorizeEntityBinding/authorizeUserAccess), CierreController (index/resolve/authorize), JuegoController.limites (rama agencia), GrupoController.authorizeBancaAccess no aplica (agencia no gestiona grupos).
7. Reportes: ventasTotales/cuadreCaja `nivel='agencia'` → agrupar por `agencias`; rendimientoTaquillas label "Taquilla" (máquina) y nuevo agrupado por local si se desea; relacionTickets/vencidos: `Agencia` = local, añadir `Taquilla` = máquina.
8. Activación efectiva: añadir agencia a la cadena (`estadoTaquilla` y `mensajeCadenaInactiva`): taquilla → agencia → grupo → banca.
9. Panel: login (ROLES_PERMITIDOS + agencia), utils ROLES, sidebar ("Agencias" = locals → nueva entrada `/agencias`; "Taquillas" = `/taquillas` para la máquina; agencia ve sus taquillas/cuadre/reportes), ROLE_LABELS (`agencia: 'Agencia'`, `taquilla: 'Taquilla'`), usuarios (select agencia), taquillas pages (labels), reportes/ventas (option `agencia` real + `taquilla` máquina), cuadre (opción "Local" + "Taquilla"), dashboard (stat locales + taquillas), límites (scope agencias).
10. Tests: actualizar los que fijan agencia≡taquilla (Terminologia, Cuadre/Reporte, login X-Panel) + nuevos (agencia crea taquilla en su local; master ve solo sus bancas; agencia scope en reportes/usuarios/cierre; activación con agencia).

## Riesgos
1. **Master↔banca sin modelo**: la decisión 3 exige definir de dónde salen las bancas del master (`master_id` vs `created_by` vs pivot). Si se elige mal o no se hace backfill, masters existentes ven todo vacío (pérdida funcional real en producción).
2. **Tests existentes fijan agencia≡taquilla**: TerminologiaTest y otros romperán si no se actualizan en el mismo cambio (suite roja).
3. **Bug latente en ventasTotales**: no soporta `nivel=agencia` (cae a banca); al introducir el local hay que añadirlo o el reporte del panel queda incorrecto.
4. **Backfill en producción inventa datos**: 1 local por grupo puede no reflejar la realidad física; mitigar con comando idempotente, log y revisión posterior por el cliente.
5. **Cadena de activación**: si no se añade agencia a `ActivacionEfectivaService`, desactivar un local NO pausa sus taquillas (fuga de control).
6. **Doble fuente rol**: olvidar columna `users.role` o rol Spatie rompe auth (login X-Panel, policies, rutas) por separado.
7. **Índice único de juego_limites**: si en el futuro se añade `agencia_id`, requiere drop/recreate destructivo — documentarlo para no mezclarlo en fase 1.

## Dependencias y orden de fases sugerido
- Fase 0 (datos): migración `agencias` + `taquillas.agencia_id` + `users.agencia_id` + `bancas.master_id`; backfill idempotente; seeder rol agencia + permisos; factories (AgenciaFactory, ajustes TaquillaFactory/UserFactory). Prerequisito de TODO.
- Fase 1 (backend scope): rama agencia en Auth/login, policies, buildApuestaQuery ×2, ApuestaController, TaquillaController, UserController, CierreController, JuegoController.limites, ActivacionEfectivaService. Tests de scope. (Depende de F0.)
- Fase 2 (super banca): whereIn banca_id en los queries globales de master + UI bancas (elegir master). Independiente de F1; solo depende de `bancas.master_id` (F0).
- Fase 3 (reportes): agrupación por agencias en ventasTotales/cuadreCaja/rendimiento/vencidos/relacionTickets + labels. Depende de F1.
- Fase 4 (panel UI): login, utils, sidebar, usuarios, taquillas, reportes, dashboard, límites. Depende de F1 y F3.
- Fase 5 (endurecer): TerminologiaTest actualizado, limpieza de mensajes, suite completa (`composer test`), verificación de fases.

Nota: F2 puede adelantarse (es ortogonal) si el cliente prioriza el alcance de super banca.

## Archivos afectados (backend)
- `database/migrations/*` (nuevas: agencias, agencia_id en taquillas/users, master_id en bancas) + `database/seeders/RolesAndPermissionsSeeder.php` (+ DatabaseSeeder/UsersSeeder para rol agencia)
- `app/Models/`: `Agencia` (nuevo), `User` (+agencia), `Taquilla` (+agencia)
- `app/Http/Controllers/Api/`: `AuthController`, `TaquillaController`, `UserController`, `GrupoController`, `BancaController`, `ApuestaController`, `CierreController`, `JuegoController`, `ReporteController`, `EstadisticaController`
- `app/Policies/ApuestaPolicy.php`, `app/Services/ApuestaService.php` (solo reportes), `app/Services/ActivacionEfectivaService.php`
- `routes/api.php`
- `tests/Feature/`: actualizar `TerminologiaTest`, `RoleAuthorizationTest`, `CuadreCajaReportTest`, `ReporteTest`, `EstadisticaTest`, `ActivacionEntidadesTest`, `GestionEntidadesApiTest`; nuevos tests de agencia y master-scope

## Archivos afectados (panel y taquilla)
- `panel/src/pages/login.astro`, `panel/src/utils/api.ts`, `panel/src/layouts/AdminLayout.astro`, `panel/src/pages/usuarios.astro`, `panel/src/pages/taquillas.astro` (+detalle), `panel/src/pages/bancas/detalle.astro`, `panel/src/pages/grupos/detalle.astro`, `panel/src/pages/dashboard.astro`, `panel/src/pages/cuadre.astro`, `panel/src/pages/reportes/ventas.astro` (+taquillas/tickets/vencidos), `panel/src/pages/limites.astro`
- `taquilla/`: sin cambios esperados (rol taquilla sigue igual; login sin X-Panel).

## Listo para propuesta
Sí. La propuesta debe: (1) fijar el esquema mínimo de `agencias` + backfill; (2) DECIDIR el mecanismo master↔bancas (`master_id` recomendado) — es la única decisión técnica abierta que requiere input del cliente/orquestador; (3) confirmar si el local debe configurar límites/monedas propios en esta iteración (recomendado: NO, fase posterior).