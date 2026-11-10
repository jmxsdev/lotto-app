// PURE sale-ticket layout. 32 columns (58 mm) is the product format; 48 is opt-in.
import { pad, wrap, row, rule, kv, stacked, truncate } from '../core/layout';
import { formatDate, formatTime, formatMoney } from '../core/format';
import { document } from '../core/encoder';
import type { EffectiveConfig, TicketData, TicketLine } from '../types';

const TABLE_COLUMNS = 48;
const MONEY_SLOT = 11;
const INDENT = '    ';
const TABLE_WIDTHS: { hora: number[]; plain: number[] } = { hora: [2, 18, 6, 11, 11], plain: [2, 22, 12, 12] };
const TABLE_ALIGNS: { hora: string[]; plain: string[] } = { hora: ['right', 'left', 'right', 'right', 'right'], plain: ['right', 'left', 'right', 'right'] };

const gameOf = (line: TicketLine): string => String((line && line.game) || '').trim();
const uniqueGames = (lines: TicketLine[]): string[] => {
  const games: string[] = [];
  for (const line of lines) {
    const game = gameOf(line);
    if (game && !games.includes(game)) games.push(game);
  }
  return games;
};
const jugadaOf = (line: TicketLine): string => {
  if (line && line.jugada !== undefined && line.jugada !== null && String(line.jugada).trim() !== '') return String(line.jugada).trim();
  const number = line && line.number !== undefined && line.number !== null && String(line.number) !== '' ? '#' + line.number : '';
  return [line && line.animal, number].filter(Boolean).join(' ') || '-';
};
const horaOf = (line: TicketLine): string => formatTime(line && line.sorteo);
const moneyBs = (value: number): string => `Bs ${formatMoney(value)}`;
const moneyUsd = (value: number): string => `$ ${formatMoney(value)}`;
const fitsTable = (lines: TicketLine[]): boolean => lines.every((line) => formatMoney(line.amountBs).length <= MONEY_SLOT
  && formatMoney(line.amountUsd).length <= MONEY_SLOT);

// Line 2 of a bet: optional Hora + the Bs/$ pair right-aligned; split when it cannot fit.
function moneyLines(line: TicketLine, columns: number): string[] {
  const hora = horaOf(line);
  const left = INDENT + (hora ? pad(hora, 5) + '  ' : INDENT);
  const segment = `${moneyBs(line.amountBs)}  ${moneyUsd(line.amountUsd)}`;
  if (left.length + segment.length <= columns) {
    return [left + ' '.repeat(columns - left.length - segment.length) + segment];
  }
  return [pad(moneyBs(line.amountBs), columns, 'right'), pad(moneyUsd(line.amountUsd), columns, 'right')];
}

// 32-col bet: line 1 is n + jugada, continuations indent 4, then the money line(s).
function stackedBet(line: TicketLine, index: number, columns: number): string[] {
  const parts = wrap(jugadaOf(line), columns - 3);
  const lines = [pad(' ' + (index + 1), 3) + parts[0]];
  if (parts.length > 1) {
    for (const part of wrap(parts.slice(1).join(' '), columns - INDENT.length)) lines.push(INDENT + part);
  }
  return lines.concat(moneyLines(line, columns));
}

// 48-col table (opt-in): one row per bet with an optional Hora column.
function tableLines(linesData: TicketLine[], columns: number, multiGame: boolean): string[] {
  const hasHora = linesData.some((line) => horaOf(line));
  const widths = hasHora ? TABLE_WIDTHS.hora : TABLE_WIDTHS.plain;
  const aligns = hasHora ? TABLE_ALIGNS.hora : TABLE_ALIGNS.plain;
  const out = [row(hasHora ? ['#', 'Jugada', 'Hora', 'BS', 'USD'] : ['#', 'Jugada', 'BS', 'USD'], widths, aligns, columns)];
  let last: string | null = null;
  linesData.forEach((line, index) => {
    const game = gameOf(line);
    if (multiGame && game && game !== last) out.push(...wrap(`-- ${game}`, columns));
    last = game;
    const parts = wrap(jugadaOf(line), widths[1] - 1);
    const cells = hasHora
      ? [String(index + 1), ' ' + parts[0], horaOf(line), formatMoney(line.amountBs), formatMoney(line.amountUsd)]
      : [String(index + 1), ' ' + parts[0], formatMoney(line.amountBs), formatMoney(line.amountUsd)];
    out.push(row(cells, widths, aligns, columns));
    for (const part of parts.slice(1)) out.push('   ' + part);
  });
  return out;
}

function totalLines(label: string, value: string, columns: number): string[] {
  return label.length + value.length <= columns ? [kv(label, value, columns)] : stacked(label, value, columns);
}

function buildLines(ticketData?: TicketData, config?: Partial<EffectiveConfig>): string[] {
  const columns = config && config.columns ? config.columns : 32;
  const data: Partial<TicketData> = ticketData || {};
  const linesData: TicketLine[] = Array.isArray(data.lines) ? data.lines : [];
  const games = uniqueGames(linesData);
  const multiGame = games.length > 1;
  const headerGame = String(data.game || '').trim() || games.join(', ') || 'Apuesta';

  const out = ['LOTTO TICKET'];
  for (const part of wrap(headerGame, columns)) out.push(pad(part, columns, 'center'));
  out.push(rule(columns));
  if (data.ticketCode) out.push(truncate(`Ticket: ${data.ticketCode}`, columns));
  const fecha = [formatDate(data.date), formatTime(data.time)].filter(Boolean).join(' ');
  if (fecha) out.push(truncate(`Fecha: ${fecha}`, columns));
  if (data.tipoPago) out.push(truncate(`Tipo de pago: ${data.tipoPago}`, columns));
  out.push(rule(columns));

  if (columns === TABLE_COLUMNS && fitsTable(linesData)) {
    out.push(...tableLines(linesData, columns, multiGame));
  } else {
    let last: string | null = null;
    linesData.forEach((line, index) => {
      const game = gameOf(line);
      if (multiGame && game && game !== last) out.push(...wrap(`-- ${game}`, columns));
      last = game;
      out.push(...stackedBet(line, index, columns));
    });
  }

  const totalBs = linesData.reduce((sum, line) => sum + (Number(line.amountBs) || 0), 0);
  const totalUsd = linesData.reduce((sum, line) => sum + (Number(line.amountUsd) || 0), 0);
  out.push(rule(columns));
  out.push(...totalLines('Total BS:', `Bs. ${formatMoney(totalBs)}`, columns));
  out.push(...totalLines('Total USD:', `$ ${formatMoney(totalUsd)}`, columns));
  out.push(rule(columns));
  out.push(pad('Gracias por su compra!', columns, 'center'));
  return out;
}

function render(ticketData?: TicketData, config: Partial<EffectiveConfig> = {}): Buffer {
  const effective: Partial<EffectiveConfig> = { columns: 32, codePage: 'cp850', cut: 'partial', feedLines: 3, ...config };
  return document({ lines: buildLines(ticketData, effective), config: effective });
}

export { render, buildLines };
