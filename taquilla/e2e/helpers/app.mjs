// Lanzador de la app Electron con estado fresco determinístico (design: AD #1/#6/#7).
//
// Secuencia por spec (Data Flow del design):
//   launch → firstWindow → clearStorageData → clock.install (06:00 Caracas)
//   → IPC stubs (evaluate-only) → addInitScript(fp/token) → reload
import { _electron, expect } from '@playwright/test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { resolveUpstream } from './guard.mjs';
import {
  E2E_MAC,
  E2E_FINGERPRINT,
  E2E_EMAIL,
  E2E_PASSWORD,
  SELECTORS,
  STUB_CHANNELS,
  caracasSixAmToday,
} from './fixtures.mjs';
import { ensureArtifactsDir, readJson, writeJson, TMP_DIRS_FILE } from './artifacts.mjs';

const require = createRequire(import.meta.url);
// `require('electron')` desde Node devuelve la ruta al binario de Electron.
const ELECTRON_PATH = require('electron');

const __dirname = path.dirname(fileURLToPath(import.meta.url));
export const E2E_ROOT = path.resolve(__dirname, '..');
export const TQ_ROOT = path.resolve(E2E_ROOT, '..');

const registeredTmpDirs = [];

function trackTmpDir(dir) {
  registeredTmpDirs.push(dir);
  ensureArtifactsDir();
  const prev = readJson(TMP_DIRS_FILE) || [];
  writeJson(TMP_DIRS_FILE, [...prev, dir]);
}

/**
 * Lanza la app en build mode (app://) salvo E2E_DEV_SERVER=1.
 * Estado fresco: --user-data-dir temporal + clearStorageData() + init scripts.
 */
export async function launchApp({
  upstream = resolveUpstream(),
  token = null,
  fingerprint = E2E_FINGERPRINT,
  devServer = process.env.E2E_DEV_SERVER === '1',
} = {}) {
  const userDataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'taquilla-e2e-'));
  trackTmpDir(userDataDir);

  const args = ['.', `--user-data-dir=${userDataDir}`];
  // MCP exploratorio: E2E_CDP=9222 expone --remote-debugging-port (REQ-8).
  if (process.env.E2E_CDP) {
    args.push(`--remote-debugging-port=${process.env.E2E_CDP}`);
  }

  // Env del hijo: upstream + seeds; el threat-matrix exige STRIP de
  // ELECTRON_DEV_URL/NODE_ENV en build mode (jamás heredar dev del padre).
  const env = {
    ...process.env,
    API_UPSTREAM: upstream,
    E2E_MAC,
    E2E_FINGERPRINT: fingerprint,
  };
  delete env.NODE_ENV;
  delete env.ELECTRON_DEV_URL;
  if (devServer) {
    env.NODE_ENV = 'development';
    env.ELECTRON_DEV_URL = process.env.ELECTRON_DEV_URL || 'http://localhost:3000';
  }

  const app = await _electron.launch({
    args,
    cwd: TQ_ROOT,
    executablePath: ELECTRON_PATH,
    env,
    timeout: 60_000,
  });

  const page = await app.firstWindow();
  page.setDefaultTimeout(15_000);

  // El primer load del splash corre SIN el fingerprint inyectado (addInitScript
  // aún no registrado) y puede redirigir a /activacion. Navegar a about:blank
  // frena ese flujo; el goto final a app://index.html es la navegación
  // determinística con el estado fresco completo.
  await page.goto('about:blank');

  // Estado fresco: storage del session default limpio (userData ya es temporal).
  await app.evaluate(({ session }) => session.defaultSession.clearStorageData());
  // Reloj congelado a hoy 06:00 America/Caracas → horarios siempre futuros.
  await page.clock.install({ time: caracasSixAmToday() });
  // Stubs IPC evaluate-only: removeHandler + re-handle, captura en el main.
  await stubPrintIpc(app);
  // Fingerprint (+ token opcional) ANTES del primer script del renderer.
  await page.addInitScript(
    ({ fingerprint: fp, token: tkn }) => {
      if (fp) localStorage.setItem('device_fingerprint', fp);
      if (tkn) localStorage.setItem('auth_token', tkn);
    },
    { fingerprint, token }
  );
  await page.goto('app://index.html');

  // Asentamiento: el splash resuelve /dispositivo/verificar y redirige a un
  // destino estable (login|activacion|dashboard). Sin esto, los specs corren
  // contra una navegación en curso ("Execution context was destroyed").
  await page
    .waitForURL(/\/(login|activacion|dashboard)/, { timeout: 20_000 })
    .catch(() => {});

  return { app, page, upstream, userDataDir };
}

/**
 * Stub evaluate-only de impresión (design AD #1): re-registra los handlers IPC
 * para capturar payloads en globalThis.__e2e_prints del proceso main. get-mac
 * devuelve el MAC E2E (necesario para VerifyMac). Sin cambios de runtime.
 */
export async function stubPrintIpc(app) {
  await app.evaluate(({ ipcMain }, channels) => {
    globalThis.__e2e_prints = globalThis.__e2e_prints || [];
    const mac = process.env.E2E_MAC || '02:E2:E0:00:00:01';
    for (const channel of channels) {
      try {
        ipcMain.removeHandler(channel);
      } catch {
        /* sin handler previo */
      }
      ipcMain.handle(channel, (event, payload) => {
        globalThis.__e2e_prints.push({ channel, payload, at: Date.now() });
        if (channel === 'get-mac') return mac;
        return { success: true, message: `e2e-stub:${channel}`, e2e: true };
      });
    }
  }, STUB_CHANNELS);
}

/** Payloads capturados por los stubs IPC en el proceso main. */
export function printsHandle(app) {
  return app.evaluate(() => globalThis.__e2e_prints || []);
}

/**
 * Avanza el reloj congelado (page.clock.install ≡ pauseAt): los setTimeout del
 * renderer (p. ej. redirect post-login) solo disparan con fastForward/runFor.
 */
export function advanceClock(page, ms) {
  return page.clock.fastForward(ms);
}

/**
 * Flujo de login E2E hasta el dashboard (reutilizado por los specs 02–07):
 * la API responde con token → localStorage.auth_token; el redirect a
 * /dashboard es un setTimeout(1s) que exige advanceClock (reloj congelado).
 */
export async function loginToDashboard(page, { email = E2E_EMAIL, password = E2E_PASSWORD } = {}) {
  await expect(page).toHaveURL(/\/login/, { timeout: 20_000 });
  await page.fill(SELECTORS.login.email, email);
  await page.fill(SELECTORS.login.password, password);
  await page.click(SELECTORS.login.submit);
  await page.waitForFunction(() => Boolean(localStorage.getItem('auth_token')), null, {
    timeout: 15_000,
  });
  await advanceClock(page, 1_500);
  await expect(page).toHaveURL(/\/dashboard/, { timeout: 20_000 });
  // Dashboard INTERACTIVO: los listeners de teclado (MainLayout + routeKey del
  // dashboard) se registran al final del script de la página, después de
  // renderizar el catálogo. Esperar #qt-numero evita presionar F-keys antes
  // de que la navegación global esté viva (race).
  await expect(page.locator(SELECTORS.dashboard.numero)).toBeVisible({ timeout: 20_000 });
}

/**
 * Navegación global robusta (F5–F8, NAV_GLOBAL de MainLayout): esas teclas
 * viven en el listener GLOBAL del layout, que puede registrarse DESPUÉS del
 * script del dashboard — el `#qt-numero` visible (loginToDashboard) no lo
 * garantiza, y un press temprano se pierde (race de carga, no bug de la app).
 * Reintenta presionando la tecla hasta que la URL matchea; re-navegar es
 * idempotente (GET de una ruta, sin efectos laterales). Al agotar los
 * intentos lanza con la última URL para diagnosticar el estado real.
 */
export async function navigateGlobalKey(page, key, urlPattern, { attempts = 5, perTry = 2500 } = {}) {
  for (let i = 0; i < attempts; i += 1) {
    if (urlMatches(page.url(), urlPattern)) return;
    await page.keyboard.press(key);
    const ok = await page
      .waitForURL(urlPattern, { timeout: perTry })
      .then(() => true)
      .catch(() => false);
    if (ok) return;
    // El listener global aún no estaba vivo: ventana para que se registre.
    await page.waitForTimeout(300);
  }
  throw new Error(
    `navigateGlobalKey(${key}): no se navegó a ${urlPattern} tras ${attempts} intentos; última URL: ${page.url()}`
  );
}

/** ¿La URL actual matchea el patrón (RegExp, o substring si es string)? */
function urlMatches(url, pattern) {
  return typeof pattern === 'string' ? url.includes(pattern) : url.match(pattern) !== null;
}

/**
 * Una línea de venta en el dashboard (specs 02/03/05/07): selecciona el
 * primer juego del tab activo (lotto-activo), el animal, un horario del
 * catálogo y añade la línea con el monto. El reloj del harness está en
 * mañana 06:00 → el horario (08:00) es futuro para el renderer Y para el
 * backend (D3), a cualquier hora real.
 */
export async function sellTicketLine(page, { animal = 'Perro', monto = '5000', horario = '08:00' } = {}) {
  await page.click('.juego-card[data-id]');
  await page.click(`.animal-check[data-label="${animal}"]`);
  await page.click(`.horario-item[data-hora="${horario}"]`);
  await page.fill(SELECTORS.dashboard.monto, monto);
  await page.click(SELECTORS.dashboard.add);
  // addLine ok: la línea aparece en el resumen (fila agrupada por grupo).
  await expect(page.locator('.resumen-table tbody tr')).not.toHaveCount(0, { timeout: 10_000 });
}

/**
 * Elige el tipo de pago en la zona `pago` (change tipo-pago-taquilla).
 * OBLIGATORIO antes de imprimir: sin método, handlePrint bloquea con aviso (R6).
 * Los tickets de los specs 02/03/05 son en Bs → `efectivo` es válido.
 */
export async function seleccionarTipoPago(page, metodo = 'efectivo') {
  const item = page.locator(`#pago-list .pago-item[data-metodo="${metodo}"]`);
  await item.click();
  await expect(item).toHaveClass(/activo/);
}

/** Cierra la app y limpia los --user-data-dir temporales registrados. */
export async function closeApp(app) {
  try {
    await app.close();
  } catch {
    /* ya cerrada */
  }
  for (const dir of [...registeredTmpDirs]) {
    try {
      fs.rmSync(dir, { recursive: true, force: true });
    } catch {
      /* best-effort */
    }
    registeredTmpDirs.splice(registeredTmpDirs.indexOf(dir), 1);
  }
}