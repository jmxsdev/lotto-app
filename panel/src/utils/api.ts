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

/**
 * Descarga un recurso protegido (p. ej. CSV de auditoría) como blob y fuerza
 * la descarga en el navegador. Adjunta el token del panel si existe.
 */
export async function apiDownload(url: string, filename?: string): Promise<void> {
  const token = localStorage.getItem('panel_token');
  const headers: Record<string, string> = {
    'Accept': 'text/csv',
    'X-Panel': 'true',
  };
  if (token) headers['Authorization'] = 'Bearer ' + token;
  const res = await fetch(API_BASE + url, { method: 'GET', headers, credentials: 'omit' });
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
      status?: number;
    };
    err.status = res.status;
    throw err;
  }
  const blob = await res.blob();
  const name = resolveFilename(res.headers.get('Content-Disposition'), filename);
  const objectUrl = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = objectUrl;
  anchor.download = name;
  anchor.style.display = 'none';
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
}

/** Extrae el nombre de archivo de `Content-Disposition` o usa el fallback. */
function resolveFilename(disposition: string | null, fallback?: string): string {
  if (disposition) {
    const extended = /filename\*=UTF-8''([^;]+)/i.exec(disposition);
    if (extended && extended[1]) {
      try { return decodeURIComponent(extended[1]); } catch (_) { /* usa el simple */ }
    }
    const simple = /filename="?([^";]+)"?/i.exec(disposition);
    if (simple && simple[1]) return simple[1];
  }
  return fallback || 'audit-events.csv';
}

export function getStoredUser() {
  try {
    const raw = localStorage.getItem('panel_user');
    return raw ? JSON.parse(raw) : null;
  } catch { return null; }
}

export const ROLES = ['super_master', 'master', 'banca', 'grupo', 'agencia'];

export type QueryParams = Record<string, string | number | boolean | null | undefined>;

/** Motivo capturado por el modal y enviado en acciones auditadas. */
export type ReasonPayload = { reason_code: string; reason_text: string };

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
 * Añade el motivo como query string (usado en DELETE).
 * Omite `reason_code`/`reason_text` vacíos y respeta si la URL ya trae `?`.
 */
export function withReasonQuery(url: string, reason: ReasonPayload | null | undefined): string {
  if (!reason) return url;
  const query = toQuery({
    reason_code: reason.reason_code,
    reason_text: reason.reason_text,
  });
  if (!query) return url;
  return url.includes('?') ? url + '&' + query.slice(1) : url + query;
}

/**
 * Fusiona el motivo en el body de la petición (usado en PATCH/PUT/POST).
 * Solo añade las claves no vacías del motivo.
 */
export function mergeReason<T extends Record<string, unknown>>(
  body: T,
  reason: ReasonPayload | null | undefined,
): T & Partial<ReasonPayload> {
  const merged = { ...body } as T & Partial<ReasonPayload>;
  if (reason?.reason_code) merged.reason_code = reason.reason_code;
  if (reason?.reason_text) merged.reason_text = reason.reason_text;
  return merged;
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
