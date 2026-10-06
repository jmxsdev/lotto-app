# Suite E2E de Taquilla (Playwright + Electron)

Harness determinístico de pruebas end-to-end para la taquilla (Electron +
Astro). Lanza la app en modo build (`app://index.html`) contra una **API
local**, con estado fresco, reloj congelado e impresión stubeada.

## Requisitos

- API local corriendo (`php artisan serve`). El upstream se toma de
  `API_UPSTREAM` (default `http://localhost:8000`); en CI es `:8000`.
- Usuario de rol `taquilla` activo + taquilla activa con `mac_address` y
  `device_fingerprint` registrados (la app exige ambos vía `VerifyMac`).
- `dist/` construido (el global-setup lo construye si falta).
- Electron ya instalado (`node_modules/`); Playwright NO descarga browsers.

### Preparar la base de datos (local)

Para no tocar `lotto_db` (base dev del operador), se usa una base dedicada:

```bash
# desde backend/
docker exec lotto_mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" \
  -e "CREATE DATABASE IF NOT EXISTS lotto_e2e ...; GRANT ALL ON lotto_e2e.* TO 'lotto_user'@'%';"
DB_DATABASE=lotto_e2e php artisan migrate:fresh --seed --force
```

El fixture E2E (taquilla `E2E01`, usuario `e2e@lotto.com`, `clave_cierre`,
resultado + ticket ganador) lo crea **`E2eSeeder`** (opt-in, idempotente):

```bash
pnpm e2e:seed     # usa DB_DATABASE (default: lotto_e2e) — NUNCA apuntarlo a lotto_db
```

> **Nota MAC**: el design original proponía `02:E2E:00:00:00:01`, inválido para
> `VerifyMac` (`^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$`); se usa
> `02:E2:E0:00:00:01` (default de `E2E_MAC`).

## Uso

```bash
cd taquilla
API_UPSTREAM=http://localhost:8003 pnpm e2e                 # toda la suite
API_UPSTREAM=http://localhost:8003 pnpm e2e e2e/tests/smoke.spec.mjs
```

- `pnpm e2e` **siembra el fixture automáticamente** (`pnpm e2e:seed` encadenado)
  contra `DB_DATABASE` (default `lotto_e2e`). Para correr sin sembrar (debug):
  `pnpm exec playwright test -c e2e/playwright.config.mjs`.
- Exit code 0 = verde; distinto de 0 = fallo (apto para CI).
- Artefactos en `taquilla/e2e/artifacts/` (gitignored): capturas por paso,
  `session.json` y reporte HTML en `e2e/html-report/`.
- Throttle `/login` (10/2 min): corridas consecutivas del suite deben
  espaciarse ~2 minutos (el seed no lo evita; aplica al login).

## CI (GitHub Actions)

El job `e2e` de `.github/workflows/ci-cd.yml` replica el patrón de `tests`
(MySQL service con base dedicada `lotto_e2e`) y corre la suite completa:

1. `migrate:fresh --seed` + `db:seed --class=E2eSeeder` (el `pnpm e2e` vuelve a
   sembrar vía `e2e:seed` — idempotente, sin daño).
2. `php artisan serve :8000` en background + healthcheck `GET /api/v1/juegos`
   (200/401).
3. `pnpm install` con `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1`, `pnpm build` y
   `E2E_SKIP_BUILD=1 xvfb-run -a pnpm e2e`.
4. Artefactos (`taquilla/e2e/artifacts/` + `taquilla/e2e/html-report/`) subidos
   con `if: always()` (retención 7 días).

- Dispara solo con push a `main` (o `workflow_dispatch`): la validación real
  del job ocurre post-merge.
- Corre **en paralelo a `deploy`** (sin `needs`) en v1: la flakiness de
  Electron no debe bloquear hotfixes POS. Promoción: tras 10 corridas verdes
  consecutivas, cambiar `deploy` a `needs: [build, e2e]`.
- Throttle `/login` (10/2 min): una suite por job = 8 logins (7 specs + el
  global-setup) → dentro del límite; `retries CI?2:0` cubren reintentos.

## Variables y flags

| Variable | Efecto |
|---|---|
| `API_UPSTREAM` | URL de la API local (`http://localhost:PUERTO` o `127.0.0.1`). **Cualquier otro valor aborta el run antes de lanzar Electron** (guard REQ-2). |
| `E2E_MAC` | MAC que devuelve el stub `get-mac` (default `02:E2:E0:00:00:01`). Debe coincidir con `taquillas.mac_address` para pasar `VerifyMac`. |
| `E2E_FINGERPRINT` | Fingerprint inyectado (default `e2e-device-0001`). Debe coincidir con `taquillas.device_fingerprint`. |
| `E2E_EMAIL` / `E2E_PASSWORD` | Credenciales del usuario de rol `taquilla` (default `e2e@lotto.com` / `password`, creado por el `E2eSeeder` sobre la taquilla E2E01). |
| `E2E_DEV_SERVER=1` | Modo dev: lanza contra `ELECTRON_DEV_URL` (default `http://localhost:3000`) en vez de `app://`. Requiere `astro dev` corriendo. |
| `E2E_SKIP_BUILD=1` | Omite el build de `dist` en el global-setup (útil con `E2E_DEV_SERVER` o tras build manual). |
| `E2E_TRACE=1` | Trace de Playwright siempre activo (default: `on-first-retry`). |
| `E2E_CDP=9222` | Añade `--remote-debugging-port=9222` a Electron para adjuntar el MCP de Playwright (ver abajo). |
| `E2E_STUB_PRINT=1` | (Reservado) fallback dev-only de stubs si `evaluate` fallara; jamás activo empaquetado. |
| `CI=1` | Retries 2 por test (flakiness de Electron en runners). |

## Cómo funciona

1. **global-setup**: guard de API local → healthcheck (`GET /api/v1/juegos`
   <500) → build `dist` si falta → login API → `e2e/artifacts/session.json`.
2. **Por spec** (`helpers/app.mjs`): lanza Electron con `--user-data-dir`
   temporal, `clearStorageData()`, reloj congelado a mañana 06:00
   America/Caracas (`page.clock.install`; mañana, no hoy: el backend valida
   `sorteo_hora` contra el reloj real — D3 — y "hoy 06:00" rompía las ventas
   tras las 08:00 reales), stubs IPC vía `electronApp.evaluate`
   (captura en `globalThis.__e2e_prints`), fingerprint/token inyectados y
   navegación determinística a `app://index.html`.
3. **Stubs IPC** (evaluate-only, sin cambios de runtime): `get-mac` devuelve el
   MAC E2E; `print-ticket`/`print-cierre`/`print-reporte` capturan el payload y
   responden `{success:true}` — nunca abren diálogo de impresión.

La impresión real se verifica por aserción sobre `__e2e_prints[]` y
`localStorage.ultimoTicket`; los badges (`.badge-anulada`, CONCILIADO) se
aseguran en los specs de flujo (fase 2).

## MCP exploratorio (Playwright MCP vía CDP)

Para inspeccionar la app en vivo desde el agente (complementario; el gate de CI
sigue siendo `pnpm e2e`):

```bash
API_UPSTREAM=http://localhost:8003 E2E_CDP=9222 pnpm e2e e2e/tests/smoke.spec.mjs
# Mientras corre, adjuntar el MCP de Playwright a:
#   http://localhost:9222
# El agente puede navegar, capturar y leer window.electron / __e2e_prints.
```

Ejemplo con el MCP de Playwright (`@playwright/mcp`) apuntando al CDP:

```json
{
  "mcpServers": {
    "playwright": {
      "command": "npx",
      "args": ["@playwright/mcp@latest", "--cdp-endpoint", "http://localhost:9222"]
    }
  }
}
```

## Estructura

```
taquilla/e2e/
  playwright.config.mjs   # workers=1, retries CI?2:0, list+html, outputDir artifacts/
  global-setup.mjs        # guard → healthcheck → build → login
  global-teardown.mjs     # limpia user-data dirs temporales
  helpers/
    guard.mjs             # assertLocalApi (aborta si API_UPSTREAM no es local)
    guard.test.mjs        # unit test (node --test)
    app.mjs               # launchApp: estado fresco + clock + stubs + init scripts
    api.mjs               # healthcheck, login, session.json
    fixtures.mjs          # constantes E2E + selectores
    artifacts.mjs         # capturas por paso + JSON helpers
  tests/                  # specs de Playwright (*.spec.mjs)
  artifacts/              # gitignored: capturas, session.json
  html-report/            # gitignored: reporte HTML
```

## Guardas (no romper)

- El harness **nunca** apunta a producción: `assertLocalApi` aborta si
  `API_UPSTREAM` no es `http://localhost:`/`127.0.0.1` (REQ-2).
- Stubs **solo vía `evaluate`**; no se modifica `ipcHandlers.cjs` ni
  `updater.cjs` (auto-update intacto).
- Sin cambios de runtime de la app por defecto: el modo build (`app://`) es el
  default; `E2E_DEV_SERVER=1` es opt-in documentado (REQ-7).
- `node scripts/check-pure.mjs` y `node scripts/check-icons.mjs` deben seguir
  verdes tras cualquier cambio.