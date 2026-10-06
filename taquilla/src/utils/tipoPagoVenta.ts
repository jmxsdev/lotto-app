/**
 * Tipo de pago de la venta (módulo PURO, sin DOM ni I/O; Lin-testable vía
 * scripts/check-pure.mjs con Node 24 type-stripping: solo sintaxis TS borrable,
 * sin enums ni namespaces).
 *
 * Contrato backend: `metodo-pago` define el enum
 * `efectivo|transferencia|pago_movil|punto_venta` y el contrato `POST /tickets`
 * (`metodo_pago`). Este módulo concentra el catálogo código↔etiqueta↔moneda, la
 * derivación por moneda del ticket, la regla de moneda única y la validación
 * del método elegido; la UI de taquilla consume estos helpers.
 */

/** Moneda efectiva del ticket: Bs, USD, mezcla (inválida) o sin líneas. */
export type MonedaTicket = 'bs' | 'usd' | 'mixto' | null;

/** Códigos del enum backend aceptados en `metodo_pago`. */
export type CodigoMetodoPago = 'transferencia' | 'efectivo' | 'punto_venta' | 'pago_movil';

/** Opción de pago: código backend + etiqueta visible + moneda a la que aplica. */
export interface OpcionPago {
  codigo: CodigoMetodoPago;
  label: string;
  moneda: 'bs' | 'usd';
}

/**
 * Catálogo completo (design §Interfaces): las cuatro opciones en Bs y la única
 * opción USD («USD efectivo», mismo código `efectivo`). `opcionesPago` filtra
 * por la moneda del ticket; `TIPOS_PAGO` es la fuente única del catálogo.
 */
export const TIPOS_PAGO: readonly OpcionPago[] = [
  { codigo: 'transferencia', label: 'Transferencia', moneda: 'bs' },
  { codigo: 'efectivo', label: 'Efectivo', moneda: 'bs' },
  { codigo: 'punto_venta', label: 'Punto de venta', moneda: 'bs' },
  { codigo: 'pago_movil', label: 'Pago móvil', moneda: 'bs' },
  { codigo: 'efectivo', label: 'USD efectivo', moneda: 'usd' },
];

/** Línea mínima para derivar la moneda del ticket. */
export interface LineaMonedaLike {
  moneda?: string | null;
}

/**
 * Moneda del ticket a partir de sus líneas:
 *   - sin líneas (o null) → null;
 *   - solo Bs → bs; solo $ → usd; Bs y $ conviviendo → mixto;
 *   - moneda ausente/desconocida cuenta como `bs` (alineado con
 *     `monedaDeApuesta`, D4).
 */
export function monedaDelTicket(
  lines: ReadonlyArray<LineaMonedaLike> | null | undefined,
): MonedaTicket {
  if (!lines || lines.length === 0) return null;
  let hayBs = false;
  let hayUsd = false;
  for (const line of lines) {
    if (line?.moneda === 'usd') hayUsd = true;
    else hayBs = true;
  }
  if (hayBs && hayUsd) return 'mixto';
  return hayUsd ? 'usd' : 'bs';
}

/**
 * Opciones de pago según la moneda: Bs → 4 opciones; USD → solo «USD efectivo»;
 * mixto/null → [] (sin ticket válido no hay opciones).
 */
export function opcionesPago(moneda: MonedaTicket): readonly OpcionPago[] {
  if (moneda === 'bs') return TIPOS_PAGO.filter((o) => o.moneda === 'bs');
  if (moneda === 'usd') return TIPOS_PAGO.filter((o) => o.moneda === 'usd');
  return [];
}

/**
 * ¿El ticket admite el método? El código debe pertenecer a las opciones de la
 * moneda del ticket (obligatoriedad en Bs y USD).
 */
export function metodoValido(
  moneda: MonedaTicket,
  metodo: string | null | undefined,
): boolean {
  if (!metodo) return false;
  return opcionesPago(moneda).some((o) => o.codigo === metodo);
}

/**
 * Etiqueta visible del método elegido (D5: `efectivo` cambia de etiqueta por
 * moneda → «Efectivo» en Bs, «USD efectivo» en USD). null si no aplica.
 */
export function labelTipoPago(
  metodo: string | null | undefined,
  moneda: MonedaTicket,
): string | null {
  if (!metodo) return null;
  return opcionesPago(moneda).find((o) => o.codigo === metodo)?.label ?? null;
}

/**
 * ¿El ticket mezcla Bs y USD? Guarda defensiva de `handlePrint`: un ticket con
 * ambas monedas no debe imprimirse ni crearse.
 */
export function hayMezclaDeMonedas(
  lines: ReadonlyArray<LineaMonedaLike> | null | undefined,
): boolean {
  return monedaDelTicket(lines) === 'mixto';
}
