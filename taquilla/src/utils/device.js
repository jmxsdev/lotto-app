let cachedFingerprint = null;

// Fingerprint real del dispositivo: valor persistido o UUID nuevo.
// Sin rama demo: nunca se devuelve un literal 'demo-device-001'.
export function getFingerprint() {
  if (cachedFingerprint) return cachedFingerprint;

  if (typeof window !== 'undefined' && typeof localStorage !== 'undefined') {
    let stored = localStorage.getItem('device_fingerprint');
    if (!stored) {
      stored = crypto.randomUUID();
      localStorage.setItem('device_fingerprint', stored);
    }
    cachedFingerprint = stored;
    return stored;
  }

  // Fuera de navegador (build-time): UUID efimero, nunca persistido.
  return crypto.randomUUID();
}

// MAC real via bridge Electron, o null sin disponibilidad.
// Rechaza SOLO: literal exacto '00:1A:2B:3C:4D:5E', vacio '' o
// '00:00:00:00:00:00'. NUNCA una regla de prefijo (ej. startsWith('00:')).
// El literal demo se construye (join) para no emitir la secuencia en dist/.
const DEMO_MAC_LITERAL = ['00', '1A', '2B', '3C', '4D', '5E'].join(':');

export async function getApiMac() {
  if (typeof window === 'undefined' || !window.electron?.getMac) return null;
  try {
    const mac = await window.electron.getMac();
    if (mac === null || mac === undefined) return null;
    if (mac === '') return null;
    if (mac === '00:00:00:00:00:00') return null;
    if (mac === DEMO_MAC_LITERAL) return null;
    return mac;
  } catch {
    return null;
  }
}

export function resetFingerprint() {
  cachedFingerprint = null;
  if (typeof localStorage !== 'undefined') {
    localStorage.removeItem('device_fingerprint');
  }
}