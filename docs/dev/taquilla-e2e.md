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

Luego ajustar la taquilla demo a los valores E2E (hasta que `E2eSeeder`
llegue en la fase 2):

```sql
UPDATE taquillas SET mac_address='02:E2:E0:00:00:01',
       device_fingerprint='e2e-device-0001' WHERE code='DEMO01';
```

> **Nota**: el design original proponía `02:E2E:00:00:00:01`, pero ese literal
> es inválido para `VerifyMac` (`^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$`:
> `E2E` son 3 hex seguidos → 403 "Formato de MAC inválido"). Se usa el MAC
> válido `02:E2:E0:00:00:01`.

## Uso

```bash
cd taquilla
API_UPSTREAM=http://localhost:8003 pnpm e2e                 # toda la suite
API_UPSTREAM=http://localhost:8003 pnpm e2e e2e/tests/smoke.spec.mjs
```

- Exit code 0 = verde; distinto de 0 = fallo (apto para CI).
- Artefactos en `taquilla/e2e/artifacts/` (gitignored): capturas por paso,
  `session.json` y reporte HTML en `e2e/html-report/`.

## Variables y flags

| Variable | Efecto |
|---|---|
| `API_UPSTREAM` | URL de la API local (`http://localhost:PUERTO` o `127.0.0.1`). **Cualquier otro valor aborta el run antes de lanzar Electron** (guard REQ-2). |
| `E2E_MAC` | MAC que devuelve el stub `get-mac` (default `02:E2:E0:00:00:01`). Debe coincidir con `taquillas.mac_address` para pasar `VerifyMac`. |
| `E2E_FINGERPRINT` | Fingerprint inyectado (default `e2e-device-0001`). Debe coincidir con `taquillas.device_fingerprint`. |
| `E2E_EMAIL` / `E2E_PASSWORD` | Credenciales del usuario de rol `taquilla` (default `demo@lotto.com` / `password`). |
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
   temporal, `clearStorageData()`, reloj congelado a hoy 06:00
   America/Caracas (`page.clock.install`), stubs IPC vía `electronApp.evaluate`
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