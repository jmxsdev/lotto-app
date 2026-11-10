// Deterministic money/date formatting. No ICU/locale data.
const CARACAS_OFFSET_MIN = -240;
const MS_PER_MIN = 60000;
const pad2 = (n: string | number): string => String(n).padStart(2, '0');

// String-based half-up: Math.round(1.005 * 100) is wrong for binary doubles.
function toCents(value: unknown): { neg: boolean; cents: number } {
  const n = Number(value);
  if (!Number.isFinite(n)) return { neg: false, cents: 0 };
  const neg = n < 0;
  const s = Math.abs(n).toString();
  if (s.includes('e') || s.includes('E')) return { neg, cents: Math.round(Math.abs(n) * 100) };
  const [intPart, fracPart = ''] = s.split('.');
  const frac = (fracPart + '000').slice(0, 3);
  let cents = Number(intPart) * 100 + Number(frac.slice(0, 2));
  if (Number(frac[2]) >= 5) cents += 1;
  return { neg, cents };
}

function formatMoney(value: unknown): string {
  const { neg, cents } = toCents(value);
  const int = String(Math.floor(cents / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const frac = String(cents % 100).padStart(2, '0');
  return (neg && cents !== 0 ? '-' : '') + int + ',' + frac;
}

const fallbackText = (value: unknown): string =>
  String(value ?? '').replace(/[\u0000-\u001F\u007F-\u009F]/g, ' ').trim().slice(0, 64);

function normalizeDateText(value: unknown): string {
  const s = String(value ?? '').trim();
  const m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
  return m ? `${pad2(m[1])}/${pad2(m[2])}/${m[3]}` : fallbackText(s);
}

function normalizeTimeText(value: unknown): string {
  const s = String(value ?? '').trim().toLowerCase().replace(/\s+/g, ' ');
  const ampm = s.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([ap])\.?\s*m\.?$/);
  if (ampm) {
    let hour = Number(ampm[1]) % 12;
    if (ampm[4] === 'p') hour += 12;
    return `${pad2(hour)}:${ampm[2]}`;
  }
  const wall = s.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
  return wall ? `${pad2(Number(wall[1]) % 24)}:${wall[2]}` : '';
}

const isDateOnly = (s: string): boolean => /^\d{4}-\d{2}-\d{2}$/.test(s);
const isWall = (s: string): boolean => /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test(s) && !/(?:[zZ]|[+-]\d{2}:?\d{2})$/.test(s);
const isInstant = (s: string): boolean => /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(s) && /(?:[zZ]|[+-]\d{2}:?\d{2})$/.test(s);
// Laravel microsecond stamps need millisecond normalization before Date.parse.
const parseInstant = (s: string): number => Date.parse(s.replace(/\.(\d{3})\d+/, '.$1'));
const caracasDate = (ms: number): Date => new Date(ms + CARACAS_OFFSET_MIN * MS_PER_MIN);

function formatDate(value: unknown): string {
  if (value == null || value === '') return '';
  const s = String(value).trim();
  if (isDateOnly(s)) {
    const [y, m, d] = s.split('-');
    return `${d}/${m}/${y}`;
  }
  if (isWall(s)) {
    const [y, m, d] = s.slice(0, 10).split('-');
    return `${d}/${m}/${y}`;
  }
  if (isInstant(s)) {
    const ms = parseInstant(s);
    if (!Number.isFinite(ms)) return fallbackText(s);
    const dt = caracasDate(ms);
    return `${pad2(dt.getUTCDate())}/${pad2(dt.getUTCMonth() + 1)}/${dt.getUTCFullYear()}`;
  }
  return normalizeDateText(s);
}

function formatTime(value: unknown): string {
  if (value == null || value === '') return '';
  const s = String(value).trim();
  if (isDateOnly(s)) return '';
  if (isWall(s)) {
    const m = s.match(/[ T](\d{2}):(\d{2})/);
    return m ? `${m[1]}:${m[2]}` : '';
  }
  if (isInstant(s)) {
    const ms = parseInstant(s);
    if (!Number.isFinite(ms)) return '';
    const dt = caracasDate(ms);
    return `${pad2(dt.getUTCHours())}:${pad2(dt.getUTCMinutes())}`;
  }
  return normalizeTimeText(s);
}

export { CARACAS_OFFSET_MIN, toCents, formatMoney, formatDate, formatTime, normalizeDateText, normalizeTimeText };
