// Server origin only (no /api/v1). Used to join server-rooted paths like signed URLs.
export const API_ORIGIN = import.meta.env.PUBLIC_API_URL || 'http://localhost:8000';
export const API_BASE = API_ORIGIN + '/api/v1';

export async function apiFetch(method, url, body) {
  const token = localStorage.getItem('panel_token');
  const headers = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'X-Panel': 'true',
  };
  if (token) headers['Authorization'] = 'Bearer ' + token;
  const opts = { method, headers, credentials: 'omit' };
  if (body) opts.body = JSON.stringify(body);
  const res = await fetch(API_BASE + url, opts);
  if (res.status === 401) {
    localStorage.removeItem('panel_token');
    window.location.href = '/login';
    throw new Error('No autorizado');
  }
  if (!res.ok) {
    const text = await res.text();
    let parsed = null;
    try { parsed = JSON.parse(text); } catch (_) {}
    const err = new Error((parsed && parsed.message) || text || res.statusText) as Error & {
      errors?: Record<string, string[]>;
      status?: number;
    };
    if (parsed && typeof parsed === 'object') {
      err.errors = parsed.errors;
    }
    err.status = res.status;
    throw err;
  }
  if (res.status === 204) return null;
  try {
    return await res.json();
  } catch (_) {
    return await res.text();
  }
}

export function getStoredUser() {
  try {
    const raw = localStorage.getItem('panel_user');
    return raw ? JSON.parse(raw) : null;
  } catch { return null; }
}

export const ROLES = ['super_master', 'master', 'banca', 'grupo', 'agencia'];

export type QueryParams = Record<string, string | number | boolean | null | undefined>;

/**
 * Construye un query string a partir de un objeto.
 * Omite null, undefined y strings vacíos. Devuelve `?a=b&c=d` o ''.
 */
export function toQuery(params: QueryParams): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === null || value === undefined) continue;
    const text = String(value);
    if (text === '') continue;
    search.append(key, text);
  }
  const query = search.toString();
  return query ? '?' + query : '';
}

/**
 * Comprueba un permiso sobre el usuario guardado en `panel_user`.
 * super_master siempre pasa; sin `permissions` (sesión antigua) se usa el
 * fallback por rol master/super_master.
 */
export function can(permission: string): boolean {
  const user = getStoredUser();
  if (!user) return false;
  if (user.role === 'super_master') return true;
  if (!Array.isArray(user.permissions)) {
    return user.role === 'master' || user.role === 'super_master';
  }
  return user.permissions.includes(permission);
}
