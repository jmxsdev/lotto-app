# Exploration: auditoria-trazabilidad-89

**Change**: `auditoria-trazabilidad-89` · Issue [#89](https://github.com/jmxsdev/lotto-app/issues/89)
**Fecha**: 2026-11-05
**Worktree**: `/home/gzuz/Documentos/lotto-app-worktrees/auditoria-trazabilidad-89`
**Base**: `main` @ `ea7d381`
**Rama tracker**: `feature/auditoria-trazabilidad-89`
**Estrategia**: `feature-branch-chain` (tracker + una rama por WU, commits locales, merge final a `main`)

---

## 1. Preflight SDD

| Campo | Valor |
|---|---|
| Execution mode | `auto` |
| Artifact store | `both` (OpenSpec + Engram) |
| Review budget | 800 líneas por WU/PR |
| Delivery strategy | chained PRs |
| Chain strategy | `feature-branch-chain` |
| Test execution | **El usuario ejecuta todos los comandos**. El agente se detiene antes de cada gate. |
| Commits | Locales por WU; sin push hasta el final del ciclo |
| Cierre | Suite completa → `sdd-verify` → `sdd-archive` → merge a `main` local → push a `main` |

---

## 2. Conflicto con el ciclo activo `retiro-legacy-backend`

El repo tiene un worktree activo:

- Path: `/home/gzuz/Documentos/lotto-app-worktrees/retiro-legacy-backend`
- Rama: `feat/retiro-legacy-backend`
- Issues: #81 (panel web legacy + welcome + `FetchResultsJob`) y #82 (`CheckRole`)
- Estado: WU-1 commiteada (`c814508`), WU-2 en curso
- Archivos que ese ciclo borra o modifica:
  - `backend/routes/web.php` (borra)
  - `backend/app/Http/Controllers/Admin/**` (borra)
  - `backend/resources/views/admin/**` y `welcome.blade.php` (borra)
  - `backend/app/Jobs/FetchResultsJob.php` (borra)
  - `backend/tests/Feature/FetchResultsJobTest.php` (borra)
  - `backend/bootstrap/app.php` (modifica: alias `checkrole` y `web:`)
  - `backend/app/Http/Middleware/CheckRole.php` (ya borrado)
  - `docs/dev/estructura.md`, `docs/dev/arquitectura-backend.md`, `docs/dev/plugins.md`, `docs/dev/auditoria-logs.md` (modifica)
  - `backend/tests/Feature/BackendSurfaceTest.php` (creado)

### Ajustes obligatorios de este ciclo

1. **No auditar el login del admin legacy.** Ese flujo se retira en #81. Solo se audita `POST /api/v1/login`, `POST /api/v1/logout` y sus fallos.
2. **No incluir `FetchResultsJob`.** Se retira en #81. La cobertura de ingesta queda en `ScrapeSourceJob` y `ScrapeResultsJob`.
3. **No tocar `backend/bootstrap/app.php`.** El middleware de contexto se declara directamente en `routes/api.php` usando la clase, sin alias global.
4. **No tocar `backend/routes/web.php`, `Admin/**`, `resources/views/**`, `CheckRole.php`, `FetchResultsJob.php` ni `BackendSurfaceTest.php`.**
5. **No tocar `docs/dev/auditoria-logs.md` durante el ciclo.** Se reconcilia después del merge de `retiro-legacy-backend`. La documentación nueva vive en `docs/dev/auditoria-sistema.md`.
6. **No tocar `docs/dev/estructura.md` ni `docs/dev/arquitectura-backend.md`.** Son propiedad del otro ciclo.
7. **Archivos compartidos seguros**: `routes/api.php`, `app/Http/Controllers/Api/**`, `app/Services/**`, `app/Models/**`, `database/migrations/**`, `panel/**`, `tests/Feature/Auditoria*`.
8. Si `retiro-legacy-backend` se mergea antes del cierre, se sincroniza `main` en la tracker y se resuelve solo lo que corresponda. No se reescribe su trabajo.

---

## 3. Estado actual verificado

### Correcciones al issue #89

- `user.role_changed` **ya existe** con actor, target, before/after, IP/UA y test (`UserController.php:196-221`, `AuditoriaRolTest`). El doc canónico está desactualizado.
- `GET /api/v1/logs` ya usa `permission:view_logs`, no `role:super_master|master` (`routes/api.php:193`).
- **No existe “reapertura” de caja.** Existe re-cierre del mismo día que sobreescribe la fila y pisa `reclosed_by/reclosed_at` (`CierreService.php:61-77`).
- El visor `/logs` está roto: el endpoint devuelve paginador y la página llama `logs.filter` (`panel/src/pages/logs.astro:24-28`). Además es XSS-prone por `innerHTML`.
- La doble codificación de `details` en `ActivacionController.php:133` es real.

### Cobertura actual

| Acción | Log | Actor | Before/After | IP/UA |
|---|---|---|---|---|
| Activación de taquilla | `logs` | null | Detalles | Sí |
| Pago de premio | `logs` | `user_id` | Parcial | Sí |
| Anulación de ticket/apuesta | `logs` | `user_id` | Parcial | Sí |
| Juego toggle/update/premios | `juego_auditoria` | `user_id` | Sí | No |
| Rol de usuario | `logs` | `user_id` | Sí | Sí |
| Scrapers | `logs` + archivo | null | Detalles | Job |
| Resto de usuarios/entidades/tasas/cierre/clave | No | — | — | — |

### Causas raíz

1. Se mezcló logging operativo con auditoría en la tabla `logs`.
2. No existe una capa de auditoría: 11 escrituras manuales.
3. El login API bypassa `Login/Failed` (`Hash::check` + `createToken`).
4. `created_by`/`set_by` se confunden con evidencia; no hay `updated_by`/`deleted_by`.
5. FKs `cascade` destruyen evidencia (`pagos.created_by`, `cierres_caja.created_by`, `juego_auditoria.user_id`); `logs.user_id` anonimiza.
6. El esquema modela una vista, no evidencia: sin `request_id`, entidad, índices, scope ni integridad.
7. La lectura no tiene contrato, scope ni redacción.
8. La semántica de fallo es inconsistente (dentro/fuera de transacción).
9. Los jobs que mutan negocio (`ScrapeExchangeRateJob`, `ExpireUnclaimedPrizesJob`, `MarcarApuestasVencidasJob`) solo escriben al archivo de Laravel.
10. No hay retención ni rotación; `logs` crece sin índices.

---

## 4. Catálogo cerrado de eventos

### 4.1 Envelope común

```text
actor_type      user | system | device
actor_id        nullable, sin FK cascade
actor_label     email/nombre snapshot
actor_roles     roles en el momento
action          domain.verb
entity_type     user | banca | grupo | agencia | taquilla | exchange_rate | ...
entity_id
scope           banca_id, grupo_id, agencia_id, taquilla_id
before          solo campos cambiados; snapshot completo en delete
after           solo campos cambiados
context         request_id | job_id | queue | source | reason_code | reason_text | route | method
ip / user_agent
schema_version
hash / prev_hash
```

### 4.2 Operadores — autenticación, sesión y acceso

| Evento | Punto de captura | Before/After | Motivo | Prioridad |
|---|---|---|---|---|
| `auth.login_succeeded` | `Api\AuthController::login` | No | No | P0 |
| `auth.login_failed` | `Api\AuthController::login` | No | `reason_code` automático | P0 |
| `auth.logout` | `Api\AuthController::logout` | No | No | P0 |
| `auth.token_revoked` | revocación de token | No | Opcional | P1 |
| `user.password_reset_by_admin` | `UserController::update` con password | No (nunca el hash) | Requerido | P0 |
| `device.verification_failed` | `DispositivoController::verificar` | No | Automático | P1 |
| `taquilla.activation_succeeded` | `ActivacionController::activar` | Snapshot de taquilla | No | P0 |
| `taquilla.activation_failed` | `ActivacionController::activar` | Código/MAC enmascarados | Automático | P0 |
| `taquilla.mac_reassigned` | `ActivacionController::activar` | MAC before/after enmascarada | Automático | P0 |
| `clave_cierre.updated` | `ClaveCierreController::update` | No (nunca la clave) | Opcional | P0 |

### 4.3 Operadores — usuarios, roles y permisos

| Evento | Punto de captura | Before/After | Motivo | Prioridad |
|---|---|---|---|---|
| `user.created` | `UserController::store` | `after` seguro + rol | No | P0 |
| `user.updated` | `UserController::update` | Campos sensibles cambiados | Opcional | P0 |
| `user.role_changed` | `UserController::update` | Roles before/after | Requerido en bajada | P0 (existe) |
| `user.permissions_changed` | asignación directa | Permisos before/after | Requerido | P0 |
| `user.activated` | update/toggle | `active` | No | P0 |
| `user.deactivated` | update/toggle | `active` | Requerido | P0 |
| `user.deleted` | `UserController::destroy` | Snapshot completo | Requerido | P0 |

### 4.4 Operadores — entidades y jerarquía

| Evento | Punto de captura | Before/After | Motivo | Prioridad |
|---|---|---|---|---|
| `banca.created` | `BancaController::store` | `after` seguro | No | P1 |
| `banca.updated` | `BancaController::update` | Campos cambiados | Opcional | P1 |
| `banca.toggled` | `BancaController::toggle` | `active` | Requerido | P0 |
| `banca.deleted` | `BancaController::destroy` | Snapshot | Requerido | P0 |
| `grupo.created/updated/toggled/deleted` | `GrupoController` | Idem + `banca_id` | Idem | P1/P0 |
| `grupo.banca_changed` | `GrupoController::update` | `banca_id` | Requerido | P0 |
| `agencia.created/updated/toggled/deleted` | `AgenciaController` | Idem + `banca_id` | Idem | P1/P0 |
| `agencia.banca_changed` | `AgenciaController::update` | `banca_id` | Requerido | P0 |
| `taquilla.created/updated/toggled/deleted` | `TaquillaController` | Idem + `grupo_id` | Idem | P1/P0 |
| `taquilla.group_changed` | `TaquillaController::update` | `grupo_id` | Requerido | P0 |

### 4.5 Operadores — dinero y configuración

| Evento | Punto de captura | Before/After | Motivo | Prioridad |
|---|---|---|---|---|
| `exchange_rate.created` | `ExchangeRateController::store` | `after` | No | P0 |
| `exchange_rate.updated` | `ExchangeRateController::update` | `rate`, `is_active` | Opcional | P0 |
| `exchange_rate.activated` | `ExchangeRateController::setActive` | `is_active` | No | P0 |
| `commission_defaults.updated` | `ComisionController::updateDefaults` | Before/after | Opcional | P0 |
| `cierre.created` | `CierreService::crear` | Totales | No | P1 |
| `cierre.reclosed` | `CierreService::recerrar` | Snapshot completo | Requerido | P0 |
| `pago.prize_paid` | `PagoController::store` | Montos/referencias | No | P0 (existe) |
| `ticket.voided` | `TicketAnulacionService` | Ticket before/after | Requerido | P0 (existe) |
| `bet.voided` | `TicketAnulacionService` | Apuesta before/after | Requerido | P0 (existe) |

### 4.6 Operadores — juegos, límites y configuración operativa

| Evento | Punto de captura | Before/After | Motivo | Prioridad |
|---|---|---|---|---|
| `game.updated` | `JuegoController::update` | Campos cambiados | Opcional | P1 |
| `game.toggled` | `JuegoController::toggle` | `active` | Opcional | P0 |
| `game.prizes_updated` | `PremiosConfigService` | `config.premios` | Requerido | P0 |
| `game.limits_updated` | `JuegoController::updateLimites` | Límite before/after | Opcional | P0 |
| `game.limits_batch_updated` | `JuegoController::batchLimites` | Resumen | Opcional | P1 |
| `game.limit_deleted` | `JuegoController::destroyLimite` | Snapshot | Requerido | P1 |
| `config.bet_expiration_window_updated` | `ConfiguracionController` | Before/after | Opcional | P1 |

### 4.7 Operadores — resultados, releases y auditoría

| Evento | Punto de captura | Before/After | Motivo | Prioridad |
|---|---|---|---|---|
| `draw.scrape_requested` | `ResultadoController::scrape` | Parámetros | No | P1 |
| `draw.scrape_all_requested` | `ResultadoController::scrapeAll` | Alcance | No | P1 |
| `draw.result_corrected` | corrección manual futura | Resultado before/after | Requerido | P0 si existe |
| `release.published` | `ReleasePublishCommand` | Versión/hash | Requerido | P1 |
| `audit.exported` | endpoint de export | Filtros | No | P1 |
| `audit.retention_purged` | comando de poda | Rango/conteo | Sistema | P1 |
| `audit.chain_verified` | comando de verificación | Resultado | Sistema | P1 |
| `permission.catalog_synced` | seeder/comando | Diff | Sistema | P1 |

### 4.8 Sistema y jobs

| Evento | Job/proceso | Before/After | Prioridad |
|---|---|---|---|
| `exchange_rate.scraped` | `ScrapeExchangeRateJob` | Tasa activa before/after | P0 |
| `draw.result_ingested` | `ScrapeSourceJob`, `ScrapeResultsJob` | `result_id`, juego, sorteo, fuente | P1 |
| `draw.settlement_completed` | detección de ganadores | Conteos y payout total | P1 |
| `ticket.expired` | `ExpireUnclaimedPrizesJob` | `estado` before/after por ticket | P0 |
| `bet.expired` | `MarcarApuestasVencidasJob` | `estado` before/after por apuesta | P0 |
| `pago.legacy_reconciled` | `AuditarPagosLegacy` | Old/new values | P1 (existe como `auditoria.pago_legacy`) |
| `backfill.completed` | comandos de backfill | Alcance y conteos | P2 |

### 4.9 No se audita

- `ticket.created`, `bet.created`, `pago.income_created`: ya son registros de negocio; asegurar `created_by`/`taquilla_id`, no duplicar payload.
- Lecturas, listados, reportes.
- Health checks, `update-check`, feed de releases.
- Scrapers en detalle: siguen en `logs` operativos y archivo.
- Fallos de cola: `failed_jobs`/Horizon.
- 403 genéricos.
- Observer global sobre todos los modelos.

---

## 5. Arquitectura

1. Tabla `audit_events` append-only; `logs` queda para operación.
2. `AuditContext` captura request metadata y resuelve actor lazy.
3. `AuditRecorder` único punto de escritura; normaliza, redacta, versiona.
4. `AuditAction` enum con taxonomía cerrada.
5. Trait `Auditable` opt-in para CRUD de alto valor.
6. Llamadas explícitas en servicios de dinero/seguridad.
7. Jobs con `actor_type=system`, `job_id`, `queue`.
8. P0 fail-closed dentro de la transacción; P1 best-effort.
9. Lectura con cursor pagination, filtros, scope `AlcanceService`, redacción.
10. Append-only app + BD; retención hot/archivo; hash chain opcional.

### Decisiones por defecto

| Decisión | Default |
|---|---|
| Retención | 12 meses hot + 5 años archivo (confirmar legal) |
| Tamper evidence | Grants/triggers en BD; hash chain si se exige |
| Lectura | `view_audit` + `AlcanceService`; super_master global, master su banca |
| Fail-closed | P0 sí; P1 best-effort; sin outbox en v1 |
| Motivo | Obligatorio en destructivas/cambio de acceso |
| Backfill | Solo eventos de negocio con actor; nunca scrapers |
| Admin legacy | Fuera de alcance (#81 lo retira) |
| `FetchResultsJob` | Fuera de alcance (#81 lo retira) |
| `/logs` | Queda operativo para super_master; `/auditoria` es el visor nuevo |

---

## 6. Front

- Nueva página `panel/src/pages/auditoria.astro`: filtros, tabla, cursor pagination, detalle con diff, export CSV, escape.
- `panel/src/utils/reason-modal.ts`: modal reutilizable de `reason_code` + nota.
- Cablear modales en usuarios, bancas/grupos/agencias/taquillas detalle, cuadre, clave-cierre, tasas, comisiones, juegos.
- `panel/src/pages/logs.astro`: corregir contrato (`logs.data`), paginación, escape; banner “operativo”.
- Permisos `view_audit`, `export_audit`; link en `AdminLayout.astro`.
- `apiFetch` con query params para acciones destructivas.

---

## 7. Plan de WUs (cada una ≤800 líneas)

| WU | Contenido | Líneas estimadas |
|---|---|---|
| WU-1a | Migración `audit_events`, modelo, enum, DTO, config | ~450 |
| WU-1b | `AuditContext`, middleware, `AuditRecorder`, redactor, trait, tests base | ~650 |
| WU-2 | Auth API: login/logout/failed, token | ~400 |
| WU-3 | Usuarios, roles, permisos, password, clave de cierre | ~700 |
| WU-4 | Tasas de cambio y comisiones | ~500 |
| WU-5 | Cierre/re-cierre, pagos y anulaciones | ~700 |
| WU-6 | Juegos, premios, límites y ventana de vencimiento | ~600 |
| WU-7 | Entidades y jerarquía | ~700 |
| WU-8 | Jobs y resultados (sin `FetchResultsJob`) | ~700 |
| WU-9 | API de lectura + permisos + export | ~600 |
| WU-10 | Panel `/auditoria` | ~800 |
| WU-11 | Modales de motivo + `/logs` operativo | ~500 |
| WU-12 | Integridad, retención, migración `juego_auditoria`, FKs | ~800 |
| WU-13 | Hardening, observabilidad, docs y rollout | ~500 |

**Total estimado**: ~7.900–9.000 líneas con tests; 22–30 días hábiles. Cada WU ≤800 líneas.

---

## 8. Protocolo de tests y commits

1. El agente implementa una WU y **no ejecuta tests, migraciones, Pint, composer, npm ni artisan**.
2. El agente entrega los comandos exactos.
3. El usuario los ejecuta y reporta pass/fail.
4. Si pasa: el orquestador hace `git add` + `git commit` local en la rama de la WU.
5. Si falla: se corrige y se repite el gate.
6. Al final: suite completa por el usuario; si pasa, `sdd-verify` → `sdd-archive` → merge a `main` local → push a `main`.

---

## 9. Riesgos

- Cambio de FKs `cascade` con datos existentes: migración separada, backup y staging.
- Fail-closed en P0 puede acoplar disponibilidad; alertar y medir.
- PII: decidir redacción de email, MAC, fingerprint, códigos, motivos.
- El panel tiene 30 páginas; cablear modales sin romper flujos existentes.
- `main` tiene cambios sin commitear (taquilla/docs); no se tocan. Antes del merge final hay que resolverlos.
- El otro ciclo puede mergear durante el nuestro; sincronizar `main` antes del cierre.
- No tocar `bootstrap/app.php`; el middleware va directo en `routes/api.php`.
