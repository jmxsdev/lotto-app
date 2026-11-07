/**
 * Composición de las líneas del ticket impreso (módulo puro, sin DOM ni I/O;
 * Lin-testable vía scripts/check-pure.mjs con Node 24 type-stripping).
 *
 * Formato multi-juego (pedido 2026-09): el ticket identifica cada jugada con
 * su juego (agrupación en la plantilla Electron) y compone el texto de la
 * jugada por familia para que el jugador interprete el resultado sin
 * ambigüedad:
 *   - animalitos: "Perro #14" (animal + número).
 *   - tripletas: modalidad + número + signo ("Triple C #157 Sagitario"). La
 *     modalidad terminal usa el mismo formato ("#05" con `modalidad` vacía).
 */

export type TipoLineaTicket = 'animalitos' | 'tripletas';

/** Código de modalidad backend → texto visible en el ticket. */
export const MODALIDADES_TRIPLETA: Readonly<Record<string, string>> = {
  triple_a: 'Triple A',
  triple_b: 'Triple B',
  triple_c: 'Triple C',
};

/** Label de la modalidad de tripleta ('triple_b' → 'Triple B'); null si no es una conocida. */
export function labelModalidad(codigo: string | null | undefined): string | null {
  if (!codigo) return null;
  return MODALIDADES_TRIPLETA[codigo] ?? null;
}

/**
 * ¿La combinación es una tripleta de animalitos (WIP multi-sorteo, issue #63)?
 * Contrato §2.2: `{ modalidad: 'tripleta', selecciones: [{ animal } × 3] }`.
 */
export function esTripletaAnimalitos(
  combinacion: Record<string, unknown> | null | undefined
): boolean {
  return (
    combinacion?.modalidad === 'tripleta' &&
    Array.isArray(combinacion.selecciones) &&
    combinacion.selecciones.length === 3
  );
}

/**
 * Texto visible de una tripleta de animalitos: "Tripleta (A, B, C)".
 * null si la combinación no es una tripleta válida de 3 animales.
 */
export function textoTripletaAnimalitos(
  combinacion: Record<string, unknown> | null | undefined
): string | null {
  if (!esTripletaAnimalitos(combinacion)) return null;
  const selecciones = combinacion!.selecciones as Array<{ animal?: unknown }>;
  const animales = selecciones
    .map((s) => String(s?.animal ?? '').trim())
    .filter(Boolean);
  return animales.length === 3 ? `Tripleta (${animales.join(', ')})` : null;
}

/** Datos mínimos de una jugada para componer su texto de ticket. */
export interface LineaJugada {
  tipo: string;
  animal?: string | null;
  numero?: string | number | null;
  modalidad?: string | null;
  signo?: string | null;
  /**
   * WU-2/D3: slot posicional vendido (`a`/`b`/`c`) en `terminal`/`punta`/
   * `uña`. Se imprime como "Triple A/B/C" para que el ticket sea auditable.
   */
  triple?: string | null;
}

/** Texto de la jugada tal como se imprime en el ticket. */
export function formatearJugada(linea: LineaJugada): string {
  const numero =
    linea.numero !== null && linea.numero !== undefined && String(linea.numero).trim() !== ''
      ? '#' + linea.numero
      : '';
  if (linea.tipo === 'animalitos') {
    // Tripleta de animalitos (WIP multi-sorteo): la modalidad ya trae el
    // texto "Tripleta (A, B, C)"; el animal simple sigue mostrando "Animal #N°".
    return [linea.modalidad, linea.animal, numero].filter(Boolean).join(' ') || '-';
  }
  // Tripletas (zodiacal y numérica): la modalidad es obligatoria para
  // interpretar el resultado; el signo solo existe en las zodiacales y el
  // triple (A/B/C) solo en las posicionales. La modalidad terminal cae aquí y
  // conserva el payload legacy `{numero}` → "#05".
  const triple = linea.triple ? 'Triple ' + String(linea.triple).toUpperCase() : '';
  return [linea.modalidad, numero, linea.signo, triple].filter(Boolean).join(' ') || '-';
}

/** Nombres de juego únicos de un ticket, en orden de primera aparición. */
export function nombresJuegos(lineas: ReadonlyArray<{ game?: string | null }>): string[] {
  const nombres: string[] = [];
  for (const linea of lineas) {
    const nombre = (linea.game ?? '').trim();
    if (nombre && !nombres.includes(nombre)) nombres.push(nombre);
  }
  return nombres;
}
