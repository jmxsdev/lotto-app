# Design: retiro-ledger-comisiones (Model A — comisiones consultivas)

## Technical Approach

Delete the settlement ledger and replace its only read surface with a read-only consultation endpoint. The calculation engine is untouched: `filasLiquidables` becomes the public rows-only `filasPorRango`, which the new `GET /api/v1/comisiones/consulta` exposes and `panel/src/pages/comisiones.astro` consumes. Historical rows are copied to `comisiones_archivo_2026` and the live table is dropped. Every consumer of the engine (`CierreService`, `ApuestaService`, reports, defaults) keeps its current behavior. `CierreService::previsualizar` (close preview) is unrelated and stays.

## Architecture Decisions

| # | Decision | Options (tradeoff) | Choice | Rationale |
|---|---|---|---|---|
| 1 | Consultation surface | New GET reusing the row builder / reports-only (3 calls) / client merge of reports | New `GET /comisiones/consulta` backed by one rows-only service method | Matches "what each entity receives for a range" in one call; reuses the already-tested row computation; no display-shaped report payloads in the panel. |
| 2 | `monto_comision` wire type | Number (natural, but PHP `json_encode(10.0)` emits `10`) / string with 2 decimals | **String** `"10.00"` | The spec requires 2 decimals on the wire; only a string guarantees trailing zeros. Mirrors the removed `decimal:2` cast the panel already formats via `Number()`. |
| 3 | Response envelope | Bare array / `{data}` / `{data, meta}` | `{ data: [...], meta: { desde, hasta, total } }` | `meta` lets the client label the applied (normalized) range and debug empty results; no pagination meta because paging is client-side. Additive and cheap. |
| 4 | Service surface | Keep `previsualizar` wrapper + new `consultaPorRango` / single public method | **Single** public `filasPorRango(desde, hasta, ?bancaIds)` returning rows only | With conflicts gone the wrapper is noise; two methods with the same meaning recreate the `previsualizar` readability trap. Dates are normalized (`startOfDay`/`endOfDay`) inside the method. |
| 5 | Controller layout | Split `ComisionConsultaController` + `ComisionDefaultsController` / keep one | Keep `ComisionController`; add `consulta` + private `bancasEnAlcance` | Minimal churn: two public methods survive, one is added. A split adds files and route churn without behavioral value. |
| 6 | `master` sending out-of-scope `banca_ids` | 403 / silently intersect | **403** | Mirrors the removed `liquidar` contract and fails loudly instead of hiding a scope mistake. Absent `banca_ids` ⇒ `masterBancaIds()`; empty ⇒ empty result. |
| 7 | Historical rows | Drop / rename / copy→archive→drop / leave read-only | **Copy → `comisiones_archivo_2026` → drop** | Preserves the paid-row audit trail and frees schema-wide FK names (`comisiones_*_foreign`) for any future re-create; rename would keep them attached and block a re-create. |
| 8 | `down()` | Irreversible marker / re-create empty table | **Re-create empty `comisiones`**, keep the archive | Restores the schema shape for a `git revert` of code without FK-name collisions (the old table is gone). Data restoration is explicitly the pre-deploy dump. |
| 9 | Panel pagination | Client-side over the full range / server pagination | **Client-side**, 15 rows/page | One row per entity with sales across three levels is a small payload (hierarchy-bounded). Server pagination would add contract surface for no current need; revisit if the hierarchy reaches thousands of entities. |
| 10 | Test file name | Rename `ComisionesApiTest` / keep | **Keep** | The name is generic; the file keeps defaults + limits coverage. Renaming is a delete+add diff with zero behavioral value in an already large slice. |
| 11 | Ledger docs | Leave out (dependency in main worktree) / copy + update | **Copy `docs/dev/motor-cierres-comisiones.md` and update it** | Makes the change self-contained and removes the untracked-file dependency. Tradeoff: ~280 lines enter the diff as a new file; isolate it in the docs slice and label it "previously untracked". |
| 12 | Slice order | Backend removal first (proposal) / endpoint + panel first | **Endpoint + panel first**, then removal, then docs | Each merged slice stays deployable: the panel never calls a removed route. The proposal's order breaks `/comisiones` between slices 1 and 2. |

## Data Flow

### Consultation (read-only)

```
panel/src/pages/comisiones.astro
  │ GET /api/v1/comisiones/consulta?desde=…&hasta=…[&banca_ids[]=…]
  ▼
ComisionController::consulta
  ├─ Validator (desde/hasta required|date, hasta >= desde) → 422 {errors}
  ├─ route middleware role:super_master|master → 403 otherwise
  └─ bancasEnAlcance(): null (super_master) | masterBancaIds() (master)
        │ explicit banca_ids outside scope → 403
        ▼
ComisionService::filasPorRango(desde, hasta, ?bancaIds)   ← no writes
  ├─ Banca / Grupo / Taquilla in scope
  ├─ bucketsPorEntidad()      ← apuestas (estado != anulada, fecha_hora inclusive)
  ├─ tasasLiquidablesBulk()   ← juego_limites + comision_defaults (topes)
  └─ aplicarTasas()           → one round(…, 2) per entity; base ≤ 0 skipped
        ▼
{ data: [{nivel, entidad_id, entidad, monto_comision}], meta }
   (no `comisiones` row, no `logs` row, no `estado`)
```

### Archive + drop migration

```
php artisan migrate --force
  ├─ Schema::hasTable('comisiones')?  no → return (already archived)
  ├─ Schema::hasTable('comisiones_archivo_2026')? no → create (no FKs, no unique)
  ├─ N = count(comisiones)
  ├─ N > 0 && archive empty → INSERT INTO archive (explicit cols) SELECT … FROM comisiones
  ├─ count(archive) < N → throw RuntimeException (comisiones left intact, no drop)
  └─ DROP TABLE comisiones
```

MySQL DDL is non-transactional (implicit commits), so the order is copy → verify → drop; any failure before the drop leaves the source table untouched.

## API Contract

`GET /api/v1/comisiones/consulta` — Sanctum bearer, `role:super_master|master`.

| Param | Type | Rules |
|---|---|---|
| `desde` | date | required, `date` |
| `hasta` | date | required, `date`, `after_or_equal:desde` |
| `banca_ids[]` | int[] | optional; `super_master` only; `master` out-of-scope ⇒ 403 |

```json
{
  "data": [
    { "nivel": "banca",    "entidad_id": 1, "entidad": "Banca Centro",    "monto_comision": "10.00" },
    { "nivel": "grupo",    "entidad_id": 4, "entidad": "Grupo Norte",     "monto_comision": "20.00" },
    { "nivel": "taquilla", "entidad_id": 9, "entidad": "Taquilla Central", "monto_comision": "70.00" }
  ],
  "meta": { "desde": "2026-09-01", "hasta": "2026-09-30", "total": 3 }
}
```

| Status | When |
|---|---|
| 200 | Rows for entities with sales > 0; `data: []` for an empty range |
| 401 | No/invalid token (existing middleware) |
| 403 | Role not `super_master`/`master`; `master` with out-of-scope `banca_ids` |
| 422 | Missing/invalid dates; `hasta < desde` → `{ "errors": { … } }` |

Guarantees: inclusive range; no persistence; no `estado`/`pendiente`/`pagado` key; no conflict lists; entities with base 0 omitted. `monto_comision` is bs-equivalent, 2 decimals, string.

## Migration

`backend/database/migrations/2026_10_30_000001_archive_and_drop_comisiones_table.php`

```php
// up() — archive table (plain columns, no FKs, no unique indexes)
Schema::create('comisiones_archivo_2026', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('banca_id')->nullable()->index();
    $table->unsignedBigInteger('grupo_id')->nullable()->index();
    $table->unsignedBigInteger('taquilla_id')->nullable()->index();
    $table->string('periodo');
    $table->decimal('monto_comision', 12, 2);
    $table->string('estado', 20);                 // enum → string, no CHECK
    $table->date('fecha_inicio')->nullable();
    $table->date('fecha_fin')->nullable();
    $table->timestamps();
    $table->timestamp('archivado_at')->useCurrent();
    $table->index(['fecha_inicio', 'fecha_fin'], 'comisiones_archivo_2026_rango_index');
});

$columnas = ['id','banca_id','grupo_id','taquilla_id','periodo','monto_comision',
             'estado','fecha_inicio','fecha_fin','created_at','updated_at'];
DB::table('comisiones_archivo_2026')->insertUsing($columnas, DB::table('comisiones')->select($columnas));
Schema::dropIfExists('comisiones');
```

Guards: early return when `comisiones` is absent; create the archive only when missing; copy only when `count(comisiones) > 0 && count(archive) === 0` (a non-empty archive can only come from a prior run); throw before the drop when `count(archive) < count(comisiones)`. `id` is preserved to keep audit references stable. `archivado_at` fills from the column default.

`down()` re-creates an **empty** `comisiones` with the original shape (guarded by `hasTable`): `id`; `banca_id`/`grupo_id`/`taquilla_id` nullable `constrained()->onDelete('cascade')`; `periodo` string; `monto_comision` decimal(12,2); `estado` enum(`pendiente`,`pagado`) default `pendiente`; `fecha_inicio`/`fecha_fin` nullable; `timestamps()`; index on `(fecha_inicio, fecha_fin)`. It never touches the archive and never restores rows — that is the dump. Re-creating with the same FK names is safe because the old table (and its constraints) no longer exists.

Tests run on MySQL (`lotto_test`) with `RefreshDatabase`: the migration replays after the two historical `comisiones` migrations, so the copy sees `fecha_inicio`/`fecha_fin`. On a fresh DB it copies 0 rows and drops. Historical migrations are never edited.

Deploy runbook (production, before `php artisan migrate --force`):

```bash
docker exec lotto_mysql_prod sh -lc 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" lotto_db comisiones' > comisiones_$(date +%F).sql
docker exec lotto_mysql_prod mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -e 'SELECT COUNT(*) FROM lotto_db.comisiones'   # pre
# migrate, then:
docker exec lotto_mysql_prod mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -e 'SELECT COUNT(*) FROM lotto_db.comisiones_archivo_2026'  # must equal pre
docker exec lotto_mysql_prod mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SHOW TABLES FROM lotto_db LIKE 'comisiones'"  # must be empty
```

## File Changes

| File | Action | Description |
|---|---|---|
| `backend/app/Models/Comision.php` | Delete | Ledger model |
| `backend/app/Models/Banca.php`, `Grupo.php`, `Taquilla.php` | Modify | Remove `comisiones()` hasMany + unused import |
| `backend/app/Http/Controllers/Api/ComisionController.php` | Modify | Keep `defaults`/`updateDefaults`; add `consulta` + `bancasEnAlcance`; remove `index`/`liquidar`/`pagar`/`comisionEnAlcanceMaster` and imports `Comision`, `Grupo`, `Log`, `Taquilla` (keep `User`, `Carbon`) |
| `backend/app/Services/ComisionService.php` | Modify | Remove `previsualizar`, `liquidar`, `conflictosPorRango`; `filasLiquidables` → public rows-only `filasPorRango`; drop unused imports (`Comision`, `Log`, `DB`) |
| `backend/routes/api.php` | Modify | Replace the ledger block with `GET /comisiones/consulta` under `role:super_master|master`; defaults routes untouched |
| `backend/database/migrations/2026_10_30_000001_archive_and_drop_comisiones_table.php` | Create | Archive + drop |
| `backend/tests/Feature/ComisionesApiTest.php` | Modify | Delete the 15 ledger tests (lines ~212–709) + helpers `crearJerarquia`, `apuesta`, `liquidar`, `Comision` import; add consulta tests + archive assertion |
| `backend/tests/Unit/ComisionServiceTest.php` | Modify | Delete `test_previsualizar_detecta_conflictos_por_rango_solapado`; rewrite the two `previsualizar` tests against `filasPorRango`; drop `Comision` import |
| `panel/src/pages/comisiones.astro` | Modify | Read-only: remove liquidar card, estado filter/badges, Pagar, `fetchBody`/`liquidar`/`pagar`/`renderConflictos`, `icono` import; add "Consultar" → `/comisiones/consulta`; keep range/level/entity filters, table and client-side pagination |
| `panel/src/layouts/AdminLayout.astro` | Modify | Comment only; label "Comisiones" unchanged |
| `collections/Comisiones/Liquidar Comisiones.yml`, `Pagar Comision.yml`, `Listar Comisiones.yml` | Delete | Ledger requests |
| `collections/Comisiones/Consultar Comisiones.yml` | Create | `GET /comisiones/consulta` with `desde`/`hasta`/`banca_ids` docs |
| `collections/Comisiones/Ver Defaults.yml`, `Actualizar Defaults.yml`, `folder.yml` | Keep | Defaults requests + folder |
| `docs/dev/motor-cierres-comisiones.md` | Create (copy from main) + modify | §1 one subsystem + archive, §2 service/controller, §5.4, §5.5 → consulta, §6 `comisiones_archivo_2026`, §8 test table, §9 findings 3 and 5 |
| `docs/dev/integracion-front-motor-premios.md`, `estructura.md`, `arquitectura-backend.md`, `runbook-ops.md`, `CHANGELOG.md` | Modify | Remove ledger/payment references; document the dump step; changelog entry |

## Testing Strategy

| Layer | What to Test | Approach |
|---|---|---|
| Unit | `filasPorRango`: 3 levels (10/20/70), empty range, `banca_ids` filter, base 0 skipped, 2-decimal rounding | Rewrite the two `previsualizar` tests; keep existing chain fixtures |
| Feature | `GET /comisiones/consulta`: 200 shape with string amounts, empty range, `super_master` global, `master` scoped, `master` with no bancas ⇒ `[]`, out-of-scope `banca_ids` ⇒ 403, roles `banca/grupo/taquilla/agencia` ⇒ 403, 422 missing dates and `hasta < desde`, no `estado` key, no `logs` action `comision_liquidar` | `ComisionesApiTest` with `RefreshDatabase` + seeder |
| Feature | Archive end-state: `Schema::hasTable('comisiones')` false, `comisiones_archivo_2026` exists and is empty | One method in `ComisionesApiTest` |
| Regression | `ComisionCierreTest`, `ComisionReporteTest`, `CierreCajaTest` commission assertions, defaults 200/403/422/16–17 limits, `/limites` matrix | Unmodified; run `composer test` |

## Threat Matrix

N/A — no routing/shell/subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. The API route change is HTTP surface covered by the feature tests above.

## Risks and Rollback

| Risk | Severity | Mitigation |
|---|---|---|
| Irreversible loss of production rows on drop | High | `mysqldump comisiones` + count check in the runbook; copy→verify→drop ordering; migration throws before dropping on a short copy |
| Archive copy mismatch or duplicate ids on retry | Medium | Copy only when the archive is empty; count assertion; ids preserved |
| `master` scope regression | Medium | Explicit in/out-of-scope feature tests; empty `masterBancaIds()` ⇒ empty list, never global |
| Panel breaks between slices | Medium | Endpoint + panel ship in slice 1, before route removal |
| Close snapshot mistaken for per-entity amounts | High (business) | Out of scope (#65 class); state in docs that `comision_bs_equivalent` is a single snapshot and consulta is the per-entity read |
| Dangling `logs.details.comision_id` | Low | Accept as historical audit; note in docs |
| Review budget exceeded | Medium | Slice 2 is deletion-heavy; split into 2a (backend code) + 2b (migration/tests/collections) if the 400-line guard counts deletions |

Rollback: `git revert` of the whole merge restores model, relations, controller, routes, page, tests and collections (revert them together or the page 404s). Schema: `down()` re-creates an empty `comisiones`; rows come from the dump. The archive table is never dropped by `down()`.

## Chained PR Slices

| Slice | Scope | Est. changed lines |
|---|---|---|
| 1 — Consulta read-only + panel | `filasPorRango`, `consulta` endpoint, routes (additive), panel rewrite, consulta unit/feature tests, `Consultar Comisiones.yml` | +330 / −150 |
| 2 — Retiro del ledger + migración | Model, relations, controller methods, routes, service ledger methods, migration, ledger tests, ledger collections | +75 / −780 (split 2a code / 2b migration+tests if needed) |
| 3 — Docs + spec + changelog | Doc copy + updates, `integracion-front`, `estructura`, `arquitectura-backend`, `runbook-ops`, `CHANGELOG`; spec archive via OpenSpec | +330 / −60 |

Each slice is independently deployable; `sdd-tasks` owns the final forecast and guard lines.

## Open Questions

- [ ] Production `comisiones` row count is unknown — the runbook dump/count gate must run before `migrate --force`. Not design-blocking.
