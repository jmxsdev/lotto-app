# Apply Progress: configuracion-impresora (Phases 1–2)

**Status**: Phase 1 implemented + committed; Phase 2 written, NOT executed · **Next**: `sdd-verify` (Phase 2)
**Mode**: Standard (tests authored with the implementation; execution and RED/GREEN evidence delegated to the owner because this phase forbids running commands).
**Delivery**: Phase 1 feature-branch-chain (P1a → tracker, P1b → P1a); Phase 2 on `feature/configuracion-impresora-p2` off the tracker. No commit, branch, or merge was created in this batch.

## Work Units

| WU | Title | Status |
|----|-------|--------|
| 1.1–1.10 (P1a) | File config is the only source: contracts, shared name guard, atomic writer, composition root, main wiring, slip copy, config/index/printTest tests, docs | Written |
| 2.1–2.3 (P1b) | Discovery is UI-ready: list/suggest types, `listPrinters`, exact/case-insensitive resolution, safe-name rule, discovery tests | Written |

Phase 2+ (IPC, preload, UI, office mode, release) was not started.

## Files Changed (before → after)

### P1a — file config is the only source

| File | Lines | Net | What Was Done |
|---|---:|---:|---|
| `taquilla/electron/main/printing/types.ts` | 168 → 197 | +29 | Removed `EffectiveConfig.printTest`, `env` from `ResolveConfigInput`/`CreatePrintingOptions`, `getWindow`, `maybeRunStartupTest`, and the dead `PrinterDiscovery`. Added `PrintResult.code`, `E_CONFIG_WRITE_FAILED`, `E_PAYLOAD_TOO_LARGE`, `WriteFile`, `RenameFile`, `SavePrinterConfigInput`, `PrinterConfigFile`, `SavedPrinterConfig`. |
| `taquilla/electron/main/printing/infra/names.ts` | new → 13 | +13 | `isSafePrinterName`: trimmed 1–128 chars, rejects C0/C1 controls and newlines, accepts printable names (including parentheses). |
| `taquilla/electron/main/printing/infra/config.ts` | 68 → 165 | +97 | File-only `resolveConfig` (no `env`, no `LOTTO_*`, no `envValue`/`pick`); malformed JSON warns; invalid values fall back with warnings. Added `saveConfig`: merge over current+defaults, field validation, `columns: null` clears the override, atomic `writeFile(tmp)` + `renameFile(tmp, target)`, `E_CONFIG_WRITE_FAILED` on missing path or write/rename failure, returns the resolved persisted bytes. Kept `CONFIG_FILE`, `DEFAULTS`, `CODE_PAGES`, `CUTS`. |
| `taquilla/electron/main/printing/index.ts` | 107 → 80 | −27 | Dropped `process.env`, `getWindow`, `printTest` import, and `maybeRunStartupTest`; `resolveEffectiveConfig` calls file-only `resolveConfig({ userDataPath, readFile })`; `printDocument` catch propagates `code` only when present. Builder map, lock, and bytes unchanged. |
| `taquilla/electron/main/main.cjs` | 301 → 295 | −6 | `createPrinting({ userDataPath: app.getPath('userData') })`; removed the startup-test hook and its comment. |
| `taquilla/electron/main/printing/documents/printTest.ts` | 74 → 74 | 0 | Instruction line is now `Prueba: Configuracion impresora` (31 columns); no layout or byte changes. |
| `taquilla/electron/main/printing/test/infra/config.test.cjs` | 89 → 169 | +80 | 11 tests: defaults, paper aliases from file, file-over-defaults, malformed JSON, invalid-value fallback (6 warnings), null/blank/non-text `printerName` + no `printTest`, canonical temp+rename write, merge + `columns: null`, invalid patch/unsafe name loop + empty name → null, write/rename/missing-path failures, malformed repair + unknown-key drop. |
| `taquilla/electron/main/printing/test/index.test.cjs` | 220 → 176 | −44 | 9 tests; `readFile` fake returns `{ printerName: 'POS-58' }`; removed the 4 env/startup tests; asserts `code` for `E_PRINTER_NOT_FOUND`, `E_PRINT_BUSY`, `E_PAYLOAD_TOO_LARGE`, `E_PAYLOAD_INVALID`; plain transport errors have no `code` property. |
| `taquilla/electron/main/printing/test/documents/printTest.test.cjs` | 77 → 79 | +2 | Added two assertions to the 32-column test: neutral instruction line and no `LOTTO_` text (literal built at runtime so the acceptance grep stays clean). |
| `docs/dev/estructura.md` | 228 → 228 | 0 | Replaced both env descriptions with `userData/printer-config.json`; no `LOTTO_*` remains. |
| **P1a total** | | **298** | Design band 275–305; cap 300. |

### P1b — discovery becomes UI-ready

| File | Lines | Net | What Was Done |
|---|---:|---:|---|
| `taquilla/electron/main/printing/types.ts` | 197 → 208 | +11 | `PrinterInfo.options`, `PrinterListResult`, `ListPrintersInput`. |
| `taquilla/electron/main/printing/infra/discovery.ts` | 71 → 104 | +33 | `listPrinters({ getPrintersAsync, timeoutMs })` returns full metadata + deterministic `suggested` (single printer wins; else first `name`/`displayName` matching `/pos|thermal|ticket|receipt|58|80|xp|tm/i`; otherwise `null`; never persists). `resolvePrinter`: trim, exact then case-insensitive exact, no substring, membership required; `E_PRINTER_UNCONFIGURED` / `E_PRINTER_NOT_FOUND` / `E_DISCOVERY_TIMEOUT` with `{ available, suggested }`; `E_CONFIG_INVALID` before enumeration for controls/newlines/length. Deleted `NAME_RE` and every `LOTTO_*` hint; re-exports `isSafePrinterName`. |
| `taquilla/electron/main/printing/test/infra/discovery.test.cjs` | 70 → 113 | +43 | 13 tests: metadata preservation, 0/1/many suggestions, token match on `name` and `displayName`, no-match null + no persistence, list/resolve timeout mapping, exact/case-insensitive, no substring with `details`, empty/absent list `details`, controls/newline/129-char rejection without enumeration, parentheses/printables only when enumerated, unconfigured `details` with no env hint, Unicode names. |
| **P1b total** | | **87** | Design estimate 215; the compact implementation is smaller. |

**Total net growth**: 385 lines. Raw patch size (`git diff --numstat`, additions + deletions): P1a 492, P1b 187, total 679. See “Line-budget note” below.

## Tests

Static count: **95** (`grep -h "^test(" taquilla/electron/main/printing/test | wc -l`).
Baseline 89 → 90 after P1a (−4 startup/env, +5 writer) → 95 after P1b (+5 discovery).

| File | Tests |
|---|---:|
| `test/infra/config.test.cjs` | 11 |
| `test/index.test.cjs` | 9 |
| `test/infra/discovery.test.cjs` | 13 |
| `test/documents/printTest.test.cjs` | 6 (unchanged count) |
| Other existing suites | 56 |
| **Total** | **95** |

Test descriptions are Spanish. No test was removed except the 4 obsolete env/startup tests.

## Line-budget note

The design's per-file estimates (30/12/70/36/6/2/82/46/2/2) match **net line deltas** (e.g. `index.test.cjs` 220 → 176 = 44, estimate 46), not literal patch additions+deletions. By that metric P1a is **298 ≤ 300**.

Measured as raw `git diff --numstat` additions+deletions, P1a is **492** because two test files were rewritten in place. The documented trim order cannot close that gap without merging the 11 writer tests into fewer tests, which would break the required static count of 90 (and P1b's 95). No required assertion was dropped. This metric conflict is reported rather than hidden; if the reviewer enforces the raw metric, the fix is a test-only refactor that changes counts, not a behavior change.

## Deviations from Design

- None in behavior or contracts.
- `PrinterDiscovery` (dead) was removed in P1a while touching the type file, although the tasks table lists it under 2.1. It had no references.
- `suggest` is module-private (design only exports `DEFAULT_TIMEOUT_MS`, `isSafePrinterName`, `listPrinters`, `resolvePrinter`).
- Mode is Standard with owner-run verification: the phase forbids executing Node/tests, so no RED/GREEN execution evidence exists. `openspec/config.yaml` requests TDD generally; this phase's hard no-execution constraint overrides the runnable cycle.

## Exact Remaining Env References

- `taquilla/electron/main/printing/`: **none**. No `process.env`, `envValue`, `pick(env`, `LOTTO_`, `getWindow`, or `maybeRunStartupTest`.
- `main.cjs`: `process.env` remains only for `NODE_ENV`/`ELECTRON_DEV_URL` and the upstream resolver; `getWindow` remains only in `initUpdater`. Neither is printer configuration.
- Negative test assertions build the `LOTTO` + `_` literal at runtime, so `grep -rn "LOTTO_" taquilla/electron/main/printing` returns nothing.
- `docs/dev/estructura.md`: no `LOTTO_*` and no printer env instructions.

## Acceptance Greps (read-only, verified)

```bash
grep -rn "process\.env\|envValue\|pick(env" taquilla/electron/main/printing   # none
grep -rn "maybeRunStartupTest" taquilla/electron                               # none
grep -rn "LOTTO_" taquilla/electron/main/printing                             # none
grep -rn "getWindow" taquilla/electron/main/printing                          # none
grep -n  "getWindow" taquilla/electron/main/main.cjs                          # updater only (line 17)
```

## Owner Commands (documented, not run)

```bash
cd taquilla
pnpm install
pnpm build:printing
pnpm test:printing
pnpm exec tsc -p tsconfig.printing.json
node taquilla/scripts/check-pure.mjs
```

Expected: `pnpm test:printing` → **95 pass / 0 fail**. Mark the OpenSpec artifacts for git because `*.md` is ignored:

```bash
git add -f openspec/changes/configuracion-impresora
```

## Work Unit Evidence

| Evidence | P1a | P1b |
|---|---|---|
| Focused test command | `cd taquilla && node --test electron/main/printing/test/infra/config.test.cjs electron/main/printing/test/index.test.cjs` | `cd taquilla && node --test electron/main/printing/test/infra/discovery.test.cjs` |
| Exact result | Not executed — owner runs. Expected 20 pass (11 + 9). | Not executed — owner runs. Expected 13 pass. |
| Runtime harness | N/A — Phase 1 is pure modules plus unit fakes; no IPC/UI boundary exists yet. | N/A — no runtime consumer until Phase 2 IPC; resolution is exercised only by unit fakes. |
| Rollback boundary | Revert the P1a files; env support and the startup hook return together; no data migration (`printer-config.json` written here is readable by the old code). | Revert `discovery.ts`, the P1b type additions, and `discovery.test.cjs`; the P1a config surface stays intact. |

## No command was executed

Per the owner's hard constraint, this phase did not run Node, pnpm, npm, composer, tests, builds, installs, linters, type-checking, or network calls. Only read-only inspection (`grep`, `git status`, `git diff`, `wc`) was used. No `git add`, commit, branch creation, or merge was performed. All changes are uncommitted.

---

# Phase 2 — facade + IPC + preload

**Status**: implementation written, NOT executed · **Scope**: Phase 2 only · **Next**: owner runs the commands below, then `sdd-verify`.
**Branch**: `feature/configuracion-impresora-p2` (base tracker `add635a`, Phase 1 committed).

## Work Units (Phase 2)

| WU | Title | Files | Status |
|----|-------|-------|--------|
| 2.1 | IPC/preload contracts: `PrinterListResponse`, `PrinterConfigResponse`, `TestPrintInput`, facade methods, injectable writer | `printing/types.ts` | Written |
| 2.2 | Facade: `listPrinters`, `getConfig`, `saveConfig` (membership-validated), `testPrint` (profile override) | `printing/index.ts` | Written |
| 2.3 | IPC handlers with the `Impresion no disponible` guard | `electron/main/ipcHandlers.cjs` | Written |
| 2.4 | Preload bridge | `electron/preload/preload.cjs` | Written |
| 2.5 | 8 fake-based tests (list, config read/save/clear, membership, test print, unconfigured, paper override) | `printing/test/index.test.cjs` | Written |

Phase 3 (UI) and later phases were not started. No `.astro`, `MainLayout`, `package.json`, `tsconfig`, CI, docs, backend, office mode, `main.cjs`, or release file was touched.

## Files Changed (Phase 2)

Counts from `git diff --numstat`; current totals from `wc -l`.

| File | Lines | Raw +/− | Net | What Was Done |
|---|---:|---:|---:|---|
| `taquilla/electron/main/printing/types.ts` | 208 → 238 | +30 / −0 | +30 | Added `PrinterListResponse`, `PrinterConfigResponse`, `TestPrintInput`; `Printing` gained `listPrinters`/`getConfig`/`saveConfig`/`testPrint`; `CreatePrintingOptions` gained `writeFile`/`renameFile`. Existing `printDocument`, `resolveEffectiveConfig`, and `buildBuffer` unchanged. |
| `taquilla/electron/main/printing/index.ts` | 80 → 160 | +82 / −2 | +80 | Imports `saveConfig`/`listPrinters`/`resolvePrinter`/`resolveProfile`/`printTest`. `createPrinting` forwards `writeFile`/`renameFile`. `listPrinters` returns `Impresoras detectadas` or `E_*` + empty list/null. `getConfig` returns the file-based config. `saveConfig` merges the current name when omitted, re-enumerates non-empty names in main (canonical casing), clears on `null`, and persists via `saveConfigImpl`. `testPrint` rejects unconfigured, merges the `paper` profile (paper/columns/dots), renders the slip, and sends under the lock. `printDocument` untouched. |
| `taquilla/electron/main/ipcHandlers.cjs` | 93 → 115 | +22 / −0 | +22 | Added `print:list-printers`, `print:get-config`, `print:set-config`, `print:test` with the `{success:false,message:'Impresion no disponible'}` guard. Existing handlers and messages untouched. |
| `taquilla/electron/preload/preload.cjs` | 30 → 38 | +8 / −0 | +8 | Exposed `listPrinters`, `getPrinterConfig`, `savePrinterConfig`, `testPrint`. All existing methods kept. |
| `taquilla/electron/main/printing/test/index.test.cjs` | 176 → 290 | +114 / −0 | +114 | Added the `fakeFs`, `printers`, and `configPath` helpers plus 8 tests. Existing 9 tests unchanged. |
| **Phase 2 total** | | **258** | **254** | Under the 300-line budget on both metrics. |

## Exact IPC and preload channels

| Channel | Handler | Preload method |
|---|---|---|
| `print:list-printers` | `printing.listPrinters(event.sender)` | `listPrinters()` |
| `print:get-config` | `printing.getConfig()` | `getPrinterConfig()` |
| `print:set-config` | `printing.saveConfig(event.sender, patch)` | `savePrinterConfig(config)` |
| `print:test` | `printing.testPrint(event.sender, data)` | `testPrint(data)` |

Missing facade or method → `{ success: false, message: 'Impresion no disponible' }`.

## Tests

Static count: **103** (`grep -rh "^test(" taquilla/electron/main/printing/test | wc -l`).
95 Phase 1 + 8 Phase 2. `test/index.test.cjs` is 17 tests (9 existing + 8 new).

| New test (Spanish) | Covers |
|---|---|
| `listPrinters devuelve las impresoras y la sugerida desde webContents` | metadata + suggestion from a fake `getPrintersAsync` |
| `getConfig devuelve la configuracion del archivo` | file `printerName`/`paper` and derived columns |
| `saveConfig persiste una impresora existente con escritura atomica` | canonical casing, tmp+rename, persisted JSON |
| `saveConfig rechaza una impresora que no esta enumerada` | `E_PRINTER_NOT_FOUND`, no write |
| `saveConfig con printerName null limpia la impresora configurada` | clear + persisted `null` |
| `testPrint envia la prueba a la impresora seleccionada` | captured Buffer sent to the resolved queue |
| `testPrint sin impresora configurada devuelve E_PRINTER_UNCONFIGURED` | unconfigured path |
| `testPrint usa el perfil de papel cuando se envia un papel distinto` | `80mm` profile (48 cols / 576 dots) |

No real Electron, printer, or disk IO: `readFile`/`writeFile`/`renameFile`/`sendRaw`/`lock`/`webContents` are injected.

## Deviation from the Phase 2 contract

`Printing.saveConfig` is typed `Promise<PrinterConfigResponse>`, not `PrinterConfigResponse`.

Reason: the contract requires re-enumerating the renderer-supplied name with `resolvePrinter`, which is async (`getPrintersAsync`). A synchronous return cannot await that call, and saving without membership validation would break the Phase 1 security gate. `ipcMain.handle` already awaits a returned promise, so the IPC and preload surfaces are unchanged. `getConfig` stays synchronous.

## Work Unit Evidence (Phase 2)

| Evidence | Value |
|---|---|
| Focused test command | `cd taquilla && node --test electron/main/printing/test/index.test.cjs` |
| Exact result | Not executed — owner runs. Expected 17 pass (9 existing + 8 new). |
| Runtime harness | N/A in this phase — no Electron runtime is launched by the agent; the real path is `renderer → preload → ipcMain → facade` and is exercised by the owner's Electron/manual check. |
| Rollback boundary | Revert the five Phase 2 files; Phase 1 config/discovery and all existing print channels remain intact. No persisted-state or schema migration is involved. |

## Owner Commands (documented, not run)

```bash
cd taquilla
pnpm build:printing
pnpm test:printing                          # expect 103 pass / 0 fail
pnpm exec tsc -p tsconfig.printing.json     # verifies the async saveConfig type
```

Mark the updated OpenSpec artifact for git because `*.md` is ignored:

```bash
git add -f openspec/changes/configuracion-impresora/apply-progress.md
```

## No command was executed (Phase 2)

No Node, pnpm, npm, composer, test, build, install, linter, type-check, or network call was run. No commit, branch, or merge was created. All Phase 2 changes are uncommitted.
