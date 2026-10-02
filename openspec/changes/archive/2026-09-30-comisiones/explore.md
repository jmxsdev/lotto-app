# Explore — Comisiones (commissions system)

**Change**: `comisiones` — decide what "comisión" means in this domain and wire the dormant scaffolding (fields, ledger table, permission, config slot) into a real feature, or clean it up.

**Bottom line up front**: All five user-reported claims are **confirmed against code at HEAD (c375786)**. There is no operational commissions system anywhere; the product decision (meaning, edit surface, retroactivity, visibility) is genuinely open and must be answered by the user before proposal/spec work. The data model already provides slots for every plausible design, which means the safest next step is a small, additive implementation that reuses `juego_limites` + the `comisiones` ledger instead of introducing new schema.

---

## 1. Verified Current State

Every claim below was verified by exhaustive `rg` over `backend/`, `panel/`, `taquilla/` (519 files) plus CodeGraph symbol queries. Docs `docs/PENDIENTE.md` §E and `docs/integracion-front-motor-premios.md` §5 (the only valid doc references) are consistent with the code — no drift found.

| # | Claim | Verdict | Evidence (file:line) |
|---|-------|---------|----------------------|
| 1a | `juego_limites.porcentaje_pago` persisted | ✅ | migration `2026_08_11_000002_create_juego_limites_table.php:33` (`decimal(5,2) nullable`); model `JuegoLimite.php:21,30` (fillable + `decimal:2` cast); upsert `JuegoController.php:413-425`; present-fields-only writer `JuegoLimiteService.php:89-143`; scope/batch writers `JuegoController.php:436-529`; create-mode writer used by Banca/Grupo/Taquilla stores via `persistirParaEntidad` (`JuegoLimiteService.php:62-79`) |
| 1b | `juego_limites.participacion` persisted | ✅ | migration `:34` (`decimal(10,2) nullable`); model `JuegoLimite.php:22,31`; same writers as 1a |
| 1c | Both validated | ✅ | `JuegoLimiteService.php:43-44` (`numeric, min:0, max:100`); `JuegoController.php:391-392` (PUT) and `:456-457` (batch) |
| 1d | Both shown in panel limits matrix | ✅ | `panel/src/utils/limites.ts:51-52` (`% Pago`, `Particip.` columns in shared matrix component); rendered in `panel/src/pages/limites.astro` (scope mode) and entity tabs `panel/src/pages/bancas/detalle.astro` (Límites tab, create-mode save); serialized as floats `JuegoController.php:1018-1019` and `valoresPresentes` `:1032` |
| 1e | **ZERO business readers** | ✅ | Only non-matrix `participacion` match is `ApuestaService.php:565,573` — a **computed report column** (`venta/totalVenta*100`, "Participación" of an entity's sales), unrelated to `juego_limites.participacion`. No service, engine, taquilla code, or report reads `porcentaje_pago`/`participacion` for business logic. `ApuestaService::getEffectiveLimit` (`:154-183`) reads only `limite_minimo`/`limite_maximo`. Seeder `ElGuacharitoSeeder.php:50` writes only `limite_minimo`. `fraccion`/`limite_tiempo` are equally dead (same pattern) — excluded from our scope |
| 2 | `comisiones` table is a dead ledger | ✅ | migration `2026_07_10_035547_create_comisiones_table.php` (all columns; `periodo` string e.g. `"2026-07"`, `monto_comision decimal(12,2)`, `estado enum ['pendiente','pagado']` default `pendiente`); model `Comision.php` (fillable + cast); relations only: `Banca.php:52-55`, `Grupo.php:50-53`, `Taquilla.php:61-64`. **No controller, no route (api.php has zero `comisiones` routes), no service, no panel reference, no seeder/factory/test usage** — the 15 total `comision*` matches are all model/migration/seeder |
| 3 | `bancas.config` "default commissions" never read | ✅ | Written: `BancaController.php:73` (`'config' => $request->config`); migration comment `2026_07_10_035305_create_bancas_table.php:15` ("Comisiones por defecto, etc."); model cast `Banca.php:19` (`array`). **Never read**: no `$banca->config` access anywhere; all `->config['...']` reads are on `Juego` (PremiosEngine, JuegoCatalogoService, scrapers) or `$request->config` writes. Panel has no `config` UI field for bancas |
| 4 | Permission `manage_comisiones` orphaned | ✅ | Created + assigned: `RolesAndPermissionsSeeder.php:43` (definition), `:60` (super_master via `Permission::all()`), `:72` (master). **No `permission:manage_comisiones` middleware, no `->can(...)` check, no policy** — contrast with `manage_grupos`/`manage_taquillas`/`manage_exchange_rates`, which ARE wired as middleware (`GrupoController.php:21-22`, `TaquillaController.php:22-23`, `api.php:242`) |
| 5a | Cash closure = ventas − egresos | ✅ | `CierreService::calcularTotales` `:226-279`: ventas = `SUM(amount_bs/amount_usd/total_bs_equivalent)` of non-`anulada` apuestas by `fecha_hora`; egresos = `Pago` tipo `egreso`/`devolucion` by `created_at`; `total_efectivo_X = ventas − egresos` `:275-276`. No commission term anywhere |
| 5b | Reports compute Utilidad = venta − premio, no distribution | ✅ | `ApuestaService::ventasTotales` `:560-576`: `Porcentaje = premio/venta*100` `:563`, `Utilidad = venta − premio` `:564`; surfaced in `panel/src/pages/reportes/ventas.astro:105,112`. Also `cuadreCaja` `:753` (`Efectivo = Venta − Pagados − Devoluciones − Vencidos`) and `timeSeriesData` `:954` (`Saldo = Ventas − (Premios + Pagados + Devolución)`). No commission/distribution term anywhere in reports |

**Exhaustive token search results** (worktree-wide): `porcentaje_pago` → 15 matches / 6 files (all listed above); `participacion` → 15 matches / 6 files (one is the unrelated report column); `comisiones|monto_comision|manage_comisiones` → 15 matches / 7 files (all dead scaffolding); `bancas.config` commission reads → 0.

### Dead code candidates
| Candidate | Location | Status |
|---|---|---|
| `Comision` model + `comisiones` table + 3 relations | `backend/app/Models/Comision.php`, `migration 2026_07_10_035547`, `Banca/Grupo/Taquilla::comisiones()` | Dead ledger — **preserve** (product decision H3 maps to it; migration exists, harmless) |
| `manage_comisiones` permission | `RolesAndPermissionsSeeder.php:43,72` | Orphaned — either gate new endpoints with it (reuse) or leave (harmless) |
| `bancas.config` | `BancaController.php:73`, `migration :15` | Write-only slot; **do not repurpose without decision** — it is per-banca free-form JSON with zero schema; prefer structured columns |
| `panel/src/components/LimitesTable.astro` | panel | Dead (0 references) — known gap §D of PENDIENTE; owned by the parallel limits agent |
| `juego_limites.porcentaje_pago/participacion/fraccion/limite_tiempo` | model/controller/panel | Persisted-but-unread (all four); `fraccion`/`limite_tiempo` NOT ours |
| `JuegoController.php:178,224-226` `agencia_id` filter on `juego_limites` | controller | **Latent bug**: validated against `agencias` and applied as `where('agencia_id', ...)` but `juego_limites` has NO `agencia_id` column → runtime SQL error if a client sends it. Known gap §D; the matrix UI workaround (agencia role matches by banca/grupo/taquilla rows `:194-203`) avoids it |

### Hidden consumers / writers not to miss
- **Seeders** write `JuegoLimite` rows (banca level, `limite_minimo` only): `CazalotonSeeder.php:43`, `ElGuacharitoSeeder.php:42`, `ElArrejuntadoSeeder.php:41` — none touch commission fields.
- **Tests** write/assert `porcentaje_pago`: `CrearEntidadConLimitesTest.php:77,95` (asserts `80.0`); limits API tests (`LimitesApiTest.php`, `LimitesScopedApiTest.php`) exercise the matrix endpoints generically.
- **No factories** for `Comision`; no `Comision` in any test.

---

## 2. Domain Map

### Entity hierarchy
```
super_master (role, no entity)
   └── master (role, owns BANCAS via bancas.master_id)          [User.php:69-72; migration 2026_08_28_000004]
         └── Banca  (bancas.id)                                  [migration 2026_07_10_035305; master_id nullable]
               └── Grupo (grupos.banca_id)                       [migration 2026_07_10_035327]
                     └── Agencia (agencias.grupo_id)             [migration 2026_08_28_000001 — "passthrough", no limits/monedas/vigencia config]
                           └── Taquilla (taquillas.grupo_id, taquillas.agencia_id nullable)  [migration 2026_08_28_000002]
```
- **Users** carry `role` + one of `banca_id`/`grupo_id`/`taquilla_id`/`agencia_id` (`User.php:21-32`); master scope closures `masterBancaIds`/`masterBancaScope`/`masterBancaChainScope`/`masterBancaGroupScope` (`User.php:114-170`) implement "master sees only its bancas' subtree, empty ⇒ `1=0`".
- **Agencia is explicitly a passthrough level** (migration comment): it configures nothing; taquillas hang off it for reporting/scope only (`ApuestaController.php:35-38`, `ReporteController.php:32-35`).

### How `juego_limites` addresses an entity
- Columns: `juego_id, banca_id (NOT NULL), grupo_id (nullable), taquilla_id (nullable), moneda enum bs|usd` — unique index `(juego_id, moneda, banca_id, COALESCE(grupo_id), COALESCE(taquilla_id))` (`migration 2026_08_11_000002:42-45`).
- **Agencia is NOT addressable** (no `agencia_id` column) — consistent with passthrough.
- Effective-limit resolution: `ApuestaService::getEffectiveLimit` (`:154-183`) — single query, order `taquilla > grupo > banca`, same `juego_id` + `moneda`. Same cascade is used by the matrix "origen" resolution (`JuegoController.php:324-361`).
- Restrictivity guard (`JuegoLimiteService::validarRestrictividadLimite` `:149-203`) enforces child ≤ parent for **`limite_minimo`/`limite_maximo` only** — `porcentaje_pago`/`participacion` have no hierarchy constraint today (a child could set a higher % than the parent; harmless while unread, relevant if H1/H2 activates them).
- Matrix edit surfaces: `GET /limites` (entity mode, `JuegoController.php:253-370`), `GET /limites?scope=` (all-entities mode `:881-938`), `PUT /limites/{juego}` (`:376-430`), `POST /limites/batch` (`:436-529`), `DELETE /limites/{limite}`. Roles: GET `super_master|master|banca|grupo|agencia`, write `super_master|master|banca` (`api.php:198-215`). Nav `/limites` shows only for super_master (`AdminLayout.astro:256`); entity detail pages embed the matrix in a "Límites" tab.

### Currency model
| Aspect | Where | Detail |
|---|---|---|
| Currency per limits row | `juego_limites.moneda` | `enum bs\|usd`, one row per currency |
| Currency of a payment | `pagos.moneda` | `enum bs\|usd\|mixto` (migration `2026_07_25_231500`) |
| Currency of a bet | `apuestas` | **no moneda column** — dual `amount_bs`/`amount_usd` (`decimal(12,2)`), `exchange_rate_applied decimal(10,4)` snapshot, `total_bs_equivalent decimal(12,2)` (migrations `2026_07_10_035505:15-18`, `2026_07_24_030916`) |
| Mixto detection | `ApuestaService::createApuesta:468` | `$moneda = amount_bs>0 && amount_usd>0 ? 'mixto' : (usd>0 ? 'usd' : 'bs')` — stored on the Pago only |
| Allowed currencies per entity | `banca/grupo.monedas_permitidas` | json array; `getEffectiveMonedas` `:118-147` intersects banca∩grupo, NULL = both |
| Amounts storage/casts | Eloquent `decimal:2` casts on `JuegoLimite`/`Comision`/amounts; serialized `(float)` in `serializarLimite` `:1012-1023`; `round(x,2)` everywhere in CierreService/ApuestaService | No shared money helper — rounding is inline `round(...,2)`; conversions `bsToUsd`/`usdToBs`/`calcularTotal` (`ApuestaService:28-109`) use the **active** rate at call time, while per-bet snapshots use `exchange_rate_applied` |

---

## 3. Money Flow

```
SALE            ApuestaController::store / TicketController::store
                └─ ApuestaService::createApuesta (361-484)
                   • validate monedas (getEffectiveMonedas) + limits (getEffectiveLimit → limite_min/max only)
                   • total_bs_equivalent = amount_bs + amount_usd × ACTIVE rate (371)
                   • persists Apuesta (amount_bs/usd, exchange_rate_applied, total_bs_equivalent,
                     estado=pendiente, fecha_hora, sorteo_hora) + DetalleApuesta (premio_posible from
                     PremiosEngine) + Pago tipo=ingreso (moneda, metodo_pago)
                   • delete within 5-min window (ApuestaController::destroy 257-284) — soft delete

PRIZE           PagoController::store  (READ-ONLY for us — owned by parallel cycle `configuracion-juegos`)
                └─ egreso: amounts optional; motor-calculated premio authoritative; ±0.01 validation;
                   updates detalle_apuestas.premio_ganado(+usd)  (PagoController.php:84-158)
WINNER          ApuestaService::verificarGanadores (972-1069) — premio_ganado on detalles,
                ticket premio_total accumulation, estados ganadora/perdida/pagada

CASH CLOSURE   CierreController (store/index/actual/semanal/show) → CierreService
               └─ period [fecha_inicio, fecha_fin) per taquilla (478-497): last cierre's fecha_fin
                  → first apuesta → now; ONE cierre per calendar day with re-close + clave (47-88)
               └─ ventas − egresos per currency (226-279); arqueo/diferencia; desglose by metodo_pago
               └─ NO commission term

REPORTING      ReporteController → ApuestaService::ventasTotales/cuadreCaja/relacionTickets/vencidos
               + EstadisticaController::rendimiento (timeSeriesData)
               └─ Utilidad = venta − premio (564); Cuadre: Efectivo = Venta − Pagados − Devoluciones − Vencidos (753)
               └─ Panel: reportes/ventas.astro (Utilidad column :105,112), tickets, vencidos, cuadre.astro,
                  rendimiento.astro, dashboard.astro
```

### Safest points to compute/persist commissions
1. **Rate/amount basis**: per-bet snapshots already exist (`apuestas.exchange_rate_applied`, `total_bs_equivalent`, `detalle_apuestas.premio_*`) — any % over sales can be computed per bet or aggregated per period with **stable rates**.
2. **Config reader**: `getEffectiveLimit` cascade is the natural place to add a reader for `porcentaje_pago`/`participacion` (or a sibling `getEffectiveComision`) — it already resolves (taquilla, juego, moneda) → effective row.
3. **Ledger write**: `comisiones` (periodo "2026-07", monto_comision, estado) is designed for **periodic settlement per entity** — populate via (a) a scheduled job (scheduler exists: `routes/console.php` with `Schedule::job(...)->dailyAt(...)`), or (b) a new endpoint invoked at month close, or (c) lazily at cierre per taquilla (but banca/grupo rollup needs a different aggregation than the per-taquilla daily cierre).
4. **Do NOT** compute commissions inside `ApuestaService::createApuesta` unless per-sale snapshot columns are added (migration) — retroactivity would otherwise break when limits change.

---

## 4. Integration Points

### Where configuration could be edited
| Option | Surface | Existing machinery | Constraint |
|---|---|---|---|
| A. Limits matrix (default per game × moneda at banca level + override per entity) | `juego_limites.porcentaje_pago/participacion` already render/edit in `limites.ts` CAMPOS + `limites.astro` + entity tabs | Complete: validation, upsert, batch, scope, hierarchy, serialization | **Matrix UI owned by the parallel limits §D agent** — any change to that surface needs coordination; the fields themselves are ours and untouched by that agent |
| B. New panel page (per entity/level only, e.g. `/comisiones`) | New `.astro` page following `limites.astro` pattern (`apiFetch` from `utils/api.ts`, `AdminLayout`, `addLink` in `AdminLayout.astro` sidebar) | Needs new backend endpoints + permission | No parallel collision; more work |
| C. `bancas.config` JSON | Write-only slot today | **Reject**: unstructured, never read, no validation, per-banca only | — |

### Permission / navigation patterns to follow
- Gate new write endpoints with the **existing** `permission:manage_comisiones` middleware, exactly like `manage_exchange_rates` (`api.php:242-247`) — the permission is already assigned to super_master + master; no seeder change needed.
- Role-scoping for read endpoints: follow `role:super_master|master|banca|grupo|agencia` middleware groups + the controller-level hierarchy closures (`masterBancaChainScope`, `banca_id` filters) used by ReporteController/ApuestaController.
- Sidebar: `addLink(label, href, icon)` in `AdminLayout.astro` (~line 230-320) with role guards; reports live in a dropdown (`reportes` array ~line 259).

### Where ledger rows would be created
- New `ComisionService` + scheduled command/job (pattern: `routes/console.php` `Schedule::job(...)->dailyAt()`; commands in `backend/app/Console/Commands/`), or a controller endpoint behind `permission:manage_comisiones` (pattern: `CierreController::store` POST /cierre).
- `comisiones` row shape fits a monthly settlement: `periodo='YYYY-MM'`, one of banca_id/grupo_id/taquilla_id set, `monto_comision` (12,2), `estado` pendiente→pagado.

### Where results would surface in the panel
- **Reportes**: extend `ReporteController` (`/reportes/ventas-totales`, `/reportes/cuadre-caja`) with a commission column/endpoint; panel pages `reportes/ventas.astro` (column already table-driven `:105-112`), `cuadre.astro`.
- **Cierre**: `CierreService::calcularTotales` + `reporteSemanal`/`previsualizar` shapes are fixed (AD-3/AD-5) — adding commission fields is an additive change to the response shape and panel `clave-cierre.astro`/cierre views.
- **Taquilla**: explicitly OUT of scope.

---

## 5. Product Decision Brief (for the orchestrator → user)

### Decision 1 — Meaning of "comisión"
| Option | Definition | Maps to | Constraints |
|---|---|---|---|
| **H1** | % of the bet paid back to the **player** (payout rate) | `juego_limites.porcentaje_pago` | Conflicts conceptually with `PremiosEngine` (motor owns prize money, `config.premios`); `porcentaje_pago` would be a UI hint or validation cross-check, NOT the prize authority. Lowest risk of duplication: prize amounts are already motor-computed at sale and payment time |
| **H2** | % **revenue share** of a level (banca/grupo) over that level's sales | `juego_limites.participacion` | Fits the existing matrix (per juego × moneda × entity, inherited); needs a business reader + report column; restrictivity guard currently only covers min/max (child could exceed parent) |
| **H3** | **Periodic settlement** per entity (the `comisiones` ledger: banca/grupo/taquilla × periodo × monto_comision × estado) | `comisiones` table (already migrated, dead) | Matches the schema; `periodo` is a string `"2026-07"`; amounts decimal(12,2) single-currency → **no currency column** (a BS-only or fixed-rate model implied; needs decision if USD/mixto settlements are required) |

Note: `bancas.config` fits none of them cleanly (unstructured, never read) — recommend leaving it.

### Decision 2 — Where edited
| Option | Surface | Pros | Cons |
|---|---|---|---|
| **Defaults per game + override per entity** | `juego_limites` (banca level = defaults; grupo/taquilla rows = overrides), edited in the existing matrix | All machinery exists (validation, upsert, batch, scope, inheritance display); per-currency; hierarchy-guarded | Matrix UI owned by parallel limits agent → coordination needed if we rename/re-label columns or change the save flow |
| **Per entity/level only** | New panel page + new endpoints | No collision; own UX | Duplicates the matrix surface; more code; loses per-game defaults unless `banca` rows are used as defaults anyway |

Strong recommendation: **defaults = banca-level rows (per juego × moneda), overrides = grupo/taquilla rows**, reusing `getEffectiveLimit` resolution — this is exactly how the matrix and `limite_minimo` already work, so the mental model is consistent.

### Decision 3 — Formula, rounding, currencies, retroactivity
- **Formula** depends on D1: H2 ⇒ `comision = Σ venta_entidad(periodo) × % efectivo`; H3 ⇒ same plus ledger row.
- **Rounding/currency**: amounts are `decimal(12,2)`, `round(x,2)` inline everywhere; per-bet snapshot `exchange_rate_applied` exists ⇒ commissions over **bs-equivalent** are stable per bet; per-currency (bs and usd separately) is also possible since `amount_bs`/`amount_usd` are stored separately. `comisiones.monto_comision` has no currency column — either settle in bs-equivalent or add a column (migration).
- **Retroactivity**: limits are editable and not versioned ⇒ computing % against past sales is **non-retroactive by default** (a limit edit changes history). Options: (a) accept it (compute at query time), (b) snapshot per bet at sale (add `comision_bs`/`comision_usd` or similar to `apuestas`/`detalle_apuestas` — migration, touches sale path which also feeds the parallel `configuracion-juegos` cycle), (c) snapshot per period when the ledger row is created (H3 — `comisiones` row freezes the settled amount; natural fit).

### Decision 4 — Where visible in panel
- Candidates: `reportes/ventas.astro` (add column), `cuadre.astro`, cierre views (`clave-cierre.astro` flow), or a dedicated `/comisiones` page listing ledger rows (H3).
- **Taquilla explicitly OUT** (hard boundary). Roles: report pages are visible to all roles; a settlement/ledger admin page would be `super_master|master` (+banca for its own subtree) per existing patterns.

---

## 6. Risks & Boundaries

### Parallel cycles (do NOT touch — describe only)
- **`configuracion-juegos` (unmerged)**: `updatePremios`, `PremiosConfigService`, `espejosLegacy`, `premios_snapshot`, `PremiosEngine`, `PagoController`, `panel/src/pages/juegos.astro`. Prize money and `juego.config.premios` are theirs; any H1 interpretation that touches payout math collides — the motor is authoritative and our commission fields must NOT alter prize computation.
- **Limits §D gap agent**: owns the limits matrix implementation/UI (and the dead `LimitesTable.astro`, the `agencia_id` filter bug, DELETE-for-inherit gap). We may reuse the matrix surface only with explicit user-coordinated hand-off; we must NOT unilaterally edit `limites.ts`/`limites.astro`/entity tabs. `fraccion`/`limite_tiempo` are NOT our scope.
- **`taquilla/*`**: entirely out of scope — the taquilla front never reads commission fields today (verified: zero matches), so no taquilla impact.

### Risks
1. **Restrictivity gap**: `validarRestrictividadLimite` only guards min/max; if H1/H2 activate `porcentaje_pago`/`participacion`, a child can currently set values exceeding the parent — needs a decision (extend the guard or accept).
2. **`agencia_id` filter bug** (`JuegoController.php:224-226`): querying limits with `agencia_id` crashes (column does not exist). Not ours, but any new limits-adjacent endpoint must not copy this pattern.
3. **`comisiones` has no currency column** and `banca_id`/`grupo_id`/`taquilla_id` are all nullable with no check constraint — mixed/absent entity rows possible.
4. **Retroactivity semantics** unresolved ⇒ spec MUST pin down whether limit edits affect past periods.
5. **`bancas.config`**: do not repurpose; if H2/H3 needs per-banca defaults, prefer new structured columns.
6. **Migration risk**: minimal — no schema changes required for any option except optional per-bet snapshot or a currency column on `comisiones`.
7. **Review-budget awareness**: this change will span backend (service + controller + routes) and panel (page/columns); forecast for chained PRs if >400 lines.

---

## 7. Open Questions (for the orchestrator to ask the user)
1. **Meaning of "comisión"**: H1 (% paid to player) vs H2 (% revenue share per level) vs H3 (periodic settlement ledger)? H1 conflicts with the motor-owned prize authority — likely to be rejected.
2. **Edit surface**: reuse the limits matrix (needs coordination with the §D agent — acceptable?) vs a new panel page?
3. **Currency of settlement**: bs-equivalent only (fits `comisiones` as-is) or per-currency (migration)?
4. **Retroactivity**: compute at query time (limit edits rewrite history) vs snapshot per sale (migration on sale path) vs snapshot per period (ledger rows)?
5. **Visibility**: report column(s) vs dedicated `/comisiones` ledger page; which roles?

**Ready for proposal**: Yes — with the answers to questions 1, 2 and 4 (they determine scope; 3 and 5 are refinements).