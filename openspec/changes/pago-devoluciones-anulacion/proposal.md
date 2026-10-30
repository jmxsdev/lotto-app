# Proposal: Pago, devoluciones y anulación

## Intent

Fix issue #66: `PagoController::store` marks the apuesta `pagada`, overwrites `detalle_apuestas.premio_ganado(_usd)`, and triggers the ticket cascade for **every** `pagos.tipo`. `ingreso`/`devolucion` resolve prizes that were never paid and erase engine prize evidence. Annulment today (`DELETE /tickets/{id}`) is a soft delete with **no refund `Pago`** and never writes `estado='anulada'`. Result: money moves without a ledger row, and the state is wrong.

## Goals

- Only `egreso` may pay a prize (D1); `devolucion` becomes a pre-draw **annulment + refund** (D4); `ingreso` via `POST /pagos` is rejected (D8).
- Reuse the existing annulment window/policy (D7); one atomic transition, one transaction.
- Reports net the annulled-sale + refund pair to zero (D6) in the same release.

## Non-goals

- No post-resolution refunds (D3). No schema migration (R-10). No historical data mutation in this change (D11). No new `Anuladas` report column (option C rejected).

## Product decisions

| # | Decision | Status |
|---|---|---|
| D1–D7 | As given by owner (§explore) | **Final** |
| D8 | `POST /pagos` egreso-only; `ingreso`/`devolucion` → 422 | Proposed, owner review |
| D9 | Refund = ticket-level annulment: `DELETE /tickets/{id}` → `TicketAnulacionService` validates policy, creates `devolucion` `Pago`(s), marks apuestas+ticket `anulada`, soft-deletes, one transaction | Proposed, owner review |
| D10 | Reports exclude refunds tied to an annulled/trashed apuesta whose `fecha_hora` is **inside** the window (`whereNotExists` raw join, exploration §5.2 Option A); refunds of sales outside the window stay real outflows | Proposed, owner review |
| D11 | Legacy repair = gated separate slice (dry-run audit + owner approval), not in core | Final |
| D12 | Refund mirrors sale `metodo_pago`, explicit override allowed, USD forced `efectivo` | Final |
| D13 | `ApuestaPolicy::delete` ownership reused for all roles (tightens cross-tenant `POST /pagos`) | Final |

## Scope (auto-chain, 800-line budget)

| WU | Content | Est. lines |
|---|---|---|
| WU1 | `ApuestaPolicy::motivoNoAnulable` reason extraction; controllers reuse (no behavior change) | ~190 |
| WU2 | `PagoController` egreso-only + `DB::transaction` + `lockForUpdate` + tests | ~160 |
| WU3 | `TicketAnulacionService` + refund `Pago` + `anulada` + cascade + tests | ~470 |
| WU4 | Report reversal-pair fixes (`cuadreCaja`, `timeSeriesData`, `CierreService`) + tests | ~240 |
| WU5 (optional) | `pagos:auditar-legacy` dry-run command | ~220 |

Order: WU1 → WU2 → WU3 → WU4 (chained PRs; WU4 depends on WU3 for regression tests). WU5 gated.

## Capabilities

**New**: `pagos` — egreso-only payment registration; pre-draw annulment+refund transition (`anulada` + soft delete + cascade; backend-authoritative amounts; transaction+lock).

**Modified**:
- `metodo-pago` — devolucion method derived from sale, USD→`efectivo`.
- `reportes-agencia` — `cuadreCaja`/`timeSeriesData` reversal-pair exclusion.
- `cierre-caja` — `calcularTotales` exclusion; `desglose_metodos` reconciles with `total_ventas_*`.
- `colecciones-api` — `POST /pagos` documented egreso-only.

## Affected areas

| Area | Impact |
|---|---|
| `backend/app/Http/Controllers/Api/PagoController.php` | Modified (split, egreso-only) |
| `backend/app/Services/TicketAnulacionService.php` | New |
| `backend/app/Policies/ApuestaPolicy.php` | Modified (reason extraction) |
| `backend/app/Http/Controllers/Api/TicketController.php`, `ApuestaController.php` | Modified |
| `backend/app/Services/ApuestaService.php`, `CierreService.php` | Modified (report queries) |
| `tests/Feature/*`, `tests/Unit/*` | Add/modify (~14 files) |

No migration expected.

## Success criteria

- [ ] `ingreso`/`devolucion` via `POST /pagos` → 422; no `Pago`, no state/premio/cascade change.
- [ ] Valid pre-draw ticket refund → `devolucion` `Pago`(s) with sale amounts, apuestas+ticket `anulada` + soft-deleted, `premio_ganado` untouched, atomic rollback on failure.
- [ ] Same-window reversal pair: `Venta=0`, `Devoluciones=0`, `Efectivo=0` (cuadreCaja, timeSeriesData saldo, cierre `total_efectivo_*`); `desglose_metodos` sums to `total_ventas_*`.
- [ ] RF-05.2/05.3 restated: only `egreso` pays/alters prize; refund MUST NOT mark paid or modify prize, pre-draw refund annuls.
- [ ] Existing annulment messages byte-identical (`EliminacionApuestasTest`).

## Risks

| Risk | Mitigation |
|---|---|
| D1+D4 without D6 → visible cash error (Efectivo −R) | WU4 lands same release |
| Breaking API (ingreso rejection) | Release note; no in-repo consumer found |
| Irreversible refund (soft delete) | Policy re-check in transaction, audit log, 404 retry |
| Concurrency double payout | `lockForUpdate` + `DB::transaction` in WU2 |
| Policy string drift | Extract `motivoNoAnulable`, keep strings identical |
| Cross-window residual double count | Accept + document (exploration §5.4) |

## Open questions

None blocking. One accepted-with-documentation: cross-window residual (§5.4) — rule is Option A; a closing already emitted cannot re-issue for a past day. Owner sign-off at design gate.

## Rollback / release notes

Code-only: revert the chained PRs in reverse (WU4→WU1). No DB migration, so no down migration. Release note: `POST /pagos` is now egreso-only; `ingreso` is automatic at sale; refunds happen only via ticket annulment `DELETE /tickets/{id}`.
