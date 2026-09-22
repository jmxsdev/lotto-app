# Deploy lotto-app — Guía completa de despliegue en VPS (desde cero)

> Guía paso a paso para desplegar la app en un VPS recién instalado (Debian 13 / trixie).
> Incluye: rotación de llaves SSH, firewall, hardening, despliegue del stack, CI/CD,
> suite de observabilidad (Prometheus + Grafana + Alertmanager → Telegram + Uptime Kuma)
> y backups con restic → Cloudflare R2.
>
> Datos del VPS: `166.1.88.100` (host-9d346c), Debian 13, 16 GB RAM / 6 vCPU / 237 GB SSD.
> Dominios: `lotto.gzuz.dev` (API), `status.gzuz.dev` (Uptime Kuma), `panel.gzuz.dev` (Vercel).

---

## Reglas de oro (léelas antes de empezar)

1. **Nunca dejes UFW sin la regla `22/tcp ALLOW`.** Cada vez que toques el firewall,
   verifica con `ufw status numbered` que el 22 siga en ALLOW. Nunca ejecutes `ufw reset`.
2. **No cierres la sesión root** hasta que hayas verificado que `deploy` entra por llave
   y que `sudo`/SSH funcionan (Fase 5 → verificación). Usa dos terminales.
3. **La consola del panel del proveedor (truobox) es out-of-band**: no pasa por UFW.
   Es tu puerta de emergencia si algún día el SSH queda bloqueado.
4. **Los secretos nunca van al repositorio.** Viven solo en el VPS
   (`/home/deploy/lotto-app/.env.production`, `/home/deploy/.backup-env`,
   `/home/deploy/monitoring/.monitoring-env`), todos con permiso 600.
5. **El monitor del proveedor entra por SSH**: el `ignoreip` de fail2ban debe incluir
   su rango de IPs (Fase 6) para que jamás lo baneemos.

---

## 0. Arquitectura del stack

| Servicio | Imagen | Puerto | Expuesto |
|---|---|---|---|
| `caddy` | caddy:2.9-alpine | 80/443 | ✅ público |
| `api` | ghcr.io/jmxsdev/lotto-app-api (FrankenPHP, PHP 8.3) | 10000 | ❌ solo `127.0.0.1` |
| `horizon` | ghcr.io/jmxsdev/lotto-app-api (worker colas) | — | ❌ interno |
| `scheduler` | ghcr.io/jmxsdev/lotto-app-api (`schedule:work`) | — | ❌ interno |
| `mysql` | mysql:8.0 | 3306 | ❌ solo red interna |
| `redis` | redis:7.2-alpine (AOF) | 6379 | ❌ solo red interna |
| Monitoreo (compose aparte) | prometheus, alertmanager, grafana, node-exporter, cAdvisor, mysqld-exporter, blackbox-exporter, uptime-kuma | 9090/9093/3000/3001 | ❌ solo `127.0.0.1` (Grafana etc. por túnel SSH); Uptime Kuma sale por Caddy en `status.gzuz.dev` |

Flujo: Internet → Caddy (TLS automático) → `api:10000`. MySQL/Redis solo en la red
Docker `lotto_net`. El stack de monitoreo vive en un compose separado (`monitoring/`)
para que su degradación no afecte a la API (spec REQ-5), pero se une a `lotto_net`
para poder recolectar métricas.

---

## Fase 1 — Rotación de llaves SSH (en TU PC)

Las llaves viejas (`lotto-vps-deploy` y `ci-vps-deploy`) ya no se consideran seguras.
Genera dos llaves nuevas. **La de CI no lleva passphrase** (GitHub Actions no puede
desbloquearla); la personal sí la lleva (recomendado).

```bash
# 1. Respaldar las viejas (por si acaso)
cd ~/.ssh
mkdir -p ~/.ssh/old-keys-$(date +%F)
cp lotto-vps-deploy lotto-vps-deploy.pub ci-vps-deploy ci-vps-deploy.pub ~/.ssh/old-keys-$(date +%F)/ 2>/dev/null || true

# 2. Generar la llave personal (CON passphrase)
ssh-keygen -t ed25519 -f ~/.ssh/lotto-vps-deploy -C "lotto-vps-deploy@lotto-app"

# 3. Generar la llave del CI (SIN passphrase — pulsa Enter dos veces)
ssh-keygen -t ed25519 -f ~/.ssh/ci-vps-deploy -C "ci-deploy@lotto-app" -N ""

# 4. Permisos correctos
chmod 700 ~/.ssh
chmod 600 ~/.ssh/lotto-vps-deploy ~/.ssh/ci-vps-deploy
chmod 644 ~/.ssh/lotto-vps-deploy.pub ~/.ssh/ci-vps-deploy.pub
```

Guarda las llaves privadas nuevas en tu gestor de contraseñas. Cuando termines el
despliegue, elimina las viejas de `~/.ssh/old-keys-*/` (o guárdalas offline).

---

## Fase 2 — Actualizar secretos en GitHub

El CI/CD (`ci-cd.yml`) despliega por SSH usando los secrets `VPS_*`. Actualiza la
llave rotada:

```bash
cd /home/gzuz/Documentos/lotto-app   # tu clon local del repo

# Autenticarse en gh si no lo estás (repo jmxsdev/lotto-app)
gh auth login

# Rotar la llave del CI en GitHub
gh secret set VPS_SSH_KEY < ~/.ssh/ci-vps-deploy

# Verificar los demás secrets (deben seguir igual)
gh secret set VPS_HOST   --body "166.1.88.100"
gh secret set VPS_USER   --body "deploy"
gh secret set VPS_PATH   --body "/home/deploy/lotto-app"

gh secret list   # confirmar: VPS_SSH_KEY, VPS_HOST, VPS_USER, VPS_PATH
```

---

## Fase 3 — Acceso inicial al VPS (consola del panel o SSH root temporal)

```bash
# Actualizar el sistema
apt update && apt upgrade -y

# Terminal kitty (si usas kitty): instalar su terminfo o el clear fallará
apt install -y kitty-terminfo

# Verificar que SSH escucha en 22
systemctl enable --now ssh
ss -tlnp | grep :22        # debe mostrar LISTEN en 0.0.0.0:22 o [::]:22
```

---

## Fase 4 — Usuario `deploy` con las llaves NUEVAS

Pega las DOS llaves públicas nuevas (personal + CI). Cópialas desde tu PC:

```bash
cat ~/.ssh/lotto-vps-deploy.pub    # ← en TU PC, copia la salida
cat ~/.ssh/ci-vps-deploy.pub       # ← en TU PC, copia la salida
```

Y en el VPS (como root):

```bash
useradd -m -s /bin/bash deploy
mkdir -p /home/deploy/.ssh
cat > /home/deploy/.ssh/authorized_keys <<'EOF'
<pega aquí la salida de lotto-vps-deploy.pub>
<pega aquí la salida de ci-vps-deploy.pub>
EOF
chown -R deploy:deploy /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
chmod 600 /home/deploy/.ssh/authorized_keys
```

**Solo estas dos llaves.** Ninguna llave vieja entra al VPS nuevo.

---

## Fase 5 — Firewall UFW (en este orden exacto)

```bash
apt install -y ufw

ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp      # ← PRIMERO el SSH, SIEMPRE
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable            # responder y

ufw status numbered   # debe mostrar:
                      #   [ 1] 22/tcp ALLOW IN Anywhere
                      #   [ 2] 80/tcp ALLOW IN Anywhere
                      #   [ 3] 443/tcp ALLOW IN Anywhere
```

Si algún día vuelves a tocar UFW, termina siempre con `ufw status numbered` y
confirma que `22/tcp ALLOW` sigue ahí. Ese fue el incidente anterior.

---

## Fase 6 — Hardening SSH + fail2ban + mantenimiento automático

### 6.1 Configuración de SSH (sshd_config)

```bash
cat > /etc/ssh/sshd_config.d/99-hardening.conf <<'EOF'
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
ChallengeResponseAuthentication no
PubkeyAuthentication yes
X11Forwarding no
MaxAuthTries 3
AllowUsers deploy
EOF

systemctl restart ssh
```

### 6.2 Verificar ANTES de seguir (¡crítico!)

Abre una **segunda terminal en tu PC** y verifica que `deploy` entra por llave:

```bash
ssh -i ~/.ssh/lotto-vps-deploy deploy@166.1.88.100 'whoami'   # debe imprimir: deploy
```

Si no entra, revisa authorized_keys (Fase 4) y `journalctl -u ssh`. **No cierres la
sesión root hasta que esto funcione** — con `PermitRootLogin no`, root ya no entra.

### 6.3 fail2ban (sin bloquear jamás al proveedor)

El monitor del proveedor también usa SSH. Su rango de IPs va en `ignoreip`.
**Pídele el rango exacto al soporte de tu proveedor** y reemplázalo abajo
(`<RANGO_DEL_PROVEEDOR>`, p. ej. `203.0.113.0/24`). Mientras no lo tengas, pon
una ventana de baneo corta (10 min) para que un falso positivo no deje nada caído.

```bash
apt install -y fail2ban

cat > /etc/fail2ban/jail.local <<'EOF'
[DEFAULT]
# ¡IMPORTANTE! Rango del proveedor (su monitor entra por SSH) + tu IP fija si tienes
ignoreip = 127.0.0.1/8 ::1 <RANGO_DEL_PROVEEDOR>
bantime  = 10m
findtime = 10m
maxretry = 5

[sshd]
enabled = true
EOF

systemctl enable --now fail2ban
fail2ban-client status sshd   # verificar que el jail corre
```

### 6.4 Actualizaciones de seguridad automáticas

```bash
apt install -y unattended-upgrades apt-listchanges
dpkg-reconfigure -plow unattended-upgrades   # seleccionar "Sí" a auto-descargas
cat > /etc/apt/apt.conf.d/50unattended-upgrades <<'EOF'
Unattended-Upgrade::Origins-Pattern {
    "origin=Debian,codename=${distro_codename},label=Debian-Security";
    "origin=Debian,codename=${distro_codename}-security";
};
Unattended-Upgrade::AutoFixInterruptedDpkg "true";
Unattended-Upgrade::MinimalSteps "true";
Unattended-Upgrade::Automatic-Reboot "false";
EOF
```

### 6.5 Swap (4 GB) y límite de logs de Docker

```bash
# Swap 4G
fallocate -l 4G /swapfile
chmod 600 /swapfile
mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab

# Logs de Docker rotados (evita que un contenedor llene el disco)
mkdir -p /etc/docker
cat > /etc/docker/daemon.json <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" }
}
EOF
```

---

## Fase 7 — Docker, git y herramientas

```bash
apt install -y docker.io docker-compose-plugin git curl htop
systemctl enable --now docker

# deploy opera Docker sin sudo (es el diseño del stack; deploy no tiene sudo)
usermod -aG docker deploy
```

Sal de la sesión y vuelve a entrar como `deploy` para que el grupo haga efecto:

```bash
ssh -i ~/.ssh/lotto-vps-deploy deploy@166.1.88.100
docker info   # debe funcionar sin sudo
```

---

## Fase 8 — Clonar el repo y crear `.env.production`

```bash
# como deploy
cd /home/deploy
git clone https://github.com/jmxsdev/lotto-app.git lotto-app
cd lotto-app

# Generar secretos fuertes (guárdalos en tu gestor de contraseñas)
APP_KEY="base64:$(openssl rand -base64 32)"
MYSQL_ROOT_PASSWORD=$(openssl rand -hex 24)
DB_PASSWORD=$(openssl rand -hex 24)
SEEDER_PASSWORD=$(openssl rand -hex 24)
echo "APP_KEY=$APP_KEY"
echo "MYSQL_ROOT_PASSWORD=$MYSQL_ROOT_PASSWORD"
echo "DB_PASSWORD=$DB_PASSWORD"
echo "SEEDER_PASSWORD=$SEEDER_PASSWORD"
```

Completa el archivo con esos valores:

```bash
cat > .env.production <<EOF
APP_NAME=lotto-app
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=https://lotto.gzuz.dev

LOG_CHANNEL=stderr
LOG_STACK=single
LOG_LEVEL=warning

MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
MYSQL_DATABASE=lotto_db
MYSQL_USER=lotto_user

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=lotto_db
DB_USERNAME=lotto_user
DB_PASSWORD=${DB_PASSWORD}

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_LIFETIME=120

QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

CORS_ALLOWED_ORIGINS=https://panel.gzuz.dev
SANCTUM_STATEFUL_DOMAINS=panel.gzuz.dev

SEEDER_PASSWORD=${SEEDER_PASSWORD}
EOF

chmod 600 .env.production
chmod +x deploy.sh
```

> El seeder crea los usuarios iniciales (super/master/banca/grupo/taquilla/demo) con
> el `SEEDER_PASSWORD`. El `entrypoint.sh` del contenedor API ejecuta migraciones +
> seed automáticamente en el primer arranque (Horizon y el scheduler las omiten:
> solo la API migra).

---

## Fase 9 — Primer despliegue y verificación

```bash
# como deploy, en /home/deploy/lotto-app
./deploy.sh
```

El script hace `docker compose pull` (imagen pública de GHCR) o build local si no
existe, levanta el stack y espera el healthcheck. Verificación manual:

```bash
docker compose --env-file .env.production -f docker-compose.prod.yml ps   # 6 contenedores healthy

# Smoke: la API responde 401 (sin token) en JSON
curl -s -o /dev/null -w '%{http_code}\n' -H 'Accept: application/json' http://127.0.0.1:10000/api/v1/juegos
# → 401

# Migraciones y seed
docker logs lotto_api_prod | grep -E "Migrac|Seed"

# Login real (usuario demo del seeder) para confirmar auth end-to-end:
# POST /api/v1/login con email demo@lotto.com + SEEDER_PASSWORD y
# headers X-Device-Fingerprint / X-Device-MAC
```

### 9.1 Servicio `scheduler` (agenda de resultados)

El servicio `scheduler` ejecuta `php artisan schedule:work` en primer plano
(`RUN_SCHEDULER=true` en `entrypoint.sh`). Versiona el scheduling en el repo y
elimina el drift del cron del host: la agenda que corre en producción ES la del
repo.

- **Agenda**: pasadas por fuente `scrape_{sourceKey}_{H:i}[+15|+30|+45]`, sweep
  `resultados:reconciliar` cada 15 min y cierre `--day-close` a las 23:45.
- ⚠️ **Agenda congelada al arrancar**: `schedule:work` carga la agenda al
  iniciar. Si siembras o editas horarios (`juego_horarios`) con el scheduler
  corriendo, reinícialo para que tome los cambios:
  `docker restart lotto_scheduler_prod`.
- **Mutex Redis** (`withoutOverlapping`): una misma tarea nunca corre dos veces
  a la vez — clave durante la transición con la cron del host todavía activa.
- **Healthcheck**: `php artisan schedule:list` (falla si la BD no responde).

```bash
# Verificación del scheduler
docker ps | grep lotto_scheduler_prod              # Up (healthy)
docker exec lotto_scheduler_prod php artisan schedule:list   # agenda real
docker logs -f lotto_scheduler_prod                # ejecuciones por minuto
```

### 9.2 Rollout: migrar desde la cron del host al servicio `scheduler`

Antes de este cambio, el host corría la agenda con una línea cron
(`* * * * * ... schedule:run` dentro de la API) que NO estaba versionada en el
repo (drift repo↔producción). Pasos para migrar (usuario, en el VPS):

1. **Quitar SOLO la línea `schedule:run`** de la crontab de `deploy`
   (conservar restic/backup y restore-test):

   ```bash
   crontab -e
   # eliminar:  * * * * * ... schedule:run ...
   # conservar: 0 3 * * * /home/deploy/lotto-app/scripts/backup.sh ...        (Fase 14)
   # conservar: 0 4 1 * * /home/deploy/lotto-app/scripts/restore-test.sh ...  (Fase 14)
   ```

2. **Desplegar el nuevo compose** (crea el contenedor `scheduler`):

   ```bash
   cd /home/deploy/lotto-app
   git pull
   docker compose --env-file .env.production -f docker-compose.prod.yml up -d
   ```

3. **Verificar**:

   ```bash
   crontab -l                          # sin la línea schedule:run
   docker ps | grep lotto_scheduler_prod      # Up (healthy)
   docker exec lotto_scheduler_prod php artisan schedule:list
   ```

4. **La transición es segura**: durante el solapamiento (cron del host +
   scheduler) el mutex Redis evita el doble dispatch, y la guarda de
   `ScrapeSourceJob` omite las pasadas sin sorteos faltantes. Rollback:
   `docker rm -f lotto_scheduler_prod` (el `up -d` no borra huérfanos) y
   re-agregar la línea cron.

---

## Fase 10 — DNS Cloudflare y certificados TLS

El runbook (`docs/runbook-ops.md`) tiene el detalle; aquí el resumen ejecutable
(requiere navegador):

1. **dash.cloudflare.com → Add a site → `gzuz.dev`** (plan Free). Cloudflare importa
   los registros actuales de Namecheap.
2. Cloudflare te da **2 nameservers** → cópialos.
3. En **Namecheap** → dominio `gzuz.dev` → Nameservers → **Custom DNS** → pega los
   dos nameservers → guarda.
4. Espera la propagación (minutos a ~24 h) hasta que Cloudflare diga **Active**.
5. En Cloudflare → **DNS** → crea:

   | Tipo | Nombre | Contenido | Proxy |
   |---|---|---|---|
   | A | `lotto` | `166.1.88.100` | 🟠 Proxied |
   | A | `status` | `166.1.88.100` | 🟠 Proxied |
   | CNAME | `panel` | `cname.vercel-dns.com` | ⚪ DNS only |

6. **SSL/TLS → modo `Full (strict)`**.
7. Caddy emitirá los certificados solo cuando `lotto.gzuz.dev` resuelva al VPS.
   Verifica en el VPS:

```bash
docker logs lotto_caddy_prod | grep -iE "certificate|serving"
curl -s -o /dev/null -w '%{http_code}\n' https://lotto.gzuz.dev/api/v1/juegos -H 'Accept: application/json'
# → 401 (con TLS válido)
```

8. Vercel: en el proyecto del panel → Domains → agregar `panel.gzuz.dev`.

---

## Fase 11 — Verificar el CI/CD end-to-end

Con los secrets de la Fase 2, cualquier push a `main` corre: tests (MySQL service) →
Pint → build GHCR → deploy SSH con healthcheck y rollback.

```bash
# en tu PC
cd /home/gzuz/Documentos/lotto-app
git push origin main
gh run watch          # o revisa en github.com/jmxsdev/lotto-app/actions
```

Verifica que el job `deploy` termine con `deploy OK`.

---

## Fase 12 — Suite de observabilidad (Prometheus + Grafana + Alertmanager + Uptime Kuma)

Stack en un compose separado, unido a la red `lotto_net` del compose principal.

### 12.1 Archivo de secretos del monitoreo

```bash
# como deploy
mkdir -p /home/deploy/monitoring
cat > /home/deploy/monitoring/.monitoring-env <<EOF
GF_SECURITY_ADMIN_USER=admin
GF_SECURITY_ADMIN_PASSWORD=$(openssl rand -hex 16)
TELEGRAM_BOT_TOKEN=<TOKEN_NUEVO_DEL_BOT>     # Fase 13 (después de rotar el viejo)
TELEGRAM_CHAT_ID=<CHAT_ID>                   # Fase 13
MYSQL_MONITOR_PASSWORD=$(openssl rand -hex 16)
EOF
chmod 600 /home/deploy/monitoring/.monitoring-env
```

> `MYSQL_MONITOR_PASSWORD` es una cuenta de solo lectura para el exporter.
> Créala así (desde el host):

```bash
docker exec lotto_mysql_prod mysql -uroot -p"$(grep MYSQL_ROOT_PASSWORD /home/deploy/lotto-app/.env.production | cut -d= -f2)" -e "CREATE USER IF NOT EXISTS 'lotto_monitor'@'%' IDENTIFIED BY 'TU_MYSQL_MONITOR_PASSWORD'; GRANT SELECT, PROCESS, REPLICATION CLIENT ON *.* TO 'lotto_monitor'@'%'; FLUSH PRIVILEGES;"
```

> Además, mysqld-exporter v0.17.1 exige un `.my.cnf` válido (fatal si falta):

```bash
cat > /home/deploy/monitoring/.my.cnf <<'EOF'
[client]
user=lotto_monitor
host=mysql
port=3306
EOF
# ⚠️ 644, no 600: el contenedor corre con otro UID y no podría leerlo (crash loop).
# Es seguro: este archivo NO contiene la password (va por DATA_SOURCE_NAME).
chmod 644 /home/deploy/monitoring/.my.cnf
```

### 12.2 `docker-compose.monitoring.yml`

```bash
cat > /home/deploy/monitoring/docker-compose.monitoring.yml <<'EOF'
services:
  prometheus:
    image: prom/prometheus:v3.4.0
    container_name: lotto_prometheus
    restart: unless-stopped
    volumes:
      - ./prometheus.yml:/etc/prometheus/prometheus.yml:ro
      - ./alerts.yml:/etc/prometheus/alerts.yml:ro
      - prometheus_data:/prometheus
    ports: ["127.0.0.1:9090:9090"]
    # ⚠️ command reemplaza el CMD de la imagen: incluir SIEMPRE --config.file
    # (el default de la imagen es /etc/prometheus/prometheus.yml)
    command:
      - --config.file=/etc/prometheus/prometheus.yml
      - --storage.tsdb.retention.time=30d
    networks: [lotto_net]

  alertmanager:
    image: prom/alertmanager:v0.28.0
    container_name: lotto_alertmanager
    restart: unless-stopped
    volumes:
      - ./alertmanager.yml:/etc/alertmanager/alertmanager.yml:ro
      - alertmanager_data:/alertmanager
    ports: ["127.0.0.1:9093:9093"]
    networks: [lotto_net]

  node-exporter:
    image: prom/node-exporter:v1.9.0
    container_name: lotto_node_exporter
    restart: unless-stopped
    volumes:
      - /:/host:ro,rslave
      - /home/deploy/monitoring/textfile:/var/lib/node_exporter/textfile:ro
    command:
      - --path.rootfs=/host
      - --path.procfs=/host/proc
      - --path.sysfs=/host/sys
      - --collector.textfile.directory=/var/lib/node_exporter/textfile
    networks: [lotto_net]

  cadvisor:
    image: gcr.io/cadvisor/cadvisor:v0.51.0
    container_name: lotto_cadvisor
    restart: unless-stopped
    privileged: true
    volumes:
      - /:/rootfs:ro
      - /var/run:/var/run:ro
      - /sys:/sys:ro
      - /var/lib/docker/:/var/lib/docker:ro
      - /dev/disk/:/dev/disk:ro
    networks: [lotto_net]

  mysqld-exporter:
    image: prom/mysqld-exporter:v0.17.1
    container_name: lotto_mysqld_exporter
    restart: unless-stopped
    # ⚠️ v0.17.1 SIEMPRE valida .my.cnf (default ".my.cnf") y es FATAL si falla,
    # aunque DATA_SOURCE_NAME esté seteada. `--config.my-cnf=` NO lo desactiva.
    # La solución: montar un .my.cnf mínimo válido (ver paso de creación abajo).
    volumes:
      - ./.my.cnf:/.my.cnf:ro
    environment:
      DATA_SOURCE_NAME: "lotto_monitor:${MYSQL_MONITOR_PASSWORD}@(mysql:3306)/"
    networks: [lotto_net]

  blackbox-exporter:
    image: prom/blackbox-exporter:v0.26.0
    container_name: lotto_blackbox
    restart: unless-stopped
    volumes:
      - ./blackbox.yml:/config/blackbox.yml:ro
    command: ["--config.file=/config/blackbox.yml"]
    networks: [lotto_net]

  grafana:
    image: grafana/grafana:11.6.0
    container_name: lotto_grafana
    restart: unless-stopped
    environment:
      GF_SECURITY_ADMIN_USER: ${GF_SECURITY_ADMIN_USER}
      GF_SECURITY_ADMIN_PASSWORD: ${GF_SECURITY_ADMIN_PASSWORD}
      GF_USERS_ALLOW_SIGN_UP: "false"
    volumes:
      - grafana_data:/var/lib/grafana
    ports: ["127.0.0.1:3000:3000"]
    networks: [lotto_net]

  uptime-kuma:
    image: louislam/uptime-kuma:1
    container_name: lotto_uptime_kuma
    restart: unless-stopped
    volumes:
      - uptime_kuma_data:/app/data
    ports: ["127.0.0.1:3001:3001"]
    networks: [lotto_net]

networks:
  lotto_net:
    external: true

volumes:
  prometheus_data:
  alertmanager_data:
  grafana_data:
  uptime_kuma_data:
EOF
```

### 12.3 `prometheus.yml`

```bash
cat > /home/deploy/monitoring/prometheus.yml <<'EOF'
global:
  scrape_interval: 15s
  evaluation_interval: 15s

rule_files:
  - /etc/prometheus/alerts.yml

alerting:
  alertmanagers:
    - static_configs:
        - targets: ["alertmanager:9093"]

scrape_configs:
  - job_name: prometheus
    static_configs:
      - targets: ["localhost:9090"]

  - job_name: node
    static_configs:
      - targets: ["node-exporter:9100"]

  - job_name: cadvisor
    static_configs:
      - targets: ["cadvisor:8080"]

  - job_name: mysql
    static_configs:
      - targets: ["mysqld-exporter:9104"]

  - job_name: blackbox-http
    metrics_path: /probe
    params:
      module: [http_2xx]
    static_configs:
      # Target directo al origin: el VPS conectándose a sí mismo vía Cloudflare
      # dispara la protección anti-loop del edge (500). blackbox corre en el VPS.
      - targets: ["https://166.1.88.100/api/v1/juegos"]
    relabel_configs:
      - source_labels: [__address__]
        target_label: __param_target
      - source_labels: [__param_target]
        target_label: instance
      - target_label: __address__
        replacement: blackbox-exporter:9115
EOF
```

### 12.4 `alerts.yml` (reglas de alerta — spec monitoreo-alertas REQ-4)

Umbral de disco calibrado sobre el **disco real (237 GB)**: alerta cuando queda
menos del 20 % libre.

Reglas de resultados (textfile `resultados.prom` que escribe el scheduler con
`resultados:metricas`): `MissingDraw` dispara cuando un sorteo esperado lleva
más de 1 hora sin persistir (distingue gap upstream de fallo del scraper);
`DailyDrawsIncomplete` cuando el conteo diario queda por debajo del esperado
según `juego_horarios`; `DrawMetricsStale` cuando el propio textfile no se
actualiza (el comando no corre).

```bash
cat > /home/deploy/monitoring/alerts.yml <<'EOF'
groups:
  - name: lotto-app
    rules:
      - alert: ApiDown
        expr: probe_success{job="blackbox-http"} == 0
        for: 2m
        labels: { severity: critical }
        annotations:
          summary: "API caída: lotto.gzuz.dev no responde"

      - alert: Api5xx
        expr: probe_http_status_code{job="blackbox-http"} >= 500
        for: 5m
        labels: { severity: critical }
        annotations:
          summary: "API devolviendo 5xx de forma sostenida"

      - alert: SslExpiring
        expr: probe_ssl_earliest_cert_expiry - time() < 14 * 24 * 3600
        for: 1h
        labels: { severity: warning }
        annotations:
          summary: "Certificado SSL por expirar en menos de 14 días"

      - alert: DiskUsageHigh
        expr: (node_filesystem_avail_bytes{mountpoint="/"} / node_filesystem_size_bytes{mountpoint="/"}) < 0.2
        for: 10m
        labels: { severity: warning }
        annotations:
          summary: "Disco por encima del 80 % de uso (umbral sobre 237 GB)"

      - alert: MysqlDown
        expr: mysql_up == 0
        for: 2m
        labels: { severity: critical }
        annotations:
          summary: "MySQL sin responder al exporter"

      - alert: BackupNotRun
        expr: (time() - backup_last_success_timestamp) > 26 * 3600
        for: 1h
        labels: { severity: warning }
        annotations:
          summary: "Backup diario sin éxito en más de 26 horas"

      - alert: MissingDraw
        expr: lotto_draws_pending_seconds > 3600
        for: 5m
        labels: { severity: warning }
        annotations:
          summary: "Sorteo faltante de {{ $labels.juego }} por más de 1 hora: gap upstream (la fuente no publicó) o fallo del scraper sostenido; verificar la fuente"

      - alert: DailyDrawsIncomplete
        expr: lotto_daily_incomplete > 0
        for: 30m
        labels: { severity: warning }
        annotations:
          summary: "Conteo diario de {{ $labels.juego }} por debajo del esperado según juego_horarios"

      - alert: DrawMetricsStale
        expr: (time() - lotto_metrics_timestamp) > 3600
        for: 15m
        labels: { severity: warning }
        annotations:
          summary: "Métricas de resultados sin actualizar hace más de 1 hora (resultados:metricas no corre)"
EOF
```

### 12.4.1 Aplicar las reglas de alertas de resultados (rollout — pendiente)

> ⚠️ El textfile `resultados.prom` lo escribe el scheduler (`resultados:metricas`
> cada 15 min) en el volumen `/var/lib/lotto-metrics` (§12.7); node-exporter lo
> scrapea con `--collector.textfile.directory=/var/lib/node_exporter/textfile`.

1. Copia el bloque `alerts.yml` actualizado al VPS (incluye las reglas
   `MissingDraw`, `DailyDrawsIncomplete` y `DrawMetricsStale`):

```bash
nano /home/deploy/monitoring/alerts.yml   # pegar el bloque completo del §12.4
docker restart lotto_prometheus           # recarga las reglas
```

2. Verifica que las 3 reglas nuevas estén presentes:
   `curl -s http://127.0.0.1:9090/api/v1/rules | jq '.data.groups[].rules[].name'` (túnel SSH).

Rollback: quitar las 3 reglas de `alerts.yml` + `docker restart lotto_prometheus`;
el textfile `resultados.prom` se puede borrar (el comando lo regenera cada 15 min).

### 12.5 `alertmanager.yml` (receptor Telegram)

```bash
cat > /home/deploy/monitoring/alertmanager.yml <<'EOF'
route:
  receiver: telegram
  group_by: ["alertname"]
  group_wait: 30s
  group_interval: 5m
  repeat_interval: 4h

receivers:
  - name: telegram
    telegram_configs:
      - bot_token: "<TOKEN_NUEVO_DEL_BOT>"
        chat_id: <CHAT_ID>
        parse_mode: HTML
        message: '{{ range .Alerts }}<b>{{ .Labels.alertname }}</b> — {{ .Annotations.summary }}{{ end }}'
EOF
chmod 644 /home/deploy/monitoring/alertmanager.yml
# ⚠️ NO usar 600: el contenedor corre con otro UID y no podría leerlo (crash loop).
# El token sigue protegido: el archivo vive en el VPS sin otros usuarios con shell.
```

### 12.6 `blackbox.yml`

```bash
cat > /home/deploy/monitoring/blackbox.yml <<'EOF'
modules:
  http_2xx:
    prober: http
    timeout: 10s
    http:
      valid_status_codes: [200, 401]
      method: GET
      headers:
        Accept: application/json
      # blackbox sondea el origin por IP (anti-loop de Cloudflare);
      # el cert se valida contra el server_name real:
      tls_config:
        server_name: lotto.gzuz.dev
EOF
```

### 12.7 Levantar el stack de monitoreo

> ⚠️ **Requisito previo:** la red del stack principal debe llamarse exactamente
> `lotto_net` (el compose de monitoreo la declara como `external`). El compose
> principal la crea con prefijo (`lotto-app_lotto_net`) a menos que le des nombre
> fijo. En `/home/deploy/lotto-app/docker-compose.prod.yml`:

```yaml
networks:
  lotto_net:
    name: lotto_net
    driver: bridge
```

```bash
# después de editar, recrear la red y contenedores (los volúmenes persisten):
cd /home/deploy/lotto-app
docker compose --env-file .env.production -f docker-compose.prod.yml up -d
docker network ls | grep lotto_net   # debe aparecer exactamente "lotto_net"

cd /home/deploy/monitoring
mkdir -p textfile && chmod 777 textfile
# ⚠️ Este directorio se monta como /var/lib/lotto-metrics (rw) en los servicios
# `api` y `scheduler` del compose principal (docker-compose.prod.yml): ahí se
# escriben los textfiles de Prometheus (backup.prom del backup y resultados.prom
# de la agenda de resultados, Fase 9.1). No borrarlo ni cambiar el permiso.
docker compose --env-file .monitoring-env -f docker-compose.monitoring.yml up -d
docker compose -f docker-compose.monitoring.yml ps        # todo healthy/running
ss -tlnp | grep -E "3000|3001|9090|9093"                  # bind 127.0.0.1
```

### 12.8 Uptime Kuma en `status.gzuz.dev`

1. **Edita el Caddyfile** del stack principal (`/home/deploy/lotto-app/Caddyfile`)
   y descomenta el bloque `status.gzuz.dev` (apunta a `uptime-kuma:3001`):

```bash
nano /home/deploy/lotto-app/Caddyfile
# quitar el "# " de las líneas del bloque status.gzuz.dev
cd /home/deploy/lotto-app && docker compose --env-file .env.production -f docker-compose.prod.yml up -d caddy
```

2. Accede por túnel SSH (no está expuesto): `ssh -L 3001:127.0.0.1:3001 -i ~/.ssh/lotto-vps-deploy deploy@166.1.88.100`
   → en tu navegador: `http://localhost:3001`.
3. Crea usuario admin (password fuerte).
4. **Add New Monitor** → tipo HTTP(s) → URL `https://lotto.gzuz.dev/api/v1/juegos`
   → **Advanced → Accepted Status Codes**: `200-299,401` (la API responde 401 sin
   token: es la señal correcta de "servidor vivo"; no uses credenciales reales en
   el monitor).
   → **Request Headers** (⚠️ **REQUERIDO**, no opcional): `Accept: application/json`.
   Sin esa cabecera la API devuelve **500** (`Route [login] not defined`): el
   middleware de Sanctum intenta redirigir a `route('login')` (inexistente en API
   pura) cuando el request no "espera JSON". El fix de raíz
   (`$middleware->redirectGuestsTo(fn () => throw new AuthenticationException(...))`
   en `backend/bootstrap/app.php`) elimina la redirección; hasta que esté en la
   imagen de producción, TODOS los monitores/clientes deben mandar `Accept`.
   → **extra_hosts** (en `docker-compose.monitoring.yml`, servicio `uptime-kuma`):

```yaml
    extra_hosts:
      - "lotto.gzuz.dev:166.1.88.100"
```

   > ⚠️ **Anti-loop de Cloudflare:** el VPS conectándose a sí mismo a través del
   > proxy de CF recibe **500** (protección anti-loop del edge). Por eso Kuma y
   > blackbox-exporter resuelven `lotto.gzuz.dev` directo al origin (166.1.88.100);
   > Caddy responde el cert TLS válido igualmente. El edge de Cloudflare no queda
   > monitoreado desde el VPS (se vigila desde el propio panel de CF).

   → Heartbeat 60s → Retries 2 (caída notificada tras ~2 min, spec REQ-3).
5. **Settings → Notifications → Telegram**: bot token nuevo + chat id (Fase 13).
   Asócialo al monitor.

### 12.9 Grafana

Acceso por túnel: `ssh -L 3000:127.0.0.1:3000 -i ~/.ssh/lotto-vps-deploy deploy@166.1.88.100`
→ `http://localhost:3000` (admin / password de `.monitoring-env`).

- **Connections → Data sources → Prometheus** → URL `http://prometheus:9090`.
- Crea (o importa, dashboard ID 1860 para node exporter y 14282 para cAdvisor)
  un dashboard con CPU/RAM/disco del host y de los contenedores (spec REQ-1).
- Verifica en **Status → Targets** que prometheus, node, cadvisor, mysql y
  blackbox estén todos UP.

---

## Fase 13 — Telegram (rotar token, obtener chat_id, prueba end-to-end)

> ⚠️ El token actual del bot se compartió en un chat, así que **rótalo antes** de
> ponerlo en producción.

1. **Rotar el token** en Telegram: abre **@BotFather** → `/mybots` → `lotto_status_bot`
   → **API Token** → **Revoke current token** → copia el token nuevo.
   (Si el bot aún no existe: `/newbot` → nombre `lotto_status_bot`.)
2. **Obtener el chat_id**: crea un grupo (o usa el chat contigo), agrega el bot al
   grupo, envía un mensaje cualquiera y ejecuta:

```bash
curl -s "https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/getUpdates" | jq '.result[].message.chat.id'
# o sin jq: curl -s ".../getUpdates" | grep -o '"chat":{"id":[0-9-]*'
```

   El `chat.id` de un grupo es negativo (p. ej. `-1001234567890`).
3. **Prueba directa del bot**:

```bash
curl -s "https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/sendMessage" \
  -d chat_id=<CHAT_ID> -d text="✅ Prueba de alertas lotto-app"
```

   Debe llegar el mensaje al grupo.
4. Pon el token nuevo y el chat_id en: `.monitoring-env`, `alertmanager.yml`
   (recarga: `docker restart lotto_alertmanager`) y en Uptime Kuma.
5. **Prueba end-to-end de alertas**:

```bash
# dispara la alerta ApiDown deteniendo el target un momento (en el VPS):
docker stop lotto_caddy_prod && sleep 150 && docker start lotto_caddy_prod
# en ~2 min + 30s de group_wait debe llegar el mensaje de Alertmanager al grupo
```

   (Alternativa sin cortar tráfico: `amtool` o una regla de prueba con `expr: vector(1)`.)
6. Confirma que el token viejo ya no funciona (`getUpdates` con el viejo → error 401).

---

## Fase 14 — Backup diario con restic → Cloudflare R2

### 14.1 Preparar Cloudflare R2 (navegador)

1. **dash.cloudflare.com → R2 → Create bucket** → nombre `lotto-backups` → Create.
2. **R2 → Manage R2 API Tokens → Create API Token**:
   - Permissions: **Object Read & Write** (solo eso, mínimo privilegio)
   - Specifiqua el bucket: `lotto-backups`
   - Copia y guarda **Access Key ID** y **Secret Access Key** (¡el secret se muestra una sola vez!)
3. **Account ID**: está en la esquina derecha de la página de R2 (o en la URL del
   dashboard: `dash.cloudflare.com/<ACCOUNT_ID>`).
4. Endpoint S3 (se deriva del Account ID):
   `https://<ACCOUNT_ID>.r2.cloudflarestorage.com` — región `auto`.

### 14.2 Archivo de credenciales del backup

```bash
# como deploy
cat > /home/deploy/.backup-env <<EOF
AWS_ACCESS_KEY_ID=<R2_ACCESS_KEY_ID>
AWS_SECRET_ACCESS_KEY=<R2_SECRET_ACCESS_KEY>
RESTIC_REPOSITORY=s3:https://<ACCOUNT_ID>.r2.cloudflarestorage.com/lotto-backups
RESTIC_PASSWORD=$(openssl rand -hex 24)
TELEGRAM_BOT_TOKEN=<TELEGRAM_BOT_TOKEN>
TELEGRAM_CHAT_ID=<CHAT_ID>
EOF
chmod 600 /home/deploy/.backup-env
```

Guarda `RESTIC_PASSWORD` y las credenciales R2 en tu gestor de contraseñas:
**si pierdes `RESTIC_PASSWORD`, los backups son irrecuperables.**

### 14.3 `scripts/backup.sh`

```bash
mkdir -p /home/deploy/lotto-app/scripts
cat > /home/deploy/lotto-app/scripts/backup.sh <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

source /home/deploy/.backup-env
export AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY RESTIC_REPOSITORY RESTIC_PASSWORD
# restic binario estático en ~/bin (el cron no trae ~/bin en el PATH)
export PATH="$HOME/bin:$PATH"

TODAY=$(date +%F)
DUMP_DIR=/home/deploy/backups
DUMP_FILE="$DUMP_DIR/lotto_db.sql.gz"
TEXTFILE=/home/deploy/monitoring/textfile/backup.prom
MYSQL_ROOT_PASSWORD=$(grep '^MYSQL_ROOT_PASSWORD=' /home/deploy/lotto-app/.env.production | cut -d= -f2-)

notify() {  # $1 = texto
  curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
    -d chat_id="${TELEGRAM_CHAT_ID}" -d text="$1" >/dev/null || true
}

mkdir -p "$DUMP_DIR"

# 1. Dump consistente + compresión
if ! docker exec lotto_mysql_prod mysqldump \
     -uroot -p"${MYSQL_ROOT_PASSWORD}" \
     --single-transaction --routines --events lotto_db \
   | gzip > "${DUMP_FILE}.tmp"; then
  notify "❌ Backup ${TODAY}: falló el mysqldump"
  exit 1
fi
mv "${DUMP_FILE}.tmp" "$DUMP_FILE"

# 2. Snapshot en R2 vía restic
if ! restic backup "$DUMP_DIR"; then
  notify "❌ Backup ${TODAY}: falló la subida a R2"
  exit 1
fi

# 3. Retención 7 diarios + 4 semanales + 6 mensuales
restic forget --keep-daily 7 --keep-weekly 4 --keep-monthly 6 --prune

# 4. Verificación del repo
restic check --read-data-subset=1G

# 5. Métrica para Prometheus (alerta BackupNotRun si falta 26 h)
cat > "$TEXTFILE" <<METRIC
backup_last_success_timestamp $(date +%s)
METRIC

notify "✅ Backup ${TODAY}: snapshot restic verificado"
EOF
chmod +x /home/deploy/lotto-app/scripts/backup.sh
```

Instala restic (binario estático, **sin root** — deploy no tiene sudo por diseño)
y programa el cron (03:00 diario):

```bash
# como deploy — restic corre desde ~/bin, sin tocar el sistema
mkdir -p ~/bin && cd ~/bin
curl -fL -o restic.bz2 https://github.com/restic/restic/releases/download/v0.19.1/restic_0.19.1_linux_amd64.bz2
echo "f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c  restic.bz2" | sha256sum -c -
# descomprimir SIN root (el VPS mínimo no trae bzip2; python3 siempre está):
python3 -c "import bz2; open('restic','wb').write(bz2.decompress(open('restic.bz2','rb').read()))"
chmod +x restic && rm restic.bz2
./restic version

# cron (03:00 diario, como deploy)
# ⚠️ Si `crontab` no existe (VPS mínimo sin el paquete cron), una sola vez desde
# la CONSOLA DEL PANEL (root): `apt install -y cron && systemctl enable --now cron`
crontab -e
# agregar:
0 3 * * * /home/deploy/lotto-app/scripts/backup.sh >> /home/deploy/backups/backup.log 2>&1
```

> **Nota (scheduler)**: la agenda de Laravel ya NO se programa por cron del
> host — la maneja el servicio `scheduler` (`schedule:work`, Fase 9.1). Esta
> crontab contiene únicamente los backups (restic) y la prueba de restauración.

### 14.4 Prueba de restauración mensual

```bash
cat > /home/deploy/lotto-app/scripts/restore-test.sh <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

source /home/deploy/.backup-env
export AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY RESTIC_REPOSITORY RESTIC_PASSWORD
# restic binario estático en ~/bin (el cron no trae ~/bin en el PATH)
export PATH="$HOME/bin:$PATH"

TARGET=/tmp/restore-test-$(date +%F)
mkdir -p "$TARGET"

notify() {
  curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
    -d chat_id="${TELEGRAM_CHAT_ID}" -d text="$1" >/dev/null || true
}

# 1. Restaurar el último snapshot
restic restore latest --target "$TARGET"
DUMP="$TARGET/home/deploy/backups/lotto_db.sql.gz"

# 2. Importar en un MySQL temporal
docker run --rm -d --name lotto_mysql_restore_test \
  -e MYSQL_ROOT_PASSWORD=restore-test -e MYSQL_DATABASE=lotto_restore \
  mysql:8.0 >/dev/null
trap 'docker rm -f lotto_mysql_restore_test >/dev/null 2>&1 || true' EXIT
sleep 30

gunzip -c "$DUMP" | docker exec -i lotto_mysql_restore_test \
  mysql -uroot -prestore-test lotto_restore

# 3. Validar conteos de tablas clave (ajusta los nombres si cambian)
COUNTS=$(docker exec lotto_mysql_restore_test mysql -uroot -prestore-test -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='lotto_restore'; \
   SELECT COUNT(*) FROM lotto_restore.users; SELECT COUNT(*) FROM lotto_restore.juegos;")
echo "tablas totales + conteos clave: $COUNTS"

notify "✅ Prueba de restauración mensual OK: ${COUNTS}"
EOF
chmod +x /home/deploy/lotto-app/scripts/restore-test.sh
```

Cron mensual (día 1 a las 04:00):

```bash
crontab -e
# agregar (requiere el paquete cron instalado — ver nota en 14.3):
0 4 1 * * /home/deploy/lotto-app/scripts/restore-test.sh >> /home/deploy/backups/restore-test.log 2>&1
```

### 14.5 Reintento manual (spec backup REQ-4)

```bash
/home/deploy/lotto-app/scripts/backup.sh
# y para inspeccionar el repo R2:
source /home/deploy/.backup-env && export AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY RESTIC_REPOSITORY RESTIC_PASSWORD
restic snapshots
```

---

## Fase 15 — Checklist final de seguridad

- [ ] `ufw status numbered`: solo 22/80/443 ALLOW, default deny incoming.
- [ ] SSH: solo llaves (`PasswordAuthentication no`), root deshabilitado, `AllowUsers deploy`.
- [ ] fail2ban activo con el rango del proveedor en `ignoreip`.
- [ ] `unattended-upgrades` activo (security).
- [ ] Swap 4G + logs Docker con rotación (`max-size 10m, max-file 3`).
- [ ] Llaves SSH rotadas en GitHub (`VPS_SSH_KEY` nuevo) y en el VPS; viejas eliminadas.
- [ ] `.env.production`, `.backup-env`, `.monitoring-env`, `alertmanager.yml`: permiso 600.
- [ ] MySQL/Redis sin puertos publicados; API solo en `127.0.0.1:10000`.
- [ ] Token de Telegram rotado (el viejo revocado) y probado end-to-end.
- [ ] R2: API token con scope mínimo (Object R/W solo al bucket); `RESTIC_PASSWORD` guardado.
- [ ] Monitoreo aislado en compose separado; Grafana/Prometheus solo por túnel SSH.
- [ ] Backup diario + retención 7/4/6 + restore-test mensual + alertas por fallo.
- [ ] TLS Full (strict) en Cloudflare; Caddy con headers de seguridad.
- [ ] CI/CD verde: push a `main` despliega con healthcheck y rollback.

---

## Fase 16 — Emergencias: si algún día el SSH queda bloqueado

1. **No entres en pánico — no toques nada aún.**
2. Entra por la **consola del panel del proveedor** (out-of-band, no pasa por UFW).
3. Diagnostica:

```bash
ufw status numbered        # ¿está 22/tcp ALLOW? Si no:
ufw allow 22/tcp
systemctl status ssh       # ¿corre sshd?
ss -tlnp | grep :22
fail2ban-client status sshd
fail2ban-client get sshd ignoreip   # ¿sigue el rango del proveedor?
```

4. Si una IP está baneada injustamente: `fail2ban-client set sshd unbanip <IP>`.
5. Verifica desde tu PC: `ssh -v deploy@166.1.88.100`.
6. **Nunca** `ufw reset` sin volver a agregar `ufw allow 22/tcp` antes de `ufw enable`.
