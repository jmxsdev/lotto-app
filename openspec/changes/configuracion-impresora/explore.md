# Explore: printer configuration and discovery (Phase 1)

Replace every environment-variable knob in the native ESC/POS module with a single
file-backed configuration (`printer-config.json`), add the writer that file never had,
and turn discovery into a list/suggest/validate surface that a settings UI can consume
later. Phase 1 stops at the core modules and their tests; IPC, preload, UI, and the
`print:test` wiring stay out.

Baseline: branch `feature/configuracion-impresora`, base `main` `1930813`, clean worktree.
Exploration is read-only: no source, test, package, or config file was modified.

All paths below are relative to `taquilla/electron/main/` unless a longer path is shown.

## Decision summary

| Question | Answer | Evidence |
|---|---|---|
| Is env support isolated? | Yes. Seven config keys plus `LOTTO_PRINT_TEST` are read in one function, `resolveConfig`. | `printing/infra/config.ts:39-65` |
| Does a config writer exist? | No. The file is read-only; nothing in `electron/` calls `writeFile`/`writeFileSync`. | `printing/infra/config.ts:13-29`; repo grep |
| Can the UI list or pick printers today? | No. There is no IPC channel, no preload method, and discovery only resolves one name. | `main/ipcHandlers.cjs:19-88`, `preload/preload.cjs:3-31`, `printing/infra/discovery.ts:47-69` |
| Does removing env force edits outside the config module? | Yes: `index.ts` defaults `env = process.env`, and `main.cjs` passes `process.env` and calls the startup hook. | `printing/index.ts:43`, `main/main.cjs:269,286-288` |
| Recommended Phase 1 split | P1a = types + config + writer + env/startup removal + config/index tests; P1b = discovery list/suggest/validate + discovery tests. | Estimates below stay under 300 changed lines each. |

## Current pipeline map

```
renderer (dashboard/historial/cierre/ganadores .astro)
  -> window.electron.printTicket / printCierre / printReporte        (preload/preload.cjs:5-7)
  -> ipcMain.handle('print-ticket' | 'print-cierre' | 'print-reporte') (main/ipcHandlers.cjs:29-72)
  -> printing.printDocument({ kind, data, webContents = event.sender }) (printing/index.ts:52-71)
       -> resolveEffectiveConfig()                  printing/index.ts:50 -> infra/config.ts:39
       -> resolvePrinter({ printerName, getPrintersAsync })  infra/discovery.ts:47
       -> buildBuffer({ kind, data, config })       printing/index.ts:36-40 (pure builders)
       -> sendRaw(printer.name, buffer)             infra/transport.ts
```

Startup path (to be deleted in Phase 1):

```
app.whenReady -> createPrinting({ env: process.env, userDataPath, getWindow })  main/main.cjs:268-272
  -> registerIpcHandlers(...)                                                   main/main.cjs:273
  -> await createWindow()                                                       main/main.cjs:284
  -> printing.maybeRunStartupTest()                                             main/main.cjs:286-288
       -> config.printTest === true (LOTTO_PRINT_TEST=1) -> printTest.render(config) -> sendRaw
```

`main.cjs` loads the compiled module (`./printing/dist/index.js`, `main/main.cjs:5`).
`printing/dist/` is git-ignored (`.gitignore`, "Salida compilada de impresion nativa"),
so a rebuild is required before any Electron run.

## Environment-variable inventory

### Keys read in code (all in `printing/infra/config.ts`)

| Key | Read at | Effect | Removal slice |
|---|---|---|---|
| `LOTTO_PRINTER_NAME` | `config.ts:42` | `EffectiveConfig.printerName`; highest precedence | P1a |
| `LOTTO_PRINT_CODEPAGE` | `config.ts:44` | `codePage` enum | P1a |
| `LOTTO_PRINT_CUT` | `config.ts:45` | `cut` enum | P1a |
| `LOTTO_PRINT_FEED` | `config.ts:46` | `feedLines` (0–10) | P1a |
| `LOTTO_PRINT_CUT_PER_BLOCK` | `config.ts:47` | `cutPerBlock` boolean | P1a |
| `LOTTO_PRINT_COLUMNS` | `config.ts:48` | explicit columns override (20–80) | P1a |
| `LOTTO_PRINT_PAPER` | `config.ts:57` | paper profile alias (`58` / `80` / `80-512`) | P1a |
| `LOTTO_PRINT_TEST` | `config.ts:64` | `printTest` boolean → startup slip | P1a |

### Env plumbing that must disappear with those keys

| Location | What it is |
|---|---|
| `printing/infra/config.ts:30` | `envValue(env, key)` helper |
| `printing/infra/config.ts:31` | `pick(env, file, fallback)` precedence helper |
| `printing/infra/config.ts:39` | `resolveConfig({ env = {}, ... })` |
| `printing/types.ts:134` | `ResolveConfigInput.env` |
| `printing/types.ts:162` | `CreatePrintingOptions.env` |
| `printing/index.ts:43` | `env = process.env` default |
| `main/main.cjs:269` | `env: process.env` passed to `createPrinting` |

### Residual env mentions (not reads)

| Location | What it is | Phase 1 action |
|---|---|---|
| `printing/infra/discovery.ts:53` | error text "Defina LOTTO_PRINTER_NAME o printer-config.json" | rewrite in P1b (config-only wording) |
| `printing/infra/discovery.ts:65` | error text "Corrija LOTTO_PRINTER_NAME o printer-config.json" | rewrite in P1b |
| `printing/documents/printTest.ts:47` | printed line `Prueba: LOTTO_PRINT_TEST=1` | **left untouched** per Phase 1 constraint; remove in Phase 2 with `print:test` wiring |
| `printing/types.ts:8` | doc comment "env > printer-config.json > defaults" | update in P1a |
| `printing/index.ts:45,74` | comments mentioning `getWindow` / `LOTTO_PRINT_TEST` | deleted with the code in P1a |
| `../docs/dev/estructura.md:194` | documents all eight env keys | update in P1a (1-line replacement) |
| `openspec/changes/impresion-escpos-nativa/design.md:729-737,774,778,791` | historical design of the env contract | historical record; do not edit |

Consequence for acceptance: a grep-based check must target env **reads**
(`process\.env`, `envValue`, `pick\(env`), not the `LOTTO_` substring, until Phase 2
removes `printTest.ts:47`.

## Current config behavior (verified)

| Aspect | Today | Evidence |
|---|---|---|
| File | `app.getPath('userData')/printer-config.json` | `config.ts:6`, `main/main.cjs:270` |
| Direction | read-only; no writer anywhere | `config.ts:13-29`; grep finds no `writeFile` in `electron/` |
| Missing file | `{}`, no warning | `config.ts:16-20` |
| Malformed / non-object JSON | `{}` plus `Invalid printer-config.json; using defaults.` | `config.ts:21-28` |
| Precedence | env > file > defaults | `config.ts:31,42-48` |
| Defaults | `paper 58mm`, `columns 32`, `dots 384`, `codePage cp850`, `cut partial`, `feedLines 3`, `cutPerBlock true` | `config.ts:9-11`, `core/profiles.ts:7-12` |
| `printerName` | `null` when absent/blank; no default printer | `config.ts:42-43` |
| Paper aliases | `58`→`58mm`, `80`→`80mm`, `80-512`→`80mm-512` | `core/profiles.ts:12` |
| Validation policy | invalid enum/int falls back to default and appends a warning; printing never blocks | `config.ts:49-60`, `profiles.ts:28-39` |
| `version` field | ignored; design showed `"version": 1` but no code reads it | `design.md:739-748`; `config.ts:13-29` |
| Return shape | `EffectiveConfig` incl. `printTest` and `readonly warnings` | `types.ts:9-20` |
| Injection seam | `readFile` defaults to `fs.readFileSync` | `types.ts:131-137`, `config.ts:39` |

Gaps: no writer, no schema/`version` handling on write, no atomic replace, no
"save only an enumerated printer" guard, and `printerName` can hold an arbitrary string
until print time.

## Current discovery behavior (verified)

| Aspect | Today | Evidence |
|---|---|---|
| Name syntax | strict allowlist `^[\p{L}\p{N} ._#,-]{1,128}$` | `discovery.ts:4` |
| Syntax error | `E_CONFIG_INVALID`, fixed Spanish message, before enumeration | `discovery.ts:6,56` |
| No name | `E_PRINTER_UNCONFIGURED`, lists queues, error text names the env var | `discovery.ts:50-54` |
| Empty enumeration | `E_PRINTER_NOT_FOUND` "No hay impresoras instaladas…" | `discovery.ts:58-61` |
| Matching | exact queue name, then case-insensitive exact; no substring | `discovery.ts:63` |
| No match | `E_PRINTER_NOT_FOUND` with available list and env-var hint | `discovery.ts:64-67` |
| Enumeration failure/timeout | both mapped to `E_DISCOVERY_TIMEOUT` (5 s default) | `discovery.ts:5,19-45` |
| Metadata | reduced to names by `namesOf`; `displayName`/`description` discarded | `discovery.ts:14-17` |
| List / suggest | no such functions | `discovery.ts:71` exports only `DEFAULT_TIMEOUT_MS, NAME_RE, resolvePrinter` |
| Dead contract | `PrinterDiscovery` interface is declared and never referenced | `types.ts:106-108` |

Gaps: no enumeration API for the UI, no deterministic suggestion heuristic, no reusable
validator export, and error copy that teaches an operator to set a variable that Phase 1
deletes.

## Baseline test suite

`npm run test:printing` = `node scripts/build-printing.mjs` (esbuild → `printing/dist/`,
CommonJS) then `node --test` from `printing/`. Node's test runner discovers every
`test/**/*.test.cjs`, so the counts below are the whole native-printing suite.

| File | Tests |
|---|---|
| `core/encoder`, `encode`, `format`, `layout`, `profiles` | 7 + 6 + 4 + 4 + 2 |
| `documents/cierre`, `printTest`, `recibo`, `reporte`, `ticket` | 4 + 6 + 6 + 5 + 6 |
| `index.test.cjs` | 13 |
| `infra/config`, `discovery`, `lock`, `transport` | 6 + 8 + 4 + 6 |
| `purity.test.cjs` | 2 |
| **Total** | **89** |

Environment-sensitive tests to change:

| Test | File:line | Why |
|---|---|---|
| "LOTTO_PRINT_PAPER mapea 58, 80 y 80-512…" | `test/infra/config.test.cjs:30-38` | set through `env` |
| "la precedencia es env > archivo > predeterminado" | `test/infra/config.test.cjs:40-64` | asserts env precedence |
| "printerName queda nulo … y LOTTO_PRINT_TEST se resuelve" | `test/infra/config.test.cjs:85-89` | asserts env resolution |
| "la configuracion predeterminada …" | `test/infra/config.test.cjs:15-28` | asserts `printTest:false` (field removed) |
| "sin/con LOTTO_PRINT_TEST …" (3 cases) | `test/index.test.cjs:179-220` | startup hook removed |
| `create()` helper | `test/index.test.cjs:50-56` | injects `env` |

`documents/printTest.test.cjs` (6 tests) does not assert the env literal, so it stays
green while `printTest.ts` is untouched.

## Phase 1 scope

### In scope

| Work | Files | Slice |
|---|---|---|
| Remove env fields/options from contracts; drop `printTest`, `maybeRunStartupTest`, `getWindow`; add writer/list/suggest types | `printing/types.ts` | P1a + P1b |
| File-only `resolveConfig`; add `saveConfig` (validate → canonical object → atomic write); keep warnings policy | `printing/infra/config.ts` | P1a |
| `listPrinters`, `suggestPrinters`, exported name validation, canonical enumerated match; config-only error copy | `printing/infra/discovery.ts` | P1b |
| Remove `env`, `getWindow`, `printTest` import, and `maybeRunStartupTest` from the composition root | `printing/index.ts` | P1a |
| Remove env pass-through, `getWindow`, and the startup hook | `main/main.cjs` | P1a |
| Rewrite env tests; add writer tests; delete startup tests | `test/infra/config.test.cjs`, `test/index.test.cjs` | P1a |
| Add list/suggest/validate tests | `test/infra/discovery.test.cjs` | P1b |
| Replace the env list with `printer-config.json` | `../docs/dev/estructura.md:194` | P1a |

Estimated result: 89 → ~86 tests after P1a, ~91 after P1b.

### Out of scope (later phases)

| Phase | Content |
|---|---|
| 2 | IPC channels (`print:list-printers`, `print:get-config`, `print:save-config`, `print:test`), preload bridge methods, wiring `printTest.render` to `print:test`, removing `printTest.ts:47` |
| 3 | Settings UI (printer list, suggestion, paper/codepage/cut fields, test button) |
| 4 | Error handling and operator-facing UX for config/discovery failures |
| 5 | Docs, changelog, release |

## P1a / P1b split

Estimate method: authored additions + deletions, comment-only edits counted as one line.
Bands reflect uncertainty; the tasks phase must re-measure before apply.

### P1a — file config becomes the only source (≈250 lines, band 230–290)

| File | Change | Est. changed |
|---|---|---|
| `printing/types.ts` | delete `printTest`, `maybeRunStartupTest`, `getWindow`, `env`; add `WriteFile`, save input/output types; fix the precedence comment | 18 |
| `printing/infra/config.ts` | drop `envValue`/`pick`/env destructure; validate and serialize the file object; `saveConfig` with tmp-write + rename; export defaults for reuse | 70 |
| `printing/index.ts` | remove `process.env`, `getWindow`, `printTest` import, `maybeRunStartupTest`, and the `Printing` member | 42 |
| `main/main.cjs` | remove `env:` and `getWindow:` from `createPrinting`; delete the startup hook block | 6 |
| `test/infra/config.test.cjs` | file-only profile/precedence tests; defaults without `printTest`; writer round-trip, malformed-input rejection, atomic replace | 80 |
| `test/index.test.cjs` | delete 4 startup tests and the `printTest` require; `create()` reads a config file instead of env | 45 |
| `../docs/dev/estructura.md:194` | env list → `printer-config.json` reference | 4 |

Why `index.ts`/`main.cjs` belong here: they are the only producers of the env object and
the only caller of `maybeRunStartupTest`. Removing the env contract without them leaves
dead options and a hook that returns `null` forever — TypeScript would not catch the
runtime behavior change, and the tests would still seed `env`.

### P1b — discovery becomes a UI-ready surface (≈210 lines, band 190–240)

| File | Change | Est. changed |
|---|---|---|
| `printing/infra/discovery.ts` | export `listPrinters` (enumerated names + metadata), `suggestPrinters` (deterministic heuristic, no auto-select), `validatePrinterName`, canonical enumerated match helper; config-only error text | 105 |
| `printing/types.ts` | list/suggestion contracts; retire the unused `PrinterDiscovery` interface | 16 |
| `test/infra/discovery.test.cjs` | list preserves metadata, timeout/rejection shapes, suggestion ranking and empty list, validation reuse, error copy without env names | 85 |
| `../docs/dev/estructura.md:194` | if the P1a edit overflows, the discovery sentence lands here | 4 |

### Verification commands (owner)

```bash
# from taquilla/
npm run test:printing                      # build + full 89-test suite
node --test electron/main/printing/test/infra/config.test.cjs
node --test electron/main/printing/test/infra/discovery.test.cjs
node --test electron/main/printing/test/index.test.cjs

# acceptance greps (reads, not historical strings)
grep -rn "process\.env\|envValue\|pick(env" electron/main/printing
grep -rn "maybeRunStartupTest\|getWindow" electron/main
grep -rn "LOTTO_PRINT" electron/main/printing/infra electron/main/printing/index.ts
```

`npm run test:printing` rebuilds `printing/dist/` (git-ignored); no Electron, printer, or
network access is needed.

## Decisions to lock in design

| Decision | Options | Recommendation |
|---|---|---|
| Writer location | `infra/config.ts` vs new `infra/configWriter.ts` | keep in `infra/config.ts`; one file owns the schema |
| Save semantics | full replace vs merge | full replace, `version: 1` written explicitly, unknown keys dropped |
| Write safety | direct write vs tmp + rename | tmp file in the same directory + `renameSync` |
| Save validation | syntax only vs enumeration membership | both: syntax via `NAME_RE`, membership via enumeration returning the canonical queue name |
| Enumeration shape | `string[]` vs `PrinterInfo[]` | `PrinterInfo[]`; the UI needs `displayName` eventually |
| Suggestion rule | substring vs deterministic token score | named tokens (`pos`, `58`, `80`, `thermal`/`termica`, `receipt`, `epson`, `xprinter`, `bixolon`, `star`) with stable tie-break by enumeration order |
| New error code for write failures | reuse `E_CONFIG_INVALID` vs add `E_CONFIG_WRITE_FAILED` | add it; write failures are not validation failures |

## Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Commissioning slip becomes unreachable until Phase 2 wires `print:test` | No way to print the ruler/accent test from the app | `printTest.render` and its 6 tests stay; document the gap and ship Phase 2 before release |
| Fresh install has no UI until Phase 3 | Every print fails with `E_PRINTER_UNCONFIGURED` | Rewrite that message in P1b to point at the settings screen, not an env var; keep the available-printers hint |
| Tests asserting env precedence/startup must change | Suite can regress silently if assertions are deleted rather than converted | Convert env tests to file tests; keep the same behavioral guarantees (defaults, invalid values, precedence file > default) |
| `printTest.ts:47` still prints `LOTTO_PRINT_TEST=1` | A grep-based "no env references" check fails | Scope the check to env reads; remove the literal in Phase 2 (1 line, no test asserts it) |
| P1a sits near the 300-line cap | Slice could exceed the review budget | Move the docs line and any writer edge-case tests to P1b if the measured diff exceeds 300 |
| `printing/dist/` is git-ignored and loaded by `main.cjs:5` | Manual Electron runs can use stale compiled code | Always run `npm run build:printing` before `electron:dev` |
| New `openspec/changes/configuracion-impresora/explore.md` is untracked-ignored | Artifact silently missing from the commit | `.gitignore:47` has `*.md`; add it with `git add -f` or add a change-scoped negation |
| Stale docs/design continue to document env | Operators follow dead instructions | Update `docs/dev/estructura.md:194`; leave historical `openspec/changes/impresion-escpos-nativa/*` as-is |
| Writer breaks the read path | Corrupt/truncated config → silent defaults | Atomic replace + a round-trip test (write then `resolveConfig` reads the same values) |

## Ready for proposal

Yes. The env surface is small and centralized, the missing writer and discovery APIs are
confirmed by code, and the Phase 1 slices are separable at the module boundary
(config/index/main vs discovery). The proposal should adopt the P1a/P1b split and the
"Phase 1 stops before IPC" boundary exactly as scoped above.
