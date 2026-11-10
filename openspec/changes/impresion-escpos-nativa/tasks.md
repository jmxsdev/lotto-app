# Tasks: Native ESC/POS printing for the taquilla

Change `impresion-escpos-nativa` · baseline `feature/impresion-escpos` @ `3855a43` · design.md is authoritative.

**58-first**: primary target is a 58 mm thermal printer (384 dots, 32 columns). 80 mm / 48 columns is opt-in only.

## Module tree (Option A + single-flight lock)

```
taquilla/electron/main/printing/
├── core/{encode,encoder,format,layout,profiles}.cjs
├── infra/{config,discovery,transport,lock}.cjs
├── documents/{ticket,recibo,cierre,reporte,printTest}.cjs   # S3a+
├── test/
│   ├── purity.test.cjs
│   ├── core/*.test.cjs
│   ├── infra/*.test.cjs
│   └── documents/*.test.cjs
└── index.cjs
```

`queue.cjs` is deleted. No cap-20 queue, no `pending`, no `E_PRINT_QUEUE_FULL`. Concurrency is a single-flight lock (`infra/lock.cjs`): one in-flight print; a second print while one is running rejects `E_PRINT_BUSY`.

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | S1a ~381 · S1b ~195 · S2 ~494 · S3a ~408 · S3b ~168 · S4a ~129 · S4b ~381 · S5 ~498 (del) · S6a ~80 · S6b ~2,879 (mechanical TS migration, `size:exception`) — total ~5,613 authored+deleted |
| 400-line budget risk | Medium (S2/S3a/S4b/S5 near 500; S1a+S1b = 576 split into two PRs; S6b exceeds 500 and is recorded as `size:exception`) |
| Chained PRs recommended | Yes |
| Chain strategy | feature-branch-chain |
| Delivery strategy | auto-chain |
| Suggested split | S1a → S1b → S2 → S3a → S3b → S4a → S4b → S5 → S6a → S6b (slice-per-PR, WU commits inside) |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: Medium

## Branch / PR plan

Tracker `feature/impresion-escpos` (already at HEAD) accumulates; only the tracker merges to `main`.

| # | Branch | Base | PR targets |
|---|--------|------|-----------|
| PR1 | `feature/impresion-escpos/s1a-core` | `feature/impresion-escpos` | tracker |
| PR2 | `feature/impresion-escpos/s1b-purity-ci` | PR1 branch | PR1 branch |
| PR3 | `feature/impresion-escpos/s2-infra-lock` | PR2 branch | PR2 branch |
| PR4 | `feature/impresion-escpos/s3a-ticket-live` | PR3 branch | PR3 branch |
| PR5 | `feature/impresion-escpos/s3b-receipt-live` | PR4 branch | PR4 branch |
| PR6 | `feature/impresion-escpos/s4a-print-test` | PR5 branch | PR5 branch |
| PR7 | `feature/impresion-escpos/s4b-close-report` | PR6 branch | PR6 branch |
| PR8 | `feature/impresion-escpos/s5-removal` | PR7 branch | PR7 branch |
| PR9 | `feature/impresion-escpos/s6a-build-infra` | PR8 branch | PR8 branch |
| PR10 | `feature/impresion-escpos/s6b-ts-migration` | PR9 branch | PR9 branch |

Merge order S1a → S6b. A child diff showing a previous slice is a base bug: retarget/rebase until only the current WU appears. Tracker PR stays draft/no-merge until all eight land and hardware is signed off. Rollback: PR1/PR2/PR3 revert (inert); PR4/PR5/PR7 `LOTTO_PRINT_MODE=html`; PR6 revert (no customer path); PR8 revert the PR (native already live). Final gate: `feature/impresion-escpos` → `main` only after the owner signs off native output on the real 58 mm hardware.

Builder map by slice: S3a registers `ticket` only; S3b adds `recibo`; S4b adds `cierre` + `reporte`. No dynamic `require` by computed path.

## Suggested Work Units (summary)

| Unit | Goal | PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|----|----------------------|-----------------|-------------------|
| S1a | Pure core byte pipeline (encode/encoder/format/layout/profiles) | PR1 | `node --test taquilla/electron/main/printing` | N/A (pure, no IO) | revert; no wiring |
| S1b | Purity gate + `test:printing` script + CI step | PR2 | `node --test taquilla/electron/main/printing` | N/A (static scan) | revert; no wiring |
| S2 | Config/discovery/transport/lock (inert, 58-first defaults) | PR3 | `node --test taquilla/electron/main/printing` | N/A (fakes for `getPrintersAsync`/spooler) | revert; nothing calls it |
| S3a | Orchestration + sale ticket live (32-first) | PR4 | `node --test taquilla/electron/main/printing` | Windows POS: print a sale ticket (32-col) | `LOTTO_PRINT_MODE=html` |
| S3b | Receipt document + builder map | PR5 | `node --test taquilla/electron/main/printing` | Windows POS: print a prize receipt (32-col) | `LOTTO_PRINT_MODE=html` |
| S4a | Commissioning `print:test` (32-col ruler) + startup hook | PR6 | `node --test taquilla/electron/main/printing` | Windows POS: `LOTTO_PRINT_TEST=1` slip | revert; no customer path |
| S4b | Close + report documents + 2 channels | PR7 | `node --test taquilla/electron/main/printing` | Windows POS: print close + range report | `LOTTO_PRINT_MODE=html` |
| S5 | Removal of HTML rollback surface | PR8 | `node --test taquilla/electron/main/printing` | Windows POS: re-print after deletion | revert PR (native live) |
| S6a | esbuild build infrastructure for `printing/` | PR9 | `node --test taquilla/electron/main/printing` | `pnpm build:printing` then `pnpm test:printing` | revert; no source change |
| S6b | Mechanical `.cjs` → `.ts` migration (size:exception) | PR10 | `pnpm test:printing` (89 tests) | `pnpm build:printing` + `tsc -p tsconfig.printing.json` | revert PR (tests still green) |

---

## S1a Core modules (pure) — slice total ~381 lines

Files: `core/{encode,encoder,format,layout,profiles}.cjs` + `test/core/*.test.cjs`. All pure; no `electron`/`fs`/`node:net`/`electron-pos-printer`.

| ID | Title | Files (C/M/D) | Depends | Acceptance (observable) | Tests | Est. lines | Commit |
|----|-------|---------------|---------|--------------------------|-------|-----------|--------|
| WU-1a.1 | Charset encode module | C `taquilla/electron/main/printing/core/encode.cjs`, `printing/test/core/encode.test.cjs` | — | `sanitize` strips C0/C1/`0x7F`/ESC/FS/GS; `\r\n`/`\r`/`\n`→space; `encodeText` maps Spanish set 1 char=1 byte; miss→ASCII fold→`?`; `lineBytes` clamps `columns`/`MAX_JOB_BYTES` | Golden CP850/CP437 bytes for `á é í ó ú ü ñ Ñ Á É Í Ó Ú ¿ ¡ °`; CP858 `€`=`0xD5`; CP1252 map; `ascii` transliteration; unknown→`?`; empty/null; 96-col clamp | ~95 | `feat(taquilla): agrega encoder puro de charset ESC/POS (core/encode.cjs)` |
| WU-1a.2 | ESC/POS command builders | C `taquilla/electron/main/printing/core/encoder.cjs`, `printing/test/core/encoder.test.cjs` | WU-1a.1 | Command table bytes exact; title `1D 21 01` double-height, reset `1D 21 00`, **never** `1D 21 10`; `cut:'none'` emits no `GS V` + final feed 5; full cut `1D 56 41 00` | `ESC @`=`1B 40`; `ESC t` 0/2/19/16; `ESC a`; `ESC E`; `ESC d`; `GS V 42/41`; full-document golden | ~100 | `feat(taquilla): agrega constructores de comandos ESC/POS (core/encoder.cjs)` |
| WU-1a.3 | Deterministic formatters | C `taquilla/electron/main/printing/core/format.cjs`, `printing/test/core/format.test.cjs` | — | Money dot-thousands/comma-decimals/2 fixed; date `dd/mm/yyyy hh:mm`; no Intl/ICU | `1.005→1,01`; `2.675→2,68`; `1234567.891`; `-12,5`; `-0→0,00`; NaN/undefined; ISO `Z` −04 shift; microsecond `...00.000000Z`; `YYYY-MM-DD` no shift; `9/11/2026→09/11/2026`; `2:30:15 p. m.→14:30`; `12:00 a. m.→00:00`; `12:00 p. m.→12:00` | ~95 | `feat(taquilla): agrega formateadores deterministicos de dinero/fecha (core/format.cjs)` |
| WU-1a.4 | Geometry + paper profiles | C `taquilla/electron/main/printing/core/layout.cjs`, `core/profiles.cjs`, `printing/test/core/layout.test.cjs`, `printing/test/core/profiles.test.cjs` | — | `pad`/`truncate`/`wrap` code-point safe; `row` asserts `sum(widths)===columns`; **default profile `58mm`/32 cols/384 dots**; 48 and 42 opt-in | Surrogate pair never split; `row` mismatch throws; long-word hard-split; continuation indent; default 58/32/384; 48/42 opt-in; `columns` override 20–80; out-of-range→default+warning | ~91 | `feat(taquilla): agrega geometria de columnas y perfiles de papel (core/layout+profiles)` |

## S1b Purity gate + CI — slice total ~195 lines

| ID | Title | Files (C/M/D) | Depends | Acceptance (observable) | Tests | Est. lines | Commit |
|----|-------|---------------|---------|--------------------------|-------|-----------|--------|
| WU-1b.1 | Purity scan + test runner + CI | C `printing/test/purity.test.cjs`; M `taquilla/package.json` (+3 `test:printing`), `.github/workflows/ci-cd.yml` (+4) | S1a | `pnpm test:printing` runs pure suite; CI `front-checks` gates it; purity scan fails on any `require('electron'|'fs'|'node:net'|'electron-pos-printer')` in `core/` and `documents/` pure files | `purity.test.cjs` static scan of core + document modules | ~195 | `feat(taquilla): habilita tests puros de impresion en CI (test:printing + purity)` |

`package.json` script: `"test:printing": "node --test electron/main/printing"` (taquilla cwd). CI (repo root): add `node --test taquilla/electron/main/printing` to `front-checks` after `check-pure.mjs` (`.github/workflows/ci-cd.yml:105`).

## S2 Infra: config + discovery + transport + lock — slice total ~494 lines, 24 tests

Files: `infra/{config,discovery,transport,lock}.cjs` + `test/infra/*.test.cjs`. Inert (nothing calls them yet). No `tcp9100` code. 58-first defaults land here. Concurrency = single-flight lock (no queue).

| ID | Title | Files (C/M/D) | Depends | Acceptance (observable) | Tests | Est. lines | Commit |
|----|-------|---------------|---------|--------------------------|-------|-----------|--------|
| WU-2.1 | Config resolution | C `taquilla/electron/main/printing/infra/config.cjs`, `printing/test/infra/config.test.cjs` | S1b | Env keys `LOTTO_PRINTER_NAME/PRINT_MODE/PRINT_TEST/PRINT_PAPER/PRINT_COLUMNS/PRINT_CODEPAGE/PRINT_CUT/PRINT_FEED/PRINT_CUT_PER_BLOCK`; `LOTTO_PRINT_PAPER` accepts `58`·`80`·`80-512`; **defaults `paper:'58mm'`, `columns:32`, `dots:384`**; precedence env > `printer-config.json` > default; malformed JSON → warning; invalid enum/int → default + warning | 6 tests: default 58/32/384; `LOTTO_PRINT_PAPER=80`→48; env>file>default; malformed JSON ignored+warn; invalid enum fallback; missing name stays null | ~145 | `feat(taquilla): agrega resolucion de config de impresora (infra/config.cjs)` |
| WU-2.2 | Discovery (exact queue) | C `taquilla/electron/main/printing/infra/discovery.cjs`, `printing/test/infra/discovery.test.cjs` | WU-2.1 | Strict allowlist `^[\p{L}\p{N} ._#,-]{1,128}$`; exact then case-insensitive match; `PostScript` never matches `POS`; errors map `E_CONFIG_INVALID`/`E_PRINTER_UNCONFIGURED`/`E_PRINTER_NOT_FOUND`/`E_DISCOVERY_TIMEOUT` with queue list | 8 tests: exact match; case-insensitive; `PostScript`≠`POS`; not-found lists queues; empty list; 5 s timeout; missing name; metacharacter names rejected even when enumerated | ~140 | `feat(taquilla): agrega descubrimiento por nombre exacto de cola (infra/discovery.cjs)` |
| WU-2.3 | Transport + lock | C `taquilla/electron/main/printing/infra/transport.cjs`, `infra/lock.cjs`, `printing/test/infra/transport.test.cjs`, `printing/test/infra/lock.test.cjs` | WU-2.2 | Lazy `require('electron-pos-printer')`; static `PosPrinter.sendRawCommand(name, buffer)` (no `new`); 15 s timeout `E_TRANSPORT_TIMEOUT`; no retry/fallback. `createLock()` → `{run, isBusy}`; second `run` while busy rejects `E_PRINT_BUSY`; release in `finally`; non-function `E_PRINT_LOCK_INVALID`; **no waiting, no cap** | 6 transport tests (static call once, exact name + Buffer hex==golden, reject→`E_TRANSPORT_FAILED`, timeout, no legacy after raw, default timeoutMs) + 4 lock tests (`{run,isBusy}` shape, `finally` release on throw, busy→`E_PRINT_BUSY`, non-function→`E_PRINT_LOCK_INVALID`) | ~209 | `feat(taquilla): agrega transporte spooler y lock single-flight (infra/transport+lock)` |

## S3a Orchestration + ticket live (32-first) — slice total ~408 lines

Files: `printing/index.cjs`, `documents/ticket.cjs`, `ipcHandlers.cjs` wiring, `main.cjs`. First live native path. 32-column 2-line-per-bet is the product format.

| ID | Title | Files (C/M/D) | Depends | Acceptance (observable) | Tests | Est. lines | Commit |
|----|-------|---------------|---------|--------------------------|-------|-----------|--------|
| WU-3a.1 | Sale ticket document (32-first) | C `taquilla/electron/main/printing/documents/ticket.cjs`, `printing/test/documents/ticket.test.cjs` | S1b | 2-line bet (line1 `n + jugada`, line2 `Hora? Bs <x> $ <y>` right-aligned); long jugada wraps; money-pair split at 13 chars; multi-game group rows `-- <game>`; aligned totals; no prize field | 32-col primary goldens (2-line bet, wrap, split, multi-game, totals, no prize); 48-col opt-in table golden | ~195 | `feat(taquilla): agrega layout nativo de ticket de venta 32 cols (documents/ticket.cjs)` |
| WU-3a.2 | Composition root | C `taquilla/electron/main/printing/index.cjs`, `printing/test/index.test.cjs` | S2, WU-3a.1 | `createPrinting` returns `printDocument`/`maybeRunStartupTest`/`resolveEffectiveConfig`/`isHtmlMode`; `buildBuffer({kind,data,config})` uses full `EffectiveConfig`; static builder map `{ticket}` only; lock wraps resolve→build→send; success message `Ticket enviado a impresora` | `printDocument` with fakes; exact ticket success string; `html` mode delegates to `legacy`; every `PrintError`→`{success:false,message}`; payload-too-large | ~150 | `feat(taquilla): agrega composition root de impresion (index.cjs)` |
| WU-3a.3 | IPC wiring (ticket live) | M `taquilla/electron/main/ipcHandlers.cjs` (+45), `main.cjs` (+8) | WU-3a.2 | 3 validations verbatim; `print-ticket` handler ~6 lines passing `event.sender` + `legacy`; `registerIpcHandlers(upstream, updater, printing)`; `main.cjs` builds `createPrinting({env,userDataPath,getWindow})` | Verified via `index.test.cjs` + unchanged E2E (no new channel, `STUB_CHANNELS` untouched) | ~53 | `feat(taquilla): enruta print-ticket a impresion nativa preservando contrato IPC` |

Handler diff shape (preserve validation text): replace `const win = BrowserWindow.getFocusedWindow(); const html = generateTicketHtml(ticketData); return await printHtml(win, html, 'Ticket')` with `return printing.printDocument({ kind, data: ticketData, webContents: event.sender, legacy })`.

## S3b Receipt live (32 stacked) — slice total ~168 lines

| ID | Title | Files (C/M/D) | Depends | Acceptance (observable) | Tests | Est. lines | Commit |
|----|-------|---------------|---------|--------------------------|-------|-----------|--------|
| WU-3b.1 | Receipt document (32 stacked) | C `taquilla/electron/main/printing/documents/recibo.cjs`, `printing/test/documents/recibo.test.cjs` | S3a | 8-field stacked block per bet; money pair `Bs x / $ y` when fits in 19 else one currency per line; prize totals; states | 32-col primary golden (8 fields, 13-char money split, states, totals); 48-col opt-in golden | ~140 | `feat(taquilla): agrega layout nativo de comprobante de pago 32 cols (documents/recibo.cjs)` |
| WU-3b.2 | Builder map + receipt wiring | M `printing/index.cjs` (+3 map), `printing/test/index.test.cjs` | WU-3b.1 | Builder map `{ticket, recibo}`; `print-ticket` with `kind:'recibo'` → recibo document; success message still `Ticket enviado a impresora` | `index.test.cjs`: recibo kind routes to recibo builder; recibo → `Ticket` success string | ~28 | `feat(taquilla): registra comprobante en el mapa de builders` |

## S4a Commissioning `print:test` (32 ruler) — slice total ~129 lines

| ID | Title | Files (C/M/D) | Depends | Acceptance (observable) | Tests | Est. lines | Commit |
|----|-------|---------------|---------|--------------------------|-------|-----------|--------|
| WU-4a.1 | Commissioning slip document | C `taquilla/electron/main/printing/documents/printTest.cjs`, `printing/test/documents/printTest.test.cjs` | S1b | Pure `printTestSlip(config)` only: digits + mark ruler exactly `config.columns` chars (default 32, `\|` at 0,5,…,30); all 17 chars incl `Ü`; 4 labelled code-page lines; cut/feed ops; no Electron/IO | Default ruler 32 chars no wrap; `columns` override 48/42; 17 mapped chars; 4 code-page lines; feed/cut ops; purity | ~80 | `feat(taquilla): agrega slip de puesta en marcha 32 cols (documents/printTest.cjs)` |
| WU-4a.2 | Startup test runner | M `printing/index.cjs` (+30 `runPrintTest`), `main.cjs` (+4), `printing/test/index.test.cjs` | WU-4a.1 | Impure `runPrintTest`; `maybeRunStartupTest` runs once when `LOTTO_PRINT_TEST==='1'` && `mode==='escpos'` after `createWindow()`; catches all errors, never blocks boot | `index.test.cjs` gating (`LOTTO_PRINT_TEST=1` + `mode=escpos`); no-window → `{success:false}` | ~49 | `feat(taquilla): activa print:test al arranque (regla de 32 cols)` |

## S4b Close + report (32 stacked) — slice total ~381 lines

| ID | Title | Files (C/M/D) | Depends | Acceptance (observable) | Tests | Est. lines | Commit |
|----|-------|---------------|---------|--------------------------|-------|-----------|--------|
| WU-4b.1 | Close document (32 stacked) | C `taquilla/electron/main/printing/documents/cierre.cjs`, `printing/test/documents/cierre.test.cjs` | S3a | Four sections (header, `TOTALES`, `ARQUEO / DIFERENCIA`, `DESGLOSE POR METODO`); concept line + `BS`/`USD` right-aligned; per-method stack for both currencies; `concepto` retained; currency heading replaces `Mon` | 32-col primary: stacked totals/arqueo/breakdown; arqueo absent; `referencia:"A\nB"`→one bounded line; negative `Falt./Sobr.`; 7-digit `1.234.567,89`; 48-col opt-in with 7-digit overflow | ~180 | `feat(taquilla): agrega layout nativo de cierre de caja 32 cols (documents/cierre.cjs)` |
| WU-4b.2 | Report document (32 stacked) | C `taquilla/electron/main/printing/documents/reporte.cjs`, `printing/test/documents/reporte.test.cjs` | WU-4b.1 | Fused + per-close stacked sections; `{feed: BLOCK_FEED=2}` + `{cut:true}` per block (`cutPerBlock`); final feed 3; `Taquilla` fallback | 32-col primary: 12-close report, feed+cut per block, final feed 3, 7-digit; 48-col opt-in golden | ~150 | `feat(taquilla): agrega layout nativo de reporte por rango 32 cols (documents/reporte.cjs)` |
| WU-4b.3 | Wire close + report channels | M `printing/index.cjs` (+6 map), `ipcHandlers.cjs` (+25), `printing/test/index.test.cjs` | WU-4b.1, WU-4b.2 | Builder map `{ticket, recibo, cierre, reporte}`; `print-cierre`/`print-reporte` wired thin; success strings `Cierre/Reporte enviado a impresora` | `index.test.cjs` exact cierre/reporte success strings; docs (`estructura.md`, `planificacion.md`) reflect 58-first native design | ~51 | `feat(taquilla): enruta print-cierre/print-reporte a impresion nativa` |

## S5 Removal — slice total ~498 del (deletion-heavy; `size:exception` if >500)

| ID | Title | Files (C/M/D) | Depends | Acceptance (observable) | Tests | Est. lines | Commit |
|----|-------|---------------|---------|--------------------------|-------|-----------|--------|
| WU-5.1 | Delete HTML generators + dead POS branch | D `generate{Ticket,Recibo,Cierre,Reporte}Html`, `escapeHtml`, `fmtMoney`, `fmtFecha`, `METODOS_CIERRE*`, `printWithSystemDialog`, `printHtml`, dead `POSPrinter`/`getPrinterList` branch in `ipcHandlers.cjs`; drop from `module.exports`; drop `BrowserWindow` import | S4b + hardware sign-off | Generators/branch absent; exports list only `getMacAddress`, `registerIpcHandlers`; app still prints natively | Existing suite + E2E unchanged (stubs bypass handler) | ~465 del | `feat(taquilla): elimina generadores HTML y rama POS muerta` |
| WU-5.2 | Remove `html` mode surface | M `printing/index.cjs` (drop `isHtmlMode`, `EffectiveConfig.mode`, `LOTTO_PRINT_MODE` parsing, `legacy` param, `mode==='escpos'` gate in `maybeRunStartupTest`, `html` branch); `infra/config.cjs` (drop mode key); `printing/test/index.test.cjs` (drop mode tests) | WU-5.1 | No `isHtmlMode`/`mode`/`LOTTO_PRINT_MODE` references; `printDocument` has no `legacy`; no dangling imports | `grep` for `isHtmlMode\|LOTTO_PRINT_MODE\|EffectiveConfig.mode\|legacy` returns nothing | ~22 del | `feat(taquilla): elimina superficie de modo html` |
| WU-5.3 | Docs + CHANGELOG | M `docs/dev/estructura.md` (`ticketPrinter.js`/`node-printer`/`ELECTRON_PRINTER_NAME`), `docs/dev/planificacion.md:281`, `CHANGELOG.md` (+5) | WU-5.2 | Docs describe `printing/` + env keys (58 mm default); no stale rollback refs; CHANGELOG entry | Manual diff review | ~11 | `docs(taquilla): actualiza documentacion de impresion y CHANGELOG` |

**Apply status (S5)**: WU-5.1, WU-5.2, WU-5.3 written. Split into S5a (`ipcHandlers.cjs`, -460 lines) and S5b (mode surface, types, tests, docs, ~-112 lines), both under 500. Not executed, uncommitted; expected suite 89 pass / 0 fail. Full evidence in `apply-progress.md`.

## S6a Build infrastructure — slice total ~80 lines

Adds esbuild build step so `printing/` can be authored as `.ts` and emitted to `printing/dist/**/*.js`. No source migration yet.

| ID | Title | Files (C/M/D) | Depends | Acceptance (observable) | Tests | Est. lines | Commit |
|----|-------|---------------|---------|--------------------------|-------|-----------|--------|
| WU-6a.1 | esbuild build for `printing/` | C `taquilla/scripts/build-printing.mjs`; M `taquilla/package.json` (add `esbuild@^0.27.7`, `build:printing` script, `dist/package.json` `{"type":"commonjs"}`); M `.gitignore` (ignore `printing/dist`) | S5 | `pnpm build:printing` emits `printing/dist/**/*.js`; nested `dist/package.json` marks it CommonJS; `dist` gitignored | — (build script; verified by `pnpm build:printing` output) | ~80 | `feat(taquilla): agrega build esbuild para impresion (build-printing)` |

## S6b TypeScript migration — slice total ~2,879 (size:exception)

Mechanical `.cjs` → `.ts` migration of the whole `printing/` tree. No behavior change; all 89 tests stay green. Recorded as `size:exception`: it is a generated/mechanical diff that cannot be split without breaking the build.

| ID | Title | Files (C/M/D) | Depends | Acceptance (observable) | Tests | Est. lines | Commit |
|----|-------|---------------|---------|--------------------------|-------|-----------|--------|
| WU-6b.1 | Migrate `printing/` to TypeScript | C 16 `.ts` files (`core/*.ts`, `infra/*.ts`, `documents/*.ts`, `index.ts`, `types.d.ts` — ~1,352 lines); D 15 `.cjs` files + `types.d.ts` (~1,527 lines); M `printing/test/*.cjs` (require `dist`), `taquilla/electron/main/main.cjs` (require `./printing/dist/index.js`), `taquilla/package.json` (`test:printing`, `electron:dev`, `electron:build`, `electron:build:win`, `e2e`), `.github/workflows/ci-cd.yml` (CI step), C `taquilla/tsconfig.printing.json` | S6a | `printing/` is `.ts` source; tests `.cjs` require `dist`; `main.cjs` requires `./printing/dist/index.js`; `pnpm build:printing` + `pnpm test:printing` green; optional `pnpm exec tsc -p tsconfig.printing.json` type-checks | 89 tests pass / 0 fail via `pnpm test:printing` | ~2,879 (1,352 created + 1,527 deleted) | `feat(taquilla): migra printing a TypeScript (esbuild)` |

## Commands for the owner (run later, not now)

```bash
# Pure suite — directory form and per-file variants
cd taquilla/electron/main/printing && node --test
node --test taquilla/electron/main/printing
node --test taquilla/electron/main/printing/test/documents/ticket.test.cjs
cd taquilla && pnpm install                                  # first run after migration
cd taquilla && pnpm build:printing                           # esbuild → printing/dist
cd taquilla && pnpm test:printing                            # 89 pass / 0 fail
cd taquilla && pnpm exec tsc -p tsconfig.printing.json       # optional type-check

# Renderer pure harness (unchanged, existing CI gate)
node taquilla/scripts/check-pure.mjs

# Builds / packaging (Windows packaging)
cd taquilla && pnpm build
cd taquilla && pnpm electron:build:win

# OpenSpec artifacts are ignored by *.md (.gitignore:44) — force-add
git add -f openspec/changes/impresion-escpos-nativa/tasks.md
```

**58 mm default note**: `LOTTO_PRINT_PAPER=58` (32 cols, 384 dots) is the default — do NOT set it unless overriding to `80` (48 cols) or `80-512` (42 cols). A fresh install needs only `LOTTO_PRINTER_NAME` (or `printer-config.json`) to print 32-column documents.

**Expected S1+S2 test result**: **49 pass / 0 fail** (S1 core ~25 tests + purity, S2 24 tests: 6 config + 8 discovery + 6 transport + 4 lock).

Hardware checklist (target Windows PC, 58 mm): `LOTTO_PRINT_TEST=1` at startup → **32-column ruler** is the FIRST test (32 chars, no wrap/clip); exactly one accent line renders (pin code page); partial cut + 3-line feed; `cut:none` = feed only; ticket 2-line-per-bet, receipt 8-field stack, close/report stacked; unplug/block PowerShell → error lists queues, no dialog, no duplicate. **80 mm is tested second, only after the 58 mm path is signed off.** E2E unchanged (`xvfb-run -a pnpm e2e`) requires the backend seed and is NOT part of this change's CI gate.

## Risks / open questions for apply

| Unknown | Safe default | Closed by |
|---|---|---|
| Paper width | **CLOSED: 58 mm / 32 cols** (owner-confirmed) | `print:test` 32-col ruler validates physical width |
| Printable dots on the 58 mm unit | 384 dots / 32 cols | 32-col ruler ends without wrap/clip; `columns` override if not |
| 80 mm / 48 cols (opt-in) | `LOTTO_PRINT_PAPER=80` → 48 | Ruler at 48 when enabled |
| Cutter present | `partial` | Audible/visible cut; else `cut:none` |
| Code page / honors `ESC t` | `cp850` (`ESC t 2`) | 4 labelled candidate lines; else `ascii` |
| PowerShell 5.1 / ExecutionPolicy | Assume available | Transport failure message; `tcp9100`/native addon documented-only |
| `PosPrinter.sendRawCommand` static + `getPrintersAsync` shape | Per design §Key assumptions | Verify at apply (S2/S3a first real print) |

No blocking questions.

## Definition of done

- **S1a**: `core/*` pure modules + tests green under `node --test`; profiles default 58/32; no wiring.
- **S1b**: `purity.test.cjs` green (no `electron`/`fs`/`node:net`/`electron-pos-printer` in pure files); `test:printing` script + CI gate land; no wiring.
- **S2**: `infra/*` (config/discovery/transport/lock) tested with fakes (58-first defaults); lock single-flight (busy → `E_PRINT_BUSY`, `finally` release, non-function → `E_PRINT_LOCK_INVALID`); nothing calls them. 49 pass / 0 fail for S1+S2.
- **S3a**: S1+S2 + a sale ticket prints natively on 58 mm hardware (2-line bet; or `LOTTO_PRINT_MODE=html` if hardware pending); `{success,message}` preserved.
- **S3b**: S3a + a prize receipt prints as the 8-field stack; builder map has `recibo`.
- **S4a**: S3a + `print:test` 32-column ruler commissions (first hardware test); startup hook fires once.
- **S4b**: S3a–S4a + close and range report print stacked on 58 mm; `print-cierre`/`print-reporte` live.
- **S5**: S1–S4b + HTML generators/`html` mode/dead POS branch absent, no dangling refs, app still prints.
- **S6a**: `pnpm build:printing` emits `printing/dist/**/*.js`; nested `dist/package.json` marks CommonJS; `dist` gitignored.
- **S6b**: `printing/` is `.ts` source; tests `.cjs` require `dist`; `main.cjs` requires `./printing/dist/index.js`; `pnpm test:printing` = 89 pass / 0 fail; `tsc -p tsconfig.printing.json` clean; CI builds before testing.
- **Final hardware sign-off** (gate for `feature/impresion-escpos` → `main`): 32-col ruler, accents on pinned code page, cut/feed, 2-line ticket, stacked receipt/close/report, clean error (no dialog, no duplicate) with printer unplugged. **80 mm only after 58 mm is signed off.**
