# Proposal: Printer configuration and discovery (Phase 1)

## Intent

Remove all environment-variable control over native ESC/POS printing and make
`userData/printer-config.json` the single source of truth, adding the writer and
the discovery surface it never had. Today seven config keys plus
`LOTTO_PRINT_TEST` are read in one function (`infra/config.ts:39-65`), there is no
writer, and the UI cannot list or pick a printer. Phase 1 lands the file-based
foundation; IPC, preload, and UI follow in later phases.

## Scope

### In Scope

- Remove env reads: `LOTTO_PRINTER_NAME`, `LOTTO_PRINT_PAPER`, `LOTTO_PRINT_COLUMNS`,
  `LOTTO_PRINT_CODEPAGE`, `LOTTO_PRINT_CUT`, `LOTTO_PRINT_FEED`,
  `LOTTO_PRINT_CUT_PER_BLOCK`, `LOTTO_PRINT_TEST`, `process.env`, and the `env`
  field in `ResolveConfigInput`/`CreatePrintingOptions`.
- File-only `resolveConfig`; add `saveConfig` (validate → canonical object → atomic
  tmp+rename); defaults 58mm/32/384/CP850/partial, `printerName: null` when absent.
- `listPrinters` (name + `displayName` + metadata), `suggestPrinters`
  (`/pos|thermal|ticket|receipt|58|80|xp|tm/i`, single printer wins), exported name
  validation (only reject control chars/newlines; parentheses/printables allowed).
- Remove `maybeRunStartupTest`, `getWindow`, and the `index.ts`/`main.cjs` env plumbing.
- Rewrite the commissioning-slip instruction (no `LOTTO_PRINT_TEST=1`); layout unchanged.

### Out of Scope

IPC channels, preload bridge, settings UI, auto-detection prompt, error surfacing in
pages, office mode, version bump/release. `printTest.ts:47` literal stays until Phase 2.

## Key Decisions

| Decision | Choice |
|---|---|
| Source | `printer-config.json` only; no env, no `process.env` |
| Writer | in `infra/config.ts`; full replace, `version: 1`, unknown keys dropped |
| Write safety | temp file in same dir + `renameSync` |
| Save validation | syntax (name) + enumeration membership (canonical queue name) |
| Discovery shape | `PrinterInfo[]` (UI needs `displayName`) |
| Suggestion | deterministic token score, stable tie-break by enumeration order |
| Write failure code | new `E_CONFIG_WRITE_FAILED` (not a validation failure) |

## Capabilities

### New Capabilities

- `configuracion-impresora`: file config as single source, save/writer, validation.
- `descubrimiento-impresoras`: list, suggest, validate printer names; enumerated match.

### Modified Capabilities

None — no existing spec covers native printing.

## Delivery (Feature Branch Chain: tracker → p1a → p1b; only tracker → main)

| Slice | Content | Est. changed |
|---|---|---|
| **P1a** | types (drop `printTest`/`maybeRunStartupTest`/`getWindow`/`env`; add `WriteFile`+save types) · config (`saveConfig`, atomic write, no env) · index/main env+startup removal · config/index test rewrite · `docs/dev/estructura.md` | ~250 (band 230–290) |
| **P1b** | discovery (`listPrinters`/`suggestPrinters`/`validatePrinterName`, config-only errors) · list/suggestion types · discovery tests | ~210 (band 190–240) |

Each slice ≤300 changed lines; tests/docs travel with the unit they verify.

## Affected Areas

| Area | Impact | Notes |
|---|---|---|
| `taquilla/electron/main/printing/types.ts` | Modified | contract cleanup + new types |
| `printing/infra/config.ts` | Modified | file-only + writer |
| `printing/infra/discovery.ts` | Modified | list/suggest/validate |
| `printing/index.ts`, `main/main.cjs` | Modified | env/startup removal |
| `printing/test/**` | Modified | env→file tests; +writer; +discovery |
| `docs/dev/estructura.md:194` | Modified | env list → `printer-config.json` |

## Risks

| Risk | Mitigation |
|---|---|
| Slip unreachable until Phase 2 | keep `printTest.render` + tests; document gap |
| No UI until Phase 3 → prints fail unconfigured | P1b rewrites error to point at settings screen |
| Obsolete env/startup tests | convert to file tests, don't delete assertions |
| `*.md` git-ignored | `git add -f` OpenSpec artifacts |
| `printing/dist/` generated/stale | `npm run build:printing` before manual Electron run |

## Rollback Plan

Revert the merge of `p1a` and `p1b` onto the tracker; the env contract and startup
hook are restored atomically (single Phase-1 boundary, no partial states). Baseline
suite: 89 tests; post-change ~86 (P1a) / ~91 (P1b).

## Acceptance Criteria

- [ ] No env reads in printing (`process.env`/`envValue`/`pick(env`) all gone).
- [ ] Missing file yields defaults + `printerName: null`; no warning.
- [ ] Writer persists and validates; atomic replace round-trips.
- [ ] List/suggest works with 0/1/many printers; `POS-58 (Copy 1)` accepted if enumerated.
- [ ] Control chars/newlines rejected; case-insensitive exact match preserved.
- [ ] P1a and P1b each ≤300 changed lines.
- [ ] 89 baseline − obsolete startup tests + new writer/discovery tests.

## Future Phases

2 IPC/preload/`print:test` wiring (removes `printTest.ts:47` literal) · 3 settings UI ·
4 error surfacing · 5 docs/changelog/release.
