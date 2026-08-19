# Design: Despliegue en VPS, seguridad, versionado de API y observabilidad

## Technical Approach

Migrar la API de Render a un VPS Debian 13 (16 GB RAM / 6 vCPU / 126 GB SSD NVMe) con Docker Compose: FrankenPHP en modo workers detrás de Caddy, MySQL 8 y Redis 7 en red interna, colas con Horizon. La API se versiona bajo `/api/v1` (prefijo de rutas + clientes). CI/CD con GitHub Actions → GHCR → deploy SSH con healthcheck y rollback. Observabilidad: Prometheus + Grafana + Alertmanager + Uptime Kuma (Telegram). Backups: restic → Cloudflare R2. Primer acto: purga y rotación de secretos expuestos. Mapea las 6 capabilities (32 REQ, 45 SCENARIO) de `specs/`.

## Architecture Decisions

| Decisión | Opciones | Elección | Razón |
|---|---|---|---|
| Runtime PHP | artisan serve / php-fpm+nginx / FrankenPHP | FrankenPHP workers | artisan serve es mono-hilo y no soporta ~1000 taquillas; FrankenPHP = 1 contenedor, opcache, workers |
| Proxy/TLS | Nginx / Traefik / Caddy | Caddy | HTTPS automático y headers de seguridad con config mínima |
| Registry | Docker Hub / GHCR | GHCR | Repo público: integración nativa con Actions y storage gratis |
| Colas/cache | sync / file / redis | Redis (+ Horizon) | Workers comparten estado; Horizon ya está instalado |
| Sesiones | file / database | database | Persistencia compartida entre workers sobre MySQL existente |
| Versionado | header / subdominio / path | Path `/api/v1` | Visible y simple con `Route::prefix('v1')` |
| Backups | cron dump suelto / borg / restic | restic → R2 | Dedupe + S3-compatible + retención nativa; tier free 10 GB |
| Métricas | Netdata / Prometheus+Grafana | Prometheus+Grafana | 16 GB RAM sobran (~1,5 GB); Alertmanager integra Telegram |

## Data Flow

```
Taquilla (Electron) ─┐
Panel (Vercel) ───────┼─→ Cloudflare (proxy, WAF) ─→ Caddy :443 ─→ API FrankenPHP (workers)
                      │                                        ├─ MySQL 8 (interno)
                      │                                        └─ Redis 7 (interno)
                      │                                        └─ Horizon (worker de colas)
node_exporter + cAdvisor ─→ Prometheus ─→ Grafana / Alertmanager ─→ Telegram
cron diario: mysqldump+gzip ─→ restic ─→ R2      Uptime Kuma → status.gzuz.dev
```

## File Changes

| Archivo | Acción | Descripción |
|---|---|---|
| backend/routes/api.php | Modify | Envolver todas las rutas en `Route::prefix('v1')` |
| backend/.env | Purge | `git filter-repo` + rotación de valores |
| backend/.env.example | Create | Plantilla sin secretos (gestion-secretos REQ-2) |
| backend/.env.testing | Modify | MySQL service para CI (ci-cd REQ-1) |
| backend/Dockerfile | Modify | `dunglas/frankenphp:1-php8.3` + pdo_mysql/bcmath |
| backend/entrypoint.sh | Modify | Migraciones idempotentes + arranque de workers |
| provisioning/provision.sh | Create | Hardening Debian 13: usuario deploy, SSH solo llaves, fail2ban, UFW 22/80/443, unattended-upgrades, swap 2–4 GB, Docker CE, logrotate |
| taquilla/src/** (~10) | Modify | `(PUBLIC_API_URL || 'http://localhost:8000') + '/api/v1'` |
| panel/src/** (~3) | Modify | Mismo patrón `/api/v1` |
| docker-compose.prod.yml | Create | api, mysql, redis, horizon, caddy (sin phpMyAdmin); MySQL `innodb_buffer_pool_size=4G` y `max_connections=300` |
| Caddyfile | Create | lotto/status → proxy, HTTPS, HSTS, headers |
| deploy.sh | Create | pull → up -d → healthcheck → rollback tag previo |
| .github/workflows/ci-cd.yml | Create | Pint + PHPUnit (MySQL service) → build GHCR → deploy |
| monitoring/* | Create | prometheus, alertmanager (Telegram), grafana, uptime-kuma |
| scripts/backup.sh, scripts/restore-test.sh | Create | dump → restic → R2; prueba mensual |
| docs/runbook-ops.md | Create | rollback, restauración, reintento de backup |
| render.yaml | Delete | En go-live |

## Interfaces / Contracts

- API pública: `https://lotto.gzuz.dev/api/v1/*`; healthcheck `GET /api/v1/juegos → 200`.
- `.env.production` (600, usuario deploy): `APP_KEY`, `DB_*`, `REDIS_*`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=database`, `CORS_ALLOWED_ORIGINS=https://panel.gzuz.dev`, `SANCTUM_STATEFUL_DOMAINS=panel.gzuz.dev`, `LOG_LEVEL=warning`.
- Imagen GHCR: `ghcr.io/jmxsdev/lotto-app-api:<sha>` y `:latest`; el `latest` previo se retiene para rollback.
- R2: endpoint S3-compatible + bucket + credenciales solo en el VPS; retención restic 7 diarios / 4 semanales / 6 mensuales.
- GitHub Secrets: `VPS_HOST`, `VPS_SSH_KEY` (deploy key de solo lectura), `GHCR_TOKEN`, credenciales DB de test.

## Testing Strategy

| Capa | Qué | Cómo |
|---|---|---|
| Unit | Suites existentes | SQLite in-memory (sin cambio) |
| Integration | Contratos bajo `/api/v1` | PHPUnit feature con MySQL service en CI |
| Infra | Despliegue | Healthcheck post-deploy; drill de rollback (1 vez) |
| Ops | Backups/alertas | Restauración mensual; prueba real de alerta Telegram |

## Threat Matrix

| Boundary | Aplicabilidad | Respuesta de diseño | RED test planeado |
|---|---|---|---|
| Documentation-like paths | N/A — sin docs ejecutables; scripts `.sh` con ruta fija | — | — |
| Git repository selection | Applicable | Scripts fijan cwd a `/srv/lotto-app`; `git filter-repo` corre sobre clon limpio | Script lanzado desde cwd incorrecto aborta sin tocar git |
| Commit state | Applicable | La purga exige worktree limpio; la deploy key no puede commitear | `git filter-repo` rechaza índice sucio |
| Push state | Applicable | Historial reescrito requiere un único force-push coordinado | La deploy key es rechazada en push (ci-cd SCENARIO-8) |
| PR commands | N/A — deploy por push a main; sin automatización de PRs | — | — |

## Migration / Rollout

Slices en orden (proposal.md): 1) secretos + versionado v1 (clientes juntos, `PUBLIC_API_URL` por ambiente) → 2) infraestructura VPS + DNS Cloudflare (TTL bajo, reversión ≤ 48 h) → 3) CI/CD (rollback a tag previo) → 4) monitoreo + backups (arrancan el día 1). Go-live: cortar DNS, validar taquilla y panel contra `/api/v1`, apagar Render. Migraciones idempotentes, sin seeders destructivos; producción arranca limpia.

## Open Questions

- IP pública del VPS (para los registros DNS) — pendiente del usuario.
- ¿Bot de Telegram existente o crear uno nuevo para las alertas?
- ¿El proyecto Vercel del panel ya existe?
- Fecha del corte DNS coordinada con la actualización de taquillas desplegadas.
