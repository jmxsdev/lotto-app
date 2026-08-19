# Apply Progress: despliegue-vps — Slice 1 (PR 1)

## Estado de tareas (Fase 1)

| Tarea | Estado | Evidencia |
|---|---|---|
| 1.1 quitar `backend/.env` + `.env.example` | ✅ | commit `36e55d2` |
| 1.2 RED (commit state): filter-repo rechaza worktree sucio | ✅ | "Aborting: ... (you have unstaged changes)" en clon de prueba `/tmp/opencode/fr-red` |
| 1.3 Purga de historial | ✅ local | Clon limpio `/tmp/opencode/fr-purge`; `backend/.env` fuera de los 168 commits; APP_KEY viejo: 0; 167 commits con `MYSQL_ROOT_PASSWORD: REDACTED`; ramas preservadas. **Pendiente: force-push (requiere confirmación del usuario)** |
| 1.4 Rotación APP_KEY + credenciales DB | ✅ | commit `0fb36a8`; APP_KEY nuevo en `.env` local; MySQL local alineado vía `ALTER USER` |
| 1.5 Prefix `v1` en `routes/api.php` | ✅ | commit `25e284a` |
| 1.6 CORS/Sanctum por env documentado | ✅ | `backend/.env.example` con valores prod comentados |
| 1.7 Clientes taquilla/panel `/api/v1` | ✅ | commit `102000b` (13 archivos) |
| 1.8 Verificación rutas + tests | ✅ | 74 rutas `/api/v1/*`, 0 desnudas; suite: **214 passed, 0 failed, 2 skipped** (566 s) |

## Commits del slice (rama `deploy/seguridad-versionado-v1`)

- `36e55d2` fix(security): quitar backend/.env del seguimiento y crear .env.example
- `0fb36a8` fix(security): rotar credenciales de desarrollo y añadir plantilla .env.production
- `25e284a` feat(api): versionar rutas bajo /api/v1 y actualizar tests
- `102000b` feat(clients): apuntar taquilla y panel a /api/v1
- `89bae55` docs(sdd): artefactos de planificación del cambio despliegue-vps

## Evidencia de verificación

- `php artisan route:list --path=api`: 74 rutas con prefijo `api/v1/`, 0 rutas desnudas `api/`.
- PHPUnit completo (MySQL local con credenciales rotadas): `{"tool":"phpunit","result":"passed","tests":216,"passed":214,"assertions":860,"skipped":2,"duration_ms":566568}`.

## Pendiente de confirmación del usuario

1. ~~Force-push del historial purgado~~ **✅ COMPLETADO 2026-08-19**: mirror push de `/tmp/opencode/fr-purge` a `origin`. Verificado en clon fresco del remoto: 0 apariciones del APP_KEY viejo, 0 de `backend/.env`, 0 de `MYSQL_PASSWORD: secret`; 45 heads y 172 commits reescritos. El repo local adoptó el historial reescrito (`git reset --hard origin/deploy/seguridad-versionado-v1`).
2. ~~Push de la rama + apertura del PR 1~~ **✅ ENTREGADO directo a main (decisión del mantenedor, 2026-08-19)**: el usuario prefirió merge directo en lugar de PR. Fast-forward `feat/seguridad-versionado-v1` → `main` (d0147fc) y push a origin. La rama queda como `feat/seguridad-versionado-v1`.

## Rollback boundary

Revertir los commits `25e284a` y `102000b` devuelve `/api` sin v1 sin efectos colaterales (los cambios de seguridad 36e55d2/0fb36a8 son independientes y no se revierten). No hay migraciones de BD en este slice.

## Gotchas encontrados

- El shell de sesión exporta variables viejas (`DB_PASSWORD=secret`, APP_KEY viejo) que tienen precedencia sobre `.env` → ejecutar artisan/phpunit con `env -u DB_* APP_KEY` o limpiar el entorno.
- `git filter-repo` aplica los regex de `--replace-text` al archivo completo SIN flag MULTILINE → los anclajes `^...$` no matchean archivos multi-línea; usar lookahead (`root(?![\w])`) o literales.
- La suite completa tarda ~9.5 min; `composer test` (que llama `artisan test`) parece colgarse pero solo es lento. El CI (slice 3) debe presupuestar timeout amplio.
- `*.md` está en `.gitignore` raíz: los artefactos OpenSpec nuevos requieren `git add -f`.

---

# Apply Progress — Slice 2 (Infraestructura VPS)

## Estado de tareas (Fase 2)

| Tarea | Estado | Evidencia |
|---|---|---|
| 2.1 Hardening + provisioning VPS | ✅ | usuario deploy (solo llaves), root SSH deshabilitado, fail2ban, UFW 22/80/443, unattended-upgrades, swap 4G, logrotate Docker, contraseña root rotada |
| 2.2 Dockerfile FrankenPHP + entrypoint | ✅ | `dunglas/frankenphp:1-php8.3` + pdo_mysql/bcmath/zip/redis/pcntl/posix; php-server (worker mode = follow-up Octane); Horizon gated |
| 2.3 docker-compose.prod.yml | ✅ | api, mysql 8 (4G/300), redis AOF, horizon, caddy; sin phpMyAdmin; puertos internos |
| 2.4 Caddyfile | ✅ | lotto.gzuz.dev + headers de seguridad; status comentado (slice 4) |
| 2.5 RED (git selection) + deploy.sh | ✅ | deploy.sh con healthcheck (200/401) y rollback manual |
| 2.6 Despliegue en VPS | ✅ | stack UP: 5 contenedores healthy; smoke 401/422/404 JSON correctos |
| 2.7 DNS Cloudflare | ⏳ manual (usuario) | guía en `docs/runbook-ops.md`; requiere navegador + nameservers |

## Correcciones de diseño aplicadas

- **Disco real: 237 GB** (no 126 GB) — umbrales de alerta se calcularán sobre el valor real en slice 4.
- **Ubicación del deploy**: `/home/deploy/lotto-app` (no `/srv/lotto-app`; deploy no tiene sudo por diseño).
- **FrankenPHP sin modo worker** en v1: el worker exige `frankenphp_handle_request()`/Octane — follow-up documentado.
- **`shouldRenderJsonWhen(fn() => true)`** en bootstrap/app.php: API pura, evita `Route [login] not defined` en requests sin cabecera Accept.
- Se agregaron extensiones `redis` y `pcntl`/`posix` (fallos de boot documentados y corregidos).

## Gotchas del slice 2

- El entrypoint corre migraciones+seed solo en el contenedor API (RUN_HORIZON gate); el seeder duplica `super@lotto.com` si la BD no está limpia (tolerado con `|| echo (ok)`, revisar idempotencia).
- Caddy no emite certificados hasta que el DNS resuelva al VPS (esperado; CF pendiente).
- UFW activo: solo 22/80/443.

---

# Apply Progress — Slice 3 (CI/CD)

## Estado de tareas (Fase 3)

| Tarea | Estado | Evidencia |
|---|---|---|
| 3.1 Tests con MySQL service | ✅ | phpunit.xml DB_* con `force="false"`; job env inyecta MySQL service; suite local con config idéntica al CI: 214 passed / 2 skipped (189 s) |
| 3.2 Workflow ci-cd.yml | ✅ | `.github/workflows/ci-cd.yml`: tests (MySQL service) → Pint → build GHCR (sha+latest) → deploy SSH + healthcheck + rollback |
| 3.3 RED (push state) | ✅ | llave CI rechazada en `git push` al repo y aceptada en SSH al VPS (SCENARIO-8) |
| 3.4 GitHub Secrets | ⏳ usuario | pendientes: VPS_SSH_KEY, VPS_HOST, VPS_USER, VPS_PATH (ver runbook) |

## Notas

- **Deuda de estilo saldada**: `vendor/bin/pint` normalizó ~100 archivos (commit `92daab8`); el gate Pint de CI quedó verde.
- El deploy del workflow se omite automáticamente si los secrets VPS_* no existen.
- GHCR: la imagen pública `ghcr.io/jmxsdev/lotto-app-api` (tags sha+latest) se empuja con GITHUB_TOKEN (repo público, packages:write).
- Ledger: intento slice3 adquirido (token conservado); settle pendiente de verificar la corrida verde en GitHub Actions (requiere confirmación del usuario).

## Actualización slice 3 (cierre, 2026-08-19)

| Tarea | Estado | Evidencia |
|---|---|---|
| 3.1 Tests con MySQL service | ✅ (cerrado) | CI verde: `32218875595` y `32219757146` success (Pint + PHPUnit) |
| 3.2 Workflow ci-cd.yml | ✅ (cerrado) | deploy SSH real ejecutado: api/horizon corren `ghcr.io/jmxsdev/lotto-app-api:latest`, 5 contenedores healthy, smoke 401 |
| 3.4 GitHub Secrets | ✅ (cerrado) | VPS_HOST, VPS_USER, VPS_PATH, VPS_SSH_KEY creados con `gh secret set`; deploy usa secrets |

## Fixes de CI encontrados

- **Warning masivo de PHPUnit (216 warnings / exit 1)**: causa raíz = `backend/tests/Unit/TripleZuliaScraperTest.php` declaraba `class TripletasScraperTest` (clase desincronizada del nombre de archivo → "Class TripleZuliaScraperTest cannot be found"). Renombrado a `backend/tests/Unit/TripletasScraperTest.php` con clase coherente (`0074906`). Suite verde sin warnings.
- **Contenedores `(unhealthy)` tras deploy**: la imagen FrankenPHP trae HEALTHCHECK propio apuntando a `:2019/metrics` (admin de Caddy, inactivo en php-server). Override en compose: api = curl 401/200 a `/api/v1/juegos`; horizon = `php artisan horizon:status` (`a4d91fc`).
- Diagnóstico agregado al CI: `COLUMNS=300` + `--display-warnings --log-junit junit.xml` + step de lectura de warnings.

## Ledger

- Slice 3 settle: `passed` (2710 líneas, sobre presupuesto 800) → reset aprobado por mantenedor (mismo criterio que slice 1); ledger limpio, sin intentos activos.
