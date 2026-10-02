// Updater OTA de la taquilla (TQ-10) — modulo main.
// - `resolveFeedUrl(upstream)` es PURO (sin Electron): deriva la URL del feed
//   generic de electron-updater desde el upstream de la API.
// - `initUpdater({upstream, getWindow, isPackaged})` conecta electron-updater
//   (NSIS) SOLO en app empaquetada: chequeo al boot + cada 1 h, autoDownload,
//   autoInstallOnAppQuit, eventos → IPC y `install()` con guard de venta
//   (query-busy 500 ms = busy + cache reportBusy).
//
// electron-updater se requiere de forma perezosa (solo en start()) para que
// este archivo siga siendo importable sin la dependencia instalada (el test
// puro de resolveFeedUrl no necesita Electron).

const CHECK_INTERVAL_MS = 60 * 60 * 1000; // 1 hora
const BUSY_QUERY_TIMEOUT_MS = 500; // sin reply en 500 ms → se trata como busy (D4)

const FEED_PATH = '/api/v1/releases/feed';

/**
 * Puro: `https://lotto.gzuz.dev` → `https://lotto.gzuz.dev/api/v1/releases/feed`.
 * Normaliza la barra final (0, 1 o varias) y devuelve null si el upstream
 * está vacío. electron-updater (generic provider) concatena `latest.yml`.
 */
function resolveFeedUrl(upstream) {
  const base = String(upstream || '').trim().replace(/\/+$/, '');
  if (!base) return null;
  return `${base}${FEED_PATH}`;
}

/**
 * Crea el updater. Devuelve la API { start, install, reportBusy, respondBusy,
 * getState, isActive }. `start()` no hace nada en desarrollo (isPackaged=false).
 */
function initUpdater({ upstream, getWindow, isPackaged }) {
  const state = {
    status: 'idle', // idle | checking | downloading | downloaded | error
    version: null,
    percent: 0,
    error: null,
    busyCache: false,
  };
  const busyReplies = new Map(); // requestId → resolve(busy)
  let lastBusyQueryId = 0;
  let timer = null;

  function send(channel, payload) {
    const win = typeof getWindow === 'function' ? getWindow() : null;
    if (win && !win.isDestroyed()) {
      win.webContents.send(channel, payload);
    }
  }

  function checkNow() {
    const { autoUpdater } = require('electron-updater');
    return autoUpdater.checkForUpdates().catch((err) => {
      // El evento 'error' ya emite update:error; aquí solo se evita la
      // promesa rechazada sin manejar (offline → update:error + reintento).
      console.error('[updater] checkForUpdates fallo:', err.message);
    });
  }

  function start() {
    if (!isPackaged) {
      console.log('[updater] modo desarrollo: updater desactivado (solo app.isPackaged).');
      return { ok: false, reason: 'dev' };
    }

    const { autoUpdater } = require('electron-updater');
    const feedUrl = resolveFeedUrl(upstream);
    if (!feedUrl) {
      console.error('[updater] upstream invalido, updater desactivado.');
      return { ok: false, reason: 'no-upstream' };
    }

    autoUpdater.setFeedURL({ provider: 'generic', url: feedUrl });
    autoUpdater.autoDownload = true;
    autoUpdater.autoInstallOnAppQuit = true;

    autoUpdater.on('checking-for-update', () => {
      state.status = 'checking';
      send('update:checking');
    });

    autoUpdater.on('update-available', (info) => {
      state.status = 'downloading';
      state.version = info.version;
      send('update:available', { version: info.version });
    });

    autoUpdater.on('download-progress', (progress) => {
      state.status = 'downloading';
      state.percent = progress.percent || 0;
      send('update:progress', {
        percent: state.percent,
        transferred: progress.transferred,
        total: progress.total,
        bytesPerSecond: progress.bytesPerSecond,
      });
    });

    autoUpdater.on('update-downloaded', (info) => {
      state.status = 'downloaded';
      state.version = info.version;
      state.percent = 100;
      send('update:downloaded', { version: info.version });
    });

    autoUpdater.on('error', (err) => {
      state.status = 'error';
      state.error = err.message || String(err);
      send('update:error', { message: state.error });
    });

    console.log(`[updater] feed: ${feedUrl}`);
    checkNow();
    timer = setInterval(checkNow, CHECK_INTERVAL_MS);
    timer.unref?.();

    return { ok: true, feedUrl };
  }

  /**
   * Query de busy (D4): pregunta al renderer y espera hasta 500 ms. Sin reply
   * → busy. El renderer responde con respondBusy(requestId, busy) o adelanta
   * su estado con reportBusy(busy) (cache).
   */
  function queryBusy() {
    return new Promise((resolve) => {
      const requestId = ++lastBusyQueryId;
      const timeout = setTimeout(() => {
        busyReplies.delete(requestId);
        state.busyCache = true; // sin reply → busy (D4)
        resolve(true);
      }, BUSY_QUERY_TIMEOUT_MS);
      busyReplies.set(requestId, (busy) => {
        clearTimeout(timeout);
        busyReplies.delete(requestId);
        resolve(busy);
      });
      send('update:query-busy', { requestId });
    });
  }

  function reportBusy(busy) {
    state.busyCache = !!busy;
  }

  function respondBusy(requestId, busy) {
    state.busyCache = !!busy;
    const resolve = busyReplies.get(requestId);
    if (resolve) resolve(!!busy);
  }

  /**
   * Instalación confirmada: nunca fuerza durante una venta en curso.
   * Devuelve { ok: false, reason: 'not-downloaded' | 'busy' } o
   * { ok: true } tras quitAndInstall().
   */
  async function install() {
    if (state.status !== 'downloaded') {
      return { ok: false, reason: 'not-downloaded' };
    }
    if (state.busyCache) {
      return { ok: false, reason: 'busy' };
    }
    const busy = await queryBusy();
    if (busy) {
      return { ok: false, reason: 'busy' };
    }
    const { autoUpdater } = require('electron-updater');
    autoUpdater.quitAndInstall();
    return { ok: true };
  }

  function getState() {
    return {
      status: state.status,
      version: state.version,
      percent: state.percent,
      error: state.error,
    };
  }

  return {
    start,
    install,
    reportBusy,
    respondBusy,
    getState,
    isActive: () => isPackaged,
  };
}

module.exports = {
  resolveFeedUrl,
  initUpdater,
  CHECK_INTERVAL_MS,
  BUSY_QUERY_TIMEOUT_MS,
  FEED_PATH,
};