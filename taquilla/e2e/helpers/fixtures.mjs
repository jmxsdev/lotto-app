// Constantes y fixtures del harness E2E (design §Contracts).
//
// NOTA (desviación documentada): el design propuso E2E_MAC=`02:E2E:00:00:00:01`,
// pero VerifyMac.php valida con /^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/ y ese
// literal tiene 3 hex seguidos (`E2E`), por lo que el backend lo rechaza con 403
// "Formato de MAC inválido". Se usa el MAC E2E válido `02:E2:E0:00:00:01`.

export const E2E_MAC = process.env.E2E_MAC || '02:E2:E0:00:00:01';
export const E2E_FINGERPRINT = process.env.E2E_FINGERPRINT || 'e2e-device-0001';
export const E2E_EMAIL = process.env.E2E_EMAIL || 'demo@lotto.com';
export const E2E_PASSWORD = process.env.E2E_PASSWORD || 'password';
export const E2E_CIERRE_CLAVE = process.env.E2E_CIERRE_CLAVE || '123456';

// Canales IPC que se re-`handle`an vía electronApp.evaluate (stub evaluate-only).
// get-mac devuelve el MAC E2E (header X-Device-MAC); los print-* capturan payload.
export const STUB_CHANNELS = [
  'get-mac',
  'print-ticket',
  'print-cierre',
  'print-reporte',
];

// Selectores estables del renderer (design §Contracts).
export const SELECTORS = {
  splash: { status: '#splash-status', retry: '#splash-retry' },
  login: { email: '#email', password: '#password', submit: '#btn-submit' },
  dashboard: {
    numero: '#qt-numero',
    monto: '#qt-monto',
    add: '#qt-add',
    print: '#qt-print',
  },
};

/**
 * Instante absoluto de "hoy 06:00 en America/Caracas" para page.clock.install.
 * El reloj queda congelado en esa hora: los horarios del catálogo (08:00–19:00)
 * siempre son futuros a cualquier hora del día en CI (design: AD #7).
 */
export function caracasSixAmToday() {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'America/Caracas',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
  }).formatToParts(new Date());
  const map = Object.fromEntries(parts.map((p) => [p.type, p.value]));
  return new Date(Date.UTC(+map.year, +map.month - 1, +map.day, 6, 0, 0, 0));
}