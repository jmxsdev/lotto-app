/**
 * Pago de premios (módulo puro, sin DOM ni I/O; Lin-testable vía
 * scripts/check-pure.mjs con Node 24 type-stripping).
 *
 * Contrato backend (docs/integracion-front-motor-premios.md §1.3,
 * PagoController): `POST /pagos {apuesta_id, tipo:'egreso', moneda}` —
 * los montos son OPCIONALES; el backend aplica el premio calculado por el
 * motor y responde `premio: {premio_bs, premio_usd}`. La taquilla no
 * calcula ni confirma montos (D4).
 */

export type MonedaPago = 'bs' | 'usd' | 'mixto';

/** Datos mínimos de una apuesta para decidir pago y moneda. */
export interface ApuestaPago {
  id?: number;
  estado?: string;
  resultado_id?: number | null;
  amount_bs?: number | null;
  amount_usd?: number | null;
}

/** Datos mínimos de un ticket para decidir si tiene premios pagables. */
export interface TicketPago {
  estado?: string;
  apuestas?: ApuestaPago[] | null;
}

/** Moneda en que se paga la apuesta: Bs→bs, $→usd, ambas→mixto (D4). */
export function monedaDeApuesta(apuesta: ApuestaPago | null | undefined): MonedaPago {
  const bs = Number(apuesta?.amount_bs) > 0;
  const usd = Number(apuesta?.amount_usd) > 0;
  if (bs && usd) return 'mixto';
  if (usd) return 'usd';
  return 'bs';
}

/**
 * ¿La apuesta admite egreso? El backend acepta `ganadora` (estado nuevo,
 * siempre con resultado asignado) y `pendiente` legacy con `resultado_id`
 * ya liquidada (PagoController: estado in {pendiente, ganadora} + resultado).
 */
export function esPagableApuesta(apuesta: ApuestaPago | null | undefined): boolean {
  if (!apuesta) return false;
  if (apuesta.estado === 'ganadora') return true;
  return apuesta.estado === 'pendiente' && Boolean(apuesta.resultado_id);
}

/** Payload exacto de `POST /pagos` para un egreso: SIN montos (D4). */
export function payloadPago(apuesta: ApuestaPago): { apuesta_id: number; tipo: 'egreso'; moneda: MonedaPago } {
  return {
    apuesta_id: apuesta.id as number,
    tipo: 'egreso',
    moneda: monedaDeApuesta(apuesta),
  };
}

/**
 * ¿El ticket tiene premios que pagar? Ticket `pendiente` o `ganador` con al
 * menos una apuesta pagable (P0: un ticket `ganador` con `ganadora` sin
 * pagar DEBE mostrar Pagar — hoy el gate solo aceptaba `pendiente`).
 */
export function esPagableTicket(ticket: TicketPago | null | undefined): boolean {
  const estado = ticket?.estado;
  if (estado !== 'pendiente' && estado !== 'ganador') return false;
  return (ticket?.apuestas ?? []).some(esPagableApuesta);
}

/** Premio aplicado por el backend en una respuesta de pago. */
export interface PremioAplicado {
  premio_bs?: number | null;
  premio_usd?: number | null;
}

export interface RespuestaPago {
  premio?: PremioAplicado | null;
}

/** Suma `response.premio` de varias respuestas (pago por ticket completo). */
export function acumularPremio(
  respuestas: ReadonlyArray<RespuestaPago | null | undefined>
): { premio_bs: number; premio_usd: number } {
  let premio_bs = 0;
  let premio_usd = 0;
  for (const respuesta of respuestas) {
    const premio = respuesta?.premio;
    if (!premio) continue;
    premio_bs += Number(premio.premio_bs) || 0;
    premio_usd += Number(premio.premio_usd) || 0;
  }
  return { premio_bs, premio_usd };
}