const API_BASE = import.meta.env.PUBLIC_API_URL || 'http://localhost:8000/api';

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
    let msg = text;
    try { msg = JSON.parse(text).message || msg; } catch (_) {}
    throw new Error(msg || res.statusText);
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

export const ROLES = ['super_master', 'master', 'banca', 'grupo'];
