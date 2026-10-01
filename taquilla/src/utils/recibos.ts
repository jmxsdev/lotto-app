/**
 * Comprobante de pago de premios (módulo puro, sin DOM ni I/O; Lin-testable
 * vía scripts/check-pure.mjs con Node 24 type-stripping).
 *
 * Contrato (S1 taquilla-operativa): `buildReciboLines(ticket, pagos)` arma las
 * líneas del comprobante desde el ticket (cada apuesta + su estado) y desde
 * las respuestas REALES de `POST /pagos`. Los totales se acumulan SOLO desde
 * las respuestas de pago (`premio`), NUNCA se recalculan desde `detalles`
 * (spec taquilla-comprobante-pago: evita drift de redondeo).
 *
 * `pagos` es un arreglo PARALELO a `ticket.apuestas`: cada entrada es la
 * respuesta de `POST /pagos` de esa apuesta (o null si no se pagó en el
 * lote). El glue de las páginas lo arma mapeando `data.apuesta_id`.
 */

import { formatearJugada, labelModalidad } from './ticket.ts';

export type EstadoRecibo = 'GANADA' | 'PERDIDA' | 'PENDIENTE' | 'PAGADA';

export interface ReciboLine {
  game: string;
  jugada: string;
  estado: EstadoRecibo;
  amountBs: number;
  amountUsd: number;
  premioBs: number;
  premioUsd: number;
}

export interface ReciboResult {
  lines: ReciboLine[];
  premioTotalBs: number;
  premioTotalUsd: number;
}

/** Mapeo de estados backend → etiqueta del comprobante (spec §1). */
const MAPA_ESTADO: Record<string, EstadoRecibo> = {
  ganadora: 'GANADA',
  perdida: 'PERDIDA',
  pendiente: 'PENDIENTE',
  pagada: 'PAGADA',
};

/** Etiqueta de estado del comprobante; estados fuera del mapa → PENDIENTE. */
export function estadoRecibo(estado: string | null | undefined): EstadoRecibo {
  return MAPA_ESTADO[estado ?? ''] ?? 'PENDIENTE';
}

/** Datos mínimos de una apuesta para el comprobante. */
export interface ApuestaRecibo {
  id?: number;
  estado?: string | null;
  amount_bs?: number | null;
  amount_usd?: number | null;
  juego?: { name?: string | null } | null;
  combinacion?: string | Record<string, unknown> | null;
}

export interface TicketRecibo {
  apuestas?: ApuestaRecibo[] | null;
}

/** Respuesta de `POST /pagos` (mínimo: el premio aplicado por el backend). */
export interface RespuestaPagoRecibo {
  premio?: { premio_bs?: number | null; premio_usd?: number | null } | null;
}

function parseCombinacion(combinacion: ApuestaRecibo['combinacion']): Record<string, unknown> {
  if (!combinacion) return {};
  if (typeof combinacion === 'string') {
    try {
      return JSON.parse(combinacion) as Record<string, unknown>;
    } catch {
      return {};
    }
  }
  return combinacion;
}

/**
 * Líneas del comprobante: UNA por apuesta del ticket, con juego, jugada
 * (formatearJugada/labelModalidad), estado (PAGADA si se pagó en el lote) y
 * el premio pagado por jugada desde la respuesta de pago correspondiente.
 * Los totales salen EXCLUSIVAMENTE de las respuestas de `POST /pagos`.
 */
export function buildReciboLines(
  ticket: TicketRecibo | null | undefined,
  pagos: ReadonlyArray<RespuestaPagoRecibo | null | undefined> | null | undefined
): ReciboResult {
  const apuestas = ticket?.apuestas ?? [];
  const lines: ReciboLine[] = apuestas.map((apuesta, i) => {
    const c = parseCombinacion(apuesta.combinacion);
    const esTripleta = Boolean(c.tipo);
    const tipo = esTripleta ? 'tripletas' : c.animal ? 'animalitos' : 'terminales';
    const respuesta = pagos?.[i] ?? null;
    const premio = respuesta?.premio;
    return {
      game: apuesta.juego?.name ?? '',
      jugada: formatearJugada({
        tipo,
        animal: (c.animal as string) || null,
        numero: (c.numero as string | number | null) ?? null,
        modalidad: esTripleta ? labelModalidad(c.tipo as string) : null,
        signo: esTripleta && c.signo ? String(c.signo) : null,
      }),
      // Pagada cuando el premio se pagó en este lote; si no, el estado real
      // de la apuesta (ganadora/perdida/pendiente/pagada previa).
      estado: respuesta ? 'PAGADA' : estadoRecibo(apuesta.estado),
      amountBs: Number(apuesta.amount_bs) || 0,
      amountUsd: Number(apuesta.amount_usd) || 0,
      premioBs: Number(premio?.premio_bs) || 0,
      premioUsd: Number(premio?.premio_usd) || 0,
    };
  });
  return {
    lines,
    premioTotalBs: lines.reduce((s, l) => s + l.premioBs, 0),
    premioTotalUsd: lines.reduce((s, l) => s + l.premioUsd, 0),
  };
}