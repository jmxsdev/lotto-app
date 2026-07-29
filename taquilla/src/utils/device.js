let cachedFingerprint = null;

export function getFingerprint() {
  if (cachedFingerprint) return cachedFingerprint;

  if (typeof window !== 'undefined' && window.electron?.getVersion) {
    const stored = localStorage.getItem('device_fingerprint');
    if (stored) { cachedFingerprint = stored; return stored; }
    const id = crypto.randomUUID();
    localStorage.setItem('device_fingerprint', id);
    cachedFingerprint = id;
    return id;
  }

  if (typeof window !== 'undefined') {
    const stored = localStorage.getItem('device_fingerprint');
    if (stored) { cachedFingerprint = stored; return stored; }
    const id = 'demo-device-001';
    localStorage.setItem('device_fingerprint', id);
    cachedFingerprint = id;
    return id;
  }

  return 'demo-device-001';
}

export function resetFingerprint() {
  cachedFingerprint = null;
  localStorage.removeItem('device_fingerprint');
}
