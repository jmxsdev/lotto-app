# Apply Progress — impresion-escpos-nativa

**Slices**: S1 Foundation (pure), S2 Config + discovery + transport + queue · **Status**: implementation written, NOT executed · **Mode**: Strict TDD (tests authored with the implementation; execution delegated to the owner)

**Test language**: test descriptions, assertion messages, and test-file comments are in neutral Spanish per owner preference; production code and this apply-progress remain English.

## Work Units

| WU | Title | Status |
|----|-------|--------|
| WU-1.1 | Charset encode module (`encode.cjs`) | Written |
| WU-1.2 | ESC/POS command builders (`encoder.cjs`) | Written |
| WU-1.3 | Deterministic formatters (`format.cjs`) | Written |
| WU-1.4 | Geometry + paper profiles (`layout.cjs`, `profiles.cjs`) | Written |
| WU-1.5 | Test runner + purity gate (`package.json`, CI, `purity.test.cjs`) | Written |

## Files

### Created

- `taquilla/electron/main/printing/encode.cjs` — CP850/CP437/CP858/CP1252 tables, sanitize, encodeText, lineBytes, job-size guard.
- `taquilla/electron/main/printing/encoder.cjs` — ESC/POS command builders and the full-document composer.
- `taquilla/electron/main/printing/format.cjs` — deterministic money/date/time formatting, no ICU/locale data.
- `taquilla/electron/main/printing/layout.cjs` — code-point-safe pad/truncate/wrap/row/rule/kv/stacked.
- `taquilla/electron/main/printing/profiles.cjs` — 58 mm default (32 cols / 384 dots), 80 mm opt-in profiles, columns override.
- `taquilla/electron/main/printing/encode.test.cjs`
- `taquilla/electron/main/printing/encoder.test.cjs`
- `taquilla/electron/main/printing/format.test.cjs`
- `taquilla/electron/main/printing/layout.test.cjs`
- `taquilla/electron/main/printing/profiles.test.cjs`
- `taquilla/electron/main/printing/purity.test.cjs`

### Modified

- `taquilla/package.json` — added `"test:printing": "cd electron/main/printing && node --test"`.
- `.github/workflows/ci-cd.yml` — added a `front-checks` step running `cd taquilla/electron/main/printing && node --test` after `check-pure.mjs` (no workflow restructuring).

## No command was executed

Per the owner's security instruction, this phase did not run Node, pnpm, npm, composer, tests, builds, installs, linters, or network calls. No `git add`, `git commit`, `git push`, branch creation, or worktree change was performed. All edits are uncommitted.

## Commands for the owner

```bash
# From the repo root
cd taquilla/electron/main/printing && node --test

# From the repo root
cd taquilla && pnpm test:printing

# From the repo root
node taquilla/scripts/check-pure.mjs
```

Expected result: `25` pure tests passing, `0` failing (6 encode, 7 encoder, 4 format, 4 layout, 2 profiles, 2 purity), no Electron or printer required. `check-pure.mjs` must stay green. CI's `front-checks` job must report the new step green.

## TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| WU-1.1 | `encode.test.cjs` | Unit | N/A (new) | Written concurrently (not executed) | Owner to run | 6 cases | Constant tables extracted |
| WU-1.2 | `encoder.test.cjs` | Unit | N/A (new) | Written concurrently (not executed) | Owner to run | 7 cases | Shared `buf`/`feed` helpers |
| WU-1.3 | `format.test.cjs` | Unit | N/A (new) | Written concurrently (not executed) | Owner to run | 4 cases | `isDateOnly`/`isWall`/`isInstant` extracted |
| WU-1.4 | `layout.test.cjs`, `profiles.test.cjs` | Unit | N/A (new) | Written concurrently (not executed) | Owner to run | 6 cases | `cpLen` reused across primitives |
| WU-1.5 | `purity.test.cjs` | Unit | N/A (new) | Written concurrently (not executed) | Owner to run | 2 static scans (5 modules + `format.cjs` literal gate) | n/a |

RED/GREEN execution gates are deferred to the owner because execution was explicitly forbidden in this phase. This is the only TDD deviation and it is recorded here.

## Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command | `cd taquilla/electron/main/printing && node --test` (repo root) |
| Exact result | Not executed — owner runs. Expected: 25 pass / 0 fail. |
| Runtime harness | N/A (pure byte modules; no IO or printer boundary in S1) |
| Rollback boundary | Revert the 11 created files plus the two script/CI edits; nothing imports `printing/` yet. |

## Risks / open items

- First execution may surface assertion-detail mismatches (regex edge cases, Node test output). Fixes should stay in S1; no S2–S5 work is included.
- Realized authored lines: S1a **381** (375 printing files + 6 script/CI changed) and S1b **195**; total **576**. Each slice stays within the 500-line per-slice PR budget. S1 ships six test files (tasks lists six; design's slice row says five) and the full mandated acceptance matrix; no acceptance case was trimmed.
- `lineBytes` guards each line; `encoder.document` guards the assembled job and exports `assertJobSize` so the S3 `buildBuffer` can reuse the same `MAX_JOB_BYTES` check.
- Fixed `-04:00` assumes Caracas has no DST (correct today); design mandates the fixed offset.
- The purity gate is a static regex scan; it is a review guard, not a sandbox.
- `formatMoney` uses the documented string half-up path; exponential inputs (≥ 1e21) use the documented scaled fallback.

## Suggested commits (Spanish, one per WU, tests included)

1. `feat(taquilla): agrega encoder puro de charset ESC/POS (encode.cjs)`
2. `feat(taquilla): agrega constructores de comandos ESC/POS (encoder.cjs)`
3. `feat(taquilla): agrega formateadores deterministicos de dinero/fecha (format.cjs)`
4. `feat(taquilla): agrega geometria de columnas y perfiles de papel (layout+profiles)`
5. `feat(taquilla): habilita tests puros de impresion en CI (test:printing + purity)`

## S1a / S1b commit-PR boundary

The S1 slice is split into two reviewable commits/PR boundaries. This is a boundary change only; no code semantics change.

| Slice | Contents | Exact lines | Budget |
|---|---|---|---|
| S1a | `encode.cjs` (67), `encoder.cjs` (57), `format.cjs` (94), `encode.test.cjs` (50), `encoder.test.cjs` (72), `format.test.cjs` (35), plus `package.json` + CI | 375 file lines + 6 script/CI changed = **381** | ≤ 500 |
| S1b | `layout.cjs` (77), `profiles.cjs` (31), `layout.test.cjs` (42), `profiles.test.cjs` (22), `purity.test.cjs` (23) | **195** | ≤ 500 |

Total S1 = **576** changed lines; each slice stays within the 500-line per-slice budget.

WU mapping: S1a = WU-1.1, WU-1.2, WU-1.3 plus the `test:printing` script and CI step from WU-1.5. S1b = WU-1.4 plus the `purity.test.cjs` gate from WU-1.5.

## Workload / PR Boundary

- Mode: chained PR slice S1 (feature-branch-chain), PR1 against `feature/impresion-escpos`, with S1a → S1b commits inside the slice.
- Current work unit: S1 Foundation (pure), split S1a / S1b.
- Boundary: starts from the empty `printing/` directory and ends at a pure, unwired byte core with tests and CI gate. S2–S5, IPC, `main.cjs`, `preload.cjs`, `.astro`, and HTML generators are untouched.
- Review budget: S1a 381 + S1b 195 = 576 changed lines; each slice is under 500. The sixth test file and the mandated acceptance matrix explain the delta from the ~492 estimate.

---

## S2 — Config + discovery + transport + queue (inert)

**Status**: implementation written, NOT executed · **Mode**: Strict TDD (tests authored with the implementation; execution delegated to the owner).

S2 is inert infrastructure: nothing imports it yet. No `index.cjs`, `layouts/`, `ipcHandlers.cjs`, `main.cjs`, `preload.cjs`, `.astro`, or S1 module was modified. No `tcp9100` code and no native addon.

### Work Units

| WU | Title | Status |
|----|-------|--------|
| WU-2.1 | Config resolution (`config.cjs`) | Written |
| WU-2.2 | Discovery by exact queue (`discovery.cjs`) | Written |
| WU-2.3 | Transport + serialized queue (`transport.cjs`, `queue.cjs`) | Written |

### Files created

- `taquilla/electron/main/printing/config.cjs` — env + `printer-config.json` resolution, env > file > default, `warnings` array, 58-first defaults.
- `taquilla/electron/main/printing/config.test.cjs`
- `taquilla/electron/main/printing/discovery.cjs` — strict name allowlist, exact then case-insensitive queue match, typed errors, 5 s enumeration timeout.
- `taquilla/electron/main/printing/discovery.test.cjs`
- `taquilla/electron/main/printing/transport.cjs` — lazy `electron-pos-printer` load, static `sendRawCommand`, 15 s timeout, error mapping.
- `taquilla/electron/main/printing/queue.cjs` — serialized promise chain, cap 20, failed job does not poison the chain.
- `taquilla/electron/main/printing/transport.test.cjs` — also covers `queue.cjs` (no separate queue test file per WU-2.3 file list).

### Contract notes

- EffectiveConfig adds a `printTest: boolean` field (from `LOTTO_PRINT_TEST === '1'`) so the env key listed in WU-2.1 is resolved; the design's documented fields are unchanged and `buildBuffer` still reads only `columns/dots/codePage/cut/feedLines/cutPerBlock`.
- `codePage` accepts `cp437` in addition to `cp850|cp858|cp1252|ascii` because design.md lists `cp437` in `EffectiveConfig`; default stays `cp850`.
- A missing `printer-config.json` is silent (design: missing → `{}`); only malformed/non-object JSON appends a warning.
- Config warnings are English (matching S1 `profiles.cjs`); discovery/transport user-facing messages are neutral Spanish as specified in design.
- `sendRaw` accepts `posPrinter` as either an object/class with static `sendRawCommand` or a zero-arg loader, so tests never require the package to be installed.

### No command was executed

Per the owner's security instruction, this phase did not run Node, pnpm, npm, composer, tests, builds, installs, linters, or network calls. No `git add`, `git commit`, `git push`, branch creation, or worktree change was performed. All S2 edits are uncommitted.

### Commands for the owner

```bash
# From the repo root
cd taquilla/electron/main/printing && node --test

# From the repo root
cd taquilla && pnpm test:printing

# From the repo root
node taquilla/scripts/check-pure.mjs
```

Expected result: `47` pure tests passing, `0` failing (S1 25 + S2 22: 6 config, 8 discovery, 8 transport/queue), no Electron, printer, or `electron-pos-printer` install required. `check-pure.mjs` must stay green (S2 modules are not part of the pure set).

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| WU-2.1 | `config.test.cjs` | Unit (injected `readFile`) | N/A (new) | Written concurrently (not executed) | Owner to run | 6 cases | `envValue`/`pick`/`toInt`/`toBool` extracted |
| WU-2.2 | `discovery.test.cjs` | Unit (`getPrintersAsync` fake) | N/A (new) | Written concurrently (not executed) | Owner to run | 8 cases | `enumerate`/`namesOf` shared; no substring path |
| WU-2.3 | `transport.test.cjs` | Unit (injected `posPrinter`, `createQueue`) | N/A (new) | Written concurrently (not executed) | Owner to run | 8 cases | `resolvePrinter`/`runWithTimeout` seams extracted |

RED/GREEN execution gates are deferred to the owner because execution was explicitly forbidden in this phase. This is the only TDD deviation and it is recorded here.

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command | `cd taquilla/electron/main/printing && node --test` (repo root) |
| Exact result | Not executed — owner runs. Expected: 47 pass / 0 fail. |
| Runtime harness | N/A (inert infrastructure; fakes for `getPrintersAsync` and the spooler; nothing calls S2 yet) |
| Rollback boundary | Revert the seven created S2 files; nothing imports `printing/` yet, so no behavior changes. |

### Risks / open items

- Config: malformed JSON handling is asserted; a non-object JSON (array/primitive) also warns. Missing file is silent by design.
- Discovery: the strict allowlist rejects shell metacharacters before enumeration; the timeout path is exercised with a 10 ms budget so the owner does not wait 5 s.
- Transport: `sendRawCommand` is invoked as a static member; the test class throws if instantiated, proving no `new`. Timeout uses a 10 ms budget.
- Queue: `createQueue` rejects `E_PRINT_QUEUE_FULL` at the cap without dropping in-flight jobs; the failed-job test proves the chain survives.
- S2 realized authored lines: **496** (68 config, 88 config.test, 69 discovery, 70 discovery.test, 68 transport, 34 queue, 99 transport.test), within the ≤500 hard budget and the chained-PR slice rule; no mandated acceptance case was trimmed.

### Suggested commits (Spanish, one per WU, tests included)

1. `feat(taquilla): agrega resolucion de config de impresora (config.cjs)`
2. `feat(taquilla): agrega descubrimiento por nombre exacto de cola (discovery.cjs)`
3. `feat(taquilla): agrega transporte spooler y cola serializada (transport+queue)`

### Workload / PR Boundary

- Mode: chained PR slice S2 (feature-branch-chain), PR2 against the PR1 branch (`feature/impresion-escpos/s2-config-transport`), WU commits inside the slice.
- Current work unit: S2 Config + discovery + transport + queue, inert.
- Boundary: starts from merged S1 pure modules and ends at tested config/discovery/transport/queue modules with no caller; S3–S5 are untouched.
- Review budget: 496 changed lines, under the 500 per-slice cap.

---

## Refactor — S1/S2 folder layout + single-flight lock

**Status**: implementation written, NOT executed · **Mode**: Standard (structural refactor; tests authored alongside) · **Behavior change**: queue → lock only.

This section is merged on top of the S1 and S2 records above; nothing in those sections was removed.

### Decision

The capped serialized promise chain (`createQueue`, `E_PRINT_QUEUE_FULL`, cap 20) is replaced by a single-flight lock (`createLock`, `E_PRINT_BUSY`). Rationale:

- A thermal print is a short, exclusive spooler call; the UI already serializes user actions.
- A queue hides work and adds retry/latency policy that the caller cannot see.
- A lock keeps one invariant only: at most one print job is in flight. The caller/UI decides whether to retry, show `E_PRINT_BUSY`, or ignore.
- `isBusy()` exposes the single boolean; no cap, no promise chain, no waiting queue.
- Release happens in `finally`, so a failed job can never leave the lock stuck.

### Final tree

```
taquilla/electron/main/printing/
├── core/
│   ├── encode.cjs
│   ├── encoder.cjs
│   ├── format.cjs
│   ├── layout.cjs
│   └── profiles.cjs
├── infra/
│   ├── config.cjs
│   ├── discovery.cjs
│   ├── lock.cjs
│   └── transport.cjs
└── test/
    ├── purity.test.cjs
    ├── core/
    │   ├── encode.test.cjs
    │   ├── encoder.test.cjs
    │   ├── format.test.cjs
    │   ├── layout.test.cjs
    │   └── profiles.test.cjs
    └── infra/
        ├── config.test.cjs
        ├── discovery.test.cjs
        ├── lock.test.cjs
        └── transport.test.cjs
```

`documents/` is intentionally absent (S3+). The old flat root keeps no duplicate copies.

### Executed moves

| From (flat `printing/`) | To |
|---|---|
| `encode.cjs`, `encoder.cjs`, `format.cjs`, `layout.cjs`, `profiles.cjs` | `core/` |
| `config.cjs`, `discovery.cjs`, `transport.cjs` | `infra/` |
| `encode.test.cjs`, `encoder.test.cjs`, `format.test.cjs`, `layout.test.cjs`, `profiles.test.cjs` | `test/core/` |
| `config.test.cjs`, `discovery.test.cjs`, `transport.test.cjs` | `test/infra/` |
| `purity.test.cjs` | `test/purity.test.cjs` |
| `queue.cjs` | deleted |

### Require/path updates

- `core/encoder.cjs` → `require('./encode.cjs')` (unchanged, both live in `core/`).
- `infra/config.cjs` → `require('../core/profiles.cjs')`.
- `test/core/*.test.cjs` → `require('../../core/*.cjs')`.
- `test/infra/*.test.cjs` → `require('../../infra/*.cjs')` (`config.test.cjs` reaches `profiles` only through `resolveConfig`).
- `test/purity.test.cjs` → scans `../core/*.cjs` and, only when it exists, `../documents/*.cjs`; it never scans `infra/`.
- Public exports and function signatures unchanged.

### Lock contract (`infra/lock.cjs`)

`createLock()` → `{ run(task), isBusy() }`.

- `run(task)` rejects `E_PRINT_LOCK_INVALID` when `task` is not a function.
- `run(task)` rejects `E_PRINT_BUSY` ("Ya hay una impresion en curso. Espere a que termine antes de reintentar.") while a job is in flight.
- Otherwise it marks busy, awaits `task()`, returns its result, and releases in `finally` on both success and failure.
- `isBusy()` returns the current boolean.
- No cap, no promise chain, no waiting queue.

### Exact file paths and line counts

| File | Lines |
|---|---:|
| `core/encode.cjs` | 67 |
| `core/encoder.cjs` | 57 |
| `core/format.cjs` | 94 |
| `core/layout.cjs` | 77 |
| `core/profiles.cjs` | 31 |
| `infra/config.cjs` | 68 |
| `infra/discovery.cjs` | 69 |
| `infra/lock.cjs` | 27 |
| `infra/transport.cjs` | 68 |
| `test/purity.test.cjs` | 29 |
| `test/core/encode.test.cjs` | 50 |
| `test/core/encoder.test.cjs` | 72 |
| `test/core/format.test.cjs` | 35 |
| `test/core/layout.test.cjs` | 42 |
| `test/core/profiles.test.cjs` | 22 |
| `test/infra/config.test.cjs` | 88 |
| `test/infra/discovery.test.cjs` | 70 |
| `test/infra/lock.test.cjs` | 44 |
| `test/infra/transport.test.cjs` | 60 |

- S1 total (core + `test/core` + purity): **576** lines (unchanged).
- S2 total (infra + `test/infra`): **494** lines (≤ 500).
- Printing tree total: **1070** lines.

### Test count

**49** tests (S1 25 + S2 24). S2 = 6 config + 8 discovery + 6 transport (`sendRaw` only) + 4 lock. The two `queue.cjs` tests were removed with the module; the four mandated lock cases were added. All descriptions remain in neutral Spanish.

### Owner commands (unchanged invocations)

```bash
# From the repo root
cd taquilla/electron/main/printing && node --test

# From the repo root
cd taquilla && pnpm test:printing

# From the repo root
node taquilla/scripts/check-pure.mjs
```

`taquilla/package.json` (`"test:printing": "cd electron/main/printing && node --test"`) and the CI `front-checks` step (`cd taquilla/electron/main/printing && node --test`) keep the same invocation. Node's test runner discovers `test/**/*.test.cjs` recursively, so no script or workflow path change is required. Expected: **49 pass / 0 fail**, `check-pure.mjs` green.

### No command was executed

This refactor did not run Node, pnpm, npm, composer, tests, builds, installs, linters, or network calls. No `git add`, `git commit`, `git push`, branch creation, or worktree change was performed. All edits remain uncommitted. Files were moved/deleted with filesystem operations only; no test or build command ran.

### Diff summary

- Moved 15 modules/tests into `core/`, `infra/`, `test/core/`, and `test/infra/`; deleted `queue.cjs`.
- Updated 9 require paths (1 in `infra/config.cjs`, 6 in `test/core`/`test/infra`, plus the purity scan root and the transport require).
- Rewrote `test/purity.test.cjs` scan roots (`../core`, optional `../documents`, never `../infra`).
- Removed the two queue tests from `test/infra/transport.test.cjs`; transport keeps its 6 `sendRaw` tests.
- Added `infra/lock.cjs` and `test/infra/lock.test.cjs` (4 Spanish-described cases).


---

## S3a — Orchestration + sale ticket live (32-first)

**Status**: implementation written, NOT executed · **Mode**: Strict TDD (tests authored with the implementation; execution delegated to the owner).

S3a is the first live native path. `print-ticket` now routes to the native ticket builder; `print-cierre`/`print-reporte` remain byte-identical on the old HTML path. No S1/S2 file, `preload.cjs`, `.astro`, cierre/reporte generator, or dead POS branch was modified.

### Work Units

| WU | Title | Status |
|----|-------|--------|
| WU-3a.1 | Sale ticket document (32-first, pure `render`/`buildLines`) | Written |
| WU-3a.2 | Composition root (`createPrinting`, `buildBuffer`, builder map `{ticket}`) | Written |
| WU-3a.3 | IPC wiring (`print-ticket` native, `main.cjs` builds `createPrinting`) | Written |

### Files created

- `taquilla/electron/main/printing/documents/ticket.cjs` (124) — pure 32-col 2-line-per-bet layout, 48-col opt-in table, optional `Hora`, 4-space continuations, 13-char money split, group rows `-- <game>`, aligned totals, no prize field.
- `taquilla/electron/main/printing/test/documents/ticket.test.cjs` (124) — 6 Spanish-described cases: single-game golden, multi-game group rows + Hora + no prize, long-jugada wrap, money split, 48-col table golden, title bytes (`1D 21 01` / `1D 21 00`, never `1D 21 10`).
- `taquilla/electron/main/printing/index.cjs` (75) — `createPrinting({env,userDataPath,getWindow,sendRaw,lock,readFile})`, `printDocument`, `maybeRunStartupTest` stub (`null`), `resolveEffectiveConfig`, `isHtmlMode`, static `BUILDERS = { ticket }`, exported `buildBuffer`.
- `taquilla/electron/main/printing/test/index.test.cjs` (146) — 8 Spanish-described cases with injected fakes: exact ticket success string and buffer parity, `html` → legacy, `recibo` → legacy, discovery/transport error mapping, `E_PRINT_BUSY` message, no HTML fallback after a raw attempt, payload-too-large guard, unknown kind rejected.

### Files modified

- `taquilla/electron/main/ipcHandlers.cjs` (+11/−3 in `print-ticket`; `registerIpcHandlers(upstream, updater, printing)`) — validation text preserved; builds `legacy = (payload) => printHtml(win, generateTicketHtml(payload), 'Ticket')` with the existing `win`; forwards `event.sender` and `kind: 'recibo'|'ticket'`; fails closed with `{success:false,message:'Impresion no disponible'}` when `printing` is absent. `print-cierre`/`print-reporte` untouched.
- `taquilla/electron/main/main.cjs` (+7/−1) — builds `createPrinting({ env: process.env, userDataPath: app.getPath('userData'), getWindow: () => mainWindow })` inside `app.whenReady()` and passes it to `registerIpcHandlers`. No `print:test` startup call (S4a).

### Flow realized

1. `resolveConfig` (default 58 mm / 32 cols / cp850 / partial / feed 3).
2. `mode === 'html'` → `legacy(data)` (chosen before any raw attempt).
3. Missing builder (`recibo` in S3a) → `legacy(data)`.
4. `lock.run(...)` wraps resolve printer → `buildBuffer` → `sendRaw(name, buffer)` (design §Single-flight).
5. `{success:true,message:'Ticket enviado a impresora'}`; every typed error → `{success:false,message}`; `printDocument` never throws.

### No command was executed

Per the owner's security instruction, this phase did not run Node, pnpm, npm, composer, tests, builds, installs, linters, or network calls. No `git add`, `git commit`, `git push`, branch creation, or worktree change was performed. All S3a edits are uncommitted.

### Commands for the owner

```bash
# From the repo root
cd taquilla/electron/main/printing && node --test

# From the repo root
cd taquilla && pnpm test:printing

# From the repo root
node taquilla/scripts/check-pure.mjs
```

Expected result: **63 pass / 0 fail** (49 S1+S2 + 14 S3a: 6 ticket + 8 index), no Electron, printer, or `electron-pos-printer` install required. `check-pure.mjs` must stay green; `purity.test.cjs` now also scans `documents/ticket.cjs`. Hardware check (owner, later): print a sale ticket on the 58 mm unit; `LOTTO_PRINT_MODE=html` is the rollback.

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| WU-3a.1 | `test/documents/ticket.test.cjs` | Unit (pure) | N/A (new) | Written concurrently (not executed) | Owner to run | 6 cases (32-col primary + 48-col opt-in) | `gameOf`/`jugadaOf`/`horaOf`/`moneyLines` extracted; `tableLines` split |
| WU-3a.2 | `test/index.test.cjs` | Unit (injected `sendRaw`/`lock`/`readFile`/`getPrintersAsync`) | N/A (new) | Written concurrently (not executed) | Owner to run | 8 cases | `legacyResult` extracted; static `BUILDERS` map |
| WU-3a.3 | `test/index.test.cjs` + unchanged E2E stubs | Integration seam | Existing E2E stubs (no new channel) | N/A (thin wiring; no new unit surface) | Owner to run suite | `print-ticket` validation + legacy closure + `event.sender` | n/a |

RED/GREEN execution gates are deferred to the owner because execution was explicitly forbidden in this phase. This is the only TDD deviation and it is recorded here.

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command | `cd taquilla/electron/main/printing && node --test` (repo root) |
| Exact result | Not executed — owner runs. Expected: 63 pass / 0 fail (14 new S3a tests). |
| Runtime harness | Windows POS (58 mm): print a sale ticket (32-col, 2-line-per-bet); rollback `LOTTO_PRINT_MODE=html`. Not run in this phase. |
| Rollback boundary | Set `LOTTO_PRINT_MODE=html` (no code revert) or revert the four created files + the two wiring edits; `print-ticket` returns to the old HTML path. |

### Deviations from design

- **`documents/ticket.cjs` exports `render(ticketData, config) -> Buffer`** (plus `buildLines` for tests) as mandated by the S3a instruction, instead of returning a layout AST. `render` delegates to `core/encoder.document`, so the single title-emphasis rule and the feed/cut policy stay owned by `encoder.cjs`.
- **Lock scope**: `lock.run` wraps resolve → build → send, matching design §Single-flight and WU-3a.2 acceptance ("lock wraps resolve→build→send"); the HTML/legacy decision happens before the lock.
- **48-col money safety**: the opt-in 48-col table is used only when every formatted amount fits an 11-char slot; otherwise that ticket falls back to the 32-style stacked layout at 48 columns, preserving "a formatted money value is never truncated".
- **WU numbering**: tasks.md and design use 3a.1/3a.2/3a.3; the S1/S2 records used 1.1–2.3 numbering. This section uses the S3a IDs.

### Workload / PR Boundary

- Mode: chained PR slice S3a (feature-branch-chain), PR4 against the PR3 branch; WU commits inside the slice.
- Current work unit: S3a orchestration + sale ticket live (32-first).
- Boundary: starts from green S1+S2 (49 tests) and ends at a natively printed sale ticket with `{success,message}` preserved; `recibo`/`cierre`/`reporte` still on legacy HTML; S3b/S4a/S4b/S5 untouched.
- Review budget: 469 created + 22 modified (18 insertions / 4 deletions) = **491 changed lines**, under the 500 per-slice cap. No 32-col acceptance case was trimmed; both mandated test files are included.

### Suggested commits (Spanish, one per WU, tests included)

1. `feat(taquilla): agrega layout nativo de ticket de venta 32 cols (documents/ticket.cjs)`
2. `feat(taquilla): agrega composition root de impresion (index.cjs)`
3. `feat(taquilla): enruta print-ticket a impresion nativa preservando contrato IPC`

---

## S3b — Receipt live (32 stacked)

**Status**: implementation written, NOT executed · **Mode**: Strict TDD (tests authored with the implementation; execution delegated to the owner).

S3b adds the prize receipt as the second native document. `print-ticket` with `kind:'recibo'` now renders ESC/POS instead of delegating to `generateReciboHtml`; `cierre`/`reporte` still delegate to legacy. No S1/S2 file, `ipcHandlers.cjs`, `main.cjs`, `preload.cjs`, `.astro`, or HTML generator was modified.

### Work Units

| WU | Title | Status |
|----|-------|--------|
| WU-3b.1 | Receipt document (32 stacked, pure `render`/`buildLines`) | Written |
| WU-3b.2 | Builder map + receipt routing (`{ticket, recibo}`) | Written |

### Files created

- `taquilla/electron/main/printing/documents/recibo.cjs` (98) — pure 32-col stacked layout: centered `COMPROBANTE DE PAGO` title, centered ticket code, `Fecha:` header, one 8-field block per bet (game line, `Jugada`/`Estado`/`Monto`/`Premio`), money pair joined when it fits 19 chars and split one currency per line otherwise, `Total premio BS:`/`Total premio USD:` via `kv`/`stacked`, footer. 48-col opt-in path aligns `Bs`/`$` in 21/14 slots without truncation.
- `taquilla/electron/main/printing/test/documents/recibo.test.cjs` (137) — 6 Spanish-described cases: 2-bet 32-col golden (states, pair, totals, footer), 13-char money split + stacked totals + six full amounts, four states with payload totals preserved over the line sum, 48-col golden, long game wrap with 4-space continuation, title/feed/cut bytes (`1D 21 01`/`1D 21 00`, never `1D 21 10`).

### Files modified

- `taquilla/electron/main/printing/index.cjs` (75 → 76) — `require('./documents/recibo.cjs')`; static `BUILDERS = { ticket: ticket.render, recibo: recibo.render }`. `SUCCESS.recibo` stays `'Ticket enviado a impresora'` (unchanged in S3a).
- `taquilla/electron/main/printing/test/index.test.cjs` (146 → 178) — the old "recibo sin builder nativo delega en legacy" case became "recibo usa el builder nativo y devuelve el mensaje de Ticket" (no legacy call, buffer parity with `buildBuffer({kind:'recibo'})`, exact success string) and a new "un kind sin builder nativo delega en legacy" case (`kind:'manifiesto'`) keeps the fallback covered. The seven remaining S3a cases are untouched.

### Receipt contract realized (32 cols)

- Title `COMPROBANTE DE PAGO` centered; double height comes from `core/encoder.document` (first line only), reset `1D 21 00`, never `1D 21 10`.
- Code centered under the title, then a rule, `Fecha: dd/mm/yyyy hh:mm`, rule.
- Per bet: `'  ' + n + ' ' + wrap(game, columns-4)` (continuations indent 4), then fields with the 13-char prefix `'    ' + pad(label, 7) + ': '` (19 usable chars at 32).
- `Monto`/`Premio` join as `Bs <x> / $ <y>` when the pair fits 19; otherwise `Bs <x>` and a 13-space continuation for `$ <y>`.
- Totals use `kv` when label + two spaces + value fits, else `stacked` (so 13-char amounts never truncate). Totals come from `premioTotalBs`/`premioTotalUsd`; the line sum is only a fallback, never a recalculation.
- 48-col opt-in: same stacked block with the `$` column starting at index 34 (`Bs` slot 21, `$` slot 14), reproducing design.md's example; totals still `kv`/`stacked`.
- 32 columns is the product path; 48 is secondary.

### No command was executed

Per the owner's security instruction, this phase did not run Node, pnpm, npm, composer, tests, builds, installs, linters, or network calls. No `git add`, `git commit`, `git push`, branch creation, or worktree change was performed. All S3b edits are uncommitted.

### Commands for the owner

```bash
# From the repo root
cd taquilla/electron/main/printing && node --test

# From the repo root
cd taquilla && pnpm test:printing

# From the repo root
node taquilla/scripts/check-pure.mjs
```

Expected result: **70 pass / 0 fail** (49 S1+S2 + 14 S3a + 6 recibo + 1 net index case), no Electron, printer, or `electron-pos-printer` install required. `check-pure.mjs` must stay green; `purity.test.cjs` already scans `documents/*.cjs`. Hardware check (owner, later): print a prize receipt on the 58 mm unit; `LOTTO_PRINT_MODE=html` is the rollback.

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| WU-3b.1 | `test/documents/recibo.test.cjs` | Unit (pure) | N/A (new) | Written concurrently (not executed) | Owner to run | 6 cases (32-col primary + 48-col opt-in) | `textField`/`moneyField`/`betLines`/`pickTotal` extracted |
| WU-3b.2 | `test/index.test.cjs` | Unit (injected `sendRaw`/`readFile`/`getPrintersAsync`) | Existing S3a index suite | Written concurrently (not executed) | Owner to run | 2 cases (native recibo + unknown kind → legacy) | Static builder map kept; legacy test retargeted to `manifiesto` |

RED/GREEN execution gates are deferred to the owner because execution was explicitly forbidden in this phase. This is the only TDD deviation and it is recorded here.

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command | `cd taquilla/electron/main/printing && node --test` (repo root) |
| Exact result | Not executed — owner runs. Expected: 70 pass / 0 fail (6 new recibo tests, 1 net new index case). |
| Runtime harness | Windows POS (58 mm): print a prize receipt (32-col, 8-field stack); rollback `LOTTO_PRINT_MODE=html`. Not run in this phase. |
| Rollback boundary | Set `LOTTO_PRINT_MODE=html` (no code revert) or revert the two created files plus the `index.cjs`/`index.test.cjs` edits; `kind:'recibo'` returns to the legacy HTML receipt. |

### Deviations from design

- **`documents/recibo.cjs` exports `render(ticketData, config) -> Buffer`** (plus `buildLines` for tests), like `ticket.cjs`, instead of returning a layout AST; `core/encoder.document` keeps the title-emphasis and feed/cut policy.
- **48-col money slots** are derived as `area - 14` (21 at 48) because the design's `[20, 28]` array cannot hold the 13-char prefix + `Bs <money>`; the resulting `$` column starts at index 34, matching the design example byte-for-byte.
- **Totals separator**: `kv` is used only when label + two spaces + value fits; this makes the 13-char totals stack exactly as in the design example while short totals stay on one line.
- **No rule between bet blocks** (the design example shows one bet only); the header rule, pre-totals rule and footer rule are present.
- **WU numbering**: S3b uses 3b.1/3b.2 as listed in tasks.md.

### Workload / PR Boundary

- Mode: chained PR slice S3b (feature-branch-chain), PR5 against the PR4 branch; WU commits inside the slice.
- Current work unit: S3b receipt live (32 stacked) + builder map.
- Boundary: starts from green S3a (63 tests) and ends at a natively printed prize receipt with `{success,message}` preserved; `cierre`/`reporte` still legacy; S4a/S4b/S5 untouched.
- Review budget: 235 created + 52 insertions / 19 deletions in modified files = **306 changed lines**, under the 500 per-slice cap (above the ~168 estimate because the golden tests are explicit rather than derived). No mandated acceptance case was trimmed.

### Suggested commits (Spanish, one per WU, tests included)

1. `feat(taquilla): agrega layout nativo de comprobante de pago 32 cols (documents/recibo.cjs)`
2. `feat(taquilla): registra comprobante en el mapa de builders`

---

## Type contracts (ADT/OOP)

**Status**: annotations written, NOT type-checked · **Mode**: Standard (comments/JSDoc only, plus one async alignment) · **Behavior change**: none.

This section is merged on top of the S1/S2/refactor/S3a/S3b records above; nothing in those sections was removed. Byte output, defaults, IPC contract, and error messages are untouched.

### Contracts artifact

- `taquilla/electron/main/printing/types.d.ts` (168 lines, types only, no runtime) declares the ADT surface shared by the whole module:
  - Scalars/enums: `PrintKind`, `Paper`, `CodePage`, `Cut`.
  - Values: `EffectiveConfig`, `TicketLine`, `TicketData`, `ReceiptLine`, `ReceiptData`, `PrintData`, `PrinterInfo`, `PrinterRef`, `PrintResult`, `SendRawOptions`, `CreatePrintingOptions`.
  - Roles (ports): `DocumentRenderer<T>`, `PrinterDiscovery`, `Transport`, `PrintLock`, `GetPrintersAsync`, `ReadFile`, `PosPrinterLike`, `LegacyPrint`, `Printing`.
  - Inputs/outputs: `BuildBufferInput`, `PrintRequest`, `ResolveConfigInput`, `ResolvePrinterInput`.
  - Errors: `PrintErrorCode`, `PrintError` (`Error & { code, details? }`), matching the typed-error ADT used by `fail()` in each module.

### Annotated modules

| File | Added | Contracts expressed |
|---|---|---|
| `printing/index.cjs` | `// @ts-check`, typedefs, `SUCCESS`/`BUILDERS` types, `buildBuffer`/`createPrinting`/`printDocument` JSDoc | `buildBuffer(input) -> Buffer`, `createPrinting(options?) -> Printing`, `printDocument(request) -> Promise<PrintResult>`, `BUILDERS: Partial<Record<PrintKind, DocumentRenderer<any>>>`, `SUCCESS: Record<PrintKind, string>` |
| `printing/documents/ticket.cjs` | `// @ts-check`, typedefs, `render`/`buildLines` JSDoc | `render(data, config?) -> Buffer`, `buildLines(data, config) -> string[]` |
| `printing/documents/recibo.cjs` | same | `render(data, config?) -> Buffer`, `buildLines(data, config) -> string[]` |
| `printing/infra/config.cjs` | `// @ts-check`, typedefs, `resolveConfig` JSDoc | `resolveConfig(input?) -> EffectiveConfig` |
| `printing/infra/discovery.cjs` | `// @ts-check`, typedefs, `fail`/`namesOf`/`listText`/`enumerate`/`resolvePrinter` JSDoc | `resolvePrinter(input?) -> Promise<PrinterRef>`, `fail(...) -> PrintError` |
| `printing/infra/transport.cjs` | `// @ts-check`, typedefs, `fail`/`loadPosPrinter`/`resolvePrinter`/`runWithTimeout`/`sendRaw` JSDoc | `sendRaw(name, buffer, options?) -> Promise<void>`, `loadPosPrinter() -> PosPrinterLike`, `resolvePrinter(PosPrinterLike | (() => PosPrinterLike))` |
| `printing/infra/lock.cjs` | `// @ts-check`, typedefs, `fail`/`createLock`/`run` JSDoc | `createLock() -> PrintLock`, generic `run<T>(task: () => Promise<T>): Promise<T>` |

JSDoc here is documentation and a future static-check seam; it emits nothing and changes no runtime path.

### Async alignment

`maybeRunStartupTest` is now `async () => null`, matching `Printing.maybeRunStartupTest(): Promise<PrintResult | null>`. Previously it returned `null` synchronously, which would have violated the declared contract the moment S4a or a caller awaited it. This is the only runtime change in this section; the resolved value (`null`) and the S4a stub behavior are identical.

### Type-check config

`taquilla/tsconfig.printing.json` (new): `allowJs`, `checkJs`, `noEmit`, `target: ES2022`, `module: commonjs`, `moduleResolution: node`, `strict: false`, `skipLibCheck`, `types: ["node"]`; includes `electron/main/printing/**/*.cjs` and `electron/main/printing/**/*.d.ts`.

- Not wired into CI and not run in this phase.
- `strict: false` is deliberate: the module is CommonJS with defensive `|| {}` fallbacks, and the goal is contract visibility, not a strict migration.
- Optional future command for the owner: `cd taquilla && pnpm exec tsc -p tsconfig.printing.json`.

### IPC seam

`ipcHandlers.cjs` gained only a JSDoc `@param {import('./printing/types').Printing|undefined} printing` on `registerIpcHandlers`. No `@ts-check` was added to that large file and no channel, validation, or message changed.

### Type ambiguities recorded

- **`printDocument` parameter is `Partial<PrintRequest>`**, not `PrintRequest`: the implementation destructures with `= {}` defaults (`kind` defaults to `'ticket'`, `data`/`webContents`/`legacy` are runtime-checked), so the optional-and-partial shape is the honest contract. The public behavior is unchanged.
- **`buildBuffer` data fallback** is cast with `/** @type {PrintData} */ (data || {})` because the runtime intentionally tolerates a missing payload and `{}` is not statically assignable to `TicketData | ReceiptData`.
- **`DocumentRenderer<T>.render` requires `config`** while `ticket`/`recibo` accept it optionally; the builders are registered through `DocumentRenderer<any>`, so the looser signature is absorbed by the `any` type argument.
- **`PrintRequest.webContents`** is typed as the minimal `{ getPrintersAsync() }` port rather than Electron's `WebContents`, so the module stays Electron-free for pure tests; `event.sender` is structurally compatible.

### No tests changed, no command executed

Test files, test count (**70**), byte output, defaults, IPC channels, and user-facing messages are unchanged. Per the owner's instruction this phase ran no Node, pnpm, npm, composer, tests, builds, installs, linters, or network calls — including `tsc`. No `git add`, `git commit`, `git push`, branch creation, or worktree change was performed. All edits remain uncommitted.

### Files

- Created: `taquilla/electron/main/printing/types.d.ts`, `taquilla/tsconfig.printing.json`, this section.
- Modified: `index.cjs`, `documents/ticket.cjs`, `documents/recibo.cjs`, `infra/config.cjs`, `infra/discovery.cjs`, `infra/transport.cjs`, `infra/lock.cjs`, `ipcHandlers.cjs` (JSDoc only), `apply-progress.md`.

---

## S4a — Commissioning `print:test` (32-column ruler) + startup hook

**Status**: implementation written, NOT executed · **Mode**: Strict TDD (tests authored with the implementation; execution delegated to the owner).

S4a adds the commissioning slip and the fire-and-forget startup hook. Only `printing/index.cjs` (the stub became the runner) and `main.cjs` (one call after `createWindow`) were modified from earlier slices; `printDocument` behavior is unchanged. `ipcHandlers.cjs`, preload, `.astro`, the HTML generators, `cierre`/`reporte`, and every S4b/S5 file are untouched.

### Work Units

| WU | Title | Status |
|----|-------|--------|
| WU-4a.1 | Commissioning slip document (`documents/printTest.cjs`, pure `render`/`buildLines`) | Written |
| WU-4a.2 | Startup test runner (`maybeRunStartupTest`), `main.cjs` hook, gating/error tests | Written |

### Files created

- `taquilla/electron/main/printing/documents/printTest.cjs` (90) — pure commissioning slip: digits ruler plus mark ruler of exactly `config.columns` characters (`\|` at 0,5,…,30), four labelled code-page sections each preceded by its own `ESC t` (`CP437` 0, `CP850` 2, `CP858` 19, `CP1252` 16), the compact 17-character Spanish set, `€` appended only to the CP858 line, a wrapped `Hex <page>:` diagnostic computed from `encodeText` (no fabricated bytes), warnings surfaced as `Aviso:`, and the `Corte: <mode>` footer. Feed/cut are delegated to `core/encoder.document`.
- `taquilla/electron/main/printing/test/documents/printTest.test.cjs` (77) — 6 Spanish-described cases: default 32-character ruler with `|` at every fifth position, `columns` override at 48/42, all 17 Spanish characters plus the CP858 `€` (0xD5), the four labels and their `ESC t` bytes, double-height-only title with feed/cut variants (`partial`, `none` + feed 5, `full` + feed 5), and the static purity assertion.

### Files modified

- `taquilla/electron/main/printing/index.cjs` (116 → 144) — `require('./documents/printTest.cjs')`; `maybeRunStartupTest` replaces the `null` stub: resolve config → `printTest !== true` → `null`; `mode === 'html'` → `null`; read `getWindow()?.webContents`, missing → `{success:false,message:'No hay ventana activa para la prueba de impresion.'}`; otherwise `lock.run(resolvePrinter → printTest.render(config) → sendRaw)` and `{success:true,message:'Prueba de impresion enviada'}`; every thrown error maps to `{success:false,message}`. `printDocument`, `SUCCESS`, `BUILDERS`, and exports are unchanged.
- `taquilla/electron/main/main.cjs` (297 → 301) — fire-and-forget call after `await createWindow()`; never blocks boot.
- `taquilla/electron/main/printing/test/index.test.cjs` (178 → 233) — `require` of the slip builder plus 5 Spanish-described cases: flag absent → no send; `LOTTO_PRINT_TEST=1` + escpos → exactly one send with buffer parity against `printTest.render(resolveEffectiveConfig())`; `mode=html` → no native send; no window → `{success:false}`; transport failure → `{success:false,message}`. The 9 pre-existing cases are untouched.

### Startup hook (exact diff, `main.cjs`)

```diff
     await createWindow();
+
+    // Puesta en marcha (LOTTO_PRINT_TEST=1): slip de 32 columnas, fire-and-forget.
+    // maybeRunStartupTest nunca rechaza; el catch es defensa y no bloquea el arranque.
+    printing.maybeRunStartupTest().catch((error) => console.warn('print:test fallo:', error && error.message));
 });
```

### Slip content (defaults, 32 columns)

```
       PRUEBA DE IMPRESION
Papel: 58mm  32 cols  384 dots
Pagina: cp850  Corte: partial
Prueba: LOTTO_PRINT_TEST=1
--------------------------------
12345678901234567890123456789012
|....|....|....|....|....|....|.
--------------------------------
CP437 ESC t 0
áéíóúüñÑÁÉÍÓÚ¿¡°
CP850 ESC t 2
áéíóúüñÑÁÉÍÓÚ¿¡°
CP858 ESC t 19
áéíóúüñÑÁÉÍÓÚ¿¡°€
CP1252 ESC t 16
áéíóúüñÑÁÉÍÓÚ¿¡°
--------------------------------
Hex cp850: A0 82 A1 A2 A3 81 A4
A5 B5 90 D6 E0 E9 A8 AD F8
--------------------------------
        Fin de la prueba
         Corte: partial
```

The slip ends with the `Corte:` line followed by `1B 64 03` + `1D 56 42 00` (defaults); no cutter means `1B 64 05` and no `GS V`.

### No command was executed

Per the owner's security instruction, this phase did not run Node, pnpm, npm, composer, tests, builds, installs, linters, or network calls. No `git add`, `git commit`, `git push`, branch creation, or worktree change was performed. All S4a edits are uncommitted.

### Commands for the owner

```bash
# From the repo root
cd taquilla/electron/main/printing && node --test

# From the repo root
cd taquilla && pnpm test:printing

# From the repo root
node taquilla/scripts/check-pure.mjs
```

Expected result: **81 pass / 0 fail** (70 S1–S3b + 6 `printTest` + 5 new index cases), no Electron, printer, or `electron-pos-printer` install required. `check-pure.mjs` must stay green; `purity.test.cjs` already scans `documents/printTest.cjs`. Hardware check (owner, later): `LOTTO_PRINT_TEST=1` on the 58 mm unit — the 32-character ruler must not wrap or clip; exactly one labelled accent line renders; the operator pins that code page.

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| WU-4a.1 | `test/documents/printTest.test.cjs` | Unit (pure) | N/A (new) | Written concurrently (not executed) | Owner to run | 6 cases (32/48/42 rulers, accents + €, four `ESC t`, feed/cut, purity) | `rulerDigits`/`rulerMarks`/`hexOf` extracted; feed/cut left in `core/encoder` |
| WU-4a.2 | `test/index.test.cjs` | Unit (injected `env`/`getWindow`/`sendRaw`/`readFile`) | 9 existing index cases | Written concurrently (not executed) | Owner to run suite | 5 gating/error cases | `maybeRunStartupTest` reuses `lock.run`/`resolvePrinter`/`sendRaw` seams |

RED/GREEN execution gates are deferred to the owner because execution was explicitly forbidden in this phase. This is the only TDD deviation and it is recorded here.

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command | `cd taquilla/electron/main/printing && node --test` (repo root) |
| Exact result | Not executed — owner runs. Expected: 81 pass / 0 fail (11 new S4a tests). |
| Runtime harness | Windows POS (58 mm): boot with `LOTTO_PRINT_TEST=1`, verify the 32-column ruler, one accent line, feed + partial cut. Not run in this phase. |
| Rollback boundary | Unset `LOTTO_PRINT_TEST` (no code revert) or revert the two created files plus the `index.cjs`/`main.cjs`/`index.test.cjs` edits; no customer path depends on `print:test`. |

### Deviations from design

- **Builder contract**: `documents/printTest.cjs` exports `render(config) -> Buffer` plus `buildLines(config) -> SlipItem[]`, with `printTestSlip` kept as an alias of `buildLines` so design.md's name still resolves. `render` delegates to `core/encoder.document`, keeping title emphasis and feed/cut policy in one owner (same pattern as `ticket.cjs`/`recibo.cjs`).
- **Compact accent line**: `á é í ó ú ü ñ Ñ Á É Í Ó Ú ¿ ¡ °` with spaces would be 33 characters and would clip `°` at 32 columns, so each section prints the 17 characters without spaces (`áéíóúüñÑÁÉÍÓÚ¿¡°`), and the CP858 line appends `€`.
- **Four `ESC t` sections are unconditional**: the slip always prints the four candidate pages regardless of `config.codePage` (that is the commissioning ladder); the configured page is restored before the hex/footer so diagnostics match the pinned runtime charset.
- **Hex diagnostic**: one wrapped `Hex <page>:` line for the configured page (design says "plus a hex-ish line"); it is derived from `encodeText`, never hand-written bytes.
- **No "has run" boolean**: the single call site in `main.cjs` runs the slip once per boot; the env flag is the only gate, matching WU-4a.2.
- **Window check**: if `webContents` exists but lacks `getPrintersAsync`, the runner returns the same no-window result instead of waiting for a discovery timeout.
- **Lock scope**: the boot slip uses the same single-flight lock as `printDocument`, so it cannot interleave with a sale print.

### Workload / PR Boundary

- Mode: chained PR slice S4a (feature-branch-chain), PR6 against the PR5 branch; WU commits inside the slice.
- Current work unit: S4a commissioning `print:test` (32-column ruler) + startup hook.
- Boundary: starts from green S3b (70 tests) and ends at a pure slip builder plus a gated, non-blocking startup call; no customer print path changes; S4b/S5 untouched.
- Review budget: 167 created + 28 (`index.cjs`) + 55 (`index.test.cjs`) + 4 (`main.cjs`) = **254 changed lines**, under the 500 per-slice cap (above the ~129 estimate because the slip tests are explicit goldens and the gating matrix has five cases). No mandated acceptance case was trimmed.

### Suggested commits (Spanish, one per WU, tests included)

1. `feat(taquilla): agrega slip de puesta en marcha 32 cols (documents/printTest.cjs)`
2. `feat(taquilla): activa print:test al arranque (regla de 32 cols)`

---

## S4b — Close + report live (32 stacked)

**Status**: implementation written, NOT executed · **Mode**: Strict TDD (tests authored with the implementation; execution delegated to the owner).

S4b makes `print-cierre` and `print-reporte` native. Both builders are pure and render the 32-column stacked form (58 mm) with a 48-column opt-in table. The HTML generators, `printHtml`, `printWithSystemDialog`, the `legacy` closure, and `LOTTO_PRINT_MODE=html` all remain in place for S5. No S1/S2/S3/S4a module other than `printing/index.cjs` and `ipcHandlers.cjs` was modified; `preload.cjs`, `.astro`, `main.cjs`, and the HTML generators are untouched.

### Work Units

| WU | Title | Status |
|----|-------|--------|
| WU-4b.1 | Close document (`documents/cierre.cjs`, pure `render`/`buildLines`) | Written |
| WU-4b.2 | Range report (`documents/reporte.cjs`, pure `render`/`buildLines`) | Written |
| WU-4b.3 | Builder map + `print-cierre`/`print-reporte` wiring + native index cases | Written |

### Files created

- `taquilla/electron/main/printing/documents/cierre.cjs` (127) — pure 32-col close: centered title + taquilla, `Desde`/`Hasta`/`Tasa`, optional `Referencia`/`Concepto`, `TOTALES`, `ARQUEO / DIFERENCIA` (only when an arqueo value exists), `DESGLOSE POR METODO` with all four methods for BS and USD, and a `Cierre #<id> - <creador>` footer. Money is right-aligned and never truncated; 48-col uses the totals table `[18,15,15]` and breakdown table `[14,12,11,11]`, falling back per currency to the stacked rows when a 12-char amount does not fit the 11-char slot.
- `taquilla/electron/main/printing/documents/reporte.cjs` (99) — pure 32-col range report: `REPORTE POR RANGO`, taquilla (fallback literal `Taquilla`), range dates, `Cierres incluidos`, wrapped `Cubierto` window, fused `TOTALES` + `DESGLOSE FUSIONADO`, then one block per close (heading, dates, tasa, totals, optional arqueo, BS/USD breakdown). `BLOCK_FEED = 2` + `{cut:true}` between blocks when `cutPerBlock` (default true); final feed comes from `config.feedLines` (3); `cut:'none'` emits no separator feed and no `GS V` (encoder adds feed 5).
- `taquilla/electron/main/printing/test/documents/cierre.test.cjs` (90) — 4 Spanish-described cases: 32-col header/totals/arqueo golden with negative `Falt./Sobr.`, 32-col four-method BS/USD breakdown with a 7-digit amount, arqueo-absent + `referencia:"A\nB"` one bounded line + `concepto` retained, and the 48-col totals table with the 7-digit breakdown stacked.
- `taquilla/electron/main/printing/test/documents/reporte.test.cjs` (83) — 5 Spanish-described cases: 32-col header/covered window golden, fused totals + 7-digit breakdown, 12 closes with one block each and 11 `feed 2 + cut` separators + final `feed 3`, `cut:'none'` (no `GS V`, feed 5), and the 48-col combined heading + tables.

### Files modified

- `taquilla/electron/main/printing/index.cjs` (+3/−1) — requires `cierre.cjs` and `reporte.cjs`; static `BUILDERS = { ticket, recibo, cierre, reporte }`. `SUCCESS` already carried `Cierre enviado a impresora` / `Reporte enviado a impresora`; no other change.
- `taquilla/electron/main/ipcHandlers.cjs` (+16/−4) — `print-cierre` and `print-reporte` keep their validation verbatim and now call `printing.printDocument({ kind, data, webContents: event.sender, legacy })`, where `legacy = (payload) => printHtml(win, generateCierreHtml|generateReporteHtml(payload), 'Cierre'|'Reporte')` and `win = BrowserWindow.getFocusedWindow()` (the same closure as before). A missing `printing` returns `{success:false,message:'Impresion no disponible'}`. Generators, `printHtml`, `printWithSystemDialog`, and the dead POS branch stay for S5.
- `taquilla/electron/main/printing/test/index.test.cjs` (+52/−1) — one loop case asserts the native cierre/reporte success strings and buffer parity with `buildBuffer`; one case asserts `LOTTO_PRINT_MODE=html` delegates both kinds to `legacy` without touching transport; the old "kind without native builder" case now uses `manifiesto` because `cierre` has a builder. The other 13 cases are untouched.
- `docs/dev/estructura.md` (+6) — short `IMPRESIÓN NÁTICA ESC/POS (58 MM POR DEFECTO)` note with the `printing/` tree, 58-first profile, and env keys. `planificacion.md:281` is deferred to S5 WU-5.3, which owns the removal of the stale `node-printer` reference.

### Handler diff shape (exact)

```diff
 ipcMain.handle('print-cierre', async (event, data) => {
     const { cierreData } = data;
     if (!cierreData || typeof cierreData !== 'object' || Array.isArray(cierreData)) {
         return { success: false, message: 'Datos de cierre invalidos' };
     }
-    const win = BrowserWindow.getFocusedWindow();
-    const html = generateCierreHtml(cierreData);
-    return await printHtml(win, html, 'Cierre');
+    if (!printing || typeof printing.printDocument !== 'function') return { success: false, message: 'Impresion no disponible' };
+    const win = BrowserWindow.getFocusedWindow();
+    const legacy = (payload) => printHtml(win, generateCierreHtml(payload), 'Cierre');
+    return printing.printDocument({ kind: 'cierre', data: cierreData, webContents: event.sender, legacy });
 });
```

`print-reporte` is the same shape with `reporteData`, `generateReporteHtml`, `'Reporte'`, and `kind: 'reporte'`.

### Close/report format realized (32 cols)

- Header is centered (title and taquilla); rules delimit the sections; dates use the deterministic `formatDate`/`formatTime` (`-04:00` shift for instants).
- `Referencia`/`Concepto` collapse any whitespace/newline into one line and wrap under a `label: ` prefix, so `"A\nB"` prints `Referencia: A B` and never injects a command (encoder sanitizes again).
- Totals print the concept (e.g. `Ventas`) on its own line and then `  BS` / `  USD` with money right-aligned to column 32. `Arqueo` / `Falt./Sobr.` use the same form and appear only when an arqueo value is present.
- Breakdown prints the currency heading (`BS`, then `USD`) and, per method, the method on its own line plus `Ventas`/`Egresos`/`Efectivo` rows (`'   ' + pad(label, 8)` + right-aligned money). All four methods (`efectivo`, `transferencia`, `pago_movil`, `punto_venta`) print for both currencies; missing data prints `0,00`.
- A 13-char amount (`-1.234.567,89`) fits the stacked form without truncation at 32 and 48 columns; at 48 the breakdown falls back per currency to the stacked rows when the amount exceeds the 11-char slot.
- Report blocks: 32-col heading is two lines (`=== Cierre #42 ===`, `Taquilla #3`), 48-col is one (`=== Cierre #42 - Taquilla #3 ===`). Blocks are separated by `{feed: 2, cut: true}` when `cutPerBlock` and a cutter exists; otherwise by a rule. The document ends with a rule and the encoder's final `feedLines` (3) + cut.

### No command was executed

Per the owner's security instruction, this phase did not run Node, pnpm, npm, composer, tests, builds, installs, linters, or network calls. No `git add`, `git commit`, `git push`, branch creation, or worktree change was performed. All S4b edits are uncommitted. `tasks.md` uses table rows (no `- [ ]` checkboxes), so completion is tracked here, consistent with S1–S4a.

### Commands for the owner

```bash
# From the repo root
cd taquilla/electron/main/printing && node --test

# From the repo root
cd taquilla && pnpm test:printing

# From the repo root
node taquilla/scripts/check-pure.mjs
```

Expected result: **92 pass / 0 fail** (81 S1–S4a + 4 cierre + 5 reporte + 2 index), no Electron, printer, or `electron-pos-printer` install required. `check-pure.mjs` must stay green; `purity.test.cjs` now also scans `documents/cierre.cjs` and `documents/reporte.cjs`.

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command | `cd taquilla/electron/main/printing && node --test` (repo root) |
| Exact result | Not executed — owner runs. Expected: 92 pass / 0 fail (11 new S4b tests). |
| Runtime harness | Windows POS (58 mm): print a close with arqueo and a 12-close range report; verify stacked totals/breakdown, 11 separators, final feed 3, and no clipping of `1.234.567,89`. Rollback `LOTTO_PRINT_MODE=html`. Not run in this phase. |
| Rollback boundary | Set `LOTTO_PRINT_MODE=html` (no code revert) or revert the four created files plus the `index.cjs`/`ipcHandlers.cjs`/`index.test.cjs` edits; `print-cierre`/`print-reporte` return to the old HTML path. |

### Deviations from design

- **Shared close helpers**: `reporte.cjs` imports `centerLines`, `dateTime`, `totalsRows`, `breakdownRows`, `totalsHeader`, `TOTALS_ROWS`, `ARQUEO_ROWS`, `CURRENCIES`, and `WIDE_COLUMNS` from `cierre.cjs` (design says the fused block is identical to the close). No dynamic require; both files stay pure.
- **Arqueo absence**: the native close omits the whole `ARQUEO / DIFERENCIA` section when neither arqueo value exists (design: section only when present), instead of the legacy `Sin arqueo registrado.` line.
- **Four methods always**: the design's 32-col example abbreviates the breakdown, but WU-4b.1 says "per-method stack for both currencies" and the legacy HTML iterates all four `METODOS_CIERRE`; the native layout prints all four (`Punto Venta` included) and `0,00` for missing data.
- **Free text wrapping**: `concepto`/`referencia` wrap with a `label: ` continuation indent instead of being clipped, so long operator text is preserved and `"A\nB"` still yields one bounded line.
- **`cut:'none'`**: the builder suppresses the `{feed: 2, cut: true}` sentinels entirely (design: `cutPerBlock` ignored without a cutter) and falls back to a rule between blocks.
- **48-col heading**: at 48 the close block heading is one line; at 32 it stays two lines, matching the design examples rather than always concatenating.
- **Line budget**: S4b landed at 483 changed lines (created 399 + `index.cjs` 4 + `index.test.cjs` 54 + `ipcHandlers.cjs` 20 + `estructura.md` 6), under the 500 cap and above the ~381 estimate. The tests were compacted (4 cierre + 5 reporte cases instead of the sketched 6+6) without dropping any mandated acceptance case: stacked totals/arqueo/breakdown BS+USD, arqueo absent, `"A\nB"` one line, negative `Falt./Sobr.`, 7-digit amount, 48-col secondary, 12 closes, feed/cut per block, final feed 3, `cut:'none'`.

### Suggested commits (Spanish, one per WU, tests included)

1. `feat(taquilla): agrega layout nativo de cierre de caja 32 cols (documents/cierre.cjs)`
2. `feat(taquilla): agrega layout nativo de reporte por rango 32 cols (documents/reporte.cjs)`
3. `feat(taquilla): enruta print-cierre/print-reporte a impresion nativa`


---

## S5 — HTML rollback surface removed (split S5a / S5b)

**Status**: implementation written, NOT executed · **Mode**: Strict TDD (tests updated with the implementation; execution delegated to the owner).

S5 is deletion-only: the HTML generators, the system-dialog fallback, the dead POS branch, the `html` mode, the `legacy` parameter, and the stale docs are gone. Every document kind (`ticket`, `recibo`, `cierre`, `reporte`) already rendered natively in S3a–S4b, so no customer path changed. `main.cjs`, `preload.cjs`, `.astro`, and `updater.cjs` are untouched; `main.cjs` needed no edit because it carried no `html`/`mode` comment.

### Work Units

| WU | Title | Status |
|----|-------|--------|
| WU-5.1 | Delete HTML generators + `printHtml`/`printWithSystemDialog` + dead POS branch + exports/imports | Written |
| WU-5.2 | Remove `html` mode surface (`index.cjs`, `config.cjs`, types, tests) | Written |
| WU-5.3 | Docs (`estructura.md`, `planificacion.md`) + `CHANGELOG.md` | Written |

### Split (S5 exceeded the 500-line slice cap)

| Slice | Contents | Changed lines |
|---|---|---:|
| S5a | `ipcHandlers.cjs`: four generators, `escapeHtml`, `fmtMoney`, `fmtFecha`, `METODOS_CIERRE*`, `printWithSystemDialog`, `printHtml`, dead `POSPrinter`/`getPrinterList` branch, thin handlers, exports, imports | **-460** |
| S5b | `printing/index.cjs` mode/legacy branch, `infra/config.cjs` mode key, `types.d.ts`, `test/index.test.cjs`, `test/infra/config.test.cjs`, docs, CHANGELOG | **~-112** |

Both slices are under 500. S5a is PR8a; S5b is PR8b. Native printing is already live, so the rollback boundary for either is a plain revert.

### Files changed (before → after)

| File | Lines | What Was Done |
|---|---:|---|
| `taquilla/electron/main/ipcHandlers.cjs` | 553 → 93 | Deleted `generate{Ticket,Recibo,Cierre,Reporte}Html`, `escapeHtml`, `fmtMoney`, `fmtFecha`, `METODOS_CIERRE`, `METODOS_CIERRE_LABELS`, `printWithSystemDialog`, `printHtml`, and the dead `POSPrinter`/`getPrinterList` branch. Dropped `BrowserWindow` from the Electron import. `print-ticket`/`print-cierre`/`print-reporte` now validate and call `printing.printDocument({kind, data, webContents: event.sender})` with no `win`/`legacy`. Exports are only `getMacAddress` and `registerIpcHandlers`. |
| `taquilla/electron/main/printing/index.cjs` | 146 → 131 | Removed `isHtmlMode`, `legacyResult`, the `legacy` parameter, the `config.mode === 'html'` branch, and the legacy fallback for unknown kinds; unknown kinds now throw `E_PAYLOAD_INVALID` with `Sin builder nativo para "<kind>".`. `maybeRunStartupTest` gates only on `config.printTest`. |
| `taquilla/electron/main/printing/infra/config.cjs` | 87 → 84 | Removed `MODES`, `DEFAULTS.mode`, the `LOTTO_PRINT_MODE`/`file.mode` resolution and validation, the `mode` return field, and the `MODES` export. `printTest`, paper/columns/dots, codePage, cut, feed, cutPerBlock, and warnings remain. |
| `taquilla/electron/main/printing/types.d.ts` | 168 → 163 | Removed `EffectiveConfig.mode`, `LegacyPrint`, `PrintRequest.legacy`, and `Printing.isHtmlMode`. All native contracts remain. |
| `taquilla/electron/main/printing/test/index.test.cjs` | 285 → 220 | Removed the three `html`-mode tests (ticket, cierre/reporte, startup slip); retargeted the unknown-kind test to `{success:false,message:'Sin builder nativo para "manifiesto".'}`; removed `legacy` from the recibo and raw-failure tests. All four native kinds still route. |
| `taquilla/electron/main/printing/test/infra/config.test.cjs` | 88 → 89 | Dropped every `mode` assertion/fixture; asserts `'mode' in config === false`; warning count 7 → 6; default 58/32/384 and `printTest` unchanged. |
| `docs/dev/estructura.md` | 3 blocks | Tree now shows `main.cjs`, `ipcHandlers.cjs`, `printing/`, `upstream.cjs`, `updater.cjs` (removed `ticketPrinter.js`/`node-printer`/`menu.js`); removed `ELECTRON_PRINTER_NAME` from the `.env` note; the native-printing note lists the current env keys and no `html` rollback. |
| `docs/dev/planificacion.md` | 1 line | Sprint 9.2 now says native ESC/POS 58 mm / 32 columns instead of `node-printer`/PDF. |
| `CHANGELOG.md` | +9 | `[Unreleased]` entry: native ESC/POS printing for tickets/receipts/closes/reports and removal of the HTML fallback. |

Deletion totals: **-460** in `ipcHandlers.cjs`, **-15** in `index.cjs`, **-3** in `config.cjs`, **-5** in `types.d.ts`, **-65** in `index.test.cjs`; net **+1** in `config.test.cjs`; docs and CHANGELOG are textual replacements.

### Remaining public surface

- `ipcHandlers.cjs` exports: `getMacAddress`, `registerIpcHandlers` (exactly two).
- `printing/index.cjs` exports: `createPrinting`, `buildBuffer`, `SUCCESS`.
- `Printing` instance: `printDocument`, `maybeRunStartupTest`, `resolveEffectiveConfig`, `buildBuffer`.

No `isHtmlMode`, no `legacy`, no `LOTTO_PRINT_MODE`, no `mode`, and no HTML fallback remains in `taquilla/electron/main`. `grep -rn "isHtmlMode\|LOTTO_PRINT_MODE\|EffectiveConfig.mode\|legacy" taquilla/electron/main` returns nothing.

### No command was executed

Per the owner's security instruction, this phase did not run Node, pnpm, npm, composer, tests, builds, installs, linters, type-checking, or network calls. No `git add`, `git commit`, `git push`, branch creation, or worktree change was performed. All S5 edits are uncommitted. `tasks.md` uses table rows (no `- [ ]` checkboxes), so completion is tracked here, consistent with S1–S4b.

### Commands for the owner

```bash
# From the repo root
cd taquilla/electron/main/printing && node --test

# From the repo root
cd taquilla && pnpm test:printing

# From the repo root
node taquilla/scripts/check-pure.mjs
```

Expected result: **89 pass / 0 fail** (92 after S4b minus the three deleted `html`-mode tests; no test was added by S5). `check-pure.mjs` must stay green. Hardware check (owner, later): after deletion, re-print a ticket, receipt, close, and report on the 58 mm unit; there is no HTML fallback, so a transport error must surface as `{success:false,message}`.

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command | `cd taquilla/electron/main/printing && node --test` (repo root) |
| Exact result | Not executed — owner runs. Expected: 89 pass / 0 fail. |
| Runtime harness | Windows POS (58 mm): re-print all four documents after deletion; verify a blocked/unplugged printer returns an error and opens no dialog. Not run in this phase. |
| Rollback boundary | Revert PR8b (mode surface/tests/docs) and PR8a (HTML generators) independently; native printing is already live, so reverting removes dead code without changing the customer path. |

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| WU-5.1 | Existing `index.test.cjs` + unchanged E2E stubs | Integration seam | 92-test suite from S1–S4b | N/A (deletion; the removed functions had no remaining caller) | Owner to run suite | Unknown-kind and raw-failure cases keep the no-fallback invariant | Thin handlers, two exports |
| WU-5.2 | `index.test.cjs`, `config.test.cjs` | Unit | Existing index/config suites | Tests updated concurrently (not executed) | Owner to run suite | Unknown-kind error, config without `mode`, startup gate | `legacyResult` removed; single `printTest` gate |
| WU-5.3 | Manual diff review | Docs | N/A | N/A | N/A | Three doc surfaces (tree, sprint table, changelog) | n/a |

RED/GREEN execution gates are deferred to the owner because execution was explicitly forbidden in this phase. This is the only TDD deviation and it is recorded here.

### Deviations from design

- **`printDocument` keeps an explicit missing-builder guard** before discovery (`throw fail('E_PAYLOAD_INVALID', 'Sin builder nativo para "<kind>".')`) even though `buildBuffer` also guards it. This makes the required message fail fast and keeps `buildBuffer` independently safe.
- **No `main.cjs` edit**: S5's removal list says to drop any `html`/`mode` comment, but the file only mentions `index.html` (the app asset). `createPrinting` and the S4a startup hook are unchanged.
- **`docs/dev/integracion-front-motor-premios.md`** still cites the old `ipcHandlers.cjs:16-55` render range; it is outside S5's named doc list and was not touched to keep the slice boundary clean. Note for a future docs pass.
- **`purity.test.cjs`** is unchanged: it never scanned `ipcHandlers.cjs`, and the deleted code was not pure.

### Workload / PR Boundary

- Mode: chained PR slice S5 (feature-branch-chain), PR8 against the PR7 branch; WU commits inside the slice, split into PR8a (S5a) and PR8b (S5b).
- Current work unit: S5 HTML rollback removal.
- Boundary: starts from green S4b (92 tests) and ends at a native-only printing module with the HTML surface deleted; S6/TypeScript migration untouched.
- Review budget: S5a **-460** and S5b **~-112** changed lines; each under the 500 per-slice cap. The split is reported instead of a `size:exception`.

### Suggested commits (Spanish, one per WU, tests included)

1. `feat(taquilla): elimina generadores HTML y rama POS muerta`
2. `feat(taquilla): elimina superficie de modo html`
3. `docs(taquilla): actualiza documentacion de impresion y CHANGELOG`

---

## S6a — TypeScript → CJS build infrastructure for `printing/` (inert)

**Status**: implementation written, NOT executed · **Mode**: Standard (no production source migrated) · **Behavior change**: none.

S6a adds only the build seam for the future TypeScript migration. No `.cjs` module, `types.d.ts`, test, `main.cjs`, `ipcHandlers.cjs`, or `preload.cjs` changed. Nothing calls the new script; the 89-test suite is untouched and the script stays inert until S6b migrates sources.

### Files changed

| File | Action | What Was Done |
|---|---|---|
| `taquilla/scripts/build-printing.mjs` | Created | esbuild JS API build of `electron/main/printing/**/*.ts` → `printing/dist/**/*.js` as CommonJS, plus `dist/package.json` `{"type":"commonjs"}`. |
| `taquilla/package.json` | Modified | `"build:printing": "node scripts/build-printing.mjs"` script; `"esbuild": "^0.27.7"` devDependency. `test:printing`, `electron:dev`, `electron:build`, `electron:build:win` untouched. |
| `taquilla/pnpm-lock.yaml` | Modified | Importer `.` gained the matching `esbuild` devDependency entry (`specifier: ^0.27.7`, `version: 0.27.7`). Required because CI runs `pnpm install --frozen-lockfile`; without it the package.json change would fail ERR_PNPM_OUTDATED_LOCKFILE. `esbuild@0.27.7` and all `@esbuild/*` platform snapshots already existed via `vite`. |
| `.gitignore` (repo root) | Modified | Added `taquilla/electron/main/printing/dist/` in the existing build-artifacts block (no restructuring). |

### Script contract (`build-printing.mjs`)

- Resolves the source root with `fileURLToPath(new URL('../electron/main/printing/', import.meta.url))`, so the cwd is irrelevant.
- `rmSync(dist, { recursive: true, force: true })` then `mkdirSync(dist, { recursive: true })`.
- Recursive `readdirSync` collection of `.ts` files, skipping any directory named `dist`; results sorted for deterministic entry points.
- `await build({ entryPoints, outdir: dist, outbase: printingRoot, format: 'cjs', platform: 'node', target: 'node22', bundle: false, sourcemap: false, logLevel: 'info' })`.
- Writes `dist/package.json` with `{"type":"commonjs"}` so the taquilla package `"type":"module"` cannot make the emitted `.js` ESM, and extensionless relative requires resolve inside `dist/`.
- Prints `[build:printing] <n> source file(s) -> <dist relative path>`.
- Empty state (today): warns `[build:printing] no .ts sources ... nothing to build` and skips the build, exiting 0 (natural exit code, so the warning is never lost to `process.exit`); the script cannot fail before S6b.

### Current execution state

No `.ts` sources exist under `printing/` yet (only `.cjs` + `types.d.ts`), so the first run just cleans `dist/`, warns, and exits 0. The build is a no-op until S6b.

### No command was executed

Per the instruction, this phase did not run Node, pnpm, npm, tests, builds, installs, linters, type-checking, or network calls. No `git add`, `git commit`, `git push`, branch creation, or worktree change was performed. All S6a edits are uncommitted.

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command | `cd taquilla/electron/main/printing && node --test` (repo root) |
| Exact result | Not executed — owner runs. Expected unchanged: **89 pass / 0 fail** (no test file was added or modified). |
| Runtime harness | N/A — the script is inert this slice; no caller, no packaged output referenced by `main.cjs`. `build:printing` becomes meaningful only after S6b migrates `.cjs` → `.ts`. |
| Rollback boundary | Delete `taquilla/scripts/build-printing.mjs`, revert the script/devDependency and lockfile importer entries, and drop the `.gitignore` line. No runtime, test, or packaging path depends on any of it. |

### Deviations from design

- **`.d.ts` files are excluded** from the entry points (`entry.name.endsWith('.d.ts')`), in addition to the requested `.ts` collection. `printing/types.d.ts` is declarations-only; feeding it to esbuild would emit an empty `dist/types.d.ts.js` into the packaged output. Both filters are documented in the script.
- **`pnpm-lock.yaml` was edited by hand** (no install was run) to keep `--frozen-lockfile` CI green; the resolution already exists transitively.

### Workload / PR Boundary

- Mode: chained PR slice S6a (feature-branch-chain), PR slice on top of S5; no `size:exception` needed.
- Current work unit: TypeScript→CJS build infrastructure for `printing/` (inert).
- Boundary: starts from the native-only 89-test printing module and ends at an authored-but-unused build script plus its dependency/ignore wiring; S6b owns the `.cjs` → `.ts` migration and the `electron:*` script changes.
- Review budget: ~95 new script lines + 3 config lines + 1 gitignore line, far under the 500 cap.

### Suggested commit

`chore(taquilla): agrega build TypeScript->CJS para printing (build-printing.mjs)`

---

## S6b — Full `.cjs` → `.ts` migration of `printing/`

**Status**: implementation written, NOT executed · **Mode**: Standard (mechanical language/module migration) · **Behavior change**: none by design.

S6b converts the entire `taquilla/electron/main/printing/` module from CommonJS `.cjs` to TypeScript `.ts`, consumed through the S6a esbuild build. Every runtime module and `types.d.ts` was replaced; no `.cjs` source duplicate remains. Public behavior, byte sequences, defaults, messages, IPC channels, and the 89-test suite are unchanged.

### Final tree

```
taquilla/electron/main/printing/
├── types.ts                  # replaced types.d.ts (deleted)
├── core/
│   ├── encode.ts
│   ├── encoder.ts
│   ├── format.ts
│   ├── layout.ts
│   └── profiles.ts
├── infra/
│   ├── config.ts
│   ├── discovery.ts
│   ├── lock.ts
│   └── transport.ts
├── documents/
│   ├── ticket.ts
│   ├── recibo.ts
│   ├── cierre.ts
│   ├── reporte.ts
│   └── printTest.ts
├── index.ts
└── test/                     # stays .cjs; requires ../../dist/...
    ├── purity.test.cjs
    ├── index.test.cjs
    ├── core/*.test.cjs
    ├── infra/*.test.cjs
    └── documents/*.test.cjs
```

### Files created (TypeScript)

| File | Lines |
|---|---:|
| `printing/types.ts` | 168 |
| `printing/index.ts` | 107 |
| `printing/core/encode.ts` | 70 |
| `printing/core/encoder.ts` | 64 |
| `printing/core/format.ts` | 92 |
| `printing/core/layout.ts` | 75 |
| `printing/core/profiles.ts` | 43 |
| `printing/infra/config.ts` | 68 |
| `printing/infra/discovery.ts` | 71 |
| `printing/infra/lock.ts` | 27 |
| `printing/infra/transport.ts` | 73 |
| `printing/documents/ticket.ts` | 123 |
| `printing/documents/recibo.ts` | 97 |
| `printing/documents/cierre.ts` | 114 |
| `printing/documents/reporte.ts` | 86 |
| `printing/documents/printTest.ts` | 74 |
| **Total** | **1352** |

### Files deleted

- Every old source: `core/{encode,encoder,format,layout,profiles}.cjs`, `infra/{config,discovery,lock,transport}.cjs`, `documents/{ticket,recibo,cierre,reporte,printTest}.cjs`, `index.cjs`, `types.d.ts` (15 `.cjs` + 1 `.d.ts`; 1527 lines removed).

### Conversion rules applied

- `require`/`module.exports` → ESM `import`/`export`; relative specifiers are extensionless (`./encode`, `../core/profiles`, `./cierre`) so the esbuild `dist/**/*.js` output resolves as CommonJS inside `dist/package.json` (`{"type":"commonjs"}`).
- Shared contracts moved to `types.ts` (`PrintKind`, `Paper`, `CodePage`, `Cut`, `EffectiveConfig` with no `mode`/`html`, ticket/receipt data, `PrinterInfo`, `GetPrintersAsync`, `PrinterRef`, `PrintResult`, `PrintErrorCode`, `PrintError`, `DocumentRenderer<T>`, `PrinterDiscovery`, `Transport`, `PrintLock`, `PrintRequest` without `legacy`, `BuildBufferInput`, `ReadFile`, `ResolveConfigInput`, `ResolvePrinterInput`, `PosPrinterLike`, `SendRawOptions`, `Printing` without `isHtmlMode`, `CreatePrintingOptions` with typed `getWindow`, and `WebContentsLike`).
- All exported functions and public data shapes are annotated; `strict:false` keeps the migration honest without reworking defensive runtime fallbacks.
- `infra/transport.ts` keeps `require('electron-pos-printer')` **inside** `loadPosPrinter()` (lazy, no top-level import) and keeps the injectable `posPrinter` seam, so pure tests still never require the package.
- `infra/config.ts` keeps `node:fs`/`node:path` (infra is not scanned by the purity gate); `printTest` remains pure.
- `index.ts` exports `createPrinting`, `buildBuffer`, `SUCCESS` only; no `legacy`, `mode`, or `isHtmlMode`.
- JSDoc `@typedef {import('./types')...}` blocks were removed from all migrated sources; contracts are native TS.
- Spanish messages, comments, byte sequences, defaults, and test descriptions were preserved verbatim.

### Files modified

| File | Change |
|---|---|
| `taquilla/electron/main/main.cjs` | `require('./printing/dist/index.js')` (was `./printing/index.cjs`). |
| `taquilla/electron/main/ipcHandlers.cjs` | Unchanged — the JSDoc `import('./printing/types')` now resolves `types.ts`. |
| `taquilla/package.json` | `test:printing` = build + `node --test`; `electron:dev`, `electron:build`, `electron:build:win`, `e2e` build `printing` first; `build:printing` and the `esbuild` devDependency unchanged. |
| `taquilla/tsconfig.printing.json` | `.ts` sources, `allowJs:false`, `checkJs:false`, `noEmit:true`, includes `electron/main/printing/**/*.ts`. |
| `.github/workflows/ci-cd.yml` | `front-checks` printing step runs `pnpm build:printing && cd electron/main/printing && node --test` with `working-directory: taquilla`; rest unchanged. |
| Tests (15 files) | Require paths retargeted to `../../dist/<dir>/<name>.js` / `../dist/index.js`; no assertions or descriptions changed. |
| `test/purity.test.cjs` | Scans `../core/*.ts` and optional `../documents/*.ts` (never `infra/`); forbidden `from`/`require` forms for electron, fs, node:fs, fs/promises, node:net, net, electron-pos-printer; the `format` scan reads `format.ts`. |
| `test/documents/printTest.test.cjs` | Its source-read path points to `documents/printTest.ts` (assertion unchanged). |
| `docs/dev/estructura.md` | Printing paths documented as `.ts` sources + `printing/dist` CJS build; `pnpm build:printing` mentioned. |
| `CHANGELOG.md` | `[Unreleased] → Técnico` note for the TypeScript migration (internal, no user-facing behavior). |

### Path-recording deviations

- The task text said `test/index.test.cjs` → `require('../../dist/index.js')`. That path resolves to `electron/main/dist/index.js`; the correct path from `printing/test/` is `../dist/index.js` (used here). The other three mappings (`../../dist/<dir>/...`) are correct as written.
- `test/documents/printTest.test.cjs` also read `documents/printTest.cjs` directly for a purity assertion; the path was updated to `printTest.ts` (assertion text untouched).
- The purity test's Spanish description and assertion message still say `format.cjs`; descriptions were intentionally left unchanged per the instruction not to alter test descriptions.

### Type notes / ambiguities

- `E_PAYLOAD_TOO_LARGE` set by `core/encode.assertJobSize` is not a member of `PrintErrorCode`; the local error is typed `Error & { code: string }` to preserve the runtime contract without widening the public ADT.
- `infra/config.ts` defaults `readFile` to `fs.readFileSync as unknown as ReadFile` so the injected seam type stays exact without changing behavior.
- `PrintRequest.webContents` and `CreatePrintingOptions.getWindow` share `WebContentsLike`; `event.sender` remains structurally compatible.
- `reporte.ts` casts the block-separator ternary to `ReporteLine[]`; runtime output is identical.

### No command was executed

Per the instruction, this phase ran no Node, pnpm, npm, composer, tests, builds, installs, linters, type-checking (`tsc`), or network calls. No `git add`, `git commit`, `git push`, branch creation, or worktree change was performed. Files were only created, edited, moved, or deleted with filesystem operations.

### Owner commands

```bash
cd taquilla
pnpm install                              # esbuild devDependency / lock already updated in S6a
pnpm build:printing                       # esbuild -> printing/dist (CJS)
pnpm test:printing                        # build + 89 tests
pnpm exec tsc -p tsconfig.printing.json   # optional type-check
pnpm build                                # Astro
pnpm electron:build:win                   # Windows package, includes printing/dist
```

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command | `pnpm test:printing` (or `pnpm build:printing && cd electron/main/printing && node --test`) |
| Exact result | Not executed — owner runs. Expected: **89 pass / 0 fail** (unchanged count; no test added/removed). |
| Runtime harness | Windows POS (58 mm): `pnpm build:printing` then boot/print the four documents; `main.cjs` must resolve `printing/dist/index.js`. Not run in this phase. |
| Rollback boundary | Revert the 16 `.ts` files and restore the 15 `.cjs` + `types.d.ts` sources, then revert `main.cjs`, `package.json`, `tsconfig.printing.json`, CI, tests, docs, and CHANGELOG. Build script and lockfile from S6a can stay. |

### Workload / PR Boundary

- Mode: **`size:exception`** — S6b is one mechanical migration (16 sources created, 16 sources deleted, 15 test require paths, config/CI/docs), well above the 500-line review budget. Splitting it would leave the tree temporarily incoherent (`.cjs` sources and `dist` consumers mixed), which the task explicitly forbids.
- Current work unit: S6b full TypeScript migration of `printing/`.
- Boundary: starts from S6a (inert esbuild build, green 89 tests on `.cjs`) and ends at a coherent TS source tree + CJS `dist` consumers, tests on `dist`, `main.cjs` on `dist/index.js`.
- Review budget: 1352 created TS lines + 1527 deleted source lines + ~60 modified config/test/doc lines. Exception recorded, per the change instruction.


