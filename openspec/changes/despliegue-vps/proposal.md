# Propuesta: Despliegue en VPS, seguridad, versionado de API y observabilidad

## Intent

- **Problema de seguridad urgente**: `backend/.env` está trackeado en el repo público `jmxsdev/lotto-app` (commit `8c04b30`) con `APP_KEY` real (`base64:6eAb...`) y credenciales DB (`lotto_user/secret`). `.gitignore` ignora `.env`, pero el archivo quedó commiteado y permanece en el historial. No existe `backend/.env.example`.
- **Debilidad del despliegue actual (Render, free tier)**: contenedor `php:8.3-cli` con `php artisan serve`, `QUEUE_CONNECTION=sync` (sin workers reales pese a tener Horizon), sin HTTPS propio, sin backups, sin monitoreo.
- **Objetivo**: migrar a VPS dedicado (Debian 13, 16 GB RAM, 6 vCPU, 126 GB SSD NVMe) con Docker Compose + FrankenPHP (workers) + Caddy (HTTPS automático), dominios propios vía Cloudflare, CI/CD en GitHub Actions, monitoreo/alertas y backups, resolviendo los secretos expuestos y habilitando la escala a ~1000 taquillas.

## Scope

### In Scope
- Fix de seguridad: sacar `.env` del historial (git filter-repo), crear `.env.example`, rotar `APP_KEY` y credenciales DB.
- Versionado de API: rutas en `routes/api.php` envueltas en `Route::prefix('v1')` → `lotto.gzuz.dev/api/v1/...`; actualizar ~14 archivos de taquilla/panel con el patrón `(import.meta.env.PUBLIC_API_URL || 'http://localhost:8000') + '/api/v1'`.
- Infra VPS: hardening (usuario deploy, SSH solo llaves, fail2ban, UFW 22/80/443, unattended-upgrades, swap 2–4 GB, logrotate), Docker CE oficial.
- Stack prod: compose en VPS — API FrankenPHP (`dunglas/frankenphp:1-php8.3`, modo workers), MySQL 8, Redis 7, worker Horizon, Caddy (HTTPS + headers de seguridad). Sin phpMyAdmin (acceso por túnel SSH).
- DNS/dominios: migrar `gzuz.dev` de Namecheap → Cloudflare; `lotto` y `status` (A, proxied), `panel` (CNAME a `cname.vercel-dns.com`, DNS only); SSL Full (strict).
- CI/CD: GitHub Actions — PHPUnit con MySQL como service (afinar `.env.testing`), Pint, build FrankenPHP → GHCR, deploy SSH, `compose pull && up -d`, healthcheck, rollback con tag anterior; deploy key de solo lectura.
- Monitoreo/alertas: Prometheus + node_exporter + cAdvisor + Grafana + Alertmanager (receptor Telegram) + Uptime Kuma en `status.gzuz.dev` (vigila `https://lotto.gzuz.dev/api/v1/juegos`).
- Backups: `mysqldump` + gzip → restic → Cloudflare R2 (S3-compatible, tier free 10 GB); retención 7 diarios + 4 semanales + 6 mensuales; prueba de restauración mensual; alerta por fallo.
- Revisar pendientes ALTO de `docs/SECURITY.md` (H2, H3, H4, H6) antes del go-live.

### Out of Scope
- Cambios funcionales de dominio (apuestas, cierres, límites, etc.).
- CrowdSec (fase 2, post go-live).
- Migración de datos desde Render (producción arranca limpia; se migra solo si existiera data).
- Alta disponibilidad / multi-región (VPS único por ahora).
- Ajustes de UI/UX de panel o taquilla.

## Decisiones cerradas

| # | Decisión |
|---|----------|
| 1 | Dominio `gzuz.dev`: Namecheap → Cloudflare (gratis); `lotto` A proxied, `status` A proxied, `panel` CNAME DNS only; SSL Full (strict) |
| 2 | API en `lotto.gzuz.dev` con versionado por path `/api/v1`; `CORS_ALLOWED_ORIGINS=https://panel.gzuz.dev`, `SANCTUM_STATEFUL_DOMAINS=panel.gzuz.dev` |
| 3 | Panel en Vercel con dominio `panel.gzuz.dev`; build env `PUBLIC_API_URL=https://lotto.gzuz.dev` |
| 4 | VPS Debian 13 (Trixie), 16 GB RAM, 6 vCPU, 126 GB SSD NVMe, IPv4 única; hardening completo |
| 5 | Docker Compose en VPS; **FrankenPHP reemplaza `php artisan serve`**; `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=database`; MySQL `innodb_buffer_pool_size=4G`, `max_connections=300` |
| 6 | CI/CD GitHub Actions (repo público: minutos y GHCR gratis); tests + Pint + build → GHCR → deploy SSH → healthcheck → rollback |
| 7 | Alertas Telegram vía Uptime Kuma + Alertmanager |
| 8 | Métricas: stack completo (~1–1.5 GB RAM) |
| 9 | Backups gratuitos con Cloudflare R2 + restic |
| 10 | Protecciones: proxy CF, rate limits (Laravel + CF), headers Caddy, secretos solo en `.env.production` (permiso 600) y GitHub Secrets, rotación de credenciales |

## Capabilities

> Contrato con sdd-spec. Investigado `openspec/specs/`: no existe capability de despliegue/infra actual.

### New Capabilities
- `gestion-secretos`: extracción de secretos del repositorio, `.env.example`, rotación y gestión de secretos en producción (`.env.production`, GitHub Secrets).
- `api-versioning`: versionado `/api/v1` de todas las rutas del backend y adaptación de clientes (taquilla/panel).
- `despliegue-produccion`: infraestructura VPS (Docker Compose, FrankenPHP, MySQL, Redis, Horizon, Caddy), hardening y DNS/dominios.
- `ci-cd`: pipeline GitHub Actions (tests, lint, build GHCR, deploy SSH, healthcheck, rollback).
- `monitoreo-alertas`: Prometheus, node_exporter, cAdvisor, Grafana, Alertmanager (Telegram), Uptime Kuma en `status.gzuz.dev`.
- `backup-restic-r2`: backups diarios restic → Cloudflare R2, retención y prueba de restauración.

### Modified Capabilities
- None — el versionado cambia la ruta de acceso, no el comportamiento de las capabilities de dominio existentes (se verifica en spec que ningún escenario dependa de la URL desnuda).

## Approach

1. **Slice 1 — Seguridad + versionado**: `git rm --cached backend/.env`, purgar historial con `git filter-repo`, rotar `APP_KEY`/credenciales DB, crear `.env.example` y `.env.production` (600), envolver `routes/api.php` en `Route::prefix('v1')`, actualizar clientes (patrón `/api/v1`), ajustar CORS/Sanctum, afinar `.env.testing` y PHPUnit para CI con MySQL service.
2. **Slice 2 — Infraestructura VPS**: aprovisionamiento (usuario deploy, SSH, UFW, fail2ban, unattended-upgrades, swap, logrotate, Docker CE), `docker-compose.prod.yml` (API FrankenPHP, MySQL 8, Redis 7, worker Horizon, Caddy), Caddyfile con headers HSTS, migración DNS a Cloudflare, registro de dominios.
3. **Slice 3 — CI/CD**: workflow Actions (PHPUnit con MySQL service, Pint, build GHCR multi-tag, deploy SSH, healthcheck `GET /api/v1/juegos`, rollback a tag anterior), secrets y deploy key.
4. **Slice 4 — Monitoreo + backups**: stack Prometheus/Grafana/Alertmanager/Uptime Kuma, script de backup restic → R2 con retención y alerta, runbook de restauración.
5. **Go-live**: revisar pendientes ALTO de `docs/SECURITY.md`, cortar DNS, validar taquilla/panel contra `https://lotto.gzuz.dev/api/v1`, desactivar Render.

**Forecast de líneas**: supera el presupuesto de revisión de 800 líneas → PRs encadenados por slice (cada slice autónomo, verificable y con rollback propio).

## Affected Areas

| Área | Impacto | Descripción |
|------|---------|-------------|
| `backend/routes/api.php` | Modified | Envolver rutas en `Route::prefix('v1')` |
| `backend/.env`, `backend/.env.example`, `backend/.env.production` | Modified/New | Eliminar `.env` del repo, crear ejemplos y producción |
| `backend/Dockerfile`, `backend/entrypoint.sh` | Modified | FrankenPHP en modo workers; ajustar entrada |
| `taquilla/`, `panel/` (~14 archivos) | Modified | Patrón `(PUBLIC_API_URL || 'http://localhost:8000') + '/api/v1'` |
| `docker-compose.prod.yml`, `Caddyfile` | New | Stack de producción VPS |
| `.github/workflows/` | New | CI/CD (tests, lint, build, deploy, rollback) |
| `monitoring/` (Prometheus, Grafana, Alertmanager, Uptime Kuma), `scripts/backup*` | New | Observabilidad y backups restic → R2 |
| `render.yaml` | Removed | Se desactiva al migrar |
| `docs/SECURITY.md` | Modified | Actualizar estado de pendientes ALTO (H2, H3, H4, H6) |

## Risks

| Riesgo | Probabilidad | Mitigación |
|--------|--------------|------------|
| Secretos ya expuestos en repo público (APP_KEY, DB creds) | Alta (confirmado) | Rotación inmediata de APP_KEY y credenciales DB; purga de historial |
| Cambio de URL `/api` → `/api/v1` rompe clientes desplegados | Media | Slice 1 despliega versionado + clientes juntos; `PUBLIC_API_URL` en prod; compatibilidad temporal verificada en CI |
| FrankenPHP/workers: config nueva (Redis, sesiones DB) | Media | `SESSION_DRIVER=database`, `QUEUE_CONNECTION=redis`; healthcheck y logs; rollback a imagen previa |
| Migración DNS Namecheap → Cloudflare con interrupción | Media | TTL bajo previo, validar registros antes de cortar, plan de reversión DNS |
| Pérdida de backups / fallo de restauración | Media | Prueba de restauración mensual automatizada; alerta Telegram por fallo |
| VPS único = punto único de falla | Media | Uptime Kuma + alertas; runbook de recuperación; HA fuera de alcance |
| Rate limits CF/laravel mal calibrados con ~1000 taquillas | Media | Reglas CF + `throttle` existente; monitoreo de 429 en Grafana |

## Rollback Plan

- **Versionado**: si `/api/v1` falla, revertir el commit del wrapper `prefix('v1')` y de clientes (tag anterior); los clientes usan `PUBLIC_API_URL` por ambiente, no hay cambio de base de datos.
- **Despliegue API**: la imagen previa queda en GHCR (`latest` + tag por commit); redeploy con `docker compose pull <tag-anterior> && up -d`. Migraciones idempotentes en `entrypoint.sh` (sin seeders destructivos).
- **DNS**: mantener registros previos de Namecheap hasta 48 h post go-live; Cloudflare permite apagar proxy (DNS only) o revertir a Render si falla el corte.
- **Datos**: backups diarios restic → R2 desde el primer día; restauración probada mensualmente.
- **Stack monitoreo/backups**: servicios auxiliares independientes del compose principal; si degradan, se detienen sin afectar la API.

## Dependencies

- VPS Debian 13 provisionado con IPv4 pública (proveedor ya contratado).
- Cuenta Cloudflare (gratis) para `gzuz.dev`; mover nameservers desde Namecheap.
- Cuenta GitHub (repo público `jmxsdev/lotto-app`) con Secrets/Environments y deploy key de solo lectura.
- Bucket Cloudflare R2 (tier free) + credenciales S3-compatible para restic.
- Vercel para `panel.gzuz.dev` (CNAME `cname.vercel-dns.com`).
- Bot de Telegram para alertas (Uptime Kuma + Alertmanager).

## Success Criteria

- [ ] `git ls-files` no incluye `backend/.env`; historial purgado; `backend/.env.example` y `.env.production` (600) presentes; APP_KEY y credenciales DB rotadas.
- [ ] `https://lotto.gzuz.dev/api/v1/juegos` responde 200 desde Internet (proxied por CF, SSL Full strict) y `https://panel.gzuz.dev` carga con `PUBLIC_API_URL=https://lotto.gzuz.dev`.
- [ ] Taquilla (Electron) y panel funcionan contra `/api/v1` en staging y prod.
- [ ] Pipeline CI/CD verde: PHPUnit (MySQL service), Pint, build GHCR, deploy, healthcheck, rollback probado una vez.
- [ ] Uptime Kuma en `status.gzuz.dev` vigila la API; alertas Telegram funcionales en prueba real de fallo.
- [ ] Backup diario restic → R2 exitoso; una restauración completa probada con éxito en el mes.
- [ ] Pendientes ALTO de `docs/SECURITY.md` revisados/resueltos o documentados como post-lanzamiento.
- [ ] `render.yaml` desactivado; cero dependencia de Render.
