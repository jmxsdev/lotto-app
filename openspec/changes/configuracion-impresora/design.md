# Design: Printer configuration and discovery (Phase 1)

## Technical approach

Phase 1 makes `userData/printer-config.json` the only printer-configuration source and
turns discovery into a UI-ready list/suggest/validate surface. It ships in two
feature-branch-chain slices:

- **P1a** removes every environment read, adds the missing atomic writer, deletes the
  startup-test hook, and rewrites the config/index tests.
- **P1b** adds `listPrinters`, the deterministic suggestion, permissive-but-safe name
  validation, and rewrites the discovery tests.

No IPC, preload, renderer, or release work is part of this phase. `printTest.render`
stays (unwired) for Phase 2; only its instruction line changes.

## Architecture decisions

| Decision | Choice | Alternatives rejected | Rationale |
|---|---|---|---|
| Config source | File + defaults only | Keep env behind a deprecation flag | Requirement forbids all env support; two sources duplicate precedence and tests. |
| Writer location | `saveConfig` in `infra/config.ts` | New `infra/configWriter.ts` | One module owns the schema, read validation, and write validation; a second file would duplicate the field table. |
| Writer API | Synchronous, throws typed errors | Async `Promise` API | `resolveConfig` and the project's `fs.*Sync` usage are synchronous; the file is tiny. Phase 2 IPC handles the throw inside its async handler. The spec's "rejects" is implemented as a synchronous throw of the same typed error. |
| Save semantics | Patch merges over current file + defaults | Full replace | Spec requires merge; missing fields keep working values; a save also repairs malformed/invalid existing files. |
| Columns persistence | Persist `columns` only when explicitly overridden | Always persist resolved columns | Always persisting freezes columns when `paper` later changes (`columns` wins over the profile). |
| Write safety | `writeFile(target.tmp)` then `renameFile(tmp, target)` | Direct write | Rename replaces atomically on POSIX and Windows; a crash cannot leave a truncated target. |
| Validation boundary | Phase 1 validates shape/types/ranges/control chars; printer existence belongs to Phase 2 IPC | Enumerate inside `saveConfig` | `infra/config.ts` has no Electron `getPrintersAsync`; Phase 2 has the sender and can require membership before persisting. |
| Discovery shape | `PrinterInfo[]` + `suggested: string \| null` | `string[]` of names | The settings UI needs `displayName`, `description`, and `options`; names alone force a second enumeration. |
| Name rule | `isSafePrinterName` (printable + no C0/C1/newlines, 1–128) + mandatory enumeration membership | Old strict `NAME_RE` allowlist | Real queues include `()`, `#`, `-`, quotes, etc.; existence in `getPrintersAsync` is the security gate, not a character allowlist. |
| Suggestion | Single printer wins; else first `name`/`displayName` matching `/pos\|thermal\|ticket\|receipt\|58\|80\|xp\|tm/i`; never auto-saved | Score/rank all printers | Deterministic, spec-exact, stable tie-break by enumeration order; a wrong suggestion only costs one click. |
| Error copy | Point to `printer-config.json` / "Configuracion de impresora" | Keep `LOTTO_*` hints | The variables no longer exist in Phase 1. |
| Slice split | P1a = contracts + config + index/main + config/index tests; P1b = discovery + discovery tests | One large PR | Modules are independent; each slice stays actionable and under the 300-line review budget. |

## Interfaces / contracts

### P1a — `printing/types.ts`

```ts
/** Fully resolved config: printer-config.json > defaults. */
export interface EffectiveConfig {
  printerName: string | null;
  paper: Paper;
  columns: number;
  dots: number;
  codePage: CodePage;
  cut: Cut;
  feedLines: number;
  cutPerBlock: boolean;
  warnings: readonly string[];
}

export interface PrintResult {
  success: boolean;
  message: string;
  code?: PrintErrorCode;
}

export type PrintErrorCode =
  | 'E_CONFIG_INVALID'
  | 'E_CONFIG_WRITE_FAILED'
  | 'E_PRINTER_UNCONFIGURED'
  | 'E_PRINTER_NOT_FOUND'
  | 'E_DISCOVERY_TIMEOUT'
  | 'E_TRANSPORT_INVALID'
  | 'E_TRANSPORT_UNAVAILABLE'
  | 'E_TRANSPORT_TIMEOUT'
  | 'E_TRANSPORT_FAILED'
  | 'E_PRINT_BUSY'
  | 'E_PRINT_LOCK_INVALID'
  | 'E_PAYLOAD_INVALID'
  | 'E_PAYLOAD_TOO_LARGE'
  | 'E_NO_WEBCONTENTS';

export type WriteFile = (path: string, data: string, encoding: 'utf8') => void;
export type RenameFile = (from: string, to: string) => void;

export interface SavePrinterConfigInput {
  printerName: string | null;
  paper?: Paper;
  columns?: number | null; // null removes an explicit override
  codePage?: CodePage;
  cut?: Cut;
  feedLines?: number;
  cutPerBlock?: boolean;
}

export type PrinterConfigFile = {
  version?: unknown;
  printerName?: unknown;
  paper?: unknown;
  columns?: unknown;
  codePage?: unknown;
  cut?: unknown;
  feedLines?: unknown;
  cutPerBlock?: unknown;
  [key: string]: unknown;
};

export interface SavedPrinterConfig {
  version: 1;
  printerName: string | null;
  paper: Paper;
  columns?: number;
  codePage: CodePage;
  cut: Cut;
  feedLines: number;
  cutPerBlock: boolean;
}

export interface ResolveConfigInput {
  userDataPath?: string;
  readFile?: ReadFile;
}

export interface CreatePrintingOptions {
  userDataPath?: string;
  sendRaw?: Transport['sendRaw'];
  lock?: PrintLock;
  readFile?: ReadFile;
}

export interface Printing {
  printDocument(request: PrintRequest): Promise<PrintResult>;
  resolveEffectiveConfig(): EffectiveConfig;
  buildBuffer(input: BuildBufferInput): Buffer;
}
```

`E_PAYLOAD_TOO_LARGE` is added because `core/encode.ts` already throws it and
`PrintResult.code` would otherwise expose a code outside the union.
`Printing` loses `maybeRunStartupTest`; `EffectiveConfig` loses `printTest`;
`ResolveConfigInput`/`CreatePrintingOptions` lose `env` and `getWindow`.

### P1a — new `printing/infra/names.ts`

```ts
const MAX_PRINTER_NAME_LENGTH = 128;
const CONTROL_CHARS = /[\u0000-\u001F\u007F-\u009F]/;

function isSafePrinterName(name: unknown): boolean {
  if (typeof name !== 'string') return false;
  const trimmed = name.trim();
  return trimmed.length >= 1
    && trimmed.length <= MAX_PRINTER_NAME_LENGTH
    && !CONTROL_CHARS.test(trimmed);
}

export { MAX_PRINTER_NAME_LENGTH, isSafePrinterName };
```

C0/C1 includes `\n`, `\r`, and `\t`. Spaces, Unicode, parentheses, quotes, `;`, and
other printable characters are allowed; enumeration membership is the real gate (see
Security rationale). Both `config.ts` and `discovery.ts` import this module, so the
rule has one implementation.

### P1a — `printing/infra/config.ts`

```ts
export interface SaveConfigOptions extends ResolveConfigInput {
  writeFile?: WriteFile;
  renameFile?: RenameFile;
}

export function resolveConfig(input?: ResolveConfigInput): EffectiveConfig;
export function saveConfig(options: SaveConfigOptions, patch: SavePrinterConfigInput): EffectiveConfig;

export { CONFIG_FILE, DEFAULTS, CODE_PAGES, CUTS };
```

`saveConfig` throws `E_CONFIG_INVALID` for bad patch values and
`E_CONFIG_WRITE_FAILED` for missing `userDataPath` or any write/rename failure.
`SaveConfigOptions` is exported for Phase 2 TypeScript callers.

### P1b — `printing/types.ts`

```ts
export interface PrinterInfo {
  name: string;
  displayName?: string;
  description?: string;
  options?: Record<string, string>;
  [key: string]: unknown;
}

export interface PrinterListResult {
  printers: PrinterInfo[];
  suggested: string | null;
}

export interface ListPrintersInput {
  getPrintersAsync?: GetPrintersAsync;
  timeoutMs?: number;
}
```

The dead `PrinterDiscovery` interface is removed. `suggested` is always the queue
`name`, even when the match was found on `displayName`.

### P1b — `printing/infra/discovery.ts`

```ts
const DEFAULT_TIMEOUT_MS = 5000;
const SUGGEST_RE = /pos|thermal|ticket|receipt|58|80|xp|tm/i;

function isSafePrinterName(name: unknown): boolean; // re-export from infra/names
function listPrinters(input?: ListPrintersInput): Promise<PrinterListResult>;
function resolvePrinter(input?: ResolvePrinterInput): Promise<PrinterRef>;

export { DEFAULT_TIMEOUT_MS, isSafePrinterName, listPrinters, resolvePrinter };
```

`NAME_RE` and its export are deleted.

## Config schema

`userData/printer-config.json`, UTF-8, written with two-space indentation and a final
newline:

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

The example shows an explicit `columns` override; omit it to follow the paper profile.

| Field | Read acceptance | Default | Read on invalid | Write rule |
|---|---|---|---|---|
| `version` | Ignored (any value) | — | — | Always `1` |
| `printerName` | `string` (trimmed, empty → `null`) or `null` | `null` | Warn `Invalid printerName; using unconfigured.` and use `null` | Required; trimmed; empty → `null` |
| `paper` | Canonical or aliases `58`, `80`, `80-512` via `resolveProfile` | `58mm` | Profile warning + `58mm` | Canonical only; alias → `E_CONFIG_INVALID` |
| `columns` | Integer 20–80 | Profile columns (`32` for 58 mm) | Profile warning + profile columns | Integer 20–80 or `null`; persisted only when explicit |
| `codePage` | `cp437`, `cp850`, `cp858`, `cp1252`, `ascii` | `cp850` | Warning + `cp850` | Canonical enum only |
| `cut` | `partial`, `full`, `none` | `partial` | Warning + `partial` | Canonical enum only |
| `feedLines` | Integer 0–10 (numeric strings accepted on read) | `3` | Warning + `3` | Integer 0–10 only |
| `cutPerBlock` | `true`/`false`, `1`/`0`, `"1"`/`"0"` | `true` | Warning + `true` | Boolean only |
| `dots` | Never read | Derived from paper | — | Never persisted |
| Unknown keys | Ignored | — | — | Dropped |

`resolveConfig` reads the file once; a missing file, missing `userDataPath`, or an
unreadable file resolves defaults silently. Invalid JSON or a non-object/array adds
`Invalid printer-config.json; using defaults.` Unknown keys never warn. No warning
mentions `LOTTO_*`.

### `saveConfig` behavior

1. Reject missing `userDataPath` with `E_CONFIG_WRITE_FAILED`.
2. Read the current file (discarding its warnings) and resolve the merge base with
   `resolveConfig`.
3. Validate the patch: `printerName` required (`string | null`; trimmed; non-empty
   must pass `isSafePrinterName`), canonical `paper`/`codePage`/`cut`, `feedLines`
   0–10, `cutPerBlock` boolean, `columns` 20–80 or `null`. Unknown patch keys are
   ignored. Invalid values throw `E_CONFIG_INVALID`; nothing is written.
4. Merge: patch fields override; absent fields keep the resolved current value.
   `columns` absent keeps a valid current explicit override; `columns: null` omits it.
5. Serialize the canonical `SavedPrinterConfig` (`version: 1`, unknown keys dropped).
6. Write `printer-config.json.tmp` in the same directory, then rename it to
   `printer-config.json`. A fixed `.tmp` suffix (no pid/random component) is enough
   because Phase 1 is single-process and the next save overwrites a stale temp.
7. On any write/rename throw: `E_CONFIG_WRITE_FAILED` with `details.cause`; the target
   is untouched.
8. Return `resolveConfig({ userDataPath, readFile: () => text })`, so the returned
   `EffectiveConfig` is derived from the exact persisted bytes. A valid canonical
   object yields `warnings: []`.

Printer existence is not checked here. Phase 2's `print:set-config` must enumerate and
canonicalize the name before calling `saveConfig`.

## Discovery algorithms

### Enumeration

`enumerate(getPrintersAsync, timeoutMs)` calls the function, wraps it in the existing
5 s timeout, and normalizes the result to `PrinterInfo[]`: entries must be objects
with a non-empty string `name`; every other own enumerable field is preserved with
`{ ...entry, name }`. Missing function, rejection, or timeout becomes
`E_DISCOVERY_TIMEOUT`. A non-array result becomes `[]`.

### `listPrinters`

1. Enumerate.
2. `suggested = suggest(printers)`.
3. Return `{ printers, suggested }`; no persistence, no mutation of the input entries.

```ts
function suggest(printers: readonly PrinterInfo[]): string | null {
  if (printers.length === 1) return printers[0].name;
  const match = printers.find((printer) =>
    SUGGEST_RE.test(printer.name)
    || (typeof printer.displayName === 'string' && SUGGEST_RE.test(printer.displayName)));
  return match ? match.name : null;
}
```

`SUGGEST_RE` has no `g` flag, so repeated `.test` calls are stateless. It is a
substring heuristic: `PostScript` matches `pos`. That is acceptable because the
suggestion is advisory and never saved; exact resolution still rejects `POS` for
`PostScript`.

### `resolvePrinter`

1. Trim `printerName`; a non-string or blank value is unconfigured.
2. Unconfigured: call `listPrinters` best-effort (rejection/timeout → empty result);
   throw `E_PRINTER_UNCONFIGURED` with `{ available, suggested }` and a message that
   points to `printer-config.json` / "Configuracion de impresora". No `LOTTO_*`.
3. `!isSafePrinterName(name)` → `E_CONFIG_INVALID` with `{ printerName: name }`,
   **before** enumeration. No spooler call happens for bad syntax.
4. Enumerate via `listPrinters`; its `E_DISCOVERY_TIMEOUT` propagates.
5. Empty list → `E_PRINTER_NOT_FOUND` with `No hay impresoras instaladas...` and
   `{ available: [], suggested: null }`.
6. Match `name` exactly, then case-insensitively exactly. No substring and no
   `displayName` matching.
7. No match → `E_PRINTER_NOT_FOUND` with `{ available, suggested }`, the available
   names, the suggestion (when one exists), and a file/settings hint.
8. Return `{ name: match.name }` (the canonical enumerated casing).

### Error contract

| Code | When | `details` | Copy points to |
|---|---|---|---|
| `E_CONFIG_INVALID` | Name has C0/C1 control chars, newline, or length 129+ | `{ printerName }` | Control-character rule |
| `E_PRINTER_UNCONFIGURED` | Name missing/blank | `{ available, suggested }` | `printer-config.json` / settings |
| `E_DISCOVERY_TIMEOUT` | Enumeration missing, rejected, or over `timeoutMs` | `{ available: [], suggested: null, cause? }` | Spooler availability |
| `E_PRINTER_NOT_FOUND` | Name not enumerated, or list empty | `{ available, suggested }` | Available queues / settings |

`E_DISCOVERY_TIMEOUT` carries empty `available`/`suggested` so every discovery error
has the same details shape; nothing was enumerated, so both are empty.

Every message is Spanish, ASCII-only where possible, and free of `LOTTO_`, `env`, and
`process.env` wording.

## Security rationale

The printer name eventually reaches `electron-pos-printer`'s `sendRawCommand`, which
builds a shell invocation. Phase 1's boundary is deliberately layered:

1. **No renderer input in Phase 1.** There is no `print:list-printers`,
   `print:get-config`, or `print:set-config` IPC yet. The renderer cannot choose a
   name; the only sources are the local config file and the enumerated OS queues.
2. **Enumeration membership is the gate.** `resolvePrinter` only returns a name that
   `getPrintersAsync` just returned. A name not installed on the machine can never
   reach the transport, regardless of its characters.
3. **Syntax guard, not an allowlist.** `isSafePrinterName` rejects C0/C1 controls
   (including newlines/tabs) and caps length at 128. It accepts printable punctuation
   because real queues contain `()`, `-`, `#`, `_`, quotes, and similar; the old
   `NAME_RE` rejected legitimate names such as `POS-58 (Copy 1)`. Rejecting every
   shell metacharacter would be security theater once membership is mandatory.
4. **Transport quoting.** `electron-pos-printer` single-quote-escapes the queue name
   when it invokes the native helper. A name can only contain a quote if the OS
   enumerates such a queue; creating one requires local control of the machine, at
   which point the attacker already has code execution.
5. **Writer containment.** `saveConfig` never executes or interpolates the name; it
   serializes JSON and writes it atomically. It rejects control characters but does
   not prove existence; that proof is Phase 2's job.
6. **Phase 2 contract (must propagate to tasks).** Any future IPC that accepts a
   printer name from the renderer MUST re-enumerate and resolve it in the main
   process before saving or printing. The renderer must never pass a raw name
   straight to `saveConfig`/`sendRaw`.

Residual risk: a local operator can save a syntactically safe but nonexistent name;
printing then fails `E_PRINTER_NOT_FOUND` with the available list. No execution path.

## Data flow

```text
app.whenReady
  └─ createPrinting({ userDataPath: app.getPath('userData') })
       └─ printDocument({ kind, data, webContents: event.sender })
            ├─ resolveConfig({ userDataPath, readFile })
            │    └─ printer-config.json + defaults -> EffectiveConfig
            ├─ resolvePrinter({ printerName, getPrintersAsync })
            │    └─ getPrintersAsync -> exact / case-insensitive exact
            ├─ buildBuffer(...)                  (pure ESC/POS)
            └─ sendRaw(printer.name, buffer)     (electron-pos-printer)
       catch -> { success: false, message, code? }

Phase 2 only (not in this change):
  renderer -> preload -> ipcMain -> listPrinters / saveConfig / printTest
```

```text
saveConfig(options, patch)
  ├─ read current file + defaults  (merge base)
  ├─ validate patch                (types, enums, ranges, isSafePrinterName)
  ├─ JSON.stringify(SavedPrinterConfig)
  ├─ writeFile("printer-config.json.tmp")
  ├─ renameFile(tmp -> "printer-config.json")
  └─ resolveConfig(serialized bytes) -> EffectiveConfig
```

## File changes

| File | Slice | Action | Description |
|---|---|---|---|
| `taquilla/electron/main/printing/types.ts` | P1a/P1b | Modify | P1a contract cleanup + writer/result types; P1b list/suggestion types and `PrinterInfo.options`. |
| `taquilla/electron/main/printing/infra/names.ts` | P1a | Create | Shared `isSafePrinterName` predicate. |
| `taquilla/electron/main/printing/infra/config.ts` | P1a | Modify | File-only `resolveConfig`; `saveConfig` with atomic write; keep `CONFIG_FILE`, `DEFAULTS`, `CODE_PAGES`, `CUTS`. |
| `taquilla/electron/main/printing/index.ts` | P1a | Modify | Drop `process.env`, `getWindow`, `printTest` import, `maybeRunStartupTest`; propagate `code`. |
| `taquilla/electron/main/main.cjs` | P1a | Modify | `createPrinting({ userDataPath })`; delete the startup hook. |
| `taquilla/electron/main/printing/documents/printTest.ts` | P1a | Modify | Replace `Prueba: LOTTO_PRINT_TEST=1` with a neutral 30-column line. |
| `taquilla/electron/main/printing/infra/discovery.ts` | P1b | Modify | `listPrinters`, `suggest`, permissive validation, resolved-name matching, config-only errors. |
| `taquilla/electron/main/printing/test/infra/config.test.cjs` | P1a | Modify | File-only read tests + writer tests. |
| `taquilla/electron/main/printing/test/index.test.cjs` | P1a | Modify | `readFile` fake; remove startup tests; assert `code`. |
| `taquilla/electron/main/printing/test/documents/printTest.test.cjs` | P1a | Modify | Two assertions for the neutral instruction line (no new test). |
| `taquilla/electron/main/printing/test/infra/discovery.test.cjs` | P1b | Modify | List/suggest/metadata + updated resolution/validation/error tests. |
| `docs/dev/estructura.md` | P1a | Modify | Replace the two env descriptions with `printer-config.json`. |

Not changed: `core/*`, `documents/{ticket,recibo,cierre,reporte}.ts`,
`infra/{transport,lock}.ts`, `ipcHandlers.cjs`, `preload.cjs`, `package.json`, build
scripts, `tsconfig.printing.json`.

## Slice plan and line estimates

Estimates are authored additions + deletions, measured per PR; the tasks phase must
re-measure before apply. Band reflects test-double verbosity.

### P1a — file config is the only source

| File | Est. changed |
|---|---|
| `printing/types.ts` | 30 |
| `printing/infra/names.ts` (new) | 12 |
| `printing/infra/config.ts` | 70 |
| `printing/index.ts` | 36 |
| `main/main.cjs` | 6 |
| `printing/documents/printTest.ts` | 2 |
| `printing/test/infra/config.test.cjs` | 82 |
| `printing/test/index.test.cjs` | 46 |
| `printing/test/documents/printTest.test.cjs` | 2 |
| `docs/dev/estructura.md` | 2–4 |
| **Total** | **~290 (band 275–305)** |

Trim order if the measured diff exceeds 300:

1. Move the `docs/dev/estructura.md` edit to P1b (−4).
2. Merge the writer failure and malformed-repair tests into one test (−8).
3. Drop the non-text `printerName` warning assertion; keep the behavior (−3).
4. Merge the paper-alias test into the file-overrides test (−6).
5. Merge the invalid-value and unsafe-name writer tests into one loop (−6).

Do not drop the atomic-write, merge, `E_CONFIG_WRITE_FAILED`, or env-removal
assertions.

### P1b — discovery becomes a UI-ready surface

| File | Est. changed |
|---|---|
| `printing/infra/discovery.ts` | 95 |
| `printing/types.ts` | 14 |
| `printing/test/infra/discovery.test.cjs` | 105 |
| **Total** | **~215 (band 195–235)** |

Trim order if needed: merge the 0-printer and 1-printer list cases (−6), merge the
Unicode test into the printable-name test (−5), fold the 129-character case into the
control-character loop (−3). Never drop the exact/case-insensitive, no-substring,
metadata-preservation, or `available`/`suggested` assertions.

## Testing strategy

| Layer | What | Approach |
|---|---|---|
| Unit | Config read: defaults, aliases, file overrides, malformed JSON, invalid enums/ints, name handling | Injected `readFile` fakes; no disk IO. |
| Unit | Config write: atomic temp+rename, canonical shape, merge, `columns: null`, invalid patches, write/rename failures, malformed repair | Injected `readFile`/`writeFile`/`renameFile` recorders; no disk IO. |
| Unit | Composition root: builders, lock, discovery/transport failures, `code` propagation, payload limits | `createPrinting` with a `readFile` fake and fake `webContents`. |
| Unit | Discovery: metadata, suggestion matrix, exact/case-insensitive match, no substring, safe-name rule, typed errors, timeout | Fake `getPrintersAsync`; short `timeoutMs` for the timeout case. |
| Static | No env reads / no startup symbols | Scoped greps below. |
| E2E | None in Phase 1 | No IPC/UI yet. |

### `test/infra/config.test.cjs` — 11 tests

| # | Test (Spanish) | Status |
|---|---|---|
| 1 | `la configuracion predeterminada es 58mm / 32 columnas / 384 puntos con nombre nulo` | Modified: remove `printTest`; assert the key is absent. |
| 2 | `los alias de papel 58, 80 y 80-512 se normalizan desde el archivo` | Converted from env to `readFile`. |
| 3 | `el archivo sobrescribe los predeterminados` | Converted from env-precedence; no env source. |
| 4 | `un JSON malformado se ignora con una advertencia` | Kept. |
| 5 | `valores de enum o enteros invalidos vuelven al predeterminado con advertencia` | Kept; warning count stays 6. |
| 6 | `printerName queda nulo si falta, esta en blanco o no es texto, y printTest ya no existe` | Converted from the `LOTTO_PRINT_TEST` test. |
| 7 | `saveConfig escribe el objeto canonico con temp + rename y el resolve posterior lo devuelve igual` | Added; includes missing-file defaults and omitted `columns`. |
| 8 | `saveConfig conserva los campos no enviados y permite quitar el override de columnas` | Added; merge + `columns: null`. |
| 9 | `saveConfig rechaza valores invalidos y nombres con controles, y guarda null si el nombre esta vacio` | Added; loops bad paper/columns/codePage/cut/feedLines/cutPerBlock/control chars; empty name → `null`. |
| 10 | `saveConfig convierte fallos de escritura o rename en E_CONFIG_WRITE_FAILED` | Added; write throw, rename throw, missing `userDataPath`. |
| 11 | `saveConfig repara un archivo malformado y descarta claves desconocidas` | Added. |

Removed: env-precedence setup, `LOTTO_PRINT_PAPER`, `printTest` resolution. Kept
behavioral guarantees: defaults, invalid-value fallback, file-over-default.

### `test/index.test.cjs` — 9 tests

Removed (4): `sin LOTTO_PRINT_TEST...`, `con LOTTO_PRINT_TEST=1...`,
`sin ventana activa...`, `un fallo del transporte en la prueba...`.
Modified:

- `create()` no longer accepts `env`/`getWindow`; it supplies
  `readFile: () => JSON.stringify({ printerName: 'POS-58' })`.
- Remove the `printTest` require and the unused `missingFile` helper.
- Discovery/transport test: `notFound.code === 'E_PRINTER_NOT_FOUND'`; a plain
  transport error has no `code` property.
- Lock test: `result.code === 'E_PRINT_BUSY'`.
- Payload test: `result.code === 'E_PAYLOAD_TOO_LARGE'`.
- No-builder test: expected object gains `code: 'E_PAYLOAD_INVALID'`.

Kept: ticket, recibo, cierre/reporte, raw no-retry, `buildBuffer`, huge payload.

### `test/documents/printTest.test.cjs` — 6 tests (count unchanged)

Add to the 32-column test: the instruction line is
`Prueba: Configuracion impresora` and no line contains `LOTTO_`.

### `test/infra/discovery.test.cjs` — 13 tests

| # | Test (Spanish) | Status |
|---|---|---|
| 1 | `listPrinters conserva name, displayName, description, options y campos extra` | Added. |
| 2 | `listPrinters devuelve una lista vacia y suggested null sin impresoras` | Added. |
| 3 | `listPrinters sugiere la unica impresora enumerada` | Added. |
| 4 | `listPrinters sugiere la primera coincidencia por token en name o displayName` | Added; includes a displayName match. |
| 5 | `listPrinters devuelve suggested null cuando ninguna impresora coincide` | Added; asserts nothing is persisted. |
| 6 | `listPrinters y resolvePrinter mapean rechazo o timeout a E_DISCOVERY_TIMEOUT` | Added, replaces the old resolve-only timeout test. |
| 7 | `resuelve la cola por coincidencia exacta y luego sin distinguir mayusculas` | Kept. |
| 8 | `no hace coincidencia por subcadena y el error incluye available y suggested` | Modified; asserts `details`. |
| 9 | `una lista vacia o una cola ausente lista las impresoras disponibles` | Modified; asserts `details`. |
| 10 | `rechaza nombres con controles, saltos de linea o longitud excesiva sin enumerar` | Merged from the old metachar + too-long tests; no enumeration side effect. |
| 11 | `acepta parentesis y otros imprimibles solo si la cola esta enumerada` | Added; `POS-58 (Copy 1)` resolves when enumerated, `POS;rm` fails not-found when absent. |
| 12 | `un nombre ausente produce E_PRINTER_UNCONFIGURED con available y suggested` | Modified; asserts `details` and no `LOTTO_`. |
| 13 | `acepta nombres Unicode con los signos permitidos` | Kept. |

Removed: the old blanket rejection of `POS;rm`, `POS"x`, `POS&`, `POS|x`, `POS(x)`,
`POS[x]`, `POS/x`, `POS:x`. Semicolons/quotes/parentheses are no longer syntax errors;
membership decides. `POS(x)` is now accepted when enumerated.

### Expected static test count

Baseline 89. After P1a: `89 − 4` startup tests `+ 5` writer tests = **90**. After P1b:
`90 + 5` discovery tests = **95**. The explore forecast (86/91) assumed one writer test;
the writer contract needs five behavior groups. If 86/91 is treated as a hard gate,
merge writer tests 9–11 into one test for 88/93; that hides three failure classes
behind one test, so keep 90/95.

## Branch plan

Feature Branch Chain; only the tracker may ever reach `main`, and not in this cycle.

```text
main (1930813)
 └─ feature/configuracion-impresora            tracker, draft/no-merge
      └─ feature/configuracion-impresora/p1a-config      PR #1 -> tracker   📍 P1a
           └─ feature/configuracion-impresora/p1b-discovery  PR #2 -> p1a   📍 P1b
```

- **P1a** starts from the tracker, targets the tracker, ~290 changed lines. Verify with
  `pnpm test:printing` (expect 90) and the scoped greps.
- **P1b** starts from `p1a-config`, targets `p1a-config`, ~215 changed lines. Verify
  with `pnpm test:printing` (expect 95).
- Each PR body states start, finish, dependency, out-of-scope items (IPC/preload/UI),
  verification, and rollback. Keep the repo PR template and add a chain-context
  section with the diagram above.
- This cycle stops after P1b. The tracker stays unmerged until Phase 2/3 (IPC/UI) or a
  release gate; no `main` merge here.
- Suggested commits: P1a `refactor(printing): configuracion por archivo y escritor
  atomico` (code + tests) and `docs: referencia de configuracion de impresora`; P1b
  `feat(printing): lista y sugiere impresoras`. Tests ship in the commit of the code
  they verify.

## Threat matrix

N/A — this change adds no routing, shell command, subprocess, VCS/PR automation,
executable-file classification, or process-integration boundary. The only new IO is a
JSON config write under `userData`, covered by atomic temp+rename, typed failures, and
the name-validation/enumeration controls above. No task is required for a threat-matrix
row.

## Risks and rollback

| Risk | Impact | Mitigation |
|---|---|---|
| Commissioning slip unreachable until Phase 2 wires `print:test` | No in-app ruler/accent test | Keep `printTest.render` + its 6 tests; only the instruction line changes; document the gap and ship Phase 2 before release. |
| No settings UI until Phase 3 | Fresh installs fail `E_PRINTER_UNCONFIGURED` | Rewrite the error in P1b to point at `printer-config.json` / settings; operators can hand-edit the file; Phase 2/3 close the gap. |
| Env removal changes deployment behavior | A deployment relying on `LOTTO_PRINTER_NAME` stops printing | Phase 1 is not a release; document the file and require Phase 2/3 before shipping. |
| Removed tests hide regressions | Env/startup guarantees vanish silently | Convert env-precedence tests to file tests; add writer/`code` assertions; keep the same behavioral guarantees. |
| P1a near the 300-line cap | PR exceeds review budget | Use the trim order above; re-measure in tasks; do not weaken assertions. |
| `*.md` is git-ignored | OpenSpec artifacts silently missing from commits | `git add -f openspec/changes/configuracion-impresora/*.md`; `docs/**/*.md` is already whitelisted. |
| `printing/dist/` is git-ignored and loaded by `main.cjs:5` | Manual Electron runs can use stale/fresh-mismatched code | Run `pnpm build:printing` before any Electron run; `test:printing` rebuilds. |
| Current config file has env-era/unknown keys | Save drops them; no runtime effect | Known fields remain valid; `version` ignored; unknown keys ignored on read and dropped on save. No migration needed. |
| `saveConfig` temp file survives a crash | Stale `.tmp` in `userData` | Harmless; the next save overwrites it. The target is never partially written. |
| Suggestion regex matches `PostScript` for `pos` | Misleading suggestion | Advisory only, never auto-saved; exact resolution rejects substrings. |
| Windows rename semantics | Atomic replace could differ | Node's `fs.renameSync` replaces an existing destination; verify during Phase 2 Windows testing. |
| Phase 2 trusts a renderer name | Shell/metacharacter exposure | Mandatory re-enumeration in main before save/print; the writer alone does not prove existence. |

Rollback: revert the P1a and P1b merges onto the tracker. Env support and the startup
hook return together; no data migration and no persisted-state cleanup. A
`printer-config.json` written by Phase 1 (including `version: 1` and omitted `columns`)
is still readable by the old code.

## Owner commands

Do not run these during design. From the repo root:

```bash
# New worktree / dependency setup
pnpm --dir taquilla install

# Build + full native-printing suite
pnpm --dir taquilla build:printing
pnpm --dir taquilla test:printing          # P1a: 90 tests; P1b: 95 tests

# Optional type check
pnpm --dir taquilla exec tsc -p tsconfig.printing.json

# Pure front-end regression harness
node taquilla/scripts/check-pure.mjs
```

Scoped acceptance greps:

```bash
grep -rn "process\.env\|envValue\|pick(env" taquilla/electron/main/printing   # expect none
grep -rn "maybeRunStartupTest" taquilla/electron                             # expect none
grep -rn "LOTTO_" taquilla/electron/main/printing                            # expect none after P1b
grep -rn "getWindow" taquilla/electron/main/printing                         # expect none
grep -n  "getWindow" taquilla/electron/main/main.cjs                         # only the updater call
```

## Out of scope (future phases)

- Phase 2: IPC `print:list-printers`, `print:get-config`, `print:set-config`,
  `print:test`; preload bridge; wiring `printTest.render`; removing the last
  commissioning literal.
- Phase 3: settings UI (printer list, suggestion, paper/columns/codePage/cut fields,
  test button).
- Phase 4: error surfacing in pages and operator-facing recovery flows.
- Phase 5: office mode, version 2.1.1, changelog, release.

## Open questions

None blocking. Phase 2 must confirm the Windows queue-name charset and must re-enumerate
renderer-supplied names before saving or printing; both are constraints, not design
dependencies.
