// Pure charset layer for ESC/POS. No Electron, no fs, no network.
import type { CodePage } from '../types';

const MAX_LINE_CHARS = 96;
const MAX_JOB_BYTES = 512 * 1024;

// CP850 bytes; CP437 shares the same bytes for this set.
const SHARED: Record<string, number> = {
  'ü': 0x81, 'é': 0x82, 'É': 0x90, 'Ü': 0x9a, 'á': 0xa0, 'í': 0xa1, 'ó': 0xa2, 'ú': 0xa3,
  'ñ': 0xa4, 'Ñ': 0xa5, '¿': 0xa8, '¡': 0xad, 'Á': 0xb5, 'Í': 0xd6, 'Ó': 0xe0, 'Ú': 0xe9,
  '°': 0xf8,
};
const CP858 = { ...SHARED, '€': 0xd5 };
const CP1252 = {
  '¡': 0xa1, '°': 0xb0, '¿': 0xbf, 'Á': 0xc1, 'É': 0xc9, 'Í': 0xcd, 'Ñ': 0xd1, 'Ó': 0xd3,
  'Ú': 0xda, 'Ü': 0xdc, 'á': 0xe1, 'é': 0xe9, 'í': 0xed, 'ñ': 0xf1, 'ó': 0xf3, 'ú': 0xfa,
  'ü': 0xfc,
};
const ASCII_FOLD: Record<string, string> = {
  'á': 'a', 'é': 'e', 'í': 'i', 'ó': 'o', 'ú': 'u', 'ü': 'u', 'ñ': 'n', 'Ñ': 'N',
  'Á': 'A', 'É': 'E', 'Í': 'I', 'Ó': 'O', 'Ú': 'U', 'Ü': 'U', '¿': '?', '¡': '!', '°': 'o', '€': 'E',
};

function tableFor(page: CodePage): Record<string, number> {
  if (page === 'cp1252') return CP1252;
  if (page === 'cp858') return CP858;
  return SHARED;
}

const has = (table: Record<string, number>, ch: string): boolean => Object.prototype.hasOwnProperty.call(table, ch);

function sanitize(value: unknown): string {
  return String(value ?? '')
    .replace(/\r\n|\r|\n/g, ' ')
    .replace(/\t/g, ' ')
    .replace(/[\u0000-\u001F\u007F\u0080-\u009F\u200B-\u200F\u2028\u2029]/g, '');
}

function encodeText(text: unknown, codePage: CodePage = 'cp850'): Buffer {
  const table = codePage === 'ascii' ? null : tableFor(codePage);
  const out: number[] = [];
  for (const ch of String(text ?? '')) {
    const cp = ch.codePointAt(0) as number;
    if (cp >= 0x20 && cp <= 0x7e) out.push(cp);
    else if (table && has(table, ch)) out.push(table[ch]);
    else out.push(has(ASCII_FOLD, ch) ? ASCII_FOLD[ch].charCodeAt(0) : 0x3f);
  }
  return Buffer.from(out);
}

function assertJobSize(buffer: Buffer): void {
  if (buffer.length <= MAX_JOB_BYTES) return;
  const err = new Error(`Print job exceeds ${MAX_JOB_BYTES} bytes`) as Error & { code: string };
  err.code = 'E_PAYLOAD_TOO_LARGE';
  throw err;
}

function lineBytes(
  line: unknown,
  { codePage = 'cp850', columns }: { codePage?: CodePage; columns?: number } = {},
): Buffer {
  const text = sanitize(line);
  if (text.includes('\n')) throw new Error('line payload must not contain LF');
  const limit = Math.min(Number.isInteger(columns) && (columns as number) > 0 ? (columns as number) : MAX_LINE_CHARS, MAX_LINE_CHARS);
  const buffer = encodeText(Array.from(text).slice(0, limit).join(''), codePage);
  assertJobSize(buffer);
  return buffer;
}

export { sanitize, encodeText, assertJobSize, lineBytes, MAX_LINE_CHARS, MAX_JOB_BYTES };
