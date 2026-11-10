# Explore: Native ESC/POS printing for the taquilla

## Context

The taquilla Electron app currently prints every document by rendering HTML in a hidden
Chromium window and calling `webContents.print()`. The same HTML prints crisply on an
office laser printer but is blurry on the thermal printer, which isolates the problem to
the thermal rasterization path rather than to ticket data or HTML composition.

This exploration maps that pipeline, verifies the suspected defects, and lists the
constraints that a native ESC/POS implementation must respect. It is read-only: no source,
test, package, or configuration file was modified.

Baseline: branch `feature/impresion-escpos`, commit `3855a43`, base `main` `3855a43`.

## Current pipeline map

### End-to-end flow

```
Renderer page
  builds a plain-object payload
  -> window.electron.printTicket / printCierre / printReporte   (preload.cjs:5-7)
  -> ipcRenderer.invoke('print-ticket' | 'print-cierre' | 'print-reporte')
  -> ipcMain.handle(...)                                       (ipcHandlers.cjs:474-506)
  -> generateTicketHtml / generateReciboHtml / generateCierreHtml / generateReporteHtml
  -> printHtml(win, html, noun)                                (ipcHandlers.cjs:418-462)
       -> dead "POS" branch: posPrinter.POSPrinter...          (ipcHandlers.cjs:419-455)
       -> fallback: printWithSystemDialog(win, html, noun)     (ipcHandlers.cjs:386-415)
            -> new hidden BrowserWindow
            -> loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(html))
            -> webContents.print({ silent: false, pageSize: 80mm x 150mm, ... })
            -> OS print driver rasterizes the page
            -> thermal printer
```

The renderer never sends bytes to the printer. The main process never emits an ESC/POS
command: `sendRawCommand` is not called anywhere in the application (repository grep,
excluding `node_modules`).

### IPC and bridge contract

| Layer | Location | Contract |
|---|---|---|
| Preload | `taquilla/electron/preload/preload.cjs:5-7` | `printTicket(data)`, `printCierre(data)`, `printReporte(data)` return `Promise<{ success: boolean, message: string }>` |
| Main ticket | `taquilla/electron/main/ipcHandlers.cjs:474-483` | Validates `ticketData.lines` non-empty, calls `generateTicketHtml` then `printHtml(..., 'Ticket')` |
| Main cierre | `taquilla/electron/main/ipcHandlers.cjs:485-494` | Validates `cierreData` is an object, calls `generateCierreHtml` then `printHtml(..., 'Cierre')` |
| Main reporte | `taquilla/electron/main/ipcHandlers.cjs:497-506` | Validates `reporteData` is an object, calls `generateReporteHtml` then `printHtml(..., 'Reporte')` |
| Renderer failure handling | e.g. `taquilla/src/pages/cierre.astro:681-688`, `:812-819` | Shows `res.message` on success, error modal otherwise; ticket sale ignores the result but keeps a local snapshot |

There is no separate `print-recibo` channel. A receipt is `print-ticket` with
`ticketData.kind === 'recibo'`; `generateTicketHtml` delegates to `generateReciboHtml`
at `taquilla/electron/main/ipcHandlers.cjs:20-22`.

### Print kinds, payloads, and printed fields

#### 1. Sale ticket (`print-ticket`, `kind` absent)

Built at:
- `taquilla/src/pages/dashboard.astro:2526-2560` (new sale), invoked at `:2566-2570`
- `taquilla/src/pages/dashboard.astro:2842-2863` (reprint from `localStorage.ultimoTicket`)
- `taquilla/src/pages/historial.astro:349-377` (reprint of a pending ticket), invoked at `:380-381`

Payload:

```js
{
  ticketCode: string,
  date: string,                 // toLocaleDateString('es-VE')
  time: string,                 // toLocaleTimeString('es-VE')
  game?: string,                // comma-joined unique game names
  tipoPago?: string,            // optional label, e.g. "Transferencia", "Pago móvil"
  lines: [{
    game?: string,
    jugada: string,             // precomposed by formatearJugada (ticket.ts)
    sorteo?: string,            // optional; presence adds the "Hora" column
    amountBs: number,
    amountUsd: number
  }]
}
```

Printed by `generateTicketHtml` (`taquilla/electron/main/ipcHandlers.cjs:16-96`):
`LOTTO TICKET`, game header, ticket code, date/time, optional payment type, line table
(`#`, `Jugada`, optional `Hora`, `BS`, `USD`), totals in BS and USD, footer. Multi-game
tickets add group rows per game (`:49-62`).

#### 2. Prize payment receipt (`print-ticket`, `kind: 'recibo'`)

Built at:
- `taquilla/src/pages/historial.astro:289-306` (automatic print after `POST /pagos`)
- `taquilla/src/pages/historial.astro:311-344` (reprint of a non-pending ticket)
- `taquilla/src/pages/ganadores.astro:182-200` (automatic print after payment)

Payload:

```js
{
  kind: 'recibo',
  ticketCode: string,
  date: string,
  time: string,
  lines: [{
    game: string,
    jugada: string,
    estado: 'GANADA' | 'PERDIDA' | 'PENDIENTE' | 'PAGADA',
    amountBs: number, amountUsd: number,
    premioBs: number, premioUsd: number
  }],
  premioTotalBs: number,
  premioTotalUsd: number
}
```

Printed by `generateReciboHtml` (`taquilla/electron/main/ipcHandlers.cjs:105-150`):
`COMPROBANTE DE PAGO`, code, date/time, an 8-column table (`#`, `Juego`, `Jugada`,
`Estado`, `Monto BS`, `Monto USD`, `Premio BS`, `Premio USD`), prize totals, footer.
Lines come from the pure builder `buildReciboLines` (`taquilla/src/utils/recibos.ts`).

#### 3. Cash close (`print-cierre`)

Payload is the API close object passed through unchanged:
- `taquilla/src/pages/cierre.astro:674-689` (current result or history row)
- `taquilla/src/pages/cierre.astro:691-698` fetches `GET /cierre/{id}` first
- `taquilla/src/pages/cierre.astro:829-831` reprints `ultimoCierre`

Backend shape: `backend/app/Services/CierreService.php:570-605`; `GET /cierre/{id}` loads
`taquilla` and `creador` (`backend/app/Http/Controllers/Api/CierreController.php:166`).
Fields read by `generateCierreHtml` (`taquilla/electron/main/ipcHandlers.cjs:184-260`):
`id`, `taquilla.nombre` or `taquillaNombre`, `creador.name` or `creadorNombre`,
`fecha_inicio`, `fecha_fin`, `exchange_rate_cierre`, `referencia`, `concepto`,
`total_ventas_bs/usd`, `total_egresos_bs/usd`, `total_efectivo_bs/usd`,
`arqueo_efectivo_bs/usd`, `faltante_sobrante_bs/usd`, and
`desglose_metodos.{bs,usd}.{efectivo,transferencia,pago_movil,punto_venta}.{ventas,egresos,efectivo}`.

#### 4. Range report (`print-reporte`)

Payload is the API rollup kept in memory:
- `taquilla/src/pages/cierre.astro:727-734` (`GET /cierre/semanal?fecha_desde=...&fecha_hasta=...`)
- `taquilla/src/pages/cierre.astro:800-820` (print)

Backend shape: `backend/app/Services/CierreService.php:570-605`. Fields read by
`generateReporteHtml` (`taquilla/electron/main/ipcHandlers.cjs:264-384`):
`fecha_desde`, `fecha_hasta`, `taquilla.nombre`, `ventana_cubierta.{desde,hasta,cierres_incluidos}`,
the same totals/arqueo/desglose fields as a close, and `cierres[]` where each entry has
`id`, `taquilla_id`, dates, `exchange_rate_cierre`, totals, arqueo, and `desglose_metodos`.
The report repeats a full block per close, so it can be arbitrarily long.

Observation: the backend report does not return a `taquilla` object
(`CierreService.php:570-605`), so `generateReporteHtml` falls back to the literal
`'Taquilla'` at `ipcHandlers.cjs:349`. This is pre-existing and not caused by the print
transport.

### Shared formatting and layout

| Concern | Implementation | Evidence |
|---|---|---|
| HTML escaping | `escapeHtml` escapes `& < > " '` | `ipcHandlers.cjs:153-160` |
| Money | `Number(...).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })` | `ipcHandlers.cjs:162-164` |
| Dates | `toLocaleString('es-VE', { day, month, year, hour, minute, hour12: true })` | `ipcHandlers.cjs:166-174` |
| Page size (CSS) | `@page { margin: 0; size: 80mm auto; }` in all four templates | `ipcHandlers.cjs:66,122,210,336` |
| Content width | `body { width: 72mm; font-family: 'Courier New', monospace; font-size: 11px; }` | `ipcHandlers.cjs:67,123,211,337` |
| Tables | `font-size: 10px` | `ipcHandlers.cjs:70,126,215,341` |
| Page size (Electron) | `pageSize: { width: 80000, height: 150000 }` (micrometres: 80 mm x 150 mm) | `ipcHandlers.cjs:402` |
| Print behavior | `silent: false`, `printBackground: true`, `margins: { marginType: 'none' }` | `ipcHandlers.cjs:399-402` |

`size: 80mm auto` combines a length with `auto`, which is not a valid CSS Paged Media
`size` value; the effective page box is therefore the Electron `pageSize` option above,
not the CSS rule. Confirm in Chromium before relying on the CSS rule.

## Defects with evidence

Status legend: **Confirmed** = directly visible in code/dependency; **Qualified** = true
with a caveat; **Inference** = expected behavior that needs hardware/browser confirmation.

| ID | Claim | Evidence | Status |
|---|---|---|---|
| D1 | The POS branch uses the wrong export: the library exports `PosPrinter`, not `POSPrinter`. | `ipcHandlers.cjs:431,441`; `electron-pos-printer@1.4.0` `dist/index.d.ts:6-33` declares `class PosPrinter`; `dist/index.js:1` export map is `{ PosPrinter }`. | Confirmed |
| D2 | `getPrinterList()` does not exist in `electron-pos-printer@1.4.0`. | The class exposes only `print`, `sendRawCommand`, `openCashDrawer`, `renderPrintDocument`: `dist/index.d.ts:11,21,23`; no `getPrinterList` in the typings or README. | Confirmed |
| D3 | `PosPrinter.print()` is not a raw path; it renders content in a `BrowserWindow` and calls `webContents.print()`. | `dist/index.js:1` (`new BrowserWindow`, `webContents.print`); typings accept `PosPrintData[]`, not an HTML string (`dist/index.d.ts:11`). | Confirmed |
| D4 | The branch is dead: `require()` succeeds, then `posPrinter.POSPrinter` is `undefined`, the throw is caught, and every print falls back to the system dialog. | `ipcHandlers.cjs:419-426` (require succeeds), `:431` (undefined access), `:452-454` (catch), `:457-458` (fallback). | Confirmed |
| D5 | Even if revived, the call would be wrong: `print(html, ...)` passes a string where `PosPrintData[]` is required, and `pageSize: { width: 800, height: 150000 }` is not 80 mm. | `ipcHandlers.cjs:441-449`; `dist/index.d.ts:11`; the library converts custom sizes with `Math.ceil(264.5833 * width)` in `dist/index.js:1`, so `width: 800` becomes ~211,667 micrometres, not 80,000. | Confirmed |
| D6 | All real printing is raster: a hidden window loads HTML and Chromium prints it; no ESC/POS bytes are emitted. | `ipcHandlers.cjs:386-415` (hidden window + `webContents.print`), `:418-462` (only transport), repository grep finds no `sendRawCommand`/`openCashDrawer` call outside the dependency. | Confirmed |
| D7 | Printing is never silent and never targets a device; the operator gets the system dialog. | `ipcHandlers.cjs:399` (`silent: false`), no `deviceName` in the options (`:398-403`). | Confirmed |
| D8 | Paper size is hardcoded to 80 mm in both the CSS and the Electron options. | `ipcHandlers.cjs:66,122,210,336` (CSS), `:402` (80,000 x 150,000 µm), `:448` (dead branch). | Confirmed |
| D9 | The fixed 150 mm page height contradicts `auto` and can paginate/scale long reports. | `ipcHandlers.cjs:402`; `generateReporteHtml` repeats a block per close (`:289-332`). | Inference (verify with a long report on hardware) |
| D10 | `ELECTRON_PRINTER_NAME` is documented but never read. | Documented at `docs/dev/estructura.md:187`; repository grep finds no code reference outside that line. | Confirmed |
| D11 | There is no printer discovery or selection in the live path. | `getPrintersAsync` is unused (repository grep); the only discovery code is the dead branch at `ipcHandlers.cjs:431-440`. | Confirmed |
| D12 | Printer auto-detection, if revived, is weak: substring matching can select a non-thermal queue (e.g. a "PostScript" name contains "pos"), and it takes the first match. | `ipcHandlers.cjs:432-440`. | Qualified |
| D13 | The docs describe files and dependencies that do not exist: `electron/printers/ticketPrinter.js` and `node-printer`. | `docs/dev/estructura.md:150`; `docs/dev/planificacion.md:281`; the directory `taquilla/electron/printers/` does not exist. | Confirmed |
| D14 | HTML escaping exists, but there is no ESC/POS control-byte sanitization. | `escapeHtml` only covers HTML metacharacters (`ipcHandlers.cjs:153-160`); raw mode would need its own guard for `0x00-0x1F`, `0x7F`, and overlong lines. | Confirmed (gap for the future path) |

### Confirmation of the provided starting facts

| Starting fact | Result |
|---|---|
| `generateTicketHtml` ~16, `generateReciboHtml` ~105, `generateCierreHtml` ~184, `generateReporteHtml` ~264, `printWithSystemDialog` ~386, `printHtml` ~418, IPC handlers ~474-506 | Confirmed exactly at those lines. |
| POS branch dead code (`POSPrinter` wrong export, no `getPrinterList`) | Confirmed (D1-D4). |
| `PosPrinter.print()` renders HTML in a BrowserWindow; only `sendRawCommand` is raw | Confirmed (D3); `sendRawCommand` typings at `dist/index.d.ts:21`. |
| `preload.cjs` exposes `printTicket`, `printCierre`, `printReporte`, `getMac`, `getVersion`, updater APIs; renderer contract `{ success, message }` | Confirmed (`preload.cjs:3-30`; handlers return that shape). |
| Call sites in dashboard ~2567/~2857, historial ~295/~381, ganadores ~189, cierre ~684/~815 | Confirmed (`dashboard.astro:2567,2857`; `historial.astro:295,381`; `ganadores.astro:189`; `cierre.astro:680,811`). |
| E2E stubs intercept IPC before the main handler | Confirmed (`e2e/helpers/fixtures.mjs:18-23`, `e2e/helpers/app.mjs:123-140`). |
| Hardcoded 80 mm, `Courier New` 11px / tables 10px, `toLocaleString('es-VE')` | Confirmed (`ipcHandlers.cjs:66-70,122-126,210-215,336-341,162-164`). |
| `ELECTRON_PRINTER_NAME` documented but never read | Confirmed (D10). |
| `package.json`: `electron-pos-printer ^1.4.0`, Electron `^41.7.1`, electron-builder 26, main `electron/main/main.cjs`, `"type": "module"` with `.cjs` files | Confirmed (`package.json:5-6,23,28,29`; `.cjs` files in `electron/main`). |
| Local test patterns: `updater.test.cjs` via `node --test`; `check-pure.mjs` for pure TS | Confirmed (`updater.test.cjs:1-3`; `scripts/check-pure.mjs:1-10,1655`). |

## Constraints

### Electron and Node runtime

- Electron `41.10.7` is installed by the lockfile; `package.json` declares `^41.7.1`
  (`taquilla/package.json:28`).
- Printer enumeration is available as `webContents.getPrintersAsync(): Promise<PrinterInfo[]>`
  (`electron.d.ts:17750`). `PrinterInfo` exposes `name`, `displayName`, `description`, and
  platform-specific `options`; it does not expose a typed `isDefault` field
  (`electron.d.ts:10981-11001`). A live `webContents` is required; the current handlers
  receive `BrowserWindow.getFocusedWindow()` (`ipcHandlers.cjs:480,491,503`), which can be
  `null`.
- Electron has no built-in raw-spooler API. `webContents.print()` always goes through the
  platform print stack; raw bytes require an external transport (the library's
  `sendRawCommand`, a native addon, a network socket, or a vendor tool).
- `taquilla/package.json:6` sets `"type": "module"`; all Electron main/preload files are
  `.cjs` (`electron/main/*.cjs`, `electron/preload/preload.cjs`). New main-process modules
  must be `.cjs` (sync `require`) or `.mjs` loaded with dynamic `import()` from a `.cjs`
  handler. Pure TypeScript under `src/utils/` is bundled by Astro/Vite for the renderer
  and is not requirable from the main process at runtime.

### Transport on Windows

- `electron-pos-printer@1.4.0`'s `sendRawCommand(printerName, Buffer)` is the only raw
  API in the installed dependency. On Windows it writes a temp `.bin` and runs
  `powershell.exe -NoProfile -NonInteractive -Command` with
  `System.Printing.LocalPrintServer` / `AddJob` / `JobStream`; on other platforms it runs
  `lp -d <printer> -o raw -` (`dist/index.js:1`; typings at `dist/index.d.ts:13-21`).
- This assumes Windows PowerShell 5.1 with `System.Printing` available. PowerShell 7,
  AppLocker, ExecutionPolicy hardening, or a driver that rejects raw jobs can break the
  transport. The fallback policy must be explicit because a partial raw failure followed
  by an automatic HTML fallback can duplicate a document.
- `electron-builder` currently packages `electron/**/*` and `dist/` as an extra resource
  (`taquilla/package.json:57-68`). Any new main-process file is included by the first
  glob; a native addon would need `asarUnpack` and a Windows build.
- The target printers are assumed to be Windows print queues (USB or network). Raw spool
  submission generally requires an ESC/POS-capable driver or a "Generic / Text Only"
  queue; GDI-only drivers may not pass raw bytes. This must be verified on the actual
  hardware.

### Character encoding for Spanish

- Ticket and report text contains Spanish accents and `ñ`: game/animal names include
  `Águila`, `Alacrán`, `Antílope`, `Araña`, `Búfalo`, `Búho`, `Caimán`, `Camaleón`,
  `Camarón`, `Cáncer`, `Chigüire`, `Ciempiés`, `Delfín`, `Gavilán`, `Géminis`,
  `Guácharo`, `Halcón`, `Hámster`, `Hipopótamo`, `Hurón`, `León`, `Murciélago`,
  `Pelícano`, `Pingüino`, `Puercoespín`, `Ratón` (`taquilla/src/data/juegos.json`).
- Payment labels also carry accents (`Pago móvil`, `Punto de venta`) and the report
  headers include free text from `referencia`/`concepto`/operator names.
- Standard ESC/POS single-byte code pages that cover Spanish are CP850, CP858, and
  Windows-1252. The printer's current code page and support for `ESC t n` are unknown;
  a transliteration fallback (e.g. `á -> a`) may be needed. This must be confirmed on
  the device.
- `toLocaleString('es-VE')` produces dot thousands and comma decimals (e.g.
  `1.234.567,89`), which can exceed 12 characters and break narrow columns.

### 58 mm vs 80 mm layout

Standard 203 dpi ESC/POS geometry, to verify on the hardware:

| Paper | Printable dots | Font A columns (12 dots/char) |
|---|---|---|
| 80 mm | 576 | 48 |
| 58 mm | 384 | 32 |

- The sale ticket has up to 5 columns (`#`, `Jugada`, optional `Hora`, `BS`, `USD`);
  it fits 48 columns only with truncation/wrapping rules and does not fit 32 columns
  without a vertical layout.
- The receipt has 8 columns and cannot be reproduced as a table on either width; it
  needs a per-line stacked layout (e.g. jugada, estado/premios on following lines).
- The close and report have 5-column breakdowns (`Mon`, `Metodo`, `Ventas`, `Egresos`,
  `Efectivo`) where each money value can be 12+ characters. The `Mon` column should be
  replaced by a currency heading; 58 mm requires abbreviations or one value per line.
- The range report repeats a full close block per close, so pagination, feed, and cut
  behavior need a policy.

### ESC/POS command surface (reference, not a requirement)

- Initialization: `ESC @` (`0x1B 0x40`).
- Text emphasis: bold `ESC E n`, underline `ESC - n`, double width/height `GS ! n`,
  alignment `ESC a n`.
- Code page: `ESC t n` (device-specific numbering; e.g. Epson `n=2` PC850, `n=16`
  WPC1252).
- Feed/cut: `ESC d n` feed *n* lines; `GS V m` full/partial cut.
- Cash drawer: `ESC p m t1 t2`; the library exposes `openCashDrawer` and its typings
  document the sequence (`dist/index.d.ts:23-33`).
- Sanitization: strip or reject control bytes (`0x00-0x1F`, `0x7F`) in free text and
  bound line length before sending bytes. HTML escaping is not sufficient for this
  channel.

## Affected files inventory

| File / area | Why it is affected | Expected change |
|---|---|---|
| `taquilla/electron/main/ipcHandlers.cjs` | Owns the four HTML generators, `printHtml`, and the IPC handlers. | Replace/augment `printHtml` with a native builder + transport; keep the HTML generators as an explicit fallback. |
| `taquilla/electron/main/printing/*` (new) | No raw encoder exists today. | Add a pure ESC/POS byte encoder, a transport adapter, and printer/paper configuration. |
| `taquilla/electron/main/upstream.cjs` | Shows the existing pattern for a pure, testable main-process module. | Reuse the pattern (`parseEnvFile` / resolver style) for printer config if useful. |
| `taquilla/electron/main/main.cjs` | `registerIpcHandlers` currently receives only upstream and updater; no window is passed. | Possibly pass the main window or a `webContents` accessor for `getPrintersAsync`; keep startup non-blocking. |
| `taquilla/electron/preload/preload.cjs` | Existing print contract is used by five pages. | Keep `printTicket`/`printCierre`/`printReporte`; add config/discovery channels only if the design adds a settings UI. |
| `taquilla/src/pages/dashboard.astro` | Sale print and reprint call sites (`:2566-2570`, `:2842-2863`). | No change if the payload contract is preserved; settings entry point if needed. |
| `taquilla/src/pages/historial.astro` | Receipt auto-print and reprint call sites (`:289-306`, `:311-388`). | No change if the payload contract is preserved. |
| `taquilla/src/pages/ganadores.astro` | Receipt auto-print after payment (`:182-200`). | No change if the payload contract is preserved. |
| `taquilla/src/pages/cierre.astro` | Close and range report print call sites (`:674-689`, `:800-820`). | No change for transport; possibly a print-preview/settings affordance. |
| `taquilla/src/utils/ticket.ts`, `recibos.ts` | Compose the text/line fields consumed by the HTML generators. | Reuse; do not duplicate formatting logic in the renderer. |
| `taquilla/package.json` | Declares `electron-pos-printer`, Electron, builder targets, and packaged files. | Keep the dependency if `sendRawCommand` is the transport; add `asarUnpack`/dependency only if a native transport is chosen. |
| `taquilla/e2e/helpers/fixtures.mjs` | `STUB_CHANNELS` lists the print channels (`:18-23`). | Add any new IPC channels to the stub; keep print payloads stable. |
| `taquilla/e2e/helpers/app.mjs` | `stubPrintIpc` replaces handlers and captures payloads (`:123-140`). | No change if channels/payloads stay stable. |
| `taquilla/e2e/tests/smoke.spec.mjs` | Asserts the bridge methods exist (`:24-27`) and stubs print (`:41-56`). | Update only if the bridge surface changes. |
| `taquilla/e2e/tests/02-venta-single.spec.mjs` | Asserts `print-ticket` payload fields (`:44-50`). | Keep payload fields stable. |
| `taquilla/e2e/tests/03-venta-multi.spec.mjs` | Asserts multi-line payload (`:35-40`). | Keep payload fields stable. |
| `taquilla/e2e/tests/04-premio.spec.mjs` | Asserts `kind: 'recibo'` and prize totals (`:48-56`). | Keep the receipt payload stable. |
| `taquilla/e2e/tests/08-tipo-pago.spec.mjs` | Asserts `tipoPago` survives print and reprint (`:77-84`). | Keep `tipoPago` in the payload. |
| `taquilla/scripts/check-pure.mjs` | Only pure TS harness run in CI; already covers ticket/recibos formatting (`:940-962`, `:1461-1580`). | Add encoder tests if the pure encoder is importable here; otherwise add a separate runner. |
| `taquilla/electron/main/updater.test.cjs` | Existing `node --test` pattern; not currently run in CI. | Follow it for a CJS encoder test if the encoder lives in `electron/main`. |
| `.github/workflows/ci-cd.yml` | Runs `check-pure`/`check-icons`/build (`:105-110`) and E2E on Ubuntu (`:121,206,213`). | Add the new test runner to `front-checks` if `node --test` is used; keep E2E free of real printers. |
| `docs/dev/estructura.md` | Documents a nonexistent `ticketPrinter.js` (`:150`) and an unread `ELECTRON_PRINTER_NAME` (`:187`). | Update the printing architecture and configuration docs. |
| `docs/dev/planificacion.md` | Still lists `node-printer`/PDF and calls thermal printing a stub (`:281`, `:393`). | Update to the chosen native ESC/POS design. |
| `openspec/specs/cierre-caja/spec.md` | Requires `electron-pos-printer` and `generateReporteHtml()` for close/report printing (`:292`). | Add a delta in `propose`/`spec` for the native transport; do not rewrite the baseline spec in explore. |
| `CHANGELOG.md` | User-visible printing change. | Add an entry at implementation time. |

## Test / verification inventory

### What exists

- **Pure formatting harness**: `taquilla/scripts/check-pure.mjs` imports the renderer's
  pure TS modules with Node 24 type stripping. It already asserts `formatearJugada`,
  `nombresJuegos`, and `buildReciboLines` behavior (`:940-962`, `:1461-1580`) and exits
  non-zero on failure (`:1655`). It is run in CI by `front-checks`
  (`.github/workflows/ci-cd.yml:105`).
- **Main-process unit test pattern**: `taquilla/electron/main/updater.test.cjs` uses
  `node --test` with no Electron dependency (`:1-9`). No CI step runs it today.
- **E2E stubs**: `stubPrintIpc` removes and re-registers `get-mac`, `print-ticket`,
  `print-cierre`, `print-reporte` in the main process and records payloads in
  `globalThis.__e2e_prints` (`e2e/helpers/app.mjs:123-140`; channel list
  `e2e/helpers/fixtures.mjs:18-23`). The real `ipcHandlers.cjs` never runs in E2E.
- **E2E assertions**: sale payload (`02:44-50`, `03:35-40`), receipt kind and totals
  (`04:48-56`), payment type (`08:77-84`), bridge surface and no native dialog
  (`smoke:24-27,41-56`).
- **CI**: `front-checks` installs, runs `check-pure`/`check-icons`, and builds taquilla
  (`ci-cd.yml:88-110`); `e2e` builds and runs `xvfb-run -a pnpm e2e` on Ubuntu
  (`:121,206,213`). There is no Windows runner and no printer hardware in CI.
- **Packaging**: `electron-builder` is not exercised in CI; `files` and
  `extraResources` are defined in `taquilla/package.json:57-68`.

### Gaps to close during `propose`/`design`

1. **No unit tests for the HTML generators or the IPC handlers.** The four generators
   and `printHtml` are untested; only their payload inputs are covered indirectly by E2E.
2. **E2E cannot verify bytes.** The stubs bypass the main handler, so a native encoder
   needs a pure unit test (CJS `node --test` or an importable module in `check-pure`).
3. **New IPC channels would be unstubbed.** If printer discovery/configuration adds
   `get-printers`/`set-printer-config` (or similar), `STUB_CHANNELS` and `stubPrintIpc`
   must handle them or E2E will hit the real main process (which has no printers in CI).
4. **No CI runner for `node --test`.** Add the encoder test to `front-checks` or fold
   it into `check-pure`; otherwise it will not gate merges.
5. **No hardware smoke test.** CI cannot verify code page, width, cutter, feed, or
   spooler behavior. The design needs a manual Windows checklist with the actual model.

### Suggested verification matrix (for planning, not a requirement)

| Case | 80 mm | 58 mm | Notes |
|---|---|---|---|
| Sale ticket, single game | pending | pending | Check columns, accents, totals. |
| Sale ticket, multi-game with group rows | pending | pending | Group rows and line wrapping. |
| Sale ticket with `sorteo`/`Hora` column | pending | pending | Fifth column pressure. |
| Receipt with 8 fields and mixed states | pending | pending | Stacked layout. |
| Close with arqueo | pending | pending | 5-column breakdown. |
| Range report with many closes | pending | pending | Pagination, feed, cut, duplicate headers. |
| Missing printer / raw failure | pending | pending | Error contract and no duplicate print. |
| Accents and long amounts | pending | pending | Code page and truncation. |

## Open questions

| Priority | Question | Why it blocks |
|---|---|---|
| P0 | What is the exact printer model, connection (USB/Windows queue/network/serial), driver, paper width (58/80 mm), cutter, and cash drawer? | Determines code page support, column count, cut/feed, and which transport can work. |
| P0 | Is Windows PowerShell 5.1 with `System.Printing` allowed on the target PCs (no AppLocker/ExecutionPolicy block)? | `electron-pos-printer.sendRawCommand` depends on it; if blocked, a native addon, network 9100, or vendor tool is required. |
| P0 | How is the printer selected and persisted? Env var, explicit dropdown, auto-detect, or system default? | There is no config store today; `ELECTRON_PRINTER_NAME` is documented but unread. A renderer-supplied name has trust implications. |
| P0 | What should happen when raw printing fails? Return an error and let the operator retry, or fall back to the current HTML dialog? | Automatic fallback can duplicate a partially printed document; the current `{ success, message }` contract has no retry queue. |
| P1 | Is 58 mm a supported target, or is the product 80 mm only? | The 8-column receipt and 5-column close/report tables degrade severely at 32 columns. |
| P1 | Which code page does the printer default to, and does it honor `ESC t`? What is the fallback for accents? | Spanish animal names and payment labels are common; wrong bytes produce garbage. |
| P1 | Should money/date formatting move to a deterministic main-process formatter? | `toLocaleString('es-VE')` depends on ICU and produces variable-width strings; raw columns need fixed widths. |
| P1 | For 58 mm, is the acceptable result an abbreviated ticket, a stacked one-value-per-line layout, or "not supported"? | Changes the encoder design and the acceptance criteria. |
| P1 | For a long range report, should the encoder paginate, feed, and cut per close, or emit one continuous job? | Affects the command sequence and paper handling. |
| P2 | Is the cash drawer in scope? Is it opened on every sale or only on demand? | The library exposes `openCashDrawer`; no current product requirement exists. |
| P2 | Should the ticket code be printed as barcode/QR? | Not printed today; adding it changes the encoder and printer capability requirements. |
| P2 | Should the `taquilla` name be added to the range report payload? | The report currently falls back to `'Taquilla'` because the backend rollup has no `taquilla` object. |
| P2 | Where should the pure encoder live so both the main process and tests can import it? | `.cjs` + `node --test`, `.mjs` + dynamic import, or a shared module; `src/utils/*.ts` is renderer-only. |
| P2 | Is a settings UI needed, or is configuration via env/config file sufficient? | Determines whether preload and renderer pages change at all. |

## Options considered

| Option | Description | Pros | Cons | Effort |
|---|---|---|---|---|
| A. Tune the HTML raster path | Set `silent: true`, `deviceName`, `dpi`/`scaleFactor`, and adjust CSS. | No layout rewrite; smallest change. | Still Chromium rasterization and driver halftoning; device-dependent; does not guarantee crisp text. | Low |
| B. Native ESC/POS in the main process | Keep the existing IPC payloads and HTML templates; add a pure byte encoder and a transport adapter, with the dialog as an explicit fallback. | Crisp printer-ROM text; deterministic bytes; testable without hardware; reuses the current payloads and call sites. | Requires per-kind layout rewrite, code-page handling, printer config, transport work. | Medium-High |
| C. Use `PosPrinter.print` with `PosPrintData[]` | Adopt the library's structured line API instead of HTML. | Less custom layout code. | Still renders in a `BrowserWindow` and calls `webContents.print` (raster); the current branch is already wrong; limited control. | Medium |
| D. Network raw TCP 9100 | Send ESC/POS bytes directly to a network printer socket. | No PowerShell/spooler dependency; crisp. | Only network printers; no USB/serial; needs printer IP configuration and firewall rules. | Medium |

**Recommendation for `propose`:** Option B. Preserve the renderer IPC contract and payload
shapes, add a pure ESC/POS encoder in a module the main process can load, add a transport
adapter (start with the library's `sendRawCommand`, keep a network/other fallback as an
open decision), and keep the HTML generators only as an explicit, operator-visible
fallback. Treat 58 mm, code page, cutter/feed, and cash drawer as design questions to
resolve with the owner before setting acceptance criteria.

## Commands for the owner

These were not run in the read-only explore phase. Run them in the target worktree or on
the Windows POS machine as applicable.

```bash
# The worktree has no node_modules; install before any local test/build.
cd taquilla && pnpm install --frozen-lockfile

# Pure formatting harness (CI parity).
cd taquilla && node scripts/check-pure.mjs

# Existing main-process unit-test pattern.
cd taquilla && node --test electron/main/updater.test.cjs

# Frontend build (what CI runs).
cd taquilla && pnpm build

# E2E (requires backend, MySQL, and seeded E2E data).
cd taquilla && xvfb-run -a pnpm e2e
```

```bash
# This repository ignores all *.md files by default (.gitignore:44); existing
# OpenSpec artifacts are tracked because they were force-added. Force-add this
# artifact when committing it.
git add -f openspec/changes/impresion-escpos-nativa/explore.md
```

```powershell
# Windows POS machine: inventory the actual print queues and drivers.
Get-Printer | Format-Table Name, DisplayName, DriverName, PortName
```

Hardware verification after implementation (not runnable in CI): print the 80 mm and
58 mm samples, the receipt, a close with arqueo, and a long range report; confirm accents,
column alignment, cut, feed, drawer (if in scope), and the behavior when PowerShell or
the printer is unavailable.
