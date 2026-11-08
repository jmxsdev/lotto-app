# auditoria-eventos Specification

**Estado**: draft

## Purpose

Define la capa de escritura de auditoría: envelope común, taxonomía cerrada `domain.verb` (`AuditAction`), catálogo de eventos por dominio con prioridad, mecanismos de captura (`AuditContext`, `AuditRecorder`, trait `Auditable` opt-in, llamadas explícitas, jobs con actor `system`), semántica de fallo (P0 fail-closed / P1 best-effort), motivo (requerido/opcional/automático), redacción de PII y reglas de before/after. Solo se audita el flujo de operadores API y los jobs de sistema; el login del admin legacy y `FetchResultsJob` están fuera de alcance (#81 los retira).

## Requirements

### Requirement: Envelope común

Todo evento MUST persistir un envelope con: `actor_type` (`user|system|device`), `actor_id` (nullable, sin FK cascade), `actor_label` (snapshot email/nombre), `actor_roles` (roles en el momento), `action` (`domain.verb`), `entity_type`, `entity_id`, `scope` (`banca_id|grupo_id|agencia_id|taquilla_id`), `before`/`after` (solo campos cambiados), `context` (`request_id|job_id|queue|source|reason_code|reason_text|route|method`), `ip`, `user_agent` y `schema_version`. El envelope MUST versionarse con `schema_version` para permitir evolución compatible.

#### Scenario: Evento de operador con request

- GIVEN un usuario autenticado que edita una banca
- WHEN `AuditRecorder` persiste `banca.updated`
- THEN el evento incluye actor, scope, before/after, `request_id`, `route`, `ip` y `user_agent`

#### Scenario: Evento de job con actor system

- GIVEN `ScrapeExchangeRateJob` ejecuta
- WHEN registra `exchange_rate.scraped`
- THEN `actor_type=system`, `job_id` y `queue` poblados, `actor_id` null y sin `request_id`

### Requirement: Taxonomía cerrada domain.verb

El sistema MUST definir un enum `AuditAction` con taxonomía `domain.verb`; `action` MUST NOT ser un string libre. El enum MUST estar versionado y los valores obsoletos MUST conservarse (no se eliminan valores en caliente).

#### Scenario: Acción fuera de catálogo rechazada

- GIVEN una escritura con `action` no listada en `AuditAction`
- WHEN `AuditRecorder` la procesa
- THEN la rechaza (no persiste un string libre)

### Requirement: Cobertura por dominio

El catálogo MUST cubrir los dominios y prioridades resumidos en la tabla; la lista exhaustiva (~65 eventos) vive en `explore.md` §4 y no se duplica aquí.

| Dominio | Eventos representativos | Prioridad |
|---|---|---|
| Auth API | `auth.login_succeeded`, `auth.login_failed`, `auth.logout`, `auth.token_revoked` | P0/P1 |
| Usuarios/roles/permisos/password/clave | `user.created`, `user.updated`, `user.role_changed`, `user.permissions_changed`, `user.activated`, `user.deactivated`, `user.deleted`, `user.password_reset_by_admin`, `clave_cierre.updated` | P0 |
| Jerarquía | `banca\|grupo\|agencia\|taquilla` `.created/.updated/.toggled/.deleted`, `.banca_changed`, `.group_changed` | P1/P0 |
| Dinero | `exchange_rate.*`, `commission_defaults.updated`, `cierre.created`, `cierre.reclosed`, `pago.prize_paid`, `ticket.voided`, `bet.voided` | P0/P1 |
| Juegos/límites/config | `game.updated/toggled/prizes_updated/limits_*`, `config.bet_expiration_window_updated` | P0/P1 |
| Resultados/releases/auditoría | `draw.*`, `release.published`, `audit.exported`, `audit.retention_purged`, `audit.chain_verified`, `permission.catalog_synced` | P1 |
| Jobs de sistema | `exchange_rate.scraped`, `draw.result_ingested`, `draw.settlement_completed`, `ticket.expired`, `bet.expired`, `pago.legacy_reconciled` | P0/P1 |

#### Scenario: Acción destructiva cubierta

- GIVEN una acción destructiva (p. ej. `user.deleted`)
- WHEN se ejecuta
- THEN existe un `AuditAction` correspondiente y se registra con prioridad P0

#### Scenario: Evento de negocio no duplicado

- GIVEN `ticket.created`, `bet.created` y `pago.income_created`
- WHEN se evalúa el catálogo
- THEN no tienen evento de auditoría propio (ya son registros de negocio; se asegura `created_by`/`taquilla_id`)

### Requirement: Mecanismos de captura

La captura MUST usar `AuditContext` (request metadata + resolución lazy del actor), `AuditRecorder` (único punto de escritura: normaliza, redacta, versiona) y el trait `Auditable` opt-in para CRUD de alto valor. Los servicios de dinero/seguridad MUST emitir llamadas explícitas. Los jobs MUST usar `actor_type=system`. Un observer global sobre todos los modelos MUST NOT existir.

#### Scenario: Único punto de escritura

- GIVEN cualquier evento
- WHEN se persiste
- THEN pasa por `AuditRecorder` (no hay escritura directa dispersa a `audit_events`)

#### Scenario: Sin observer global

- GIVEN el código aplicado
- WHEN un test/scan busca un observer global de Eloquent
- THEN no existe; la captura es opt-in + llamadas explícitas

### Requirement: Auth solo API

El sistema MUST auditar únicamente `POST /api/v1/login`, `POST /api/v1/logout` y sus fallos/revocación de token. El login del admin legacy MUST NOT auditarse (se retira en #81).

#### Scenario: Login legacy no auditado

- GIVEN el flujo de login del admin legacy
- WHEN se ejecuta
- THEN no emite eventos de auditoría

### Requirement: Semántica de fallo

Los eventos P0 MUST ser fail-closed dentro de la transacción de negocio: si la escritura de auditoría falla, la operación de negocio MUST fallar (rollback). Los eventos P1 SHOULD ser best-effort (registro de error sin romper la operación). Un fallo de escritura P0 MUST NOT silenciarse jamás.

#### Scenario: P0 falla dentro de la transacción

- GIVEN una operación P0 (p. ej. `cierre.reclosed`)
- WHEN la escritura de auditoría falla
- THEN la transacción hace rollback y la operación de negocio no se aplica

#### Scenario: P1 degrada sin romper

- GIVEN una operación P1
- WHEN la escritura de auditoría falla
- THEN la operación de negocio se aplica y el fallo se registra en el log de errores

### Requirement: Motivo

El motivo (`reason_code` + `reason_text`) MUST ser requerido en acciones destructivas y de cambio de acceso; opcional en el resto; automático cuando el sistema lo deriva (p. ej. `auth.login_failed`). `reason_code` MUST validarse contra un enum y `reason_text` MUST tener límite de longitud. El motivo MUST NOT contener secretos.

#### Scenario: Destructiva sin motivo

- GIVEN `user.deleted` sin `reason_code`
- WHEN se intenta registrar
- THEN se rechaza (el motivo es requerido)

#### Scenario: Motivo con secreto

- GIVEN un `reason_text` que contiene un token o password
- WHEN se redacta
- THEN el secreto se elimina o enmascara

### Requirement: Redacción de PII

`AuditRedactor` MUST redactar PII antes de persistir: email, MAC, fingerprint, códigos de activación, motivos con datos sensibles, tokens y password/hashes. Ningún secreto MUST quedar en `before`/`after` ni en `context`. La redacción MUST ser determinista e idempotente.

#### Scenario: Email enmascarado

- GIVEN un actor con email
- WHEN se persiste `actor_label`
- THEN el email se enmascara (p. ej. `u***@dominio`) o se omite, según la regla definida

#### Scenario: Hash de password nunca presente

- GIVEN `user.password_reset_by_admin`
- WHEN se captura before/after
- THEN el hash del password no aparece

### Requirement: Before/after

`before`/`after` MUST contener solo los campos cambiados (diff); en delete MUST ser snapshot completo del registro previo; en create, `before` MUST ser null. `before`/`after` MUST NOT contener secretos y los valores MUST normalizarse (tipos/JSON estables).

#### Scenario: Create con before null

- GIVEN `banca.created`
- WHEN se registra
- THEN `before=null` y `after` contiene solo campos seguros

#### Scenario: Delete con snapshot

- GIVEN `user.deleted`
- WHEN se registra
- THEN `before` contiene el snapshot completo (sin secretos) y `after=null`
