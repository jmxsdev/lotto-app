# Proposal: Native ESC/POS printing for the taquilla

## Why

The taquilla prints every document by rasterizing HTML in a hidden Chromium window
(`ipcHandlers.cjs:386-462`). The same HTML is crisp on an office laser printer and
blurry on the thermal printer, so the defect is the raster path, not the data. The fix
is to emit raw ESC/POS bytes to the thermal queue so text is drawn by the printer ROM,
not by Chromium + the OS driver. No data-building change is needed.

## What changes

- Add a pure ESC/POS byte encoder + deterministic formatters in the Electron main process.
- Add printer/paper configuration (exact queue name, paper profile) with env overrides.
- Replace the four HTML raster templates with native line-layout builders for the three
  existing IPC channels (`print-ticket`, `print-cierre`, `print-reporte`).
- Add a transport adapter over `electron-pos-printer` `sendRawCommand`.
- Add a commissioning `print:test` path (ruler + accents/ñ + cut/feed).
- Delete the dead POS branch and the HTML generators only in the final slice.

## Scope

### In scope

- Native ESC/POS as the only customer-facing print path. A temporary `html` rollback
  mode exists behind config during rollout only; it is not a supported office-printing
  feature and is removed when native is verified on hardware.
- Printer discovery/config: exact queue name resolved from `webContents.getPrintersAsync`,
  persisted config, env overrides, actionable error when unconfigured.
- Paper profiles: 58 mm (32 cols) and 80 mm (48 cols), plus a column/dots override for
  512-dot 80 mm printers.
- Code page strategy for Spanish (CP850 default; CP858/CP1252 fallback; transliteration).
- Deterministic money/date formatting (fixed-width, es-VE visual style, no ICU reliance).
- Commissioning `print:test` slip.
- Transport abstraction with documented fallback and no silent duplicate printing.

### Out of scope

- Office/laser printer support (the `html` mode is a temporary rollback, not a feature).
- Fiscal printer / SENIAT integration.
- Emoji, barcodes, images, QR codes, cash-drawer behavior (cash drawer noted as a
  possible future bonus, not in this change).
- Changes to how ticket data is built in the renderer or in `ticket.ts`/`recibos.ts`.
- A printer settings UI in the renderer (first pass configures via env/file only).

## Capabilities

### New Capabilities

- `impresion-escpos`: native thermal printing — byte encoder, transport, printer/paper
  configuration, code page selection, deterministic formatting, commissioning slip.

### Modified Capabilities

- `cierre-caja`: the requirement "Impresión del cierre y del reporte desde la taquilla"
  currently mandates `electron-pos-printer` + `generateReporteHtml()`; it must point at
  the native path instead.

## Key decisions

| # | Decision | Rationale |
|---|----------|-----------|
| 1 | Native ESC/POS is the primary and only customer-facing path; `html` mode exists only behind `LOTTO_PRINT_MODE=html` during rollout. | Owner confirmed thermal is the target; the HTML path is the defect. Rollback is for safe cut-over, not a product feature. |
| 2 | Printer = exact queue name from `webContents.getPrintersAsync`. Resolution order: env `LOTTO_PRINTER_NAME` → persisted `printer-config.json` in `app.getPath('userData')` → error. | Exact-name match avoids the substring false-positives of the dead branch (`ipcHandlers.cjs:432-440`). Env first keeps ops controllable; file persists normal use. |
| 3 | Unconfigured/undiscoverable printer returns `{ success: false, message }` listing the available queue names and the key to set. | The existing error modal already shows `res.message`; no new UI or channel needed. |
| 4 | Paper profile selects columns: 58 mm → 32, 80 mm → 48 (203 dpi); an explicit `columns`/dots override handles 512-dot 80 mm queues. | Some 80 mm printers expose 512 dots (≈42 cols), so a 2-way enum is insufficient. |
| 5 | Code page: CP850 default (`ESC t`), CP858 then CP1252 fallback; if the device rejects `ESC t`, transliterate accents (á→a, ñ→n). | Spanish game/animal names and payment labels carry accents (`juegos.json`); wrong bytes print garbage. |
| 6 | Money/date move to a deterministic main-process formatter: dot thousands, comma decimals, 2 fixed decimals, right-aligned and width-bounded; dates as `dd/mm/yyyy hh:mm`. | `toLocaleString('es-VE')` is ICU-dependent and variable-width, breaking raw columns. Same visual style, deterministic bytes. |
| 7 | Transport = `sendRawCommand(name, Buffer)` behind a thin adapter. On raw failure, return an actionable error; NEVER auto-fall back to HTML. | `sendRawCommand` shells out to PowerShell `System.Printing` on Windows; a silent HTML fallback after a partial raw job duplicates the document. |
| 8 | `print:test` triggered by `LOTTO_PRINT_TEST=1` at startup (no renderer surface). | Commissions the unknown hardware (width, code page, cutter) without touching `.astro`. |
| 9 | Renderer contract unchanged: same 3 channels, same `{ success, message }`, no `.astro` or preload edits. | Preserves the five call sites and all E2E payload assertions. |
| 10 | Dead POS branch + HTML generators are deleted only in the final slice, after native is verified on hardware. | They are the `html` rollback; removing them earlier removes the rollback. |

## Impact

| Area | Change |
|------|--------|
| `taquilla/electron/main/printing/encoder.cjs` (new) | Pure ESC/POS byte encoder (init, emphasis, align, code page, feed/cut, sanitize/truncate/wrap). |
| `taquilla/electron/main/printing/format.cjs` (new) | Deterministic money/date formatters. |
| `taquilla/electron/main/printing/config.cjs` (new) | Printer/paper/mode config resolution + `getPrintersAsync` discovery. |
| `taquilla/electron/main/printing/transport.cjs` (new) | `sendRawCommand` adapter + raw-failure policy. |
| `taquilla/electron/main/printing/layout{ticket,recibo,cierre,reporte}.cjs` (new) | Native line-layout builders replacing the 4 HTML generators. |
| `taquilla/electron/main/ipcHandlers.cjs` | Route the 3 channels to native builders; remove dead branch + generators in final slice. |
| `taquilla/electron/main/main.cjs` | Pass a `getWindow()`/`webContents` accessor to `registerIpcHandlers` for `getPrintersAsync`. |
| `taquilla/electron/preload/preload.cjs` | Unchanged (no new channels). |
| `docs/dev/estructura.md`, `docs/dev/planificacion.md` | Replace `ticketPrinter.js`/`node-printer`/`ELECTRON_PRINTER_NAME` claims with the native design and env keys. |
| `openspec/specs/cierre-caja/spec.md` | Delta for the print requirement (via sdd-spec). |
| `CHANGELOG.md` | User-visible entry at implementation time. |

## Delivery slices (feature-branch-chain, tracker → main only)

Each slice is one PR against the chain; every slice is autonomous with tests and
rollback = revert. Forecasts are authored additions/deletions, best-effort.

| Slice | Contents | Forecast | Notes |
|-------|----------|----------|-------|
| S1 Foundation: encoder + formatters | `encoder.cjs` + `format.cjs` + unit tests | ~365 | Pure additive, no wiring; tests via `node --test`. |
| S2 Config + transport + ticket live | `config.cjs` + `transport.cjs` + `layoutTicket.cjs` + wire `print-ticket` + tests | ~405 | First native path end-to-end; borderline → trim or split at design. |
| S3 Test slip + receipt | `printTest.cjs` + `layoutRecibo.cjs` + receipt wiring + tests | ~250 | Adds commissioning path. |
| S4 Close + report layouts | `layoutCierre.cjs` + `layoutReporte.cjs` + wire 2 channels + tests | ~390 | Completes the native surface. |
| S5 Removal | Delete 4 HTML generators, `printHtml`, `printWithSystemDialog`, dead POS branch, HTML-only helpers, `html` mode | ~480 deletions | Deletion-heavy; low cognitive load, flag for size review. |

Total ≈ 1,890 changed lines across 5 chained PRs; each ≤ ~480 (within the 500-line
budget; S2/S4/S5 sit near the chained-pr 400-line guideline and are flagged).

## Risks and mitigations

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| `sendRawCommand` blocked (PowerShell 7/AppLocker/ExecutionPolicy/driver rejects raw) | Med | Documented fallback; actionable error, no duplicate. Design documents a network-9100 or native-addon alternative if blocked. |
| Wrong code page → garbled Spanish accents | Med | `print:test` slip prints accent set; CP850→CP858→CP1252→transliteration ladder. |
| 32-col (58 mm) layout degrades receipt/close tables | Med | Stacked one-value-per-line layouts; accepted degraded output, verified on `print:test`. |
| Fixed 150 mm page replaced by continuous paper → cutter/feed behavior | Med | Explicit feed + cut policy per document type; long reports paginate/feed per close. |
| Deletion PR is large | Low | Pure deletion, one generator at a time or `size:exception` for S5. |
| No printer hardware in CI | High | Unit tests for encoder/formatters/layouts gate CI; manual Windows checklist with `print:test` for hardware. |

## Rollout / rollback

- Rollout: ship S1–S4 behind the tracker; default mode `escpos`. During cut-over set
  `LOTTO_PRINT_MODE=html` on any machine that fails the `print:test` slip.
- Rollback: set `LOTTO_PRINT_MODE=html` (or revert the tracker merge); the HTML path
  survives until S5 lands.
- Hard cut-over: S5 lands only after the owner signs off the native output on the real
  hardware; once merged, `html` mode and the generators are gone.

## Acceptance criteria (observable)

- [ ] `print:test` prints a column ruler that aligns to the configured width, correct
      Spanish accents/ñ, and an audible/visible cut + feed on the thermal printer.
- [ ] Sale ticket prints on 80 mm and 58 mm with correct columns, totals, and accents;
      multi-game group rows and the optional `Hora` column wrap without truncation.
- [ ] Receipt prints the 8 fields in the stacked layout with correct prize totals.
- [ ] Close and range report print the 5-column breakdown, arqueo, and per-close blocks;
      long reports feed/cut without paginating off-paper.
- [ ] A missing/unconfigured printer returns `{ success:false, message }` listing
      available queue names (no crash, no system dialog).
- [ ] A raw transport failure returns an error; no duplicate document is printed.
- [ ] E2E still passes with unchanged `print-*` channels and payloads; encoder/formatter
      unit tests pass in CI.
- [ ] Dead POS branch and HTML generators are absent after S5 and the app still prints.

## Open questions to close by design

- Exact printer model, connection, driver, and cutter (P0 — decides transport and cut).
- Whether PowerShell 5.1 `System.Printing` is permitted on the target PCs (P0).
- Device code-page support and whether it honors `ESC t` (P1 — decided by `print:test`).
- Confirmed 512-dot 80 mm behavior → exact column override value (P1).
- Whether the backend report payload should include the `taquilla` name (P2, pre-existing).

## Commands (read-only phase; run later, not now)

```bash
git add -f openspec/changes/impresion-escpos-nativa/proposal.md   # .gitignore ignores *.md
```
