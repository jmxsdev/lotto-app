# Tasks: Printer configuration and discovery (Phase 1)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | P1a ~290 (band 275–305) · P1b ~215 (band 195–235) |
| 400-line budget risk | Medium |
| Chained PRs recommended | Yes |
| Suggested split | PR 1: P1a → tracker · PR 2: P1b → P1a |
| Delivery strategy | auto-chain |
| Chain strategy | feature-branch-chain |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: Medium

## Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| P1a | File config becomes the only source; atomic writer; env/startup removal | PR 1 → tracker | `node --test electron/main/printing/test/infra/config.test.cjs electron/main/printing/test/index.test.cjs` | N/A — pure module + unit tests, no IPC/UI runtime boundary yet | Revert PR 1; env/startup return together |
| P1b | Discovery list/suggest/validate surface | PR 2 → P1a | `node --test electron/main/printing/test/infra/discovery.test.cjs` | N/A — no IPC/UI yet; resolution has no runtime consumer | Revert PR 2; discovery module alone |

## Slice P1a — file config is the only source (~290 lines)

| ID | Title | Files | Dep | Acceptance | Tests | Lines | Commit |
|---|---|---|---|---|---|---|---|
| 1.1 | Contract cleanup + writer/result types | M `printing/types.ts` | — | No `printTest`/`env`/`getWindow`/`maybeRunStartupTest`; add `PrintResult.code`, `WriteFile`, `RenameFile`, `E_PAYLOAD_TOO_LARGE`, `SavePrinterConfigInput`/`PrinterConfigFile`/`SavedPrinterConfig` | type-only | 30 | `refactor(printing): configuracion por archivo y escritor atomico` |
| 1.2 | Shared name predicate | C `printing/infra/names.ts` | 1.1 | `isSafePrinterName` (printable, 1–128, no C0/C1/newlines) | — | 12 | same |
| 1.3 | File-only `resolveConfig` + `saveConfig` | M `printing/infra/config.ts` | 1.2 | No `LOTTO_*`/`envValue`/`pick`; `saveConfig` merge+validate+atomic tmp/rename; `E_CONFIG_WRITE_FAILED`; keep `CONFIG_FILE`/`DEFAULTS`/`CODE_PAGES`/`CUTS` | `config.test.cjs` | 70 | same |
| 1.4 | Composition root: drop env/getWindow/startup | M `printing/index.ts` | 1.1, 1.3 | `resolveConfig({userDataPath, readFile})`; propagate `code`; keep builder map/lock | `index.test.cjs` | 36 | same |
| 1.5 | Main wiring | M `main.cjs` | 1.4 | `createPrinting({ userDataPath })`; no env/getWindow/startup hook | `index.test.cjs` (indirect) | 6 | same |
| 1.6 | Commissioning slip copy | M `printing/documents/printTest.ts` | — | Neutral `Prueba: Configuracion impresora`; no `LOTTO_` | `printTest.test.cjs` | 2 | same |
| 1.7 | Config tests: file-only + writer | M `test/infra/config.test.cjs` | 1.3 | 11 tests below; `readFile` fakes; no disk IO | 11 | 82 | same |
| 1.8 | Index tests: readFile fake + code | M `test/index.test.cjs` | 1.4 | 9 tests; remove 4 startup; assert `code` | 9 | 46 | same |
| 1.9 | printTest assertions | M `test/documents/printTest.test.cjs` | 1.6 | 2 assertions; no `LOTTO_` | 6 (unchanged) | 2 | same |
| 1.10 | Docs | M `docs/dev/estructura.md` | 1.3 | env list → `printer-config.json` | — | 2–4 | `docs: referencia de configuracion de impresora` |

P1a total ≈ 290. Trim order if >300: 1.10 → P1b; merge writer failure+repair tests; drop non-text `printerName` warning assert; merge paper-alias test; merge invalid/unsafe writer loop. Do not drop atomic-write, merge, `E_CONFIG_WRITE_FAILED`, or env-removal asserts.

## Slice P1b — discovery becomes UI-ready (~210 lines)

| ID | Title | Files | Dep | Acceptance | Tests | Lines | Commit |
|---|---|---|---|---|---|---|---|
| 2.1 | List/suggest/resolve types | M `printing/types.ts` | 1.1 | `PrinterInfo`, `PrinterListResult`, `ListPrintersInput`; remove dead `PrinterDiscovery` | type-only | 14 | `feat(printing): lista y sugiere impresoras` |
| 2.2 | Discovery surface | M `printing/infra/discovery.ts` | 1.2, 2.1 | `listPrinters`, `suggest` heuristic, exact/case-insensitive resolution, parentheses accepted, controls rejected, no `LOTTO_*` copy; delete `NAME_RE` | `discovery.test.cjs` | 95 | same |
| 2.3 | Discovery tests | M `test/infra/discovery.test.cjs` | 2.2 | 13 tests below; list 0/1/many, suggestion, exact/case-insensitive/no-substring, parentheses/control, errors with `available`/`suggested` | 13 | 105 | same |

P1b total ≈ 215. Trim order if needed: merge 0/1-printer list cases; merge Unicode into printable-name test; fold 129-char into control loop. Never drop exact/case-insensitive, no-substring, metadata-preservation, or `available`/`suggested` asserts.

## Test plan (Spanish)

### `config.test.cjs` → 11 tests
1. `la configuracion predeterminada es 58mm / 32 columnas / 384 puntos con nombre nulo`
2. `los alias de papel 58, 80 y 80-512 se normalizan desde el archivo`
3. `el archivo sobrescribe los predeterminados`
4. `un JSON malformado se ignora con una advertencia`
5. `valores de enum o enteros invalidos vuelven al predeterminado con advertencia`
6. `printerName queda nulo si falta, esta en blanco o no es texto, y printTest ya no existe`
7. `saveConfig escribe el objeto canonico con temp + rename y el resolve posterior lo devuelve igual`
8. `saveConfig conserva los campos no enviados y permite quitar el override de columnas`
9. `saveConfig rechaza valores invalidos y nombres con controles, y guarda null si el nombre esta vacio`
10. `saveConfig convierte fallos de escritura o rename en E_CONFIG_WRITE_FAILED`
11. `saveConfig repara un archivo malformado y descarta claves desconocidas`

### `index.test.cjs` → 9 tests
Remove 4 (3 startup + 1 no-window transport). `create()` supplies `readFile: () => JSON.stringify({ printerName: 'POS-58' })`. Assert `code`: `E_PRINTER_NOT_FOUND`, `E_PRINT_BUSY`, `E_PAYLOAD_TOO_LARGE`, `E_PAYLOAD_INVALID`.

### `discovery.test.cjs` → 13 tests
1. `listPrinters conserva name, displayName, description, options y campos extra`
2. `listPrinters devuelve una lista vacia y suggested null sin impresoras`
3. `listPrinters sugiere la unica impresora enumerada`
4. `listPrinters sugiere la primera coincidencia por token en name o displayName`
5. `listPrinters devuelve suggested null cuando ninguna impresora coincide`
6. `listPrinters y resolvePrinter mapean rechazo o timeout a E_DISCOVERY_TIMEOUT`
7. `resuelve la cola por coincidencia exacta y luego sin distinguir mayusculas`
8. `no hace coincidencia por subcadena y el error incluye available y suggested`
9. `una lista vacia o una cola ausente lista las impresoras disponibles`
10. `rechaza nombres con controles, saltos de linea o longitud excesiva sin enumerar`
11. `acepta parentesis y otros imprimibles solo si la cola esta enumerada`
12. `un nombre ausente produce E_PRINTER_UNCONFIGURED con available y suggested`
13. `acepta nombres Unicode con los signos permitidos`

## Branch / PR plan

```text
main (1930813)
 └─ feature/configuracion-impresora                     tracker, draft/no-merge
      └─ feature/configuracion-impresora/p1a-config        PR #1 → tracker   📍 P1a
           └─ feature/configuracion-impresora/p1b-discovery  PR #2 → p1a      📍 P1b
```

- P1a starts from tracker, targets tracker. Verify `pnpm test:printing` (90) + scoped greps.
- P1b starts from `p1a-config`, targets `p1a-config`. Verify `pnpm test:printing` (95).
- Each PR body: start, finish, dependency, out-of-scope (IPC/preload/UI), verification, rollback, chain-context diagram.
- **This cycle stops after P1b; no merge to main.**

## Owner commands (agent must NOT run)

```bash
cd taquilla && pnpm install
pnpm build:printing
pnpm test:printing            # 90 after P1a · 95 after P1b
pnpm exec tsc -p tsconfig.printing.json    # optional
cd .. && node taquilla/scripts/check-pure.mjs
git add -f openspec/changes/configuracion-impresora/*.md   # *.md is ignored
```

Acceptance greps:

```bash
grep -rn "process\.env\|envValue\|pick(env" taquilla/electron/main/printing   # none
grep -rn "maybeRunStartupTest" taquilla/electron                               # none
grep -rn "LOTTO_" taquilla/electron/main/printing                             # none after P1b
grep -rn "getWindow" taquilla/electron/main/printing                          # none
grep -n  "getWindow" taquilla/electron/main/main.cjs                          # updater call only
```

## Definition of done

**P1a**: no env read in printing; `saveConfig` atomic + round-trips; startup hook gone; `code` propagated; 90 tests pass; ≤300 lines.

**P1b**: `listPrinters` preserves metadata + deterministic suggestion; exact→case-insensitive, no substring; controls rejected / parentheses via membership; no `LOTTO_*` copy; 95 tests pass; ≤300 lines.

**Stop after Phase 1**: no IPC, preload, UI, or release work. Tracker stays unmerged until Phase 2/3.

## Test count

89 baseline → 90 after P1a (89 − 4 startup + 5 writer) → 95 after P1b (+5 discovery). Static count verified.

## Risks / open questions

- Commissioning slip unreachable until Phase 2 (`printTest.render` kept; only instruction line changes).
- No settings UI until Phase 3; fresh installs fail `E_PRINTER_UNCONFIGURED` (copy rewritten in P1b).
- Stale `printing/dist` (git-ignored, loaded by `main.cjs:5`) → rebuild before any Electron run.
- `.gitignore` ignores `*.md` → `git add -f` OpenSpec artifacts.
- P1a near the 300-line cap → apply trim order; do not weaken assertions.
- No blocking questions.

## Apply Progress (Phase 1)

- [x] 1.1 Contract cleanup + writer/result types
- [x] 1.2 Shared name predicate (`infra/names.ts`)
- [x] 1.3 File-only `resolveConfig` + `saveConfig` (atomic temp + rename)
- [x] 1.4 Composition root: drop env/getWindow/startup; propagate `code`
- [x] 1.5 Main wiring (`createPrinting({ userDataPath })`, startup hook removed)
- [x] 1.6 Commissioning slip copy
- [x] 1.7 Config tests: file-only + writer (11)
- [x] 1.8 Index tests: `readFile` fake + `code` (9)
- [x] 1.9 printTest assertions (6)
- [x] 1.10 Docs (`docs/dev/estructura.md`)
- [x] 2.1 List/suggest/resolve types
- [x] 2.2 Discovery surface (`listPrinters`, `suggest`, exact/case-insensitive resolution)
- [x] 2.3 Discovery tests (13)

Static count: 95 tests after Phase 1.

## Apply Progress (Phase 2)

Phase 2 was executed on `feature/configuracion-impresora-p2` (not planned in the Phase 1 tables above).

- [x] 2.1 Contracts: `PrinterListResponse`, `PrinterConfigResponse`, `TestPrintInput`, facade methods, `writeFile`/`renameFile`
- [x] 2.2 Facade: `listPrinters`, `getConfig`, `saveConfig` (membership-validated), `testPrint` (paper profile)
- [x] 2.3 IPC handlers: `print:list-printers`, `print:get-config`, `print:set-config`, `print:test`
- [x] 2.4 Preload bridge: `listPrinters`, `getPrinterConfig`, `savePrinterConfig`, `testPrint`
- [x] 2.5 Tests: 8 fake-based tests in `test/index.test.cjs`

Static count: 103 tests. Phase 3+ not started. `saveConfig` returns a Promise (async membership check).
