# Runbook de Operaciones — lotto-app en VPS

## Estado (2026-08-19)

- **VPS**: `166.1.88.100` (host-9d346c.ns.truo.co), Debian 13, 16 GB RAM / 6 vCPU / 237 GB SSD
- **Stack**: Docker Compose en `/home/deploy/lotto-app` — `api` (FrankenPHP), `mysql` 8, `redis` 7, `horizon`, `caddy`
- **Acceso SSH**: SOLO por llave, usuario `deploy`. Root SSH deshabilitado.
- **API interna**: `127.0.0.1:10000` (healthcheck `GET /api/v1/juegos` → 401/200 = vivo)
- **Archivo de secretos**: `/home/deploy/lotto-app/.env.production` (permiso 600)
- **CI/CD**: GitHub Actions verde (tests+Pint → GHCR `ghcr.io/jmxsdev/lotto-app-api:latest` → deploy SSH). Slice 3 cerrado.
- **Seeder**: passwords via `SEEDER_PASSWORD` (env). Rotado en producción el 2026-08-19 (6 usuarios: super/master/banca/grupo/taquilla/demo). La copia local del password está en tu gestor y en `/tmp/opencode/secrets/seed-password.txt` (PC vieja).

## Migración del dominio a Cloudflare (requiere navegador)

1. Crear cuenta en https://dash.cloudflare.com → **Add a site** → `gzuz.dev` (plan Free)
2. Cloudflare escanea e importa los registros DNS actuales de Namecheap (revísalos)
3. Cloudflare muestra 2 nameservers (ej. `ada.ns.cloudflare.com`) → cópialos
4. En **Namecheap** → Dashboard → `gzuz.dev` → **Domain** → Nameservers → **Custom DNS** → pegar los 2 nameservers → guardar
5. Esperar propagación (minutos a ~24 h); Cloudflare mostrará "Active"
6. En Cloudflare → DNS → crear estos registros:

| Tipo | Nombre | Contenido | Proxy |
|---|---|---|---|
| A | `lotto` | `166.1.88.100` | 🟠 Proxied |
| A | `status` | `166.1.88.100` | 🟠 Proxied |
| CNAME | `panel` | `cname.vercel-dns.com` | ⚪ DNS only |

7. SSL/TLS → modo **Full (strict)**
8. Caddy emitirá los certificados automáticamente cuando `lotto.gzuz.dev` resuelva al VPS (verificar con `docker logs lotto_caddy_prod`)
9. Vercel: en el proyecto del panel → Domains → agregar `panel.gzuz.dev`

## Comandos de operación (como `deploy` en el VPS)

```bash
cd /home/deploy/lotto-app
git pull                                  # actualizar código
./deploy.sh                               # build + up + healthcheck
docker compose --env-file .env.production -f docker-compose.prod.yml ps
docker logs -f lotto_api_prod             # logs API
docker logs -f lotto_horizon_prod         # logs colas
# Rollback manual:
docker compose --env-file .env.production -f docker-compose.prod.yml up -d <imagen-anterior>
```

## Checklist — PC nueva

### Accesos y claves

1. Copiar la llave privada `~/.ssh/lotto-vps-deploy` (y `lotto-vps-deploy.pub`) a la PC nueva (`~/.ssh/`, permiso 600)
2. Verificar acceso: `ssh -i ~/.ssh/lotto-vps-deploy deploy@166.1.88.100`
3. (Opcional) Generar una llave nueva `ssh-keygen -t ed25519 -f ~/.ssh/lotto-vps-deploy` y agregar la `.pub` a `~/.ssh/authorized_keys` del VPS (como `deploy`)
4. Rotar la contraseña de root del VPS (proveedor o `sudo passwd root` desde el panel) y guardarla en tu gestor de contraseñas
5. Instalar y autenticar GitHub CLI: `gh auth login` (cuenta `jmxsdev`, scopes repo+workflow) — los secrets VPS_* del CI ya están en GitHub, no se copian
6. Clonar el repo: `git clone git@github.com:jmxsdev/lotto-app.git && cd lotto-app`

### Entorno local (backend)

7. Instalar PHP 8.3 + extensiones (`pdo_mysql`, `bcmath`, `zip`, `redis`, `pcntl`, `posix`), Composer, Node 20+, Docker
8. MySQL local: `sudo apt install mysql-server` o contenedor `docker run -d --name lotto-mysql -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=lotto_db -e MYSQL_USER=lotto_user -e MYSQL_PASSWORD=lotto_dev_a99d67c045151737823c1927 -p 3306:3306 mysql:8.0`
9. `cd backend && cp .env.example .env` → completar `DB_PASSWORD` y `APP_KEY` (`php artisan key:generate`)
10. `cp .env.testing.example .env.testing && composer install && npm install` (si aplica)
11. `php artisan migrate --seed` y verificar: `php artisan test` (214 passed / 2 skipped) y `vendor/bin/pint --test`

### Verificación de producción

12. Smoke: `ssh deploy@166.1.88.100 'curl -s -o /dev/null -w "%{http_code}" -H "Accept: application/json" http://127.0.0.1:10000/api/v1/juegos'` → 401
13. Login real: ver sección de operaciones (demo@lotto.com con el password del seeder, headers `X-Device-Fingerprint`/`X-Device-MAC`)

## Pendientes conocidos

- **Slice 4** (2026-08-20): monitoreo (prometheus/alertmanager→Telegram/grafana/uptime-kuma en `status.gzuz.dev`), backups restic→R2, reglas de alerta, prueba real Telegram. Requiere: chat_id del bot `lotto_status_bot` y credenciales R2 de Cloudflare.
- **Migración DNS a Cloudflare** (requiere navegador; ver arriba) — desbloquea certs de Caddy y el cutover desde Render
- FrankenPHP en modo worker (requiere integración Octane/`frankenphp_handle_request`) — follow-up
- Seeder tolera duplicados con `|| echo (ok)`; revisar idempotencia de `UsersSeeder` en futuros resets de BD
- Rotar password de `super@lotto.com` (y resto de usuarios del seeder) periódicamente: cambiar `SEEDER_PASSWORD` en `.env.production` del VPS y re-ejecutar la rotación (tinker)
