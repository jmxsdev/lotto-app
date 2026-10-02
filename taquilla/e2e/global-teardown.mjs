// global-teardown (task 1.7): limpieza best-effort de user-data dirs temporales.
import fs from 'node:fs';
import path from 'node:path';
import { readJson, writeJson, TMP_DIRS_FILE } from './helpers/artifacts.mjs';

export default async function globalTeardown() {
  const dirs = readJson(TMP_DIRS_FILE) || [];
  let removed = 0;
  for (const dir of dirs) {
    try {
      fs.rmSync(dir, { recursive: true, force: true });
      removed += 1;
    } catch {
      /* best-effort: algún dir ya no existe */
    }
  }
  try {
    writeJson(TMP_DIRS_FILE, []);
  } catch {
    /* artifacts pueden no existir */
  }
  if (dirs.length > 0) {
    console.log(`[e2e] teardown: ${removed}/${dirs.length} user-data dirs eliminados`);
  }
}