// PURE commissioning slip. No Electron, no IO, no printer.
import { pad, truncate, wrap, rule } from '../core/layout';
import { encodeText } from '../core/encode';
import { document, CODE_PAGE_CMD } from '../core/encoder';
import type { CodePage, Cut, EffectiveConfig } from '../types';

type SlipItem = string | { codePage: CodePage } | { feed: number } | { cut: true };

const DEFAULT_COLUMNS = 32;
const DIGITS = '1234567890';
// The 17-character Spanish set, compact so it never wraps at 32 columns.
const ACCENTS = 'áéíóúüñÑÁÉÍÓÚ¿¡°';
const EURO = '€';
const PAGES: CodePage[] = ['cp437', 'cp850', 'cp858', 'cp1252'];

function rulerDigits(columns: number): string {
  let out = '';
  while (out.length < columns) out += DIGITS;
  return out.slice(0, columns);
}

function rulerMarks(columns: number): string {
  let out = '';
  for (let i = 0; i < columns; i += 1) out += i % 5 === 0 ? '|' : '.';
  return out;
}

const hexOf = (codePage: CodePage): string => Array.from(encodeText(ACCENTS + (codePage === 'cp858' ? EURO : ''), codePage))
  .map((byte) => byte.toString(16).toUpperCase().padStart(2, '0'))
  .join(' ');

/**
 * Commissioning layout: 32-column ruler, four labelled code-page sections, feed/cut.
 */
function buildLines(config: Partial<EffectiveConfig> = {}): SlipItem[] {
  const columns = Number.isInteger(config.columns) && (config.columns as number) > 0 ? (config.columns as number) : DEFAULT_COLUMNS;
  const paper = config.paper || '58mm';
  const dots = Number.isInteger(config.dots) ? (config.dots as number) : 384;
  const codePage: CodePage = config.codePage || 'cp850';
  const cut: Cut = config.cut || 'partial';
  const warnings = Array.isArray(config.warnings) ? config.warnings : [];

  const out: SlipItem[] = [
    'PRUEBA DE IMPRESION',
    truncate(`Papel: ${paper}  ${columns} cols  ${dots} dots`, columns),
    truncate(`Pagina: ${codePage}  Corte: ${cut}`, columns),
    'Prueba: LOTTO_PRINT_TEST=1',
    rule(columns),
    rulerDigits(columns),
    rulerMarks(columns),
    rule(columns),
  ];
  for (const warning of warnings) out.push(...wrap(`Aviso: ${warning}`, columns));

  for (const page of PAGES) {
    out.push({ codePage: page }, `${page.toUpperCase()} ESC t ${CODE_PAGE_CMD[page]}`);
    out.push(truncate(ACCENTS + (page === 'cp858' ? EURO : ''), columns));
  }

  // Restore the configured page so the diagnostics and footer use the pinned charset.
  out.push({ codePage }, rule(columns));
  for (const line of wrap(`Hex ${codePage}: ${hexOf(codePage)}`, columns)) out.push(line);
  out.push(rule(columns));
  out.push(pad('Fin de la prueba', columns, 'center'));
  out.push(pad(`Corte: ${cut}`, columns, 'center'));
  return out;
}

function render(config: Partial<EffectiveConfig> = {}): Buffer {
  const effective: Partial<EffectiveConfig> = { columns: DEFAULT_COLUMNS, codePage: 'cp850', cut: 'partial', feedLines: 3, ...config };
  return document({ lines: buildLines(effective), config: effective });
}

export { render, buildLines, buildLines as printTestSlip };
