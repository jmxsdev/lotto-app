// PURE cash-close layout. 32 columns (58 mm) is the product format; 48 is opt-in.
import { pad, wrap, truncate, rule, row, cpLen } from '../core/layout';
import { formatDate, formatTime, formatMoney } from '../core/format';
import { document } from '../core/encoder';
import type { EffectiveConfig } from '../types';

type CierreData = Record<string, any>;

const TITLE = 'CIERRE DE CAJA';
const WIDE_COLUMNS = 48;
const TOTALS_WIDTHS = [18, 15, 15];
const BREAKDOWN_WIDTHS = [14, 12, 11, 11];
const TOTALS_ROWS = [['Ventas', 'total_ventas'], ['Egresos', 'total_egresos'], ['Efectivo', 'total_efectivo']];
const ARQUEO_ROWS = [['Arqueo', 'arqueo_efectivo'], ['Falt./Sobr.', 'faltante_sobrante']];
const METHODS = [['efectivo', 'Efectivo'], ['transferencia', 'Transferencia'], ['pago_movil', 'Pago Movil'], ['punto_venta', 'Punto Venta']];
const FIELDS = [['ventas', 'Ventas'], ['egresos', 'Egresos'], ['efectivo', 'Efectivo']];
const CURRENCIES = [['bs', 'BS'], ['usd', 'USD']];

const isWide = (columns: number): boolean => columns === WIDE_COLUMNS;
const oneLine = (value: unknown): string => String(value ?? '').replace(/\s+/g, ' ').trim();
const money = (value: unknown): string => formatMoney(value);
const hasArqueo = (data: CierreData): boolean => data.arqueo_efectivo_bs != null || data.arqueo_efectivo_usd != null;
const fieldValue = (data: CierreData, key: string, currency: string): string => money(data[`${key}_${currency}`]);
const methodOf = (data: CierreData, currency: string, key: string): any => ((data.desglose_metodos || {})[currency] || {})[key] || {};
const methodMoney = (data: CierreData, currency: string, key: string, field: string): string => money(methodOf(data, currency, key)[field]);
const dateTime = (value: unknown): string => [formatDate(value), formatTime(value)].filter(Boolean).join(' ') || '-';
const centerLines = (text: unknown, columns: number): string[] => wrap(oneLine(text), columns).map((part) => pad(part, columns, 'center'));

// Right-aligned money that never truncates the amount.
function alignedMoney(prefix: string, amount: string, columns: number): string {
  return prefix + ' '.repeat(Math.max(0, columns - cpLen(prefix) - cpLen(amount))) + amount;
}

// Free text: label on the first line, continuations indented under the value.
function labeledText(label: string, value: unknown, columns: number): string[] {
  const text = oneLine(value);
  if (!text) return [];
  const indent = label.length + 2;
  return wrap(text, Math.max(1, columns - indent))
    .map((part, index) => (index === 0 ? `${label}: ` : ' '.repeat(indent)) + part);
}

// Concept plus both currencies: table at 48, stacked currency rows at 32.
function totalsRows(data: CierreData, rows: string[][], columns: number): string[] {
  const out: string[] = [];
  for (const [label, key] of rows) {
    const bs = fieldValue(data, key, 'bs');
    const usd = fieldValue(data, key, 'usd');
    if (isWide(columns)) out.push(pad(label, TOTALS_WIDTHS[0]) + pad(bs, TOTALS_WIDTHS[1], 'right') + pad(usd, TOTALS_WIDTHS[2], 'right'));
    else out.push(label, alignedMoney('  ' + pad('BS', 3), bs, columns), alignedMoney('  ' + pad('USD', 3), usd, columns));
  }
  return out;
}

// Column header for the 48-col totals table; empty at 32 columns.
const totalsHeader = (columns: number): string[] => (isWide(columns)
  ? [row(['Concepto', 'BS', 'USD'], TOTALS_WIDTHS, ['left', 'right', 'right'], columns)]
  : []);

// Four methods per currency. A 12-char amount exceeds the 11-char 48-col slot,
// so that currency falls back to the stacked rows used at 32 columns.
function breakdownRows(data: CierreData, currency: string, columns: number): string[] {
  const entries = METHODS.map(([key, label]) => ({ label, values: FIELDS.map(([field]) => methodMoney(data, currency, key, field)) }));
  const wide = isWide(columns) && entries.every((entry) => entry.values.every((value) => cpLen(value) <= BREAKDOWN_WIDTHS[2]));
  if (!wide) {
    const out: string[] = [];
    for (const entry of entries) {
      out.push(' ' + entry.label);
      FIELDS.forEach(([, fieldLabel], index) => out.push(alignedMoney('   ' + pad(fieldLabel, 8), entry.values[index], columns)));
    }
    return out;
  }
  const aligns = ['left', 'right', 'right', 'right'];
  const out = [row(['Metodo', ...FIELDS.map(([, label]) => label)], BREAKDOWN_WIDTHS, aligns, columns)];
  for (const entry of entries) out.push(row([entry.label, ...entry.values], BREAKDOWN_WIDTHS, aligns, columns));
  return out;
}

function buildLines(cierreData?: CierreData, config?: Partial<EffectiveConfig>): string[] {
  const columns = (config && config.columns) || 32;
  const data = cierreData || {};
  const out = [
    ...centerLines(TITLE, columns),
    ...centerLines(data.taquilla?.nombre || data.taquillaNombre || 'Taquilla', columns),
    rule(columns),
    truncate(`Desde: ${dateTime(data.fecha_inicio)}`, columns),
    truncate(`Hasta: ${dateTime(data.fecha_fin)}`, columns),
    truncate(`Tasa: ${money(data.exchange_rate_cierre)}`, columns),
    ...labeledText('Referencia', data.referencia, columns),
    ...labeledText('Concepto', data.concepto, columns),
    rule(columns),
    'TOTALES',
    ...totalsHeader(columns),
    ...totalsRows(data, TOTALS_ROWS, columns),
  ];
  if (hasArqueo(data)) out.push(rule(columns), 'ARQUEO / DIFERENCIA', ...totalsRows(data, ARQUEO_ROWS, columns));
  out.push(rule(columns), 'DESGLOSE POR METODO');
  for (const [currency, heading] of CURRENCIES) out.push(heading, ...breakdownRows(data, currency, columns));
  const id = data.id != null && String(data.id) !== '' ? ` #${data.id}` : '';
  const creator = oneLine(data.creador?.name || data.creadorNombre);
  out.push(rule(columns), ...wrap(`Cierre${id}${creator ? ' - ' + creator : ''}`, columns));
  return out;
}

function render(cierreData?: CierreData, config: Partial<EffectiveConfig> = {}): Buffer {
  const effective: Partial<EffectiveConfig> = { columns: 32, codePage: 'cp850', cut: 'partial', feedLines: 3, ...config };
  return document({ lines: buildLines(cierreData, effective), config: effective });
}

export {
  render, buildLines, centerLines, dateTime, oneLine, money, totalsHeader, totalsRows, breakdownRows,
  hasArqueo, TOTALS_ROWS, ARQUEO_ROWS, CURRENCIES, WIDE_COLUMNS,
};
