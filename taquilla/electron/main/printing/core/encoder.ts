// Pure ESC/POS command builders. No Electron, no fs, no network.
import { lineBytes, assertJobSize } from './encode';
import type { CodePage, Cut, EffectiveConfig } from '../types';

const CODE_PAGE_CMD: Record<string, number> = { cp437: 0x00, cp850: 0x02, cp858: 0x13, cp1252: 0x10 };
const ALIGN: Record<string, number> = { left: 0x00, center: 0x01, right: 0x02 };
const buf = (...bytes: number[]): Buffer => Buffer.from(bytes);

interface DocumentControl {
  codePage?: CodePage;
  feed?: number;
  cut?: boolean;
}

type DocumentItem = string | DocumentControl;

const init = (): Buffer => buf(0x1b, 0x40);
const codepage = (page: CodePage): Buffer => (CODE_PAGE_CMD[page] === undefined ? Buffer.alloc(0) : buf(0x1b, 0x74, CODE_PAGE_CMD[page]));
const align = (mode: string): Buffer => {
  if (ALIGN[mode] === undefined) throw new RangeError(`Invalid align: ${mode}`);
  return buf(0x1b, 0x61, ALIGN[mode]);
};
const bold = (on: boolean): Buffer => buf(0x1b, 0x45, on ? 0x01 : 0x00);
// Double height only: bits 0-3 are height, bits 4-7 are width (never 0x10).
const size = (double: boolean): Buffer => buf(0x1d, 0x21, double ? 0x01 : 0x00);
const feed = (lines: number): Buffer => buf(0x1b, 0x64, Number.isInteger(lines) ? Math.max(0, Math.min(255, lines)) : 0);
const cut = (mode: Cut): Buffer => {
  if (mode === 'partial') return buf(0x1d, 0x56, 0x42, 0x00);
  if (mode === 'full') return buf(0x1d, 0x56, 0x41, 0x00);
  return Buffer.alloc(0);
};

// lines: Array<string | { feed: number } | { cut: true } | { codePage: string }>
function document({ lines = [], config = {} }: { lines?: DocumentItem[]; config?: Partial<EffectiveConfig> } = {}): Buffer {
  const columns = config.columns ?? 32;
  const cutMode: Cut = config.cut ?? 'partial';
  let activePage: CodePage = config.codePage ?? 'cp850';
  const parts = [init(), codepage(activePage)];
  let titlePending = true;

  for (const item of lines) {
    if (item !== null && typeof item === 'object' && !Buffer.isBuffer(item)) {
      if (item.codePage) {
        activePage = item.codePage;
        parts.push(codepage(activePage));
      }
      if (Number.isInteger(item.feed)) parts.push(feed(item.feed as number));
      if (item.cut) parts.push(cut(cutMode));
      continue;
    }
    const bytes = lineBytes(String(item), { codePage: activePage, columns });
    if (titlePending) parts.push(align('center'), bold(true), size(true), bytes, buf(0x0a), size(false), bold(false), align('left'));
    else parts.push(bytes, buf(0x0a));
    titlePending = false;
  }

  const finalFeed = cutMode === 'none' ? 5 : (Number.isInteger(config.feedLines) ? (config.feedLines as number) : 3);
  parts.push(feed(finalFeed), cut(cutMode));
  const out = Buffer.concat(parts);
  assertJobSize(out);
  return out;
}

export { CODE_PAGE_CMD, init, codepage, align, bold, size, feed, cut, document };
