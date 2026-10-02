# Design: Comisiones (revenue-share ledger)

## Technical Approach

Add an isolated, read-mostly `ComisionService`: resolve the **tasa efectiva** from `juego_limites.porcentaje_pago` (taquilla > grupo > banca > global default), derive the **tasa liquidable** through the cumulative cap (D11: each level is capped by its ancestors' **own** rates, per currency), compute bs-equivalent commission over inclusive ranges from per-sale `exchange_rate_applied`, and freeze results as `comisiones` rows for Grupo + Taquilla. The sale path (`createApuesta`/`getEffectiveLimit`) stays byte-identical; reports and cierre gain additive columns.

## Decisions

| # | Decision | Choice | Rationale (rejected) |
|---|---|---|---|
| D1 | Global default storage | New table `comision_defaults` (2 rows bs/usd: `moneda` unique, `porcentaje_pago decimal(5,2) nullable`, timestamps), migration-seeded; applies to all games | `juego_limites` requires NOT NULL `juego_id`/`banca_id`; `bancas.config` is unstructured write-only JSON |
| D2 | Rate readers — never conflate | **tasa propia** = the level's own `porcentaje_pago` row (NULL/absent = 0); **tasa efectiva** = cascade-resolved (NULL rows cede, falls to global default, unset ⇒ 0.00); **tasa liquidable** = post-cap (D11). `tasaEfectiva(nivel, entidadId, juegoId, moneda)` mirrors `getEffectiveLimit` ordering | Reuses `JuegoLimite` read-only; `ApuestaService` signature unchanged (regression safety) |
| D3 | Range record | Additive nullable `fecha_inicio`/`fecha_fin` (date) on `comisiones` + index; keep NOT-NULL `periodo` as label `YYYY-MM-DD..YYYY-MM-DD` | `periodo` alone can't express generic ranges or support overlap queries |
| D4 | Double counting | Reject the whole settlement (422 + conflicting entity ids) when any target entity has rows with `fecha_inicio <= hasta AND fecha_fin >= desde`; checked in `DB::transaction` with `lockForUpdate` | Auto-subtracting settled days is complex |
| D5 | Estado | `pendiente → pagado` only; PATCH on an already-paid row is idempotent no-op (200) | Safe retries; reverse transition not required |
| D6 | Currency of rate | Per-sale buckets at each currency's **liquidable** rate (D11): bs-only ⇒ bs-equivalent at bs; usd-only ⇒ `total_bs_equivalent` at usd; mixto ⇒ `amount_bs` at bs + `amount_usd×exchange_rate_applied` at usd; round total once to 2dp | Makes the 2-row default meaningful; single-currency ranges still equal `round(SUM×tasa_liquidable,2)` |
| D7 | Settlement scope | One aggregated row per (nivel, entidad, rango), computed at that entity's **liquidable** rate; entities with 0 base get no row; `banca_id` always null; only Grupo/Taquilla | One row per game would bloat the ledger; banca's own rate caps descendants only (D11), never a ledger row |
| D8 | Write gating | Writes: `permission:manage_comisiones` + `role:super_master|master`; `master` scoped to `masterBancaIds()` (else 403) | No seeder change; mirrors existing hierarchy guards |
| D9 | Report column | Recipient rows (taquilla/grupo) show their settleable amount (= ledger, liquidable rate); banca/agencia show informational subtree rollup `Σgrupo+Σtaquilla` (banca's own rate is retention, never a payout row). Honor `tipo_juego`/`moneda` filters | Default banca view stays informative; grouping untouched |
| D10 | Cierre | `comision_bs_equivalent` (nullable decimal) added to `cierres_caja` and returned by `calcularTotales`/`previsualizar`/`reporteSemanal`; `total_efectivo_*`, arqueo, faltante/sobrante untouched | Matches existing persisted-snapshot shape |
| D11 | Cumulative cap (**tasa liquidable**) | `tasaLiquidable = min(tasaEfectiva, max(0, 100 − Σ tasas propias de ancestros))`; Σ uses **own** configured rates (NULL/absent = 0), computed **per currency**; for grupo ⇒ Σ{banca}; for taquilla ⇒ Σ{grupo, banca}. Sum ≤ 100 ⇒ unchanged; sum > 100 ⇒ parent priority. Examples: banca 10 + taquilla 100 ⇒ taquilla liquidates 90; banca 60, grupo NULL, taquilla NULL ⇒ resolved 60, liquidates 40 | Official spec example. Summing **tasa efectiva** instead of **tasa propia** over-caps the child (forbidden: they must not be conflated); floor 0 avoids negative rates |

## Contracts

```
Routes (api.php, static paths before {comision}):
GET/PUT /api/v1/comisiones/defaults           super_master (PUT adds permission:manage_comisiones)
GET     /api/v1/comisiones/preview            super_master|master (scoped)
GET     /api/v1/comisiones                    super_master|master (scoped, paginated)
POST    /api/v1/comisiones/liquidar           permission:manage_comisiones + super_master|master
PATCH   /api/v1/comisiones/{comision}/pagar   permission:manage_comisiones + super_master|master

ComisionService (new):
  tasaPropia(string $nivel, int $entidadId, int $juegoId, string $moneda): float      // own row only; NULL/absent = 0
  tasaEfectiva(string $nivel, int $entidadId, int $juegoId, string $moneda): float    // cascade; NULL row cedes; unset ⇒ 0.00
  tasaLiquidable(string $nivel, int $entidadId, int $juegoId, string $moneda): float  // D11 cap; Σ ancestors' own rates; per moneda
  comisionEntidad(string $nivel, int $entidadId, Carbon $desde, Carbon $hasta, ?int $juegoId, ?string $moneda): float
  comisionesReporte(string $nivel, array $entidadIds, Carbon $desde, Carbon $hasta, ?int $juegoId, ?string $moneda): array<int,float>
  previsualizar(Carbon $desde, Carbon $hasta, ?array $bancaIds): array   // rows + conflictos
  liquidar(Carbon $desde, Carbon $hasta, ?array $bancaIds, int $userId): array
```

`comisionEntidad` — and everything above it (`previsualizar`, `liquidar`, `comisionesReporte`) — multiplies each currency bucket by `tasaLiquidable`; `moneda = null` evaluates bs and usd independently (each with its own cap). Bulk lookups only (no per-entity queries); `ApuestaService` resolves it via `app(ComisionService::class)` inside `ventasTotales`/`cuadreCaja` (same pattern as `app(PremiosEngine::class)`), preserving its constructor.

## Data Flow

```
config: super_master ──PUT /comisiones/defaults──► comision_defaults ◄─ reader fallback
rates:  juego_limites (tasa propia) ─► tasaEfectiva ─► tasaLiquidable (D11 cap, per moneda)
sales:  apuestas(bs/usd, rate_applied, total_bs_equiv) ─► ComisionService ─► report/cuadre column
                                                          └─ liquidar ─► comisiones(grupo|taquilla, fechas, estado)
close:  CierreService.calcularTotales ─► + comision_bs_equivalent ─► cierres_caja/reporteSemanal
```

## Files

Create under `backend/`: migrations `2026_09_29_000001_create_comision_defaults_table.php`, `..._000002_add_rango_fechas_to_comisiones_table.php`, `..._000003_add_comision_to_cierres_caja_table.php`; `app/Models/ComisionDefault.php`; `app/Services/ComisionService.php`; `app/Http/Controllers/Api/ComisionController.php`. Panel: `panel/src/pages/comisiones.astro`.

Modify: `backend/app/Models/Comision.php` (range fillable/casts), `CierreCaja.php` (fillable/cast), `backend/routes/api.php`, `backend/app/Services/ApuestaService.php` (only `ventasTotales`/`cuadreCaja`: add `EntidadId` + `Comision`), `backend/app/Services/CierreService.php` (inject `ComisionService`; totals/attributes/semanal), `panel/src/pages/limites.astro` (additive 2-row global block for super_master; no matrix restructure), `panel/src/pages/reportes/ventas.astro` and `cuadre.astro` (column), `panel/src/layouts/AdminLayout.astro` (nav link, `super_master|master`).

Not touched: sale path (`createApuesta`, `validarMonedaYLimites`, `getEffectiveLimit`, `JuegoLimite*`, `JuegoController` limits, `limites.ts`), `configuracion-juegos` files, `taquilla/*`, `participacion`/`fraccion`/`limite_tiempo`.

## Testing Strategy

All paths under `backend/tests/`.

| Spec area | Scenarios | Test file |
|---|---|---|
| Cascade (tasa efectiva) + assumption | override, NULL cede, global fallback, child > parent allowed | `Unit/ComisionServiceTest.php` |
| Cumulative cap (D11) | cap applies (banca 10 + taquilla 100 ⇒ 90); sum ≤ 100 keeps 40; cap independent per currency; NULL/inheritance (banca 60 ⇒ liquidates 40) | `Unit/ComisionServiceTest.php` |
| Calculation | SUM×tasa liquidable rounded, anuladas excluded, inclusive range, empty range | `Unit/ComisionServiceTest.php` |
| Config + permission | 2 default rows persist, override, 403 without permission | `Feature/ComisionesApiTest.php` |
| Ledger | Grupo+Taquilla rows, no banca, pendiente→pagado, frozen, overlap 422 | `Feature/ComisionesApiTest.php` |
| Reportes | column present per level, grouping intact | `Feature/ComisionReporteTest.php` |
| Cierre | breakdown present, totals/arqueo/diferencia intact | `Feature/ComisionCierreTest.php` |
| Regression (untouched, green) | limits sale path, premios | existing `Unit/ApuestaServiceTest.php`, `Feature/MotorPremiosRegresionTest.php`, `CierreCajaTest.php`, `CuadreCajaReportTest.php`, `LimitesApiTest.php` |

Commands: `cd backend && DB_DATABASE=lotto_test_motor php artisan test` (not `composer test`); `./vendor/bin/pint --dirty`; `cd panel && npm run build`.

## Slices (for tasks)

S1 config default → S2 readers (`tasaEfectiva` + `tasaLiquidable` cap, D11) → S3 calculation/preview (liquidable buckets) → S4 ledger + settlement API + panel `comisiones.astro` → S5 reports/cierre + panel columns. Against the 1600-line budget S4 (~600) and S5 (~450) need chained PRs; tasks phase emits guard lines.

## Threat Matrix / Rollout

N/A — no routing, shell, subprocess, VCS/PR, executable-classification, or process boundary. Migrations are additive (new table, nullable columns, seeded defaults), no backfill; rollback removes routes/panel blocks.

## Open Questions

- [ ] **ASSUMPTION (spec i, pre-apply):** cap applies per currency; each currency chain is capped independently.
- [ ] **ASSUMPTION (spec ii, pre-apply):** banca stays excluded from ledger rows; its rate acts only as retention/cap over descendants.
- [ ] **ASSUMPTION (spec iii, pre-apply):** Σ ancestros = ancestors' **own** configured rates (NULL/absent = 0), floor 0, `min(child, max(0, 100 − Σ))`.
- [ ] Coordinate `limites.astro` global block with the §D limits agent (no matrix changes).
