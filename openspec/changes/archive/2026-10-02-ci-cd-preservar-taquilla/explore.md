# Exploración — ci-cd-preservar-taquilla

> Estado: exploración completada. No se modificó código ni configuración.

## 1. Contexto

Al hacer deploy a producción (VPS), el instalador de la taquilla
(`Taquilla-Setup-<version>.exe`) deja de estar disponible: la API sigue
reportando la versión en `/api/v1/update-check`, pero el archivo ya no se
puede servir por `/api/v1/releases/serve` (404). El flujo actual es:

1. Build local del instalador (`pnpm electron:build:win` en `taquilla/`).
2. `scp` del `.exe` al VPS → `docker cp` al contenedor `lotto_api_prod:/tmp/`.
3. `docker exec lotto_api_prod php artisan releases:publish /tmp/Taquilla-Setup-<version>.exe`
   (`docs/dev/runbook-ops.md:101-115`).
4. El comando mueve el archivo al disco `releases` y reemplaza la fila única
   en `releases` (D3, sin historial) (`backend/app/Console/Commands/ReleasePublishCommand.php`).
5. La taquilla instalada consulta `GET /api/v1/update-check` (notify-only:
   solo aviso de versión, nunca auto-instala) (`taquilla/src/pages/index.astro:129-150`).

La publicación es un procedimiento **manual**; el CI/CD solo despliega el
backend (`git pull && docker compose ... up -d`), no publica releases.

## 2. Causa raíz (verificada con evidencia)

**La hipótesis inicial es parcialmente correcta: el mecanismo (recreación del
contenedor que borra el archivo) es cierto, pero el detalle es distinto. NO
falta el volumen persistente: existe un named volume `taquilla_releases`, pero
está montado en la RUTA EQUIVOCADA y nunca recibe el archivo.**

Cadena de evidencia:

| Evidencia | Archivo:línea |
|---|---|
| El root de la app en el contenedor es `/app` (WORKDIR desde el primer commit del Dockerfile). | `backend/Dockerfile:5` |
| El entrypoint confirma `/app`: escribe `/app/.env` y sirve desde `/app/public`. | `backend/entrypoint.sh:9,76` |
| El disco `releases` apunta a `storage_path('app/releases')` = `/app/storage/app/releases`. | `backend/config/filesystems.php:50-55` |
| `releases:publish` escribe el `.exe` en ese disco (capa del contenedor). | `backend/app/Console/Commands/ReleasePublishCommand.php:45` |
| **El named volume `taquilla_releases` se monta en `/var/www/html/storage/app/releases` — una ruta que NO existe en la imagen y donde nada escribe.** | `docker-compose.prod.yml:51` |
| El volumen se declara (existe) pero queda vacío/inerte. | `docker-compose.prod.yml:143` |
| El deploy recrea el contenedor: `git pull && docker compose ... pull && docker compose ... up -d`; la imagen `latest` cambia en cada build, forzando recreación. | `.github/workflows/ci-cd.yml:164`; `ci-cd.yml:128-132` |
| Al recrear el contenedor se descarta la capa writable, incluido `/app/storage/app/releases` → el `.exe` se pierde. | (comportamiento de Docker; la fila en MySQL sobrevive) |
| La fila de DB sobrevive (volumen MySQL) → `update-check`/`latest` siguen reportando versión+sha256 leyendo SOLO la DB. | `backend/app/Http/Controllers/Api/ReleaseController.php:86-98`; `backend/app/Models/Release.php:29-32` |
| `serve` devuelve 404 si el archivo no existe en disco → instalador inservible pese a que `update-check` anuncia la versión. | `ReleaseController.php:70-71` |
| El volumen se agregó con la ruta errónea desde el commit original del volumen. | `git show 566525f` (2026-09-01, `chore(infra): volumen taquilla_releases`) |

**Síntoma resultante**: las taquillas ven el aviso "Hay una nueva versión
disponible" (`taquilla/src/pages/index.astro:137-146`) pero el panel no puede
descargar/servir el instalador (404 en `serve` tras pedir URL firmada en
`download`). La documentación actual afirma que el volumen persiste
(`docs/dev/manual-mantenimiento.md:49,194`), lo cual es engañoso: el volumen
existe pero es inefectivo por la ruta.

### Nota de contexto (por qué `/var/www/html`)

`/var/www/html` es la ruta clásica de Laravel Sail/Laradock. El Dockerfile de
este proyecto usó `WORKDIR /app` desde el inicio (commit `e725d00`, imagen
para Render), así que la ruta del volumen fue incorrecta desde el día 1.

## 3. Opciones comparadas

| Opción | Pros | Contras | Complejidad |
|---|---|---|---|
| **A. Corregir la ruta del named volume** (`taquilla_releases:/app/storage/app/releases`) | Cambio de 1 línea; el flujo manual actual (docker cp + releases:publish) sigue igual; sobrevive a redeploys; cero cambios de código/secrets | El named volume NO queda cubierto por el backup restic del host (que respalda rutas de `/home/deploy`, no `/var/lib/docker/volumes/...`) → recuperación del instalador depende de re-subir el `.exe`; requiere verificar migración de la release existente (copy-on-mount de Docker si el volumen está vacío; si no, re-publicar) | Baja |
| **B. Bind mount a ruta del host** (`/home/deploy/lotto-app/storage/app/releases`) | Visible en el host; cubierto por backups restic del host; inspección manual trivial; sobrevive a redeploys | Permisos uid/gid entre contenedor y host (el contenedor escribe como su usuario); requiere `mkdir`/`chown` en el VPS (paso de deploy o script); acopla la ruta al layout del VPS (`VPS_PATH`) | Media |
| **C. Almacenamiento externo (R2/S3) para el instalador** | Durable e independiente del ciclo de vida del contenedor Y del disco del host; precedente en el proyecto (restic → Cloudflare R2, `docs/dev/deploy.md` Fase 14); `serve` ya usa `Storage::disk('releases')` (S3 soporta streaming) | Cambio mayor: cablear `AWS_*` al servicio api (secrets en `.env.production`), el entrypoint fuerza `FILESYSTEM_DISK=local` (`entrypoint.sh:32`) y habría que liberar ese disco o usar un segundo; el archivo es grande (instalador NSIS); más superficie de fallo (red, credenciales); cambia el flujo manual | Alta |
| **D. Publicar/re-publicar la release desde CI/CD tras cada deploy** | El instalador siempre "vivo"; elimina el paso manual | NO arregla la persistencia por sí solo (el archivo seguiría en la capa del contenedor salvo que se combine con A/B/C); build de instalador Windows en CI requiere wine (electron-builder) y el ciclo de releases de la taquilla NO está ligado a deploys del backend — sería re-publicar el mismo `.exe` en cada deploy; alcance mucho mayor | Alta |
| **E. Combinación A + D** | A arregla la causa raíz; D es un complemento futuro (automatizar build+publicación) | D debe ir en un cambio separado; no mezclar | A: Baja; D: Alta |

## 4. Recomendación

**Opción A como fix de causa raíz** (corregir el mount a
`taquilla_releases:/app/storage/app/releases` en `docker-compose.prod.yml:51`),
más:

1. **Corregir la documentación** que afirma la persistencia con la ruta vieja
   (`docs/dev/manual-mantenimiento.md:49,194` y el flujo en §4.4) y actualizar
   el runbook con un paso de verificación post-deploy.
2. **Migración verificable**: al aplicar el fix, validar que la release vigente
   se recupera (copy-on-mount de Docker si el volumen está vacío) o re-publicarla;
   el criterio de éxito es `update-check` y `serve` consistentes (mismo sha256,
   `serve` con URL firmada devuelve 200).
3. **Verificación post-deploy en CI** (opcional, bajo): agregar al job `deploy`
   un chequeo de que `/api/v1/releases/serve` responde 200 tras `up -d`, para
   que un regresión de persistencia falle el deploy en vez de degradar en silencio.

**Opciones B/C**: documentar como evolución futura (B si se quiere cobertura de
backup del instalador; C como hardening con R2, ya hay precedente de
credenciales restic en el host). No incluirlas en este cambio.

## 5. Alcance propuesto

- `docker-compose.prod.yml:51` — corregir la ruta del mount
  (`/var/www/html/storage/app/releases` → `/app/storage/app/releases`).
- `docs/dev/manual-mantenimiento.md` §4.4 y tabla de servicios — corregir la
  ruta real y el estado de persistencia.
- `docs/dev/runbook-ops.md` §"Taquilla Windows release" — paso de verificación
  post-deploy (servir con URL firmada).
- (Opcional, bajo) `.github/workflows/ci-cd.yml` job `deploy` — chequeo post-deploy
  de `releases/serve` (o al menos de que el archivo existe en el volumen:
  `docker exec lotto_api_prod ls -la /app/storage/app/releases`).
- Migración en VPS: validar/recuperar la release vigente tras el primer deploy
  con el mount corregido.

## 6. No-goals

- NO cambiar el flujo notify-only de `update-check` (sin auto-install, sin URL
  en la respuesta) — `ReleaseController.php:86-98`, `index.astro:129-150`.
- NO tocar secretos ni `.env.production` del VPS.
- NO cambiar la semántica D3 de `releases` (fila única, sin historial).
- NO automatizar el build de la taquilla en CI (cambio separado, opción D).
- NO migrar a R2/S3 como almacén de instaladores en este cambio (opción C).
- NO cambiar `FILESYSTEM_DISK` global del entrypoint.

## 7. Requisitos verificables candidatos

- **REQ-1** Tras un deploy (recreación de `lotto_api_prod`), el instalador
  publicado sigue servible: `GET /api/v1/releases/serve` con URL firmada devuelve
  200 y el mismo `sha256` que reporta `update-check`.
- **REQ-2** `releases:publish` escribe el archivo en `/app/storage/app/releases`
  dentro del contenedor (verificable con `docker exec lotto_api_prod ls -la
  /app/storage/app/releases`).
- **REQ-3** El contrato de `update-check` y `latest` no cambia (notify-only,
  solo `version` + `sha256` [+ `published_at`/`file_size` según endpoint]).
- **REQ-4** El volumen `taquilla_releases` queda montado en la ruta real de la
  app (`/app/storage/app/releases`), documentado en `manual-mantenimiento.md` y
  `runbook-ops.md`.
- **REQ-5** (migración) Tras aplicar el cambio, la release vigente se recupera
  o se re-publica; `update-check` y `serve` quedan consistentes (nunca
  versión anunciada sin archivo servible).

## 8. Escenarios de aceptación candidatos (Given/When/Then)

1. **Persistencia tras deploy**: Dado un instalador publicado y `update-check`
   respondiendo versión X; Cuando se ejecuta un deploy de CI/CD exitoso (recreación
   del contenedor); Entonces `GET /api/v1/releases/serve` con URL firmada devuelve
   200 y el sha256 de X, y el archivo existe en `/app/storage/app/releases`.
2. **Nueva publicación**: Dado el flujo manual del runbook; Cuando se ejecuta
   `releases:publish` con un `.exe` nuevo; Entonces `update-check` reporta la
   nueva versión, `serve` la sirve, y la anterior queda reemplazada (D3).
3. **Migración sin release previa**: Dado un VPS donde el volumen
   `taquilla_releases` existe pero está vacío y hay una fila en `releases`;
   Cuando se despliega con el mount corregido; Entonces el archivo se recupera
   (copy-on-mount) o el runbook indica re-publicar, y `serve` deja de devolver 404.
4. **Contrato notify-only intacto**: Dado el fix aplicado; Cuando una taquilla
   con versión distinta consulta `/update-check`; Entonces recibe solo
   `version`+`sha256` (sin URL de descarga) y nunca auto-instala.

## 9. Riesgos

- **Recuperación de la release vigente**: si el volumen `taquilla_releases` ya
  tiene contenido o Docker no hace copy-on-mount, hay que re-publicar el `.exe`
  manualmente (una vez). Mitigación: paso de migración explícito en el cambio.
- **Named volume sin cobertura de backup**: el instalador vive en
  `/var/lib/docker/volumes/...`; restic (host) no lo respalda. Aceptado para
  este cambio (el `.exe` se regenera con build); documentar como limitación.
- **Doc desactualizada**: `manual-mantenimiento.md:49,194` afirma persistencia
  con la ruta vieja; si no se corrige en el mismo cambio, reaparece el error.
- **Dependencia del layout del contenedor**: si algún día cambia `WORKDIR` de la
  imagen, la ruta del mount vuelve a romperse; considerar un comentario en el
  compose y un chequeo post-deploy (REQ-1) como red de seguridad.