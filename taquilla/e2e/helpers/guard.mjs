// Guard de API local para el harness E2E (spec REQ-2).
//
// El harness SOLO puede ejecutarse contra una API local:
//   http://localhost:PUERTO  |  http://127.0.0.1:PUERTO
// Cualquier otro upstream (https, dominio remoto, IP LAN) aborta ANTES de
// lanzar Electron: un run jamás puede tocar producción.

const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1']);

/** Upstream efectivo: env API_UPSTREAM o el default local de desarrollo. */
export function resolveUpstream() {
  return process.env.API_UPSTREAM || 'http://localhost:8000';
}

/**
 * Valida que `upstream` sea una API local. Lanza Error si no lo es.
 * Devuelve el URL parseado (con hostname/port) para el healthcheck/login.
 */
export function assertLocalApi(upstream) {
  if (typeof upstream !== 'string' || upstream.trim() === '') {
    throw new Error(
      'API_UPSTREAM es obligatorio y debe ser una URL local ' +
        '(http://localhost:PUERTO o http://127.0.0.1:PUERTO).'
    );
  }

  let url;
  try {
    url = new URL(upstream);
  } catch {
    throw new Error(
      `API_UPSTREAM inválida: "${upstream}". Debe ser una URL local ` +
        '(http://localhost:PUERTO o http://127.0.0.1:PUERTO).'
    );
  }

  if (url.protocol !== 'http:' || !LOCAL_HOSTS.has(url.hostname)) {
    throw new Error(
      `API_UPSTREAM apunta fuera del entorno local: "${upstream}". ` +
        'El harness E2E SOLO corre contra http://localhost:PUERTO o ' +
        'http://127.0.0.1:PUERTO; jamás contra producción.'
    );
  }

  return url;
}