// global-setup (task 1.7): guard → healthcheck → build dist → login API.
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { assertLocalApi, resolveUpstream } from './helpers/guard.mjs';
import { healthcheck, apiLogin } from './helpers/api.mjs';
import { TQ_ROOT } from './helpers/app.mjs';

export default async function globalSetup() {
  // 1. Guard: aborta antes de lanzar nada si el upstream no es local (REQ-2).
  const upstream = resolveUpstream();
  assertLocalApi(upstream);
  console.log(`[e2e] guard OK — upstream local: ${upstream}`);

  // 2. Healthcheck derivado del upstream (no hardcodeado; CI usa :8000).
  const status = await healthcheck(upstream);
  console.log(`[e2e] healthcheck OK — GET /api/v1/juegos → ${status}`);

  // 3. Build de dist si falta (default build mode, REQ-7). Flags:
  //    E2E_SKIP_BUILD=1 → no construir; E2E_DEV_SERVER=1 → dev server externo.
  const distIndex = path.join(TQ_ROOT, 'dist', 'index.html');
  const devServer = process.env.E2E_DEV_SERVER === '1';
  const skipBuild = process.env.E2E_SKIP_BUILD === '1';
  if (!devServer && !skipBuild && !fs.existsSync(distIndex)) {
    console.log('[e2e] dist ausente → pnpm build…');
    execSync('pnpm build', { cwd: TQ_ROOT, stdio: 'inherit' });
  } else {
    console.log('[e2e] dist presente (o flag skip/dev) — sin build');
  }

  // 4. Login API → session.json para fixtures/helpers (S2 usa el token).
  const session = await apiLogin(upstream);
  console.log(
    `[e2e] sesión API lista (${session.email}) → e2e/artifacts/${'session.json'}`
  );

  return { upstream, session };
}