/**
 * Badges de resultados (TQ-04) — módulo puro, sin DOM.
 *
 * `badgesResultado(resultado, opciones)` traduce las claves nuevas del motor
 * (design §6 S4, contrato §1.5) a badges renderizables:
 *   - `figuras[]`   → por figura: `trophy` + `animal|nombre_animal` + `#numero`
 *                     (+ emoji del catálogo si el label matchea `juego.opciones`).
 *   - `comodin`     → `true`→MEGA, `'A'`/`'B'`→COMODÍN A/B; con `comodin_nombre`
 *                     se muestra la glosa. Badge `gem`.
 *   - `patronus`    → `crown` + "PATRONUS".
 *   - `arrimao`     → `target` + `#arrimao`.
 *   - `pegadito`    → `hash` + `#pegadito`.
 *
 * Solo iconos Lucide vía `icono()` (check-icons valida que `resultados.ts`
 * únicamente referencie trophy/gem/crown/target/hash); ningún emoji nuevo: el
 * emoji de figura, si aparece, proviene del catálogo (`opcion.icono`), que
 * check-icons ya permite.
 */

import { icono } from './iconos.ts';
import { normalizarLabel } from './catalogo.ts';
import type { OpcionCatalogo } from './catalogo.ts';

export interface BadgeResultado {
  /** Nombre del icono Lucide (trophy/gem/crown/target/hash). */
  icono: string;
  /** SVG inline del icono Lucide (vía `icono()`), listo para innerHTML. */
  svg: string;
  /** Texto del badge (p. ej. `Delfín #0`, `MEGA`, `PATRONUS`, `#1825`). */
  texto: string;
  /** Emoji del catálogo (solo figuras cuyo label matchea `juego.opciones`). */
  emoji?: string;
}

/** SVG de los 5 iconos de badges, resueltos una sola vez. */
const SVG_BADGES = {
  trophy: icono('trophy'),
  gem: icono('gem'),
  crown: icono('crown'),
  target: icono('target'),
  hash: icono('hash'),
};

/** Emoji del catálogo para un label de figura, si matchea una opción. */
function emojiDeOpcion(label: string, opciones: OpcionCatalogo[]): string | undefined {
  if (!label) return undefined;
  const objetivo = normalizarLabel(label);
  const opcion = opciones.find((o) => normalizarLabel(o.label) === objetivo);
  return typeof opcion?.icono === 'string' && opcion.icono !== '' ? opcion.icono : undefined;
}

function badge(icono: string, svg: string, texto: string, emoji?: string): BadgeResultado {
  const b: BadgeResultado = { icono, svg, texto };
  if (emoji) b.emoji = emoji;
  return b;
}

/**
 * Badges de las claves nuevas del resultado. `opciones` (opcional) son las
 * opciones del juego desde el catálogo, usadas para resolver el emoji de las
 * figuras. Devuelve [] cuando no hay claves nuevas (nunca inventa).
 */
export function badgesResultado(
  resultado: Record<string, unknown> | null | undefined,
  opciones: OpcionCatalogo[] = [],
): BadgeResultado[] {
  if (typeof resultado !== 'object' || resultado === null) return [];

  const badges: BadgeResultado[] = [];

  // figuras[] → trophy + animal|nombre_animal + #numero (+ emoji del catálogo).
  if (Array.isArray(resultado.figuras)) {
    for (const figura of resultado.figuras) {
      if (typeof figura !== 'object' || figura === null) continue;
      const label = String(figura.animal ?? figura.nombre_animal ?? '').trim();
      const numero = figura.numero;
      const tieneNumero = numero !== undefined && numero !== null && numero !== '';
      // Una figura es animal + número: sin label no hay figura que mostrar.
      if (!label) continue;
      const texto = [label, tieneNumero ? `#${numero}` : ''].filter(Boolean).join(' ');
      badges.push(badge('trophy', SVG_BADGES.trophy, texto, emojiDeOpcion(label, opciones)));
    }
  }

  // comodin: true→MEGA, 'A'/'B'→COMODÍN A/B; + comodin_nombre (glosa).
  if (resultado.comodin === true) {
    const nombre = typeof resultado.comodin_nombre === 'string' && resultado.comodin_nombre ? ` · ${resultado.comodin_nombre}` : '';
    badges.push(badge('gem', SVG_BADGES.gem, `MEGA${nombre}`));
  } else if (resultado.comodin === 'A' || resultado.comodin === 'B') {
    const nombre = typeof resultado.comodin_nombre === 'string' && resultado.comodin_nombre ? ` · ${resultado.comodin_nombre}` : '';
    badges.push(badge('gem', SVG_BADGES.gem, `COMODÍN ${resultado.comodin}${nombre}`));
  }

  // patronus → crown "PATRONUS".
  if (resultado.patronus) {
    badges.push(badge('crown', SVG_BADGES.crown, 'PATRONUS'));
  }

  // arrimao → target #arrimao (4 cifras).
  if (resultado.arrimao !== undefined && resultado.arrimao !== null && resultado.arrimao !== '') {
    badges.push(badge('target', SVG_BADGES.target, `#${resultado.arrimao}`));
  }

  // pegadito → hash #pegadito (5 cifras).
  if (resultado.pegadito !== undefined && resultado.pegadito !== null && resultado.pegadito !== '') {
    badges.push(badge('hash', SVG_BADGES.hash, `#${resultado.pegadito}`));
  }

  return badges;
}