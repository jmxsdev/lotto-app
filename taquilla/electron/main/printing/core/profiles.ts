// Pure paper profiles. 58 mm (32 cols) is the default primary target.
import type { Paper } from '../types';

const DEFAULT_PAPER: Paper = '58mm';
const MIN_COLUMNS = 20;
const MAX_COLUMNS = 80;
const PAPER_PROFILES: Record<string, { columns: number; dots: number }> = Object.freeze({
  '58mm': Object.freeze({ columns: 32, dots: 384 }),
  '80mm': Object.freeze({ columns: 48, dots: 576 }),
  '80mm-512': Object.freeze({ columns: 42, dots: 512 }),
});
const PAPER_ALIASES: Record<string, Paper> = { '58': '58mm', '80': '80mm', '80-512': '80mm-512' };

interface ResolveProfileInput {
  paper?: string;
  columns?: number | null;
}

interface ResolvedProfile {
  paper: Paper;
  columns: number;
  dots: number;
  warnings: string[];
}

function resolveProfile(input: ResolveProfileInput = {}): ResolvedProfile {
  const warnings: string[] = [];
  let paper = input.paper ?? DEFAULT_PAPER;
  if (PAPER_ALIASES[paper]) paper = PAPER_ALIASES[paper];
  if (!PAPER_PROFILES[paper]) {
    warnings.push(`Invalid paper profile "${input.paper}"; using ${DEFAULT_PAPER}.`);
    paper = DEFAULT_PAPER;
  }

  let { columns, dots } = PAPER_PROFILES[paper];
  if (input.columns != null) {
    if (Number.isInteger(input.columns) && input.columns >= MIN_COLUMNS && input.columns <= MAX_COLUMNS) columns = input.columns;
    else warnings.push(`Invalid columns ${JSON.stringify(input.columns)}; using ${columns}.`);
  }
  return { paper: paper as Paper, columns, dots, warnings };
}

export { DEFAULT_PAPER, PAPER_PROFILES, MIN_COLUMNS, MAX_COLUMNS, resolveProfile };
