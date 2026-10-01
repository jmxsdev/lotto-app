# Proposal: Comisiones (commissions revenue-share)

## Why

Comisión is dormant scaffolding: `juego_limites.porcentaje_pago` is persisted + validated but has **zero business readers**; the `comisiones` ledger table and `manage_comisiones` permission are orphaned. No revenue-share or settlement exists today. This change makes "comisión" mean revenue share (%) of a level over sales, settled as ledger rows.

## What Changes

Comisión = revenue share (%) of a level over sales, settled as `comisiones` rows (estado pendiente→pagado); rate field = `juego_limites.porcentaje_pago`.

### In Scope

- Effective-rate reader for `porcentaje_pago` (cascade taquilla>grupo>banca, mirroring `ApuestaService::getEffectiveLimit`).
- Global default matrix (2 rows: bs, usd) editable by super_master on the limits page (additive block; coordinated with §D limits agent).
- Compute commission over `apuestas.total_bs_equivalent` (per-sale `exchange_rate_applied` snapshot), round 2 decimals, generic date ranges.
- Settle ledger rows for Grupo + Taquilla only (banca excluded); freeze at settlement (non-retroactive).
- Report column in `reportes/ventas` + breakdown in cuadre/cierre.

### Capabilities

- **New**: `comisiones` — commission config, calculation, ledger settlement.
- **Modified**: `reportes-agencia` (commission column in `ventasTotales`); `cierre-caja` (commission breakdown in cuadre/cierre).

### Out of Scope

`participacion`, `fraccion`, `limite_tiempo` fields/UI (other agents). `taquilla/*`. Prize/payout math (`configuracion-juegos` cycle). Settlement (pending→paid) UX surface (design). Restrictivity-guard change.

## Impact

| Area | Impact |
|---|---|
| `backend/app/Services/ApuestaService.php` (getEffectiveLimit pattern) | New reader |
| `backend/app/Models/Comision.php`, `comisiones` table | Reuse |
| New `ComisionService` + `routes/console.php` job / endpoint | New |
| `backend/routes/api.php` (gate `permission:manage_comisiones`) | New routes |
| `panel/src/pages/limites.astro` (additive global block) | Modified (coord w/ §D) |
| `panel/src/pages/reportes/ventas.astro`, `cuadre.astro`, cierre views | Modified |
| `limite_minimo`/`limite_maximo` sale path | MUST NOT regress |

## Approach

Small additive build reusing existing schema. New reader resolves effective `porcentaje_pago` per (entity, juego, moneda) like `getEffectiveLimit`. `ComisionService` aggregates sales by level over a date range, computes bs-equivalent %, writes `comisiones` rows (Grupo/Taquilla). Settlement job/endpoint behind `manage_comisiones`. Report/cierre surfaces are additive columns. Strict TDD planned later; testability baked in now.

## Decisions (locked, 2026-09-29)

1. **Meaning**: revenue share % → `comisiones` ledger, rate = `porcentaje_pago`.
2. **Config**: global default (2 rows bs/usd, super_master, limits page) + per-game/entity overrides via matrix.
3. **Currency**: bs-equivalent from `total_bs_equivalent`; round 2 decimals.
4. **Retroactivity**: non-retroactive; freeze at settlement.
5. **Visibility**: sales report column + cuadre/cierre breakdown.
6. **Recipients**: Grupo + Taquilla only.
7. **Period**: generic date ranges.

## Risks

| Risk | Mitigation |
|---|---|
| Regress `limite_minimo/maximo` sale path | Reader is additive; no write-path change; regression tests |
| §D limits-matrix collision | Minimal additive block; explicit coordination |
| Global default has no natural row (`juego_id`/`banca_id` NOT NULL) | Design chooses sentinel/seed/config slot |
| Restrictivity guard covers only min/max | Decide whether child % may exceed parent |
| 400-line review budget | Forecast in tasks; chain PRs if needed |

## Open Questions

1. Where does the global 2-row default live (`juego_limites` requires `juego_id`+`banca_id` NOT NULL)?
2. Should a child's `porcentaje_pago` be allowed to exceed its parent's (restrictivity guard)?

## Rollback Plan

Feature is additive (new reader/service/routes/columns). Revert = remove routes + hide columns; `comisiones` rows and `porcentaje_pago` writes remain harmless (fields already persisted). No destructive migration.

## Success Criteria

- [ ] Effective `porcentaje_pago` resolves per entity/currency via cascade.
- [ ] Commission computed as bs-equivalent % over arbitrary date range, rounded 2dp.
- [ ] Settlement writes `comisiones` rows for Grupo+Taquilla, estado pendiente→pagado, frozen.
- [ ] Report column + cuadre/cierre breakdown render; `limite_minimo/maximo` sale path regression-tested green.
