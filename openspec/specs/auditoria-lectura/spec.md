# auditoria-lectura Specification

**Estado**: draft

## Purpose

Define la capa de lectura de auditoría: `GET /api/v1/audit-events` con cursor pagination y filtros, autorización `view_audit` + scope `AlcanceService` (super_master global, master su banca, demás 403), redacción por rol, export CSV con `export_audit` (emite `audit.exported`), visor panel `/auditoria` sin XSS y la corrección de `/logs` operativo (contrato `logs.data`, paginación, escape, banner "operativo", acceso con `view_logs` para master y super_master, filtrado a acciones operativas).

## Requirements

### Requirement: Endpoint de lectura con cursor pagination

`GET /api/v1/audit-events` MUST devolver cursor pagination (no offset) con respuesta `{data, next_cursor, prev_cursor}`. MUST aceptar filtros por `action`/`domain`, `actor`, `entity_type`/`entity_id`, rango de fechas (`from`/`to`) y `request_id`. `per_page` MUST tener un tope máximo.

#### Scenario: Listado con cursor

- GIVEN eventos existentes y un actor autorizado
- WHEN consulta `GET /api/v1/audit-events?per_page=25`
- THEN recibe `{data, next_cursor, prev_cursor}` con `data` acotado y cursor para la siguiente página

#### Scenario: Filtro por action y fecha

- GIVEN el actor filtra `action=user.deleted` con `from` y `to`
- WHEN consulta
- THEN devuelve solo los eventos que coinciden

#### Scenario: per_page excedido

- GIVEN `per_page` superior al tope
- WHEN consulta
- THEN se acota al tope (sin paginar sin límite)

### Requirement: Autorización por scope

La lectura MUST exigir `permission:view_audit`. El alcance MUST delegarse en `AlcanceService`: `super_master` ve global (todas las bancas), `master` ve solo sus bancas, y cualquier otro rol MUST recibir 403.

#### Scenario: super_master global

- GIVEN un `super_master`
- WHEN consulta `audit-events`
- THEN ve eventos de todas las bancas

#### Scenario: master acotado a sus bancas

- GIVEN un `master`
- WHEN consulta
- THEN ve solo eventos de las bancas que administra

#### Scenario: rol sin permiso 403

- GIVEN un `banca`, `grupo`, `agencia` o `taquilla`
- WHEN consulta `audit-events`
- THEN recibe 403

### Requirement: Redacción por rol

La respuesta MUST redactar según el rol del lector; MUST NOT devolver el `User` completo ni secretos. `actor_label` y `actor_roles` (snapshots ya redactados en escritura) MUST preservarse.

#### Scenario: Nunca User completo

- GIVEN la respuesta de listado
- WHEN se inspecciona
- THEN no incluye la relación `User` completa (solo `actor_label`/`actor_roles` snapshot)

#### Scenario: Sin secretos en respuesta

- GIVEN eventos con PII redactada
- WHEN se leen
- THEN no se desenmascara ni se filtra información sensible

### Requirement: Export CSV

`GET /api/v1/audit-events/export` MUST exigir `permission:export_audit`, aplicar los mismos filtros y scope que la lectura, devolver CSV y emitir `audit.exported` (filtros + conteo). El CSV MUST redactar igual que la lectura.

#### Scenario: Export autorizado

- GIVEN un `master` con `export_audit`
- WHEN exporta con filtros
- THEN recibe CSV acotado a su scope y se registra `audit.exported`

#### Scenario: Export sin permiso 403

- GIVEN un usuario sin `export_audit`
- WHEN exporta
- THEN recibe 403

### Requirement: Visor panel /auditoria

El panel MUST exponer `/auditoria` con filtros, tabla, cursor pagination, detalle con diff before/after y botón de export. Todo valor de usuario MUST escaparse (textContent/templates); MUST NOT usar `innerHTML` con datos de usuario.

#### Scenario: Detalle con diff

- GIVEN un evento seleccionado
- WHEN se abre el detalle
- THEN muestra `before`/`after` como diff

#### Scenario: Sin XSS por innerHTML

- GIVEN un valor de evento que contiene HTML/script
- WHEN se renderiza la tabla o el detalle
- THEN se escapa (no se interpreta como HTML)

### Requirement: /logs operativo

`/logs` MUST quedar como log operativo: contrato `logs.data` (la página no llama `logs.filter`), paginación correcta, escape de valores, banner "operativo" y acceso con `view_logs` (master y super_master), filtrado a acciones operativas.

#### Scenario: Contrato logs.data

- GIVEN la página `/logs`
- WHEN consume el endpoint
- THEN lee `logs.data` (no asume array directo ni `logs.filter`)

#### Scenario: Acceso con view_logs

- GIVEN un usuario sin `view_logs`
- WHEN abre `/logs`
- THEN no accede (403)

#### Scenario: Master solo ve operativos

- GIVEN un `master` con `view_logs`
- WHEN abre `/logs`
- THEN accede y solo ve acciones operativas (scrapers, activación, jobs), no eventos de auditoría

### Requirement: Permisos en catálogo

`view_audit` y `export_audit` MUST existir en el catálogo y la matriz de `catalogo-permisos` (solo `master`; `super_master` por bypass). El cambio MUST mantener coherente `PermissionCatalogTest` (sus conteos exactos se actualizan a 36 permisos y `master` 35, sin relajar sus invariantes).

#### Scenario: Catálogo coherente

- GIVEN el catálogo aplicado
- WHEN `PermissionCatalogTest` se ejecuta
- THEN `view_audit`/`export_audit` existen y los nombres previos se preservan con guard `api`
