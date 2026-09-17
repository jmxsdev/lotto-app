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

/** Zona horaria del sorteo (A4): reloj del cliente = backend (config/app.php). */
export const ZONA_HORARIA = 'America/Caracas';

/** HH:MM actual en la zona del sorteo (America/Caracas, A4). */
export function ahoraHHMM(ahora: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-GB', {
    timeZone: ZONA_HORARIA,
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(ahora);
}

/** ¿El horario HH:MM ya pasó en la zona del sorteo? (A4: bloquea añadir). */
export function horarioExpirado(hora: string, ahora: Date = new Date()): boolean {
  return hora <= ahoraHHMM(ahora);
}

/** Solo horarios futuros del día: HH:MM > ahora (REQ-MH-04, A4). */
export function filtrarHorariosFuturos(horarios: readonly string[], ahora: Date = new Date()): string[] {
  const ahoraStr = ahoraHHMM(ahora);
  return horarios.filter((h) => h > ahoraStr);
}

/** Seleccionados que ya expiraron (A4: rojo, bloquean añadir, desmarcables). */
export function seleccionadosExpirados(seleccion: readonly string[], ahora: Date = new Date()): string[] {
  const ahoraStr = ahoraHHMM(ahora);
  return seleccion.filter((h) => h <= ahoraStr);
}

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

/**
 * Agrupa líneas por groupId preservando el orden de inserción (REQ-MH-03,
 * REQ-TF-04, A3): el resumen muestra un grupo por jugada ("Perro → 14:00,
 * 17:00") aunque el POST envíe N líneas.
 */
export function agruparPorGroupId(lineas: readonly LineaExpandida[]): Array<{ groupId: string; lines: LineaExpandida[] }> {
  const grupos: Array<{ groupId: string; lines: LineaExpandida[] }> = [];
  const indice = new Map<string, number>();
  for (const l of lineas) {
    let i = indice.get(l.groupId);
    if (i === undefined) {
      i = grupos.length;
      indice.set(l.groupId, i);
      grupos.push({ groupId: l.groupId, lines: [] });
    }
    grupos[i].lines.push(l);
  }
  return grupos;
}