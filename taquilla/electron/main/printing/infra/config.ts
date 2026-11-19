import * as fs from 'node:fs';
import * as path from 'node:path';
import { MAX_COLUMNS, MIN_COLUMNS, PAPER_PROFILES, resolveProfile } from '../core/profiles';
import { isSafePrinterName } from './names';
import type {
  CodePage, Cut, EffectiveConfig, Paper, PrintError, PrintErrorCode, ReadFile, RenameFile,
  ResolveConfigInput, SavedPrinterConfig, SavePrinterConfigInput, WriteFile,
} from '../types';

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
const toInt = (value: unknown): number => (Number.isInteger(Number(value)) ? Number(value) : NaN);
const toBool = (value: unknown): boolean | undefined => {
  if (value === true || value === 1 || value === '1') return true;
  if (value === false || value === 0 || value === '0') return false;
  return undefined;
};
const fail = (code: PrintErrorCode, message: string, details?: unknown): PrintError => {
  const error = new Error(message) as PrintError;
  error.code = code;
  error.details = details;
  return error;
};
const invalidField = (field: string, value: unknown): PrintError => fail(
  'E_CONFIG_INVALID',
  `Valor invalido para "${field}" en ${CONFIG_FILE}.`,
  { field, value },
);

function resolveConfig({ userDataPath, readFile = fs.readFileSync as unknown as ReadFile }: ResolveConfigInput = {}): EffectiveConfig {
  const warnings: string[] = [];
  const file = readConfigFile(userDataPath, readFile, warnings);

  const rawName = file.printerName;
  let printerName: string | null = null;
  if (typeof rawName === 'string') printerName = rawName.trim() === '' ? null : rawName.trim();
  else if (rawName !== null && rawName !== undefined) warnings.push('Invalid printerName; using unconfigured.');

  let codePage: string = file.codePage ?? DEFAULTS.codePage;
  let cut: string = file.cut ?? DEFAULTS.cut;
  if (!CODE_PAGES.includes(codePage)) { warnings.push(`Invalid codePage ${JSON.stringify(codePage)}; using cp850.`); codePage = DEFAULTS.codePage; }
  if (!CUTS.includes(cut)) { warnings.push(`Invalid cut ${JSON.stringify(cut)}; using partial.`); cut = DEFAULTS.cut; }
  let feedLines = toInt(file.feedLines ?? DEFAULTS.feedLines);
  if (!(feedLines >= 0 && feedLines <= 10)) { warnings.push(`Invalid feedLines ${JSON.stringify(file.feedLines)}; using 3.`); feedLines = DEFAULTS.feedLines; }
  let cutPerBlock = toBool(file.cutPerBlock ?? DEFAULTS.cutPerBlock);
  if (cutPerBlock === undefined) { warnings.push(`Invalid cutPerBlock ${JSON.stringify(file.cutPerBlock)}; using true.`); cutPerBlock = DEFAULTS.cutPerBlock; }

  const profile = resolveProfile({
    paper: file.paper ?? DEFAULTS.paper,
    columns: file.columns === undefined ? undefined : toInt(file.columns),
  });
  warnings.push(...profile.warnings);
  return {
    printerName, paper: profile.paper, columns: profile.columns, dots: profile.dots,
    codePage: codePage as CodePage, cut: cut as Cut, feedLines, cutPerBlock, warnings,
  };
}

export interface SaveConfigOptions extends ResolveConfigInput {
  writeFile?: WriteFile;
  renameFile?: RenameFile;
}

function saveConfig({
  userDataPath,
  readFile = fs.readFileSync as unknown as ReadFile,
  writeFile = fs.writeFileSync as unknown as WriteFile,
  renameFile = fs.renameSync as unknown as RenameFile,
}: SaveConfigOptions, patch: SavePrinterConfigInput): EffectiveConfig {
  if (!userDataPath) throw fail('E_CONFIG_WRITE_FAILED', `No se pudo escribir ${CONFIG_FILE}: falta userDataPath.`);
  const input = patch || {} as SavePrinterConfigInput;
  const current = readConfigFile(String(userDataPath), readFile, []);
  const base = resolveConfig({ userDataPath, readFile });

  if (input.printerName === undefined) throw invalidField('printerName', input.printerName);
  let printerName: string | null;
  if (input.printerName === null) printerName = null;
  else if (typeof input.printerName === 'string' && input.printerName.trim() === '') printerName = null;
  else if (typeof input.printerName === 'string' && isSafePrinterName(input.printerName)) printerName = input.printerName.trim();
  else throw invalidField('printerName', input.printerName);

  let paper = base.paper;
  if (input.paper !== undefined) {
    if (typeof input.paper !== 'string' || !Object.prototype.hasOwnProperty.call(PAPER_PROFILES, input.paper)) throw invalidField('paper', input.paper);
    paper = input.paper as Paper;
  }

  let codePage = base.codePage;
  if (input.codePage !== undefined) {
    if (typeof input.codePage !== 'string' || !CODE_PAGES.includes(input.codePage)) throw invalidField('codePage', input.codePage);
    codePage = input.codePage as CodePage;
  }

  let cut = base.cut;
  if (input.cut !== undefined) {
    if (typeof input.cut !== 'string' || !CUTS.includes(input.cut)) throw invalidField('cut', input.cut);
    cut = input.cut as Cut;
  }

  let feedLines = base.feedLines;
  if (input.feedLines !== undefined) {
    if (!Number.isInteger(input.feedLines) || input.feedLines < 0 || input.feedLines > 10) throw invalidField('feedLines', input.feedLines);
    feedLines = input.feedLines;
  }

  let cutPerBlock = base.cutPerBlock;
  if (input.cutPerBlock !== undefined) {
    if (typeof input.cutPerBlock !== 'boolean') throw invalidField('cutPerBlock', input.cutPerBlock);
    cutPerBlock = input.cutPerBlock;
  }

  let columns: number | undefined;
  const currentColumns = toInt(current.columns);
  const hasCurrentColumns = Number.isInteger(currentColumns) && currentColumns >= MIN_COLUMNS && currentColumns <= MAX_COLUMNS;
  if (input.columns === null) columns = undefined;
  else if (input.columns !== undefined) {
    if (!Number.isInteger(input.columns) || input.columns < MIN_COLUMNS || input.columns > MAX_COLUMNS) throw invalidField('columns', input.columns);
    columns = input.columns;
  } else if (hasCurrentColumns) columns = currentColumns;

  const saved: SavedPrinterConfig = {
    version: 1,
    printerName,
    paper,
    ...(columns === undefined ? {} : { columns }),
    codePage,
    cut,
    feedLines,
    cutPerBlock,
  };
  const text = `${JSON.stringify(saved, null, 2)}\n`;
  const target = path.join(String(userDataPath), CONFIG_FILE);
  const temp = `${target}.tmp`;
  try {
    writeFile(temp, text, 'utf8');
    renameFile(temp, target);
  } catch (error) {
    throw fail('E_CONFIG_WRITE_FAILED', `No se pudo escribir ${CONFIG_FILE}.`, { cause: (error as Error)?.message });
  }
  return resolveConfig({ userDataPath, readFile: () => text });
}

export { CONFIG_FILE, DEFAULTS, CODE_PAGES, CUTS, resolveConfig, saveConfig };
