import * as fs from 'node:fs';
import * as path from 'node:path';
import { resolveProfile } from '../core/profiles';
import type { CodePage, Cut, EffectiveConfig, Paper, ReadFile, ResolveConfigInput } from '../types';

const CONFIG_FILE = 'printer-config.json';
const CODE_PAGES: string[] = ['cp437', 'cp850', 'cp858', 'cp1252', 'ascii'];
const CUTS: string[] = ['partial', 'full', 'none'];
const DEFAULTS: { paper: Paper; codePage: CodePage; cut: Cut; feedLines: number; cutPerBlock: boolean } = {
  paper: '58mm', codePage: 'cp850', cut: 'partial', feedLines: 3, cutPerBlock: true,
};

function readConfigFile(userDataPath: string | undefined, readFile: ReadFile, warnings: string[]): Record<string, any> {
  if (!userDataPath) return {};
  let text: string;
  try {
    text = readFile(path.join(String(userDataPath), CONFIG_FILE), 'utf8');
  } catch {
    return {}; // missing or unreadable file: defaults are valid, no warning
  }
  try {
    const parsed = JSON.parse(String(text));
    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) return parsed;
  } catch {
    // fall through to the warning below
  }
  warnings.push(`Invalid ${CONFIG_FILE}; using defaults.`);
  return {};
}
const envValue = (env: Record<string, string | undefined> | undefined, key: string): string | undefined => (env && env[key] !== undefined && env[key] !== '' ? env[key] : undefined);
const pick = (env: any, file: any, fallback: any): any => (env !== undefined ? env : file !== undefined ? file : fallback);
const toInt = (value: unknown): number => (Number.isInteger(Number(value)) ? Number(value) : NaN);
const toBool = (value: unknown): boolean | undefined => {
  if (value === true || value === 1 || value === '1') return true;
  if (value === false || value === 0 || value === '0') return false;
  return undefined;
};

function resolveConfig({ env = {}, userDataPath, readFile = fs.readFileSync as unknown as ReadFile }: ResolveConfigInput = {}): EffectiveConfig {
  const warnings: string[] = [];
  const file = readConfigFile(userDataPath, readFile, warnings);
  const rawName = pick(envValue(env, 'LOTTO_PRINTER_NAME'), file.printerName, null);
  const printerName = typeof rawName === 'string' && rawName.trim() !== '' ? rawName : null;
  let codePage: string = pick(envValue(env, 'LOTTO_PRINT_CODEPAGE'), file.codePage, DEFAULTS.codePage);
  let cut: string = pick(envValue(env, 'LOTTO_PRINT_CUT'), file.cut, DEFAULTS.cut);
  const rawFeed = pick(envValue(env, 'LOTTO_PRINT_FEED'), file.feedLines, DEFAULTS.feedLines);
  const rawPerBlock = pick(envValue(env, 'LOTTO_PRINT_CUT_PER_BLOCK'), file.cutPerBlock, DEFAULTS.cutPerBlock);
  const rawColumns = pick(envValue(env, 'LOTTO_PRINT_COLUMNS'), file.columns, undefined);
  if (!CODE_PAGES.includes(codePage)) { warnings.push(`Invalid codePage ${JSON.stringify(codePage)}; using cp850.`); codePage = DEFAULTS.codePage; }
  if (!CUTS.includes(cut)) { warnings.push(`Invalid cut ${JSON.stringify(cut)}; using partial.`); cut = DEFAULTS.cut; }
  let feedLines = toInt(rawFeed);
  if (!(feedLines >= 0 && feedLines <= 10)) { warnings.push(`Invalid feedLines ${JSON.stringify(rawFeed)}; using 3.`); feedLines = DEFAULTS.feedLines; }
  let cutPerBlock = toBool(rawPerBlock);
  if (cutPerBlock === undefined) { warnings.push(`Invalid cutPerBlock ${JSON.stringify(rawPerBlock)}; using true.`); cutPerBlock = DEFAULTS.cutPerBlock; }

  const profile = resolveProfile({
    paper: pick(envValue(env, 'LOTTO_PRINT_PAPER'), file.paper, DEFAULTS.paper),
    columns: rawColumns === undefined ? undefined : toInt(rawColumns),
  });
  warnings.push(...profile.warnings);
  return {
    printerName, paper: profile.paper, columns: profile.columns, dots: profile.dots,
    codePage: codePage as CodePage, cut: cut as Cut, feedLines, cutPerBlock,
    printTest: env.LOTTO_PRINT_TEST === '1', warnings,
  };
}

export { CONFIG_FILE, DEFAULTS, CODE_PAGES, CUTS, resolveConfig };
