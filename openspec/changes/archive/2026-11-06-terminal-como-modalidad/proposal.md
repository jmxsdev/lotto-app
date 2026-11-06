# Proposal: Terminal como modalidad (absorb `terminal-activo` into Trío Activo)

## Intent

Business decision (2026-10-16, RF-01.7): `terminal` is a **modality of `tripletas`**, not a game type. `terminal-activo` (Terminal Trío) is absorbed into `trio-activo` (2 last digits of its triple, 60×). Catalog drops 21→20 games; the `terminales` type is retired.

## Scope

### In Scope

- Remove `Terminales` UI tab from taquilla (`dashboard.astro`, `resultados.astro`) and panel (`resultados-loterias.astro`).
- Retire backend `terminal-activo`: migration, `TerminalesSeeder`, `Plugins/Juegos/Terminales.php`, `PremiosOficiales` entry + `ESPEJO_EXTRA`, `AnimalitosScraper` `terminal-trio` mapping + `terminal_activo` slug branch, `ScraperSourceResolver::KEY_TERMINAL_ACTIVO`.
- Regenerate `docs/juegos.json` (20 games) and copy byte-identical to `taquilla/src/data/juegos.json`.
- Remove `terminales` from `TipoJuego`/`TIPOS_VALIDOS`/`derivarFamilia` (`catalogo.ts`); clean legacy ticket branches (`ticket.ts`, `recibos.ts`, `historial.astro`, `dashboard.astro`); remove logo (`logos.json`, `manifest.json`, asset).
- Update `check-pure.mjs` (21→20, drop terminal assertions, move `"05"` search test to `trio-activo`), backend tests/fixtures, specs, docs.
- Version bump + CHANGELOG.

### Out of Scope

- New terminal integrations for other operators (DEC-06).
- `ComisionService` code change — terminal play sells under `tripletas` → 25% cap (DEC-09 resolved).
- Historical-data migration (no sales in production; system in development).

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `motor-premios`: remove standalone `terminal-activo` (base 60× vs `numero`); terminal liquidated as `tripletas` modality (2 last digits, 60×).
- `comisiones`: drop `terminales` from `topePorTipo` surface ("otros tipos sin tope" scenario); terminal inherits 25% via `tripletas`.
- `catalogo-juegos`: catalog 21→20; `terminales` type removed from catalog/type contract.

## Approach

Two-phase, front-first to avoid sale errors in taquillas that have not received the OTA update.

**Phase 1 — Front-first (backend unchanged).** Remove `Terminales` tabs. Keep backend `terminal-activo` active, `Terminales` plugin/seeder, `juegos.json` at 21 byte-identical to `docs/juegos.json`, `TipoJuego`/`TIPOS_VALIDOS` still accepting `terminales` (old catalog still parses). Version bump + CHANGELOG. Old taquillas keep selling terminal; updated ones stop offering the section; terminal remains sellable as `trio-activo` modality.

**Phase 2 — Backend retirement + total cleanup.** New migration retires `terminal-activo` + dependents (no sales to preserve). Remove seeder/plugin/`PremiosOficiales`/`ESPEJO_EXTRA`/scraper mapping. Regenerate `docs/juegos.json` (20) → copy byte-identical. Remove `terminales` from catalog types; clean ticket/recibos/historial/dashboard; remove logo asset; update `check-pure.mjs` + tests/fixtures + specs + docs.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `taquilla/src/pages/dashboard.astro` | Modified | Remove `Terminales` tab/filter/label + legacy `terminal` familia branch |
| `taquilla/src/pages/resultados.astro` | Modified | Remove `Terminales` tab |
| `panel/src/pages/resultados-loterias.astro` | Modified | Remove `Terminales` tab + grouping branch |
| `taquilla/src/utils/catalogo.ts` | Modified | Drop `terminales` from `TipoJuego`/`TIPOS_VALIDOS`/`derivarFamilia` (Phase 2) |
| `taquilla/src/utils/{ticket,recibos}.ts`, `historial.astro` | Modified | Clean legacy `terminales` branches (Phase 2) |
| `taquilla/src/data/juegos.json` | Modified | 21→20, byte-identical to `docs/juegos.json` |
| `taquilla/src/data/logos.json`, `public/images/juegos/manifest.json` + asset | Removed | terminal-activo logo entry/asset |
| `taquilla/scripts/check-pure.mjs` | Modified | 21→20; drop terminal assertions; move `"05"` test to `trio-activo` |
| `backend/database/seeders/{TerminalesSeeder,DatabaseSeeder}.php` | Removed/Modified | Drop seeder + registration |
| `backend/app/Plugins/Juegos/Terminales.php` | Removed | Drop plugin |
| `backend/app/Support/PremiosOficiales.php` | Modified | Remove `terminal-activo` + `ESPEJO_EXTRA` |
| `backend/app/Plugins/Scrapers/AnimalitosScraper.php`, `ScraperSourceResolver.php` | Modified | Remove `terminal-trio` mapping + slug branch |
| `backend/app/Services/ComisionService.php` | Unchanged | 25% inherited via `tripletas` |
| `backend/database/migrations/` | New | Retire `terminal-activo` + dependents |
| `backend/tests/**` + fixtures | Modified | Counts 21→20, drop terminal cases/fixtures |
| `openspec/specs/{motor-premios,comisiones,catalogo-juegos}` | Modified | Delta specs |
| `docs/juegos.json`, client docs | Modified | 20 games; mark DEC-09 resolved |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Byte-identical catalog drift (`docs/juegos.json` ↔ bundled copy) | Med | `juegos:export` + byte-diff assertion in `check-pure`/CI |
| No version telemetry for taquilla OTA rollout → Phase 2 breaks stale clients | Med | Keep backend active in Phase 1; retire only after rollout confirmed; `TipoJuego` keeps accepting `terminales` until catalog drops it |
| Phase 2 cascade on `terminal-activo` dependents | Low | Migration handles dependents; no production sales; documented rollback |
| Commission cap change (100%→25%) surprises | Low | DEC-09 resolved; `ComisionService` unchanged; documented |

## Rollback Plan

Phase 1: revert frontend commit (tabs reappear; backend untouched). Phase 2: roll back migration + restore seeders/plugin; `docs/juegos.json` recovered from git history (20↔21 copies are committed).

## Dependencies

- Confirmed 25% cap (DEC-09, resolved). Taquilla OTA rollout sequencing for Phase 2.

## Success Criteria

- [ ] No `type: terminales` game in catalog/DB/JSON/fronts after Phase 2.
- [ ] Terminal bet sells/liquidates as `tripletas` modality (2 last digits, 60×).
- [ ] `terminal_activo` feed not scraped (Phase 2).
- [ ] 20 games; tests green; catalog copies byte-identical.
- [ ] Old taquillas (pre-OTA) keep selling terminal without errors (Phase 1 invariant).

## Review Workload

Two PRs (chained). Phase 1 ≈ 40–80 lines. Phase 2 ≈ 300–450 lines (migration + seeder/plugin removal + tests + specs). Combined > 400-line budget → recommend chained/stacked slices. `Chained PRs recommended: Yes`. `400-line budget risk: Medium`.

## Open Decisions

None. DEC-09 (commission cap 25%) is resolved; `docs/cliente/alcance-proyecto.md` §11 still lists the unnamed "tope de comisión" row — sync to resolved during implementation.
