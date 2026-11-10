// Pure column geometry. All functions are code-point safe.
const chars = (text: unknown): string[] => Array.from(String(text ?? ''));
const cpLen = (text: unknown): number => chars(text).length;

function pad(text: unknown, width: number, align = 'left'): string {
  if (!Number.isInteger(width) || width < 0) throw new RangeError('width must be a non-negative integer');
  const body = chars(text).slice(0, width).join('');
  const gap = width - cpLen(body);
  if (align === 'right') return ' '.repeat(gap) + body;
  if (align === 'center') {
    const left = Math.floor(gap / 2);
    return ' '.repeat(left) + body + ' '.repeat(gap - left);
  }
  return body + ' '.repeat(gap);
}

function truncate(text: unknown, width: number): string {
  if (!Number.isInteger(width) || width < 0) throw new RangeError('width must be a non-negative integer');
  return chars(text).slice(0, width).join('');
}

function wrap(text: unknown, width: number): string[] {
  if (!Number.isInteger(width) || width < 1) throw new RangeError('width must be a positive integer');
  const words = String(text ?? '').trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) return [''];
  const lines: string[] = [];
  let current = '';
  for (const word of words) {
    let rest = word;
    while (cpLen(rest) > width) {
      if (current) {
        lines.push(current);
        current = '';
      }
      lines.push(chars(rest).slice(0, width).join(''));
      rest = chars(rest).slice(width).join('');
    }
    if (!rest) continue;
    if (!current) current = rest;
    else if (cpLen(current) + 1 + cpLen(rest) <= width) current += ' ' + rest;
    else {
      lines.push(current);
      current = rest;
    }
  }
  if (current) lines.push(current);
  return lines;
}

function row(cells: string[], widths: number[], aligns: string[] | undefined, columns: number): string {
  if (!Array.isArray(cells) || !Array.isArray(widths) || cells.length !== widths.length) {
    throw new Error('row: cells and widths must be arrays of equal length');
  }
  const total = widths.reduce((sum, w) => sum + w, 0);
  if (total !== columns) throw new Error(`row: sum(widths)=${total} must equal columns=${columns}`);
  return widths.map((w, i) => pad(cells[i], w, aligns?.[i] ?? 'left')).join('');
}

function rule(width: number, ch = '-'): string {
  return (chars(ch)[0] || '-').repeat(Math.max(0, width));
}

function kv(label: string, value: string, columns: number): string {
  const v = truncate(value, columns);
  return pad(label, Math.max(0, columns - cpLen(v))) + v;
}

function stacked(label: string, value: unknown, columns: number, indent = 2): string[] {
  const head = truncate(label, columns);
  if (value == null || value === '') return [head];
  const available = Math.max(1, columns - indent);
  return [head, ...wrap(value, available).map((line) => ' '.repeat(indent) + line)];
}

export { pad, truncate, wrap, row, rule, kv, stacked, cpLen };
