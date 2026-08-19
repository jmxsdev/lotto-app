# Tasks: Despliegue en VPS, seguridad, versionado de API y observabilidad

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1000–1300 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 → PR 2 → PR 3 → PR 4 |
| Delivery strategy | ask-on-risk → split |
| Chain strategy | stacked-to-main |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Secretos fuera del repo + API `/api/v1` | PR 1 | `cd backend && composer test` | `php artisan route:list` → solo `/api/v1/*` | Revert commit del wrapper y clientes |
| 2 | Infraestructura VPS + compose | PR 2 | `docker compose -f docker-compose.prod.yml config -q` | Healthcheck local con curl | `compose down` + imagen previa |
| 3 | CI/CD (Actions + GHCR + deploy) | PR 3 | Push a rama → workflow verde | Deploy SSH real + healthcheck | `compose pull <tag previo>` |
| 4 | Monitoreo + backups | PR 4 | `restic check` + alerta Telegram de prueba | Stack de monitoreo en VPS | Detener monitoreo sin tocar la API |

## Phase 1: Seguridad y versionado

- [ ] 1.1 `git rm --cached backend/.env`; crear `backend/.env.example` con placeholders (gestion-secretos REQ-2)
- [ ] 1.2 RED (commit state): verificar que `git filter-repo` rechaza un worktree sucio
- [ ] 1.3 Purga de historial con `git filter-repo` sobre clon limpio + único force-push (REQ-1)
- [ ] 1.4 Rotar APP_KEY y credenciales DB; `.env.production` con 600 en el VPS (REQ-3, REQ-4)
- [ ] 1.5 Envolver `backend/routes/api.php` en `Route::prefix('v1')` (api-versioning REQ-1)
- [ ] 1.6 CORS/Sanctum por env para `panel.gzuz.dev` (REQ-3)
- [ ] 1.7 Actualizar ~10 archivos `taquilla/src/**` y ~3 `panel/src/**` al patrón `(PUBLIC_API_URL||'http://localhost:8000')+'/api/v1'` (REQ-2)
- [ ] 1.8 Verificar con `php artisan route:list` que no queda ruta desnuda `/api/`

## Phase 2: Infraestructura VPS

- [ ] 2.1 `provisioning/provision.sh`: usuario deploy, SSH solo llaves, fail2ban, UFW 22/80/443, unattended-upgrades, swap, Docker CE, logrotate (despliegue-produccion REQ-1/2)
- [ ] 2.2 `backend/Dockerfile` → FrankenPHP 8.3 (pdo_mysql, bcmath); `entrypoint.sh` con migraciones idempotentes + workers
- [ ] 2.3 `docker-compose.prod.yml`: api, mysql (4G/300), redis, horizon, caddy; sin phpMyAdmin (REQ-3/5/6)
- [ ] 2.4 `Caddyfile`: rutas lotto/status, HTTPS, HSTS, headers de seguridad (REQ-4)
- [ ] 2.5 RED (git selection): `deploy.sh` lanzado desde cwd incorrecto aborta sin tocar git
- [ ] 2.6 `deploy.sh`: pull → up -d → healthcheck → rollback
- [ ] 2.7 DNS Cloudflare: A lotto/status proxied, CNAME panel DNS-only, SSL Full strict (REQ-7)

## Phase 3: CI/CD

- [ ] 3.1 `backend/.env.testing` → MySQL service de Actions (ci-cd REQ-1)
- [ ] 3.2 `.github/workflows/ci-cd.yml`: Pint → PHPUnit con MySQL → build GHCR (sha + latest) → deploy SSH → healthcheck → rollback (REQ-1..5)
- [ ] 3.3 RED (push state): la deploy key es rechazada en push (SCENARIO-8)
- [ ] 3.4 GitHub Secrets: VPS_HOST, VPS_SSH_KEY, GHCR_TOKEN (REQ-6; gestion-secretos REQ-5)

## Phase 4: Monitoreo y backups

- [ ] 4.1 `monitoring/`: prometheus, alertmanager (Telegram), grafana, uptime-kuma en `status.gzuz.dev`
- [ ] 4.2 Reglas: API caída, SSL ≤ 14 días, disco > 80 % (126 GB), MySQL down, backup fallido (monitoreo-alertas REQ-4)
- [ ] 4.3 `scripts/backup.sh`: mysqldump + gzip → restic → R2; retención 7/4/6 (backup REQ-1/2)
- [ ] 4.4 `scripts/restore-test.sh`: restauración mensual + validación de conteos (REQ-3)
- [ ] 4.5 Prueba real de alerta Telegram (REQ-2; backup REQ-4)

## Phase 5: Go-live y cleanup

- [ ] 5.1 `docs/runbook-ops.md`: rollback, restauración, reintento de backup
- [ ] 5.2 Revisar pendientes ALTO de `docs/SECURITY.md`
- [ ] 5.3 Corte DNS; validar taquilla y panel contra `/api/v1`; apagar Render y borrar `render.yaml`
- [ ] 5.4 Ejecutar un drill de rollback (ci-cd SCENARIO-7)
