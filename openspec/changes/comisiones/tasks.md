# Tasks: Comisiones (revenue-share ledger)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1600–1900 (S4 ≈600, S5 ≈450) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR1 S1+S2 → PR2 S3 → PR3 S4 → PR4 S5 |
| Delivery strategy | auto-chain |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

### Pre-apply confirmations (guard lines)
- [x] **Independence CONFIRMED**: child `porcentaje_pago` MAY exceed parent; the cumulative cap (D11) tops each level to `100 − Σ tasas propias de ancestros`. No longer an assumption.
- [ ] **ASSUMPTION (spec i, per-currency cap)**: the cap applies per currency; each currency chain is capped independently — confirm before apply.
- [ ] **ASSUMPTION (spec ii, banca retention)**: banca stays excluded from ledger rows; its rate acts only as retention/cap over descendants — confirm before apply.
- [ ] **ASSUMPTION (spec iii, Σ own rates floor 0)**: Σ ancestros = ancestors' **own** configured rates (NULL/absent = 0), floor 0, `min(child, max(0, 100 − Σ))` — confirm before apply.
- [ ] **Chain strategy**: stacked-to-main vs feature-branch-chain — orchestrator asks user (plan splits independently either way).
- [ ] **§D coordination**: `limites.astro` global block is additive only; no `limites.ts`/matrix restructure.
- [ ] **Note**: `tasaEfectiva` unset ⇒ `0.00` only when the chain AND the global default are both unset.

### Suggested Work Units

| Unit | Goal | PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|----|----------------------|-----------------|-------------------|
| 1 | S1 config default + S2 reader | PR 1 | `DB_DATABASE=lotto_test_motor php artisan test --filter=ComisionServiceTest` | N/A — unit/service tests | drop `comision_defaults` migration |
| 2 | S3 calculation/preview | PR 2 | `… --filter=ComisionServiceTest` | N/A — unit tests | revert `ComisionService` counts |
| 3 | S4 ledger + settlement API + panel | PR 3 | `… --filter=ComisionesApiTest` | N/A — feature (DB) tests | drop range/cierre migrations + routes |
| 4 | S5 reports/cierre + panel columns | PR 4 | `… --filter=ComisionReporteTest|ComisionCierreTest` | N/A — feature tests | revert added report/cierre columns |

Commit note: `*.md` is `.gitignore:44`-ignored — commit openspec artifacts with `git add -f`.

## Phase 1: Config default (S1)

- [x] 1.1 Migration `backend/database/migrations/2026_09_29_000001_create_comision_defaults_table.php` (`moneda` unique, `porcentaje_pago decimal(5,2)` nullable, timestamps), seeded 2 rows bs/usd.
- [x] 1.2 Create `backend/app/Models/ComisionDefault.php` (fillable + `decimal:2` cast).
- [x] 1.3 RED `tests/Feature/ComisionesApiTest.php`: 2 default rows persist; PUT override; 403 without `manage_comisiones` (scenarios S4, S6).
- [x] 1.4 `backend/app/Http/Controllers/Api/ComisionController.php` + routes `GET/PUT /api/v1/comisiones/defaults` (super_master; PUT + `permission:manage_comisiones`).
- [x] 1.5 `panel/src/pages/limites.astro` additive 2-row global block (super_master); coordinate §D; no matrix restructure (S4, S5).
- [x] 1.6 `./vendor/bin/pint --test`.

## Phase 2: Readers (S2)

- [x] 2.1 RED `tests/Unit/ComisionServiceTest.php` (cascada): override gana, NULL cede, fallback global, hijo>padre permitido (S1, S2, S3).
- [x] 2.2 `ComisionService::tasaPropia(nivel, entidadId, juegoId, moneda): float` — fila propia del nivel; NULL/ausente = 0.
- [x] 2.3 `ComisionService::tasaEfectiva(nivel, entidadId, juegoId, moneda): float` — cascada taquilla>grupo>banca>global; NULL cede; sin definir ⇒ 0.00 (D2).
- [x] 2.4 RED cap D11 `ComisionServiceTest`: (a) banca 10 + taquilla 100 ⇒ 90; (b) acumulado ≤ 100 conserva (20+40 ⇒ 40); (c) tope independiente por moneda; (d) NULL/herencia (banca propia 60, grupo NULL, taquilla NULL ⇒ resuelve 60, liquidable 40).
- [x] 2.5 `ComisionService::tasaLiquidable(nivel, entidadId, juegoId, moneda): float` — D11 `min(tasaEfectiva, max(0, 100 − Σ tasas propias ancestros))`; grupo ⇒ {banca}; taquilla ⇒ {grupo, banca}; por moneda; piso 0.

## Phase 3: Calculation/preview (S3)

- [x] 3.1 RED `ComisionServiceTest`: SUM×tasa liquidable rounded, exclude anuladas, inclusive range, empty range, per-currency buckets (S7–S10, D6).
- [x] 3.2 `comisionEntidad(nivel, entidadId, desde, hasta, ?juegoId, ?moneda): float` — D6 buckets: cada bucket de moneda multiplica por `tasaLiquidable` (bs→bs, usd→usd, mixto→`amount_bs` + `amount_usd×exchange_rate_applied`); round 2dp; `moneda = null` evalúa bs y usd independientemente.
- [x] 3.3 `comisionesReporte(nivel, entidadIds, …)` + `previsualizar(desde, hasta, ?bancaIds)` — consumen `tasaLiquidable`; devuelven rows + conflictos.

## Phase 4: Ledger + settlement API + panel (S4)

- [x] 4.1 Migrations `…_000002_add_rango_fechas_to_comisiones_table.php` (nullable `fecha_inicio`/`fecha_fin` + index) and `…_000003_add_comision_to_cierres_caja_table.php` (`comision_bs_equivalent` nullable decimal). *(Nota slice 2: solo se adelantó `_000002` + `$table='comisiones'` y fillable/casts en `Comision.php`; `_000003` y `CierreCaja.php` siguen pendientes en 4.2.)*
- [ ] 4.2 Update `backend/app/Models/Comision.php` (range fillable/casts) + `CierreCaja.php` (comision fillable/cast).
- [ ] 4.3 RED `ComisionesApiTest`: Grupo+Taquilla rows only, pendiente→pagado, frozen non-retroactive, overlap 422 (S11–S14).
- [ ] 4.4 `ComisionService::liquidar(...)` — D4 `DB::transaction` + `lockForUpdate`, D5 idempotent PATCH, D7 one row per (nivel, entidad, rango).
- [ ] 4.5 Routes `GET /comisiones`, `POST /comisiones/liquidar`, `PATCH /comisiones/{comision}/pagar` (D8: `manage_comisiones` + super_master|master, master scoped `masterBancaIds()`).
- [ ] 4.6 Create `panel/src/pages/comisiones.astro` + `AdminLayout.astro` nav link (super_master|master).
- [ ] 4.7 `./vendor/bin/pint --test` + `cd panel && pnpm run build`.

## Phase 5: Reports/cierre + panel columns (S5)

- [ ] 5.1 RED `tests/Feature/ComisionReporteTest.php`: commission column per level, grouping intact (S18, S19); note D9: recipient rows settleable, banca/agencia subtree rollup; juego-filtered cells ≠ unfiltered ledger (finding F4).
- [ ] 5.2 Modify `ApuestaService::ventasTotales`/`cuadreCaja` — additive Comision via `app(ComisionService::class)`; preserve grouping.
- [ ] 5.3 RED `tests/Feature/ComisionCierreTest.php`: breakdown present; `total_efectivo_*`, arqueo, faltante/sobrante intact (S20, S21).
- [ ] 5.4 Modify `CierreService` (`calcularTotales`/`previsualizar`/`reporteSemanal`) — inject `ComisionService`, add `comision_bs_equivalent` (D10).
- [ ] 5.5 `panel/src/pages/reportes/ventas.astro` + `panel/src/pages/cuadre.astro` commission column/breakdown (finding F2).
- [ ] 5.6 `./vendor/bin/pint --test` + `cd panel && pnpm run build`.

## Phase 6: Regression verification

- [ ] 6.1 `DB_DATABASE=lotto_test_motor php artisan test --filter='ApuestaServiceTest|MotorPremiosRegresionTest|CierreCajaTest|CuadreCajaReportTest|LimitesApiTest'` — limits/premios untouched (S16, S17).
- [ ] 6.2 Full suite `DB_DATABASE=lotto_test_motor php artisan test` green.