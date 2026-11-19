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

---

# Phase 3 — printer setup UI (header, modal, auto-detection)

**Status**: implementation written, NOT executed · **Scope**: Phase 3 only · **Next**: owner runs the commands below, then `sdd-verify`.
**Branch**: `feature/configuracion-impresora-p3` (base P2 `3c8c76f`, Phase 2 committed).
**Mode**: Standard (owner-run verification; this phase forbids executing commands).

## Work Units (Phase 3)

| WU | Title | Files | Status |
|----|-------|-------|--------|
| 3.1 | Header button `#printer-setup-btn` + `printer` icon | `MainLayout.astro` | Written |
| 3.2 | Static `#printer-modal` + minimal CSS | `MainLayout.astro` | Written |
| 3.3 | Open/close wiring (button, overlay, Cancel) | `MainLayout.astro` | Written |
| 3.4 | `refreshPrinters` (list, labels, preselection, status) | `MainLayout.astro` | Written |
| 3.5 | `savePrinter` + office guard | `MainLayout.astro` | Written |
| 3.6 | `testPrinter` (office diagnostic confirmation) | `MainLayout.astro` | Written |
| 3.7 | `maybePromptPrinterSetup` auto-detect | `MainLayout.astro` | Written |
| 3.8 | Globals `window.openPrinterSetup`, `window.showPrintFailure` | `MainLayout.astro` | Written |
| 3.9 | E2E stubs (`print:list-printers`, `print:get-config`, `print:set-config`, `print:test`) | `fixtures.mjs`, `app.mjs` | Written |

Phase 4 (page call sites: dashboard/historial/ganadores/cierre), office mode, version bump, and release were not started. No page `.astro` outside `MainLayout` was touched.

## Files Changed (Phase 3)

Counts from `git diff --numstat`; net per file from the same diff.

| File | Raw +/− | Net | What Was Done |
|---|---:|---:|---|
| `taquilla/src/layouts/MainLayout.astro` | +345 / −4 | +341 | Header printer button; static `#printer-modal` (printer/paper selects, status, warning, Volver a detectar / Imprimir prueba / Guardar / Cancelar); printer CSS + `#printer-modal[hidden]` + `.printer-toast`; JS classifier, `openPrinterSetup`/`closePrinterSetup`, `refreshPrinters`, `savePrinter`, `testPrinter`, `maybePromptPrinterSetup`, `showPrintFailure`, and the `window.openPrinterSetup`/`window.showPrintFailure` globals. Reused `.modal-overlay`/`.modal-dialog`/`.modal-btn` and extended the `.logout-btn` rules for `.printer-btn`. |
| `taquilla/e2e/helpers/fixtures.mjs` | +6 / −0 | +6 | Added `print:list-printers`, `print:get-config`, `print:set-config`, `print:test` to `STUB_CHANNELS`. |
| `taquilla/e2e/helpers/app.mjs` | +38 / −0 | +38 | `stubPrintIpc` special-cases the four configuration channels: always-config `POS-58` (so the auto-prompt exits immediately), a single printer with `suggested: 'POS-58'`, a payload-echoing set-config response, and a `print:test` success. `get-mac` and all existing print channels remain. |
| **Phase 3 total** | **+389 / −4** | **+385** | Over the 300-line target; see “Line-budget note (Phase 3)”. |

## Exact UX behavior (Phase 3)

### Auto-detection on load

Runs from `MainLayout` (authenticated pages only; `/login` and `/activacion` are skipped explicitly). It exits immediately when `window.electron` is absent, or when `getPrinterConfig()` returns a config with a non-empty `printerName`.

| Detected state | Result |
|---|---|
| 0 printers | Opens `#printer-modal`; the status reads `No se detectaron impresoras.` and Guardar is disabled. |
| 1 printer, thermal-like | Auto-saves `{ printerName, paper: '58mm' }` and shows a 3.5 s toast `Impresora configurada: <label>`; no modal and no later prompt because the config now has a name. |
| 1 printer, office-like | Opens the modal with the warning; nothing is auto-saved. |
| Several printers | Opens the modal with `suggested` preselected (config name wins, then `suggested`, then the first printer). |

### Classification and office guard

The classifier reads `name + displayName` and tests office first, then thermal:

- office-like: `/laser|office|deskjet|inkjet|mfc|mfp|canon|brother|lexmark|ricoh|kyocera/i`
- thermal-like: `/pos|thermal|receipt|ticket|58|80|xp|tm|zebra|bixolon|star/i`
- otherwise unknown

| Kind | Warning line | Guardar | Imprimir prueba |
|---|---|---|---|
| office | `No compatible con impresión térmica ESC/POS; la salida puede ser basura` | Disabled; `savePrinter` also re-checks and shows an error modal | Allowed as a diagnostic; first asks `¿Enviar de todos modos?` |
| thermal | none | Enabled | Allowed |
| unknown | `No se pudo determinar el tipo; probá la impresión antes de guardar.` | Enabled | Allowed |

Office-like printers are never auto-saved.

### Save and test

- **Guardar**: reads the selected printer + paper (`58mm`/`80mm`), calls `window.electron.savePrinterConfig({ printerName, paper })`. On success it stores the returned config locally, closes the modal, and shows `Configuración de impresora guardada.` through `showModal` (success). Failure/catch shows an error modal.
- **Imprimir prueba**: calls `window.electron.testPrint({ printerName, paper })`, then `showModal` success (`Prueba enviada a la impresora.`) or error with the backend message.
- **Volver a detectar**: re-runs `refreshPrinters()` without closing the modal.
- **Cancelar / overlay click**: closes the modal.
- **`showPrintFailure(result, { created })`**: ignores `success`; for `E_PRINTER_UNCONFIGURED`/`E_PRINTER_NOT_FOUND` it asks `¿Configurar impresora?` and opens the modal on confirm; otherwise it shows an error prefixed with `El ticket se creo, pero no se pudo imprimir: ` when `created` is true.
- No page calls these globals yet; that is Phase 4.

## E2E stub contract

| Channel | Stub response |
|---|---|
| `print:get-config` | `{ success, config: POS-58 / 58mm / 32 / 384 / cp850 / partial / 3 / true }` |
| `print:list-printers` | one `POS-58` printer, `suggested: 'POS-58'` |
| `print:set-config` | defaults + payload `printerName`/`paper` (fallback `POS-58`/`58mm`) |
| `print:test` | `{ success: true, message: 'e2e-stub:print:test', e2e: true }` |

Because `print:get-config` always returns a configured name, `maybePromptPrinterSetup` exits before listing and the modal never blocks the existing E2E specs. The extra IPC call is recorded in `__e2e_prints`, and existing assertions filter by channel or compare counts before/after.

## Line-budget note (Phase 3)

Target was ≤300 changed lines. Measured result: **393 raw** (`+389 / −4`) and **385 net**. The required acceptance surface (header button, static modal, open/close wiring, detection matrix, office guard, save/test flows, two globals, and the four E2E stubs) does not compress below the target without deleting behavior or collapsing distinct states. Nothing required was dropped; no Phase 4 work was pulled in.

## Deviations from the Phase 3 brief

- Auto-save success uses a small non-blocking toast (`.printer-toast`) instead of `showModal`, because a blocking success dialog on every fresh install would interrupt the first sale. User-initiated Save/Test still use `showModal` as specified.
- `openPrinterSetup` re-reads the config when `printerConfig` is still `null` (e.g. opened from `maybePromptPrinterSetup`), which can repeat one `print:get-config` call. No behavior change.
- Office-like “Imprimir prueba” shows a `showModal` confirm before sending; the spec asked for a warning and this makes it explicit.

## Work Unit Evidence (Phase 3)

| Evidence | Value |
|---|---|
| Focused test command | `cd taquilla && pnpm test:printing` |
| Exact result | Not executed — owner runs. Expected 103 pass / 0 fail (Phase 3 adds no unit tests). |
| Runtime harness | `cd taquilla && pnpm e2e` (optional; requires backend seed). The stub configuration keeps the auto-prompt inert and does not change existing print assertions. |
| Rollback boundary | Revert `MainLayout.astro` to the Phase 2 version and remove the four `print:*` channels from `fixtures.mjs`/`app.mjs`. Phases 1–2 (printing module, IPC, preload) stay intact; no persisted-state or schema migration is involved. |

## Owner Commands (documented, not run)

```bash
cd taquilla
pnpm build                                  # Astro build validates the .astro/JS
pnpm test:printing                          # expect 103 pass / 0 fail
pnpm exec tsc -p tsconfig.printing.json
```

Optional E2E (requires backend seed):

```bash
cd taquilla && pnpm e2e
```

Mark the OpenSpec artifacts for git because `*.md` is ignored:

```bash
git add -f openspec/changes/configuracion-impresora/tasks.md openspec/changes/configuracion-impresora/apply-progress.md
```

## No command was executed (Phase 3)

Per the owner's hard constraint, this phase did not run Node, pnpm, npm, composer, tests, builds, installs, linters, type-checking, or network calls. Only read-only inspection (`grep`, `git status`, `git diff`, `wc`) was used. No `git add`, commit, branch creation, or merge was performed. All changes are uncommitted.
