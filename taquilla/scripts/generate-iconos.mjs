#!/usr/bin/env node
/**
 * Generador del mapa SVG Lucide offline (decisión de arquitectura 4).
 *
 * Ejecutar desde la raíz del repo:
 *   node taquilla/scripts/generate-iconos.mjs
 *
 * Entrada:
 *   - lucide-static (ISC, pineado por lockfile en taquilla y panel).
 *     Cada icono se lee por su clave PascalCase (p. ej. 'Trash2') y su SVG ya
 *     trae `stroke="currentColor"`.
 *
 * Salida (duplicada por app, decisión 4 — no hay workspace pnpm):
 *   - taquilla/src/data/iconos.js   (ESM: `export const ICONOS = {...}`)
 *   - panel/src/data/iconos.js      (byte-idéntico al de taquilla)
 *
 * Ambos archivos se COMMITEAN; el build no ejecuta este generador.
 * El mapa solo incluye la lista canónica CANONICO: nombres que cubren TODO el
 * inventario actual de emoji usados como iconos de UI en taquilla y panel
 * (navegación, botones, modales, pestañas, estados). Los emoji de ANIMALITOS
 * (valores `icono` del catálogo) NO forman parte del mapa: se resuelven desde
 * juegos.json y se renderizan con la fuente subseteada (slice 4).
 *
 * Licencia: los iconos Lucide son ISC (ver node_modules/lucide-static/LICENSE);
 * el mapa generado conserva esa licencia al ser transformación mecánica.
 */
import { createRequire } from 'node:module';
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const lucide = require('lucide-static'); // CJS: { PascalCase: '<svg ...>' }

const AQUI = dirname(fileURLToPath(import.meta.url));
const RAIZ = join(AQUI, '..', '..');

/** Salidas exactas (decisión 4: duplicado por app, sin workspace). */
export const SALIDAS = [
  join(RAIZ, 'taquilla', 'src', 'data', 'iconos.js'),
  join(RAIZ, 'panel', 'src', 'data', 'iconos.js'),
];

/**
 * Mapeo canónico emoji → nombre Lucide. Documenta de dónde sale cada icono:
 * cubre todos los emoji usados hoy como icono de UI en ambas apps. Varios
 * emoji pueden mapear al mismo nombre (⚠/⚠️, →/⇒, 🎰/🎲...); CANONICO
 * deduplica. Los animales NO están aquí (son datos del catálogo, no UI).
 */
export const MAPEO_EMOJI = {
  // --- taquilla: navegación y chrome (MainLayout) ---
  '🏠': 'home',            // nav Inicio
  '📋': 'clipboard-list',  // nav Ventas / Historial
  '🏆': 'trophy',          // nav Ganadores
  '💎': 'gem',             // nav
  '💰': 'banknote',        // dinero / Cierre de caja
  '🎰': 'dices',           // logo "Lotto Taquilla" (Lucide no tiene slot machine)
  '🚪': 'log-out',         // Salir
  '🔑': 'key',             // clave
  '✅': 'circle-check',    // éxito
  '❌': 'circle-x',        // error
  '⚠': 'triangle-alert',   // advertencia (sin VS16)
  '⚠️': 'triangle-alert',  // advertencia (con VS16)
  'ℹ': 'info',             // información (sin VS16)
  'ℹ️': 'info',            // información (con VS16)
  // --- taquilla: páginas ---
  '🔐': 'shield-check',    // activación
  '🔄': 'refresh-cw',      // Refrescar
  '🖨️': 'printer',         // Imprimir
  '▶': 'chevron-right',    // expandir detalle
  '▼': 'chevron-down',     // expandir detalle
  '🕐': 'clock',           // stat tiempo
  '🎯': 'target',          // stat objetivo
  '💱': 'arrow-left-right', // cambio de moneda
  '←': 'arrow-left',       // Volver
  '→': 'arrow-right',      // avanzar / separador
  '➕': 'plus',             // Agregar línea
  '🗑️': 'trash-2',         // Limpiar
  '↑': 'arrow-up',         // mover fila
  '↓': 'arrow-down',       // mover fila
  '🔢': 'hash',            // pestaña Números
  '⇒': 'arrow-right',      // "entonces" / siguiente
  '↔': 'arrow-left-right', // intercambio
  '🐾': 'paw-print',        // pestaña Animalitos (resultados; 🐾 también es
                           // valor `icono` del catálogo, pero como etiqueta de
                           // pestaña es UI y se resuelve con el icono Lucide)
  '🔍': 'search',          // buscar
  '🎫': 'ticket',          // ticket / boletos
  '🎲': 'dices',           // dados / resultados
  '✓': 'check',            // confirmación
  '✗': 'x',                // cancelar
  '✕': 'x',                // cerrar
  // --- panel: navegación (AdminLayout) ---
  '📊': 'chart-bar',       // Reportes
  '👑': 'crown',           // Masters
  '🏦': 'landmark',        // Agencias
  '📂': 'folder',          // Grupos
  '🏪': 'store',           // Bancas
  '🖥️': 'monitor',         // Taquillas
  '👥': 'users',           // Usuarios
  '📈': 'trending-up',     // Rendimiento
  '⬇️': 'download',        // Descargar
  '⏰': 'alarm-clock',     // Horario
  '👤': 'user',            // usuario
  '🔴': 'circle',          // estado activo (coloreado por CSS)
  '🟢': 'circle',          // estado inactivo (coloreado por CSS)
  '💾': 'save',            // Guardar
  '✏️': 'pencil',          // Editar
  '👁️': 'eye',             // Ver
  '◀': 'chevron-left',     // paginación
};

/** Lista canónica (ordenada, deduplicada) de nombres Lucide del mapa. */
export const CANONICO = [...new Set(Object.values(MAPEO_EMOJI))];

function pascalDe(nombre) {
  return nombre.split('-').map((p) => p.charAt(0).toUpperCase() + p.slice(1)).join('');
}

/** Normaliza el SVG de lucide-static: sin clase, una sola línea. */
function normalizar(svg) {
  return svg
    .replace(/\s*class="[^"]*"/, '')
    .replace(/\s+/g, ' ')
    .trim();
}

const mapa = {};
const faltantes = [];
for (const nombre of CANONICO) {
  const svg = lucide[pascalDe(nombre)];
  if (!svg) {
    faltantes.push(nombre);
    continue;
  }
  mapa[nombre] = normalizar(svg);
}

if (faltantes.length) {
  console.error(`FALTAN en lucide-static: ${faltantes.join(', ')}`);
  process.exit(1);
}

const contenido = `// GENERADO por taquilla/scripts/generate-iconos.mjs — NO editar a mano.
// Fuente: lucide-static (ISC). Mapa canónico: ${CANONICO.length} iconos de UI.
// Regenerar con: node taquilla/scripts/generate-iconos.mjs
export const ICONOS = ${JSON.stringify(mapa, null, 2)};
`;

for (const salida of SALIDAS) {
  mkdirSync(dirname(salida), { recursive: true });
  writeFileSync(salida, contenido);
  console.log(`✓ ${salida.replace(RAIZ + '/', '')} (${Object.keys(mapa).length} iconos)`);
}
console.log(`Mapa generado: ${CANONICO.length} nombres canónicos cubiertos.`);