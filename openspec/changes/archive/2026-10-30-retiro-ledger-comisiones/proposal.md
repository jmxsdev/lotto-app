# Proposal: retiro-ledger-comisiones (Model A — comisiones consultivas)

## Intent

The business chose **Model A**: commissions are consultative only. The system MUST compute and display what each entity (banca / grupo / taquilla) would receive for a date range, but MUST NOT persist a settlement ledger, MUST NOT transition commissions to `pagado`, and MUST NOT run a payment workflow. Today the `comisiones` ledger (`estado` `pendiente`→`pagado`) duplicates computation the engine already does for reports and the close snapshot. Removing it eliminates an unwanted write path while keeping every read surface (reports, `comision_bs_equivalent` snapshot, `GET/PUT /comisiones/defaults`) intact.

## Scope

### In Scope
- Remove the settlement ledger: `comisiones` table/model, `Comision` relations, `ComisionController::{index,liquidar,pagar,comisionEnAlcanceMaster}`, ledger routes, `ComisionService::{liquidar,previsualizar,conflictosPorRango,filasLiquidables}` (refactored to a read-only row builder), ledger tests, ledger UI (Liquidar/Pagar), ledger docs and collections.
- Archive historical `comisiones` rows (Option D: copy → `comisiones_archivo_2026` → drop), preceded by `mysqldump` in the deploy runbook.
- Keep `/comisiones` as a read-only consultation page backed by a new `GET /comisiones/consulta` (Option B).

### Out of Scope
- Commission calculation rules/rates (`tasaPropia`, `tasaEfectiva`, `tasaLiquidable`, `topePorTipo`, `comisionEntidad`, `comisionesReporte`, bulk rate paths) — unchanged.
- Close snapshot over-counting with 2+ taquillas (issue #65 / `comision-cierre-cadena`).
- New payment workflow or settlement feature.
- `comision_defaults` and `GET/PUT /comisiones/defaults`.
- Issue #67 unique-index work (implemented then reverted; discarded).

## Decisions

| # | Decision | Choice | Rationale |
|---|---|---|---|
| 1 | Historical `comisiones` data | **Option D — copy→archive→drop** | Preserves paid-row audit trail and frees schema-wide FK names. Reject rename (Option B): MySQL FK constraint names (`comisiones_*_foreign`) are schema-wide, so a renamed table blocks any future re-create of `comisiones`. Production row count is UNKNOWN — the deploy MUST `mysqldump comisiones` and verify before dropping. |
| 2 | Panel surface | **Option B** (fallback A) | B matches "what each entity should receive for a date range" in one call across all three levels and reuses the tested row computation (`filasLiquidables`→`filasPorRango`). Option A (remove page, rely on `/reportes/ventas-totales` + `/reportes/cuadre-caja`) is the fallback for the smallest surface. Option C rejected: leaks display-shaped report payloads into the page. |
| 3 | Read-endpoint permission | **`super_master\|master`, no `manage_comisiones`** | Consultation is read-only; `manage_comisiones` is a write permission (still gates `PUT /comisiones/defaults`). Mirror the former `index`: `super_master` global, `master` scoped to `masterBancaIds()`. |
| 4 | Migration | **archive+drop, MySQL-safe** | MUST be MySQL 8-safe and replayable (`hasTable` guards, `INSERT...SELECT` with explicit casting, archive table declares NO FKs). #67 `down()` failed dropping a composite unique index backing an FK — the lesson is to drop the whole table, never individual indexes. |

## Capabilities

### New Capabilities
None.

### Modified Capabilities
- `comisiones`: REMOVE "Liquidación en el ledger" (4 scenarios); MODIFY Purpose (drop "liquidada como filas del ledger `comisiones` (estado `pendiente`→`pagado`)"); MODIFY "Independencia entre niveles y tope acumulado" (rule 2 "recibe fila en el ledger" → "recibe su monto liquidable en el cálculo/reportes"); MODIFY "Sincronía single/bulk de liquidación" (drop `liquidación`; keep single/bulk + tope sync); ADD (Option B) "Consulta de comisión por rango" (read-only, MUST NOT persist, per-level amounts, role scope, inclusive range).

`cierre-caja` and `reportes-agencia` reference the `comisiones` capability, not the ledger — no delta (verify wording at spec phase).

## Approach

Delete the ledger write/read/state paths and UI; refactor `filasLiquidables` into a public rows-only `filasPorRango` and `previsualizar` into `consultaPorRango`, exposed via `GET /comisiones/consulta`; add the archive+drop migration; update specs, docs, and collections.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `backend/app/Models/Comision.php`, `Banca.php`, `Grupo.php`, `Taquilla.php` | Removed | ledger model + relations |
| `backend/app/Http/Controllers/Api/ComisionController.php` | Modified | keep `defaults`/`updateDefaults`; remove `index/liquidar/pagar/comisionEnAlcanceMaster`; add `consulta` |
| `backend/app/Services/ComisionService.php` | Modified | keep engine; remove `liquidar/conflictosPorRango`; rename `filasLiquidables`→`filasPorRango`, `previsualizar`→`consultaPorRango` |
| `backend/routes/api.php` | Modified | remove ledger routes; add `GET /comisiones/consulta` |
| new migration | Added | archive+drop `comisiones` |
| `panel/src/pages/comisiones.astro`, `AdminLayout.astro` | Modified | read-only consultation page + sidebar label |
| `collections/Comisiones/*` | Modified | remove ledger YAMLs; keep defaults |
| `openspec/specs/comisiones/spec.md` | Modified | spec delta (via spec phase) |
| `docs/dev/*` | Modified | remove ledger references |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Irreversible loss of production rows on drop | High | Option D + `mysqldump` pre-migrate; verify/export before drop |
| FK name collision if renamed then re-created | Med | Option D (never rename-and-recreate) |
| MySQL test failures from careless FK/index drop | Med | Drop whole table; never edit historical migrations |
| Close snapshot is sole persisted figure and over-counts siblings | High (visibility) | Out of scope; state explicitly; never present snapshot as per-entity amounts |
| Dead `/comisiones` route | Low | Ship Option B (or remove sidebar link in same slice) |
| Diff exceeds 400-line review budget | Med | `auto-chain`: `sdd-tasks` forecasts chained PRs |

## Rollback Plan

- **Code**: `git revert` of the merge restores model, relations, controller, routes, page, tests, collections. The restored page needs routes restored too or it 500s.
- **Schema**: `down()` re-creates an empty `comisiones` (no FK collisions); restoring rows requires the pre-deploy dump.
- **Spec**: if the delta was archived, restoring the removed requirement is manual.

## Dependencies

- Production `comisiones` row count / export decision before drop (`mysqldump comisiones` in the runbook).
- `docs/dev/motor-cierres-comisiones.md` is untracked in the main worktree and absent here — commit it with this change or edit it in main.

## Success Criteria

- [ ] No code path writes to `comisiones`; table absent (or archived) after migration.
- [ ] `GET /comisiones/consulta` returns banca+grupo+taquilla amounts for a range; nothing persisted; `master` limited to `masterBancaIds()`.
- [ ] Reports (`ventas-totales`, `cuadre-caja`), `GET /cierre/actual`, and `GET/PUT /comisiones/defaults` behave unchanged.
- [ ] Historical rows survive in `comisiones_archivo_2026` (production verified/exported before drop).

## Chained PR Slices

Likely review slices (exact forecast left to `sdd-tasks`):
1. Backend ledger removal + migration (model, relations, controller methods, routes, service, migration).
2. Panel consultation page + `GET /comisiones/consulta` + collections.
3. Docs + spec delta + test rewrites.
