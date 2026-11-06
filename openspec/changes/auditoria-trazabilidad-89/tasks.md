# Tasks: Auditoría y trazabilidad (#89)

**Change**: `auditoria-trazabilidad-89` · Issue [#89](https://github.com/jmxsdev/lotto-app/issues/89)
**Base**: `main` @ `ea7d381` · **Rama tracker**: `feature/auditoria-trazabilidad-89`
**Tienda de artefactos**: OpenSpec + Engram (`sdd/auditoria-trazabilidad-89/tasks`)

---

## 1. Review Workload Forecast

| Campo | Valor |
|---|---|
| Líneas cambiadas estimadas | ~8.900–10.000 (código + tests + docs + colecciones) |
| Presupuesto por PR | 800 líneas (additions + deletions) |
| Riesgo sobre presupuesto de 400 líneas | **Alto** (muy por encima del umbral mínimo) |
| Chained PRs recomendados | Sí |
| Split sugerido | 22 WUs: 1a-1, 1a-2, 1a-3, 1b-1a, 1b-1b, 1b-1c, 1b-2, 2–9, 10a, 10b, 11a, 11b, 12a, 12b, 13 |
| Delivery strategy | `auto-chain` |
| Chain strategy | `feature-branch-chain` |
| Decisión necesaria antes de apply | **No** (preflight ya decidió `auto-chain` + `feature-branch-chain`) |
| Branch tracker | `feature/auditoria-trazabilidad-89` (draft, sin merge) |

```text
Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: High
```

### 1.1 Estimación por WU

| WU | Rama | Líneas est. | Depende de |
|---|---|---|---|
| 1a-1 | `feat/89-wu-1a-esquema-auditoria` | ~736 | — (tracker) |
| 1a-2 | `feat/89-wu-1a2-taxonomia` | ~577 | 1a-1 |
| 1a-3 | `feat/89-wu-1a3-dto` | ~443 | 1a-2 |
| 1b-1a | `feat/89-wu-1b1-contexto` | ~667 | 1a-3 |
| 1b-1b | `feat/89-wu-1b1b-scope-hash` | ~536 | 1b-1a |
| 1b-1c | `feat/89-wu-1b1c-redactor` | ~475 | 1b-1b |
| 1b-2 | `feat/89-wu-1b2-recorder` | ~650 | 1b-1c |
| 2 | `feat/89-wu-2-auth` | ~500 | 1b-2 |
| 3 | `feat/89-wu-3-usuarios` | ~700 | 2 |
| 4 | `feat/89-wu-4-tasas-comisiones` | ~500 | 3 |
| 5 | `feat/89-wu-5-cierre-pagos` | ~700 | 4 |
| 6 | `feat/89-wu-6-juegos-limites` | ~600 | 5 |
| 7 | `feat/89-wu-7-entidades` | ~700 | 6 |
| 8 | `feat/89-wu-8-jobs-resultados` | ~700 | 7 |
| 9 | `feat/89-wu-9-lectura-permisos` | ~600 | 8 |
| 10a | `feat/89-wu-10a-panel-auditoria` | ~450 | 9 |
| 10b | `feat/89-wu-10b-panel-detalle` | ~350 | 10a |
| 11a | `feat/89-wu-11a-motivos` | ~400 | 10b |
| 11b | `feat/89-wu-11b-logs-operativo` | ~250 | 11a |
| 12a | `feat/89-wu-12a-integridad-datos` | ~450 | 11b |
| 12b | `feat/89-wu-12b-retencion` | ~400 | 12a |
| 13 | `feat/89-wu-13-hardening` | ~500 | 12b |

**Topología** (Feature Branch Chain): cada rama hija se basa en su padre inmediato;
el tracker acumula todo y solo él mergea a `main` al cierre.

```text
main
└── feature/auditoria-trazabilidad-89            (tracker, draft/no-merge)
    └── feat/89-wu-1a-esquema-auditoria          (1a-1)
        └── feat/89-wu-1a2-taxonomia             (1a-2)
            └── feat/89-wu-1a3-dto               (1a-3)
                └── feat/89-wu-1b1-contexto      (1b-1a)
                    └── feat/89-wu-1b1b-scope-hash (1b-1b)
                        └── feat/89-wu-1b1c-redactor (1b-1c)
                            └── feat/89-wu-1b2-recorder (1b-2)
                                └── feat/89-wu-2-auth
                        └── feat/89-wu-3-usuarios
                            └── feat/89-wu-4-tasas-comisiones
                                └── feat/89-wu-5-cierre-pagos
                                    └── feat/89-wu-6-juegos-limites
                                        └── feat/89-wu-7-entidades
                                            └── feat/89-wu-8-jobs-resultados
                                                └── feat/89-wu-9-lectura-permisos
                                                    └── feat/89-wu-10a-panel-auditoria
                                                        └── feat/89-wu-10b-panel-detalle
                                                            └── feat/89-wu-11a-motivos
                                                                └── feat/89-wu-11b-logs-operativo
                                                                    └── feat/89-wu-12a-integridad-datos
                                                                        └── feat/89-wu-12b-retencion
                                                                            └── feat/89-wu-13-hardening
```

### 1.2 Regla de split (gatillo duro)

Cualquier WU que en el diff real (additions + deletions) **supere 800 líneas** se parte
**antes** de implementar, sin excepción:

| WU | Split al superar 800 |
|---|---|
| 3 | 3a usuarios (CRUD + rol + permisos + password) / 3b clave-cierre |
| 5 | 5a cierre + re-cierre / 5b pagos + anulaciones |
| 7 | 7a banca + grupo / 7b agencia + taquilla |
| 8 | 8a jobs de tasa/vencimientos / 8b resultados + releases |
| 9 | 9a API lectura + permisos / 9b export + colecciones |
| 13 | 13a limpieza de logs duplicados / 13b docs + suite + arquitectura |

Las ramas de split siguen el patrón `feat/89-wu-<n><letra>-<slug>`.

---

## 2. File ownership / conflict guard

### 2.1 Archivos propiedad de `retiro-legacy-backend` — **NO tocar**

| Archivo | Acción del ciclo legacy |
|---|---|
| `backend/routes/web.php` | Borra |
| `backend/app/Http/Controllers/Admin/**` | Borra |
| `backend/resources/views/**` (incl. `welcome.blade.php`) | Borra |
| `backend/app/Jobs/FetchResultsJob.php` | Borra |
| `backend/tests/Feature/FetchResultsJobTest.php` | Borra |
| `backend/app/Http/Middleware/CheckRole.php` | Ya borrado |
| `backend/bootstrap/app.php` | Modifica |
| `backend/tests/Feature/BackendSurfaceTest.php` | Crea |
| `docs/dev/estructura.md`, `docs/dev/arquitectura-backend.md`, `docs/dev/plugins.md`, `docs/dev/auditoria-logs.md` | Modifica |

### 2.2 Archivos compartidos seguros — este ciclo sí toca

- `backend/routes/api.php` (WU-1b middleware, WU-9 lectura, WU-11b `/logs`; cambios pequeños y secuenciales)
- `backend/app/Http/Controllers/Api/**`
- `backend/app/Services/**`
- `backend/app/Models/**`
- `backend/app/Providers/AppServiceProvider.php` (solo WU-1b, binding `scoped`)
- `backend/database/migrations/**` y `backend/database/sql/**`
- `backend/database/seeders/RolesAndPermissionsSeeder.php` (solo WU-9)
- `panel/**`
- `tests/Feature/Auditoria*` (nuevos), `collections/` (WU-9)
- `docs/dev/auditoria-sistema.md` (nuevo), `docs/dev/permisos-capabilities.md` (WU-9)

### 2.3 Regla de sincronización con `main`

- El middleware de contexto se registra **directo en `routes/api.php`** por referencia de clase
  (`CaptureAuditContext::class`); **nunca** en `bootstrap/app.php` ni con alias global.
- Si `retiro-legacy-backend` mergea antes del cierre: `git fetch` → merge/rebase de `main` en la
  tracker → resolver **solo archivos propios**. No se reescriben los borrados de `web.php`,
  `Admin/**`, `views/**`, `FetchResultsJob.php`, `CheckRole.php` ni `bootstrap/app.php`.
- Si hay conflicto en `routes/api.php`, se conservan las rutas de auditoría y los cambios del otro
  ciclo; la referencia por clase sigue siendo válida aunque cambien los aliases de `bootstrap/app.php`.
- `docs/dev/auditoria-logs.md` se reconcilia **después** del merge legacy; este ciclo no lo toca.
- Los cambios sin commitear de `main` (taquilla/docs) **no se tocan**; se coordinan con su dueño
  antes del merge final.

---

## 3. Fases y Work Units

> El agente implementa una WU y **no ejecuta** tests, migraciones, Pint, composer, npm ni artisan.
> Cada WU entrega los comandos exactos y el usuario los ejecuta (ver §4 Protocolo de test gate).

### Fase 1 — Fundación (esquema + capa de captura)

#### 1.1 — WU-1a · Esquema, enums, modelo y config

**Objetivo y alcance**: crear la tabla append-only `audit_events` y el estado de cadena, el enum
cerrado `AuditAction` con taxonomía `domain.verb` (prioridad, política de motivo, entidad), el DTO
inmutable, el value object de scope, el cast UTC, el modelo `AuditEvent` append-only y
`config/audit.php`. Es puramente aditivo: sin llamadas de negocio.

> **Split real aplicado (presupuesto 800)**: la implementación base dio **1.746 líneas**, así que se
> dividió en tres slices antes del gate:
>
> | Slice | Contenido | Líneas | Rama |
> |---|---|---|---|
> | **1a-1** | Migraciones, `AuditEvent`, `UtcDateTime`, `AuditAppendOnlyException`, `config/audit.php`, `AuditPriority`, `AuditActorType`, `AuditReason`, `AuditScope` + tests de scope/cast/modelo | ~736 | `feat/89-wu-1a-esquema-auditoria` |
> | **1a-2** | `AuditAction` (67 casos) + `ReasonPolicy` + `AuditActionTest` | ~577 | `feat/89-wu-1a2-taxonomia` |
> | **1a-3** | `AuditEventData` + `AuditEventDataTest` | ~443 | `feat/89-wu-1a3-dto` |
>
> **Estado:** 1a-1 implementado (20 tests, 47 assertions); 1a-2 implementado (60 tests, 516 assertions); 1a-3 implementado (12 tests, 49 assertions); 1b-1a implementado (15 tests, 49 assertions); 1b-1b implementado (19 tests, 27 assertions) el 2026-11-05.
>
> Gates por slice:
> ```bash
> # 1a-1
> cd backend && php artisan test --filter='AuditScopeTest|UtcDateTimeTest|AuditEventTest'
> # 1a-2
> cd backend && php artisan test --filter='AuditActionTest'
> # 1a-3
> cd backend && php artisan test --filter='AuditEventDataTest'
> # Pint en cada slice
> cd backend && ./vendor/bin/pint --test
> ```

**Archivos** (crear):
- `backend/database/migrations/2026_11_05_000001_create_audit_events_table.php`
- `backend/database/migrations/2026_11_05_000002_create_audit_chain_state_table.php`
- `backend/app/Audit/AuditAction.php`, `AuditPriority.php`, `AuditReason.php`, `ReasonPolicy.php`, `AuditActorType.php`
- `backend/app/Audit/AuditEventData.php`, `AuditScope.php`
- `backend/app/Audit/Casts/UtcDateTime.php`
- `backend/app/Models/AuditEvent.php`
- `backend/app/Exceptions/AuditAppendOnlyException.php`
- `backend/config/audit.php`

**Tareas ordenadas**:
- [ ] Crear migración `audit_events` con columnas, `event_uuid` unique y los 6 índices de la §3.2 del design
- [ ] Crear migración `audit_chain_state` (fila única `id=1`)
- [ ] Crear enum `AuditAction` con los ~67 casos `domain.verb` + `domain()/priority()/reasonPolicy()/entityType()/requiresReason()/isFailClosed()`
- [ ] Crear `AuditPriority`, `AuditReason`, `ReasonPolicy`, `AuditActorType`
- [ ] Crear `AuditEventData` (inmutable, `forModel()`, `withReason()`, `toRow()`) y `AuditScope` (`merge()`, `isEmpty()`)
- [ ] Crear cast `UtcDateTime` y modelo `AuditEvent` (`$timestamps=false`, `updating/deleting` lanzan `AuditAppendOnlyException`)
- [ ] Crear `config/audit.php` (flags, retención, export, reasons, pii, logs)

**Tests a escribir** (no ejecutados por el agente):
- `backend/tests/Unit/Audit/AuditActionTest.php` — prioridad/motivo/entidad por acción; rechazo de strings libres
- `backend/tests/Unit/Audit/AuditEventDataTest.php` — normalización, before/after, `forModel`
- `backend/tests/Unit/Audit/AuditScopeTest.php` — merge/empty/toArray
- `backend/tests/Unit/Audit/UtcDateTimeTest.php` — ISO-8601 UTC
- `backend/tests/Unit/Models/AuditEventTest.php` — update/delete lanzan `AuditAppendOnlyException`

**Verificación (usuario)**:
```bash
cd backend && php artisan test --filter='AuditActionTest|AuditEventDataTest|AuditScopeTest|UtcDateTimeTest|AuditEventTest'
```

**Aceptación**: tabla e índices creados; enum cerrado versionado; modelo append-only; cast UTC;
sin `created_at/updated_at`; `audit_chain_state` creado; config con defaults.

**Commit local**: `feat(auditoria): esquema append-only de audit_events, enums y config`
**Rollback**: `git revert` del commit (tablas aditivas, sin impacto en negocio).
**Líneas**: ~450 · **Rama**: `feat/89-wu-1a-esquema-auditoria` · **Depende**: — (tracker)

---

#### 1.2 — WU-1b · Contexto, recorder, redactor, trait, middleware y binding

**Objetivo y alcance**: capa de captura completa: `AuditContext` (request metadata + actor lazy +
sistema), `AuditRecorder` (único punto de escritura; P0/P1; transacción; idempotencia; hash opcional),
`AuditRedactor` (PII determinista), `AuditScopeResolver`, `AuditChainHasher`, trait `Auditable`
(opt-in), middleware `CaptureAuditContext` (registrado por clase en `routes/api.php`), excepciones y
binding `scoped` en `AppServiceProvider`.

> **Split preventivo (presupuesto 800):**
>
> | Slice | Contenido | Líneas | Rama |
> |---|---|---|---|
> | **1b-1a** | `AuditContext` + `AuditContextTest` | ~667 | `feat/89-wu-1b1-contexto` |
> | **1b-1b** | `AuditScopeResolver`, `AuditChainHasher` + `AuditScopeResolverTest`, `AuditChainHasherTest` | ~536 | `feat/89-wu-1b1b-scope-hash` |
> | **1b-1c** | `AuditRedactor` + `AuditRedactorTest` | ~475 | `feat/89-wu-1b1c-redactor` |
> | **1b-2** | `AuditRecorder`, trait `Auditable`, `CaptureAuditContext`, excepciones, binding `AppServiceProvider`, `routes/api.php` + tests de recorder/middleware/arquitectura | ~650 | `feat/89-wu-1b2-recorder` |
>
> Gates (Pint con path-scoped para no evaluar slices futuros que aún están sin commit):
> ```bash
> # 1b-1a
> cd backend && php artisan test --filter='AuditContextTest'
> cd backend && ./vendor/bin/pint --test app/Audit/AuditContext.php tests/Unit/Audit/AuditContextTest.php
> # 1b-1b
> cd backend && php artisan test --filter='AuditScopeResolverTest|AuditChainHasherTest'
> cd backend && ./vendor/bin/pint --test app/Audit/AuditScopeResolver.php app/Audit/AuditChainHasher.php tests/Unit/Audit/AuditScopeResolverTest.php tests/Unit/Audit/AuditChainHasherTest.php
> # 1b-1c
> cd backend && php artisan test --filter='AuditRedactorTest'
> cd backend && ./vendor/bin/pint --test app/Audit/AuditRedactor.php tests/Unit/Audit/AuditRedactorTest.php
> # 1b-2
> cd backend && php artisan test --filter='AuditRecorderTest|AuditoriaMiddlewareTest|AuditArchitectureTest'
> cd backend && ./vendor/bin/pint --test
> ```

**Archivos**:
- Crear: `backend/app/Audit/{AuditContext,AuditRecorder,AuditRedactor,AuditScopeResolver,AuditChainHasher,Auditable}.php`
- Crear: `backend/app/Http/Middleware/CaptureAuditContext.php`
- Crear: `backend/app/Exceptions/{AuditWriteException,AuditValidationException}.php`
- Modificar: `backend/app/Providers/AppServiceProvider.php` (binding `scoped`)
- Modificar: `backend/routes/api.php` (middleware `CaptureAuditContext::class` en el grupo `v1`)

**Tareas ordenadas**:
- [ ] `AuditContext`: `captureRequest`, `requestId`, `ip`, `userAgent`, `route`, `method`, `client`, `reason`, `actor`, `resolveActor`, `forSystem`, `reset`
- [ ] `AuditRedactor`: `redact`, `redactValue`, `maskEmail`, `maskMac`, `hashFingerprint`, `redactReason` (reglas deterministas)
- [ ] `AuditScopeResolver` + `AuditChainHasher` (canonical JSON + hash chain bajo `lockForUpdate`)
- [ ] `AuditRecorder`: `record`, `critical`, `bestEffort`, `recordMany`, `created/updated/deleted`; fail-closed P0 con `DB::transactionLevel()>0`
- [ ] Trait `Auditable`: `$auditEvents/$auditExclude/$auditRedact/$auditActionMap`, `auditBefore/auditAfter/auditSnapshot`
- [ ] `CaptureAuditContext` (propaga `X-Request-Id`; no resuelve actor) + registro por clase en `routes/api.php`
- [ ] Excepciones `AuditWriteException` (500) y `AuditValidationException` (422) + render JSON
- [ ] Binding `scoped` en `AppServiceProvider::register()` (sin observer global)

**Tests a escribir**:
- `backend/tests/Unit/Audit/AuditRecorderTest.php` — P0 fail-closed, P1 best-effort, idempotencia, transacción
- `backend/tests/Unit/Audit/AuditContextTest.php` — lazy actor, request-id, `forSystem`
- `backend/tests/Unit/Audit/AuditRedactorTest.php` — email/MAC/fingerprint/password/token; idempotencia
- `backend/tests/Unit/Audit/AuditChainHasherTest.php` — canonical JSON y hash
- `backend/tests/Feature/AuditoriaMiddlewareTest.php` — `X-Request-Id`, context poblado, route/method
- `backend/tests/Feature/AuditArchitectureTest.php` — sin observer global; única escritura vía recorder

**Verificación (usuario)**:
```bash
cd backend && php artisan test --filter='AuditRecorderTest|AuditContextTest|AuditRedactorTest|AuditChainHasherTest|AuditoriaMiddlewareTest|AuditArchitectureTest'
```

**Aceptación**: contexto lazy, `X-Request-Id`, recorder único, redactor determinista, P0/P1,
trait opt-in, middleware por clase (sin tocar `bootstrap/app.php`), sin observer global.

**Commit local**: `feat(auditoria): capa de captura (contexto, recorder, redactor, trait, middleware)`
**Rollback**: `git revert` (capa aditiva; no altera rutas de negocio).
**Líneas**: ~650 · **Rama**: `feat/89-wu-1b-captura-auditoria` · **Depende**: 1a

---

### Fase 2 — Implementación núcleo (captura por dominio)

#### 2.1 — WU-2 · Auth API + activación/dispositivo

**Objetivo y alcance**: auditar `POST /api/v1/login` (éxito/fallo), `POST /api/v1/logout`,
revocación de token; activación de taquilla (éxito/fallo/MAC reasignada) y verificación de
dispositivo. Sin auditar el login legacy.

**Archivos**:
- Modificar: `backend/app/Http/Controllers/Api/AuthController.php`, `ActivacionController.php`, `DispositivoController.php`
- Crear: `backend/tests/Feature/AuditoriaAuthTest.php`, `backend/tests/Feature/AuditoriaActivacionTest.php`

**Tareas ordenadas**:
- [ ] `AuthController::login`: envolver `Hash::check` + `createToken` + auditoría en `DB::transaction`; emitir `auth.login_succeeded` / `auth.login_failed` (motivo automático)
- [ ] Fail-closed: si `auth.login_succeeded` falla, rollback (token no persiste) y 500 (no 401)
- [ ] `AuthController::logout`: `auth.logout` P0 + `auth.token_revoked` P1 best-effort
- [ ] `ActivacionController::activar`: transacción única; `taquilla.activation_succeeded/failed/mac_reassigned` (MAC/código enmascarados)
- [ ] `DispositivoController::verificar`: `device.verification_failed` P1 best-effort
- [ ] Deshacer doble codificación de `details` en activación (log legacy normalizado)

**Tests a escribir**:
- `AuditoriaAuthTest` — login éxito/fallo/logout/token_revoked; rollback del token ante fallo de audit; motivos automáticos
- `AuditoriaActivacionTest` — activación éxito/fallo; MAC reasignada; device.verification_failed

**Verificación (usuario)**:
```bash
cd backend && php artisan test --filter='AuditoriaAuthTest|AuditoriaActivacionTest'
```

**Aceptación**: eventos P0 de auth dentro de transacción; token no persiste si audit falla;
activación auditada con MAC/código enmascarados; cero referencias al login legacy.

**Commit local**: `feat(auditoria): auditar login/logout API y activación de taquilla`
**Rollback**: `git revert`; el flujo de auth vuelve a su estado pre-auditoría.
**Líneas**: ~500 · **Rama**: `feat/89-wu-2-auth` · **Depende**: 1b

---

#### 2.2 — WU-3 · Usuarios, roles, permisos, password y clave

**Objetivo y alcance**: auditar CRUD de usuarios, cambio de rol, permisos efectivos, reset de
password por admin, activate/deactivate/delete y actualización de clave de cierre. Reusar/refinar
`user.role_changed` existente.

**Archivos**:
- Modificar: `backend/app/Http/Controllers/Api/UserController.php`, `ClaveCierreController.php`
- Modificar: `backend/app/Models/User.php` (trait `Auditable`)
- Crear: `backend/tests/Feature/AuditoriaUsuariosTest.php`, `backend/tests/Feature/ClaveCierreTest.php`
- Modificar: `backend/tests/Feature/AuditoriaRolTest.php` (reescribir al nuevo sistema)

**Tareas ordenadas**:
- [ ] Aplicar trait `Auditable` a `User` con `$auditExclude` (`password`, `remember_token`) y `$auditRedact`
- [ ] `UserController::store/update/destroy`: envolver mutación + auditoría en `DB::transaction`
- [ ] Emitir `user.created/updated/role_changed/permissions_changed/activated/deactivated/deleted`
- [ ] `user.deleted` snapshot completo; `user.deactivated` y `role/permissions_changed` requieren motivo
- [ ] `user.password_reset_by_admin`: solo `{password_changed:true}` (nunca hash)
- [ ] `ClaveCierreController::update`: `clave_cierre.updated` P0, sin capturar la clave

**Tests a escribir**:
- `AuditoriaUsuariosTest` — CRUD, rol/permisos, activate/deactivate/delete, password (hash ausente), motivo requerido
- `ClaveCierreTest` — clave actualizada sin exponer clave
- `AuditoriaRolTest` — regresión del rol al nuevo envelope

**Verificación (usuario)**:
```bash
cd backend && php artisan test --filter='AuditoriaUsuariosTest|AuditoriaRolTest|ClaveCierreTest'
```

**Aceptación**: todo CRUD de usuario auditado con before/after y motivo donde aplica; hash/clave nunca en evento.

**Commit local**: `feat(auditoria): auditar CRUD de usuarios, roles, permisos, password y clave de cierre`
**Rollback**: `git revert`; el negocio de usuarios no cambia de contrato.
**Líneas**: ~700 · **Rama**: `feat/89-wu-3-usuarios` · **Depende**: 2
**Split**: si el diff supera 800 → 3a usuarios / 3b clave-cierre.

---

#### 2.3 — WU-4 · Tasas de cambio y comisiones

**Objetivo y alcance**: auditar creación/actualización/activación de tasas de cambio y
actualización de defaults de comisiones (dominio dinero, P0).

**Archivos**:
- Modificar: `backend/app/Http/Controllers/Api/ExchangeRateController.php`, `ComisionController.php`
- Modificar: `backend/app/Models/ExchangeRate.php`, `ComisionDefault.php` (trait `Auditable`)
- Crear: `backend/tests/Feature/AuditoriaDineroTest.php`; ajustar `ExchangeRateTest` si aplica

**Tareas ordenadas**:
- [ ] Aplicar trait `Auditable` a `ExchangeRate` y `ComisionDefault`
- [ ] Emitir `exchange_rate.created/updated/activated` (before/after de `rate`, `is_active`) en transacción P0
- [ ] Emitir `commission_defaults.updated` con before/after
- [ ] Registro con motivo opcional; sin secretos

**Tests a escribir**:
- `AuditoriaDineroTest` — create/update/activate de tasa; comisiones; P0 en transacción
- Ajustes de `ExchangeRateTest` si cuentan eventos

**Verificación (usuario)**:
```bash
cd backend && php artisan test --filter='AuditoriaDineroTest|ExchangeRateTest|ComisionTest'
```

**Aceptación**: tasas y comisiones auditadas con scope `null` (global, solo super_master en lectura) y P0 fail-closed.

**Commit local**: `feat(auditoria): auditar tasas de cambio y comisiones`
**Rollback**: `git revert`.
**Líneas**: ~500 · **Rama**: `feat/89-wu-4-tasas-comisiones` · **Depende**: 3

---

#### 2.4 — WU-5 · Cierre, re-cierre, pagos y anulaciones

**Objetivo y alcance**: auditar creación de cierre, re-cierre (destructivo, motivo requerido),
pago de premio, anulación de ticket y de apuesta. Llamadas explícitas en los servicios de dinero.

**Archivos**:
- Modificar: `backend/app/Services/CierreService.php`, `TicketAnulacionService.php`
- Modificar: `backend/app/Http/Controllers/Api/PagoController.php`, `TicketController.php`, `CierreController.php` (si aplica)
- Modificar: `backend/app/Models/{CierreCaja,Pago,Ticket,Apuesta}.php` (trait `Auditable` donde aporte)
- Crear: `backend/tests/Feature/AuditoriaCierreTest.php`, `backend/tests/Feature/AuditoriaPagosTest.php`
- Modificar: `backend/tests/Feature/PagoDevolucionAnulacionTest.php` (si cuenta eventos)

**Tareas ordenadas**:
- [ ] `CierreService::crearCierre`: añadir `?string $reasonCode = null, ?string $reasonText = null`; emitir `cierre.created` P1
- [ ] `CierreService::recerrar`: emitir `cierre.reclosed` P0 con snapshot completo + motivo requerido (rollback si falta)
- [ ] `PagoController::store`: emitir `pago.prize_paid` P0 con montos/referencias
- [ ] `TicketAnulacionService::anularTicket`: mapear `motivo` a `reason_text`; emitir `ticket.voided` y `bet.voided` P0 con before/after
- [ ] `TicketController` anulación: aceptar motivo por body y query; 422 si falta en anulación

**Tests a escribir**:
- `AuditoriaCierreTest` — cierre creado; re-cierre con/without motivo (422 + rollback)
- `AuditoriaPagosTest` — premio pagado; anulación ticket/apuesta con before/after
- `PagoDevolucionAnulacionTest` — regresión de devolución/anulación

**Verificación (usuario)**:
```bash
cd backend && php artisan test --filter='AuditoriaCierreTest|AuditoriaPagosTest|PagoDevolucionAnulacionTest'
```

**Aceptación**: re-cierre, pago y anulaciones auditados con motivo; P0 rollback de negocio si audit falla.

**Commit local**: `feat(auditoria): auditar cierre, re-cierre, pagos y anulaciones`
**Rollback**: `git revert`; los servicios no cambian contrato salvo parámetros aditivos.
**Líneas**: ~700 · **Rama**: `feat/89-wu-5-cierre-pagos` · **Depende**: 4
**Split**: si supera 800 → 5a cierre/re-cierre / 5b pagos/anulaciones.

---

#### 2.5 — WU-6 · Juegos, premios, límites y ventana de vencimiento

**Objetivo y alcance**: auditar update/toggle de juegos, actualización de premios, límites (simple,
batch, borrado) y ventana de vencimiento de apuestas. Deja de escribir en `juego_auditoria`.

**Archivos**:
- Modificar: `backend/app/Http/Controllers/Api/JuegoController.php`, `ConfiguracionController.php`
- Modificar: `backend/app/Services/PremiosConfigService.php`
- Modificar: `backend/app/Models/{Juego,JuegoLimite}.php` (trait `Auditable`)
- Crear: `backend/tests/Feature/AuditoriaJuegosTest.php`; ajustar `JuegoPremiosApiTest`/`LimitesTest`

**Tareas ordenadas**:
- [ ] Emitir `game.updated` P1 y `game.toggled` P0 (trait) en `JuegoController`
- [ ] Emitir `game.prizes_updated` P0 (motivo requerido) en `PremiosConfigService`
- [ ] Emitir `game.limits_updated` / `game.limits_batch_updated` / `game.limit_deleted`
- [ ] `ConfiguracionController`: `config.bet_expiration_window_updated` P1 con before/after
- [ ] Dejar de escribir en `JuegoAuditoria` (la escritura migra a `AuditRecorder`; la migración de datos es WU-12a)

**Tests a escribir**:
- `AuditoriaJuegosTest` — toggle/update/premios/límites/ventana; scope y before/after
- Ajustes en `JuegoPremiosApiTest` y `LimitesTest` si cuentan eventos

**Verificación (usuario)**:
```bash
cd backend && php artisan test --filter='AuditoriaJuegosTest|JuegoPremiosApiTest|LimitesTest'
```

**Aceptación**: eventos de juego con scope correcto; `game.prizes_updated` y `game.limit_deleted` con motivo; `juego_auditoria` sin escrituras nuevas.

**Commit local**: `feat(auditoria): auditar juegos, premios, límites y ventana de vencimiento`
**Rollback**: `git revert`.
**Líneas**: ~600 · **Rama**: `feat/89-wu-6-juegos-limites` · **Depende**: 5

---

#### 2.6 — WU-7 · Entidades y jerarquía

**Objetivo y alcance**: auditar CRUD/toggle/delete y cambios de banca/grupo en banca, grupo,
agencia y taquilla (P0 en toggle/delete/cambios; P1 en create/update).

**Archivos**:
- Modificar: `backend/app/Http/Controllers/Api/{BancaController,GrupoController,AgenciaController,TaquillaController}.php`
- Modificar: `backend/app/Models/{Banca,Grupo,Agencia,Taquilla}.php` (trait `Auditable`)
- Crear: `backend/tests/Feature/AuditoriaEntidadesTest.php`; ajustar `GestionEntidadesApiTest`

**Tareas ordenadas**:
- [ ] Aplicar trait `Auditable` a los 4 modelos; derivar `banca_id`/`grupo_id` en el scope
- [ ] Emitir `*.created/updated` (P1) y `*.toggled/deleted` (P0, motivo requerido)
- [ ] Emitir `grupo.banca_changed`, `agencia.banca_changed`, `taquilla.group_changed` P0 (before/after)
- [ ] Envolver toggle/destroy en `DB::transaction` cuando emiten P0

**Tests a escribir**:
- `AuditoriaEntidadesTest` — CRUD/toggle/delete y cambios de jerarquía; scope correcto; motivo en destructivas
- Ajustes en `GestionEntidadesApiTest` si cuentan eventos

**Verificación (usuario)**:
```bash
cd backend && php artisan test --filter='AuditoriaEntidadesTest|GestionEntidadesApiTest'
```

**Aceptación**: jerarquía auditada con scope; cambios de banca/grupo con before/after; destructivas con motivo.

**Commit local**: `feat(auditoria): auditar entidades y jerarquía (banca/grupo/agencia/taquilla)`
**Rollback**: `git revert`.
**Líneas**: ~700 · **Rama**: `feat/89-wu-7-entidades` · **Depende**: 6
**Split**: si supera 800 → 7a banca/grupo / 7b agencia/taquilla.

---

#### 2.7 — WU-8 · Jobs de sistema, resultados y releases

**Objetivo y alcance**: auditar jobs de sistema (tasa scrape, vencimientos) con `actor_type=system`,
ingesta de resultados y publicación de releases. Sin `FetchResultsJob`.

**Archivos**:
- Modificar: `backend/app/Jobs/{ScrapeExchangeRateJob,ExpireUnclaimedPrizesJob,MarcarApuestasVencidasJob,ScrapeSourceJob,ScrapeResultsJob}.php`
- Modificar: `backend/app/Services/ApuestaService.php` (settlement, si aplica)
- Modificar: `backend/app/Http/Controllers/Api/ResultadoController.php`
- Modificar: `backend/app/Console/Commands/ReleasePublishCommand.php`
- Crear: `backend/tests/Feature/AuditoriaJobsTest.php`; ajustar `ExchangeRateJobTest`/`ExpireUnclaimedPrizesJobTest`

**Tareas ordenadas**:
- [ ] `ScrapeExchangeRateJob`: transacción única; `exchange_rate.scraped` P0 (before/after); sin evento si la tasa no cambia
- [ ] `ExpireUnclaimedPrizesJob`: una transacción por ticket; `ticket.expired` + `bet.expired` P0 por lote
- [ ] `MarcarApuestasVencidasJob`: chunks con `recordMany('bet.expired')` P0
- [ ] `ScrapeSourceJob`/`ScrapeResultsJob`: `draw.result_ingested` / `draw.settlement_completed` P1 best-effort
- [ ] `ResultadoController`: `draw.scrape_requested` / `draw.scrape_all_requested` P1
- [ ] `ReleasePublishCommand`: `release.published` P1 con versión/hash
- [ ] Cada job: `AuditContext::forSystem()` + `reset()` en `finally`

**Tests a escribir**:
- `AuditoriaJobsTest` — actor system + job_id + queue; batch consistente; P0 rollback de lote; sin `FetchResultsJob`
- Ajustes en `ExchangeRateJobTest`/`ExpireUnclaimedPrizesJobTest`

**Verificación (usuario)**:
```bash
cd backend && php artisan test --filter='AuditoriaJobsTest|ExchangeRateJobTest|ExpireUnclaimedPrizesJobTest|MarcarApuestasVencidasJobTest'
```

**Aceptación**: jobs auditados con `actor_type=system`, `job_id`, `queue`; vencimientos P0; ingesta/resultados P1; cero referencias a `FetchResultsJob`.

**Commit local**: `feat(auditoria): auditar jobs de sistema, resultados y releases`
**Rollback**: `git revert`.
**Líneas**: ~700 · **Rama**: `feat/89-wu-8-jobs-resultados` · **Depende**: 7
**Split**: si supera 800 → 8a jobs tasa/vencimientos / 8b resultados/releases.

---

### Fase 3 — Integración (lectura, panel, motivos, logs)

#### 3.1 — WU-9 · API de lectura + permisos + export

**Objetivo y alcance**: `GET /api/v1/audit-events` con cursor pagination, filtros y scope
`AlcanceService`; `GET /api/v1/audit-events/export` CSV; permisos `view_audit`/`export_audit`;
seed y colecciones. `AuthController` añade `permissions` al payload.

**Archivos**:
- Crear: `backend/app/Services/{AuditQueryService,AuditExportService}.php`
- Crear: `backend/app/Http/Controllers/Api/AuditEventController.php`
- Modificar: `backend/app/Services/AlcanceService.php` (`applyToAuditoria`, contrato `AUDITORIA`)
- Modificar: `backend/routes/api.php` (rutas de lectura/export)
- Modificar: `backend/database/seeders/RolesAndPermissionsSeeder.php` (36/35)
- Modificar: `backend/app/Http/Controllers/Api/AuthController.php` (payload `permissions`)
- Crear: `backend/tests/Feature/{AuditoriaLecturaTest,AuditoriaExportTest}.php`; modificar `PermissionCatalogTest`
- Crear: `collections/Auditoria/` (requests lectura/export) + `docs/dev/permisos-capabilities.md`

**Tareas ordenadas**:
- [ ] `AuditQueryService`: `query/page/toArray` con filtros, cursor y redacción de lectura
- [ ] `AlcanceService::applyToAuditoria` (super_master global; master su banca; `null` scope ⇒ super_master)
- [ ] `AuditEventController::index` con `permission:view_audit`; validación de filtros (422 ante desconocidos)
- [ ] `AuditExportService::stream` con `export_audit`, tope `max_rows`, escape de fórmulas, emite `audit.exported`
- [ ] Seeder: añadir `view_audit`/`export_audit` a `master`; `forgetCachedPermissions()`; actualizar conteos 36/35
- [ ] `AuthController`: exponer `permissions` (aditivo) en login y `GET /user`
- [ ] Colecciones `Auditoria/` fieles a `routes/api.php`

**Tests a escribir**:
- `AuditoriaLecturaTest` — cursor/filtros/per_page/orden/403/scope/redacción; nunca `User` completo
- `AuditoriaExportTest` — CSV columnas exactas, tope, escape, `audit.exported`
- `PermissionCatalogTest` — conteos 36/35 y guard `api` preservados

**Verificación (usuario)**:
```bash
cd backend && php artisan test --filter='AuditoriaLecturaTest|AuditoriaExportTest|PermissionCatalogTest'
```

**Aceptación**: API con cursor/filtros/scope/redacción; export CSV con tope; permisos 36/35; colecciones en paridad.

**Commit local**: `feat(auditoria): API de lectura/export, permisos view_audit/export_audit y colecciones`
**Rollback**: `git revert`; revierte rutas y permisos sin afectar captura.
**Líneas**: ~600 · **Rama**: `feat/89-wu-9-lectura-permisos` · **Depende**: 8
**Split**: si supera 800 → 9a lectura+permisos / 9b export+colecciones.

---

#### 3.2 — WU-10a · Panel `/auditoria`: página, filtros, tabla, cursor y escape

**Objetivo y alcance**: página base del visor con filtros, tabla, cursor pagination y utilidades de
escape; sin detalle ni export (10b).

**Archivos**:
- Crear: `panel/src/pages/auditoria.astro`, `panel/src/utils/escape.ts`
- Modificar: `panel/src/utils/api.ts` (`toQuery()`, helpers de permisos)

**Tareas ordenadas**:
- [ ] `escape.ts`: `esc()`, `setText()`, `cell()` (regla `textContent`)
- [ ] `api.ts`: `toQuery()` para filtros; helper `can()` / permisos
- [ ] `auditoria.astro`: estados loading/empty/error/403; filtros (domain/action/actor/entity/from/to/request_id/per_page)
- [ ] Tabla con cursor: “Siguiente” push `next_cursor`; “Anterior” pop del stack local; orden `occurred_at DESC, id DESC`
- [ ] Todos los valores vía `textContent`/`createTextNode`; sin `innerHTML` con datos de usuario

**Tests (estático/panel)**:
- Revisión estática: sin `innerHTML` con datos de usuario en `auditoria.astro`; `textContent` obligatorio

**Verificación (usuario)**:
```bash
cd panel && npm run build
```

**Aceptación**: página renderiza con filtros y cursor; build verde; cero XSS por `innerHTML`.

**Commit local**: `feat(panel): visor /auditoria con filtros, tabla, cursor y escape`
**Rollback**: `git revert`; la página no altera rutas previas.
**Líneas**: ~450 · **Rama**: `feat/89-wu-10a-panel-auditoria` · **Depende**: 9

---

#### 3.3 — WU-10b · Panel `/auditoria`: detalle diff, export, nav y estados

**Objetivo y alcance**: detalle con diff before/after, export CSV, link de navegación por permiso
y estados completos.

**Archivos**:
- Modificar: `panel/src/pages/auditoria.astro`
- Modificar: `panel/src/utils/api.ts` (`apiDownload()` con auth)
- Modificar: `panel/src/layouts/AdminLayout.astro` (link `/auditoria` por `view_audit`)
- Modificar: `panel/src/pages/login.astro` (guardar `permissions` en `panel_user`)

**Tareas ordenadas**:
- [ ] Detalle drawer con diff `before`/`after` en `<pre>` + `textContent` + `JSON.stringify(..., null, 2)`
- [ ] “Copiar JSON” con `navigator.clipboard.writeText`
- [ ] Export con `apiDownload()` (CSV, auth, toast éxito/error, botón deshabilitado durante descarga)
- [ ] `AdminLayout`: link “Auditoría” visible con `can('view_audit')`; fallback `master|super_master`
- [ ] `login.astro`: persistir `permissions` en `panel_user` (aditivo)

**Tests (estático/panel)**:
- Revisión estática: link por permiso; `apiDownload` envía auth

**Verificación (usuario)**:
```bash
cd panel && npm run build
```

**Aceptación**: detalle con diff, export y navegación por permiso; build verde.

**Commit local**: `feat(panel): detalle con diff, export y navegación del visor de auditoría`
**Rollback**: `git revert`.
**Líneas**: ~350 · **Rama**: `feat/89-wu-10b-panel-detalle` · **Depende**: 10a

---

#### 3.4 — WU-11a · Modal de motivo + cableado en páginas

**Objetivo y alcance**: modal reutilizable de motivo y su cableado en acciones destructivas/cambios
de acceso (listas y detalles), cuadre, clave-cierre, tasas, comisiones y juegos.

**Archivos**:
- Crear: `panel/src/utils/reason-modal.ts`
- Modificar: `panel/src/pages/{usuarios,bancas,grupos,agencias,taquillas}.astro`
- Modificar: `panel/src/pages/{bancas,grupos,agencias,taquillas}/detalle.astro`
- Modificar: `panel/src/pages/{cuadre,clave-cierre,tasas,comisiones,juegos,limites}.astro`
- Modificar: `panel/src/utils/api.ts` (soporte query/body para motivo)

**Tareas ordenadas**:
- [ ] `reason-modal.ts`: `openReasonModal()` (select de códigos, `reason_text` ≤500, `required`, cierre Escape/overlay ⇒ `null`)
- [ ] Cablear delete/toggle/rol/password en listas y detalles (DELETE query / PATCH-PUT body)
- [ ] Cablear motivo en re-cierre, clave, tasas, comisiones, premios y límites
- [ ] El modal no hace la petición: devuelve `{reason_code, reason_text}` y el llamador lo añade

**Tests (estático/panel)**:
- Revisión estática: `reason-modal` devuelve contrato; flujos cableados sin romper contratos previos

**Verificación (usuario)**:
```bash
cd panel && npm run build
```

**Aceptación**: motivo capturado y enviado en toda acción destructiva/cambio de acceso; build verde.

**Commit local**: `feat(panel): modal de motivo en acciones destructivas y cambios de acceso`
**Rollback**: `git revert`.
**Líneas**: ~400 · **Rama**: `feat/89-wu-11a-motivos` · **Depende**: 10b

---

#### 3.5 — WU-11b · `/logs` operativo + LogController

**Objetivo y alcance**: corregir el contrato de `/logs` (lee `logs.data`), paginación servidor,
escape y banner “operativo”; `LogController` con allowlist de acciones operativas.

**Archivos**:
- Crear: `backend/app/Http/Controllers/Api/LogController.php`
- Modificar: `backend/routes/api.php` (ruta `/logs` → `LogController` con `permission:view_logs`)
- Modificar: `panel/src/pages/logs.astro`
- Crear: `backend/tests/Feature/LogsOperativoTest.php`

**Tareas ordenadas**:
- [ ] `LogController::index`: paginador Laravel (`data`, `current_page`, `last_page`, `total`); `whereIn('action', config('audit.logs.operational_actions'))`; filtros `action/from/to/per_page`
- [ ] `logs.astro`: leer `res.data` (no `logs.filter`); paginación “Anterior/Siguiente”; `textContent` en celdas; `details` como `<pre>` JSON
- [ ] Banner “Registro operativo (no es auditoría). Para evidencia de cambios, use Auditoría.”
- [ ] Acceso `permission:view_logs` (403 sin permiso)

**Tests a escribir**:
- `LogsOperativoTest` — contrato paginador, allowlist, 403 sin permiso

**Verificación (usuario)**:
```bash
cd backend && php artisan test --filter=LogsOperativoTest && cd panel && npm run build
```

**Aceptación**: `/logs` operativo con contrato `data`, paginación, escape y banner; separado de `/auditoria`.

**Commit local**: `feat(auditoria): /logs operativo (contrato data, paginación, escape)`
**Rollback**: `git revert`.
**Líneas**: ~250 · **Rama**: `feat/89-wu-11b-logs-operativo` · **Depende**: 11a

---

### Fase 4 — Integridad y retención

#### 4.1 — WU-12a · Integridad, migración de datos y FKs

**Objetivo y alcance**: migración de `juego_auditoria` a `audit_events`, endurecimiento de FKs que
preservan evidencia, triggers/grants append-only y `JuegoAuditoria` read-only.

**Archivos**:
- Crear: `backend/database/migrations/2026_11_05_000003_migrate_juego_auditoria_to_audit_events.php`
- Crear: `backend/database/migrations/2026_11_05_000004_harden_audit_evidence_foreign_keys.php`
- Crear: `backend/database/migrations/2026_11_05_000005_add_audit_append_only_guards.php`
- Crear: `backend/database/sql/audit_events_grants.sql`
- Modificar: `backend/app/Models/JuegoAuditoria.php` (read-only)
- Crear: `backend/tests/Feature/{AuditoriaIntegridadTest,AuditoriaMigracionJuegoTest}.php`

**Tareas ordenadas**:
- [ ] Migración `juego_auditoria` → `audit_events` con `event_uuid = sha1('juego_auditoria:'.$id)`, mapeo de `accion`, scope `null`
- [ ] Migración de FKs: `pagos.created_by`, `cierres_caja.created_by`, `juego_auditoria.user_id/juego_id` → `nullOnDelete`; `down()` solo sin filas `NULL`
- [ ] Triggers `BEFORE UPDATE/DELETE` con `SIGNAL`/`RAISE(ABORT)` (permite delete con `@audit_retention=1`)
- [ ] `database/sql/audit_events_grants.sql` (SELECT/INSERT app; SELECT/DELETE retención)
- [ ] `JuegoAuditoria` read-only (create/update/delete lanzan excepción)

**Tests a escribir**:
- `AuditoriaIntegridadTest` — triggers bloquean update/delete; `nullOnDelete` conserva filas; modelo append-only
- `AuditoriaMigracionJuegoTest` — N filas → N eventos sin pérdida; duplicados omitidos; tabla vieja read-only

**Verificación (usuario)**:
```bash
cd backend && php artisan test --filter='AuditoriaIntegridadTest|AuditoriaMigracionJuegoTest'
```

**Aceptación**: append-only en BD; `juego_auditoria` migrada y read-only; FKs `nullOnDelete`; grants SQL presentes.

**Commit local**: `feat(auditoria): migración de juego_auditoria, FKs que preservan evidencia y guards`
**Rollback**: `git revert` de la migración (requiere backup/staging; irreversibilidad documentada).
**Líneas**: ~450 · **Rama**: `feat/89-wu-12a-integridad-datos` · **Depende**: 11b

---

#### 4.2 — WU-12b · Retención, archivo, verificación y comandos

**Objetivo y alcance**: comandos `audit:archive/prune/verify/backfill`, retención NDJSON.gz +
checksum + manifest, evento `audit.retention_purged`/`audit.chain_verified`/`backfill.completed` y
documentación del sistema.

**Archivos**:
- Crear: `backend/app/Console/Commands/{AuditArchive,AuditPrune,AuditVerify,AuditBackfill}.php`
- Modificar: `backend/config/database.php` (conexión de retención)
- Modificar: `backend/app/Jobs/AuditarPagosLegacy.php` (o servicio equivalente, `pago.legacy_reconciled`)
- Crear: `backend/tests/Feature/{AuditoriaComandosTest,AuditoriaRetencionTest}.php`
- Crear: `docs/dev/auditoria-sistema.md`

**Tareas ordenadas**:
- [ ] `audit:archive {--months} {--apply}`: archivo NDJSON.gz + `.sha256` + `.manifest.json`; borra hot con `@audit_retention=1`; emite `audit.retention_purged`
- [ ] `audit:prune {--years} {--apply} {--force}`: elimina archivos >5 años con verificación previa
- [ ] `audit:verify`: verifica checksums y hash chain; emite `audit.chain_verified`; exit 1 ante rotura
- [ ] `audit:backfill {--source} {--apply}`: convierte `logs`/`juego_auditoria` idempotente; excluye scrapers; `backfill.completed` P2
- [ ] `AuditarPagosLegacy`: `pago.legacy_reconciled` P1
- [ ] `docs/dev/auditoria-sistema.md`: cobertura, consulta, retención, verificación, restore

**Tests a escribir**:
- `AuditoriaComandosTest` — backfill dry-run/apply; idempotencia; exclusión de scrapers
- `AuditoriaRetencionTest` — archive dry-run/apply (NDJSON + checksum + manifest), purge event; verify detecta rotura

**Verificación (usuario)**:
```bash
cd backend && php artisan test --filter='AuditoriaComandosTest|AuditoriaRetencionTest'
```

**Aceptación**: retención 12m hot + 5y archivo; comandos dry-run por defecto; verify con exit code; docs presentes.

**Commit local**: `feat(auditoria): retención/archivo, comandos audit:* y verificación`
**Rollback**: `git revert`.
**Líneas**: ~400 · **Rama**: `feat/89-wu-12b-retencion` · **Depende**: 12a

---

### Fase 5 — Hardening y cierre

#### 5.1 — WU-13 · Hardening, limpieza final y suite completa

**Objetivo y alcance**: flags finales de rollout, eliminar logs de negocio duplicados, tests de
arquitectura, colecciones pendientes, docs finales y suite completa.

**Archivos**:
- Modificar: `backend/config/audit.php` (flags finales), `.env.example` (si documenta flags)
- Modificar: `backend/app/Console/Commands/ReleasePublishCommand.php` / seeders (`permission.catalog_synced`)
- Modificar: `backend/app/Jobs/AuditarPagosLegacy.php` (si falta `pago.legacy_reconciled`)
- Crear/modificar: `backend/tests/Feature/AuditArchitectureTest.php` (scan final)
- Modificar: `docs/dev/auditoria-sistema.md` (final), `docs/dev/permisos-capabilities.md`
- Crear/completar: `collections/Auditoria/` si falta

**Tareas ordenadas**:
- [ ] Ampliar `AUDIT_FAIL_CLOSED_DOMAINS` a dominios completos tras observar errores
- [ ] Eliminar escrituras de negocio duplicadas (dual-write) una vez el visor nuevo es única fuente
- [ ] `permission.catalog_synced` P1 en el seeder
- [ ] Tests de arquitectura: sin observer global, sin escritura directa a `audit_events`, sin `Admin/**`/`FetchResultsJob`, sin `innerHTML` con datos en panel
- [ ] Docs finales (cobertura/consulta/retención/verificación) y colecciones en paridad

**Tests a escribir**:
- `AuditArchitectureTest` final (scans de exclusión y append-only)
- Suite completa (todos los tests del ciclo)

**Verificación (usuario)**:
```bash
cd backend && composer test && cd backend && ./vendor/bin/pint --test && cd panel && npm run build
```

**Aceptación**: cero referencias legacy; suite verde; Pint verde; build verde; docs y rollout documentados.

**Commit local**: `chore(auditoria): hardening final, docs, colecciones y suite completa`
**Rollback**: `git revert`; documentar rollback de flags en `docs/dev/auditoria-sistema.md`.
**Líneas**: ~500 · **Rama**: `feat/89-wu-13-hardening` · **Depende**: 12b

---

## 4. Protocolo de test gate (explícito)

1. El agente implementa **una WU** y se **detiene**. No ejecuta tests, migraciones, Pint, composer, npm ni artisan.
2. El agente entrega los comandos exactos de la WU (ver §3); el usuario los ejecuta y reporta pass/fail.
3. Si **pass**: el orquestador hace `git add` + `git commit` local en la rama de la WU (mensaje de la WU).
4. Si **fail**: el agente corrige y se repite el gate de esa WU; **no hay commit hasta que pase**.
5. Al final del ciclo, el usuario ejecuta la suite completa (`composer test` + `pint --test` + `npm run build`).
6. Con la suite verde: `sdd-verify` → `sdd-archive` → merge de la tracker a `main` local → push a `main`.

Instalación de dependencias (solo si el worktree no las tiene; las ejecuta el usuario, una vez, en preflight):
```bash
cd backend && composer install
cd panel && pnpm install
```

---

## 5. Matriz de criterios de aceptación (cobertura specs → WUs)

45 requirements / 91 scenarios. Mapeo por capability y dominio (para `sdd-verify`):

| Capability (spec) | Req | Esc | Cubierto por WUs |
|---|---|---|---|
| `auditoria-eventos` (escritura, catálogo, fallo, motivo, PII) | 9 | 16 | 1a, 1b, 2, 3, 4, 5, 6, 7, 8 |
| `auditoria-lectura` (API cursor/filtros/scope, export, visor, `/logs`) | 7 | 16 | 9, 10a, 10b, 11b |
| `auditoria-integridad` (append-only, índices, retención, verificación, migración, FKs, docs) | 7 | 15 | 1a, 12a, 12b, 13 |
| `catalogo-permisos` (catálogo, matriz, bypass, seeder, view_audit/export_audit) | 7 | 11 | 9, 13 |
| `colecciones-api` (entornos, URLs, cobertura, requests auditoría, contratos) | 8 | 18 | 9, 13 |
| `panel-jerarquia` (navegación, sidebar, login, página auditoría/logs, terminología) | 7 | 15 | 10b, 11b, 13 |

### Criterios de aceptación del proposal → WU

| Criterio de aceptación | WU(s) que lo garantizan |
|---|---|
| 100% del catálogo de eventos cubierto por tests (actor, before/after, IP/UA, scope) | 1a (enum) + 2–8 (eventos) + 13 (suite) |
| `audit_events` append-only (sin UPDATE/DELETE app ni BD en hot) | 1a (modelo) + 12a (triggers/grants) |
| Visor con filtros, cursor, detalle diff, export CSV; `/logs` corregido | 10a, 10b, 11b |
| Motivo capturado/persistido en destructivas y cambio de acceso (no nullable) | 3, 5, 7 (backend) + 11a (panel) |
| Retención verificada: hot→archivo, poda con `audit.retention_purged` | 12b |
| Cero referencias al admin legacy ni `FetchResultsJob` | 2, 8, 13 (scan de arquitectura) |
| `view_audit`/`export_audit` aplicados; scope jerárquico sin fuga | 9 |

---

## 6. Checklist final de cutover

- [ ] `git status` limpio en el worktree (`/home/gzuz/Documentos/lotto-app-worktrees/auditoria-trazabilidad-89`).
- [ ] Sincronizar `main` / resolver merge legacy si `retiro-legacy-backend` mergeó antes (solo archivos propios).
- [ ] Resolver estado sucio de `main` (taquilla/docs) con su dueño antes del merge final.
- [ ] Suite completa: `cd backend && composer test`.
- [ ] Pint: `cd backend && ./vendor/bin/pint --test`.
- [ ] `php artisan route:list` (verificar rutas de auditoría + middleware por clase).
- [ ] Build panel: `cd panel && npm run build`.
- [ ] `sdd-verify` (cobertura specs) → `sdd-archive`.
- [ ] Merge de la tracker a `main` local; push a `main`; CI/CD verde.
- [ ] Actualizar issue #89 y docs tras el merge.
- [ ] Reconciliar `docs/dev/auditoria-logs.md` **solo después** del merge de `retiro-legacy-backend`.

---

## 7. Fuera de alcance / follow-ups

| Ítem | Motivo / seguimiento |
|---|---|
| Login del admin legacy | #81 lo retira; no se audita ni referencia |
| `FetchResultsJob` | #81 lo retira; cobertura en `ScrapeSourceJob`/`ScrapeResultsJob` |
| Eliminación de Vite/Tailwind | Fuera del alcance de #89 |
| Revisión CSRF / Sanctum stateful | Revisión de seguridad posterior |
| Auditoría en detalle de scrapers | Siguen en `logs` operativo + archivo |
| Lecturas/consultas auditadas | Solo escrituras de alto valor; no se auditan lecturas |
| Observer global de Eloquent | Explícitamente excluido (trait opt-in + llamadas explícitas) |
| Hash chain obligatoria | Opcional (`AUDIT_HASH_CHAIN=false`); evaluar si se exige evidencia a prueba de manipulación |
| Confirmación legal de retención (12m/5y) | Pendiente con legal antes de producción |
