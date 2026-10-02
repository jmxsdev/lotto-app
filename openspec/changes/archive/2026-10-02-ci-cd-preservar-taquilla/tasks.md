# Tasks: ci-cd-preservar-taquilla

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~10–80 (3 archivos) |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | auto-chain (no se activa: cambio chico) |
| Chain strategy | pending (PR único) |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Mount + docs | PR 1 | `docker compose -f docker-compose.prod.yml config` | `docker exec lotto_api_prod ls -la /app/storage/app/releases` | `git revert` del compose; el volumen no se borra |
| 2 | Chequeo CI post-deploy | PR 1 | lint YAML del workflow | `docker inspect lotto_api_prod --format '{{json .Mounts}}'` | revertir el bloque del job `deploy` |

## Phase 1: Corrección de config + docs (WU1)

- [x] 1.1 `docker-compose.prod.yml:51` — mount `taquilla_releases:/app/storage/app/releases` + comentario `# ruta atada a WORKDIR=/app (backend/Dockerfile:5)`. Criterio: `docker compose config` muestra el destino correcto.
- [x] 1.2 `docs/dev/manual-mantenimiento.md` §2.1 (tabla servicio `api`, ~línea 49) — ruta del volumen → `/app/storage/app/releases`. Criterio: ya no menciona `/var/www/html`.
- [x] 1.3 `docs/dev/manual-mantenimiento.md` §4.4 (líneas 193-194) y registro (línea 354) — corregir ruta y aclarar persistencia real del volumen. Criterio: doc indica `/app/storage/app/releases` y persistencia entre deploys.
- [x] 1.4 `docs/dev/runbook-ops.md` §"Taquilla Windows release" paso 5 — verificar archivo en `/app/storage/app/releases` + `sha256sum`; nota de migración. Criterio: runbook cubre REQ-1/REQ-4.

## Phase 2: Migración manual en VPS (operativo, sin código)

> Ejecutada por el operador: release 1.0.3 publicada y `update-check` respondiendo
> `{"version":"1.0.3","sha256":"…"}` (REQ-5 verificado en el VPS).

- [x] 2.1 Pre-deploy — `docker exec lotto_api_prod ls -la /app/storage/app/releases/` y `docker cp lotto_api_prod:/app/storage/app/releases/Taquilla-Setup-<v>.exe /tmp/`; si no existe, `scp` desde `taquilla/release/`. Criterio: `.exe` vigente en `/tmp` del VPS. — hecho por operador (release 1.0.3 publicada)
- [x] 2.2 Post-deploy — `docker cp /tmp/…exe lotto_api_prod:/tmp/` + `docker exec lotto_api_prod php artisan releases:publish /tmp/…exe --release-version=<v>`. Criterio: REQ-2 (escribe en el volumen). — hecho por operador (release 1.0.3 publicada)
- [x] 2.3 Verificar consistencia — `sha256sum` del archivo en el contenedor == `update-check.sha256` (equivale a `serve` 200). Criterio: REQ-5 (nunca versión anunciada sin archivo servible). — hecho por operador (release 1.0.3 publicada)

## Phase 3: Verificación post-deploy en CI (WU2)

> DIFERIDA hasta que la Fase 2 estuviera verificada en el VPS (design.md línea 49):
> habilitarla antes habría hecho fallar el primer deploy con el fix (la DB anunciaba
> una versión cuyo archivo vivía en la capa del contenedor viejo). Con REQ-5 verificado
> (release 1.0.3 servida desde el volumen), el guard quedó habilitado.

- [x] 3.1 `.github/workflows/ci-cd.yml` job `deploy` — tras el healthcheck (`ok=0`) y antes de `echo "deploy OK"`: `docker inspect` verifica mount destino == `/app/storage/app/releases`; si `update-check` responde 200, comparar `sha256(update-check)` vs `sha256sum` del `.exe` en el volumen. Fallo → `exit 1` sin rollback de imagen. RED test de la threat matrix (Nuevo shell/SSH CI, read-only). Criterio: REQ-6 (regresión de persistencia falla el deploy). — WU2, commit `8fc682c`

## Phase 4: Verificación final

- [x] 4.1 `composer test` verde (sin cambios PHP; `ReleaseController` intacto, REQ-3). Criterio: CI verde. — N/A por constraint del orquestador: el cambio toca cero código de aplicación; attestado en verify-report (10/10 completas, 0 incompletas).
- [x] 4.2 Verificación manual `update-check`/`serve` + escenarios del spec: Persistencia tras deploy, Nueva publicación, Migración sin release previa, Contrato notify-only, Volumen montado, Documentación actualizada, Chequeo post-deploy. Criterio: todos los escenarios cubiertos. — attestado en verify-report (5/7 COMPLIANT, 2 PARTIAL aceptados por el orquestador).
