# Proposal: Auditoría y trazabilidad (#89)

**Change**: `auditoria-trazabilidad-89` · Issue [#89](https://github.com/jmxsdev/lotto-app/issues/89)
**Base**: `main` @ `ea7d381` · **Rama tracker**: `feature/auditoria-trazabilidad-89`
**Estrategia**: `feature-branch-chain` · **Presupuesto de revisión**: 800 líneas por WU/PR

---

## 1. Intent

Construir un **sistema de auditoría completo**, no un hotfix del visor roto. Hoy la "auditoría" es una mezcla de logging operativo (`logs`) y una tabla aislada (`juego_auditoria`) que modela una vista, no evidencia. No se puede responder quién hizo qué, sobre qué entidad, en qué alcance, cuándo y por qué.

**"Completo" significa**: un catálogo cerrado de eventos con taxonomía `domain.verb`, una capa única de captura con contexto (actor, request/job, scope, IP/UA), una tabla `audit_events` append-only con integridad verificable, una API de lectura con filtros/scope/export, un visor de panel funcional y sin XSS, motivo obligatorio en acciones destructivas, y retención/rotación con respaldo legal. Todo esto reemplaza progresivamente las 11 escrituras manuales dispersas sin romper el flujo legacy que #81 retira.

## 2. Problem

### Estado verificado (corrige afirmaciones obsoletas del issue #89)

- `user.role_changed` **ya existe** (`UserController::update:206-219`) con actor, target, before/after, IP/UA y test (`AuditoriaRolTest`). El doc canónico está desactualizado.
- `GET /api/v1/logs` ya exige `permission:view_logs`, no `role:super_master|master` (`routes/api.php:193`).
- **No existe "reapertura" de caja**: existe re-cierre del mismo día que **sobreescribe** la fila y pisa `reclosed_by/reclosed_at` (`CierreService`). Es destructivo, sin evidencia.
- El visor `/logs` está roto: el endpoint devuelve paginador y la página llama `logs.filter` (`logs.astro:24-28`); además es XSS-prone por `innerHTML`.
- Doble codificación de `details` en `ActivacionController` (real).

### Causas raíz

1. Logging operativo mezclado con auditoría en `logs`.
2. No hay capa de auditoría: 11 escrituras manuales dispersas.
3. Login API bypassa `Login/Failed` (`Hash::check` + `createToken`).
4. `created_by`/`set_by` se usan como evidencia; no hay `updated_by`/`deleted_by`.
5. FKs `cascade` destruyen evidencia (`pagos.created_by`, `cierres_caja.created_by`, `juego_auditoria.user_id`); `logs.user_id` anonimiza vía `set null`.
6. Esquema = vista, no evidencia: sin `request_id`, entidad, índices, scope ni integridad.
7. Lectura sin contrato, scope ni redacción de PII.
8. Semántica de fallo inconsistente (dentro/fuera de transacción).
9. Jobs que mutan negocio solo escriben al archivo de Laravel.
10. Sin retención/rotación; `logs` crece sin índices.

## 3. Scope

### In Scope

- Catálogo cerrado de eventos para **operadores** (CRUD usuarios/entidades/tasas/comisiones/cierre/juegos/límites/activaciones) y **jobs** (scrape de tasa, vencimientos).
- Tabla `audit_events` append-only + modelo + enum `AuditAction` + DTO + config.
- Capas de captura: `AuditContext`, `AuditRecorder`, `AuditRedactor`, trait `Auditable` opt-in, llamadas explícitas en servicios de dinero/seguridad.
- API de lectura con cursor pagination, filtros, scope `AlcanceService`, redacción y export CSV.
- Visor panel `/auditoria` + corrección de `/logs` (contrato, paginación, escape, banner "operativo").
- Modales de motivo (`reason_code` + nota) en acciones destructivas/cambio de acceso.
- Retención (12m hot + 5y archivo), integridad (grants/triggers; hash chain opcional), migración de `juego_auditoria` y FKs.
- Tests (100% del catálogo) y documentación nueva (`docs/dev/auditoria-sistema.md`).

### Out of Scope

- Login del admin legacy y `FetchResultsJob` (#81 los retira).
- Duplicar lecturas/ventas (`ticket.created`, `bet.created`, `pago.income_created`): ya son negocio.
- Detalle operativo de scrapers (siguen en `logs` operativo + archivo).
- Observer global sobre todos los modelos (captura opt-in + llamadas explícitas).
- WUs >800 líneas (se parten en tasks si hace falta).
- `backend/routes/web.php`, `Admin/**`, `resources/views/**`, `CheckRole.php`, `bootstrap/app.php`, docs del otro ciclo.

## 4. Conflict analysis

Matriz de propiedad vs `retiro-legacy-backend` (rama `feat/retiro-legacy-backend`, issues #81/#82):

| Archivo | Ciclo legacy | Este ciclo |
|---|---|---|
| `backend/routes/web.php` | Borra | **No tocar** |
| `backend/app/Http/Controllers/Admin/**` | Borra | **No tocar** |
| `backend/resources/views/**`, `welcome.blade.php` | Borra | **No tocar** |
| `backend/app/Jobs/FetchResultsJob.php` | Borra | **No tocar** |
| `backend/app/Http/Middleware/CheckRole.php` | Ya borrado | **No tocar** |
| `backend/bootstrap/app.php` | Modifica | **No tocar** (middleware directo en `routes/api.php`) |
| `docs/dev/estructura.md`, `arquitectura-backend.md`, `plugins.md`, `auditoria-logs.md` | Modifica | **No tocar** (docs nueva: `auditoria-sistema.md`) |
| `backend/tests/Feature/BackendSurfaceTest.php` | Crea | **No tocar** |

**Compartidos seguros** (este ciclo sí toca): `routes/api.php`, `app/Http/Controllers/Api/**`, `app/Services/**`, `app/Models/**`, `database/migrations/**`, `panel/**`, `tests/Feature/Auditoria*`.

**Cómo evita pisarlo**: (1) no audita el login legacy ni `FetchResultsJob`; (2) no toca `bootstrap/app.php` — el middleware de contexto se declara inline en `routes/api.php`; (3) no toca `auditoria-logs.md` (se reconcilia post-merge); (4) si legacy mergea antes, se sincroniza `main` en la tracker y solo se resuelve lo propio, sin reescribir su trabajo.

## 5. Capabilities

### New Capabilities

- `auditoria-eventos`: taxonomía cerrada (`AuditAction`), envelope común, catálogo de eventos por dominio y prioridad, capa de escritura (contexto, recorder, redactor, trait opt-in, llamadas explícitas, jobs).
- `auditoria-lectura`: API de lectura con cursor pagination, filtros, scope (`AlcanceService`), redacción PII, export CSV, visor panel `/auditoria`, corrección de `/logs`.
- `auditoria-integridad`: append-only (app + BD), retención/rotación (12m hot + 5y archivo), verificación (grants/triggers; hash chain opcional), migración de `juego_auditoria` y FKs.

### Modified Capabilities

- `catalogo-permisos`: añade permisos `view_audit` y `export_audit` (y su seed).
- `panel-jerarquia`: nueva página `/auditoria` y link en `AdminLayout`.
- `colecciones-api`: nuevos endpoints de lectura/export de auditoría.

## 6. Approach

1. **Tabla** `audit_events` append-only; `logs` queda como log operativo.
2. **`AuditContext`** captura request metadata y resuelve actor lazy (snapshot email/roles).
3. **`AuditRecorder`** como único punto de escritura: normaliza, redacta, versiona.
4. **Trait `Auditable`** opt-in para CRUD de alto valor (no observer global).
5. **Llamadas explícitas** en servicios de dinero/seguridad (cierre, pagos, anulaciones, clave, tasas, comisiones).
6. **Auth API**: eventos `auth.login_succeeded/failed`, `auth.logout`, `token_revoked`.
7. **Jobs de sistema**: `actor_type=system`, `job_id`, `queue` (tasa, vencimientos; sin `FetchResultsJob`).
8. **Fail-closed P0** dentro de la transacción; P1 best-effort; sin outbox en v1.
9. **Lectura** con cursor pagination, filtros, scope `AlcanceService`, redacción.
10. **Retención/integridad**: hot + archivo, grants/triggers, hash chain opcional.

## 7. Decisions

| Decisión | Default |
|---|---|
| Retención | 12 meses hot + 5 años archivo (confirmar legal) |
| Tamper evidence | Grants/triggers en BD v1; hash chain si se exige |
| Lectura | `view_audit` + `AlcanceService`; super_master global, master su banca |
| Fail-closed | P0 sí; P1 best-effort; sin outbox v1 |
| Motivo | Obligatorio en destructivas/cambio de acceso |
| Backfill | Solo eventos de negocio con actor; nunca scrapers |
| `/logs` | Operativo para super_master; `/auditoria` es el visor nuevo |
| Admin legacy / `FetchResultsJob` | Fuera de alcance (#81) |

## 8. Event catalog summary

Referencia completa en [`explore.md` §4](../../changes/auditoria-trazabilidad-89/explore.md) (~65 eventos). Resumen por dominio:

| Dominio | Eventos | P0 | P1/P2 |
|---|---|---|---|
| Autenticación, sesión y acceso (4.2) | 10 | 8 | 2 |
| Usuarios, roles y permisos (4.3) | 7 | 7 | 0 |
| Entidades y jerarquía (4.4) | 19 | 11 | 8 |
| Dinero y configuración (4.5) | 9 | 8 | 1 |
| Juegos, límites y config operativa (4.6) | 7 | 3 | 4 |
| Resultados, releases y auditoría (4.7) | 8 | 1 | 7 |
| Sistema y jobs (4.8) | 7 | 3 | 4 |

**Grupos P0** (fail-closed, motivo cuando aplica): (1) autenticación/sesión (login/logout/failed, reset de password, activación de taquilla, clave de cierre); (2) usuarios/roles/permisos (CRUD, cambio de rol/permisos, activate/deactivate/delete); (3) jerarquía (toggle/delete y cambio de banca/grupo); (4) dinero (tasas, comisiones, re-cierre, pagos, anulaciones); (5) juegos (toggle, premios, límites); (6) sistema/jobs (`exchange_rate.scraped`, `ticket.expired`, `bet.expired`).

## 9. Delivery

- Estrategia `feature-branch-chain`: rama tracker + una rama por WU; commits **locales** por WU (tests + docs con el código); sin push hasta el cierre.
- Gates de test ejecutados **por el usuario** (el agente se detiene antes de cada gate); el agente no ejecuta tests/migraciones/Pint/composer/npm/artisan.
- Cierre: suite completa → `sdd-verify` → `sdd-archive` → merge a `main` local → push a `main`.
- **Presupuesto**: 800 líneas por WU/PR. Si una WU de `explore.md` puede superarlo (p. ej. WU-10 panel ~800, WU-12 integridad ~800), se parte en tasks antes de implementar.

## 10. Risks & rollback

| Riesgo | Likelihood | Mitigación / rollback |
|---|---|---|
| Cambio de FKs `cascade` con datos existentes | Med | Migración separada con backup y staging; rollback revierte solo la migración |
| Fail-closed P0 acopla disponibilidad | Med | Alertar y medir; degradación controlada a best-effort por feature flag |
| PII en evento (email, MAC, fingerprint, motivos) | Med | Redactor central con reglas explícitas; revisión legal antes de activar |
| Panel (30 páginas): cablear modales sin romper flujos | Med | WUs de panel aisladas; tests de regresión por página |
| `main` con cambios sin commitear (taquilla/docs) | Med | No tocar; resolver antes del merge final |
| Legacy mergea durante nuestro ciclo | Med | Sincronizar `main` en tracker; resolver solo lo propio |

**Rollback**: la fase se revierte por WU (`git revert` del commit local de esa rama). El sistema es aditivo (nueva tabla + nuevas capas); no altera la escritura de negocio salvo donde se añaden llamadas explícitas. Revertir una WU no deja el modelo en estado inconsistente porque la captura es opt-in.

## 11. Acceptance criteria

- [ ] 100% del catálogo de eventos cubierto por tests (con actor, before/after, IP/UA, scope donde aplica).
- [ ] `audit_events` es append-only (sin UPDATE/DELETE desde la app ni BD en hot).
- [ ] Visor con filtros, cursor pagination, detalle con diff y export CSV; `/logs` corregido (contrato, paginación, escape).
- [ ] Motivo capturado y persistido en toda acción destructiva/cambio de acceso (no nullable).
- [ ] Retención verificada: hot→archivo, poda con evento `audit.retention_purged`.
- [ ] Cero referencias al admin legacy ni a `FetchResultsJob` en el nuevo sistema.
- [ ] `view_audit`/`export_audit` aplicados; scope jerárquico por `AlcanceService` sin fuga.

## 12. Forecast

~7.900–9.000 líneas con tests · 13 WUs · 22–30 días hábiles · cada WU ≤800 líneas.
