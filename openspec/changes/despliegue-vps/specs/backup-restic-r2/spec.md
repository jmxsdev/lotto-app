---
capability: backup-restic-r2
change: despliegue-vps
status: done
---

# backup-restic-r2 Specification

## Purpose

Backups automatizados de la base de datos MySQL hacia Cloudflare R2 (compatible S3, tier free 10 GB) mediante restic: dump diario comprimido, política de retención, prueba de restauración mensual y alerta ante fallos. Los backups empiezan el primer día del despliegue.

## Requirements

| # | Requirement | Strength | Estado |
|---|------------|----------|--------|
| REQ-1 | Backup diario a R2 | MUST | draft |
| REQ-2 | Retención 7+4+6 | MUST | draft |
| REQ-3 | Prueba de restauración mensual | MUST | draft |
| REQ-4 | Alerta por fallo de backup | MUST | draft |
| REQ-5 | Almacenamiento dentro del tier free | SHOULD | draft |

### Requirement: REQ-1 — Backup diario a R2

El sistema MUST ejecutar un backup diario que realice `mysqldump` de la base de datos, comprima con gzip y lo almacene con restic en un bucket Cloudflare R2 (endpoint S3-compatible), incluyendo las credenciales R2 solo desde el archivo de entorno del VPS. Un backup exitoso MUST producir un snapshot restic nuevo y verificable.

#### Scenario: SCENARIO-1 — Snapshot diario creado

- GIVEN el cron/scheduler de backup activo en el VPS
- WHEN el backup diario se ejecuta
- THEN `restic snapshots` muestra un snapshot nuevo del día
- AND `restic check` sobre el snapshot no reporta errores

#### Scenario: SCENARIO-2 — Contenido íntegro del dump

- GIVEN el último snapshot restic
- WHEN se restaura el archivo del dump y se abre con gzip
- THEN el dump descomprimido es un mysqldump válido con las tablas esperadas
- AND su tamaño es coherente con el de la base de datos origen

### Requirement: REQ-2 — Retención 7+4+6

El sistema MUST aplicar la política de retención: 7 snapshots diarios, 4 semanales y 6 mensuales. Los snapshots fuera de esa política MUST eliminarse automáticamente con `restic forget --prune`.

#### Scenario: SCENARIO-3 — Snapshots antiguos purgados

- GIVEN un repositorio con 60 snapshots diarios acumulados
- WHEN se ejecuta la política de retención
- THEN quedan como máximo 7 diarios, 4 semanales y 6 mensuales
- AND el prune libera el espacio de los snapshots eliminados

### Requirement: REQ-3 — Prueba de restauración mensual

El sistema MUST ejecutar mensualmente una prueba de restauración completa: restaurar el último snapshot en un entorno aislado, importar el dump en una base MySQL temporal y validar la integridad de los datos (conteo de registros de tablas clave).

#### Scenario: SCENARIO-4 — Restauración probada con éxito

- GIVEN la prueba mensual de restauración programada
- WHEN se restaura el último snapshot en el entorno aislado y se importa el dump
- THEN la importación termina sin errores
- AND los conteos de registros de tablas clave coinciden con los de producción
- AND el resultado de la prueba queda registrado (log o artefacto)

#### Scenario: SCENARIO-5 — Fallo de restauración queda visible

- GIVEN una prueba de restauración con dump corrupto o importación fallida
- WHEN la prueba termina
- THEN el sistema registra el fallo
- AND se envía la alerta correspondiente por Telegram

### Requirement: REQ-4 — Alerta por fallo de backup

Ante un backup diario fallido (dump, compresión o subida a R2), el sistema MUST notificar por Telegram en un plazo máximo de 24 h desde la ejecución programada. El backup fallido MUST poder reintentarse manualmente con un comando documentado.

#### Scenario: SCENARIO-6 — Backup fallido notificado

- GIVEN una ejecución de backup con error (p. ej., R2 no alcanzable)
- WHEN la ejecución termina con código distinto de éxito
- THEN se envía la alerta de fallo por Telegram
- AND la alerta indica el paso que falló y el timestamp

#### Scenario: SCENARIO-7 — Reintento manual

- GIVEN un backup fallido notificado
- WHEN el operador ejecuta el comando de reintento documentado
- THEN se crea un snapshot exitoso
- AND la siguiente alerta de fallo ya no se emite para ese día

### Requirement: REQ-5 — Almacenamiento dentro del tier free

El consumo de R2 SHOULD mantenerse dentro del tier free (10 GB) mediante la política de retención; si el volumen de datos lo excede, el sistema SHOULD registrar una advertencia de crecimiento.

#### Scenario: SCENARIO-8 — Crecimiento dentro del tier

- GIVEN el repositorio con retención aplicada y una BD de ~2 GB
- WHEN se consulta el uso del bucket
- THEN el almacenamiento total se mantiene por debajo de 10 GB
- AND la retención impide acumulación indefinida de snapshots
