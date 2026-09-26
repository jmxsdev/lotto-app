/**
 * Calculadora de vuelto (A12, REQ-KB-07 F9) — módulo PURO, sin DOM ni I/O.
 *
 * Lin-testable vía scripts/check-pure.mjs (Node 24 type-stripping: solo
 * sintaxis TS borrable — sin enums, sin namespaces, sin parameter properties).
 *
 * Reglas (A12):
 *   - totalBs: total del ticket en Bs (el modal lo precarga con
 *     getTicketTotal() y lo deja editable).
 *   - recibido + moneda: monto recibido en Bs o $; en $ se convierte con la
 *     tasa activa del renderer (tasaActiva, SIN fetch nuevo).
 *   - tasa === null (loadTasa() falló): NUNCA se usa una tasa silenciosa; si
 *     el monto recibido es $ el cálculo no está disponible
 *     ('tasa-no-disponible'); en Bs se calcula igual (vueltoUsd queda null).
 *   - Negativo = «Falta»; positivo = vuelto a devolver.
 */

export interface ParamsVuelto {
  /** Total a cobrar en Bs (editable en el modal). */
  totalBs: number;
  /** Monto recibido, en la moneda indicada. */
  recibido: number;
  /** Moneda del monto recibido: 'bs' | 'usd'. */
  moneda: 'bs' | 'usd';
  /** Tasa activa (Bs por $); null si loadTasa() falló (A12). */
  tasa: number | null;
}

export type ResultadoVuelto =
  | { ok: true; vueltoBs: number; vueltoUsd: number | null; negativo: boolean }
  | { ok: false; motivo: 'tasa-no-disponible' };

export function calcularVuelto({ totalBs, recibido, moneda, tasa }: ParamsVuelto): ResultadoVuelto {
  if (moneda === 'usd') {
    // El monto recibido en $ exige conversión: sin tasa activa no hay cálculo
    // (nunca una tasa por defecto silenciosa).
    if (tasa === null || !(tasa > 0)) {
      return { ok: false, motivo: 'tasa-no-disponible' };
    }
    const recibidoBs = recibido * tasa;
    const vueltoBs = recibidoBs - totalBs;
    return { ok: true, vueltoBs, vueltoUsd: vueltoBs / tasa, negativo: vueltoBs < 0 };
  }
  const vueltoBs = recibido - totalBs;
  const vueltoUsd = tasa !== null && tasa > 0 ? vueltoBs / tasa : null;
  return { ok: true, vueltoBs, vueltoUsd, negativo: vueltoBs < 0 };
}