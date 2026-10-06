// Rutas y utilidades de artefactos (REQ-5: capturas + reporte HTML gitignored).
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// taquilla/e2e  y  taquilla (raíz del app Electron)
export const E2E_ROOT = path.resolve(__dirname, '..');
export const TQ_ROOT = path.resolve(E2E_ROOT, '..');
export const ARTIFACTS_DIR = path.join(E2E_ROOT, 'artifacts');

export const SESSION_FILE = 'session.json';
export const TMP_DIRS_FILE = 'tmp-dirs.json';

export function ensureArtifactsDir() {
  fs.mkdirSync(ARTIFACTS_DIR, { recursive: true });
  return ARTIFACTS_DIR;
}

/** Captura paso a paso: e2e/artifacts/<name>.png */
export function stepShot(page, name, { fullPage = false } = {}) {
  ensureArtifactsDir();
  const file = path.join(ARTIFACTS_DIR, `${name}.png`);
  return page.screenshot({ path: file, fullPage });
}

export function writeJson(fileName, data) {
  ensureArtifactsDir();
  const file = path.join(ARTIFACTS_DIR, fileName);
  fs.writeFileSync(file, JSON.stringify(data, null, 2));
  return file;
}

export function readJson(fileName) {
  const file = path.join(ARTIFACTS_DIR, fileName);
  if (!fs.existsSync(file)) return null;
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch {
    return null;
  }
}