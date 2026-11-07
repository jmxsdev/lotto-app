# Sistema de auditoría (#89)

> Documento operativo y técnico de la auditoría de cambios: qué se audita, dónde se
> escribe, cómo se lee, cómo se protege la evidencia, cómo se archiva y cómo se
> despliega. Complementa [`permisos-capabilities.md`](permisos-capabilities.md)
> (acciones por rol) y [`alcance-jerarquico.md`](alcance-jerarquico.md) (filas por rol).
>
> Fuentes en código: `backend/config/audit.php`, `backend/app/Audit/**`,
> `backend/app/Console/Commands/Audit*.php`, `backend/routes/api.php` y
> `backend/database/sql/audit_events_grants.sql`.

## Ruta rápida

1. La evidencia vive en la tabla **append-only** `audit_events`; `logs` queda como log operativo.
2. La escritura pasa **solo** por `AuditRecorder`; los eventos P0 fallan cerrado dentro de la transacción de negocio.
3. La lectura es `GET /api/v1/audit-events` (+ `/export`) con `view_audit`/`export_audit` y `/auditoria` en el panel.
4. La integridad se sostiene en tres capas: modelo, triggers MySQL y grants. MySQL necesita `log_bin_trust_function_creators=1`.
5. La retención es 12 meses hot + 5 años en NDJSON.gz con checksum; se opera con `audit:archive/prune/verify/backfill`.

---

## 1. Alcance

| Se audita | No se audita |
|---|---|
| Autenticación API (`login` éxito/fallo, `logout`, revocación de token) | Lecturas, listados, reportes y health checks |
| Activación de taquilla y verificación de dispositivo | Login del admin legacy (`routes/web.php`, `Admin/**`) |
| Usuarios, roles y permisos efectivos | `FetchResultsJob` (lo retira #81) |
| Jerarquía (banca, grupo, agencia, taquilla) | Detalle operativo de scrapers (sigue en `logs`) |
| Dinero (tasas, comisiones, cierre, pagos, anulaciones) | `ticket.created`, `bet.created`, `pago.income_created` (ya son negocio) |
| Juegos, premios, límites y ventana de vencimiento | Fallos de cola y 403 genéricos |
| Jobs de sistema, resultados, releases y comandos | Update-check y feed de releases |

Los eventos se nombran `domain.verb` con un enum cerrado (`App\Audit\AuditAction`):
`auth.login_succeeded`, `user.role_changed`, `exchange_rate.scraped`,
`ticket.expired`, `audit.retention_purged`, `audit.chain_verified`,
`backfill.completed`, entre otros.

## 2. Escritura

| Pieza | Responsabilidad |
|---|---|
| `AuditContext` | Metadatos del request (request-id, IP, UA, ruta, método, cliente), actor lazy y contexto de sistema. |
| `AuditRecorder` | Único punto de escritura: prioridad, transacción, redacción, idempotencia y hash opcional. |
| `AuditRedactor` | Redacción determinista de PII/secretos antes de persistir. |
| `AuditScopeResolver` | Deriva `banca_id`/`grupo_id`/`agencia_id`/`taquilla_id` desde el actor o la entidad. |
| `Auditable` | Trait opt-in de snapshots/diffs; no dispara auditoría solo. |

Reglas de prioridad:

| Prioridad | Comportamiento |
|---|---|
| **P0** | Se escribe dentro de `DB::transaction`; si falla, rollback del negocio y 500. |
| **P1/P2** | Best-effort: el fallo se reporta (`Log::error`) y la operación continúa. |

`AUDIT_FAIL_CLOSED` y `AUDIT_FAIL_CLOSED_DOMAINS` controlan qué dominios P0
abortan la operación. Con `*` (default) todos los P0 son fail-closed.

Motivos: cada acción declara `required`, `optional` o `automatic` (`ReasonPolicy`).
Un motivo requerido ausente devuelve 422 y, en P0, revierte.

Idempotencia: `event_uuid` es único. En vivo se genera UUIDv4; en backfill es
determinista (`sha1('logs:<id>')`, `sha1('juego_auditoria:<id>')`). Un duplicado es
éxito, no error.

## 3. Lectura

| Endpoint | Permiso | Notas |
|---|---|---|
| `GET /api/v1/audit-events` | `view_audit` | Cursor (`{data,next_cursor,prev_cursor}`), orden `occurred_at DESC, id DESC`. |
| `GET /api/v1/audit-events/export` | `export_audit` | CSV con tope, escape de fórmulas y evento `audit.exported`. |

Filtros: `action`, `domain`, `actor_id`, `actor_type`, `entity_type`, `entity_id`,
`from`, `to`, `request_id`, `reason_code`; `per_page` default 25, máximo 100.
Filtros desconocidos → 422. El scope lo aplica `AlcanceService::applyToAuditoria`:
`super_master` ve todo; `master` solo sus bancas; un evento con `banca_id = null`
es invisible para `master`.

Export CSV: `AUDIT_EXPORT_MAX_ROWS` (default 50000), streaming por chunks, BOM
UTF-8 y prefijo `'` en celdas que empiezan con `=`, `+`, `-`, `@`, tab o CR.
Al terminar emite `audit.exported` P1.

Panel:

- `/auditoria`: filtros, tabla, detalle diff y export; link visible con `view_audit`.
- `/logs`: registro **operativo** (allowlist de acciones, paginación, banner
  “no es auditoría”), con `view_logs`.

## 4. Integridad

Tres capas independientes:

| Capa | Mecanismo |
|---|---|
| Aplicación | `AuditEvent::updating/deleting` lanzan `AuditAppendOnlyException`; no hay rutas de update/delete. |
| Base de datos | Triggers `audit_events_no_update` y `audit_events_no_delete` (MySQL `SIGNAL SQLSTATE '45000'`). |
| Permisos | `audit_events_grants.sql`: app `SELECT, INSERT`; retención `SELECT, DELETE`. |

### 4.1 Requisito MySQL: `log_bin_trust_function_creators=1`

Con el binlog activo y un usuario sin `SUPER`, MySQL rechaza crear triggers con
el error **1419**:

```text
ERROR 1419 (HY000): You do not have the SUPER privilege and binary logging is enabled
(you *might* want to use the less safe log_bin_trust_function_creators variable)
```

La migración de guards (`2026_11_05_000005_add_audit_append_only_guards.php`) crea
los triggers; por eso el flag debe estar activo **antes** de migrar.

Local (runtime):

```bash
docker exec -it lotto_mysql mysql -u root -p
# dentro de MySQL:
SET GLOBAL log_bin_trust_function_creators = 1;
```

Persistencia: `docker-compose.yml` pasa `--log-bin-trust-function-creators=1` al
servicio `mysql`, de modo que sobrevive a reinicios. `docker-compose.prod.yml` lo
incluye en el `command` de MySQL por la misma razón.

### 4.2 Triggers y bypass de retención

- `audit_events_no_update`: bloquea todo `UPDATE`.
- `audit_events_no_delete`: bloquea `DELETE` salvo que la sesión haga
  `SET @audit_retention = 1`; `audit:archive` lo activa y lo revierte en `finally`.

Los triggers cubren `UPDATE`/`DELETE`, **no** `DROP TABLE`: el DDL sigue siendo
responsabilidad del usuario de migración.

### 4.3 Grants y mínimo privilegio

`backend/database/sql/audit_events_grants.sql` se aplica fuera de migraciones, por
entorno. Usuarios recomendados:

| Usuario | Privilegios | Uso |
|---|---|---|
| `lotto_migrator` | DDL sobre el esquema | Solo migraciones. |
| `lotto_app` | `SELECT, INSERT` en `audit_events` (sin `UPDATE`/`DELETE`, sin DDL) | Runtime de la API, Horizon y scheduler. |
| `lotto_retention` | `SELECT, DELETE` en `audit_events` | Solo `audit:archive`. |

El usuario de runtime nunca debe tener DDL ni `UPDATE`/`DELETE` sobre
`audit_events`. Verifica con `SHOW GRANTS FOR 'lotto_app'@'%';`.

### 4.4 Hash chain opcional

`AUDIT_HASH_CHAIN=false` por defecto: la garantía v1 son triggers + grants. Con
`true`, `AuditChainHasher` encadena `hash`/`prev_hash` bajo un lock global por
escritura. `audit:verify` recalcula y reporta el primer `id` roto.

Backfill y cadena son incompatibles por orden cronológico: con la cadena activa,
`audit:backfill --apply` exige `--allow-chain` y las filas quedan fuera de la
cadena. Recomendación: **backfill antes de activar la cadena**.

## 5. Retención y archivo

| Ventana | Destino |
|---|---|
| 12 meses | Tabla hot `audit_events`. |
| 5 años | Archivo NDJSON.gz en el disco de archivo. |

Ruta por defecto: `storage/app/private/audit/archive/` (disco `local`,
`AUDIT_ARCHIVE_PATH=audit/archive`). Cada archivo
`audit-YYYYMMDD-HHMMSS.ndjson.gz` lleva:

- `.sha256` con el checksum del archivo.
- `.manifest.json` con `{file, sha256, count, from, to, months, created_at}`.

Comandos (todos dry-run por defecto):

| Comando | Flags | Efecto con `--apply` |
|---|---|---|
| `audit:archive` | `--months --from --to --chunk --apply --disk` | Escribe NDJSON.gz + checksum + manifest, borra hot por chunks y emite `audit.retention_purged`. |
| `audit:prune` | `--years --apply --force --dry-run` | Elimina archivos > 5 años verificando el checksum cuando existe (salvo `--force`). |
| `audit:verify` | `--from --to --archive --strict` | Verifica checksums, NDJSON y, si está activa, la cadena; exit 1 ante rotura. |
| `audit:backfill` | `--source --from --to --limit --apply --allow-chain` | Convierte `logs`/`juego_auditoria` de forma idempotente y emite `backfill.completed`. |

La conexión de retención se configura con `AUDIT_RETENTION_CONNECTION`. Por defecto
usa `DB_CONNECTION`; en producción setea `AUDIT_RETENTION_CONNECTION=audit_retention`
para usar la conexión dedicada con `DB_RETENTION_HOST`, `DB_RETENTION_PORT`,
`DB_RETENTION_DATABASE`, `DB_RETENTION_USERNAME` y `DB_RETENTION_PASSWORD`. Ese
usuario es el único con `DELETE`, y el trigger exige además `@audit_retention = 1`.

Ejemplos:

```bash
# Ver qué se archivaría (sin tocar datos)
php artisan audit:archive

# Archivar y podar
php artisan audit:archive --apply
php artisan audit:prune --apply

# Verificar integridad (sale 1 si algo está roto)
php artisan audit:verify --strict
```

## 6. Restore

Restaurar un archivo **no es un comando programado**; es una operación manual de
mantenimiento:

1. Verificar el checksum antes de descomprimir:

   ```bash
   cd storage/app/private/audit/archive
   FILE=audit-YYYYMMDD-HHMMSS.ndjson.gz
   test "$(sha256sum "$FILE" | cut -d' ' -f1)" = "$(tr -d '\n' < "$FILE.sha256")" \
     && echo "checksum OK"
   ```

2. Descomprimir y validar que cada línea sea JSON con `event_uuid`:

   ```bash
   gzip -dc audit-YYYYMMDD-HHMMSS.ndjson.gz \
     | jq -c 'select(.event_uuid != null) | .event_uuid'
   ```

3. Cargar en una tabla staging y comparar `event_uuid` contra `audit_events`.

4. Reinsertar solo los que no existan, con `context.restored = true`. **Nunca**
   sobrescribir filas existentes ni pasar por `AuditRecorder` (ese camino generaría
   eventos nuevos). El `event_uuid` original es la clave de idempotencia.

## 7. Despliegue

### 7.1 Pipeline

1. **CI**: tests contra MySQL (root en el service container), Pint y build.
2. **Build/push**: imagen `ghcr.io/jmxsdev/lotto-app-api`.
3. **Deploy SSH**: `docker compose pull` y `up -d` en el VPS.
4. **Arranque**: `entrypoint.sh` del contenedor `api` ejecuta
   `php artisan migrate --force` **antes** de levantar FrankenPHP. Los triggers se
   crean ahí, no durante el build. Horizon y scheduler omiten migraciones
   (`RUN_HORIZON`/`RUN_SCHEDULER`).

### 7.2 Orden obligatorio

| Paso | Acción | Por qué |
|---|---|---|
| 1 | Activar `log_bin_trust_function_creators=1` en MySQL | Sin esto la migración de guards falla con 1419. |
| 2 | Desplegar / migrar | El `api` crea `audit_events`, `audit_chain_state` y los triggers. |
| 3 | Aplicar grants (`audit_events_grants.sql`) | Necesitan que la tabla ya exista. |
| 4 | Verificar triggers | `SHOW TRIGGERS` debe listar los dos guards. |

Verificación:

```sql
SHOW TRIGGERS WHERE `Table` = 'audit_events';
SHOW GRANTS FOR 'lotto_app'@'%';
```

### 7.3 Compose

- `docker-compose.yml`: MySQL arranca con `--log-bin-trust-function-creators=1`
  para que el flag sobreviva a reinicios locales.
- `docker-compose.prod.yml`: mismo flag en el `command` de MySQL; `horizon` y
  `scheduler` declaran `depends_on: api: service_healthy` además de `mysql`/`redis`,
  para no arrancar antes de que el `api` termine sus migraciones.
- El healthcheck del `api` corre después de migrar; un fallo de migración deja el
  contenedor unhealthy y bloquea a los workers.

### 7.4 Rollback

La imagen puede revertirse con `IMAGE_TAG`, pero el **DDL no es transaccional**:
la tabla y los triggers persisten. El rollback real de esquema se hace con
`php artisan migrate:rollback` (con el flag activo) o restaurando el backup; los
grants aplicados permanecen y deben revisarse a mano.

## 8. Operación

### 8.1 Scheduler

`backend/routes/console.php` programa las tareas de retención con
`withoutOverlapping()`:

| Tarea | Cadencia | Hora |
|---|---|---|
| `audit:archive --apply` | Mensual, día 1 | 03:00 |
| `audit:prune --apply` | Anual, 1 de enero | 04:00 |
| `audit:verify` | Semanal, lunes | 05:00 |

Ver la agenda real:

```bash
docker exec lotto_scheduler_prod php artisan schedule:list
```

### 8.2 Backups y RPO/RTO

- El dump de BD incluye `audit_events` hot.
- El disco de archivo (NDJSON.gz) se respalda **por separado** y debe cubrir el
  mismo periodo legal que la BD.
- RPO/RTO: definir según el backup de BD; el archivo restaurable acota la pérdida
  al último snapshot verificado.
- `audit:verify --strict` es la comprobación de integridad previa a cualquier
  restauración.

### 8.3 Monitoreo

- Crecimiento de `audit_events` y tamaño del disco de archivo.
- Resultado de `audit:verify` (exit code ≠ 0 = rotura).
- Fallos de escritura P1 quedan en logs; vigilar su tasa antes de ampliar
  `AUDIT_FAIL_CLOSED_DOMAINS`.
- Retención legal 12 meses hot / 5 años archivo **pendiente de confirmación legal**.

## 9. Rollout y rollback

| Flag | Default | Uso |
|---|---|---|
| `AUDIT_ENABLED` | `true` | Kill switch; `false` detiene la captura sin borrar evidencia. |
| `AUDIT_FAIL_CLOSED` | `true` | `false` degrada P0 a best-effort (solo emergencia). |
| `AUDIT_FAIL_CLOSED_DOMAINS` | `*` | CSV de dominios fail-closed; se amplía por fase. |
| `AUDIT_HASH_CHAIN` | `false` | Activa la cadena criptográfica. |

Fases: esquema aditivo → shadow/dual-write → cutover de lectura → fail-closed por
dominio → migración legacy → cierre. Cada WU se revierte con `git revert`.

Rollback por WU:

- Las WUs de captura son aditivas; revertirlas no altera el negocio.
- La migración de `juego_auditoria` y el cambio de FKs (`pagos.created_by`,
  `cierres_caja.created_by`) son las **únicas** que exigen backup/staging.
- `AUDIT_ENABLED=false` detiene la captura sin perder evidencia;
  `AUDIT_FAIL_CLOSED=false` restaura disponibilidad a costa de evidencia P0.

## 10. Seguimiento

- [ ] Confirmar con legal la retención 12 meses hot + 5 años archivo.
- [ ] Decidir si se exige hash chain (`AUDIT_HASH_CHAIN=true`).
- [ ] Aplicar grants por entorno (`lotto_app`/`lotto_retention`) y verificar con
  `SHOW GRANTS`.
- [ ] Confirmar el flag `log_bin_trust_function_creators=1` en producción antes de
  la primera migración de guards.
- [ ] Revisar si `master` debe ver eventos de configuración global (v1:
  `null scope ⇒ super_master`).
