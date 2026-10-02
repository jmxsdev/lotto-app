// Helpers de API local (healthcheck, login, session.json).
// El upstream SIEMPRE deriva de resolveUpstream() (env API_UPSTREAM), nunca
// hardcodeado: local puede correr en :8003; CI corre en :8000.
import { resolveUpstream } from './guard.mjs';
import { E2E_EMAIL, E2E_PASSWORD, E2E_FINGERPRINT } from './fixtures.mjs';
import { writeJson, readJson, SESSION_FILE } from './artifacts.mjs';

export { resolveUpstream };

/**
 * Healthcheck de la API local: GET /api/v1/juegos responde <500 (200 con token,
 * 401 sin token) = viva. Reintenta hasta `retries` con `delayMs` entre intentos.
 */
export async function healthcheck(
  upstream = resolveUpstream(),
  { retries = 20, delayMs = 500 } = {}
) {
  const url = `${upstream}/api/v1/juegos`;
  let lastErr;
  for (let i = 0; i < retries; i++) {
    try {
      const res = await fetch(url, { signal: AbortSignal.timeout(3000) });
      if (res.status < 500) return res.status;
      lastErr = new Error(`healthcheck HTTP ${res.status}`);
    } catch (err) {
      lastErr = err;
    }
    await new Promise((r) => setTimeout(r, delayMs));
  }
  throw new Error(
    `API no responde en ${upstream} tras ${retries} intentos: ${lastErr?.message || 'timeout'}`
  );
}

/**
 * Login API (POST /api/v1/login) con un usuario role taquilla y el fingerprint
 * del harness. Persiste la sesión en e2e/artifacts/session.json.
 */
export async function apiLogin(
  upstream = resolveUpstream(),
  { email = E2E_EMAIL, password = E2E_PASSWORD, fingerprint = E2E_FINGERPRINT } = {}
) {
  const res = await fetch(`${upstream}/api/v1/login`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Device-Fingerprint': fingerprint,
    },
    body: JSON.stringify({ email, password }),
  });
  const payload = await res.json().catch(() => null);
  if (!res.ok) {
    throw new Error(
      `Login API falló (${res.status}) para ${email}: ${JSON.stringify(payload)}`
    );
  }
  const session = {
    upstream,
    email,
    fingerprint,
    token: payload.token,
    user: payload.user,
    at: new Date().toISOString(),
  };
  writeJson(SESSION_FILE, session);
  return session;
}

export function loadSession() {
  return readJson(SESSION_FILE);
}