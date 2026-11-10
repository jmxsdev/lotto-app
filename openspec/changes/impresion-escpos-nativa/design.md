# Design: Native ESC/POS printing for the taquilla

**Status**: implementation-ready · **Change**: `impresion-escpos-nativa` · **Baseline**: `feature/impresion-escpos` @ `3855a43`

The taquilla replaces Chromium raster printing with a pure ESC/POS byte pipeline in the
Electron main process. **The primary target is a 58 mm thermal printer: 384 dots, 32
columns (Font A, 12×24).** 80 mm / 48 columns stays supported as an opt-in profile. The
renderer contract is untouched: the same three IPC channels (`print-ticket`,
`print-cierre`, `print-reporte`), the same payloads, the same `{ success, message }`
result. All layout, formatting, encoding and transport decisions live in
`taquilla/electron/main/printing/`; the existing HTML generators survive only as an
operator-only rollback (`LOTTO_PRINT_MODE=html`) until S5 deletes them.

## Technical approach

```
event.sender.getPrintersAsync() ─┐
printer-config.json + env ───────┼─→ resolvePrinter → buildBuffer → serialized sendRaw
                                 │        (pure)         (spooler)
```

- Pure modules (no `electron`, no `fs`, no `electron-pos-printer`) produce the exact byte
  buffer; tests compare golden hex with `node --test`, no hardware.
- Impure modules own config IO, printer enumeration and spool submission.
- No new runtime dependency. `iconv-lite` is absent from `taquilla/pnpm-lock.yaml`, so the
  CP850/CP437/CP858/CP1252 Spanish set is a small explicit table.

## Architecture decisions

| # | Decision | Options considered | Choice / rationale |
|---|---|---|---|
| D1 | Byte source | HTML raster · library structured API · own encoder | Own pure encoder. `PosPrinter.print()` is HTML-in-BrowserWindow (`dist/index.js:1`); only `sendRawCommand` is raw. Deterministic bytes are the fix. |
| D2 | Module split | One `encoder.cjs` · command/encoding split | `core/` (encode, encoder, format, layout, profiles), `infra/` (config, discovery, transport, lock), `documents/` (ticket, recibo, cierre, reporte, printTest). Each pure file stays ≲140 authored lines and is independently golden-testable. |
| D3 | Transport | Spooler raw · network 9100 · native addon | `sendRawCommand` is the only implementation. `tcp9100` and a native addon are documented future escape hatches behind the stable `sendRaw` seam — no code, config keys or tests in this change. |
| D4 | Failure policy | Auto-HTML fallback · error only | Error only. A partial raw job plus HTML fallback duplicates the document. `html` mode is chosen **before** any raw attempt. |
| D5 | Printer selection | Substring auto-detect · exact name | Exact queue name from `getPrintersAsync`; env `LOTTO_PRINTER_NAME` → `printer-config.json` → actionable error listing queues. No default-printer guess (`PrinterInfo` has no typed `isDefault`). |
| D6 | Paper geometry | 58/80 enum · enum + override | **58 mm → 32 cols is the default** (owner-confirmed); `80mm`→48 and `80mm-512`→42 are opt-in, plus explicit `columns` override. 512 dots ÷ 12 = 42.6 → 42. |
| D7 | Encoding ladder | ICU/`iconv-lite` · explicit table | Explicit CP850 map (CP437 shares it), CP858 (+€), CP1252, `ascii` transliteration. CP850 → CP858 → CP1252 → `ascii` is an **operator-selected commissioning ladder** (config + `print:test`), not an automatic runtime sequence; `ESC t` rejection is not observable through the spooler. |
| D8 | Money/date | `toLocaleString` · deterministic formatter | Deterministic: dot thousands, comma decimals, 2 fixed decimals, fixed `-04:00` for ISO timestamps. No Intl in the print path. |
| D9 | Print concurrency | Fire-and-forget · serialized queue · single-flight lock | **Single-flight lock** (`infra/lock.cjs`). The Windows spooler already serializes jobs per printer, the UI blocks sale prints, and a single operator does not produce 20 concurrent tickets, so a waiting mechanism adds surface without benefit. `createLock()` returns `{ run(task), isBusy() }`; a second `run` while busy rejects `E_PRINT_BUSY`; the lock always releases in `finally`; a non-function task rejects `E_PRINT_LOCK_INVALID`. No waiting, no cap. |
| D10 | Rollback | Keep HTML forever · delete after sign-off | `html` mode + generators survive S3a–S4b, deleted in S5 after hardware sign-off. |
| D11 | Renderer changes | New channels/UI · none | None. Handlers stay thin; preload and `.astro` untouched. |

## Module tree

```
taquilla/electron/main/printing/
├── types.ts             # shared contracts (PrintResult, EffectiveConfig, LayoutOp, ...)
├── core/                # PURE
│   ├── encode.ts        # code-page tables, sanitize, transliterate, encodeText/line
│   ├── encoder.ts       # ESC/POS command builders (init/align/bold/size/codepage/feed/cut)
│   ├── format.ts        # formatMoney, formatDate, formatTime, normalizeDate/TimeText
│   ├── layout.ts        # pad, truncate, wrap, row, rule, stacked, kv
│   └── profiles.ts      # paper profiles, resolveProfile, column validation
├── infra/               # IMPURE
│   ├── config.ts        # env + printer-config.json resolution/validation
│   ├── discovery.ts     # exact-queue resolution via getPrintersAsync
│   ├── transport.ts     # spooler adapter (sendRawCommand) only
│   └── lock.ts          # single-flight lock (createLock, no IO)
├── documents/           # PURE document builders
│   ├── ticket.ts        # sale ticket (single/multi-game, optional Hora)
│   ├── recibo.ts        # prize receipt (stacked, 32/48)
│   ├── cierre.ts        # close (totals, arqueo, breakdown)
│   ├── reporte.ts       # range report (repeat block per close)
│   └── printTest.ts     # commissioning slip builder only (printTestSlip)
├── index.ts             # composition root: createPrinting, printDocument, builder map, buildBuffer
├── dist/                # esbuild CJS output (runtime target, not hand-edited)
│   ├── package.json     # {"type":"commonjs"} so extensionless require resolves .js
│   └── **/*.js
└── test/                # unchanged .cjs tests, run with node --test
    ├── purity.test.cjs
    ├── core/*.test.cjs
    ├── infra/*.test.cjs
    └── documents/*.test.cjs
```

**S6 migration note**: S1–S4 authored the sources as `.cjs`; S6 renames them to `.ts` with
no behavior change (mechanical migration). The runtime consumes `dist/**/*.js`, built by
`scripts/build-printing.mjs` (esbuild, `format: cjs`). Tests stay `.cjs` and require
`dist/**/*.js`.

**Deviation from the proposal** (justified): the proposal named `encoder.cjs`, `format.cjs`,
`config.cjs`, `transport.cjs` and `layout{ticket,recibo,cierre,reporte}.cjs` at the root.
This design groups pure byte/geometry code under `core/`, IO/transport under `infra/`,
documents under `documents/`, tests under `test/`, and keeps `index` as the composition
root. The former serialization module is replaced by `infra/lock` (single-flight).
`documents/` was created in S3a with `ticket`.

**Dependency rule** (enforced by review + `test/purity.test.cjs`): `core/*.ts` and
`documents/*.ts` must not import `electron`, `fs`, `node:net` or `electron-pos-printer`.
The scan covers `core/` and, when present, `documents/`; it **never** scans `infra/`.
Package is `"type": "module"`, so `.ts` sources are bundled to CJS in `dist/`.

**print:test split**: `documents/printTest.ts` exports only `printTestSlip(config)` (pure
ops). The impure runner lives in `index.ts` as
`runPrintTest({ config, resolvePrinter, sendRaw, webContents })` and is reached through
`createPrinting().maybeRunStartupTest()`. No impure code is added to the builder, so the
static purity scan covers the whole file with no exclusion list.

## Data flow

```
Renderer (unchanged)                         Main process
────────────────────                         ────────────────────────────────────────────
window.electron.printTicket(data)
  → ipcRenderer.invoke('print-ticket')  ──→  ipcMain.handle (validation only)
                                              │
                                              ├─ resolveConfig()  env > userData file > defaults
                                              │     mode === 'html' ? legacy() : continue
                                              ├─ resolvePrinter(name, event.sender.getPrintersAsync)
                                              ├─ buildBuffer({ kind, data, config }) [PURE]
                                              │     documents/<kind> → layout.row/pad/wrap
                                              │                    → encode.line (configured code page)
                                              │                    → encoder.* (commands)
                                              ├─ lock.run(() => sendRaw(name, buffer))
                                              │            spooler → PowerShell System.Printing
                                              └─ { success, message }
```

## Interfaces / contracts

```js
// Result contract — identical to today.
/** @typedef {{ success: boolean, message: string }} PrintResult */

// Success messages — the renderer renders res.message, so the text is preserved verbatim.
const KIND_META = {
  ticket:  { noun: 'Ticket',  layout: 'ticket'  },  // print-ticket, kind absent
  recibo:  { noun: 'Ticket',  layout: 'recibo'  },  // print-ticket + kind:'recibo'
  cierre:  { noun: 'Cierre',  layout: 'cierre'  },
  reporte: { noun: 'Reporte', layout: 'reporte' },
};
// success message = `${noun} enviado a impresora`
// (ticket|recibo → "Ticket enviado a impresora", cierre → "Cierre enviado a impresora",
//  reporte → "Reporte enviado a impresora"; assert these exact strings in index.test.cjs)

// index.ts (source; runtime is dist/index.js)
function createPrinting({ env, userDataPath, getWindow, sendRaw, lock = createLock() } = {}) → {
  printDocument({ kind, data, webContents, legacy }) → Promise<PrintResult>,
  maybeRunStartupTest()                              → Promise<PrintResult|null>,
  // internal: runPrintTest({ config, resolvePrinter, sendRaw, webContents }) in index.ts
  // (impure runner; documents/printTest.ts stays pure)
  resolveEffectiveConfig()                           → EffectiveConfig,   // diagnostics/tests
  isHtmlMode()                                       → boolean,
}

// infra/lock.ts — single-flight, no waiting queue, no cap
function createLock() → {
  run(task) → Promise<unknown>,   // rejects E_PRINT_BUSY when busy;
                                   // rejects E_PRINT_LOCK_INVALID when task is not a function
  isBusy() → boolean,             // true only while a task is executing
}
// run() calls task() immediately, sets busy, and releases in finally on success or failure.
// There is no waiting list of any kind: a second caller fails fast instead of waiting.
// kind: 'ticket' | 'recibo' | 'cierre' | 'reporte'
// legacy: optional (data) => Promise<PrintResult>; only used when mode === 'html'

function buildBuffer({ kind, data, config }) → Buffer   // PURE, exported for tests
// `config` is the full EffectiveConfig object (see below); the builder reads exactly:
//   config.columns, config.dots, config.codePage, config.cut, config.feedLines,
//   config.cutPerBlock
// No other config field influences bytes. `index.test.cjs` passes the same object to
// buildBuffer and to printDocument fakes.
function resolveConfig({ env, userDataPath })  → EffectiveConfig  // infra/config.ts
function resolvePrinter({ printerName, getPrintersAsync }) → Promise<{ name: string }> // infra/discovery.ts
function sendRaw(printerName, buffer, { timeoutMs=15000 } = {}) → Promise<void> // infra/transport.ts
function printTestSlip(config) → Array<string | { codePage: string } | { feed: number } | { cut: true }> // PURE builder, documents/printTest.ts

// EffectiveConfig
{
  printerName: string|null, mode: 'escpos'|'html',
  paper: '58mm'|'80mm'|'80mm-512', columns: number, dots: number,
  codePage: 'cp437'|'cp850'|'cp858'|'cp1252'|'ascii',
  cut: 'partial'|'full'|'none', feedLines: number, cutPerBlock: boolean,
  warnings: string[],
}
// Defaults: paper '58mm', columns 32, dots 384, codePage 'cp850', cut 'partial',
// feedLines 3, cutPerBlock true, mode 'escpos'. 80 mm (48 cols) is opt-in only.

// Layout AST returned by every layout builder (pure):
//   Array<string | { feed: number } | { cut: true } | { codePage: string }>
```

Errors: `class PrintError extends Error { code, details }` with codes
`E_CONFIG_INVALID`, `E_PRINTER_UNCONFIGURED`, `E_PRINTER_NOT_FOUND`, `E_NO_WEBCONTENTS`,
`E_DISCOVERY_TIMEOUT`, `E_LAYOUT_OVERFLOW`, `E_PAYLOAD_INVALID`, `E_PAYLOAD_TOO_LARGE`,
`E_TRANSPORT_FAILED`, `E_TRANSPORT_TIMEOUT`, `E_PRINT_BUSY`, `E_PRINT_LOCK_INVALID`.
`printDocument` never throws: every error maps to `{ success: false, message }`.

### Call chain per channel

```js
// ipcHandlers.cjs — validation text preserved verbatim.
ipcMain.handle('print-ticket', async (event, data) => {
  const { ticketData } = data;
  if (!ticketData || !ticketData.lines || ticketData.lines.length === 0)
    return { success: false, message: 'Datos de ticket invalidos' };
  const kind = ticketData.kind === 'recibo' ? 'recibo' : 'ticket';
  return printing.printDocument({ kind, data: ticketData, webContents: event.sender, legacy });
});

ipcMain.handle('print-cierre', async (event, data) => {
  const { cierreData } = data;
  if (!cierreData || typeof cierreData !== 'object' || Array.isArray(cierreData))
    return { success: false, message: 'Datos de cierre invalidos' };
  return printing.printDocument({ kind: 'cierre', data: cierreData, webContents: event.sender, legacy });
});

ipcMain.handle('print-reporte', async (event, data) => {
  const { reporteData } = data;
  if (!reporteData || typeof reporteData !== 'object' || Array.isArray(reporteData))
    return { success: false, message: 'Datos de reporte invalidos' };
  return printing.printDocument({ kind: 'reporte', data: reporteData, webContents: event.sender, legacy });
});
```

## ESC/POS command layer (`encoder.cjs`)

| Purpose | Sequence | Bytes |
|---|---|---|
| Initialize | `ESC @` | `1B 40` |
| Select code page | `ESC t n` | `1B 74 n` — cp437 = decimal 0 (`0x00`), cp850 = 2 (`0x02`), cp858 = 19 (`0x13`), cp1252 = 16 (`0x10`) |
| Align left/center/right | `ESC a n` | `1B 61 00` / `01` / `02` |
| Bold on/off | `ESC E n` | `1B 45 01` / `1B 45 00` |
| Double height on/off | `GS ! n` | `1D 21 01` / `1D 21 00` (bits 0–3 = height, bits 4–7 = width) |
| Feed *n* lines | `ESC d n` | `1B 64 nn` |
| Cut partial | `GS V 66 0` | `1D 56 42 00` |
| Cut full | `GS V 65 0` | `1D 56 41 00` |
| Cash drawer (out of scope) | `ESC p m t1 t2` | `1B 70 00 19 FA` / `1B 70 01 19 FA` — never emitted |

Document sequence (defaults in **bold**):

```
1B 40                       init, once per document
1B 74 <n>                   only when codePage !== 'ascii'
<line bytes> 0A             one LF per line; never CRLF, never CR
1B 64 <finalFeed>           final feed: config.feedLines (default 3); 5 when cut:'none'
1D 56 42 00                 only when cut !== 'none'; full → 1D 56 41 00
```

**Feed policy (single-valued, one owner per value):**

| Value | Owner | Default | Applied |
|---|---|---|---|
| `feedLines` | `config.cjs` — env `LOTTO_PRINT_FEED`, integer 0–10 | **3** | Final feed after the last line of ticket, recibo, cierre and the whole reporte |
| `blockFeed` | builder constant in `documents/reporte.cjs` (`BLOCK_FEED = 2`), **not configurable** | **2** | `{ feed: 2 }` before each intermediate cut between close blocks |
| `cut:'none'` final feed | `buildBuffer` override | **5** | Replaces `feedLines` for the final feed only; no `GS V` anywhere and `cutPerBlock` is ignored |

`buildBuffer` reads `config.feedLines`; it never derives a second feed value. The reporte
builder emits `{ feed: 2 }` sentinels from its own `BLOCK_FEED` constant, so the config
schema has no `blockFeed` key. Golden expectations: ticket/cierre/reporte end with
`1B 64 03` + `1D 56 42 00`; a 2-close report has exactly one `1B 64 02` + `1D 56 42 00`
between blocks; `cut:'none'` ends with `1B 64 05` and no `1D 56`.

- Titles: center + bold + **double height only** (`1D 21 01`; reset `1D 21 00`). In
  `GS ! n`, bits 0–3 are height and bits 4–7 are width, so `1D 21 10` would be double
  **width** and is never emitted because it halves the effective column count and would
  break column math. All golden buffers use `1D 21 01` for the title.
- **No cutter**: `cut: 'none'` follows the feed table (final feed 5, no `GS V`). Detection
  is impossible over the spooler, so this is explicit config, confirmed by `print:test`.
- **Long reports**: `documents/reporte.cjs` emits `{ feed: BLOCK_FEED }` + `{ cut: true }`
  after each close block when `cutPerBlock` is true (default). That is the only mid-job
  cut; a block never exceeds one page of continuous paper. `print:test` validates the same
  sequence.
- Cash drawer remains out of scope and is never sent, even on sale success.

## Encoding (`encode.cjs`)

CP850 bytes for the Spanish set (the same bytes in CP437, so one table serves both):

```js
const SHARED = {
  'ü':0x81, 'é':0x82, 'É':0x90, 'Ü':0x9A, 'á':0xA0, 'í':0xA1, 'ó':0xA2, 'ú':0xA3,
  'ñ':0xA4, 'Ñ':0xA5, '¿':0xA8, '¡':0xAD, 'Á':0xB5, 'Í':0xD6, 'Ó':0xE0, 'Ú':0xE9,
  '°':0xF8,
};
const CP858_EXTRA = { '€':0xD5 };
const CP1252 = {
  '¡':0xA1, '°':0xB0, '¿':0xBF, 'Á':0xC1, 'É':0xC9, 'Í':0xCD, 'Ñ':0xD1, 'Ó':0xD3,
  'Ú':0xDA, 'Ü':0xDC, 'á':0xE1, 'é':0xE9, 'í':0xED, 'ñ':0xF1, 'ó':0xF3, 'ú':0xFA,
  'ü':0xFC,
};
const ASCII_FOLD = { 'á':'a','é':'e','í':'i','ó':'o','ú':'u','ü':'u','ñ':'n','Ñ':'N',
  'Á':'A','É':'E','Í':'I','Ó':'O','Ú':'U','Ü':'U','¿':'?','¡':'!','°':'o','€':'E' };
```

- `codePage` mapping (decimal = hex): `cp437` → `ESC t 0` (`0x00`), `cp850` → `ESC t 2`
  (`0x02`), `cp858` → `ESC t 19` (`0x13`), `cp1252` → `ESC t 16` (`0x10`), `ascii` → no
  `ESC t` and no high bytes.
- `sanitize(text)`: `String(x ?? '')`; **every newline sequence (`\r\n`, `\r`, `\n`) becomes a
  single space**, tab becomes a space, then remove the remaining C0 controls
  (`\u0000-\u001F`; LF was already replaced), `\u007F`, `\u0080-\u009F`, plus zero-width and
  line separators (`\u200B-\u200F`, `\u2028`, `\u2029`). LF exists **only** as the explicit
  line separator appended by `buildBuffer`; `encode.line` asserts the payload contains no
  LF. This explicitly removes `ESC 0x1B`, `FS 0x1C` and `GS 0x1D`, so free text
  (`referencia`, `concepto`, names, `jugada`, `estado`) can never inject a command or break
  a line. A `referencia` of `"A\nB"` therefore renders as one bounded `A B` line. HTML
  escaping is irrelevant on this channel.
- `encodeText(text, codePage)`: map each code point (identity for `0x20–0x7E`); miss →
  ASCII fold → if still a miss, `?` (`0x3F`). Return a `Buffer`; 1 char = 1 byte.
- `lineBytes(line, { codePage, columns })` truncates to `min(columns, MAX_LINE_CHARS = 96)`
  as a safety net (layouts already wrap) and `MAX_JOB_BYTES = 512 * 1024` →
  `E_PAYLOAD_TOO_LARGE` before any transport attempt.
- **No automatic code-page sequence**: `ESC t` acceptance cannot be read back from the
  spooler, so the runtime emits exactly the configured page and nothing else. The
  CP850 → CP858 → CP1252 → `ascii` order is a **commissioning/operator ladder**:
  `print:test` prints the four candidates, the operator pins the working one via
  `LOTTO_PRINT_CODEPAGE` or `printer-config.json`, and `ascii` is the explicit last resort
  when none renders. `print:test` closes the unknown hardware; the runtime never guesses.
- **Proposal wording note**: the proposal's "CP858 then CP1252 fallback" describes that
  commissioning order, not runtime probing — the runtime never retries a code page. This
  design and the corrected `impresion-escpos` spec are authoritative over that wording.
- `print:test` slip: it prints the identical accent line four times, each preceded by a
  different `ESC t`, labelled `CP437/CP850/CP858/CP1252`, plus a hex-ish line. Exactly one
  renders correctly; the operator pins it. If none renders, `ascii` is selected manually.
- Spanish set max width: `°` and accents are single bytes, so column math stays valid.

## Deterministic formatters (`format.cjs`)

```js
// String-based half-up: Math.round(1.005 * 100) === 100 because the double is
// 1.00499999..., so rounding is done on the shortest round-trip decimal string.
function toCents(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return { neg: false, cents: 0 };
  const neg = n < 0;
  const s = Math.abs(n).toString();                 // 1.005 -> "1.005"
  if (s.includes('e') || s.includes('E')) {         // >= 1e21 only; documented fallback
    return { neg, cents: Math.round(Math.abs(n) * 100) };
  }
  const [intPart, fracPart = ''] = s.split('.');
  const frac = (fracPart + '000').slice(0, 3);      // third digit decides half-up
  let cents = Number(intPart) * 100 + Number(frac.slice(0, 2));
  if (Number(frac[2]) >= 5) cents += 1;             // 1.005 -> 101 -> "1,01"
  return { neg, cents };
}

function formatMoney(value) {
  const { neg, cents } = toCents(value);
  const int = String(Math.floor(cents / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const frac = String(cents % 100).padStart(2, '0');
  return (neg && cents !== 0 ? '-' : '') + int + ',' + frac; // 1.234.567,89 · -12,50 · 0,00
}
```

Half-up is applied to the third decimal; digits beyond it are truncated. Values in
exponential notation (≥ 1e21, outside the business range) use the documented scaled
fallback. `-0,00` is normalized to `0,00`.

`formatDate(iso)` / `formatTime(iso)`:

- Input classes are handled deterministically:
  - `YYYY-MM-DD` (report range dates) → reorder to `dd/mm/yyyy`; **no timezone shift**.
  - `YYYY-MM-DD HH:mm[:ss[.fff]]` with no `Z`/offset → treated as Caracas wall time; **no
    shift**.
  - Instant with `Z` or `±HH:mm` (close/report timestamps) → shift by the fixed Caracas
    offset `-240` minutes and read with UTC getters. Laravel microsecond stamps
    (`2026-11-09T14:30:00.000000Z`) are normalized first:
    `iso.replace(/\.(\d{3})\d+/, '.$1')` so `Date.parse` is reliable.
  - Already-localized ticket strings (`ticketData.date` = `DD/MM/YYYY`,
    `ticketData.time` = `HH:MM:SS a. m.`) are parsed, not re-localized:
    `normalizeDateText` zero-pads parts; `normalizeTimeText` converts 12 h +
    `a. m./p. m.` to 24 h and drops seconds (`10:30:15 p. m.` → `22:30`,
    `12:00:00 a. m.` → `00:00`, `12:00:00 p. m.` → `12:00`).
  - Anything else → sanitized, width-bounded text, never dropped.
- Output is identical on every machine and does not depend on OS locale/ICU. Layouts
  compose `Fecha: ${formatDate(x)} ${formatTime(y)}`; no `Intl`, no `toLocaleString`, no
  `Date#toLocale*` anywhere under `printing/`.
- Width rule: a formatted money value is **never truncated**. The largest business value is
  `-1.234.567,89` (13 chars including sign). Each layout budgets the money column from the
  actual values; when the budget cannot hold 13 chars it switches that row to a stacked
  two-line form. Goldens include a 7-digit amount (`1.234.567,89`) at 32 and 48 columns for
  cierre/report to prove the overflow path. `E_LAYOUT_OVERFLOW` is a test-only guard.

## Paper profiles and layouts

`profiles.cjs`: `58mm → 384 dots / 32 cols` (**default, primary target**); `80mm → 576 / 48`
(opt-in); `80mm-512 → 512 / 42` (opt-in, 512-dot 80 mm units); explicit `columns`
(integer 20–80) overrides. `dots` is informational for the ruler. The default
`EffectiveConfig` is `paper: '58mm'`, `columns: 32`, `dots: 384`.

**`print:test` ruler (32 default):** `printTestSlip(config)` emits a digits line and a
mark line, both exactly `config.columns` chars: digits cycle `1..0`; the mark line puts `|`
at positions 0, 5, 10, … and `.` elsewhere, truncated to the column budget. Default
(32 cols):

```
12345678901234567890123456789012
|....|....|....|....|....|....|.
```

If the owner sets `LOTTO_PRINT_COLUMNS=48` (or `paper=80`), the ruler follows the override
and must end exactly at column 48. This is the first hardware check: the physical print
must not wrap or clip the mark line.

`layout.cjs` primitives (all pure, all code-point safe via `Array.from`):

| Function | Contract |
|---|---|
| `pad(text, width, align)` | exact `width`; `left`/`right`/`center`; throws if `width < 0` |
| `truncate(text, width)` | first `width` code points; never splits a surrogate pair |
| `wrap(text, width)` | `string[]`; breaks on spaces, hard-splits words longer than `width` |
| `row(cells, widths, aligns, columns)` | returns exactly `sum(widths)` chars; **asserts** `sum(widths) === columns` and equal array lengths |
| `rule(width, ch='-')` | single rule line |
| `kv(label, value, columns)` | `label` left, `value` right, dot-leader not used |
| `stacked(label, value, columns, indent=2)` | `label` line then indented value line |

Invariants: every emitted line length `≤ columns`; `sum(widths) === columns`; continuation
lines are indented so they never look like a new row (2–4 spaces for row/money
continuations, 13 spaces for receipt field continuations). Emphasis policy is fixed so
goldens stay stable: `buildBuffer` centers and bolds **only the first line** (the document
title); every other line prints normal. Group and currency headings are plain rows. No
other emphasis is emitted.

### Example renderings (reference for golden tests)

Width arrays are normative; exact space counts are pinned by the golden tests in S1,
S3a/S3b and S4b and may be tightened without changing the algorithms. **32-column examples
are the product path (58 mm, 384 dots); 48-column examples document the opt-in 80 mm
profile.** At 32 columns the stacked forms are the normal layout, not a degraded fallback.

**32 cols — single-game ticket (primary, 58 mm / 384 dots)**:

Format contract: every bet is **two lines** — line 1 is `index + jugada`, line 2 is
`Hora?  Bs <value>  $ <value>` right-aligned. A `jugada` longer than 29 columns wraps with
a 4-space continuation. If both money values are 13 chars the pair splits into one
right-aligned line per currency (worst case: 4 lines per bet). Totals stay right-aligned;
no potential-prize field is ever emitted.

```
          LOTTO TICKET
           Animalitos
--------------------------------
Ticket: A1B2C3
Fecha: 09/11/2026 14:30
Tipo de pago: Pago movil
--------------------------------
 1 Perro #14
               Bs 150,00  $ 0,00
 2 Gato #07
                Bs 50,00  $ 0,00
--------------------------------
Total BS:           Bs. 150,00
Total USD:                $ 0,00
--------------------------------
   Gracias por su compra!
```

Exact line contract:
- First line: `pad(' ' + n, 3) + wrap(jugada, 29)[0]`; continuations: `'    ' + rest`.
- Money line: left part `'    ' + (hora ? pad(hora, 5) + '  ' : '    ')`; money segment
  `Bs <money>  $ <money>` is right-aligned to column 32. If
  `left.length + segment.length > 32`, emit `Bs` and `$` on separate right-aligned lines.
- Totals: `kv('Total BS:', 'Bs. ' + money, 32)`; if it does not fit, `stacked()`.

**32 cols — multi-game with `Hora` and a wrapped `jugada`**:

```
          LOTTO TICKET
   Animalitos, Tripleta
--------------------------------
-- Animalitos
 1 Perro #14
    14:30      Bs 100,00  $ 0,00
 2 Gato #07
    14:30       Bs 50,00  $ 0,00
-- Tripleta Zodiacal
 3 Triple C #157
    Sagitario
    16:00      Bs 200,00  $ 0,00
--------------------------------
Total BS:           Bs. 350,00
Total USD:                $ 0,00
--------------------------------
   Gracias por su compra!
```

Group rows are `-- <game>` at column 0, wrapped by `wrap(name, 32)`, with no amount, so the
money alignment of the following bets is unaffected.

**48 cols — ticket (opt-in 80 mm, secondary)** (single-game widths `[2, 22, 12, 12]`;
multi-game with `Hora` `[2, 18, 6, 11, 11]`; long jugada wraps with a 3-space
continuation). Single-game uses the same table without the `Hora` column:

```
              LOTTO TICKET
        Animalitos, Tripleta Zodiacal
------------------------------------------------
 #Jugada            Hora         BS         USD
 -- Animalitos
  1 Perro #14       14:30    100,00        0,00
  2 Gato #07        14:30     50,00        0,00
 -- Tripleta Zodiacal
  3 Triple C #157
     Sagitario      16:00    200,00        0,00
```

**32 cols — receipt (primary, 58 mm)**:

Exact stacked block per bet: game line (`' ' + pad(n,2) + ' ' + wrap(game, 27)`), then four
labelled fields with prefix `'    ' + pad(label, 7) + ': '` (13 chars, 19 usable). `Jugada`
wraps at 19 with a 13-space continuation. `Monto`/`Premio` join the pair as
`Bs <value> / $ <value>` when it fits in 19; otherwise split one currency per line,
indented 13. Totals use `kv`; when the label + value exceeds 32 they use `stacked`.

```
        COMPROBANTE DE PAGO
              A1B2C3
--------------------------------
Fecha: 10/11/2026 09:15
--------------------------------
  1 Aguila
    Jugada : Perro #14
    Estado : PAGADA
    Monto  : Bs 100,00 / $ 0,00
    Premio : Bs 1.234.567,89
             $ 1.234.567,89
--------------------------------
Total premio BS:
  Bs. 1.234.567,89
Total premio USD:
  $ 1.234.567,89
--------------------------------
     Premio pagado - Gracias!
```

**48 cols — receipt (opt-in, secondary)** (8 fields stacked; money pair `[20, 28]`):

```
            COMPROBANTE DE PAGO
                 A1B2C3
------------------------------------------------
Fecha: 10/11/2026 09:15
------------------------------------------------
  1 Aguila
    Jugada : Perro #14
    Estado : PAGADA
    Monto  : Bs 100,00            $ 0,00
    Premio : Bs 1.500,00          $ 0,00
------------------------------------------------
Total premio BS: Bs. 1.500,00
Total premio USD: $0,00
------------------------------------------------
          Premio pagado - Gracias!
```

**32 cols — close (primary, 58 mm)**: four exact sections — header, `TOTALES`, `ARQUEO /
DIFERENCIA`, `DESGLOSE POR METODO`. Totals/arqueo print the concept on its own line, then
`  BS` and `  USD` with the money right-aligned to 32 (`'  ' + currency + ' ' + money`,
right-aligned). The breakdown prints a currency heading and, per method, the method on its
own line plus `Ventas`/`Egresos`/`Efectivo` as `'   ' + pad(label, 8)` + money right-aligned
to 32. The four methods repeat for **both** currencies; `concepto` is retained. A 7-digit
amount fits in the stacked form without truncation.

```
          CIERRE DE CAJA
            Taquilla 1
--------------------------------
Desde: 09/11/2026 08:00
Hasta: 09/11/2026 18:00
Tasa: 40,00
Referencia: C-1042
Concepto: Cierre turno tarde
--------------------------------
TOTALES
Ventas
  BS                   12.500,00
  USD                     312,50
Egresos
  BS                    1.200,00
  USD                      30,00
Efectivo
  BS                   11.300,00
  USD                     282,50
--------------------------------
ARQUEO / DIFERENCIA
Arqueo
  BS                   11.250,00
  USD                     281,25
Falt./Sobr.
  BS                      -50,00
  USD                      -1,25
--------------------------------
DESGLOSE POR METODO
BS
 Efectivo
   Ventas              10.000,00
   Egresos                500,00
   Efectivo         1.234.567,89
 Transferencia
   Ventas               2.000,00
   Egresos                400,00
   Efectivo             1.600,00
 Pago Movil
   Ventas                 500,00
   Egresos                300,00
   Efectivo               200,00
USD
 Efectivo
   Ventas                  50,00
   Egresos                 10,00
   Efectivo                40,00
--------------------------------
Cierre #42 - Maria Perez
```

**48 cols — close (opt-in, secondary)** (totals `[18, 15, 15]`, breakdown
`[14, 12, 11, 11]`; the `Mon` column is gone, replaced by the `BS` / `USD` heading; the
four methods repeat for **both** currencies and `concepto` is retained, matching today's
`generateCierreHtml`; a 7-digit value exceeds an 11-char breakdown slot and falls back to
the same stacked row used at 32):

```
                CIERRE DE CAJA
                  Taquilla 1
------------------------------------------------
Desde: 09/11/2026 08:00
Hasta: 09/11/2026 18:00
Tasa: 40,00
Referencia: C-1042
Concepto: Cierre turno tarde
------------------------------------------------
TOTALES
Concepto                BS              USD
Ventas           12.500,00           312,50
Egresos           1.200,00            30,00
Efectivo         11.300,00           282,50
------------------------------------------------
ARQUEO / DIFERENCIA
Arqueo           11.250,00           281,25
Falt./Sobr.         -50,00            -1,25
------------------------------------------------
DESGLOSE POR METODO
BS
Metodo             Ventas      Egresos    Efectivo
Efectivo        10.000,00       500,00    9.500,00
Transferencia    2.000,00       400,00    1.600,00
Pago Movil         500,00       300,00      200,00
USD
Metodo             Ventas      Egresos    Efectivo
Efectivo            50,00        10,00       40,00
Transferencia       20,00         0,00       20,00
Pago Movil          10,00         5,00        5,00
------------------------------------------------
Cierre #42 - Maria Perez
```

**32 cols — range report (primary, 58 mm)**: header, fused `TOTALES` / `DESGLOSE
FUSIONADO` using the same stacked sections as the close, then one block per close. Each
block ends with `BLOCK_FEED = 2` + partial cut when `cutPerBlock` is true; the document ends
with `feedLines = 3`. A 7-digit amount is shown in the fused breakdown.

```
        REPORTE POR RANGO
            Taquilla
--------------------------------
Desde: 01/11/2026
Hasta: 07/11/2026
Cierres incluidos: 12
Cubierto: 01/11/2026 08:00
          07/11/2026 18:00
--------------------------------
TOTALES
Ventas
  BS                   12.500,00
  USD                     312,50
Egresos / Efectivo     (same stacked form)
DESGLOSE FUSIONADO
BS
 Efectivo
   Ventas              10.000,00
   Egresos                500,00
   Efectivo         1.234.567,89
 Transferencia / Pago Movil (same stacked form)
USD
 Efectivo
   Ventas                  50,00
   Egresos                 10,00
   Efectivo                40,00
--------------------------------
=== Cierre #42 ===
Taquilla #3
Desde: 07/11/2026 08:00
Hasta: 07/11/2026 18:00
Tasa: 40,00
Ventas / Egresos / Efectivo (stacked)
Arqueo / Falt./Sobr. (stacked)
Desglose BS / USD (stacked)
<BLOCK_FEED = 2 + partial cut>
=== Cierre #43 ===
...
--------------------------------
<final feedLines = 3>
```

**48 cols — range report (opt-in, secondary)**; the fused block is identical to the close,
then one block per close, each terminated by feed + cut:

```
            REPORTE POR RANGO
                Taquilla
------------------------------------------------
Desde: 01/11/2026
Hasta: 07/11/2026
Cierres incluidos: 12
Cubierto: 01/11/2026 08:00 - 07/11/2026 18:00
------------------------------------------------
TOTALES / DESGLOSE FUSIONADO
   (same tables as the close)
------------------------------------------------
=== Cierre #42 - Taquilla #3 ===
Desde: 07/11/2026 08:00
Hasta: 07/11/2026 18:00
Tasa: 40,00
Ventas / Egresos / Efectivo
Arqueo / Falt./Sobr.
Desglose (BS / USD headings)
<feed 2 + partial cut>
=== Cierre #43 - Taquilla #3 ===
...
```

## Config and discovery

Env keys (all optional; defaults in **bold**):

| Key | Values | Meaning |
|---|---|---|
| `LOTTO_PRINTER_NAME` | queue name | exact spooler queue; highest precedence |
| `LOTTO_PRINT_MODE` | **`escpos`** · `html` | `html` = rollout escape hatch only |
| `LOTTO_PRINT_TEST` | `1` | print the commissioning slip once at startup |
| `LOTTO_PRINT_PAPER` | **`58`** · `80` · `80-512` | paper profile (58 → 32 cols default; 80 → 48; 80-512 → 42) |
| `LOTTO_PRINT_COLUMNS` | integer 20–80 | explicit override (wins over profile) |
| `LOTTO_PRINT_CODEPAGE` | **`cp850`** · `cp437` · `cp858` · `cp1252` · `ascii` | charset |
| `LOTTO_PRINT_CUT` | **`partial`** · `full` · `none` | cutter behavior |
| `LOTTO_PRINT_FEED` | integer 0–10, **3** | final document feed (`feedLines`) |
| `LOTTO_PRINT_CUT_PER_BLOCK` | `0` · **`1`** | report cut per close block (uses builder `BLOCK_FEED = 2`) |

`printer-config.json` in `app.getPath('userData')`:

```json
{
  "version": 1,
  "printerName": "POS-58",
  "paper": "58mm",
  "columns": 32,
  "codePage": "cp850",
  "cut": "partial",
  "feedLines": 3,
  "cutPerBlock": true
}
```

Resolution (`config.cjs`): synchronous small-file read (missing → `{}`; malformed JSON →
`{}` + `warnings` entry, print still proceeds) → overlay env → validate. Invalid enum/int
values fall back to the default and append a warning instead of blocking the till;
`warnings` are surfaced in `print:test`. Precedence: **env > file > default**.
`LOTTO_PRINT_PAPER` accepts `58`, `80` or `80-512` and maps to `58mm`, `80mm` or
`80mm-512`. Defaults when unset: `paper '58mm'`, `columns 32`, `dots 384`,
`codePage 'cp850'`, `cut 'partial'`, `feedLines 3`, `cutPerBlock true`, `mode 'escpos'`.
`printerName` has no default; no substring matching and no system-default guess.

Discovery (`discovery.cjs`), called with `webContents.getPrintersAsync()`:

- **Name validation (before enumeration)**: the configured name must match the strict
  allowlist `^[\p{L}\p{N} ._#,-]{1,128}$` (Unicode letters/digits, space, `. _ # , -`).
  Anything else — quotes, semicolons, ampersands, pipes, dollar signs, backticks,
  parentheses, angle brackets, backslash, slash, colon, control chars, or length >128 —
  → `E_CONFIG_INVALID`:
  `Nombre de impresora invalido. Use solo letras, numeros, espacios, punto, guion, guion bajo, # y coma.`
  This rejects shell metacharacters **even when the name matches an enumerated queue**
  (defense-in-depth for the PowerShell/`lp` boundary).
- **No name** → `E_PRINTER_UNCONFIGURED`:
  `Impresora no configurada. Defina LOTTO_PRINTER_NAME o printer-config.json. Impresoras disponibles: A, B, C.`
- **Empty list** → `No hay impresoras instaladas. Instale o comparta la impresora termica y reintente.`
- **Match**: exact `name`, then case-insensitive exact match; otherwise
  `E_PRINTER_NOT_FOUND`:
  `Impresora "POS-58" no encontrada. Disponibles: A, B, C. Corrija LOTTO_PRINTER_NAME o printer-config.json.`
- **Enumeration failure**: `getPrintersAsync` rejects or exceeds 5 s →
  `E_DISCOVERY_TIMEOUT` with the same list hint. All errors become
  `{ success: false, message }` in the existing modal.

Why no `main.cjs` window accessor for normal prints: `ipcMain.handle` always receives a
live `event.sender` (`WebContents`), even with no focused window. Today's
`BrowserWindow.getFocusedWindow()` can be `null`; `event.sender` cannot. Missing
`webContents` in `escpos` mode → `E_NO_WEBCONTENTS` before any config work. `main.cjs` is
touched only so `print:test` can enumerate queues at startup: `createPrinting` receives
`getWindow: () => mainWindow`, and `maybeRunStartupTest()` runs **after**
`await createWindow()` so `mainWindow.webContents` exists. Without the window it returns
`{ success:false, message:'... sin ventana activa' }` and never blocks boot.
`maybeRunStartupTest()` runs once per boot, only when `LOTTO_PRINT_TEST === '1'` and
`mode === 'escpos'`; it catches all errors and logs the outcome.

## Transport and failure policy

```js
// transport.cjs — lazy require keeps pure tests dependency-free.
// sendRawCommand is STATIC (dist/index.d.ts:22: static sendRawCommand(printerName, data)).
async function spoolerSend(printerName, buffer) {
  const { PosPrinter } = require('electron-pos-printer');
  await PosPrinter.sendRawCommand(printerName, buffer); // Windows: PowerShell 5.1 System.Printing
                                                        // Linux/macOS: lp -d <name> -o raw
}
```

`transport.test.cjs` loads `transport.cjs` with a stubbed `electron-pos-printer` (module
cache injection or a `createTransport({ posPrinter })` seam) and asserts:
`PosPrinter.sendRawCommand` was called **statically** (no `new`), exactly once, with the
resolved queue name and a `Buffer` whose hex equals the encoder golden.

- **Timeout**: 15 s per job (`E_TRANSPORT_TIMEOUT`). Typical 1–5 s; no read-back is
  possible, so a resolved promise means *submitted*, not *printed*.
- **No automatic retry and no HTML fallback** after a raw attempt. Error message:
  `No se pudo enviar el documento a "POS-58": <reason>. Verifique la impresora y la cola antes de reimprimir; no se reintento automaticamente.`
  This is the anti-duplicate rule: a partial spool plus retry/fallback can print twice.
- **Single-flight**: `infra/lock.cjs` exposes `createLock().run(task)`. `index.cjs` wraps
  resolve + build + send in `lock.run(...)`, so no two buffers can interleave. A second
  call while a job is in flight rejects `E_PRINT_BUSY` (fail fast, no waiting); the lock
  releases in `finally` on success or failure; a non-function task rejects
  `E_PRINT_LOCK_INVALID`. There is no waiting list and no depth cap — the
  Windows spooler already serializes per printer and the UI blocks sale prints.
- **Future escape hatch — `tcp9100` (documented only, NOT implemented)**: the design keeps
  `sendRaw(printerName, buffer, { timeoutMs })` as a stable seam. If PowerShell is proven
  blocked on the target PCs, a later change can add a `node:net` adapter that connects to
  `host:9100`, writes the buffer, and resolves on `close`. No `node:net` code, no transport
  config keys and no tests ship in S1–S5.
- **Native addon** (documented, NOT implemented): a small N-API `WritePrinter` binding
  would bypass PowerShell, but needs per-Electron-ABI prebuilds, `asarUnpack`, and a
  Windows CI builder. Out of scope for this change; only if the spooler path is proven
  blocked on the target PCs.
- **HTML mode** never mixes with raw: `mode` is resolved once per print, before discovery
  and before any byte is sent.

## Wiring

### `ipcHandlers.cjs`

- Import `printDocument`/`createPrinting` (module receives the service as a 3rd
  `registerIpcHandlers(upstream, updater, printing)` argument).
- Keep the three validations verbatim; handlers become ~6 lines each, passing
  `event.sender` as `webContents` and a `legacy` closure.
- Keep `generateTicketHtml`, `generateReciboHtml`, `generateCierreHtml`,
  `generateReporteHtml`, `escapeHtml`, `fmtMoney`, `fmtFecha`, `printWithSystemDialog`,
  `printHtml` **until S5**; `legacy(data)` maps:
  `ticket|recibo → generateTicketHtml → printWithSystemDialog(null, html, 'Ticket')`,
  `cierre → generateCierreHtml → 'Cierre'`, `reporte → generateReporteHtml → 'Reporte'`.
  `printWithSystemDialog` ignores its `win` argument today, so `null` is correct.

### `main.cjs` (only for `print:test`)

```js
const { createPrinting } = require('./printing/dist/index.js');

app.whenReady().then(async () => {
  const printing = createPrinting({
    env: process.env,
    userDataPath: app.getPath('userData'),
    getWindow: () => mainWindow,
  });
  registerIpcHandlers(upstream, updater, printing);
  updater.start();
  registerCustomProtocol();
  registerApiProtocol();
  Menu.setApplicationMenu(null);
  await createWindow();
  printing.maybeRunStartupTest();            // fire-and-forget, catches internally
});
```

### Unchanged

`preload.cjs`, all `.astro` pages, payload shapes, `e2e/helpers/fixtures.mjs`
(`STUB_CHANNELS` untouched: no new channel), `e2e/helpers/app.mjs`, the five `print:*`
call sites.

### S5 removal

Delete `generate{Ticket,Recibo,Cierre,Reporte}Html`, `escapeHtml`, `fmtMoney`, `fmtFecha`,
`METODOS_CIERRE*`, `printWithSystemDialog`, `printHtml` and the dead
`POSPrinter`/`getPrinterList` branch; drop the removed names from `module.exports`; drop
the now-unused `BrowserWindow` import; delete the `html` mode branch in `index.cjs` and the
`legacy` parameter. Also remove `isHtmlMode()`, the `EffectiveConfig.mode` field,
`LOTTO_PRINT_MODE` parsing and its docs, and the `mode === 'escpos'` gate in
`maybeRunStartupTest` (the runner no longer needs a mode check). `electron-pos-printer`
stays in `package.json` because `sendRawCommand` uses it. Update `docs/dev/estructura.md`
(remove `ticketPrinter.js`/`node-printer`/`ELECTRON_PRINTER_NAME` claims, document
`printing/` + env keys) and `docs/dev/planificacion.md:281`; add `CHANGELOG.md`.

## Testing strategy

All pure tests run with `node --test`, no Electron, no printer. Tests require the built
`dist/**/*.js` (S6), so `pnpm build:printing` must run first.

| File | Cases |
|---|---|
| `printing/test/core/encode.test.cjs` | golden CP850/CP437 bytes for `á é í ó ú ü ñ Ñ Á É Í Ó Ú ¿ ¡ °`; CP858 `€`; CP1252 map; each code page selectable via `ESC t`; `ascii` transliterates the full set; `?` for unknown; ESC/FS/GS/`0x00`/`0x7F`/C1 stripped; `\r\n`, `\r`, `\n` all become a single space (free text `"A\nB"` → `A B`, one bounded line); empty/null; 96/columns clamp; 1 char = 1 byte |
| `printing/test/core/encoder.test.cjs` | every command hex from the table, including title `1D 21 01` (double height) and reset `1D 21 00`; never `1D 21 10`; `cut:'none'` emits no `GS V` and ends with feed 5; `full` variant; `feedLines`; `ESC t` values 0/2/19/16; reporte `BLOCK_FEED = 2` sentinel; full document golden buffer |
| `printing/test/core/format.test.cjs` | `0`, `1.5`, `1.005`→`1,01`, `2.675`→`2,68`, `1234.567`, `-12.5`, `1234567.891`, `NaN`, `undefined`, `-0`→`0,00`; ISO Z with −04 shift; microsecond stamp `...00.000000Z`; date-only `YYYY-MM-DD` no shift; invalid date; `9/11/2026`→`09/11/2026`; `10:30:15 a. m.`→`10:30`; `2:30:15 p. m.`→`14:30`; `12:00 a. m.`→`00:00`; `12:00 p. m.`→`12:00` |
| `printing/test/core/layout.test.cjs` | pad/truncate/wrap; surrogate pair never split; `row` exact width; mismatch throws; long word hard-split; continuation indent |
| `printing/test/core/profiles.test.cjs` | default is `58mm`/32 cols/384 dots; 48 and 42 opt-in; `columns` override; out-of-range override → default + warning |
| `printing/test/infra/config.test.cjs` | default `paper:'58mm'`, `columns:32`; `LOTTO_PRINT_PAPER=80` → 48; env > file > default; malformed JSON ignored with warning; invalid enum → default + warning; missing `printerName` stays null |
| `printing/test/infra/discovery.test.cjs` | exact match; case-insensitive exact match; `PostScript` queue does **not** match `POS`; not-found lists queues; empty list message; timeout; missing name; strict name allowlist rejects semicolons, quotes, ampersands, pipes, dollar signs, backticks, parentheses, angle brackets, slashes, colons and newlines **even when an enumerated queue matches** |
| `printing/test/infra/transport.test.cjs` | 6 `sendRaw` tests: stubbed `electron-pos-printer` asserts `PosPrinter.sendRawCommand` is called **statically**, once, with the exact queue name and a `Buffer` matching the encoder golden; resolves; rejects → `E_TRANSPORT_FAILED`; times out → `E_TRANSPORT_TIMEOUT`; lazy require keeps the module loadable without the dependency |
| `printing/test/infra/lock.test.cjs` | `E_PRINT_BUSY` on a second `run` while busy; release after success; release after failure; `isBusy()` true during and false after; `E_PRINT_LOCK_INVALID` for a non-function task; no waiting behaviour |
| `printing/test/index.test.cjs` | full `printDocument` with fakes and the same `config` object passed to `buildBuffer`; success messages exactly `Ticket enviado a impresora`, `Cierre enviado a impresora`, `Reporte enviado a impresora` (recibo → `Ticket`); `html` mode delegates to `legacy`; `maybeRunStartupTest` gating (`LOTTO_PRINT_TEST=1` and `mode=escpos`); `E_PRINT_BUSY` surfaces as `{success:false,message}`; payload-too-large guard |
| `printing/test/documents/printTest.test.cjs` | pure `printTestSlip(config)` only: default ruler is exactly 32 chars (`|` at 0,5,…,30); follows `columns` override (48/42); all 17 mapped chars (incl. `Ü`); four code-page labelled lines; cut/feed ops; no Electron/IO require |
| `printing/test/documents/ticket.test.cjs` | 32-col primary goldens: 2-line bet without/with `Hora`, long `jugada` wrap, money-pair split at 13 chars, multi-game group rows, aligned totals, no prize field; 48-col opt-in goldens (table form) |
| `printing/test/documents/recibo.test.cjs` | 32-col primary: 8-field stacked block, one-line money pair and the 13-char split, states, prize totals; 48-col opt-in golden |
| `printing/test/documents/cierre.test.cjs` | 32-col primary: header, `TOTALES`, `ARQUEO / DIFERENCIA`, per-method `BS` and `USD` stacks for all four methods, `concepto` retained, `referencia: "A\nB"` one bounded line, negative `Falt./Sobr.`, 7-digit `1.234.567,89` in a breakdown line; 48-col opt-in golden with 7-digit overflow to the stacked row |
| `printing/test/documents/reporte.test.cjs` | 32-col primary: 12-close report, fused + per-close stacked sections, `BLOCK_FEED=2` + cut per block, final feed 3, 7-digit amount; 48-col opt-in golden |
| `printing/test/purity.test.cjs` | static scan of `core/*.ts` and optional `documents/*.ts`: no `electron`, `fs`, `node:net` or `electron-pos-printer` imports; `infra/` is never scanned |

Integration/E2E: unchanged. E2E stubs intercept the three channels before the main
handler, so a native encoder cannot break them; no stub changes. Packaging is unaffected
(`electron/**/*` already globs the new folder, including `dist/`). CI (S1 originally, S6
final form): `working-directory: taquilla`, then
`pnpm build:printing && cd electron/main/printing && node --test`. The gate lands with S1
and keeps running after S6.

Manual hardware checklist (`LOTTO_PRINT_TEST=1`):

- [ ] Default profile is 58 mm / 32 columns; the first hardware test uses the 32-column
      ruler (32 chars, no wrap/clip).
- [ ] Ruler ends exactly at the configured column (32 default; 48/42 only when opted in).
- [ ] All accents render correctly on exactly one labelled code-page line; pin it.
- [ ] Partial cut separates the slip; feed leaves 3 blank lines, no off-paper.
- [ ] `cut:none` (no cutter) prints feed only, no `GS V` noise.
- [ ] 512-dot printer with `columns:42` aligns; `48` is visibly too wide.
- [ ] Ticket, receipt, close (with arqueo), 12-close report: columns, totals, no splitting.
- [ ] Unplug printer → error lists queues, no dialog; block PowerShell → transport error,
      reprint only on operator action, no duplicate.

Commands for the owner (not run in this phase):

```bash
cd taquilla && pnpm install --frozen-lockfile
cd taquilla && pnpm build:printing            # esbuild → printing/dist (*.js CJS)
cd taquilla && pnpm test:printing             # builds first, then node --test
cd taquilla && pnpm exec tsc -p tsconfig.printing.json   # optional type gate
cd taquilla && node scripts/check-pure.mjs
cd taquilla && pnpm build
cd taquilla && xvfb-run -a pnpm e2e
git add -f openspec/changes/impresion-escpos-nativa/design.md   # .gitignore:44 ignores *.md
```

## Slices / work units (feature-branch-chain, tracker → main only)

Rebalanced from the proposal so **every slice stays ≤500 changed lines**. The deviation is
intentional: S1 splits into S1a/S1b, S2 lands inert infrastructure
(config/discovery/transport/lock), the original S3/S4 split in two because the 58-first
stacked layouts no longer fit one PR, and S5 removes the rollback surface and also updates
docs/CHANGELOG (deletion-heavy, not deletion-only). Each row is one PR against the previous
branch; the chain has 10 PRs (S1–S5 plus the implemented S6a/S6b migration).

| Slice | Files (authored / deleted) | Est. | Depends on | Rollback |
|---|---|---|---|---|
| **S1a Core bytes** | `core/encode.cjs` 70, `core/encoder.cjs` 85, `core/format.cjs` 75; core tests ~151 (S1 test total 25 cases) | ~381 | — | revert; no wiring |
| **S1b Core geometry** | `core/layout.cjs` 90, `core/profiles.cjs` 25; core tests ~73; `package.json` +3 (`test:printing`); CI step +4 | ~195 | S1a | revert; no wiring |
| **S2 Config + discovery + transport + lock** | `infra/config.cjs` 88 (58-first defaults), `infra/discovery.cjs` 55, `infra/transport.cjs` 65 (spooler only), `infra/lock.cjs` 35 (`createLock`); 24 tests (~250 lines). **WU-2.3 title: "Transport + lock"** | ~494 | S1b | revert; nothing calls it yet |
| **S3a Ticket live (32-first)** | `index.cjs` 100, `documents/ticket.cjs` 135 (2-line bet, wrap, money split), `ipcHandlers.cjs` +45, `main.cjs` +8; `test/index.test.cjs` + ticket tests (~120) | ~408 | S1b, S2 | `LOTTO_PRINT_MODE=html` |
| **S3b Receipt live (32 stacked)** | `documents/recibo.cjs` 100, `index.cjs` builder map +3; `test/documents/recibo.test.cjs` 65 | ~168 | S3a | `LOTTO_PRINT_MODE=html` |
| **S4a `print:test` (32 ruler)** | `documents/printTest.cjs` 55 (pure builder), `index.cjs` `runPrintTest` +30, `main.cjs` +4; `test/documents/printTest.test.cjs` 40 | ~129 | S3a | revert; no customer path |
| **S4b Close + report (32 stacked)** | `documents/cierre.cjs` 130, `documents/reporte.cjs` 100, `index.cjs` builder map +6, `ipcHandlers.cjs` +25; `test/documents/cierre.test.cjs` + `reporte.test.cjs` 120 | ~381 | S3a, S3b | `LOTTO_PRINT_MODE=html` |
| **S5 Removal** | delete generators ~370, `printHtml`/`printWithSystemDialog` ~75, dead POS branch ~12, `html` mode ~10, `isHtmlMode()` + `EffectiveConfig.mode` + `LOTTO_PRINT_MODE` parsing ~8, `maybeRunStartupTest` `mode === 'escpos'` gate ~4; exports/imports ~8; docs (mode/rollback refs) ~6; `CHANGELOG.md` +5 | ~498 del | S4b + hardware sign-off | revert the PR (native already live) |
| **S6a Build infra** | `scripts/build-printing.mjs` 55 (esbuild 0.27.7, CJS, `bundle:false`, `outdir: printing/dist`, `outbase: printing`, writes `dist/package.json`), `tsconfig.printing.json` 15, package scripts/CI 10 | ~80 | S5 | revert the build wrapper; runtime falls back to checked-in `.cjs` |
| **S6b Mechanical TS migration** | `.cjs` → `.ts` rename with types (`types.ts` added): **1,352 created / 1,527 deleted**; tests unchanged (**89 cases**). Requires **`size:exception`** (over 500). | ~2,879 changed | S6a | revert the PR; behavior-neutral |

Trim order if a slice runs hot: S2 lock invalid-input cases → S3a wrap goldens → S4b
report goldens. S5 is deletion-heavy; if it exceeds 500, use `size:exception` (proposal
already flags it) or split "generators" / "html mode + docs" into two PRs.

Builder map by slice: S3a creates `documents/` and registers `ticket` only
(`require('./documents/ticket.cjs')`); S3b adds `recibo`; S4b adds `cierre` and `reporte`.
No dynamic `require` by computed path.

Test counts: S1 = 25 cases (S1a + S1b), S2 = 24 cases (49 total for the foundation and
infra PRs).

**Commit hygiene**: one conventional commit per WU, tests with the code they verify. Every
OpenSpec artifact here is ignored by `.gitignore:44` (`*.md`) and must be committed with
`git add -f`.

## S6 — TypeScript migration (implemented)

S6 is a behavior-neutral source migration: TypeScript for readability and contracts, esbuild
CJS output for Electron. S1–S5 remain the functional path; S6 changes no bytes.

**Source tree**: `types.ts`, `core/*.ts`, `infra/*.ts`, `documents/*.ts`, `index.ts`. Tests
stay `.cjs` under `test/` and run with `node --test` against `dist/**/*.js`.

**Build**: `scripts/build-printing.mjs` uses **esbuild 0.27.7** with `format: 'cjs'`,
`platform: 'node'`, `bundle: false`, `outdir: 'printing/dist'`, `outbase: 'printing'`. It
writes `printing/dist/package.json` with `{"type":"commonjs"}` so extensionless
`require('./x')` resolves `.js` inside the `"type": "module"` package. `dist/` is generated
and never hand-edited.

**Consumers**:
- `main.cjs` requires `./printing/dist/index.js`.
- Tests require `dist/**/*.js` (never the `.ts` sources).
- `test/purity.test.cjs` scans `core/*.ts` and optional `documents/*.ts`; it never scans
  `infra/`.
- `ipcHandlers.cjs` JSDoc resolves the shared contracts in `printing/types.ts`.

**Scripts / CI**:
- `build:printing` → `node scripts/build-printing.mjs`.
- `test:printing` builds first, then runs `node --test electron/main/printing`.
- `electron:dev`, `electron:build`, `electron:build:win` and `e2e` build first.
- CI step: `working-directory: taquilla`, then
  `pnpm build:printing && cd electron/main/printing && node --test`.

**Type checking**: `tsconfig.printing.json` sets `allowJs: false`, `checkJs: false`,
`noEmit`, `module: 'commonjs'`, `include: printing/**/*.ts`. Optional gate:
`pnpm exec tsc -p tsconfig.printing.json`. esbuild does not type-check, so tsc is the type
gate, not the bundler.

**Slices**: S6a build infra ~80 lines; S6b mechanical migration 1,352 created / 1,527
deleted with `size:exception`; the 89 tests are unchanged. **Tradeoff**: TS source for
readability/contracts, esbuild CJS for Electron, and no full main-process migration.

## Threat matrix

| Boundary | Applicability | Design response | Planned RED tests |
|---|---|---|---|
| Documentation-like paths | **N/A** — no executable classification; the only file read is a fixed `printer-config.json` under `userData` | Fixed path, JSON only, malformed → ignored | — |
| Git repository selection | **N/A** — no git invocation in product code | — | — |
| Commit state | **N/A** — no commit automation | — | — |
| Push state | **N/A** — no push automation | — | — |
| PR commands | **N/A** — no PR automation | — | — |
| **Subprocess / printer-queue boundary** (custom row: this change shells out via `sendRawCommand`) | **Applicable** — the queue name reaches `powershell.exe`/`lp`; the buffer is untrusted spooler input | Name must match `^[\p{L}\p{N} ._#,-]{1,128}$` and an enumerated queue; shell metacharacters (quotes, `; & \| $`, backtick, parentheses, angle brackets, slashes, colon), control chars and newlines are rejected even for an enumerated queue; the buffer is passed as data (library temp file), never as command text; errors never echo bytes; no retry/fallback after a send | `test/infra/discovery.test.cjs`: metacharacter and non-enumerated names rejected; `test/infra/transport.test.cjs`: static call with the exact name/buffer, no HTML path after a raw attempt; `test/core/encode.test.cjs`: ESC/GS/FS stripped |

## Migration / rollout

No data migration. Rollout: S1+S2 are inert. The default profile is **58 mm / 32 columns**
(384 dots); 80 mm is opt-in via `LOTTO_PRINT_PAPER=80` (48 cols) or `columns`. Before
deploying S3a, ops must set `LOTTO_PRINTER_NAME` (or ship `printer-config.json`) on each PC;
otherwise every print returns the actionable "not configured" message (never a dialog).
The first hardware test on each PC is the 32-column `print:test` ruler. Machines that fail
it run `LOTTO_PRINT_MODE=html` until fixed. S5 lands only after the owner signs off the
native output; rollback = revert the tracker merge or set `html` before S5. Add the
`print:test` step to the Windows deployment checklist.

## Acceptance notes

- The default profile is **58 mm / 32 columns** (384 dots). A fresh install with only
  `LOTTO_PRINTER_NAME` prints 32-column documents; no paper/columns env is required.
- The first hardware test on every PC is `LOTTO_PRINT_TEST=1`, and it must show the
  **32-column ruler** ending exactly at column 32 before any other document is tested.
- 80 mm / 48 columns remains supported as an **opt-in** profile
  (`LOTTO_PRINT_PAPER=80` or `LOTTO_PRINT_COLUMNS=48`); it is never the default and is
  tested second.
- The 32-column stacked ticket/receipt/close/report forms are the product path; their
  goldens are the primary acceptance artifacts.
- The sale ticket never prints a potential-prize field (RF-02.6).

## Open questions and assumptions

No blocking questions. Each unknown has a safe default and a hardware check that closes it.

| Unknown | Safe default | Closed by |
|---|---|---|
| Printer model, USB/network queue, driver | Assume a Windows spooler queue; `spooler` transport | `Get-Printer` inventory + `sendRawCommand` success on a test job |
| Paper width | **CLOSED: 58 mm / 32 columns** (owner-confirmed) | `print:test` 32-column ruler validates the physical printable width |
| Printable dots on the 58 mm unit | Assume 384 dots (32 cols) | 32-column ruler ends without wrap/clip; `columns` override if not |
| 80 mm / 48 cols (opt-in only) | `LOTTO_PRINT_PAPER=80` → 48 cols | Ruler at 48 columns when the profile is enabled |
| 512-dot 80 mm (opt-in) | `48`; set `columns:42` if the ruler wraps | Ruler length |
| Cutter present | `partial` cut | Audible/visible cut on the slip; else `cut:none` |
| Device code page / honors `ESC t` | `cp850` (`ESC t 2`) | Four labelled candidate lines; else `ascii` |
| PowerShell 5.1 / policy | Assume available | Transport failure message; `tcp9100` and a native addon are documented future escape hatches (not implemented) |
| Long-report pagination | `BLOCK_FEED=2` + cut per close block; final `feedLines=3` | 12-close manual print, no off-paper |
| Money column widths | Max 13 chars incl. sign (`-1.234.567,89`); stacked overflow beyond that | Close/report goldens with 7-digit amounts at 32 and 48 |
| Report `taquilla` name missing (pre-existing) | Literal `Taquilla` fallback | Backend follow-up, out of scope |
| Cash drawer / barcode / QR / image | Out of scope, never emitted | — |

## Key assumptions to verify at apply time

1. `electron-pos-printer@1.4.0` exports `PosPrinter` with a **static** method
   `sendRawCommand(printerName, data)` (`dist/index.d.ts:22`); the design calls it as
   `PosPrinter.sendRawCommand(name, buffer)` with no instance.
2. `Contents.getPrintersAsync()` exists on Electron 41 and returns `name`/`displayName`;
   no typed default-printer flag is relied on.
3. `.cjs` everywhere under `electron/main/`; no `import()`/TS in the print path.
4. E2E never executes the real handlers, so no stub change is needed.
