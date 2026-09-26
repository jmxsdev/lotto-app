# Manual de Mantenimiento — lotto-app

Guía de mantenimiento y operación del monorepo `lotto-app` (API Laravel + despliegue Docker en VPS + aplicación de escritorio Taquilla en Electron/Astro). Está dirigida al propietario del proyecto (rol desarrollador/operador) y describe, con rutas verificables, cómo se despliega, cómo se aplican las migraciones, cómo se empaqueta y distribuye Taquilla, cómo se activan dispositivos y cómo se opera el sistema a diario.

> **Estado**: verificado en el repositorio al **2026-09-07**. Los puntos que cambiarán con el cambio SDD en curso (`fix-taquilla-produccion`) están marcados con "> Pendiente".

---

## Tabla de contenido

| # | Sección | Qué responde |
|---|---------|--------------|
| 1 | [Propósito y mapa del documento](#1-propósito-y-mapa-del-documento) | Cómo está organizado este manual |
| 2 | [Arquitectura de despliegue (producción)](#2-arquitectura-de-despliegue-producción) | Qué corre en el VPS y cómo llega el código |
| 3 | [Ciclo de vida de migraciones en producción](#3-ciclo-de-vida-de-migraciones-en-producción) | Cuándo corren las migraciones y qué hacer si fallan |
| 4 | [Empaquetado y release de la app Taquilla](#4-empaquetado-y-release-de-la-app-taquilla) | Cómo se construye el instalador y llega a las taquillas |
| 5 | [Activación de dispositivos](#5-activación-de-dispositivos) | Cómo se vincula una PC a una taquilla |
| 6 | [CORS y orígenes](#6-cors-y-orígenes) | Qué orígenes puede llamar a la API |
| 7 | [Higiene de secrets](#7-higiene-de-secrets) | Dónde viven los secretos y qué hacer si se filtran |
| 8 | [Operación diaria](#8-operación-diaria) | Checklists de uso cotidiano |

---

## 1. Propósito y mapa del documento

| Sección | Contenido |
|---------|-----------|
| **2. Arquitectura de despliegue** | Servicios de `docker-compose.prod.yml`, imágenes GHCR, pipeline CI/CD de `.github/workflows/ci-cd.yml`, Caddy y healthcheck con rollback automático. |
| **3. Migraciones en prod** | La respuesta central del manual: las migraciones corren **automáticamente en cada deploy** vía `backend/entrypoint.sh`; cuándo NO hace falta tocar nada, cuándo sí se ejecutan comandos manuales y qué pasa si fallan. |
| **4. Empaquetado Taquilla** | Reglas de build del instalador Electron, flujo de distribución (volumen `taquilla_releases` → `releases:publish` → URLs firmadas) y versionado. Incluye el estado conocido (con problemas) del `main` actual. |
| **5. Activación de dispositivos** | `device_fingerprint`, `POST /dispositivo/verificar`, `POST /activar` y cómo activar una taquilla nueva en producción. |
| **6. CORS** | Política de orígenes por variable de entorno y el valor por defecto del entrypoint. |
| **7. Secrets** | Reglas de dónde (no) viven los secretos, rotación y protocolo ante fugas. |
| **8. Operación diaria** | Crear entidades (jerarquía), revisar errores, probar scrapers y diagnosticar un HTTP 500. |

Documentos complementarios del repo: `docs/deploy.md` (despliegue del VPS desde cero), `docs/runbook-ops.md` (runbook de operaciones y acceso SSH), `docs/estructura.md` (estructura del repo).

---

## 2. Arquitectura de despliegue (producción)

> Estado: verificado en el repo al 2026-09-07. Fuentes: `docker-compose.prod.yml`, `.github/workflows/ci-cd.yml`, `backend/entrypoint.sh`, `Caddyfile`, `deploy.sh`, `docs/deploy.md`.

### 2.1 Stack del VPS (docker-compose.prod.yml)

| Servicio | Imagen | Puerto | Notas |
|---|---|---|---|
| `caddy` | `caddy:2.9-alpine` | 80/443 públicos | TLS automático; `lotto.gzuz.dev` → `reverse_proxy api:10000` con headers de seguridad (`Caddyfile:9-19`). |
| `api` | `ghcr.io/jmxsdev/lotto-app-api:${IMAGE_TAG:-latest}` | `127.0.0.1:10000` | FrankenPHP (PHP 8.3). `env_file: .env.production`. Volumen `taquilla_releases:/var/www/html/storage/app/releases`. Healthcheck interno contra `/api/v1/juegos` esperando `200|401` (`docker-compose.prod.yml:52-57`). |
| `horizon` | Misma imagen que `api`, con `RUN_HORIZON=true` | interno | Worker de colas. **No ejecuta migraciones** (ver §3). Healthcheck: `php artisan horizon:status`. |
| `mysql` | `mysql:8.0` | `127.0.0.1:3306` | Solo loopback (túneles SSH/GUI). Datos en volumen `mysql_prod_data`. |
| `redis` | `redis:7.2-alpine` | interno | AOF `everysec`; datos en `redis_prod_data`. |

Volúmenes declarados (`docker-compose.prod.yml:110-115`): `mysql_prod_data`, `redis_prod_data`, `caddy_data`, `caddy_config`, `taquilla_releases`.

### 2.2 Pipeline CI/CD (.github/workflows/ci-cd.yml)

Cada push a `main` (o `workflow_dispatch`) ejecuta tres jobs en orden:

1. **tests** — MySQL 8 como service, PHP 8.3, `composer install`, `vendor/bin/pint --test`, `php artisan test` (`ci-cd.yml:13-67`).
2. **build** — construye la imagen desde `backend/` y la publica en GHCR con dos tags: `ghcr.io/jmxsdev/lotto-app-api:<sha7>` y `:latest` (`ci-cd.yml:69-89`).
3. **deploy** — solo en `refs/heads/main` y solo si existen los secrets `VPS_SSH_KEY`, `VPS_HOST`, `VPS_USER`, `VPS_PATH` (`ci-cd.yml:91-137`):
   - Guarda la imagen actual del contenedor `lotto_api_prod` (para rollback).
   - Por SSH: `git pull` + `docker compose --env-file .env.production -f docker-compose.prod.yml pull` + `up -d`.
   - Healthcheck: hasta 36 intentos cada 5 s contra `http://127.0.0.1:10000/api/v1/juegos` con `Accept: application/json`; se considera vivo con `200` o `401`.
   - **Rollback automático** (`ci-cd.yml:119-137`): si el healthcheck no pasa en ~3 minutos, el pipeline etiqueta la imagen previa como `ghcr.io/jmxsdev/lotto-app-api:rollback` (`docker tag $PREV ...:rollback`) y re-leva el stack con `IMAGE_TAG=rollback ... up -d`. El job termina en fallo con el mensaje "healthcheck falló; rollback aplicado".

Alternativa manual: `./deploy.sh` en el VPS hace el mismo `pull` + `up -d` con el mismo bucle de healthcheck; si falla, imprime la pista de rollback manual (`docker compose ... up -d <imagen-anterior>`) (`deploy.sh:24-28`). `render.yaml` es un blueprint alternativo (Render) con healthcheck `/api/juegos`; no es la ruta de producción actual.

---

## 3. Ciclo de vida de migraciones en producción

> Estado: verificado en el repo al 2026-09-07. Fuente principal: `backend/entrypoint.sh:42-56`.

### 3.1 La regla: las migraciones corren solas en cada deploy

**Cuándo corren automáticamente**: en **cada deploy a `main`**. La cadena completa es:

```
merge a main
  → CI: tests (MySQL) + Pint            (ci-cd.yml, job "tests")
  → build y push a GHCR                 (ci-cd.yml, job "build")
  → SSH al VPS: git pull + compose pull + up -d   (ci-cd.yml, job "deploy")
  → el contenedor API arranca y su entrypoint ejecuta:
       php artisan migrate --force
  → SOLO DESPUÉS arranca FrankenPHP y empieza a servir
```

El `entrypoint.sh` del contenedor API (líneas 42-56) hace esto antes de servir tráfico:

1. Si la tabla `migrations` **existe** (BD ya inicializada): ejecuta `php artisan migrate --force` para aplicar migraciones pendientes.
2. Si **no existe** (primer arranque, BD vacía): ejecuta `php artisan migrate --force` y luego `php artisan db:seed --force --class=DatabaseSeeder` (los usuarios del seeder toman su password de la variable `SEEDER_PASSWORD`).
3. Limpia caches (`php artisan optimize:clear`) y finalmente arranca FrankenPHP en `0.0.0.0:10000`.

El contenedor **Horizon** se salta este bloque completo (condición `RUN_HORIZON != true`, `entrypoint.sh:43-56`): nunca ejecuta migraciones ni seeders, solo arranca `php artisan horizon` (`entrypoint.sh:61-64`).

### 3.2 Qué significa en la práctica

- **Para trabajo normal de features NO necesitas ejecutar migraciones a mano, nunca.** Mergear a `main` es el único paso: el deploy aplica las migraciones pendientes antes de servir tráfico.
- No hay un job de migración separado en el pipeline: la migración está acoplada al arranque del contenedor API. Esto garantiza que la BD nunca queda detrás del código que está sirviendo.
- Ventana de riesgo conocida: `horizon` no espera a que `api` termine de migrar. Si un deploy agrega tablas que un job en cola usa, Horizon puede fallar en esa ventana y auto-recuperarse (`restart: unless-stopped` + healthcheck `horizon:status`).

### 3.3 Cuándo ejecutar comandos manuales

| Caso | Comando | Nota |
|---|---|---|
| Verificar el estado de la BD | `php artisan migrate:status` (dentro del contenedor API) | Único chequeo pasivo recomendado tras cada deploy con migraciones. |
| Backfills de datos | `php artisan agencias:backfill --force` | Es un **comando de consola**, no una migración. En producción exige `--force` (`backend/app/Console/Commands/AgenciasBackfill.php:22-26`). Primer paso recomendado: `--dry-run` para ver qué haría sin escribir. |
| Tasa BCV manual | `php artisan scrape:exchange-rate` | Scraping síncrono del BCV (`backend/app/Console/Commands/ScrapeExchangeRate.php`). |
| Cambio de BD sin redeploy (caso excepcional) | `docker compose --env-file .env.production -f docker-compose.prod.yml exec api php artisan migrate --force` | Es el mismo comando que corre el entrypoint (`entrypoint.sh:46`). Úsalo solo si realmente no puedes esperar al deploy; el código que la migración acompaña llegará con el siguiente `up -d`. |

Publicar releases de Taquilla (releases:publish) es otro comando manual, pero pertenece al flujo de §4.

### 3.4 Qué pasa si una migración falla en el arranque

Secuencia de fallo (`ci-cd.yml:119-137`):

1. `entrypoint.sh` tiene `set -e`: si `migrate --force` falla, el contenedor API muere antes de servir.
2. El healthcheck del pipeline (`/api/v1/juegos` → 200/401) no obtiene respuesta en la ventana de ~3 min.
3. El pipeline etiqueta la imagen anterior como `ghcr.io/jmxsdev/lotto-app-api:rollback` y re-leva el stack con `IMAGE_TAG=rollback`. El deploy queda marcado como fallido.

**Advertencia importante**: el rollback devuelve la **imagen (código)**, pero **no revierte la base de datos**. Si una migración falló a mitad de camino, las migraciones ya aplicadas quedan en la tabla `migrations`. Por eso el checklist de abajo exige migraciones aditivas/reversibles y un plan documentado para operaciones destructivas — el rollback automático protege el código, no los datos.

### 3.5 Checklist — antes de mergear un cambio con migraciones

- [ ] La migración es **aditiva** (nuevas tablas/columnas/índices) o **reversible** (`down()` correcto y probado).
- [ ] Sin operaciones destructivas (drop/rename/cambio de tipo) sin un plan documentado en la descripción del PR.
- [ ] Tests en verde con MySQL: `composer test` local o, mejor, dejar que el job `tests` del CI los corra (`ci-cd.yml` usa MySQL 8 como service).
- [ ] El código que usa el nuevo esquema va en el mismo merge (para que `migrate` + código deployen juntos).
- [ ] Si el cambio requiere backfill de datos, va como comando de consola (`agencias:backfill` es el patrón a seguir: idempotente, con `--dry-run` y `--force`), no dentro de la migración.

### 3.6 Checklist — después del deploy con migraciones

- [ ] `php artisan migrate:status` (en el contenedor API) muestra las nuevas migraciones como `Ran`.
- [ ] Los logs del API no muestran errores de migración: `docker logs lotto_api_prod --tail 100` (buscar `migrat`).
- [ ] La feature nueva funciona contra producción (verificar el endpoint o el flujo real).
- [ ] `docker ps` muestra `lotto_api_prod` y `lotto_horizon_prod` healthy.

---

## 4. Empaquetado y release de la app Taquilla

### 4.1 Estado actual verificado (resuelto)

> Estado: actualizado al 2026-09-11, tras el merge de `fix-taquilla-produccion` (PR #6). Los problemas conocidos de la etapa anterior quedaron resueltos; rigen las reglas de §4.2.

- **Contrato de URL de la API unificado**: base única en `taquilla/src/config/api.ts` (`api:///api/v1`) y proxy `api://` con normalización de path — el upstream recibe siempre `/api/v1/*` (nunca `/api/api`). Nota de runtime: Chromium promueve el primer segmento del path a host en esquemas estándar, por lo que el proxy restaura `/api` si el `pathname` llega sin él.
- **Empaquetado reproducible**: purga encadenada (`clean:artifacts`) antes de cada build, `directories` única (`release`), `dist` por un solo mecanismo (`extraResources`), target AppImage codificado y `pnpm-lock.yaml` versionado.
- **Config pnpm (11.x)**: la clave vigente es `allowBuilds` (mapa booleano paquete→`true`) en `pnpm-workspace.yaml`; `onlyBuiltDependencies` fue eliminada en pnpm 11 (usarla produce `ERR_PNPM_IGNORED_BUILDS`).
- **Creación de taquillas robustecida**: 422 (nunca 500) ante padres soft-deleted; el `activation_code` lo genera SIEMPRE la API (`Str::random(16)`) y el panel lo muestra solo lectura, con columna en la lista.

> Corte de referencia: merge de `fix-taquilla-produccion` (PR #6, 2026-09-11) — deploy verde (tests → GHCR → VPS con healthcheck) y panel actualizado.

### 4.2 Las reglas vigentes

1. **Purga antes de cada empaquetado**: borrar `dist/`, `build/` y `release/` antes de cada paso de packaging. Nunca empacar sobre carpetas de una corrida anterior.
2. **Un solo árbol de código por build**: construir desde un único checkout limpio de un commit concreto. No mezclar `dist/` preexistente con código nuevo.
3. **Verificación de consistencia del artefacto**: en el HTML construido, todas las bases de API del renderer deben ser idénticas entre sí. Chequeo mínimo antes de publicar: `grep -r "api:" dist/` (y revisar que no queden bases contradictorias).
4. **Nunca confiar en una carpeta de artefactos vieja**: si no construiste tú el artefacto en esta sesión, re-construye.
5. **Un solo mecanismo de envío de `dist`**: eliminar la doble inclusión (`files` + `extraResources`) para que el loader de `main.cjs` (`getDistPath`, `main.cjs:9-15`) encuentre exactamente una copia.
6. **Lockfile commiteado**: `pnpm-lock.yaml` sale del `.gitignore` de `taquilla/` y se versiona.

### 4.3 Procedimiento de empaquetado (plantilla / checklist)

Comandos reales del repo (scripts de `taquilla/package.json`):

```bash
cd taquilla
pnpm install                       # 1. dependencias respetando pnpm-lock.yaml
pnpm electron:build:win            # 2. clean:artifacts + astro build + electron-builder --win
                                   #    (Linux: pnpm electron:build → AppImage en release/)
grep -r "api:" dist/               # 3. consistencia: bases de API idénticas en todo el HTML
```

- [ ] Paso 5 sin contradicciones entre páginas (todas las bases del renderer iguales).
- [ ] El instalador sale en la carpeta configurada como `Taquilla-Setup-<versión>.exe` (`artifactName` de `package.json`).
- [ ] Probar el instalador en una máquina limpia antes de publicar.

> Nota: ambos targets están codificados (`build.linux` → AppImage; `build.win` → NSIS) y los scripts `electron:build`/`electron:build:win` purgan artefactos y compilan el renderer antes de empaquetar.

### 4.4 Flujo de distribución al usuario (producción)

> Estado: verificado en el repo al 2026-09-07. Fuentes: `backend/app/Console/Commands/ReleasePublishCommand.php`, `backend/app/Http/Controllers/Api/ReleaseController.php`, `backend/routes/api.php:34-41,230-233`, `docker-compose.prod.yml:51`, `backend/config/filesystems.php:50-55`.

```
.exe construido en tu PC
  → se copia al VPS (p. ej. por scp)
  → php artisan releases:publish <ruta-del-.exe>     (ReleasePublishCommand)
  → el comando calcula SHA-256, mueve el archivo al disco "releases"
    y REEMPLAZA la fila única de releases (D3: sin historial)
  → el disco "releases" (storage/app/releases) vive en el volumen
    persistente taquilla_releases → sobrevive a redeploys
  → la app instalada consulta GET /api/v1/update-check (público, throttle 30/min)
  → el panel consulta GET /api/v1/releases/latest y pide
     GET /api/v1/releases/download → devuelve URL firmada (5 min)
  → GET /api/v1/releases/serve (URL firmada + throttle) sirve el .exe por streaming
```

Detalles verificados:

- **`releases:publish`** (sig: `releases:publish {path} {--release-version=}`): exige que el archivo exista; la versión se infiere del nombre `Taquilla-Setup-<version>.exe`, si no, usar `--release-version=`. Borra fila(s) y archivo(s) anteriores dentro de una transacción y crea la nueva (`ReleasePublishCommand.php:38-54`).
- **Throttle** `releases-download`: 10/min por usuario autenticado (o por IP en el serve firmado), definido en `backend/app/Providers/AppServiceProvider.php:29-30`. La firma de la URL **es** la credencial del serve: no requiere auth (`routes/api.php:34-38`).
- **`update-check` es notify-only** (REQ-B1): devuelve `version` + `sha256`; nunca auto-instala ni expone URL de descarga (`ReleaseController.php:86-98`).
- Roles del panel con acceso a releases: `super_master|master|banca|grupo|agencia` (la taquilla recibe 403, `routes/api.php:230-233`).

### 4.5 Versionado

- La versión vive en `taquilla/package.json` → `"version"` (actual `0.1.0`).
- `artifactName: "Taquilla-Setup-${version}.${ext}"` genera el nombre del instalador.
- `releases:publish` parsea la versión de ese filename; si lo renombras, pásala con `--release-version=`. Esa versión es la que reporta `/update-check` y `/releases/latest`.
- Bump de versión = editar `package.json` → rebuild → publish; la fila única de releases garantiza que solo existe "la última".

---

## 5. Activación de dispositivos

> Estado: verificado en el repo al 2026-09-07. Fuentes: `taquilla/src/pages/index.astro`, `taquilla/src/pages/activacion.astro`, `backend/app/Http/Controllers/Api/DispositivoController.php`, `backend/app/Http/Controllers/Api/ActivacionController.php`, `backend/routes/api.php:29-41`.

### 5.1 Flujo

1. **Huella del dispositivo**: al abrir la app, `index.astro` genera un `device_fingerprint` con `crypto.randomUUID()` (cuando corre dentro de Electron) y lo guarda en `localStorage` (`index.astro:74-82`). En modo web/demo usa `PUBLIC_DEMO_FP`.
2. **Verificación**: la splash llama `POST /api/v1/dispositivo/verificar` con `{ device_fingerprint }` (`routes/api.php:32`).
   - Si existe una taquilla `active` con esa huella → respuesta `status: active` → redirige a `/login`.
   - Si no → `status: pending` → redirige a la página `/activacion`.
3. **Activación**: `activacion.astro` pide el **código de activación** (entregado por el administrador con la taquilla), obtiene la **MAC** vía `window.electron.getMac()` y llama `POST /api/v1/activar` con `{ activation_code, mac_address, device_fingerprint }` (throttle `10,60`: 10 intentos por hora, `routes/api.php:31`).

### 5.2 Reglas del backend (`ActivacionController::activar`)

| Situación | Respuesta |
|---|---|
| Código inexistente | `404` — "Código de activación inválido." |
| Taquilla ya activa **con la misma MAC** | Reactivación OK (actualiza `last_connection_at`). |
| Taquilla ya activa **con otra MAC** | `403` — anti-hijacking: no se reactiva con una MAC distinta. |
| Otra taquilla activa ya usa esta MAC | Reasignación automática: la otra queda inactiva y sin MAC/huella (queda registrado en la tabla `logs`). |
| Activación exitosa | Vincula `active=true`, `mac_address`, `device_fingerprint`, `last_connection_at`. |

Cada intento (exitoso o no) se registra en la tabla `logs` con acción `activacion_taquilla` (`ActivacionController.php:128-143`), auditable vía `GET /api/v1/logs` (roles `super_master|master`).

### 5.3 Activar un dispositivo pendiente en producción (operador)

1. Crear (o localizar) la taquilla en el panel — el alta de taquillas está en `routes/api.php` (`apiResource taquillas`, roles `super_master|master|banca|grupo|agencia`) — y obtener su `activation_code`.
2. En la PC de la taquilla: instalar el instalador publicado (§4.4) y abrir la app. La splash mostrará "Dispositivo no registrado" y llevará a la pantalla de activación.
3. Entregar el código al operador de la taquilla; este lo ingresa en la pantalla y confirma.
4. Verificar: la app redirige a `/login` y el login exige los headers de dispositivo (`X-Device-Fingerprint` / `X-Device-MAC`, ver `docs/runbook-ops.md`).

### 5.4 Fallos comunes

- **PC nueva = dispositivo nuevo**: una máquina recién instalada genera un `device_fingerprint` nuevo → `/dispositivo/verificar` responde `pending`. Es el comportamiento esperado; se resuelve con el código de activación. No es un error del sistema.
- **Cambio de MAC** (cambio de placa/adaptador, o activar desde otra interfaz): activación rechazada con 403 por anti-hijacking; gestionar la taquilla desde el panel (`PATCH /taquillas/{id}/toggle` o update del `apiResource`, `routes/api.php:89-92`) y reintentar.
- **MAC no disponible**: `activacion.astro` aborta con "No se pudo obtener la dirección MAC" si `window.electron.getMac()` falla (fuera de Electron, sin demo config).

---

## 6. CORS y orígenes

> Estado: actualizado al 2026-09-11 tras `fix-taquilla-produccion` (PR #6). Fuentes: `backend/config/cors.php`, `backend/entrypoint.sh:38`, `taquilla/electron/main/main.cjs`, `backend/.env.example:71-83`.

- **Política del backend**: los orígenes permitidos se leen de `CORS_ALLOWED_ORIGINS` (lista separada por comas, `backend/config/cors.php:15`); `allowed_origins_patterns` incluye el patrón local `#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#` para desarrollo. Aplica a `api/*` y `sanctum/csrf-cookie`, con `supports_credentials: true`.
- **Entrypoint de producción**: `backend/entrypoint.sh:38` usa el default seguro `http://localhost:3000,http://localhost:4321,https://panel.gzuz.dev` — ya **no** hay wildcard. El `.env.production` del VPS puede sobreescribirlo explícitamente.
- **Origen `app://` (Electron)**: lo resuelve el **proxy** de la app, no el backend. El handler `api://` de `main.cjs` hace STRIP de los `access-control-*` del upstream e inyecta su propio CORS: `Access-Control-Allow-Origin` = eco del `Origin` del renderer (`app://index.html` en empaquetado; `http://localhost:3000` en dev), `Allow-Headers`/`Allow-Methods` y `Max-Age: 600`; los preflight `OPTIONS` se responden localmente con `204` sin tocar el upstream. El panel conserva su CORS explícito (`panel.gzuz.dev`), sin cambios.

**Reglas**:

1. Añadir orígenes siempre de forma **explícita** en `CORS_ALLOWED_ORIGINS` (separados por comas, sin espacios).
2. **Nunca usar `*` para el panel** — con `supports_credentials: true` el comodín no funciona en navegadores y amplía superficie de ataque sin necesidad.
3. Tras cambiar la variable en `.env.production`, reiniciar los contenedores API/Horizon (el entrypoint regenera el `.env` en cada arranque, `entrypoint.sh:8-40`).
4. El CORS de la taquilla **no se toca en el backend**: agregar `app://` a los orígenes del backend debilitaría la política sin necesidad — el proxy de la app ya lo resuelve localmente.

---

## 7. Higiene de secrets

> Estado: verificado en el repo al 2026-09-07. Fuentes: `backend/.env.example`, `taquilla/.gitignore:15-21`, `.github/workflows/ci-cd.yml:97-110`, `docs/deploy.md:21-23`.

### 7.1 Reglas

1. **Los secretos nunca se commitean.** Viven solo en:
   - El VPS: `.env.production` (permiso 600) — contraseñas de BD, `APP_KEY`, `SEEDER_PASSWORD`, CORS de prod, etc. (`docs/deploy.md:21-23`, `docs/runbook-ops.md:9`).
   - GitHub Actions secrets: `VPS_SSH_KEY`, `VPS_HOST`, `VPS_USER`, `VPS_PATH` (consumidos en `ci-cd.yml:97-110`). El push de imágenes usa el `GITHUB_TOKEN` del propio workflow.
2. **`.env.example` debe contener solo placeholders vacíos.** Desviación conocida a corregir: `backend/.env.example:31` lleva hoy un valor concreto en `DB_PASSWORD` en lugar de un placeholder. Fix pendiente: sustituirlo por `DB_PASSWORD=` (vacío). No lo repliques en ningún otro archivo.
3. **Excepción documentada**: `taquilla/.env.production` se commitea a propósito y contiene solo `PUBLIC_API_URL` (valor público, decisión D5 — ver comentario en `taquilla/.gitignore:20-21`).
4. Los valores que aparecen en `.github/workflows/ci-cd.yml` para el service MySQL de **tests** (`root`/`test_secret`) son credenciales de entorno de testing efímero; no son secretos de producción.

### 7.2 Rotación

| Secret | Dónde rotar |
|---|---|
| `VPS_SSH_KEY` (CI) | Generar llave nueva sin passphrase → `gh secret set VPS_SSH_KEY` → autorizar la `.pub` en `authorized_keys` del VPS. Procedimiento completo en `docs/deploy.md` (Fase 1-2). |
| Credenciales de BD (`MYSQL_ROOT_PASSWORD`, `DB_PASSWORD`) | Editar `.env.production` del VPS + recrear contenedores afectados; ojo: cambiar la password de MySQL requiere también `ALTER USER` en la BD o re-crear el volumen con backup. |
| `SEEDER_PASSWORD` | Rotar en `.env.production` y re-ejecutar la rotación de passwords de los usuarios del seeder (ver "Pendientes conocidos" en `docs/runbook-ops.md`). |

### 7.3 Protocolo si un secret aparece en un diff

1. **Si aún no se commiteó**: `git checkout -- <archivo>` / deshacer el cambio. Nunca hagas commit "para revisarlo después".
2. **Si ya llegó al historial** (aunque sea un push privado): **rota el secret inmediatamente** — la rotación es el fix real; reescribir historial no lo sustituye.
3. Revisa `git log -p` del archivo afectado para confirmar el alcance y registra el incidente.

---

## 8. Operación diaria

### 8.1 Crear entidades (jerarquía y roles)

> Fuente: `backend/routes/api.php` (secciones USUARIOS/BANCAS/GRUPOS/AGENCIAS/TAQUILLAS).

Jerarquía: **banca → grupo → agencia → taquilla**, creada de arriba hacia abajo. Roles: `super_master`, `master`, `banca`, `grupo`, `agencia`, `taquilla`.

| Endpoint | Quién puede crear/gestionar |
|---|---|
| `POST /api/v1/bancas` (+ `PATCH /bancas/{id}/toggle`) | `super_master`, `master` |
| `POST /api/v1/grupos` (+ toggle) | `super_master`, `master`, `banca` |
| `POST /api/v1/agencias` (+ toggle) | `super_master`, `master`, `banca`, `grupo` |
| `POST /api/v1/taquillas` (+ toggle) | `super_master`, `master`, `banca`, `grupo`, `agencia` |
| `POST /api/v1/users` | todos los roles (el alcance jerárquico lo limita el controlador; `destroy` restringido a `super_master|master`) |

Orden recomendado de alta: banca → grupo → agencia → taquilla → usuario (rol) → entregar `activation_code` a la taquilla (§5). Si heredas datos antiguos sin agencias por grupo, usa el backfill: `php artisan agencias:backfill --dry-run` y luego `--force` (§3.3).

### 8.2 Revisar errores

| Dónde | Comando | Cuándo |
|---|---|---|
| Local | `grep -r "production.ERROR" backend/storage/logs/` | El canal de logs local es `stack/single` (`backend/.env.example:21-22`), escribe en `backend/storage/logs/laravel.log`. |
| Producción | `docker logs -f lotto_api_prod`, `docker logs -f lotto_horizon_prod` y `docker logs -f lotto_scheduler_prod` | En el contenedor el canal es `stderr` (`entrypoint.sh:29`): los errores van a los logs de Docker, **no** a `storage/logs`. El scheduler (agenda de resultados) loguea las ejecuciones de scrape y del sweep. |
| Auditoría funcional | `GET /api/v1/logs` (roles `super_master|master`) | Tabla `logs` de la BD (activaciones, acciones de usuarios). |

### 8.3 Probar scrapers localmente

- **Tasa BCV**: `php artisan scrape:exchange-rate` (ejecución síncrona; revisa el log al terminar) — `backend/app/Console/Commands/ScrapeExchangeRate.php`.
- **Resultados de juegos**: endpoints del panel, roles `super_master|master`: `POST /api/v1/resultados/scrape` (un juego) y `POST /api/v1/resultados/scrape-all` (`backend/routes/api.php:117-120`). En local, autentícate con un usuario de seeder y llama los endpoints.
- **Agenda automática de resultados** (producción): corre en el servicio `scheduler` (`schedule:work`). Para inspeccionarla: `docker exec lotto_scheduler_prod php artisan schedule:list` (pasadas por fuente `scrape_{sourceKey}_{H:i}[+15|+30|+45]`, sweep cada 15 min y cierre a las 23:45). Si siembras o editas horarios (`juego_horarios`), reinicia el scheduler para que tome la agenda: `docker restart lotto_scheduler_prod` (la agenda se congela al arrancar).

### 8.4 Diagnóstico de un HTTP 500 (checklist en orden)

1. **Log primero**: `docker logs lotto_api_prod --tail 200` (prod) o `tail -f backend/storage/logs/laravel.log` (local). El stack trace dice la capa exacta.
2. **Estado de migraciones**: `php artisan migrate:status` — una migración `Pending` con código que ya la asume produce 500 en producción (y contradiría §3: revisa por qué el deploy no la aplicó).
3. **Filas soft-deleted**: muchas entidades usan soft deletes (el patrón `withTrashed` aparece, p. ej., en `AgenciasBackfill.php:169`). Con `php artisan tinker` revisa la entidad implicada:
   ```php
   $x = \App\Models\Agencia::withTrashed()->find($id);
   $x->trashed(); // true = está eliminada lógicamente
   ```
   Un padre soft-deleted (grupo/agencia) mientras sus hijos siguen activos es una causa típica de 500 en rutas jerárquicas.
4. **Después de corregir**: reiniciar solo lo necesario (`docker compose --env-file .env.production -f docker-compose.prod.yml up -d`) y re-verificar el healthcheck local: `curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' http://127.0.0.1:10000/api/v1/juegos` → esperar `200` o `401`.

---

## Registro de verificación

| Dato | Fuente en el repo |
|---|---|
| Pipeline (tests → GHCR → SSH deploy → rollback) | `.github/workflows/ci-cd.yml` |
| Migraciones al arranque / Horizon y scheduler las omiten / CORS `*` por defecto / log `stderr` | `backend/entrypoint.sh` |
| Servicios, volúmenes (incl. `taquilla_releases` y textfile `/var/lib/lotto-metrics`), healthchecks | `docker-compose.prod.yml` |
| Rutas públicas/autenticadas, throttles, releases | `backend/routes/api.php` |
| Throttle `releases-download` (10/min) | `backend/app/Providers/AppServiceProvider.php:29-30` |
| `releases:publish` (SHA-256, fila única D3) | `backend/app/Console/Commands/ReleasePublishCommand.php` |
| `agencias:backfill` (`--force` prod, `--dry-run`) | `backend/app/Console/Commands/AgenciasBackfill.php` |
| Disco `releases` → `storage/app/releases` | `backend/config/filesystems.php:50-55` |
| Contrato `api://` del renderer y proxy (problema doble `/api`) | `taquilla/electron/main/main.cjs:119-175`, `taquilla/src/pages/index.astro:72`, `taquilla/src/pages/activacion.astro:4` |
| Config de build / artifactName / duplicado `directories` | `taquilla/package.json` |
| `pnpm-lock.yaml` gitignoreado | `taquilla/.gitignore:5` |
| CORS por env | `backend/config/cors.php:15` |
| Verificación de dispositivos / activación | `backend/app/Http/Controllers/Api/DispositivoController.php`, `ActivacionController.php` |
| Estado de artefactos `release/` / `build/` / `dist/` | inspección directa de carpetas, 2026-09-07 |
