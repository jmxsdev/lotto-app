# Proposal: ci-cd-preservar-taquilla

## Intent

El instalador de la taquilla (`Taquilla-Setup-<version>.exe`) deja de servirse tras cada deploy a producción: `update-check` anuncia la versión, pero `GET /api/v1/releases/serve` devuelve 404. Causa raíz verificada: el named volume `taquilla_releases` está montado en `/var/www/html/storage/app/releases` (ruta inexistente; el WORKDIR de la imagen es `/app`), por lo que `releases:publish` escribe en la capa del contenedor y el archivo se pierde al recrear `lotto_api_prod`. Las taquillas ven "Hay una nueva versión" pero no pueden descargarla.

## Scope

### In Scope
- Corregir el mount del volumen a `/app/storage/app/releases` (`docker-compose.prod.yml:51`).
- Corregir docs (`manual-mantenimiento.md`, `runbook-ops.md`): ruta y persistencia reales + paso de verificación post-deploy.
- Migración verificable de la release vigente tras el primer deploy (copy-on-mount o re-publicar).
- (Opcional) chequeo post-deploy en CI del job `deploy`.

### Out of Scope
- No automatizar el build de la taquilla (opción D).
- No migrar a R2/S3 (opción C).
- No cambiar el contrato notify-only de `update-check`.
- No tocar secretos ni `.env.production`.
- No cambiar `FILESYSTEM_DISK` del entrypoint.

## Capabilities

### New Capabilities
- `distribucion-instalador-taquilla`: persistencia y servicio del instalador de la taquilla a través de deploys (mount correcto del volumen, contrato `serve`/`update-check`, verificación post-deploy).

### Modified Capabilities
- None

## Approach

Opción A: corregir el mount del named volume a `/app/storage/app/releases` (una línea). El flujo manual actual (`docker cp` + `releases:publish`) sigue intacto y sobrevive a redeploys. Complementos: docs, migración (REQ-5) y verificación post-deploy opcional en CI. Opciones B/C quedan documentadas como evolución futura.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `docker-compose.prod.yml` | Modified | Corregir ruta del mount del volumen `taquilla_releases` |
| `docs/dev/manual-mantenimiento.md` | Modified | Ruta real y estado de persistencia |
| `docs/dev/runbook-ops.md` | Modified | Paso de verificación post-deploy |
| `.github/workflows/ci-cd.yml` | Modified (opcional) | Chequeo post-deploy de `releases/serve` |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Release vigente no se recupera al migrar | Med | Paso de migración explícito; re-publicar `.exe` |
| Named volume sin cobertura de backup restic | Med | Aceptado: el `.exe` se regenera con build |
| Doc desactualizada reaparece | Low | Corregir en el mismo cambio |
| Cambio futuro de WORKDIR rompe la ruta | Low | Comentario en compose + chequeo post-deploy |

## Rollback Plan

Revertir el cambio en `docker-compose.prod.yml` (`git revert` + `docker compose up -d`). El volumen no se destruye; si el fix se retira, vuelve el estado anterior (archivo en la capa del contenedor). Re-publicar manualmente si el `.exe` quedó solo en el volumen.

## Dependencies

- Acceso SSH al VPS para migración y verificación.
- `.exe` vigente disponible para re-publicación si el volumen no hace copy-on-mount.

## Success Criteria

- [ ] REQ-1: tras deploy, `serve` con URL firmada devuelve 200 y el mismo `sha256` que `update-check`.
- [ ] REQ-2: `releases:publish` escribe en `/app/storage/app/releases`.
- [ ] REQ-3: contrato `update-check`/`latest` sin cambios (notify-only).
- [ ] REQ-4: volumen montado en la ruta real y documentado.
- [ ] REQ-5: release vigente recuperada o re-publicada; `update-check` y `serve` consistentes.

## Size Estimate

~10–80 líneas en 3–4 archivos. PR único; `delivery_strategy: auto-chain` no debería activarse.

## Review Workload Forecast

- `Decision needed before apply: No`
- `Chained PRs recommended: No`
- `400-line budget risk: Low`
