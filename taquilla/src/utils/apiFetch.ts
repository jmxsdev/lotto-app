import { API_BASE } from '../config/api';
import { getFingerprint, getApiMac } from './device';

export type ApiErrorKind =
  | 'network'
  | 'timeout'
  | 'unauthorized'
  | 'forbidden'
  | 'rate-limited'
  | 'http';

export class ApiError extends Error {
  readonly kind: ApiErrorKind;
  readonly status?: number;
  readonly payload?: unknown;

  constructor(kind: ApiErrorKind, message: string, status?: number, payload?: unknown) {
    super(message);
    this.name = 'ApiError';
    this.kind = kind;
    this.status = status;
    this.payload = payload;
  }
}

export interface ApiFetchOptions {
  method?: string;
  body?: unknown;
  timeout?: number;
  headers?: Record<string, string>;
}

// Headers de dispositivo disponibles de forma sincrona (fingerprint real).
function deviceHeaders(): Record<string, string> {
  const headers: Record<string, string> = {};
  const fp = getFingerprint();
  if (fp) headers['X-Device-Fingerprint'] = fp;
  return headers;
}

async function parsePayload(res: Response): Promise<unknown> {
  try {
    return await res.json();
  } catch {
    try {
      return await res.text();
    } catch {
      return null;
    }
  }
}

function extractMessage(payload: unknown, fallback: string): string {
  if (payload && typeof payload === 'object' && 'message' in payload) {
    const msg = (payload as { message?: unknown }).message;
    if (typeof msg === 'string' && msg) return msg;
  }
  return fallback;
}

/**
 * Wrapper unico de fetch sobre api:///api/v1 (API_BASE).
 * - Timeout por defecto 10s via AbortSignal.timeout.
 * - Inyecta Authorization (auth_token) y headers de dispositivo si existen.
 * - Clasifica errores en ApiError{kind}: network|timeout|unauthorized|forbidden|rate-limited|http.
 * - 401: limpia sesion y redirige a /login (salvo estar ya en login).
 * - Sin redirects de ventana por error (solo el bounce de sesion expirada).
 */
export async function apiFetch<T = unknown>(
  path: string,
  options: ApiFetchOptions = {}
): Promise<T> {
  const { method = 'GET', body, timeout = 10000, headers = {} } = options;

  const finalHeaders: Record<string, string> = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    ...headers,
  };

  if (typeof localStorage !== 'undefined') {
    const token = localStorage.getItem('auth_token');
    if (token) finalHeaders['Authorization'] = 'Bearer ' + token;
  }

  Object.assign(finalHeaders, deviceHeaders());
  const mac = await getApiMac();
  if (mac) finalHeaders['X-Device-MAC'] = mac;

  const opts: RequestInit = {
    method,
    headers: finalHeaders,
    credentials: 'omit',
    signal: AbortSignal.timeout(timeout),
  };
  if (body !== undefined) opts.body = JSON.stringify(body);

  let res: Response;
  try {
    res = await fetch(API_BASE + path, opts);
  } catch (err) {
    if (err instanceof DOMException && (err.name === 'TimeoutError' || err.name === 'AbortError')) {
      throw new ApiError('timeout', 'La solicitud tardó demasiado. Intente nuevamente.');
    }
    throw new ApiError('network', 'Error de conexión con el servidor.');
  }

  if (res.status === 401) {
    if (typeof localStorage !== 'undefined') localStorage.removeItem('auth_token');
    if (typeof window !== 'undefined' && window.location.pathname !== '/login') {
      window.location.href = '/login';
    }
    throw new ApiError('unauthorized', 'No autorizado', 401);
  }

  if (res.status === 403) {
    const payload = await parsePayload(res);
    throw new ApiError('forbidden', extractMessage(payload, 'Acceso denegado.'), 403, payload);
  }

  if (res.status === 429) {
    const payload = await parsePayload(res);
    throw new ApiError(
      'rate-limited',
      extractMessage(payload, 'Demasiados intentos. Espere un momento e intente nuevamente.'),
      429,
      payload
    );
  }

  if (!res.ok) {
    const payload = await parsePayload(res);
    throw new ApiError(
      'http',
      extractMessage(payload, 'Error ' + res.status),
      res.status,
      payload
    );
  }

  if (res.status === 204) return undefined as T;
  return (await res.json()) as T;
}