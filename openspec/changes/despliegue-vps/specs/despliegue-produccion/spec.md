---
capability: despliegue-produccion
change: despliegue-vps
status: done
---

# despliegue-produccion Specification

## Purpose

Provisión y operación del entorno de producción sobre un VPS Debian 13 (16 GB RAM, 6 vCPU, 126 GB SSD NVMe, 1 IPv4): hardening del sistema, stack Docker Compose (FrankenPHP en modo workers, MySQL 8, Redis 7, Horizon, Caddy), ajustes de runtime/tuning y configuración DNS/dominios en Cloudflare con SSL Full (strict).

## Requirements

| # | Requirement | Strength | Estado |
|---|------------|----------|--------|
| REQ-1 | Hardening del VPS | MUST | draft |
| REQ-2 | Docker CE oficial | MUST | draft |
| REQ-3 | Stack Docker Compose de producción | MUST | draft |
| REQ-4 | Caddy con HTTPS y headers de seguridad | MUST | draft |
| REQ-5 | Ajustes de runtime (colas/cache/sesión) | MUST | draft |
| REQ-6 | Tuning de MySQL | MUST | draft |
| REQ-7 | DNS y dominios en Cloudflare | MUST | draft |

### Requirement: REQ-1 — Hardening del VPS

El VPS MUST disponer de: usuario `deploy` sin sudo interactivo, acceso SSH exclusivamente por llaves (root con contraseña deshabilitado), fail2ban activo, UFW permitiendo solo 22/80/443, unattended-upgrades habilitado, swap de 2–4 GB y logrotate configurado.

#### Scenario: SCENARIO-1 — SSH sin llave es rechazado

- GIVEN el VPS con hardening aplicado
- WHEN se intenta conectar por SSH con contraseña o como root
- THEN la conexión es rechazada
- AND solo la llave del usuario `deploy` establece sesión

#### Scenario: SCENARIO-2 — Firewall restrictivo

- GIVEN UFW activo
- WHEN se escanean los puertos abiertos del VPS
- THEN solo responden 22, 80 y 443
- AND los puertos internos del stack (MySQL 3306, Redis 6379, 8080) no son accesibles desde Internet

### Requirement: REQ-2 — Docker CE oficial

El VPS MUST ejecutar Docker CE y Docker Compose instalados desde los repositorios oficiales de Docker, con el usuario `deploy` habilitado para administrar contenedores sin root.

#### Scenario: SCENARIO-3 — Compose operativo

- GIVEN el VPS provisionado
- WHEN el usuario `deploy` ejecuta `docker compose version` y `docker ps`
- THEN ambos comandos funcionan sin `sudo`
- AND el daemon de Docker arranca automáticamente tras un reinicio

### Requirement: REQ-3 — Stack Docker Compose de producción

El stack `docker-compose.prod.yml` MUST incluir: API con imagen FrankenPHP (`dunglas/frankenphp:1-php8.3`) en modo workers, MySQL 8, Redis 7, worker Horizon y Caddy. MUST NOT incluir phpMyAdmin ni otros servicios de administración con puerto público; el acceso a BD es por túnel SSH.

#### Scenario: SCENARIO-4 — Servicios saludables tras reinicio

- GIVEN el stack desplegado con `restart: unless-stopped`
- WHEN el VPS se reinicia
- THEN API, MySQL, Redis, Horizon y Caddy vuelven a estado healthy sin intervención manual
- AND el healthcheck de la API responde 200

#### Scenario: SCENARIO-5 — Sin phpMyAdmin expuesto

- GIVEN el compose de producción
- WHEN se intenta acceder a `http://<ip>/phpmyadmin` o al puerto 8080 desde Internet
- THEN no hay respuesta en ningún puerto de administración de BD

### Requirement: REQ-4 — Caddy con HTTPS y headers de seguridad

Caddy MUST emitir certificados TLS automáticos para `lotto.gzuz.dev` y MUST enviar cabeceras de seguridad (HSTS, X-Content-Type-Options, X-Frame-Options, referrer policy) en todas las respuestas HTTPS. `status.gzuz.dev` sigue el mismo tratamiento.

#### Scenario: SCENARIO-6 — HTTPS y HSTS en respuestas

- GIVEN Caddy emitiendo certificados
- WHEN se ejecuta `curl -I https://lotto.gzuz.dev/api/v1/juegos`
- THEN responde 200 por HTTPS con certificado válido
- AND la respuesta incluye `Strict-Transport-Security` y las cabeceras de seguridad configuradas

### Requirement: REQ-5 — Ajustes de runtime (colas/cache/sesión)

En producción la API MUST ejecutarse con `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` y `SESSION_DRIVER=database`; FrankenPHP en modo workers MUST procesar peticiones sin `php artisan serve`.

#### Scenario: SCENARIO-7 — Jobs procesados por Horizon vía Redis

- GIVEN un job encolado (p. ej., obtención de resultados) en producción
- WHEN el job se despacha y se consulta su estado
- THEN Horizon lo procesa vía Redis
- AND la cache y las sesiones persisten en Redis/BD, no en archivos

### Requirement: REQ-6 — Tuning de MySQL

MySQL 8 de producción MUST operar con `innodb_buffer_pool_size=4G` y `max_connections=300` para soportar ~1000 taquillas concurrentes.

#### Scenario: SCENARIO-8 — Parámetros aplicados

- GIVEN el servidor MySQL del stack
- WHEN se consultan las variables de runtime
- THEN `innodb_buffer_pool_size` es 4G
- AND `max_connections` es 300

### Requirement: REQ-7 — DNS y dominios en Cloudflare

El dominio `gzuz.dev` MUST migrarse de Namecheap a Cloudflare con los registros: `lotto` (A, proxied) y `status` (A, proxied) apuntando a la IPv4 del VPS; `panel` (CNAME a `cname.vercel-dns.com`, DNS only). El modo SSL MUST ser Full (strict).

#### Scenario: SCENARIO-9 — Resolución y proxy de producción

- GIVEN los registros DNS migrados y propagados
- WHEN se resuelven `lotto.gzuz.dev`, `status.gzuz.dev` y `panel.gzuz.dev`
- THEN `lotto` y `status` resuelven a la IPv4 del VPS con proxy de Cloudflare activo
- AND `panel` resuelve a Vercel sin proxy
- AND el certificado de origen es válido para SSL Full (strict)

#### Scenario: SCENARIO-10 — Reversión DNS

- GIVEN una incidencia post-corte
- WHEN se aplica el plan de reversión
- THEN los nameservers de Namecheap pueden reactivarse en un máximo de 48 h
- AND `panel` permanece operativo por ser DNS only
