# auditoria-integridad Specification

**Estado**: draft

## Purpose

Define la integridad, retención y migración de `audit_events`: tabla append-only (sin UPDATE/DELETE desde la app ni BD en hot), índices mínimos, retención 12 meses hot + 5 años archivo con poda/archivo NDJSON comprimido + checksum, verificación (grants/triggers; hash chain opcional), migración de `juego_auditoria` sin pérdida de historia y cambio de las FKs que hoy destruyen evidencia (`cascade`). Documenta cobertura, consulta, retención y verificación en `docs/dev/auditoria-sistema.md`.

## Requirements

### Requirement: Append-only

`audit_events` MUST ser append-only: la app MUST NOT emitir UPDATE/DELETE. La BD MUST impedirlo con grants/triggers en hot. `actor_id` MUST NOT tener FK cascade (conserva el actor como snapshot incluso si el usuario se borra).

#### Scenario: Sin update/delete desde la app

- GIVEN el modelo `AuditEvent`
- WHEN se intenta update/delete
- THEN no existe ruta para hacerlo (el modelo no lo expone)

#### Scenario: BD rechaza mutación

- GIVEN un trigger/grant en `audit_events`
- WHEN se intenta UPDATE/DELETE directo en SQL
- THEN la BD lo rechaza

#### Scenario: Actor borrado conserva evidencia

- GIVEN un evento cuyo actor es borrado
- WHEN se consulta
- THEN el evento sigue con `actor_label`/`actor_roles` snapshot (sin perder la fila)

### Requirement: Índices mínimos

`audit_events` MUST incluir índices sobre: `occurred_at`, `(action, occurred_at)`, `(actor_id, occurred_at)`, `(entity_type, entity_id, occurred_at)`, `(banca_id, occurred_at)` y `request_id`.

#### Scenario: Índices presentes

- GIVEN la migración de `audit_events`
- WHEN se inspecciona el esquema
- THEN los seis índices existen

### Requirement: Retención y archivo

El sistema MUST retener 12 meses en hot y 5 años en archivo. Un comando de poda/archivo MUST mover eventos hot→archivo y emitir `audit.retention_purged` (rango + conteo). El archivo MUST ser NDJSON comprimido con checksum; el procedimiento de restore MUST estar documentado.

#### Scenario: Poda emite evento

- GIVEN eventos hot con más de 12 meses
- WHEN corre el comando de poda
- THEN se archivan (NDJSON + checksum) y se emite `audit.retention_purged`

#### Scenario: Restore documentado

- GIVEN un archivo NDJSON
- WHEN se consulta `docs/dev/auditoria-sistema.md`
- THEN documenta cómo restaurar y verificar el checksum

### Requirement: Verificación de integridad

La integridad en v1 MUST garantizarse con grants/triggers. Un hash chain opcional (`hash`/`prev_hash`) MAY añadirse si se exige evidencia a prueba de manipulación; un comando de verificación MUST emitir `audit.chain_verified` con el resultado. La decisión del nivel de garantía MUST quedar explícita en la documentación.

#### Scenario: Verificación emite resultado

- GIVEN el comando de verificación
- WHEN se ejecuta
- THEN emite `audit.chain_verified` con resultado (ok/fail) y, si aplica, detecta rotura del chain

#### Scenario: Nivel de garantía explícito

- GIVEN `docs/dev/auditoria-sistema.md`
- WHEN se consulta
- THEN declara si se usa hash chain o solo grants/triggers y por qué

### Requirement: Migración de juego_auditoria

`juego_auditoria` MUST migrarse a `audit_events` sin pérdida de historia (los eventos existentes de juegos se convierten a `AuditAction`). La tabla vieja MUST quedar read-only. No MUST hacerse backfill de scrapers (solo eventos de negocio con actor).

#### Scenario: Historia preservada

- GIVEN filas existentes en `juego_auditoria`
- WHEN se aplica la migración
- THEN se convierten a `audit_events` sin pérdida (mismo actor/acción/campos)

#### Scenario: Tabla vieja read-only

- GIVEN `juego_auditoria` migrada
- WHEN se intenta escribir
- THEN se impide (read-only) y la app ya no escribe ahí

#### Scenario: Sin backfill de scrapers

- GIVEN registros de scrapers
- WHEN se evalúa el backfill
- THEN no se convierten en eventos de auditoría

### Requirement: FKs que preservan evidencia

`pagos.created_by`, `cierres_caja.created_by` y `juego_auditoria.user_id` MUST dejar de ser `cascade` (conservan la fila/evidencia al borrar el usuario). `logs.user_id` MUST seguir `set null` (anonimiza el log operativo). Los eventos MUST usar snapshots de actor.

#### Scenario: FK sin cascade

- GIVEN un usuario referenciado por `pagos.created_by`
- WHEN el usuario se borra
- THEN la fila de pago se conserva (sin cascade) y la auditoría mantiene el snapshot

#### Scenario: logs.user_id set null

- GIVEN un log operativo
- WHEN su usuario se borra
- THEN `logs.user_id` queda null (anonimizado), sin tocar la evidencia de auditoría

### Requirement: Documentación del sistema

`docs/dev/auditoria-sistema.md` MUST documentar cobertura, consulta, retención y verificación. `docs/dev/auditoria-logs.md` MUST NOT tocarse hasta después del merge del ciclo legacy (#81).

#### Scenario: Documentación nueva presente

- GIVEN el cambio aplicado
- WHEN se consulta `docs/dev/auditoria-sistema.md`
- THEN cubre cobertura/consulta/retención/verificación

#### Scenario: auditoria-logs.md intacto

- GIVEN el diff del cambio
- WHEN se inspecciona
- THEN `docs/dev/auditoria-logs.md` no se modifica
