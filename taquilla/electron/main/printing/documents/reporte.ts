// PURE range-report layout. 32 columns (58 mm) is the product format; 48 is opt-in.
import { truncate, wrap, rule } from '../core/layout';
import { formatDate, formatMoney } from '../core/format';
import { document } from '../core/encoder';
import {
  centerLines, dateTime, oneLine, money, totalsHeader, totalsRows, breakdownRows,
  hasArqueo, TOTALS_ROWS, ARQUEO_ROWS, CURRENCIES, WIDE_COLUMNS,
} from './cierre';
import type { EffectiveConfig, Cut } from '../types';

type ReporteData = Record<string, any>;
type ReporteLine = string | { feed: number; cut?: boolean };

const TITLE = 'REPORTE POR RANGO';
const BLOCK_FEED = 2;
const FALLBACK_TAQUILLA = 'Taquilla';
const CUBIERTO_INDENT = 'Cubierto: '.length;
const closesOf = (data: ReporteData): any[] => (Array.isArray(data.cierres) ? data.cierres : []);
const taquillaOf = (data: ReporteData): string => oneLine(data.taquilla?.nombre || data.taquillaNombre) || FALLBACK_TAQUILLA;

// Fused totals use the payload; included closes fall back to the list length.
function includedOf(data: ReporteData): any {
  const value = (data.ventana_cubierta || {}).cierres_incluidos;
  return value === undefined || value === null ? closesOf(data).length : value;
}
// One heading per close: combined at 48, two lines (cierre + taquilla) at 32.
function blockHeading(close: any, columns: number): string[] {
  const id = close.id != null && String(close.id) !== '' ? ` #${close.id}` : '';
  const taquilla = close.taquilla_id != null && String(close.taquilla_id) !== '' ? ` #${close.taquilla_id}` : '';
  if (columns === WIDE_COLUMNS) return wrap(`=== Cierre${id}${taquilla ? ' - Taquilla' + taquilla : ''} ===`, columns);
  return [...wrap(`=== Cierre${id} ===`, columns), ...wrap(`Taquilla${taquilla}`, columns)];
}
// Per-close block: totals, optional arqueo, per-method breakdown for both currencies.
function closeBlock(close: any, columns: number): string[] {
  const data = close || {};
  const out = [
    ...blockHeading(data, columns),
    truncate(`Desde: ${dateTime(data.fecha_inicio)}`, columns),
    truncate(`Hasta: ${dateTime(data.fecha_fin)}`, columns),
    truncate(`Tasa: ${money(data.exchange_rate_cierre)}`, columns),
    ...totalsRows(data, TOTALS_ROWS, columns),
  ];
  if (hasArqueo(data)) out.push(...totalsRows(data, ARQUEO_ROWS, columns));
  for (const [currency, heading] of CURRENCIES) out.push(heading, ...breakdownRows(data, currency, columns));
  return out;
}

function buildLines(reporteData?: ReporteData, config?: Partial<EffectiveConfig>): ReporteLine[] {
  const columns = (config && config.columns) || 32;
  const data = reporteData || {};
  const closes = closesOf(data);
  const ventana = data.ventana_cubierta || {};
  const cut: Cut = (config && config.cut) || 'partial';
  const cutPerBlock = !config || config.cutPerBlock !== false;
  const out: ReporteLine[] = [
    ...centerLines(TITLE, columns),
    ...centerLines(taquillaOf(data), columns),
    rule(columns),
    truncate(`Desde: ${formatDate(data.fecha_desde) || '-'}`, columns),
    truncate(`Hasta: ${formatDate(data.fecha_hasta) || '-'}`, columns),
    truncate(`Cierres incluidos: ${includedOf(data)}`, columns),
    truncate(`Cubierto: ${dateTime(ventana.desde)}`, columns),
    ' '.repeat(CUBIERTO_INDENT) + truncate(dateTime(ventana.hasta), columns - CUBIERTO_INDENT),
    rule(columns),
    'TOTALES',
    ...totalsHeader(columns),
    ...totalsRows(data, TOTALS_ROWS, columns),
    'DESGLOSE FUSIONADO',
  ];
  for (const [currency, heading] of CURRENCIES) out.push(heading, ...breakdownRows(data, currency, columns));
  out.push(rule(columns));
  if (closes.length > 0) {
    closes.forEach((close, index) => {
      if (index > 0) out.push(...(cutPerBlock && cut !== 'none' ? [{ feed: BLOCK_FEED, cut: true }] : [rule(columns)]) as ReporteLine[]);
      out.push(...closeBlock(close, columns));
    });
    out.push(rule(columns));
  }
  return out;
}

function render(reporteData?: ReporteData, config: Partial<EffectiveConfig> = {}): Buffer {
  const effective: Partial<EffectiveConfig> = { columns: 32, codePage: 'cp850', cut: 'partial', feedLines: 3, cutPerBlock: true, ...config };
  return document({ lines: buildLines(reporteData, effective), config: effective });
}
export { render, buildLines, BLOCK_FEED };
