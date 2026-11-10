// PURE prize-receipt layout. 32 columns (58 mm) is the product format; 48 is opt-in.
import { pad, wrap, truncate, rule, kv, stacked } from '../core/layout';
import { formatDate, formatTime, formatMoney } from '../core/format';
import { document } from '../core/encoder';
import type { EffectiveConfig, ReceiptData, ReceiptLine } from '../types';

const TITLE = 'COMPROBANTE DE PAGO';
const WIDE_COLUMNS = 48;
const LABEL_WIDTH = 7;
const PREFIX_WIDTH = 13; // '    ' + label(7) + ': ' -> 19 usable at 32 cols, 35 at 48
const GAME_PREFIX = 4; // '  ' + bet number + ' '
const USD_SLOT = 14; // 48-col secondary path: Bs slot is area - USD_SLOT (21), matching design.md

const moneyBs = (value: unknown): string => `Bs ${formatMoney(value)}`;
const moneyUsd = (value: unknown): string => `$ ${formatMoney(value)}`;
const totalBs = (value: unknown): string => `Bs. ${formatMoney(value)}`;
const totalUsd = (value: unknown): string => `$ ${formatMoney(value)}`;

const gameOf = (line: ReceiptLine): string => String((line && line.game) || '').trim() || '-';
const jugadaOf = (line: ReceiptLine): string => {
  const value = line && line.jugada;
  return value === undefined || value === null || String(value).trim() === '' ? '-' : String(value).trim();
};
const estadoOf = (line: ReceiptLine): string => String((line && line.estado) || '').trim() || 'PENDIENTE';

const fieldPrefix = (label: string): string => '    ' + pad(label, LABEL_WIDTH) + ': ';

// Labelled field: first line carries the 13-char prefix, wraps indent by 13.
function textField(label: string, value: unknown, columns: number): string[] {
  const prefix = fieldPrefix(label);
  return wrap(value, columns - PREFIX_WIDTH)
    .map((part, index) => (index === 0 ? prefix : ' '.repeat(PREFIX_WIDTH)) + part);
}

// Money pair inside the value area; split one currency per line when it cannot fit.
function moneyField(label: string, bs: string, usd: string, columns: number): string[] {
  const prefix = fieldPrefix(label);
  const area = columns - PREFIX_WIDTH;
  if (columns >= WIDE_COLUMNS) {
    const bsSlot = area - USD_SLOT;
    if (bs.length <= bsSlot && usd.length <= USD_SLOT) return [prefix + pad(bs, bsSlot) + usd];
  }
  if (`${bs} / ${usd}`.length <= area) return [prefix + `${bs} / ${usd}`];
  return [prefix + bs, ' '.repeat(PREFIX_WIDTH) + usd];
}

// One stacked block per bet: game line + Jugada/Estado/Monto/Premio fields.
function betLines(line: ReceiptLine, index: number, columns: number): string[] {
  const game = wrap(gameOf(line), columns - GAME_PREFIX);
  const out = ['  ' + (index + 1) + ' ' + game[0]];
  for (const part of game.slice(1)) out.push('    ' + part);
  out.push(...textField('Jugada', jugadaOf(line), columns));
  out.push(...textField('Estado', estadoOf(line), columns));
  out.push(...moneyField('Monto', moneyBs(line && line.amountBs), moneyUsd(line && line.amountUsd), columns));
  out.push(...moneyField('Premio', moneyBs(line && line.premioBs), moneyUsd(line && line.premioUsd), columns));
  return out;
}

// Payload totals win; the sum is only a defensive fallback, never a recalculation.
const pickTotal = (value: unknown, lines: ReceiptLine[], key: 'premioBs' | 'premioUsd'): number => {
  const n = value === null || value === undefined || value === '' ? NaN : Number(value);
  return Number.isFinite(n) ? n : lines.reduce((sum, line) => sum + (Number(line && line[key]) || 0), 0);
};

// kv needs a two-space separator so label and value never touch; otherwise stack.
function totalLines(label: string, value: string, columns: number): string[] {
  return label.length + 2 + value.length <= columns ? [kv(label, value, columns)] : stacked(label, value, columns);
}

function buildLines(ticketData?: ReceiptData, config?: Partial<EffectiveConfig>): string[] {
  const columns = (config && config.columns) || 32;
  const data: Partial<ReceiptData> = ticketData || {};
  const lines: ReceiptLine[] = Array.isArray(data.lines) ? data.lines : [];

  const out = [pad(TITLE, columns, 'center')];
  if (data.ticketCode) out.push(pad(truncate(String(data.ticketCode), columns), columns, 'center'));
  out.push(rule(columns));
  const fecha = [formatDate(data.date), formatTime(data.time)].filter(Boolean).join(' ');
  if (fecha) out.push(truncate(`Fecha: ${fecha}`, columns));
  out.push(rule(columns));

  lines.forEach((line, index) => out.push(...betLines(line, index, columns)));

  out.push(rule(columns));
  out.push(...totalLines('Total premio BS:', totalBs(pickTotal(data.premioTotalBs, lines, 'premioBs')), columns));
  out.push(...totalLines('Total premio USD:', totalUsd(pickTotal(data.premioTotalUsd, lines, 'premioUsd')), columns));
  out.push(rule(columns));
  out.push(pad('Premio pagado - Gracias!', columns, 'center'));
  return out;
}

function render(ticketData?: ReceiptData, config: Partial<EffectiveConfig> = {}): Buffer {
  const effective: Partial<EffectiveConfig> = { columns: 32, codePage: 'cp850', cut: 'partial', feedLines: 3, ...config };
  return document({ lines: buildLines(ticketData, effective), config: effective });
}

export { render, buildLines };
