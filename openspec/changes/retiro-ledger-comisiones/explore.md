# Explore: retiro-ledger-comisiones (Model A — comisiones consultivas)

> Phase: explore (read-only). No production code was modified.
> Repo root: `/home/gzuz/Documentos/lotto-app-worktrees/sdd-issue-67-modelo-a` (branch `sdd/issue-67-modelo-a`).

## 1. Summary

The business has chosen **Model A**: commissions are **consultative only**. The system MUST compute and display what each entity (banca / grupo / taquilla) would receive for a date range, but MUST NOT persist a settlement ledger, MUST NOT transition commissions to `pagado`, and MUST NOT run a payment workflow.

Today two subsystems share the calculation engine but not writes:

| Subsystem | Storage | Trigger | Decision |
|---|---|---|---|
| Close snapshot | `cierres_caja.comision_bs_equivalent` | `CierreService` on close / preview | **KEEP** |
| Settlement ledger | table `comisiones` (`estado` `pendiente`→`pagado`) | `POST /comisiones/liquidar` on demand | **REMOVE** |

The ledger is **not** fed by close snapshots. `ComisionService::liquidar` recomputes directly from `apuestas` through `filasLiquidables`. Therefore removing the ledger does not change any snapshot value, and keeping the snapshot does not require the ledger.

The calculation engine (`tasaPropia`, `tasaEfectiva`, `tasaLiquidable`, `topePorTipo`, `comisionesReporte`, `comisionEntidad`, bulk rate resolution) and the global default config (`comision_defaults`, `GET/PUT /comisiones/defaults`, used by `panel/src/pages/limites.astro`) are **shared** and stay.

Confirmed by reading code and by querying the local MySQL 8.0.46 DB (`lotto_db`): the `comisiones` table has **2 rows**, both `estado = pagado`, `monto_comision = 0.00`, `periodo = 2026-09-01..2026-10-01` (one `grupo_id = 1`, one `taquilla_id = 3`). FK names: `comisiones_banca_id_foreign`, `comisiones_grupo_id_foreign`, `comisiones_taquilla_id_foreign`; indexes: `comisiones_fecha_inicio_fecha_fin_index` plus the three FK indexes. Production row count is unknown.

## 2. Confirmed current state

- **Ledger write path**: `ComisionController::liquidar` → `ComisionService::liquidar` (inside `DB::transaction`, `lockForUpdate` on overlap query) → one `Comision::create` per (nivel, entidad, rango) with `estado = pendiente` → `Log` action `comision_liquidar`.
- **Ledger read path**: `GET /api/v1/comisiones` → paginated `Comision::with(['banca','grupo','taquilla'])`, `super_master` sees all, `master` scoped by `masterBancaIds()`.
- **Ledger state change**: `PATCH /api/v1/comisiones/{comision}/pagar` → `pendiente`→`pagado`, idempotent no-op when already paid, `Log` action `comision_pagar`. No reversal exists.
- **Ledger UI**: `panel/src/pages/comisiones.astro` (371 lines) = "Liquidar período" card + filters (estado, rango, nivel, entidad) + client-side paginated table + "Pagar" buttons + overlap-conflict rendering. Sidebar link in `AdminLayout.astro` for `super_master|master`.
- **Ledger idempotency guard**: overlap query only (`fecha_inicio <= hasta AND fecha_fin >= desde`), no unique constraint. Issue #67 (unique index) was implemented and reverted in the main worktree; it is **not** active work and must not be reintroduced here.
- **Engine consumers that must not regress**: `CierreService::comisionCadena` (→ `comisionEntidad`), `ApuestaService::comisionesParaNivel` / `comisionesPorEntidad` (→ `comisionesReporte`) for `GET /reportes/ventas-totales` and `GET /reportes/cuadre-caja`, and `CierreService::previsualizar` for `GET /cierre/actual`.
- **Permission**: `manage_comisiones` is used by `PUT /comisiones/defaults` (keep) **and** by the two ledger write routes (removed). `GET /comisiones/defaults` uses `role:super_master` only. Removing the permission from the seeder would break defaults, so it stays.
- **Tests run against MySQL** (`backend/phpunit.xml`: `DB_CONNECTION=mysql`, `DB_DATABASE=lotto_test`), not SQLite. Any schema migration in this change must be MySQL-safe.
- **`docs/dev/motor-cierres-comisiones.md` is untracked in the main worktree** (`git status` shows `?? docs/dev/motor-cierres-comisiones.md`) and **does not exist in this worktree**. Its §1, §5.4, §5.5 are the docs sections that describe the ledger.

## 3. Inventory (file:line → what it does → decision)

### Backend — ledger code (REMOVE)

| File:line | What it does | Decision |
|---|---|---|
| `backend/app/Models/Comision.php:1-39` | Eloquent model for `comisiones` + 3 `belongsTo` | **REMOVE** |
| `backend/app/Models/Banca.php:52-55` | `comisiones()` hasMany | **REMOVE** |
| `backend/app/Models/Grupo.php:50-53` | `comisiones()` hasMany | **REMOVE** |
| `backend/app/Models/Taquilla.php:61-64` | `comisiones()` hasMany | **REMOVE** |
| `backend/app/Http/Controllers/Api/ComisionController.php:72-96` | `index` (paginated ledger) | **REMOVE** |
| `backend/app/Http/Controllers/Api/ComisionController.php:106-156` | `liquidar` (validation, master scope, 422 conflictos) | **REMOVE** |
| `backend/app/Http/Controllers/Api/ComisionController.php:165-186` | `pagar` (state transition + Log) | **REMOVE** |
| `backend/app/Http/Controllers/Api/ComisionController.php:192-217` | `comisionEnAlcanceMaster` helper | **REMOVE** |
| `backend/app/Http/Controllers/Api/ComisionController.php:6,7,9,10,11,14` | imports only used by ledger (`Comision`, `Grupo`, `Log`, `Taquilla`, `User`, `Carbon`) | **REMOVE** (unused) |
| `backend/routes/api.php:241-254` | `GET /comisiones`, `POST /comisiones/liquidar`, `PATCH /comisiones/{comision}/pagar` | **REMOVE** |
| `backend/app/Services/ComisionService.php:556-614` | `liquidar` (transaction, lock, insert, Log `comision_liquidar`) | **REMOVE** |
| `backend/app/Services/ComisionService.php:706-750` | `conflictosPorRango` (reads `comisiones`) | **REMOVE** |
| `backend/app/Services/ComisionService.php:6,7,12,16` | imports `Comision`, `Log`, `DB` (+ `Banca` at `:6` only used by `filasLiquidables`) | **REMOVE** (unused after refactor) |

### Backend — engine / config (KEEP)

| File:line | What it does | Decision |
|---|---|---|
| `backend/app/Services/ComisionService.php:42-49` | `topePorTipo` | **KEEP** |
| `backend/app/Services/ComisionService.php:55-190` | `tasaPropia`, `tasaEfectiva`, `tasaLiquidable`, ancestor sum, `cadena`, `defaultGlobal` | **KEEP** |
| `backend/app/Services/ComisionService.php:205-216` | `comisionEntidad` (used by `CierreService::comisionCadena`) | **KEEP** |
| `backend/app/Services/ComisionService.php:223-337` | `comisionesReporte`, `bucketsPorEntidad`, `aplicarTasas` (used by reports + close + preview) | **KEEP** |
| `backend/app/Services/ComisionService.php:350-511` | bulk rate resolution (`tasasLiquidablesBulk`, mirrors of single path) | **KEEP** |
| `backend/app/Http/Controllers/Api/ComisionController.php:27-63` | `defaults`, `updateDefaults` | **KEEP** |
| `backend/routes/api.php:228-239` | `GET/PUT /comisiones/defaults` | **KEEP** |
| `backend/app/Models/ComisionDefault.php`, `backend/database/migrations/2026_09_29_000001_create_comision_defaults_table.php` | global default rates | **KEEP** |
| `backend/database/migrations/2026_09_29_000003_add_comision_to_cierres_caja_table.php`, `backend/app/Models/CierreCaja.php` | close snapshot column | **KEEP** |
| `backend/app/Services/CierreService.php:275-320` | `comisionCadena` + `comision_bs_equivalent` in `calcularTotales` | **KEEP** |
| `backend/app/Services/ApuestaService.php:1008-1075` | `comisionesParaNivel`, `comisionesPorEntidad`, `comisionesRollup` | **KEEP** |
| `backend/database/seeders/RolesAndPermissionsSeeder.php:43,72` | `manage_comisiones` permission | **KEEP** (still gates `PUT /comisiones/defaults`) |

### Backend — read-only preview (REPLACE or REMOVE, see §4)

| File:line | What it does | Decision |
|---|---|---|
| `backend/app/Services/ComisionService.php:521-538` | `previsualizar` = rows + `conflictos`; **no route, no UI**, only unit tests | **REPLACE** (rows-only consulta) or **REMOVE** |
| `backend/app/Services/ComisionService.php:626-694` | `filasLiquidables` (private): one row per entity with sales, all 3 levels, optional `bancaIds` | **REPLACE** (make public rows-only `filasPorRango`, drop `bancaIds/grupoIds/taquillaIds` return payload) or **REMOVE** |

### Migrations

| File | What it does | Decision |
|---|---|---|
| `backend/database/migrations/2026_07_10_035547_create_comisiones_table.php` | original `comisiones` create | **KEEP in history** (never edit) |
| `backend/database/migrations/2026_09_29_000002_add_rango_fechas_to_comisiones_table.php` | adds `fecha_inicio`/`fecha_fin` + index | **KEEP in history** (never edit) |
| **new** migration | archive and/or drop `comisiones` | **ADD** (see §5) |

### Panel

| File:line | What it does | Decision |
|---|---|---|
| `panel/src/pages/comisiones.astro:1-371` | ledger UI (liquidar, list, pagar) | **REPLACE** (read-only consultation) or **REMOVE** |
| `panel/src/layouts/AdminLayout.astro:259-260` | sidebar "Comisiones" link + ledger comment | **REPLACE** label/comment or **REMOVE** |
| `panel/src/pages/limites.astro:166,181` | `GET/PUT /comisiones/defaults` | **KEEP** |
| `panel/src/pages/reportes/ventas.astro:115` | `Comision` column | **KEEP** |
| `panel/src/pages/cuadre.astro:119,130` | `Comision` column + total | **KEEP** |

### API collections

| File | What it does | Decision |
|---|---|---|
| `collections/Comisiones/Listar Comisiones.yml` | ledger list | **REMOVE** |
| `collections/Comisiones/Liquidar Comisiones.yml` | ledger write | **REMOVE** |
| `collections/Comisiones/Pagar Comision.yml` | ledger state change | **REMOVE** |
| `collections/Comisiones/Ver Defaults.yml`, `Actualizar Defaults.yml` | defaults | **KEEP** |
| `collections/Comisiones/folder.yml` | folder | **KEEP** (or drop if only ledger left) |

## 4. Read-only consultation surface — options

Reports already satisfy "show what each entity should receive", but per level and with report-specific columns:

- `GET /api/v1/reportes/ventas-totales?nivel=banca|grupo|taquilla|agencia&fecha_desde&fecha_hasta` → rows with `Entidad`, `Venta`, `Premio`, `Comision` (computed by `ApuestaService::comisionesPorEntidad`). Role-scoped server-side in `ReporteController::buildApuestaQuery` for every role.
- `GET /api/v1/reportes/cuadre-caja` → `Comision` column + totals.
- `GET /api/v1/cierre/actual` → close snapshot `comision_bs_equivalent` for one taquilla (not a range/entity view).

### Option A — Remove the page; reports are the only surface

- Pros: zero new backend code, zero new spec requirement; role scoping already correct for all roles; no 404 risk if the sidebar link is dropped together.
- Cons: no single view spanning banca + grupo + taquilla for one range (needs 3 report calls); report rows only include entities with sales in the range (same limitation as `filasLiquidables`, so no regression); `agencia` rows show an informative rollup that can be misread as payable; bookmarked `/comisiones` becomes 404.
- Effort: **Low**.

### Option B — Keep `/comisiones` as read-only consultation, add a thin read endpoint

- Shape: `GET /api/v1/comisiones/consulta?desde&hasta[&banca_ids]` → `{ data: [{nivel, entidad_id, entidad, monto_comision}] }`, reusing `filasLiquidables` (renamed `filasPorRango`, rows only). `previsualizar` is renamed/replaced by this method; `conflictosPorRango` disappears.
- Pros: one request returns all three levels (matches the business phrasing "what each entity should receive for a date range"); reuses the already-tested row computation (`ComisionServiceTest::test_previsualizar_devuelve_rows_de_banca_grupo_y_taquilla`); keeps a dedicated nav entry and preserves operator muscle memory.
- Cons: new route + new spec requirement + role-scope logic to write and test; partially duplicates reports; `banca_ids` scoping is a second authorization model next to `buildApuestaQuery`.
- Effort: **Medium**.

### Option C — Keep the page, source it from `/reportes/ventas-totales`

- Pros: no backend change and no new spec requirement; dedicated nav entry survives.
- Cons: 3 HTTP calls + client-side merge; response uses Spanish display keys (`Entidad`, `Comision`) instead of API snake_case; agencia rollup confusion; couples the page to report column semantics that may evolve.
- Effort: **Low–Medium**.

### Recommendation

**Option B**, with Option A as the fallback if the reviewer prefers the smallest surface. Rationale: Model A explicitly asks the system to "show what each entity should receive for a date range"; a single consolidated view is the closest match, and the row computation is already written and tested. Option C is rejected because it duplicates report semantics in the client and leaks display-shaped payloads into a page. If the proposal chooses Option A, the sidebar link must be removed in the same slice so the panel never exposes a dead route.

Either way, `CierreService::previsualizar` (close preview) and `GET /cierre/actual` stay untouched — do not confuse the two `previsualizar` methods.

## 5. Historical data and migration strategy

Facts: 3 outbound FKs (`banca_id`, `grupo_id`, `taquilla_id`, all `nullable`, `onDelete cascade`), 1 composite index on `(fecha_inicio, fecha_fin)`, no inbound FKs to `comisiones`, 2 local rows (both `pagado`, `0.00`), prod unknown. The reverted #67 migration failed in `down()` because MySQL refuses to drop a composite unique index still needed by an FK constraint (error 1553) — that is an **index-drop** problem, not a table-drop problem.

| Option | How | Pros | Cons / risks |
|---|---|---|---|
| **A. Drop** | New migration `Schema::dropIfExists('comisiones')` | Cleanest end state; MySQL drops the table with its FKs and indexes atomically; no name collisions; matches "no ledger" | Irreversible data loss; prod audit trail (paid rows) gone; `down()` can only re-create an empty table, not the data |
| **B. Rename** | `Schema::rename('comisiones', 'comisiones_archivo_2026')` | Reversible (`RENAME TABLE` back); data intact; one-line rollback | Dead table lives forever; **FK constraint names are schema-wide in MySQL/InnoDB**, so `comisiones_banca_id_foreign` etc. stay attached to the renamed table and a later re-create of `comisiones` with the same FK names would fail (duplicate constraint). Also keeps the 3 cascading FKs alive on an archive table |
| **C. Leave read-only** | Remove code/routes/model, keep table and rows untouched | Zero migration risk; data intact; fastest to ship | Contradicts "remove the ledger"; dead schema remains and future readers may assume it is live; still must delete `conflictosPorRango` because it reads the table |
| **D. Copy → archive table → drop** (recommended) | Create `comisiones_archivo_2026` with plain columns (no FKs, keep `banca_id`/`grupo_id`/`taquilla_id` as indexed integers + `periodo`, `monto_comision`, `estado`, `fecha_inicio`, `fecha_fin`, timestamps) → `INSERT ... SELECT` → `Schema::dropIfExists('comisiones')` | Preserves audit data; frees FK constraint names; clean live schema; `down()` re-creates empty `comisiones` without collisions | Two-step migration; archive table is a new (small) permanent artifact; enum/decimal copy must be explicit |

Recommendation: **D**, preceded by a `mysqldump` of `comisiones` in the deploy runbook. If the business confirms production has no rows worth keeping (local evidence suggests the ledger was barely used: 2 rows, both zero, both `pagado`), collapse to **A**. Option B is the weakest because of the FK-name collision it creates for any future re-create.

Migration safety notes for whichever option is chosen:
- Tests run on MySQL (`lotto_test`) with `RefreshDatabase`, so migrations execute in order on every test run; the new migration must be idempotent (`hasTable` guards) and must not depend on SQLite behavior.
- Do not touch `2026_07_10_035547_create_comisiones_table.php` or `2026_09_29_000002_add_rango_fechas_to_comisiones_table.php`; history must stay replayable for fresh installs.
- No inbound FKs means `DROP TABLE` needs no `dropForeign` dance; if Option D is used, the archive table must not declare FKs.
- `Log` rows with `action = comision_liquidar|comision_pagar` keep dangling `comision_id` values in `details`; harmless and acceptable as historical audit (see §8).

## 6. Test impact

### Stay (must not be modified except for incidental imports)

| Test | Why |
|---|---|
| `backend/tests/Feature/ComisionCierreTest.php` (7 tests, incl. `test_previsualizar_incluye_comision_bs_equivalent` at `:234`) | Close snapshot + `GET /cierre/actual` preview |
| `backend/tests/Feature/ComisionReporteTest.php` (7 tests) | `Comision` column in `ventas-totales` / `cuadre-caja` |
| `backend/tests/Feature/CierreCajaTest.php` (`comision_bs_equivalent` assertions `:1089-1133`) | Close payload contract |
| `backend/tests/Unit/ComisionServiceTest.php:109-560` | Cascade, own/liquidable rates, caps, per-currency buckets, calculation, `comisionesReporte` |
| `backend/tests/Unit/ComisionServiceTest.php:746-829` | `topePorTipo` + single/bulk sync |
| `backend/tests/Feature/ComisionesApiTest.php:152-210`, `:945-964` | `GET/PUT /comisiones/defaults`, 403, 422 validation |
| `backend/tests/Feature/ComisionesApiTest.php:710-944` | Limits-matrix writes (`/limites`) — not ledger; keep (optionally relocate to a limits test file, but relocation is churn and not required) |

### Go (delete with the ledger)

`backend/tests/Feature/ComisionesApiTest.php` ledger methods at lines `212`, `269`, `290`, `320`, `342`, `374`, `395`, `410`, `425`, `439`, `459`, `544`, `565`, `632`, `680`, plus ledger helpers (`crearJerarquia`, `apuesta`, `liquidar`, and any helper used only by them). After removal the file keeps defaults + limits coverage only; consider renaming it (e.g. `ComisionDefaultsApiTest`) or leaving the name to avoid extra churn — decide in design.

`backend/tests/Unit/ComisionServiceTest.php`:
- `:636` `test_previsualizar_detecta_conflictos_por_rango_solapado` — **delete** (concept disappears).
- `:680` `test_previsualizar_filtra_por_banca_ids` — **delete** if Option A, **rewrite** for the new read endpoint if Option B.
- `:571` `test_previsualizar_devuelve_rows_de_banca_grupo_y_taquilla` — **rewrite** against the rows-only method (`filasPorRango` / `consulta`) if Option B; **delete** if Option A.
- `Comision` import at `:7` disappears with the above.

### New tests

- If Option B: a feature test for `GET /comisiones/consulta` covering 200 shape (3 levels, `monto_comision`), date validation (422 when `hasta < desde`), role scoping (`master` limited to `masterBancaIds`, `super_master` global, 403/empty for other roles per the decision in §8), and the "no writes" guarantee (`assertSame(0, Log::where('action','comision_liquidar')->count())` or equivalent).
- Migration test (optional but valuable): after migrations, `Schema::hasTable('comisiones')` is false (Option A/D) and archive table holds the copied rows (Option D).

## 7. Specs and docs impact

### Spec deltas (OpenSpec)

`openspec/changes/retiro-ledger-comisiones/specs/comisiones/spec.md`:

- **REMOVED Requirement: Liquidación en el ledger** (`openspec/specs/comisiones/spec.md:99-125`) with its 4 scenarios (Filas para Banca/Grupo/Taquilla, Transición pendiente→pagado, Congelado al liquidar, Sin doble conteo).
- **MODIFIED Purpose** (`:5`) — remove "liquidada como filas del ledger `comisiones` (estado `pendiente`→`pagado`)"; restate as consultative calculation + close snapshot.
- **MODIFIED Requirement: Independencia entre niveles y tope acumulado** (`:127-199`) — rule 2 at `:136` says "recibe fila en el ledger"; restate as "recibe su monto liquidable en el cálculo/reportes".
- **MODIFIED Requirement: Sincronía single/bulk de liquidación** (`:223-237`) — the sentence "Reportes, preview y liquidación MUST tomar el monto liquidado" loses `liquidación`; keep single/bulk + tope sync.
- **ADDED Requirement (only if Option B)**: "Consulta de comisión por rango" — read-only, MUST NOT persist, MUST expose per-level amounts, MUST respect role scope, date range inclusive.

`openspec/specs/cierre-caja/spec.md:308` and `openspec/specs/reportes-agencia/spec.md:97` reference the `comisiones` **capability**, not the ledger — **no delta required** (verify wording at spec phase). `openspec/specs/limites/spec.md` has no ledger reference.

### Docs

| File | Change |
|---|---|
| `docs/dev/motor-cierres-comisiones.md` §1 (table rows), §2 (ComisionService responsibility), §5.4, §5.5, §6 (`comisiones` structure), §8 (test table), §9 findings 3 and 5 | Rewrite/remove ledger references. **Blocked**: file is untracked in the main worktree and absent from this worktree — the change must either commit the doc as part of this change or apply the edit in the main worktree. |
| `docs/dev/integracion-front-motor-premios.md:210,236,267-278` | Remove "se liquidan/pagan en `/comisiones`", liquidation/payment rows, `manage_comisiones` as a ledger gate |
| `docs/dev/estructura.md:32,47,68` | Remove `Comision.php` model and the "Comisiones" job mention; adjust `ComisionService` description |
| `docs/dev/arquitectura-backend.md:61,66,72` | `ComisionController` now only defaults; remove `Comision` from model list |
| `docs/dev/pendientes-front.md:103` | Optional historical note that the ledger was retired (do not rewrite history of merged PRs) |
| `CHANGELOG.md` | Entry for the removal |
| `docs/cliente/comisiones-escenarios-para-decision.md` §6 ("Las liquidaciones ya pagadas no se tocan") | Untracked business doc; update only if the change ships with it |

## 8. Open questions for the proposal

1. **Historical rows**: drop (A), archive-copy-then-drop (D, recommended), rename (B), or leave read-only (C)? What is the production row count, and are the paid rows needed as payment evidence?
2. **Panel surface**: Option A (remove page + sidebar link) or Option B (read-only consultation page + thin `GET /comisiones/consulta`)? If B, is the endpoint `super_master|master` only (mirroring the old `index`) or open to `banca`/`grupo` with scope (mirroring reports)?
3. **Preview method fate**: rename `previsualizar` → `consultaPorRango` (rows only) and keep it as the single implementation, or delete it and let the new endpoint call `filasPorRango` directly? (Two `previsualizar` methods with different meanings is a readability trap.)
4. **Log actions**: keep historical `comision_liquidar` / `comision_pagar` rows untouched (recommended; `logs.astro` is a generic action filter, no enum) — confirm no cleanup job is expected.
5. **`manage_comisiones`**: confirm it stays in the seeder because `PUT /comisiones/defaults` still requires it.
6. **Close snapshot**: Model A keeps `comision_bs_equivalent`, but after this change it becomes the **only persisted commission figure**, and `CierreService::comisionCadena` is documented as over-counting when a grupo/banca has 2+ taquillas (doc §9 finding 1, no test). Is fixing that explicitly out of scope for this change, and does the proposal need to state it so the snapshot is not mistaken for the per-entity consultative numbers?
7. **Archive schema** (if D): keep `estado`/`periodo`/`fecha_inicio`/`fecha_fin` in the archive table, and which indexes (plain, no FKs)?
8. **Deploy runbook**: does `deploy.sh` / `docs/dev/runbook-ops.md` need a pre-migrate `mysqldump comisiones` step?

## 9. Risks and rollback

| Risk | Severity | Mitigation |
|---|---|---|
| Irreversible loss of production `comisiones` rows on drop | High | Export first; prefer Option D (copy-then-drop); document `mysqldump comisiones` in the runbook |
| FK constraint name collision if the table is renamed and later re-created (`comisiones_*_foreign` are schema-wide in MySQL) | Medium | Use Option D; never rename-and-recreate |
| Tests run on MySQL, so a careless index/FK drop fails the suite (the #67 `down()` failure mode) | Medium | Drop/archive the whole table instead of individual indexes; never edit historical migrations |
| After removal, the close snapshot is the sole persisted commission number and is known to over-count siblings | High (business visibility) | Keep it out of scope but state it in the proposal; fix in a separate change (#65/#68 class); do not present snapshot totals as per-entity amounts |
| Panel `/comisiones` becomes a dead route | Low | Remove the sidebar link in the same slice, or ship Option B |
| Reports are the only consultation surface and require 3 calls (per level) | Low | Accept (Option A) or add the consolidated endpoint (Option B) |
| `Log` rows keep dangling `comision_id` in `details` | Low | Accept as historical audit; note in docs |
| Diff size: deletions dominate (model ~39, controller ~120, service ~230, tests ~700, page 371, docs). Authored additions+deletions will likely exceed the 400-line review budget | Medium | Tasks phase MUST forecast chained/stacked slices (e.g. backend ledger removal → panel+collections → docs+spec delta) and record the guard lines |
| Removing `previsualizar` deletes the only tested "all levels in one call" path | Low | Option B rewrites that test against the new method instead of deleting coverage |

Rollback:
- **Code**: the change is a removal; `git revert` of the merge restores model, relations, controller methods, routes, page, tests and collections. If the spec delta was archived, restoring the removed requirement is manual.
- **Schema (Option D)**: `down()` re-creates an empty `comisiones` table; restoring rows requires the pre-deploy dump. **Schema (Option B)**: `down()` renames back (no data loss) but must first drop the archive table's FK constraints if a re-create is ever attempted.
- **Panel**: restoring `comisiones.astro` from git restores the ledger UI, but the backend routes must be reverted too or the page will 500/404 on load.

## 10. Ready for proposal

**Yes.** The current state is fully mapped, the engine/ledger boundary is explicit, and the only real product decisions are (a) historical rows and (b) whether the panel keeps a read-only consultation page. Both have concrete options with tradeoffs and a recommended default (D + B). The #67 unique-index work is confirmed as reverted and out of scope.
