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
  /** Cifras exigidas (0 = sin número: signo_solo, tripleta). */
  digitos: number;
  /** La modalidad exige signo zodiacal (sigla en el payload). */
  requiereSigno: boolean;
  /**
   * S6: multi-selección same-draw (design §4) — presente ⇒ el payload es
   * `{modalidad, selecciones[]}` (contrato §2.2) y describe los slots en
   * orden. `selecciones[i].tipo` es el `tipo` de cada selección y `digitos`
   * sus cifras (0 = sin número: tripleta animales).
   */
  selecciones?: SlotSeleccion[];
}

/** Slot de una modalidad multi-selección (contrato §2.2, design §4). */
export interface SlotSeleccion {
  /** `selecciones[i].tipo` del payload (punta, triple_a, triple_b, animal). */
  tipo: string;
  /** Cifras del número de la selección (0 = sin número: tripleta). */
  digitos: number;
  /**
   * true ⇒ el número exige EXACTAMENTE `digitos` cifras (`^\d{N}$`, triples
   * A/B del Par Millonario — contrato histórico); false/omitido ⇒ 1..N con
   * padStart (puntas del cruzado, el motor normaliza).
   */
  exacto?: boolean;
}

/** Modalidad disponible en un juego: DEFS + multiplicador del catálogo. */
export interface ModalidadDisponible extends DefModalidad {
  /** `premios.modalidades[clave]` (null si no es número). */
  multiplicador: number | null;
}

/**
 * DEFS (design §4): clave canónica → forma de UI/payload. Cubre las
 * modalidades single-draw (una selección) y la multi-selección same-draw
 * (cruzado, triple_a_b, tripleta — S6, con `selecciones[]`). Los tiers
 * derivados (cruzado_10, solo_a_b) quedan FUERA: no se ofrecen (el motor los
 * emite al liquidar cuando solo acierta una selección).
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
  // S6: multi-selección same-draw (contrato §2.2) — el payload lleva
  // `{modalidad, selecciones[]}`; `digitos` describe la PRIMERA selección
  // (para el preload de dígitos y la barra superior).
  cruzado: {
    clave: 'cruzado', label: 'Cruzado', tipo: 'punta', digitos: 2, requiereSigno: false,
    selecciones: [
      { tipo: 'punta', digitos: 2 },
      { tipo: 'punta', digitos: 2 },
    ],
  },
  triple_a_b: {
    clave: 'triple_a_b', label: 'Par A+B', tipo: 'triple_a', digitos: 3, requiereSigno: false,
    selecciones: [
      { tipo: 'triple_a', digitos: 3, exacto: true },
      { tipo: 'triple_b', digitos: 3, exacto: true },
    ],
  },
  tripleta: {
    clave: 'tripleta', label: 'Tripleta', tipo: '', digitos: 0, requiereSigno: false,
    selecciones: [
      { tipo: 'animal', digitos: 0 },
      { tipo: 'animal', digitos: 0 },
      { tipo: 'animal', digitos: 0 },
    ],
  },
};

/** ¿Es una modalidad multi-selección same-draw (S6, design §4)? */
export function esMultiSeleccion(modalidad: DefModalidad | null | undefined): boolean {
  return Boolean(modalidad && Array.isArray(modalidad.selecciones) && modalidad.selecciones.length > 0);
}

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
 * Modalidades disponibles en el juego (D2, design §4): la base sintetizada
 * por familia (triple_a/triple_b del producto base) PRIMERO y luego las
 * claves de `premios.modalidades` con forma en DEFS, en el orden del
 * catálogo, con el multiplicador del catálogo (nunca hardcodeado).
 * Deduplica por clave: si el catálogo ya lista la base (el-arrejuntado),
 * NO se duplica y manda el multiplicador del catálogo. S6: incluye la
 * multi-selección same-draw (cruzado, triple_a_b, tripleta) cuando el
 * catálogo la expone; excluye los tiers derivados (solo_a_b, cruzado_10) y
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

// ─── S6: multi-selección same-draw (contrato §2.2, design §4) ─────────────

/** Entrada de una multi-selección de DOS números (cruzado / Par A+B). */
export interface EntradaMulti {
  /** Primera selección (punta A / triple_a). */
  numero?: string;
  /** Segunda selección (punta B / triple_b). */
  numeroB?: string;
}

/**
 * Arma el payload `{modalidad, selecciones[]}` de dos selecciones con número
 * (contrato §2.2, design §4): cruzado → 2 puntas, triple_a_b → triple_a +
 * triple_b. Cada número se valida/normaliza contra las cifras de su slot
 * (`padStart`). null si alguna entrada no es válida — el cajero NO postea.
 */
export function construirCombinacionMulti(
  modalidad: DefModalidad | null | undefined,
  entrada: EntradaMulti,
): Record<string, unknown> | null {
  const slots = modalidad?.selecciones;
  if (!modalidad || !slots || slots.length !== 2) return null;

  const a = normalizarNumeroSlot(entrada.numero ?? '', slots[0]);
  const b = normalizarNumeroSlot(entrada.numeroB ?? '', slots[1]);
  if (a === null || b === null) return null;

  return {
    modalidad: modalidad.clave,
    selecciones: [
      { tipo: slots[0].tipo, numero: a },
      { tipo: slots[1].tipo, numero: b },
    ],
  };
}

/**
 * Normaliza el número de un slot multi-selección: EXACTO (`^\d{N}$`) para
 * los triples del Par A+B (contrato histórico) o 1..N → padStart para las
 * puntas del cruzado (el motor normaliza). null si no es válido.
 */
function normalizarNumeroSlot(valor: string, slot: SlotSeleccion): string | null {
  const limpio = String(valor ?? '').trim();
  if (slot.exacto === true) {
    return new RegExp(`^\\d{${slot.digitos}}$`).test(limpio) ? limpio : null;
  }
  return validarDigitos(limpio, slot.digitos);
}

/**
 * Arma el payload Tripleta (contrato §2.2): `{modalidad:'tripleta',
 * selecciones:[{animal}×3]}`. null si no hay EXACTAMENTE 3 animales (los
 * labels viajan tal cual; el backend normaliza acentos, REQ2).
 */
export function construirTripleta(animales: readonly string[]): Record<string, unknown> | null {
  const unicos = animales.map((a) => String(a ?? '').trim()).filter(Boolean);
  if (unicos.length !== 3) return null;

  return { modalidad: 'tripleta', selecciones: unicos.map((animal) => ({ animal })) };
}

/**
 * Toggle de un animal en la selección de tripleta (design §5): agrega si no
 * está (con el tope lleno NO añade), quita si ya está. Conserva el orden de
 * selección (lista ordenada, contador "n/3").
 */
export function alternarSeleccionAnimal(
  selecciones: readonly string[],
  animal: string,
  tope = 3,
): string[] {
  const norm = String(animal ?? '').trim();
  if (!norm) return [...selecciones];
  if (selecciones.includes(norm)) return selecciones.filter((a) => a !== norm);
  if (selecciones.length >= tope) return [...selecciones];
  return [...selecciones, norm];
}