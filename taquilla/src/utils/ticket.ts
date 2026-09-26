/**
 * Composición de las líneas del ticket impreso (módulo puro, sin DOM ni I/O;
 * Lin-testable vía scripts/check-pure.mjs con Node 24 type-stripping).
 *
 * Formato multi-juego (pedido 2026-09): el ticket identifica cada jugada con
 * su juego (agrupación en la plantilla Electron) y compone el texto de la
 * jugada por familia para que el jugador interprete el resultado sin
 * ambigüedad:
 *   - animalitos: "Perro #14" (animal + número).
 *   - terminales: solo el número ("#05"; antes se imprimía duplicado).
 *   - tripletas: modalidad + número + signo ("Triple C #157 Sagitario").
 */

export type TipoLineaTicket = 'animalitos' | 'tripletas' | 'terminales';

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

/** Datos mínimos de una jugada para componer su texto de ticket. */
export interface LineaJugada {
  tipo: string;
  animal?: string | null;
  numero?: string | number | null;
  modalidad?: string | null;
  signo?: string | null;
}

/** Texto de la jugada tal como se imprime en el ticket. */
export function formatearJugada(linea: LineaJugada): string {
  const numero =
    linea.numero !== null && linea.numero !== undefined && String(linea.numero).trim() !== ''
      ? '#' + linea.numero
      : '';
  if (linea.tipo === 'animalitos') {
    return [linea.animal, numero].filter(Boolean).join(' ') || '-';
  }
  if (linea.tipo === 'terminales') {
    return numero || '-';
  }
  // Tripletas (zodiacal y numérica): la modalidad es obligatoria para
  // interpretar el resultado; el signo solo existe en las zodiacales.
  return [linea.modalidad, numero, linea.signo].filter(Boolean).join(' ') || '-';
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
