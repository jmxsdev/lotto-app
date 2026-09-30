#!/usr/bin/env node
/**
 * Generador del subset de Noto Color Emoji (decisión de arquitectura 3, A1 CBDT).
 *
 * Ejecutar desde la raíz del repo:
 *   NOTO_EMOJI_SRC=/ruta/NotoColorEmoji.ttf node taquilla/scripts/build-emoji-font.mjs
 *
 * Toolchain (venv aislado, NUNCA commiteado) — ver taquilla/scripts/font-requirements.txt:
 *   python3 -m venv /tmp/opencode/font-spike-venv
 *   /tmp/opencode/font-spike-venv/bin/pip install -r taquilla/scripts/font-requirements.txt
 *   PATH=/tmp/opencode/font-spike-venv/bin:$PATH NOTO_EMOJI_SRC=... node taquilla/scripts/build-emoji-font.mjs
 *
 * Entrada:
 *   - taquilla/src/data/juegos.json  → los `icono` del catálogo (fuente de verdad).
 *   - Fuente completa NotoColorEmoji.ttf (CBDT, OFL-1.1) desde
 *     https://github.com/googlefonts/noto-emoji (2D/fonts/NotoColorEmoji.ttf).
 *     Se pasa por NOTO_EMOJI_SRC o --src; no se descarga aquí (sin red en build).
 *
 * Salida (COMMITEADA; el build de la app NO ejecuta este script):
 *   - taquilla/src/assets/fonts/noto-emoji-subset.ttf   (TTF, CBDT/CBLC intactos)
 *   - taquilla/src/assets/fonts/emoji-subset.manifest.json (codepoints, origen, licencia)
 *
 * Seguridad (matriz de amenazas del design): pyftsubset se invoca con spawnSync
 * y argv FIJO (sin shell, sin interpolación); el unicodes.txt temporal vive en
 * el tmpdir del SO. exit ≠ 0 → el script falla y NO escribe el manifest.
 *
 * Licencia: Noto Color Emoji es OFL-1.1; el texto completo se commitea junto al
 * subset (OFL-NotoColorEmoji.txt).
 */
import { readFileSync, writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const AQUI = dirname(fileURLToPath(import.meta.url));
const RAIZ = join(AQUI, '..', '..');

const FUENTE = process.env.NOTO_EMOJI_SRC ?? process.argv.find((a) => a.startsWith('--src='))?.slice(6);
const PYFTSUBSET = process.env.PYFTSUBSET ?? 'pyftsubset';

const JSON_CATALOGO = join(RAIZ, 'taquilla', 'src', 'data', 'juegos.json');
const DIR_FUENTES = join(RAIZ, 'taquilla', 'src', 'assets', 'fonts');
const SALIDA_TTF = join(DIR_FUENTES, 'noto-emoji-subset.ttf');
const SALIDA_MANIFEST = join(DIR_FUENTES, 'emoji-subset.manifest.json');

if (!FUENTE) {
  console.error('Falta la fuente completa: NOTO_EMOJI_SRC=/ruta/NotoColorEmoji.ttf (o --src=...)');
  process.exit(2);
}

// --- 1. Codepoints desde el catálogo bundled ---------------------------------
const catalogo = JSON.parse(readFileSync(JSON_CATALOGO, 'utf8'));
const codepoints = new Set();
for (const j of catalogo.juegos) {
  for (const o of j.opciones) {
    if (typeof o.icono === 'string' && o.icono) {
      for (const ch of o.icono) codepoints.add(ch.codePointAt(0));
    }
  }
}
// VS16 (U+FE0F) y ZWJ (U+200D) por diseño: el manifest los declara aunque el
// catálogo actual no use ZWJ; FE0F es obligatorio (🕊️ 🐿️ 🕷️ del catálogo).
codepoints.add(0xfe0f);
codepoints.add(0x200d);
const ordenados = [...codepoints].sort((a, b) => a - b);

// --- 2. unicodes.txt temporal (tmpdir del SO, nunca se commitea) -------------
const tmp = join(tmpdir(), `noto-emoji-unicodes-${process.pid}.txt`);
const unicodes = ordenados.map((cp) => `U+${cp.toString(16).toUpperCase().padStart(4, '0')}`).join('\n') + '\n';
writeFileSync(tmp, unicodes);
mkdirSync(DIR_FUENTES, { recursive: true });

// --- 3. pyftsubset con argv FIJO (sin shell) ---------------------------------
// Flags del design §Pipeline: conserva CBDT/CBLC, cmap format 14 (VS16),
// nombres, tablas name completas; sin recalc de timestamp (determinista).
const argv = [
  FUENTE,
  `--output-file=${SALIDA_TTF}`,
  `--unicodes-file=${tmp}`,
  '--layout-features=*',
  '--glyph-names',
  '--symbol-cmap',
  '--legacy-cmap',
  '--notdef-glyph',
  '--notdef-outline',
  '--recommended-glyphs',
  '--name-IDs=*',
  '--name-legacy',
  '--name-languages=*',
  '--no-recalc-timestamp',
];

console.log(`pyftsubset: ${ordenados.length} codepoints → ${SALIDA_TTF.replace(RAIZ + '/', '')}`);
const res = spawnSync(PYFTSUBSET, argv, { stdio: 'inherit', encoding: 'utf8' });
rmSync(tmp, { force: true });
if (res.error) {
  console.error(`No se pudo ejecutar ${PYFTSUBSET}: ${res.error.message}`);
  process.exit(2);
}
if (res.status !== 0) {
  console.error(`pyftsubset falló (exit ${res.status}); NO se escribe el manifest.`);
  process.exit(res.status ?? 1);
}

// --- 4. Manifest -----------------------------------------------------------------
const bytes = readFileSync(SALIDA_TTF);
const manifest = {
  font: 'Noto Color Emoji',
  format: 'CBDT (bitmap TTF; CBLC/CBDT y cmap format 14 conservados)',
  source: 'https://github.com/googlefonts/noto-emoji/blob/main/2D/fonts/NotoColorEmoji.ttf',
  sourceCommit: '1ffdd21391dd (2026-09-17)',
  license: 'OFL-1.1',
  licenseFile: 'OFL-NotoColorEmoji.txt',
  generatedBy: 'taquilla/scripts/build-emoji-font.mjs',
  codepoints: ordenados.map((cp) => cp.toString(16).toUpperCase().padStart(4, '0')),
  sizeBytes: bytes.length,
};
writeFileSync(SALIDA_MANIFEST, JSON.stringify(manifest, null, 2) + '\n');
console.log(`✓ ${SALIDA_TTF.replace(RAIZ + '/', '')} (${Math.round(bytes.length / 1024)} KB, ${ordenados.length} codepoints)`);
console.log(`✓ ${SALIDA_MANIFEST.replace(RAIZ + '/', '')} (${manifest.codepoints.length} codepoints declarados)`);