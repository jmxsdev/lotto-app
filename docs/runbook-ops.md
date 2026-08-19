# Runbook de Operaciones — lotto-app en VPS

## Estado (2026-08-19)

- **VPS**: `166.1.88.100` (host-9d346c.ns.truo.co), Debian 13, 16 GB RAM / 6 vCPU / 237 GB SSD
- **Stack**: Docker Compose en `/home/deploy/lotto-app` — `api` (FrankenPHP), `mysql` 8, `redis` 7, `horizon`, `caddy`
- **Acceso SSH**: SOLO por llave, usuario `deploy`. Root SSH deshabilitado.
- **API interna**: `127.0.0.1:10000` (healthcheck `GET /api/v1/juegos` → 401/200 = vivo)
- **Archivo de secretos**: `/home/deploy/lotto-app/.env.production` (permiso 600)

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

1. Copiar la llave privada `~/.ssh/lotto-vps-deploy` (y `lotto-vps-deploy.pub`) a la PC nueva (`~/.ssh/`, permiso 600)
2. Verificar acceso: `ssh -i ~/.ssh/lotto-vps-deploy deploy@166.1.88.100`
3. (Opcional) Agregar tu llave personal: `ssh-copy-id deploy@166.1.88.100`
4. Rotar la contraseña de root del VPS (proveedor o `sudo passwd root` desde el panel) y guardarla en tu gestor de contraseñas
5. Clonar el repo en la PC nueva: `git clone git@github.com:jmxsdev/lotto-app.git`

## Pendientes conocidos

- FrankenPHP en modo worker (requiere integración Octane/`frankenphp_handle_request`) — follow-up
- Slice 3 (CI/CD) y Slice 4 (monitoreo + backups restic→R2) pendientes
- Seeder tolera duplicados con `|| echo (ok)`; revisar idempotencia de `UsersSeeder` en futuros resets de BD
