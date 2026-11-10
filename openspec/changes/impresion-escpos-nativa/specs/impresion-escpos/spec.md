# Delta for impresion-escpos

## Purpose

Native ESC/POS thermal printing for the taquilla: raw-byte encoding, transport, printer/paper configuration, code-page handling, deterministic formatting, and a commissioning slip. Replaces Chromium raster printing for the thermal queue.

## ADDED Requirements

### Requirement: Native ESC/POS as the only customer-facing print path

The system MUST send raw ESC/POS bytes to a configured thermal queue as the only customer-facing print path. It MUST NOT rasterize via Chromium (`webContents.print`) and MUST NOT open the system print dialog for a configured thermal printer. A temporary `LOTTO_PRINT_MODE=html` mode MAY exist during rollout only as an escape hatch; it is not supported behavior and MUST be removed in the final slice.

#### Scenario: Configured thermal printer prints natively

- GIVEN a thermal queue is configured and print mode is `escpos`
- WHEN the operator prints a ticket, receipt, close, or report
- THEN raw ESC/POS bytes are sent to the queue
- AND no Chromium rasterization or system dialog occurs

#### Scenario: Rollout escape hatch is not a feature

- GIVEN the `html` mode exists during rollout
- WHEN the final removal slice lands
- THEN the `html` mode and its generators are absent
- AND native ESC/POS is the sole print path

### Requirement: Printer resolution and discovery

The system MUST resolve the target printer by exact queue name using `webContents.getPrintersAsync`. Precedence MUST be: environment variable (`LOTTO_PRINTER_NAME`) → persisted config in `app.getPath('userData')` → error. When no queue is configured or the configured name matches no available queue, the system MUST return `{ success: false, message }` listing available queue names and the key to set, and MUST NOT open the system dialog or crash.

#### Scenario: Env override wins

- GIVEN both `LOTTO_PRINTER_NAME` and a persisted config name
- WHEN printing resolves the queue
- THEN the env value is used

#### Scenario: Unconfigured printer errors with a list

- GIVEN no env var, no persisted config, or the configured name matches no available queue
- WHEN the operator prints
- THEN the result is `{ success: false, message }` listing available queue names
- AND no system dialog opens

### Requirement: Paper profiles and column geometry

The system MUST derive the printable column count from the paper profile: 58 mm → 32 columns, 80 mm → 48 columns (203 dpi). An explicit column/dots override MUST be supported for 512-dot 80 mm queues. Wrapping and truncation MUST preserve column alignment.

#### Scenario: Width selects columns

- GIVEN an 80 mm (203 dpi) profile
- WHEN a document is laid out
- THEN lines are bounded to 48 columns
- AND a 58 mm profile bounds lines to 32 columns

#### Scenario: 512-dot override

- GIVEN an 80 mm queue exposing 512 dots
- WHEN the explicit columns override is set
- THEN the override value governs column width instead of 48

### Requirement: Encoding and sanitization

The system MUST encode text using CP850 as the default code page (`ESC t 2`). CP858 (`ESC t 19`), CP1252 (`ESC t 16`), and an `ascii` transliteration mode MUST be operator/commissioning-selectable alternatives, chosen via config and verified with the `print:test` slip. The system MUST NOT perform automatic runtime probing or fallback between code pages; `ascii` is the manual last resort when the device does not honor `ESC t` or the chosen page. Spanish accents, `ñ`, `¿`, `¡`, and `°` MUST be preserved. Control characters (`0x00–0x1F`, `0x7F`) MUST be stripped from free text. Money and date formatting MUST be deterministic (fixed-width, es-VE visual style) and MUST NOT depend on ICU.

#### Scenario: Spanish text preserves accents

- GIVEN ticket text with `ñ` and accented game names
- WHEN bytes are encoded
- THEN accents render on the configured code page or transliterate safely

#### Scenario: Control bytes stripped

- GIVEN free text containing control characters
- WHEN the encoder sanitizes
- THEN control bytes are removed before bytes are emitted

#### Scenario: Deterministic formatting

- GIVEN money and dates to print
- WHEN formatting runs
- THEN output is dot thousands, comma decimals, 2 fixed decimals, `dd/mm/yyyy hh:mm`
- AND identical input yields identical bytes regardless of locale data

### Requirement: Sale ticket layout

The system MUST lay out a sale ticket with: ticket code, date/time, optional payment type, one row per bet, group rows per game for multi-game tickets, an optional `Hora` column when `sorteo` is present, BS and USD totals, and a footer. It MUST NOT expose potential prize amounts (RF-02.6).

#### Scenario: Single-game ticket

- GIVEN a ticket payload with lines and totals
- WHEN the ticket layout builds
- THEN code, date/time, one row per bet, BS/USD totals, and footer are present

#### Scenario: Multi-game with group rows

- GIVEN a ticket spanning multiple games
- WHEN the layout builds
- THEN a group row per game appears and each bet is under its game

#### Scenario: No potential-prize exposure

- GIVEN a sale ticket
- WHEN the layout builds
- THEN no potential-prize field appears

### Requirement: Prize receipt layout

The system MUST lay out a prize receipt as one entry per bet with game, jugada, state, amounts, and prize in a stacked layout that renders correctly at 32 and 48 columns. Reprint MUST use registered payments.

#### Scenario: Stacked receipt at both widths

- GIVEN a receipt payload with 8 fields per bet
- WHEN the layout builds at 32 or 48 columns
- THEN each bet prints as a stacked block with game/jugada/state/amounts/prize
- AND prize totals match

#### Scenario: Reprint uses registered payments

- GIVEN a reprint of an already-paid ticket
- WHEN the receipt builds
- THEN prizes come from registered payments, not zero

### Requirement: Close and range report layout

The system MUST lay out a close and a range report with totals, arqueo, faltante/sobrante, and a per-method breakdown (currency heading instead of a repeated `Mon` column). The range report MUST repeat a block per close. Long reports MUST feed/cut per block so content never prints off-paper.

#### Scenario: Close with arqueo

- GIVEN a close payload with totals, arqueo, and desglose
- WHEN the close layout builds
- THEN totals, arqueo, faltante/sobrante, and the per-method breakdown are present

#### Scenario: Long range report feeds and cuts

- GIVEN a range report with many closes
- WHEN the report prints
- THEN each close block is followed by feed/cut and no content is lost off-paper

### Requirement: Commissioning print:test slip

The system MUST provide a `print:test` slip (triggered by `LOTTO_PRINT_TEST=1` at startup) printing a column ruler, an accent/`ñ` set, and a cut/feed. It MUST be runnable on the target PC without a renderer surface.

#### Scenario: Test slip commissions hardware

- GIVEN `LOTTO_PRINT_TEST=1` at startup
- WHEN the app starts
- THEN a slip with a column ruler, accent set, and cut/feed prints on the configured queue

### Requirement: Transport and failure policy

The system MUST send raw bytes via `sendRawCommand(printerName, Buffer)`. On raw failure it MUST return an actionable error and MUST NOT automatically fall back to HTML after a raw attempt, so no duplicate document is produced.

#### Scenario: Raw success

- GIVEN a configured queue
- WHEN bytes are sent
- THEN the result is `{ success: true }`

#### Scenario: Raw failure returns error, no fallback

- GIVEN `sendRawCommand` fails
- WHEN a document is sent
- THEN the result is `{ success: false, message }` with an actionable message
- AND no HTML fallback runs, producing no duplicate

### Requirement: Renderer IPC contract preservation

The system MUST preserve the `print-ticket`, `print-cierre`, and `print-reporte` channels and the `{ success, message }` response shape. Payload shapes and E2E stubs MUST remain valid; preload/renderer code is unchanged.

#### Scenario: Channels and shape unchanged

- GIVEN the existing renderer call sites
- WHEN native printing is wired in
- THEN the same three channels return `{ success, message }`
- AND E2E stubs remain valid without modification

### Requirement: Testing contract

The system MUST support pure unit tests for the encoder, formatters, and layouts that run without Electron or printer hardware in CI. Hardware behavior (code page, width, cutter, feed) MUST be verified by a manual checklist, not CI.

#### Scenario: CI runs pure tests

- GIVEN the encoder, formatters, and layouts
- WHEN CI runs
- THEN unit tests pass with no Electron and no printer

#### Scenario: Hardware verified manually

- GIVEN no printer in CI
- WHEN hardware must be confirmed
- THEN a manual checklist exercises code page, width, cut, and feed on the real device

### Requirement: Removal slice

The system MUST remove the dead POS branch and the HTML generators only after native printing is verified on hardware, and MUST leave no dangling references or exports.

#### Scenario: Clean removal

- GIVEN native printing is verified on hardware
- WHEN the removal slice lands
- THEN the dead POS branch and HTML generators are absent
- AND no dangling references or exports remain, and printing still works
