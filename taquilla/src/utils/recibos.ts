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
 *
 * `pagosPorApuesta` (S5, reimpresión) es un MAPA id-apuesta → pagos
 * registrados devueltos por `GET /pagos/{apuesta}` (`data`: arreglo de
 * `Pago` con `amount_bs`/`amount_usd`, cast decimal:2 → string). La
 * reimpresión de un ticket ya pagado lo consulta porque NO tiene las
 * respuestas de `POST /pagos`; sin él los totales saldrían en 0. Los montos
 * se suman por moneda (admite pagos parciales); si el GET falla o `data` es
 * vacío, la jugada conserva su estado real y premio 0 (nunca bloquea la
 * impresión).
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

/** Pago registrado en backend (`GET /pagos/{apuesta}` → `data[]`). Los
 * montos llegan como string por el cast `decimal:2` del modelo. */
export interface PagoRegistradoRecibo {
  amount_bs?: number | string | null;
  amount_usd?: number | string | null;
}

/** Suma los montos de los pagos registrados de una apuesta (parciales
 * incluidos); sin pagos o sin montos → null (no hay premio que mostrar). */
function premioDeRegistrados(
  registrados: ReadonlyArray<PagoRegistradoRecibo> | PagoRegistradoRecibo | null | undefined
): { premio_bs: number; premio_usd: number } | null {
  if (!registrados) return null;
  const lista = Array.isArray(registrados) ? registrados : [registrados];
  const premio_bs = lista.reduce((s, p) => s + (Number(p?.amount_bs) || 0), 0);
  const premio_usd = lista.reduce((s, p) => s + (Number(p?.amount_usd) || 0), 0);
  if (premio_bs === 0 && premio_usd === 0) return null;
  return { premio_bs, premio_usd };
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
 * (formatearJugada/labelModalidad), estado (PAGADA si se pagó en el lote o
 * tiene pagos registrados) y el premio pagado por jugada desde la respuesta
 * de pago correspondiente (POST del lote o GET de pagos registrados).
 * Los totales salen EXCLUSIVAMENTE de esos pagos, nunca de `detalles`.
 */
export function buildReciboLines(
  ticket: TicketRecibo | null | undefined,
  pagos: ReadonlyArray<RespuestaPagoRecibo | null | undefined> | null | undefined,
  pagosPorApuesta?: Readonly<Record<number, ReadonlyArray<PagoRegistradoRecibo> | PagoRegistradoRecibo | null | undefined>> | null
): ReciboResult {
  const apuestas = ticket?.apuestas ?? [];
  const lines: ReciboLine[] = apuestas.map((apuesta, i) => {
    const c = parseCombinacion(apuesta.combinacion);
    const esTripleta = Boolean(c.tipo);
    const tipo = esTripleta ? 'tripletas' : c.animal ? 'animalitos' : 'terminales';
    const respuesta = pagos?.[i] ?? null;
    // S5: la reimpresión trae los pagos registrados por apuesta (GET
    // /pagos/{apuesta}); el lote de POST /pagos (auto-print) tiene prioridad.
    const registrados = pagosPorApuesta?.[apuesta.id ?? -1] ?? null;
    const hayRegistrados =
      registrados != null && (!Array.isArray(registrados) || registrados.length > 0);
    const premio = respuesta?.premio ?? premioDeRegistrados(hayRegistrados ? registrados : null);
    return {
      game: apuesta.juego?.name ?? '',
      jugada: formatearJugada({
        tipo,
        animal: (c.animal as string) || null,
        numero: (c.numero as string | number | null) ?? null,
        modalidad: esTripleta ? labelModalidad(c.tipo as string) : null,
        signo: esTripleta && c.signo ? String(c.signo) : null,
      }),
      // Pagada cuando el premio se pagó en este lote o está registrado en el
      // backend; si no, el estado real de la apuesta (ganadora/perdida/
      // pendiente).
      estado: respuesta || hayRegistrados ? 'PAGADA' : estadoRecibo(apuesta.estado),
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