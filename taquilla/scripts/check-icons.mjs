#!/usr/bin/env node
/**
 * Harness front [Lin] de iconografía offline — slice 2 (iconografia-front R1).
 *
 * Ejecutar desde la raíz del repo:
 *   node taquilla/scripts/check-icons.mjs
 *
 * Requiere Node 24+ (type-stripping nativo para importar .ts).
 *
 * Dos grupos de asserts:
 *   - Grupo A (infraestructura, cierra en slice 2): el mapa SVG generado
 *     existe en ambas apps, es byte-idéntico, cubre la lista canónica del
 *     generador, toda referencia `<Icon name>` / `icono('...')` existe en el
 *     mapa, y `icono()` cumple su contrato ('' para nombre desconocido).
 *   - Grupo B (aceptación de migración, cierra en slices 3a/3b/4.6): 0 emoji
 *     Unicode como iconos de UI, 0 referencias a Google Fonts, y sin el array
 *     hardcodeado ANIMAL_EMOJIS. El escaneo de emoji excluye los valores
 *     `icono` del catálogo bundled (juegos.json) y descarta comentarios.
 */
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { CANONICO } from './generate-iconos.mjs';
import { icono as iconoTaquilla } from '../src/utils/iconos.ts';
import { icono as iconoPanel } from '../../panel/src/utils/iconos.ts';

const AQUI = dirname(fileURLToPath(import.meta.url));
const RAIZ = join(AQUI, '..', '..');

const RUTAS_MAPA = {
  taquilla: join(RAIZ, 'taquilla', 'src', 'data', 'iconos.js'),
  panel: join(RAIZ, 'panel', 'src', 'data', 'iconos.js'),
};

const RUTA_FUENTE = join(RAIZ, 'taquilla', 'src', 'assets', 'fonts', 'noto-emoji-subset.ttf');
const RUTA_MANIFEST = join(RAIZ, 'taquilla', 'src', 'assets', 'fonts', 'emoji-subset.manifest.json');

let checks = 0;
let fallos = 0;

function ok(condicion, mensaje) {
  checks += 1;
  if (condicion) {
    console.log(`  ✓ ${mensaje}`);
  } else {
    fallos += 1;
    console.error(`  ✗ ${mensaje}`);
  }
}

async function cargarMapa(ruta) {
  try {
    const mod = await import(`${ruta}?t=${Date.now()}`);
    return mod.ICONOS ?? null;
  } catch {
    return null;
  }
}

function archivosBajo(dir, ext) {
  const salida = [];
  for (const entrada of readdirSync(dir)) {
    const p = join(dir, entrada);
    if (statSync(p).isDirectory()) archivosBajo(p, ext).forEach((f) => salida.push(f));
    else if (ext.some((e) => p.endsWith(e))) salida.push(p);
  }
  return salida;
}

// --- Escaneo de emoji UI (Grupo B) -------------------------------------------

const SEGMENTADOR = new Intl.Segmenter(undefined, { granularity: 'grapheme' });
const RE_EMOJI = /[\p{Emoji_Presentation}\p{Extended_Pictographic}]/u;
const FLECHAS = /[←→↑↓↔⇒◀▶]/u;

/** Quita comentarios para no contar emoji documentales (no son iconos UI). */
function sinComentarios(txt) {
  return txt
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '');
}

function emojiUIEn(txt, permitidos) {
  const encontrados = new Map(); // cluster → conteo
  for (const g of SEGMENTADOR.segment(sinComentarios(txt))) {
    const c = g.segment;
    if ((RE_EMOJI.test(c) || FLECHAS.test(c)) && !permitidos.has(c)) {
      encontrados.set(c, (encontrados.get(c) ?? 0) + 1);
    }
  }
  return encontrados;
}

// --- Referencias a iconos en plantillas (Grupo A) ----------------------------

const RE_ICON_COMPONENTE = /<Icon\s+name="([a-z0-9-]+)"/g;
const RE_ICONO_HELPER = /icono\(\s*['"]([a-z0-9-]+)['"]\s*\)/g;

function nombresReferenciados(ruta) {
  const txt = readFileSync(ruta, 'utf8');
  const nombres = new Set();
  for (const m of txt.matchAll(RE_ICON_COMPONENTE)) nombres.add(m[1]);
  for (const m of txt.matchAll(RE_ICONO_HELPER)) nombres.add(m[1]);
  return nombres;
}

// ============================================================================

console.log('== Grupo A: infraestructura del mapa SVG (slice 2) ==');

const mapaTaquilla = await cargarMapa(RUTAS_MAPA.taquilla);
ok(mapaTaquilla !== null, 'taquilla/src/data/iconos.js existe y exporta ICONOS');
ok(mapaTaquilla !== null && Object.keys(mapaTaquilla).length > 0, `mapa taquilla no vacío (${mapaTaquilla ? Object.keys(mapaTaquilla).length : 0} iconos)`);

const mapaPanel = await cargarMapa(RUTAS_MAPA.panel);
ok(mapaPanel !== null, 'panel/src/data/iconos.js existe y exporta ICONOS');

const bytesTaquilla = mapaTaquilla === null ? null : readFileSync(RUTAS_MAPA.taquilla, 'utf8');
const bytesPanel = mapaPanel === null ? null : readFileSync(RUTAS_MAPA.panel, 'utf8');
ok(bytesTaquilla !== null && bytesPanel !== null && bytesTaquilla === bytesPanel, 'mapa byte-idéntico entre taquilla y panel (decisión 4: duplicado por app)');

const faltantesCanonicos =
  mapaTaquilla === null ? CANONICO : CANONICO.filter((n) => !(n in mapaTaquilla));
ok(
  faltantesCanonicos.length === 0,
  `cobertura canónica: ${CANONICO.length - faltantesCanonicos.length}/${CANONICO.length} nombres del generador en el mapa` +
    (faltantesCanonicos.length ? ` (faltan: ${faltantesCanonicos.join(', ')})` : ''),
);

const referencias = new Map(); // nombre → Set(apps)
for (const app of Object.keys(RUTAS_MAPA)) {
  const dir = join(RAIZ, app, 'src');
  const archivos = archivosBajo(dir, ['.astro', '.ts', '.js', '.mjs', '.tsx', '.jsx']);
  for (const f of archivos) {
    if (f.endsWith('data/iconos.js')) continue;
    for (const nombre of nombresReferenciados(f)) {
      if (!referencias.has(nombre)) referencias.set(nombre, new Set());
      referencias.get(nombre).add(app);
    }
  }
}
// Toda referencia debe existir en el mapa de su propia app.
const refsFaltantes = [];
for (const [nombre, apps] of referencias) {
  for (const app of apps) {
    const mapa = app === 'taquilla' ? mapaTaquilla : mapaPanel;
    if (mapa !== null && !(nombre in mapa)) refsFaltantes.push(`${app}:${nombre}`);
  }
}
ok(
  refsFaltantes.length === 0,
  `referencias en plantillas: ${referencias.size} nombres distintos usados, todos en el mapa` +
    (refsFaltantes.length ? ` (faltan: ${refsFaltantes.join(', ')})` : ''),
);

const svgHome = iconoTaquilla('home');
ok(typeof svgHome === 'string' && svgHome.startsWith('<svg'), "icono('home') devuelve markup SVG");
ok(svgHome.includes('currentColor'), 'SVG hereda color vía currentColor');
ok(iconoTaquilla('nombre-inexistente-xyz') === '', "icono('nombre-inexistente-xyz') → '' (contrato)");
ok(iconoPanel('home') === svgHome, 'icono() de panel devuelve el mismo markup (misma app duplicada)');
ok(iconoPanel('nombre-inexistente-xyz') === '', "icono() panel con nombre desconocido → ''");

console.log('\n== Grupo S4 (TQ-04): iconos de badges de resultados ==');

// Los 5 iconos Lucide que `badgesResultado` usa (trophy/gem/crown/target/hash)
// deben existir en el mapa canónico; `resultados.ts` no puede inventar emoji.
const BADGES_S4 = ['trophy', 'gem', 'crown', 'target', 'hash'];
const faltantesBadges = BADGES_S4.filter((n) => !(n in mapaTaquilla));
ok(
  faltantesBadges.length === 0,
  `badges S4: trophy/gem/crown/target/hash en el mapa (${faltantesBadges.length ? `faltan: ${faltantesBadges.join(', ')}` : 'los 5 presentes'})`,
);
const rutaResultados = join(RAIZ, 'taquilla', 'src', 'utils', 'resultados.ts');
let refsNoBadge = ['resultados.ts-ausente'];
let refsResultados = [];
if (existsSync(rutaResultados)) {
  const txtResultados = readFileSync(rutaResultados, 'utf8');
  refsResultados = [...txtResultados.matchAll(RE_ICONO_HELPER)].map((m) => m[1]);
  refsNoBadge = refsResultados.filter((n) => !BADGES_S4.includes(n));
}
ok(
  refsNoBadge.length === 0,
  `resultados.ts solo referencia iconos de badges S4 (${refsNoBadge.length ? `extra: ${refsNoBadge.join(', ')}` : 'solo trophy/gem/crown/target/hash'})`,
);

console.log('\n== Grupo B: aceptación de migración (3a/3b/4.6) ==');

const rutaJson = join(RAIZ, 'taquilla', 'src', 'data', 'juegos.json');
const catalogo = JSON.parse(readFileSync(rutaJson, 'utf8'));
const iconosAnimales = new Set();
for (const j of catalogo.juegos) {
  for (const o of j.opciones) {
    if (typeof o.icono === 'string' && o.icono) iconosAnimales.add(o.icono);
  }
}

let emojiUI = 0;
for (const app of Object.keys(RUTAS_MAPA)) {
  const dir = join(RAIZ, app, 'src');
  const archivos = archivosBajo(dir, ['.astro', '.ts', '.js', '.mjs', '.tsx', '.jsx']);
  for (const f of archivos) {
    if (f.endsWith('data/juegos.json') || f.endsWith('data/logos.json') || f.endsWith('data/iconos.js')) continue;
    const txt = readFileSync(f, 'utf8');
    const hallazgos = emojiUIEn(txt, iconosAnimales);
    if (hallazgos.size) {
      emojiUI += hallazgos.size;
      const detalle = [...hallazgos.entries()].map(([c, n]) => `${JSON.stringify(c)}×${n}`).join(' ');
      console.error(`  ✗ emoji UI en ${f.replace(RAIZ + '/', '')}: ${detalle}`);
    }
  }
}
ok(emojiUI === 0, `0 emoji Unicode como iconos de UI en taquilla/src + panel/src (${emojiUI} hallazgos; se excluyen los icono del catálogo)`);

let refsGoogleFonts = 0;
for (const app of Object.keys(RUTAS_MAPA)) {
  const dir = join(RAIZ, app, 'src');
  for (const f of archivosBajo(dir, ['.astro', '.ts', '.js', '.mjs', '.css'])) {
    const txt = readFileSync(f, 'utf8');
    if (/fonts\.googleapis\.com|fonts\.gstatic\.com/.test(txt)) {
      refsGoogleFonts += 1;
      console.error(`  ✗ Google Fonts en ${f.replace(RAIZ + '/', '')}`);
    }
  }
}
ok(refsGoogleFonts === 0, `0 referencias a Google Fonts (${refsGoogleFonts} archivos con CDN)`);

let refsANIMAL_EMOJIS = 0;
for (const app of Object.keys(RUTAS_MAPA)) {
  const dir = join(RAIZ, app, 'src');
  for (const f of archivosBajo(dir, ['.astro', '.ts', '.js', '.mjs'])) {
    const txt = readFileSync(f, 'utf8');
    if (txt.includes('ANIMAL_EMOJIS')) {
      refsANIMAL_EMOJIS += 1;
      console.error(`  ✗ ANIMAL_EMOJIS en ${f.replace(RAIZ + '/', '')}`);
    }
  }
}
ok(refsANIMAL_EMOJIS === 0, `sin ANIMAL_EMOJIS hardcodeado (${refsANIMAL_EMOJIS} referencias)`);

// --- Fuente emoji subseteada (4.2/4.4/4.5 — R3) -------------------------------

// Codepoints usados por los `icono` del catálogo bundled (juegos.json).
function codepointsCatalogo() {
  const cps = new Set();
  for (const j of catalogo.juegos) {
    for (const o of j.opciones) {
      if (typeof o.icono === 'string' && o.icono) {
        for (const ch of o.icono) cps.add(ch.codePointAt(0));
      }
    }
  }
  // U+FE0F (VS16) y U+200D (ZWJ) se incluyen por diseño en el subset (el
  // manifest debe declararlos aunque el catálogo no use ZWJ hoy).
  cps.add(0xfe0f);
  cps.add(0x200d);
  return cps;
}

let fuenteOK = false;
let fuenteSize = 0;
try {
  const stats = statSync(RUTA_FUENTE);
  fuenteOK = stats.isFile() && stats.size > 0;
  if (fuenteOK) fuenteSize = stats.size;
} catch {
  fuenteOK = false;
}
ok(fuenteOK, `existe taquilla/src/assets/fonts/noto-emoji-subset.ttf no vacío (${fuenteOK ? Math.round(fuenteSize / 1024) : 0} KB)`);

let manifest = null;
try {
  manifest = JSON.parse(readFileSync(RUTA_MANIFEST, 'utf8'));
} catch {
  manifest = null;
}
ok(manifest !== null, 'existe emoji-subset.manifest.json válido (JSON parseable)');

const delCatalogo = codepointsCatalogo();
let ausentes = delCatalogo;
if (manifest !== null) {
  const declarados = new Set((manifest.codepoints ?? []).map((cp) => Number.parseInt(String(cp), 16)));
  ausentes = [...delCatalogo].filter((cp) => !declarados.has(cp));
}
ok(
  ausentes.length === 0,
  `manifest cubre los ${delCatalogo.size} codepoints del catálogo (+FE0F/200D)` +
    (ausentes.length ? ` (faltan: ${ausentes.map((c) => `U+${c.toString(16).toUpperCase()}`).join(', ')})` : ''),
);

console.log(`\n${checks} checks, ${fallos} fallos`);
process.exit(fallos === 0 ? 0 : 1);