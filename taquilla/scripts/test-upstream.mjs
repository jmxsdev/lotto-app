// Test unitario del resolver de upstream (G1). Sin runner: script Node puro.
// Casos: dev+env→local, dev sin env→prod, empaquetado ignora env/override→prod,
// key invalida→{ok:false}, dev+override→prod. Exit 0 si todos pasan.

import { createUpstreamResolver, parseEnvFile, WHITELIST, DEFAULT_UPSTREAM } from '../electron/main/upstream.cjs';

const PROD = DEFAULT_UPSTREAM;
const LOCAL = WHITELIST.local;

let failures = 0;

function assert(name, actual, expected) {
  const a = JSON.stringify(actual);
  const e = JSON.stringify(expected);
  if (a === e) {
    console.log(`✅ ${name}`);
  } else {
    failures++;
    console.error(`❌ ${name}\n   esperado: ${e}\n   obtenido: ${a}`);
  }
}

// 1. Dev + env → local
{
  const r = createUpstreamResolver({ isPackaged: false, env: { API_UPSTREAM: LOCAL } });
  assert('dev+env → local', r.get(), LOCAL);
}

// 2. Dev sin env → prod
{
  const r = createUpstreamResolver({ isPackaged: false, env: {} });
  assert('dev sin env → prod', r.get(), PROD);
}

// 3. Empaquetado ignora env y override → prod
{
  const r = createUpstreamResolver({ isPackaged: true, env: { API_UPSTREAM: LOCAL } });
  assert('empaquetado con env → prod', r.get(), PROD);
  const set = r.setOverride('local');
  assert('empaquetado rechaza override', set, { ok: false, reason: 'packaged' });
  assert('empaquetado sigue prod tras override', r.get(), PROD);
}

// 4. Key invalida → {ok:false}
{
  const r = createUpstreamResolver({ isPackaged: false, env: {} });
  const set = r.setOverride('http://evil.example');
  assert('key invalida → {ok:false}', set, { ok: false, reason: 'invalid-key' });
  assert('key invalida no muta el estado', r.get(), PROD);
}

// 5. Dev + override → prod (override gana a .env.development)
{
  const r = createUpstreamResolver({ isPackaged: false, env: { API_UPSTREAM: LOCAL } });
  const set = r.setOverride('prod');
  assert('dev+override prod → {ok:true, upstream:prod}', set, { ok: true, upstream: PROD });
  assert('dev+override → get()=prod', r.get(), PROD);
}

// Extra: parser de .env (usado por electron-dev.mjs)
{
  const env = parseEnvFile('# comentario\nAPI_UPSTREAM=http://localhost:8000\n\nEMPTY=\nQUOTED="valor"\n');
  assert('parseEnvFile extrae API_UPSTREAM', env.API_UPSTREAM, 'http://localhost:8000');
  assert('parseEnvFile ignora comentarios/vacias', env.EMPTY, '');
  assert('parseEnvFile quita comillas', env.QUOTED, 'valor');
}

if (failures > 0) {
  console.error(`\n${failures} caso(s) fallido(s)`);
  process.exit(1);
}
console.log('\nTodos los casos de upstream pasan.');