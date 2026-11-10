#!/usr/bin/env node
/**
 * Compile the native ESC/POS printing module from TypeScript to CommonJS.
 *
 * The taquilla package is `"type": "module"`, so the emitted `.js` files would
 * otherwise be treated as ESM. A `dist/package.json` with `{"type":"commonjs"}`
 * keeps the output CommonJS and lets Node resolve extensionless relative
 * requires inside `dist/`.
 *
 * Usage (from taquilla/):
 *   node scripts/build-printing.mjs
 */
import { mkdirSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

const printingRoot = fileURLToPath(new URL('../electron/main/printing/', import.meta.url));
const distDir = join(printingRoot, 'dist');

/** Recursively collect every `.ts` source under `dir`, skipping `dist/` and `.d.ts`. */
function collectSources(dir) {
  const found = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (entry.isDirectory()) {
      if (entry.name === 'dist') continue;
      found.push(...collectSources(join(dir, entry.name)));
      continue;
    }
    if (!entry.isFile()) continue;
    if (!entry.name.endsWith('.ts')) continue;
    if (entry.name.endsWith('.d.ts')) continue;
    found.push(join(dir, entry.name));
  }
  return found.sort();
}

rmSync(distDir, { recursive: true, force: true });
mkdirSync(distDir, { recursive: true });

const entryPoints = collectSources(printingRoot);

if (entryPoints.length === 0) {
  console.warn(`[build:printing] no .ts sources under ${printingRoot} yet; nothing to build`);
} else {
  await build({
    entryPoints,
    outdir: distDir,
    outbase: printingRoot,
    format: 'cjs',
    platform: 'node',
    target: 'node22',
    bundle: false,
    sourcemap: false,
    logLevel: 'info',
  });

  writeFileSync(join(distDir, 'package.json'), `${JSON.stringify({ type: 'commonjs' }, null, 2)}\n`);

  console.log(
    `[build:printing] ${entryPoints.length} source file(s) -> ${relative(process.cwd(), distDir) || distDir}`,
  );
}
