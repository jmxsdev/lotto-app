// Upstream resolver for the API proxy — modulo puro (sin imports de Electron).
// Precedencia en desarrollo: override IPC > env (shell > archivo) > prod.
// Empaquetado (app.isPackaged): SIEMPRE prod, env y override ignorados.

const DEFAULT_UPSTREAM = 'https://lotto.gzuz.dev';

// Whitelist fija de entornos conmutables desde el selector dev-only.
// Nunca se acepta una URL arbitraria del renderer.
const WHITELIST = {
  local: 'http://localhost:8000',
  prod: 'https://lotto.gzuz.dev',
};

// Parser minimalista de archivos .env (CLAVE=valor, ignora comentarios y vacias).
function parseEnvFile(content) {
  const env = {};
  if (!content) return env;
  for (const rawLine of String(content).split(/\r?\n/)) {
    const line = rawLine.trim();
    if (!line || line.startsWith('#')) continue;
    const eq = line.indexOf('=');
    if (eq === -1) continue;
    const key = line.slice(0, eq).trim();
    let value = line.slice(eq + 1).trim();
    // Quitar comillas simples/duplas envolventes
    if (
      value.length >= 2 &&
      ((value.startsWith('"') && value.endsWith('"')) ||
        (value.startsWith("'") && value.endsWith("'")))
    ) {
      value = value.slice(1, -1);
    }
    env[key] = value;
  }
  return env;
}

function createUpstreamResolver({ isPackaged = false, env = {} }) {
  const state = { override: null };

  return {
    // Upstream vigente para una peticion. Empaquetado: prod incondicional.
    get() {
      if (isPackaged) return DEFAULT_UPSTREAM;
      if (state.override) return state.override;
      if (env.API_UPSTREAM) return env.API_UPSTREAM;
      return DEFAULT_UPSTREAM;
    },

    // Selector dev-only: solo claves de la whitelist y solo en desarrollo.
    setOverride(key) {
      if (isPackaged) {
        return { ok: false, reason: 'packaged' };
      }
      const target = WHITELIST[key];
      if (!target) {
        return { ok: false, reason: 'invalid-key' };
      }
      state.override = target;
      return { ok: true, upstream: target };
    },
  };
}

module.exports = { parseEnvFile, createUpstreamResolver, WHITELIST, DEFAULT_UPSTREAM };