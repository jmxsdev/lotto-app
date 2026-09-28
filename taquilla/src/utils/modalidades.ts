/**
 * Modalidades single-draw (D2, design §4) — módulo PURO, sin DOM ni I/O.
 *
 * Fuente canónica de opciones: `premios.modalidades` del catálogo bundled
 * (S2: `premios(juego)`/`modalidadesDe`). DEFS aporta SOLO la forma (label
 * de UI, cifras, signo, `tipo` del payload); los multiplicadores vienen del
 * catálogo (cero valores hardcodeados).
 *
 * Lin-testable vía scripts/check-pure.mjs (Node 24 type-stripping, solo
 * sintaxis TS borrable).
 */

import type { JuegoCatalogo } from './catalogo.ts';
import { premios } from './catalogo.ts';

/** Forma estática de una modalidad single-draw (design §4). */
export interface DefModalidad {
  /** Clave canónica en `premios.modalidades` (design §4). */
  clave: string;
  /** Label de UI (design §4). */
  label: string;
  /** `combinacion.tipo` del payload (contrato §2.1): signo_triple viaja como triple_c. */
  tipo: string;
  /** Cifras exigidas (0 = sin número: signo_solo). */
  digitos: number;
  /** La modalidad exige signo zodiacal (sigla en el payload). */
  requiereSigno: boolean;
}

/** Modalidad disponible en un juego: DEFS + multiplicador del catálogo. */
export interface ModalidadDisponible extends DefModalidad {
  /** `premios.modalidades[clave]` (null si no es número). */
  multiplicador: number | null;
}

/**
 * DEFS (design §4): clave canónica → forma de UI/payload. Cubre las
 * modalidades single-draw (una selección). La multi-selección (cruzado,
 * triple_a_b, tripleta) y los tiers derivados (cruzado_10, solo_a_b) quedan
 * FUERA: no se ofrecen en S5 (S6 los agrega con `selecciones[]`).
 */
export const DEFS: Record<string, DefModalidad> = {
  triple_a: { clave: 'triple_a', label: 'Triple A', tipo: 'triple_a', digitos: 3, requiereSigno: false },
  triple_b: { clave: 'triple_b', label: 'Triple B', tipo: 'triple_b', digitos: 3, requiereSigno: false },
  signo_triple: { clave: 'signo_triple', label: 'Triple C', tipo: 'triple_c', digitos: 3, requiereSigno: true },
  punta: { clave: 'punta', label: 'Punta', tipo: 'punta', digitos: 2, requiereSigno: false },
  terminal: { clave: 'terminal', label: 'Terminal', tipo: 'terminal', digitos: 2, requiereSigno: false },
  uña: { clave: 'uña', label: 'Una', tipo: 'uña', digitos: 1, requiereSigno: false },
  aproximacion: { clave: 'aproximacion', label: 'Aproximación', tipo: 'aproximacion', digitos: 2, requiereSigno: false },
  signo_terminal: { clave: 'signo_terminal', label: 'Signo + terminal', tipo: 'signo_terminal', digitos: 2, requiereSigno: true },
  signo_uña: { clave: 'signo_uña', label: 'Signo + una', tipo: 'signo_uña', digitos: 1, requiereSigno: true },
  signo_solo: { clave: 'signo_solo', label: 'Signo solo', tipo: 'signo_solo', digitos: 0, requiereSigno: true },
  arrimao: { clave: 'arrimao', label: 'Arrimao', tipo: 'arrimao', digitos: 4, requiereSigno: false },
  pegadito: { clave: 'pegadito', label: 'Pegadito', tipo: 'pegadito', digitos: 5, requiereSigno: false },
};

function esNumero(valor: unknown): valor is number {
  return typeof valor === 'number' && Number.isFinite(valor);
}

/**
 * Entradas BASE sintetizadas (design §4 fila "— (base)"): el producto base
 * del juego NO viaja como clave en `premios.modalidades` para la mayoría de
 * los juegos (zulia, caliente, chance, trio-activo, tachira, facil,
 * zamorano) — la familia lo sintetiza ANTES de las claves del catálogo. El
 * multiplicador es `premios(juego).base` (el motor resuelve
 * `modalidades[clave] ?? base`, PremiosEngine::multiplicadorPara), nunca
 * hardcodeado. `el-arrejuntado` SÍ lista `triple_a`/`triple_b` en el
 * catálogo (600×): `modalidadesDisponibles` deduplica por clave y conserva
 * la del catálogo.
 */
function entradasBase(juego: JuegoCatalogo): ModalidadDisponible[] {
  const base = premios(juego).base;
  // Sin premios (la-ricachona, no vendible) no hay producto base que ofrecer.
  if (!esNumero(base)) return [];

  if (juego.familia === 'zodiacal') {
    return [
      { ...DEFS.triple_a, multiplicador: base },
      { ...DEFS.triple_b, multiplicador: base },
    ];
  }
  if (juego.familia === 'numerica') {
    // A2 (numérica 00-99): la base es el triple seco SOLO triple_a, con label
    // "Triple" y la semántica legacy "05"→"005" (padding a 3 del plugin).
    return [{ ...DEFS.triple_a, label: 'Triple', multiplicador: base }];
  }
  return [];
}

/**
 * Modalidades single-draw disponibles en el juego (D2, design §4): la base
 * sintetizada por familia (triple_a/triple_b del producto base) PRIMERO y
 * luego las claves de `premios.modalidades` con forma en DEFS, en el orden
 * del catálogo, con el multiplicador del catálogo (nunca hardcodeado).
 * Deduplica por clave: si el catálogo ya lista la base (el-arrejuntado),
 * NO se duplica y manda el multiplicador del catálogo. Excluye claves
 * multi-selección/tiers (cruzado, triple_a_b, solo_a_b, cruzado_10) y
 * espejos legacy.
 */
export function modalidadesDisponibles(juego: JuegoCatalogo): ModalidadDisponible[] {
  const modalidades = premios(juego).modalidades;

  const delCatalogo = Object.keys(modalidades)
    .filter((clave) => clave in DEFS)
    .map((clave) => {
      const def = DEFS[clave];
      const valor = modalidades[clave];
      return {
        ...def,
        multiplicador: esNumero(valor) ? (valor as number) : null,
      };
    });

  const clavesCatalogo = new Set(delCatalogo.map((m) => m.clave));

  return [
    ...entradasBase(juego).filter((base) => !clavesCatalogo.has(base.clave)),
    ...delCatalogo,
  ];
}

/**
 * Valida y normaliza los dígitos de una modalidad (design §4): `^\d{1,N}$`
 * → `padStart(N,'0')`. null si no son dígitos (1..N) — el cajero NO postea.
 */
export function validarDigitos(digitos: string, maxDigitos: number): string | null {
  const limpio = String(digitos ?? '').trim();
  if (!new RegExp(`^\\d{1,${maxDigitos}}$`).test(limpio)) return null;
  return limpio.padStart(maxDigitos, '0');
}

/**
 * Arma el payload `combinacion` single-draw (contrato §2.1, design §4):
 * `{tipo, numero?, signo?}` con el número normalizado (padStart) y el signo
 * cuando la modalidad lo exige. null si la entrada no es válida (dígitos
 * fuera de rango o signo faltante) — en ese caso NO se arma la línea.
 */
export function construirCombinacion(
  modalidad: DefModalidad | undefined | null,
  entrada: { numero?: string; signo?: string },
): Record<string, string> | null {
  if (!modalidad) return null;

  const combinacion: Record<string, string> = { tipo: modalidad.tipo };

  if (modalidad.digitos > 0) {
    const normalizado = validarDigitos(entrada.numero ?? '', modalidad.digitos);
    if (!normalizado) return null;
    combinacion.numero = normalizado;
  }

  if (modalidad.requiereSigno) {
    const signo = String(entrada.signo ?? '').trim();
    if (!signo) return null;
    combinacion.signo = signo;
  }

  return combinacion;
}