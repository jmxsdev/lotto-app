// Constantes y fixtures del harness E2E (design §Contracts).
//
// NOTA (desviación documentada): el design propuso E2E_MAC=`02:E2E:00:00:00:01`,
// pero VerifyMac.php valida con /^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/ y ese
// literal tiene 3 hex seguidos (`E2E`), por lo que el backend lo rechaza con 403
// "Formato de MAC inválido". Se usa el MAC E2E válido `02:E2:E0:00:00:01`.

export const E2E_MAC = process.env.E2E_MAC || '02:E2:E0:00:00:01';
export const E2E_FINGERPRINT = process.env.E2E_FINGERPRINT || 'e2e-device-0001';
// Identidad E2E canónica (E2eSeeder): e2e@lotto.com sobre la taquilla E2E01.
// S1 usaba demo@lotto.com como provisional hasta que el seeder existiera.
export const E2E_EMAIL = process.env.E2E_EMAIL || 'e2e@lotto.com';
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
 * Instante de "06:00 en America/Caracas" con offset de días para
 * page.clock.install.
 *
 * DESVIACIÓN documentada (design AD #7): el design fijaba "hoy 06:00", pero el
 * BACKEND valida `sorteo_hora` contra el reloj REAL del servidor (D3,
 * ApuestaService::createApuesta: "El sorteo seleccionado ya pasó"), no contra
 * el reloj mockeado del renderer. Con el reloj en HOY, vender después de las
 * 08:00 reales (o de cualquier horario del catálogo) falla. El reloj se fija
 * en MAÑANA 06:00 (default dayOffset=1): todo horario del catálogo cae en el
 * futuro tanto para el renderer como para el backend, a cualquier hora real.
 */
export function caracasSixAmToday(dayOffset = 1) {
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
  const d = new Date(Date.UTC(+map.year, +map.month - 1, +map.day, 6, 0, 0, 0));
  d.setUTCDate(d.getUTCDate() + dayOffset);
  return d;
}

/**
 * Fecha "hoy + days" en America/Caracas (YYYY-MM-DD), independiente del reloj
 * mockeado: el fixture del seeder (resultado/ganadores) vive en la fecha REAL.
 * days=-1 → ayer, la fecha del resultado E2E y del ticket E2E-WIN-0001.
 */
export function caracasDateOffset(days) {
  const base = caracasSixAmToday(0);
  base.setUTCDate(base.getUTCDate() + days);
  return base.toISOString().slice(0, 10);
}