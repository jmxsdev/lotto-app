/**
 * Estados de ticket (módulo puro, sin DOM ni I/O; Lin-testable vía
 * scripts/check-pure.mjs con Node 24 type-stripping).
 *
 * D5: los chips del historial muestran el estado del TICKET (`ganador`,
 * `vencido`, o el derivado "resuelto sin ganadores"); los badges de la fila
 * son por APUESTA (`ganadora`, `vencido`, …) — `GET /tickets?estado=` filtra
 * por ticket, no por apuesta.
 *
 * "Resuelto sin ganadores" se deriva del payload de `GET /tickets` SOLO
 * cuando el ticket no tiene apuestas `pendiente`/`ganadora` Y
 * `tiene_ganadores=false` (el backend lo calcula como ganadoras_count > 0).
 * Si los datos están incompletos (sin `apuestas[]` o sin `tiene_ganadores`),
 * NUNCA se inventa: cae al estado real del ticket.
 */

export interface TicketEstado {
  estado?: string;
  tiene_ganadores?: boolean;
  apuestas?: Array<{ estado?: string } | null> | null;
}

/** Estado derivado de un ticket para el chip del historial (D5). */
export function estadoTicket(ticket: TicketEstado | null | undefined): string {
  if (!ticket) return '';
  const estadoReal = ticket.estado ?? '';
  const apuestas = ticket.apuestas;
  // Datos incompletos → nunca inventa: cae al estado real del ticket.
  if (!Array.isArray(apuestas) || apuestas.length === 0) return estadoReal;
  if (typeof ticket.tiene_ganadores !== 'boolean') return estadoReal;
  const tieneAbierta = apuestas.some((a) => a?.estado === 'pendiente' || a?.estado === 'ganadora');
  if (!tieneAbierta && ticket.tiene_ganadores === false) return 'resuelto-sin-ganadores';
  return estadoReal;
}