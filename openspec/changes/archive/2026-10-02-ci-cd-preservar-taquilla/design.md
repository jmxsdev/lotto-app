# Design: ci-cd-preservar-taquilla

## Enfoque técnico

Corregir la ruta del named volume `taquilla_releases` a la ruta real de la app
(`/app/storage/app/releases`, `WORKDIR /app` de `backend/Dockerfile:5`) y
documentarla. El flujo manual (`docker cp` + `releases:publish`) no cambia; el
`.exe` pasa al volumen y sobrevive a la recreación de `lotto_api_prod`.
Complementos: migración verificable (REQ-5) y chequeo post-deploy en CI (REQ-6).
Opciones B/C quedan fuera de alcance (propuesta §Out of Scope).

## Decisiones de arquitectura

| # | Decisión | Opciones | Elección / razón |
|---|---|---|---|
| 1 | Persistencia | A: corregir ruta a `/app/storage/app/releases` · B: bind mount host · C: R2/S3 | **A**. Cambio de 1 línea, sin permisos ni secrets; el disco `releases` (`filesystems.php:50-55`) ya apunta a `storage/app/releases`. B acopla `VPS_PATH` y exige `chown`; C está fuera de alcance y choca con `FILESYSTEM_DISK=local` (`entrypoint.sh:32`). |
| 2 | Migración | Copy-on-mount (esperanza) · rescue+re-publicar | **Rescue + re-publicar**. Docker solo copia al volumen vacío el contenido **de la imagen** en el mountpoint, en el primer `up`. `storage/app/.gitignore` excluye `releases/` de la imagen (`*` salvo `private/`, `public/`), así que no hay nada que copiar: el `.exe` vivía en la capa escribible del contenedor previo y se pierde al recrearlo. **Copy-on-mount NO recupera la release.** |
| 3 | Verificación post-deploy (SHOULD) | `serve` firmado · `ls` en volumen · `docker inspect` mounts + `sha256sum` | **inspect + sha256 condicional**. El chequeo estructural (mount destino correcto) corre siempre; si `update-check` responde 200, se compara `sha256` de `update-check` con `sha256sum` del `.exe` en el volumen. Evita el race de "sin release publicada" y no exige generar URL firmada. |
| 4 | Documentación | Corregir §2.1/§4.4 + runbook + comentario compose | **Sí, en este cambio**; la doc afirma persistencia con la ruta vieja (`manual-mantenimiento.md:49,193-194`). |

## Flujo de datos

```
push a main → CI tests/Pint → build+push GHCR → deploy SSH:
  VPS: git pull → compose pull → up -d  (recrea lotto_api_prod)
       volumen taquilla_releases:/app/storage/app/releases (CORREGIDO)
  → releases:publish escribe /app/storage/app/releases (volumen)
  → update-check 200 (DB) + serve firmado 200 (archivo en volumen)
  → chequeo CI: mount destino + sha256(update-check) == sha256(archivo)
```

Rollback: `git revert` del mount + `docker compose up -d`. Docker **no** borra
named volumes en `up -d`; el volumen persiste. Revertir vuelve al bug; el `.exe`
del volumen queda invisible pero no se destruye. **Nunca** usar `down -v` en rollback.

## Migración verificable (VPS)

Pre-deploy (contenedor viejo aún vivo): `docker exec lotto_api_prod ls -la /app/storage/app/releases/` y `docker cp lotto_api_prod:/app/storage/app/releases/Taquilla-Setup-<v>.exe /tmp/`. Si no existe (un deploy previo ya lo perdió), obtener el `.exe` del build (`taquilla/release/`) por `scp` a `/tmp/`. Post-deploy: `docker cp /tmp/…exe lotto_api_prod:/tmp/` + `docker exec lotto_api_prod php artisan releases:publish /tmp/…exe --release-version=<v>`. Verificar: `sha256sum` del archivo en el contenedor == `update-check.sha256` (equivale a `serve` 200). Criterio REQ-5.

## Cambios de archivos (orden)

| Orden | Archivo | Acción | Descripción |
|---|---|---|---|
| 1 | `docker-compose.prod.yml` | Modify | Línea 51: `/var/www/html/...` → `/app/storage/app/releases`. Comentario: ruta atada a `WORKDIR=/app`. |
| 2 | `docs/dev/manual-mantenimiento.md` | Modify | §2.1 tabla de servicios (ruta del volumen), línea 55 (ref. `:110-115`→`:138-143`), §4.4 (flujo, líneas 193-194), registro línea 354. |
| 3 | `docs/dev/runbook-ops.md` | Modify | §"Taquilla Windows release" paso 5: verificar archivo en `/app/storage/app/releases` + `sha256sum`; nota de migración. |
| 4 | `.github/workflows/ci-cd.yml` | Modify | Job `deploy`, tras el healthcheck (`ok=0`, ~línea 171) y antes de `echo "deploy OK"`: chequeo de mount y, si hay release, sha256. Falla el deploy (`exit 1`) **sin** rollback de imagen (la causa es volumen/ruta, no la imagen). |

**Orden recomendado**: pasos 1–3 en el primer work unit → migración manual verificada → paso 4 en un commit/PR posterior. Habilitar el chequeo CI antes de migrar haría fallar el primer deploy cuando la DB anuncia una versión ya perdida.

## Threat matrix

| Boundary | Applicability | Respuesta |
|---|---|---|
| Documentation-like paths | N/A: no se clasifican ni ejecutan rutas docs. | — |
| Git repository selection | N/A: no se agregan `git -C`/selectores. | — |
| Commit state | N/A: sin automatización de commits. | — |
| Push state | N/A: sin cambios de push. | — |
| PR commands | N/A: sin automatización de PR. | — |
| Nuevo shell/SSH (CI) | Aplicable (read-only) | `docker inspect`/`exec ls`/`sha256sum` sobre `lotto_api_prod` con nombre fijo, sin input externo. Falla → `exit 1` (deploy rojo). RED test = chequeo CI + verificación manual de migración. |

## Testing

Sin código PHP nuevo; los tests de `ReleaseController` no cambian (REQ-3 intacto). La regresión se cubre con el chequeo CI y la verificación manual por comando. `composer test` debe seguir verde.

## Open questions

- [ ] ¿El `.exe` vigente aún existe en el contenedor prod actual o hay que re-construirlo? Se resuelve en el paso pre-deploy.
