/**
 * Lógica pura de horarios multi-sorteo (PR3a: REQ-MH-01..04, REQ-TF-01,
 * REQ-KB-05, REQ-KB-10; A3, A4). Sin DOM ni I/O; Lin-testable vía
 * scripts/check-pure.mjs (Node 24 type-stripping: solo sintaxis TS borrable).
 *
 * Contenido (crece por slice):
 *   - alternarHorario (toggle multiselect, KB-05), marcarTodosVisibles
 *     (Ctrl+A / `*`), expandirLineas (N horarios ⇒ N apuestas, D2).
 *   - ahoraHHMM, horarioExpirado, filtrarHorariosFuturos,
 *     seleccionadosExpirados (filtro dinámico + bloqueo al expirar, A4).
 *   - agruparPorGroupId (resumen agrupado, MH-03).
 *
 * Los horarios del catálogo bundled son cadenas "HH:MM" de 24 h con cero a la
 * izquierda; la comparación lexicográfica es equivalente a la temporal.
 */

/** Alterna (toggle) un horario en la selección multiselect (KB-05, A3). */
export function alternarHorario(seleccion: readonly string[], hora: string): string[] {
  return seleccion.includes(hora)
    ? seleccion.filter((h) => h !== hora)
    : [...seleccion, hora];
}

/** Marca TODOS los horarios visibles (KB-05: Ctrl+A / `*` fuera de input). */
export function marcarTodosVisibles(visibles: readonly string[]): string[] {
  return [...visibles];
}

/** Base de una línea de apuesta antes de expandir por horarios (A3). */
export interface LineaBase {
  juegoId: number;
  juegoName: string;
  juegoType: string;
  numero: string;
  animal: string | null;
  monto: number;
  moneda: 'bs' | 'usd';
  tripleModalidad: string | null;
  tripleModalidadLabel: string | null;
}

/** Línea expandida: base + horario propio + groupId del grupo (D2). */
export interface LineaExpandida extends LineaBase {
  groupId: string;
  horario: string;
}

/**
 * Expande N horarios en N líneas de apuesta (REQ-MH-02, REQ-TF-01, D2): cada
 * línea repite la base (monto completo) y lleva su horario y el groupId del
 * grupo que comparte la jugada.
 */
export function expandirLineas(base: LineaBase, horarios: readonly string[], groupId: string): LineaExpandida[] {
  return horarios.map((horario) => ({ ...base, horario, groupId }));
}