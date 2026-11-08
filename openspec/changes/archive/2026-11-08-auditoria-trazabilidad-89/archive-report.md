# Archive Report — auditoria-trazabilidad-89 (#89)

**Change**: `auditoria-trazabilidad-89` · Issue [#89](https://github.com/jmxsdev/lotto-app/issues/89)
**Branch**: `feat/89-wu-13-hardening` · **Base**: `main` @ `ea7d381`
**Commit candidate**: `65ad919` (`fix(auditoria): cierra críticos de verify (permisos, PII y motivo)`)
**Artifact store**: OpenSpec + Engram (`lotto-app`)
**Date**: 2026-11-08
**Verdict**: **PASS WITH WARNINGS** — 0 CRITICAL, 6 WARNING, 10 SUGGESTION

---

## 1. Resumen ejecutivo

El cambio entrega un sistema de auditoría completo y aditivo: tabla `audit_events` append-only, capa única de captura (`AuditContext` + `AuditRecorder` + `AuditRedactor` + trait opt-in), catálogo cerrado de 67 acciones `domain.verb`, API de lectura con cursor/filtros/scope/export CSV, visor de panel `/auditoria`, separación de `/logs` como registro operativo, permisos `view_audit`/`export_audit`, y retención 12 meses hot + 5 años en NDJSON.gz con verificación de integridad.

La verificación de la ronda 2 (commit `65ad919`) aprobó **45/45 requirements y 91/91 scenarios**, con los **3 CRITICAL de la ronda 1 resueltos**:

| # | Critical ronda 1 | Resolución |
|---|---|---|
| C1 | `user.permissions_changed` sin call site de producción | Emitido dentro de la transacción de `UserController::update`, con snapshots before/after de permisos y test dedicado |
| C2 | `actor_label` persistía emails crudos (fuga de PII) | `AuditRecorder::resolveActor` enmascara todo label con `@`; backfill y migración legacy también enmascaran/anulan |
| C3 | `game.limit_deleted` no exigía motivo | Validación `required` + `Rule::enum(AuditReason::class)` antes del delete; 422 sin mutación ni evento |

**Suite final** (ejecutada por el usuario sobre este candidato): **1.853 tests, 11.748 assertions, 2 skipped, 0 failures**. Pint verde. Build del panel verde.

**Alcance del cierre**: el cambio queda listo para archivar y mergear a `main` local. No se hizo push. Esta fase no movió la carpeta del cambio ni sincronizó specs — ver §9.

---

## 2. Estado final por capability

| Capability | Requirements | Scenarios | Estado | Observaciones residuales |
|---|---:|---:|---|---|
| `auditoria-eventos` | 9 / 9 | 16 / 16 | ✅ Aprobada | WARNING-2 (trait sin uso en producción), WARNING-6 (`unspecified` aceptado como motivo) |
| `auditoria-lectura` | 7 / 7 | 16 / 16 | ✅ Aprobada | WARNING-3 (backfill sin scope), WARNING-4 (`auth.login_failed` sin scope) |
| `auditoria-integridad` | 7 / 7 | 15 / 15 | ✅ Aprobada | WARNING-1 (`bet.expired` por apuesta), WARNING-5 (fidelidad de migración 000003) |
| `catalogo-permisos` | 7 / 7 | 11 / 11 | ✅ Aprobada | Sin warnings |
| `colecciones-api` | 8 / 8 | 18 / 18 | ✅ Aprobada | Sin warnings |
| `panel-jerarquia` | 7 / 7 | 15 / 15 | ✅ Aprobada | Sin warnings |
| **Total** | **45 / 45** | **91 / 91** | **✅** | **0 CRITICAL** |

Los 3 críticos corregidos en la ronda 2 están cubiertos por tests nuevos/actualizados en `AuditoriaRolTest`, `AuditRecorderTest`, `AuditoriaLimitesConfigTest` y `LimitesApiTest`, además de las aserciones de enmascaramiento en las suites de dominio.

---

## 3. Entregables

### 3.1 Esquema y migraciones

- `backend/database/migrations/2026_11_05_000001_create_audit_events_table.php` — tabla append-only + 6 índices.
- `backend/database/migrations/2026_11_05_000002_create_audit_chain_state_table.php` — estado de cadena (hash chain opcional).
- `backend/database/migrations/2026_11_05_000003_migrate_juego_auditoria_to_audit_events.php` — migración idempotente de `juego_auditoria`.
- `backend/database/migrations/2026_11_05_000004_harden_audit_evidence_foreign_keys.php` — FKs `nullOnDelete` para preservar evidencia.
- `backend/database/migrations/2026_11_05_000005_add_audit_append_only_guards.php` — triggers MySQL `SIGNAL` para UPDATE/DELETE (bypass explícito de retención).
- `backend/database/sql/audit_events_grants.sql` — mínimo privilegio: app `SELECT/INSERT`; retención `SELECT/DELETE`.

### 3.2 Capa de auditoría

- `backend/app/Audit/`: `AuditAction` (67 casos), `AuditPriority`, `AuditActorType`, `AuditReason`, `ReasonPolicy`, `AuditEventData`, `AuditScope`, `AuditContext`, `AuditRecorder`, `AuditRedactor`, `AuditScopeResolver`, `AuditChainHasher`, `Auditable` (trait opt-in), `Casts/UtcDateTime`.
- `backend/app/Http/Middleware/CaptureAuditContext.php` — registrado por clase en `routes/api.php`.
- `backend/app/Models/AuditEvent.php` — modelo inmutable (`updating/deleting` lanzan `AuditAppendOnlyException`).
- `backend/app/Exceptions/{AuditAppendOnlyException,AuditWriteException,AuditValidationException}.php`.
- `backend/config/audit.php` y flags en `backend/.env.example`.

### 3.3 API, lectura y export

- `backend/app/Http/Controllers/Api/AuditEventController.php` — `GET /api/v1/audit-events` (cursor, filtros, 422 ante filtros desconocidos) y `/export`.
- `backend/app/Services/AuditQueryService.php` — paginación por cursor, orden `occurred_at DESC, id DESC`, redacción en lectura.
- `backend/app/Services/AuditExportService.php` — CSV con BOM, escape de fórmulas, tope configurable y evento `audit.exported`.
- `backend/app/Support/Scope/AlcanceService.php` — `applyToAuditoria`; `super_master` global, `master` sus bancas, resto 403.

### 3.4 Panel

- `panel/src/pages/auditoria.astro` — filtros, tabla, cursor, drawer de diff, export, estados 403/error/empty; sin `innerHTML` con datos.
- `panel/src/pages/logs.astro` — contrato `data`, paginación servidor, escape y banner “registro operativo”.
- `panel/src/utils/reason-modal.ts` — modal reutilizable de motivo (`reason_code` + `reason_text`).
- `panel/src/utils/escape.ts`, `panel/src/utils/api.ts`, `panel/src/layouts/AdminLayout.astro`, `panel/src/pages/login.astro` — escape, descarga autenticada, nav por `view_audit` y persistencia de `permissions`.

### 3.5 Permisos y `/logs`

- `backend/database/seeders/RolesAndPermissionsSeeder.php` — 36 permisos; `view_audit`/`export_audit` para `master` (35 para master); evento `permission.catalog_synced` P1 best-effort.
- `backend/app/Http/Controllers/Api/LogController.php` — allowlist de acciones operativas, paginador Laravel, `permission:view_logs`.

### 3.6 Retención, verificación y backfill

- `backend/app/Console/Commands/AuditArchive.php` — NDJSON.gz + `.sha256` + `.manifest.json`, borrado hot con `@audit_retention=1`, evento `audit.retention_purged`.
- `backend/app/Console/Commands/AuditPrune.php` — poda de archivos > 5 años con verificación previa.
- `backend/app/Console/Commands/AuditVerify.php` — checksums, NDJSON y hash chain; exit 1 ante rotura.
- `backend/app/Console/Commands/AuditBackfill.php` — backfill idempotente de `logs`/`juego_auditoria`, exclusión de scrapers.
- `backend/app/Console/Commands/AuditarPagosLegacy.php` — `pago.legacy_reconciled`.
- `backend/config/database.php` — conexión dedicada `audit_retention`.

### 3.7 Documentación

- `docs/dev/auditoria-sistema.md` — cobertura, consulta, retención, verificación, despliegue, rollback y restore.
- `docs/dev/permisos-capabilities.md` — matriz de capabilities.
- `docs/dev/auditoria-logs.md` — sin cambios frente a `main` (se reconcilia post-merge legacy).

### 3.8 Colecciones

- `collections/Auditoria/Listar Eventos de Auditoría.yml`
- `collections/Auditoria/Exportar Auditoría CSV.yml`
- `collections/Auditoria/folder.yml`

### 3.9 Tests

Suites nuevas/actualizadas: `tests/Unit/Audit/*` (acción, contexto, DTO, redactor, recorder, scope/hash, cast UTC, modelo append-only), `tests/Feature/Auditoria*` (auth, activación, usuarios/rol, banca/grupo/agencia/taquilla, dinero, cierre, pagos, anulaciones, juegos/límites, jobs, resultados/releases, lectura, export, integridad, migración legacy, retención, verify, comandos, middleware, arquitectura) y `LogsOperativoTest`. La suite completa queda en **1.853 tests / 11.748 assertions / 2 skipped / 0 failures**.

### 3.10 Despliegue

- `docker-compose.yml` y `docker-compose.prod.yml` arrancan MySQL con `--log-bin-trust-function-creators=1`.
- `docker-compose.prod.yml`: `horizon` y `scheduler` dependen de `api: condition: service_healthy`.

---

## 4. Commits clave

Rango `ea7d381..HEAD` (110 commits totales). La rama propia aporta 81 commits de primer padre; el resto entra por el merge de `main` (#80/#81) y no pertenece a este cambio. Se listan los commits de código/entrega; los `docs(sdd)` de registro por WU se omiten por brevedad.

### Fase 1 — Fundación

| Commit | Entrega |
|---|---|
| `62b5ce9` | Esquema `audit_events`, modelo append-only y config (1a-1) |
| `b1b0ed8` | Taxonomía `AuditAction` y política de motivo (1a-2) |
| `fd81ac9` | DTO `AuditEventData` y scope (1a-3) |
| `72dc95d` | Contexto de request y actor lazy (1b-1a) |
| `a749adc` | Resolución de scope y hash canónico (1b-1b) |
| `aa1d43e` | Redactor PII determinista (1b-1c) |
| `2a90be8` | Recorder único con fail-closed y hash chain (1b-2a) |
| `813db40` | Trait, middleware y contexto scoped (1b-2b) |

### Fase 2 — Captura por dominio

| Commit | Entrega |
|---|---|
| `9f2d6cf` | Login/logout API (2a) |
| `232fcb6` | Activación y verificación de dispositivo (2b) |
| `1438cc9` | Alta y baja de usuarios (3a-1) |
| `a86872d` | Actualización de usuarios y roles (3a-2) |
| `a0d3b6d` | Cambio de clave de cierre (3b) |
| `f16ac12` | Tasas de cambio y comisiones (4) |
| `1e68d1e` | Cierre y re-cierre (5a) |
| `4e8edc3` | Pago de premio (5b-1) |
| `18b28b6`, `0b1b8c2` | Anulación de ticket/apuesta y migración de tests (5b-2) |
| `3558ab1` | Juegos y premios (6a) |
| `e1a34fa` | Límites y ventana de vencimiento (6b) |
| `554dd8b` | Banca (7a-1) |
| `f933df0` | Grupo (7a-2) |
| `dfa53e8` | Agencia (7b-1) |
| `6292a9e` | Taquilla (7b-2) |
| `58cd892` | Jobs de dinero y vencimientos (8a-1) |
| `2baba1b` | Conciliación legacy (8a-2) |
| `ae667e0` | Resultados y releases (8b) |

### Fase 3 — Integración

| Commit | Entrega |
|---|---|
| `83e20c4` | API de lectura y permisos de auditoría (9a) |
| `3ded531` | Export CSV y colecciones (9b) |
| `e472e32` | Visor `/auditoria` con filtros, tabla, cursor y escape (10a) |
| `2091276` | Detalle con diff, export y navegación (10b) |
| `59aed9d` | Modal de motivo base, jerarquía y límites (11a-1) |
| `659e67d` | Motivo en usuarios, jerarquía y premios (11a-2) |
| `df7b492` | Motivo opcional en clave, tasas, comisiones y configuración (11a-3) |
| `8451b04` | `/logs` operativo con contrato `data`, paginación y escape (11b) |

### Fase 4 — Integridad y retención

| Commit | Entrega |
|---|---|
| `ff5cc73` | Integridad de evidencia, migración legacy y FKs (12a) |
| `e653384` | Archivo y poda de retención (12b-1) |
| `77a67e4` | Verificación de archivos y hash chain (12b-2) |
| `27e06a9` | Backfill idempotente de `logs` y `juego_auditoria` (12b-3) |
| `b59101e` | Docs del sistema, retención y despliegue (12b-4) |

### Fase 5 — Hardening y cierre

| Commit | Entrega |
|---|---|
| `255c2df` | Hardening final, evento del seeder y tests de arquitectura (13) |
| `a163ba8` | **Merge `main` → `feat/89-wu-13-hardening`** (trae #80 terminal-como-modalidad y #81/#82 retiro legacy) |
| `34ee0f8` | Fix del gap: auditar solicitudes manuales de scrape (`draw.scrape_requested` / `scrape_all_requested`) |
| `9465084` | Registro SDD de la suite final y del gap fix |
| `65ad919` | **Critical fix**: permisos, PII de `actor_label` y motivo obligatorio en `game.limit_deleted` |

### Integración con `main`

El merge `a163ba8` integra la historia de `main` posterior a `ea7d381` (archivo de `terminal-como-modalidad` #80, retiro legacy #81/#82 y sus fixes). Esos commits no forman parte de la autoría de este cambio y se conservan sin reescritura.

---

## 5. Cobertura de acceptance criteria (proposal §11)

| Criterio | Estado | Evidencia |
|---|---|---|
| 100% del catálogo de eventos cubierto por tests (actor, before/after, IP/UA, scope) | ✅ Cumplido | Enum `AuditAction` con 67 casos y tests por acción; suites por dominio (auth, usuarios/rol, jerarquía, dinero, juegos, jobs, resultados); verify 45/45 requirements y 91/91 scenarios. Salvedades: WARNING-1 (`bet.expired` por apuesta en `ExpireUnclaimedPrizesJob`) y WARNING-6 (`unspecified` como motivo) |
| `audit_events` append-only (sin UPDATE/DELETE desde la app ni BD en hot) | ✅ Cumplido | Modelo inmutable + triggers MySQL 000005 + grants; `AuditoriaIntegridadTest` y `AuditArchitectureTest` |
| Visor con filtros, cursor, detalle con diff y export CSV; `/logs` corregido (contrato, paginación, escape) | ✅ Cumplido | `auditoria.astro`, `AuditQueryService`, `AuditExportService`; `LogsOperativoTest`; `AuditArchitectureTest` verifica ausencia de `innerHTML` |
| Motivo capturado y persistido en toda acción destructiva/cambio de acceso (no nullable) | ✅ Cumplido | `ReasonPolicy` + validación en recorder/controllers; 422 y rollback en tests de cierre, pagos, anulaciones, jerarquía y límites; modal del panel. Salvedad: WARNING-6 |
| Retención verificada: hot→archivo, poda con `audit.retention_purged` | ✅ Cumplido | `AuditArchive`/`AuditPrune`/`AuditVerify`; `AuditoriaRetencionTest`; docs §5–§6 |
| Cero referencias al admin legacy ni a `FetchResultsJob` | ✅ Cumplido | `AuditArchitectureTest` (scans de exclusión); verify §Exclusion checks |
| `view_audit`/`export_audit` aplicados; scope jerárquico por `AlcanceService` sin fuga | ✅ Cumplido | Seeder 36 permisos; `AlcanceService::applyToAuditoria`; `AuditoriaLecturaTest` (super_master global, master sus bancas, 403 sin permiso) |

Todos los criterios quedan cubiertos. Los dos WARNING que tocan criterios (WARNING-1 y WARNING-6) no anulan el escenario verificado y se registran como follow-ups en §7.

---

## 6. Rollout / deploy

### 6.1 Orden obligatorio

1. **Activar `log_bin_trust_function_creators=1` en MySQL antes de migrar.** Sin este flag, la migración `000005` (triggers append-only) falla con error 1419 cuando el binlog está activo y el usuario no tiene `SUPER`.
2. **Desplegar / migrar.** El entrypoint del contenedor `api` ejecuta `php artisan migrate --force` antes de levantar FrankenPHP; ahí se crean `audit_events`, `audit_chain_state` y los triggers.
3. **Aplicar grants** con `backend/database/sql/audit_events_grants.sql` (requiere que la tabla exista).
4. **Verificar triggers y grants**:
   ```sql
   SHOW TRIGGERS WHERE `Table` = 'audit_events';
   SHOW GRANTS FOR 'lotto_app'@'%';
   ```

### 6.2 Compose

- `docker-compose.yml:7` y `docker-compose.prod.yml:12` incluyen `--log-bin-trust-function-creators=1` en el `command` de MySQL, de modo que el flag sobrevive reinicios.
- En `docker-compose.prod.yml`, `horizon` y `scheduler` declaran `depends_on: api: condition: service_healthy` (además de MySQL/Redis), para no arrancar antes de que la API termine sus migraciones.
- El healthcheck del `api` corre después de migrar: un fallo de migración deja el contenedor unhealthy y bloquea a los workers.

### 6.3 Flags operativos

| Flag | Valor por defecto | Uso |
|---|---|---|
| `AUDIT_ENABLED` | `true` | Kill switch de emergencia. |
| `AUDIT_FAIL_CLOSED` | `true` | `false` degrada P0 a best-effort (solo emergencia). |
| `AUDIT_FAIL_CLOSED_DOMAINS` | `*` | Fail-closed para todos los dominios P0. |
| `AUDIT_HASH_CHAIN` | `false` | Cadena criptográfica opcional. |
| `AUDIT_HOT_MONTHS` / `AUDIT_ARCHIVE_YEARS` | `12` / `5` | Ventanas de retención. |
| `AUDIT_EXPORT_MAX_ROWS` | `50000` | Tope de export (el design decía 100.000; ver SUGGESTION 2). |
| `AUDIT_RETENTION_CONNECTION` | `DB_CONNECTION` | Conexión con `DELETE` para retención. |

### 6.4 Merge

- Merge de la rama tracker a `main` **local**.
- **Push pendiente**: decisión del usuario. No se ejecutó ningún push en esta fase.

---

## 7. Riesgos residuales y follow-ups

Ninguno bloquea el archivo (0 CRITICAL). Se listan los 6 WARNING y 10 SUGGESTION del verify-report con recomendación. No se crean issues nuevos en este documento.

### 7.1 WARNING

| ID | Hallazgo | Recomendación |
|---|---|---|
| WARNING-1 | `ExpireUnclaimedPrizesJob` emite `ticket.expired` pero no un `bet.expired` por apuesta afectada, contra design §4.3 | **Follow-up issue**: emitir `bet.expired` P0 por apuesta dentro de la transacción del ticket, o enmendar design §4.3 a la semántica implementada |
| WARNING-2 | El trait `Auditable` está implementado pero ningún modelo de producción lo usa; la captura real es por llamadas explícitas | **Follow-up issue**: aplicar el trait a los modelos designados o enmendar spec/design; añadir test que impida duplicar eventos |
| WARNING-3 | El backfill de `logs` fija scope en `null`, por lo que el histórico es visible solo para `super_master` y no para `master` | **Follow-up issue** (impacto de lectura histórica): derivar scope al backfilear o documentar la limitación en `auditoria-sistema.md` |
| WARNING-4 | `auth.login_failed` no persiste scope para usuarios conocidos, reduciendo la visibilidad de seguridad por banca | **Fix ahora o follow-up corto**: resolver `scopeResolver->forModel($user)` cuando el email exista; mantener `null` para desconocidos |
| WARNING-5 | La migración `000003` tiene menor fidelidad que design §10.3 (`actor_label` nulo, sin `active` sintetizado, clave `legacy_source` en vez de `source`) | **Follow-up issue**: sintetizar `active` before/after, resolver label enmascarado y alinear la clave, o actualizar el design |
| WARNING-6 | El motivo requerido acepta `unspecified` (y `other` sin texto) en el borde de API, aunque el panel no lo permite | **Fix ahora (pre-deploy)**: rechazar `unspecified` y `other` sin `reason_text` cuando la política sea `Required`; añadir test. Es un cambio pequeño y cierra un bypass del control |

### 7.2 SUGGESTION

| # | Hallazgo | Recomendación |
|---|---|---|
| S1 | Deriva entre el enum de motivos y design §5.10/§8.4 | **Follow-up doc**: alinear design con el enum implementado |
| S2 | `AUDIT_EXPORT_MAX_ROWS` usa 50.000; design dice 100.000 | **Follow-up doc/config**: alinear design o config (docs y colecciones ya dicen 50.000) |
| S3 | La migración 000005 omite guards en SQLite, mientras design §9.1 menciona `RAISE(ABORT)` | **Follow-up**: añadir guard SQLite o documentar la limitación |
| S4 | `hashFingerprint` usa SHA-256 plano; design pide HMAC-SHA256 con `app.key` | **Follow-up de hardening**: migrar a HMAC cuando se active la cadena |
| S5 | `MarcarApuestasVencidasJob` usa una transacción por apuesta, no `recordMany` por chunk | **Follow-up doc**: documentar la desviación (el comportamiento actual es más seguro) |
| S6 | `user_agent` se trunca pero no se redacta | **Follow-up corto**: pasar la UA por `AuditRedactor` |
| S7 | El link de `/logs` se filtra por rol, no por `can('view_logs')` | **Follow-up menor**: usar la capability para evitar deriva |
| S8 | `ReleasePublishCommand` cae silenciosamente en `business_decision` si falta el motivo | **Follow-up**: fallar o documentar el default |
| S9 | El test de FKs verifica `information_schema` pero no borra un usuario y comprueba que pago/cierre sobreviven | **Follow-up**: añadir test end-to-end de borrado |
| S10 | Los conteos de progreso de `tasks.md` estaban desactualizados (1.848/11.702) | **Resuelto en el working tree**: actualizado a 1.853/11.748; pendiente de commit por el orquestador |

---

## 8. Rollback

### 8.1 Por WU

Cada work unit es aditiva y se revierte con `git revert <commit>` de su rama/slice. Revertir una WU no deja el modelo inconsistente porque la captura es opt-in y no cambia el contrato de negocio salvo los parámetros aditivos de motivo.

### 8.2 Migraciones

| Migración | Rollback | Requisito |
|---|---|---|
| `000001`, `000002` | `git revert` / `migrate:rollback` | Aditivas; sin impacto en negocio |
| `000003` (migración `juego_auditoria`) | Restaurar backup/staging | **Irreversible sin backup**; `juego_auditoria` queda read-only tras la copia |
| `000004` (FKs) | `down()` solo si no hay filas `NULL` | **Requiere backup y staging**; en MySQL puede reconstruir la tabla → ventana de mantenimiento |
| `000005` (triggers) | `DROP TRIGGER` / rollback de migración | **DDL no transaccional**: el fallo a mitad no revierte automáticamente; revisar triggers manualmente |

Los `up()` de 000003–000005 usan DDL, que en MySQL es **no transaccional**: un fallo parcial no se deshace solo. El rollback de esas migraciones es una operación de mantenimiento con backup verificado, no un simple revert.

### 8.3 Flags de emergencia

- `AUDIT_ENABLED=false` detiene la captura sin borrar evidencia.
- `AUDIT_FAIL_CLOSED=false` restaura la disponibilidad ante una caída de escritura, a costa de evidencia P0.
- `AUDIT_HASH_CHAIN=false` (default) evita el lock global de la cadena.

La documentación de rollback vive en `docs/dev/auditoria-sistema.md` §7 y design §10.6.

---

## 9. Archive status

- **Verdict**: PASS WITH WARNINGS — 0 CRITICAL. El cambio está **listo para archivar y mergear** a `main` local.
- **Push**: **no realizado**. Pendiente de decisión del usuario.
- **Estado del working tree**: `openspec/changes/auditoria-trazabilidad-89/tasks.md` tiene la actualización final (suite 1.853/11.748 y estado de verify) pendiente del commit del orquestador. No se modificó código de producción, tests ni specs durante esta fase.
- **Movimiento de carpeta y sincronización de specs**: no ejecutados en esta fase por el alcance asignado al subagente de archivo. La carpeta permanece en `openspec/changes/auditoria-trazabilidad-89/`, lista para el paso mecánico de archivo (`openspec/changes/archive/2026-11-08-auditoria-trazabilidad-89/`) y la fusión de deltas.
- **Nota de completitud**: la tabla §1.2 de `tasks.md` marca las 13 WUs como implementadas y el verify-report confirma que no hay tareas de implementación pendientes. Los checkboxes por sección (`- [ ]`) siguen siendo marcadores de planificación obsoletos, tal como documenta el verify-report; la fuente de verdad de completitud es §1.2 + verify-report, no esos checkboxes.
- **Nota de commit**: `.gitignore:44` ignora `*.md`; `archive-report.md` es un archivo nuevo y debe añadirse con `git add -f` para que el orquestador lo commitee.
- **Trazabilidad Engram** (observaciones leídas):
  - proposal: `#792`
  - spec: `#793`
  - design: `#794`
  - tasks: `#795`
  - verify-report: `#815`
  - archive-report: (esta observación, topic `sdd/auditoria-trazabilidad-89/archive-report`)

---

*Reporte generado en la fase `sdd-archive`. Sin cambios en código de producción, tests ni specs.*
