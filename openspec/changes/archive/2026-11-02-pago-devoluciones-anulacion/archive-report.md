# Archive Report — `pago-devoluciones-anulacion`

**Change**: `pago-devoluciones-anulacion` · Issue #66
**Archived**: 2026-11-02
**Branch**: `feature/pago-devoluciones-anulacion` (tracker) · worktree `/home/gzuz/Documentos/lotto-app-worktrees/pago-devoluciones-anulacion`
**Head at archive**: `4b36597` (13 commits ahead of `main`, clean tree)
**Final status**: **PASS WITH WARNINGS** — `sdd-verify` round 2: `verdict: pass_with_warnings`, 0 blockers, 0 critical findings, 13/13 requirements, 41/41 scenarios.

> Bookkeeping note: `apply-progress.md` and `verify-report.md` are intermediate snapshots. This report is the terminal record of the cycle and was reconciled against the commits, the verified specs and the user-reported suite evidence at close.

---

## 1. What shipped

Issue #66: `PagoController::store` marked the apuesta `pagada`, overwrote `detalle_apuestas.premio_ganado(_usd)` and triggered the ticket cascade for **every** `pagos.tipo`; `ingreso`/`devolucion` resolved prizes that were never paid and erased engine prize evidence. Annulment (`DELETE /tickets/{id}`) was a bare soft delete with no refund `Pago` and never wrote `estado='anulada'`, so money moved without a ledger row.

Delivered behavior:

| Area | Behavior at close |
|---|---|
| `POST /api/v1/pagos` | `egreso` only. `ingreso`/`devolucion` → 422 with a flow message, rejected immediately after input validation (no apuesta load, no transaction, no write). Enum values kept in the schema. |
| Egreso payment | Single `DB::transaction` + `lockForUpdate` on the apuesta; re-checks existence (404), taquilla ownership (403), `estado ∈ {pendiente, ganadora}`, `resultado_id`, engine prize > 0 and ±0.01 tolerance **inside** the lock. Engine-authoritative amounts persisted in `pagos.amount_*` and `detalle_apuestas.premio_ganado(_usd)`; 201 response includes `premio`. Only egreso mutates state/prize and runs the cascade. Cascade hardened: skips trashed/`pagada`/`anulada` tickets, evaluates live lines only, `isNotEmpty()` guard. |
| Refund | Ticket-level annulment via `DELETE /api/v1/tickets/{id}` → `TicketAnulacionService`: validates the policy per live line before any write (all-or-nothing), records one `devolucion` `Pago` per live line mirroring the sale (`amount_bs`, `amount_usd`, `exchange_rate_applied`, method), sets apuestas + ticket `anulada`, soft-deletes both, writes one `ticket_anulado` log — one transaction. `premio_ganado(_usd)` untouched. |
| `DELETE /api/v1/apuestas/{id}` | Ticketless-only. `authorize('view')` first (cross-tenant → 403, before any ticket-level probe), then `ticket_id !== null` → 422 redirecting to `DELETE /tickets/{ticket_id}`; ticketless → `anularApuestaSuelta` (refund + `anulada` + soft delete + `apuesta.anulada` log). |
| Refund method | Explicit override → sale `ingreso.metodo_pago` (lowest `pagos.id`) → `efectivo`; `Pago::resolverMetodoPago` forces `efectivo` when USD is involved. Invalid override → 422 before any write. |
| Reports | `cuadreCaja` and `timeSeriesData` exclude a `devolucion` whose sale is `anulada`/soft-deleted **and** whose `fecha_hora` falls inside the report window (`ReversalPairQuery`). Cross-window refunds and untied refunds (`apuesta_id = null`) remain real outflows. |
| Cierre | `CierreService::calcularTotales` uses `egreso OR (devolucion AND NOT EXISTS sale-in-window)` with a half-open `[fechaInicio, fechaFin)`; the same filtered query feeds `total_egresos_*` and `egresosDesglose`, so `desglose_metodos` reconciles with `total_ventas_*` by construction. `$ventasDesglose` unchanged (AD-7). |
| Ops (gated) | `pagos:auditar-legacy`: dry-run by default (C1–C5 queries, grouped by taquilla, optional CSV, zero writes); `--apply`/`--revert` require explicit numeric `--ids`, are mutually exclusive and transactional; never touches `pagos.amount_*`, `cierres_caja`, `comisiones`; unreconciled multi-line tickets go to manual review. |
| Schema | No migration, no route change, no model change. |

---

## 2. Work units and commits

| WU | Content | Commit |
|---|---|---|
| WU1 | `ApuestaPolicy::motivoNoAnulable` reason extraction (`estado`, `con_resultado`, `sorteo_ocurrido`, `fuera_de_ventana`, `sin_ownership`), one message mapper; behavior-neutral | `6a897b4` refactor(api): extrae motivos de anulación a ApuestaPolicy |
| — | SDD artifacts + WU1 record | `221c999` docs(sdd): registra artefactos y WU1 |
| WU2 | `POST /pagos` egreso-only, transaction + row lock, engine-authoritative amounts, cascade hardening, collection update | `adb4f42` fix(api): restringe /pagos a egreso y endurece el pago |
| — | WU2 record | `35e9444` docs(sdd): registra WU2 |
| WU3-A | `TicketAnulacionService` + `TicketAnulacionException`, ticket annulment with refund, `anulada` + soft delete + audit (size exception) | `539096f` feat(api): anula ticket y registra devolución atómica |
| — | WU3-A record + size exception | `de09e78` docs(sdd): registra WU3-A con size:exception |
| WU3-B | `ApuestaController::destroy` ticketless-only, rejection matrix, method rules, collections (size exception) | `089e610` feat(api): restringe DELETE /apuestas a ventas sueltas |
| — | WU3-B record + size exception | `6e2576e` docs(sdd): registra WU3-B |
| WU4 | `ReversalPairQuery`, `ApuestaService` (cuadre/time-series), `CierreService::calcularTotales`, docs | `3ffbb37` fix(reportes): neutraliza par venta anulada y devolución |
| — | WU4 record | `6fd7efc` docs(sdd): registra WU4 |
| WU5 (gated) | `PremiosLiquidador` extraction, `pagos:auditar-legacy` (dry-run/apply/revert), runbook | `8032638` feat(pagos): audita y repara pagos legacy con comando gated |
| — | WU5 record + full-suite green | `64cccb0` docs(sdd): registra WU5 y suite completa en verde |
| Remediation | Two verification-evidence tests (zero-prize egreso, mid-cascade rollback) — tests only, no production change | `4b36597` test(pagos): cubre premio cero y rollback de anulación (verify) |

---

## 3. Tests and evidence

Strict TDD was active for the whole cycle: **the agent never executed a test, `composer test`, `php artisan test` or Pint**. All runtime evidence below is user-run.

| Evidence | Command | Result |
|---|---|---|
| WU1 focused | `php artisan test --filter='ApuestaPolicyAnulacion\|EliminacionApuestas\|TicketEliminacion'` | GREEN (user-reported) |
| WU2 focused | `php artisan test --filter='PagoIngresoRechazado\|PagoEgresoUnicoMutante\|PagoTipo\|ApuestaTest'` | GREEN (user-reported) |
| WU3-A focused | `php artisan test --filter='PagoDevolucionAnulacion\|EliminacionApuestas'` | GREEN (user-reported) |
| WU3-B focused | `php artisan test --filter='PagoDevolucionRechazos\|MetodoPago\|TicketAnuladosVisibles'` | GREEN (user-reported) |
| WU4 focused | `php artisan test --filter='CuadreCaja\|CierreCaja\|Estadistica'` | GREEN (user-reported) |
| WU5 focused | `php artisan test --filter=AuditarPagosLegacy` | GREEN (user-reported) |
| Remediation (round 2) | `php artisan test --filter='PagoEgresoUnicoMutante\|PagoDevolucionAnulacion'` | GREEN (user-reported) |
| **Full parallel suite** | `cd backend && composer test:parallel` | **1337 tests, 7726 assertions, 2 skipped, OK** (user-reported) |

- Build: not applicable (no build step; PHP/Laravel).
- Coverage tool: not run (agent forbidden from executing tests); coverage threshold is `0` in `openspec/config.yaml`.
- Pint: not run in verify. `cd backend && vendor/bin/pint --test` remains the CI style gate for delivery.
- Test layer mix: 17 unit tests (`ApuestaPolicyAnulacionTest`) + 179 HTTP feature test methods across 13 feature files; no E2E for the backend.

Round-1 verification blockers (evidence completeness only, no behavioral defect) were closed by `4b36597`, adding exactly two tests:

- zero-prize egreso → 422 with exact message, no `estado`/prize/`pagos`/log writes;
- mid-cascade rollback → failure injected at the final log write rolls back the refund insert, both `anulada` updates and both soft deletes, leaving no `ticket_anulado` log.

---

## 4. Owner decisions recorded as final

| # | Decision |
|---|---|
| D1 | Only `egreso` marks an apuesta `pagada`, writes `premio_ganado(_usd)` and triggers the ticket cascade. |
| D2 | Refunds exist only pre-draw and inside the effective `tiempo_eliminacion` window (default 5 minutes; hierarchy taquilla → grupo → banca → 5). |
| D3 | Every other refund is disabled — no post-resolution refund. |
| D4 | A refund is a ticket-level annulment plus `devolucion` `Pago`(s), with apuestas + ticket `anulada` and soft-deleted, in one atomic transaction. |
| D5 | Non-`egreso` types never mutate state, prize or ticket. |
| D6 | Reports treat the annulled sale and its refund as a reversal pair (net zero in-window). |
| D7 | The annulment policy/window rules are reused, not re-implemented (reason-code extraction, one message mapper). |
| D8 | `POST /pagos` is egreso-only; `ingreso` and `devolucion` → 422. |
| D9 | Refund = ticket-level annulment + refund via `DELETE /tickets/{id}`; irreversible by design. |
| D10 | Reversal-pair exclusion in reports/cierre; cross-window and cross-midnight residual accepted and documented. |
| D11 | Legacy data repair is a gated, owner-approved ops slice (WU5), not part of the core change; no historical mutation in this change. |
| D12 | Refund mirrors the sale `metodo_pago`, explicit override allowed, USD forced `efectivo`. |
| D13 | `ApuestaPolicy` ownership reused for all roles (taquilla own, agencia/grupo/banca subtree, master/super_master any). |
| AD-4 | `DELETE /apuestas/{id}` is ticketless-only; a line inside a ticket → 422 pointing to `DELETE /tickets/{id}`. |
| AD-9 | Receipt/reprint `GET /pagos/{apuesta}` inflation is pre-existing and unrelated to #66; the fix is deferred to a separate change. |

Approval source: the owner approved D8, D9, D10, AD-4 and AD-9, and the `size:exception` requests, before/at the design gate and during apply (as asserted by the orchestrator and recorded in `tasks.md`). The `design.md` header and §16 still read "under owner review"; that text was not rewritten because archived artifacts are immutable, and this table is the approval of record.

---

## 5. Spec sync (source of truth updated)

Deltas merged into `openspec/specs/` before the archive move. Existing main-spec content was preserved; only the deltas were applied.

| Domain | Action | Details |
|---|---|---|
| `pagos` | **Created** (`openspec/specs/pagos/spec.md`) | New capability: 8 requirements / 22 scenarios. Copied mechanically from the delta; the "open decisions / owner review" note was replaced with the approved-decisions note. |
| `metodo-pago` | **Updated** | 1 MODIFIED requirement ("Captura de método en el pago de premio (egreso/devolucion)"): egreso-only capture plus refund-mirrors-sale/override/USD-forced rules. 3 existing scenarios preserved, 3 refund scenarios added. 5 requirements untouched. |
| `reportes-agencia` | **Updated** | 1 ADDED requirement ("Reversal-pair exclusion in cuadreCaja and timeSeriesData") with 5 scenarios. 8 existing requirements untouched. |
| `cierre-caja` | **Updated** | 1 ADDED requirement ("Reversal-pair exclusion in cierre totals") with 3 scenarios. 13 existing requirements untouched. |
| `colecciones-api` | **Updated** | 1 MODIFIED requirement ("Contratos de payload y respuesta": Pagos/Tickets rows and a new rejection scenario) + 1 ADDED requirement ("Annulment + refund contract in collections"). 5 existing requirements untouched. |

Language note: deltas mix English and Spanish while the main specs are Spanish. The merge keeps each file internally coherent — the new `pagos` spec body is English (as authored and verified), and the appended requirements keep their authored language; no existing Spanish text was translated.

---

## 6. Size exceptions and review load

| Slice | Forecast | Actual | Status |
|---|---|---|---|
| WU1 | 280–320 (inner target 400) | above inner target (unit-test scaffolding) | accepted, below the 800-line session budget |
| WU2 | 560–650 | as forecast | — |
| WU3-A | 600–660 | ≈1.1k | **owner-approved `size:exception`** — one atomic transition (service + exception + controller + tests), hard to split without breaking all-or-nothing |
| WU3-B | 320–400 | ≈977 | **owner-approved `size:exception`** |
| WU4 | 340–390 | as forecast | — |
| WU5 | 310–360 | above forecast | **owner-approved `size:exception`** for the cycle |

Branch totals (`git diff --numstat main...HEAD`): **7823 insertions, 329 deletions (8152 changed lines)**, of which roughly 2.5k are `openspec/` artifacts. The 400-line PR budget is exceeded by design; delivery uses the `feature-branch-chain` / `auto-chain` strategy with WU3-A, WU3-B and WU5 reviewed as single units. Release unit: **WU3-A + WU3-B + WU4**; never merge WU3 without WU4.

---

## 7. Warnings carried, and their state at close

| ID | Warning | State at close |
|---|---|---|
| W3 | `agencia` cannot refund a ticket: `routes/api.php` excludes `agencia` from the tickets group, so the policy branch is unreachable via `DELETE /tickets/{id}`. | **Accepted residual.** Pre-existing route restriction, documented in design and collections; the verified scenario names `banca`/`grupo`, which pass. Follow-up: route/role restriction decision. |
| W4 | Stale SDD bookkeeping (pending GREEN columns, "uncommitted" notes, unchecked acceptance checklist). | **Fixed at archive** — see §8. |
| W5 | `docs/dev/motor-cierres-comisiones.md` is an add/add conflict on rebase; branch version is a strict superset. | **Operational, trivially resolvable**: resolve by keeping the branch file when retargeting onto `main`. |
| W6 | Owner-approved size exceptions. | **Accepted**, recorded in §6. |
| W7 | `POST /pagos` egreso ownership remains taquilla-only; `banca`/`grupo`/`master` can pay outside their subtree. | **Pre-existing scope gap**, unchanged from `main`; the delta specs' ownership requirement covers refunds only. Proposal D13's parenthetical overstates what shipped. |

---

## 8. Archive-time bookkeeping reconciliation

The Task Completion Gate found stale unchecked boxes in `tasks.md` (acceptance checklist) and stale `pending` GREEN columns in `apply-progress.md`. The orchestrator explicitly instructed reconciliation at archive. Proof used: `apply-progress.md` per-WU completion records, `verify-report.md` (13/13 acceptance items "Met"), and the commit list. No implementation task was incomplete (25/25 implementation tasks were already checked).

Reconciled mechanically:

- `tasks.md`: phase line set to `archive (2026-11-02)`; a final-state note added; the 13 acceptance checklist items marked `[x]`. Checklist text was not altered.
- `apply-progress.md`: a final-state note added; 17 stale `pending` GREEN cells → `GREEN (user-reported)`; 5 `user-run pending` evidence rows → `GREEN (user-reported)`; 3 "changes left uncommitted" notes replaced with the committed-state note.

No behavior, source or test file was modified during archive.

---

## 9. Residual risks and follow-ups

1. **AD-9 — receipt/reprint `GET /pagos/{apuesta}`** still returns every `Pago` for the apuesta, including the sale `ingreso`, and `recibos.ts` sums them (pre-existing inflation). New refund rows cannot reach a reprint today because an annulled ticket exposes zero live apuestas, but the fix is owed as a separate receipt change with taquilla tests.
2. **Cross-window / cross-period reversal pair** nets `−R` across closings when the sale belongs to an already-closed period. Accepted (D10) and documented; a schema-level fix needs `anulada_at` bucketing in a separate change. A closing already emitted cannot re-issue for a past day.
3. **Cross-midnight per-day attribution** is wrong even though the window total nets zero (documented; `EstadisticaTest` pins the residual).
4. **`agencia` route restriction** (W3) — ticket annulment is unreachable for the `agencia` role through the route; needs a route/role decision if the agency role is to refund at the counter.
5. **Concurrency evidence is sequential.** The `for update` lock is proven by a query-log assertion and a sequential double-egreso test, not by a real race; MySQL current-read semantics are the actual guarantee. A true parallel-request test would strengthen it.
6. **Refund is irreversible** (D9): no un-annul endpoint by design; reverting a wrongly issued refund requires a data fix.
7. **`cuadreCaja` taquilla visibility**: `taquillaIds` derives from the sales query, so a taquilla with only refunds in the window is invisible (pre-existing R11).
8. **`desglose_metodos` depends on the sale `ingreso` row**: a legacy duplicate/missing `ingreso` still skews the breakdown (pre-existing finding #8).
9. **WU5 is a production data mutation**, gated only by operator discipline (`--ids` + owner approval + backup per the runbook). Dry-run review is mandatory before any `--apply`.
10. **Pint** (`vendor/bin/pint --test`) was not run during verify; it remains the CI style gate and must pass before merge.
11. **`POST /pagos` egreso ownership** stays taquilla-only (W7) — a pre-existing scope gap worth a follow-up.

---

## 10. Final file inventory

Change artifacts (moved to `openspec/changes/archive/2026-11-02-pago-devoluciones-anulacion/`):

| File | Purpose |
|---|---|
| `explore.md` | Exploration: root cause, options, report double-count analysis |
| `proposal.md` | Intent, goals, decisions D1–D13, scope, risks, rollback |
| `specs/pagos/spec.md` | Delta → new main spec |
| `specs/metodo-pago/spec.md` | Delta (MODIFIED) |
| `specs/reportes-agencia/spec.md` | Delta (ADDED) |
| `specs/cierre-caja/spec.md` | Delta (ADDED) |
| `specs/colecciones-api/spec.md` | Delta (MODIFIED + ADDED) |
| `design.md` | Architecture decisions AD-1…AD-9, flows, locks, queries |
| `tasks.md` | Work units, TDD checkpoints, verified acceptance checklist |
| `apply-progress.md` | Per-WU implementation record and TDD evidence |
| `verify-report.md` | Round-2 verification (pass_with_warnings, 13/13, 41/41) |
| `archive-report.md` | This report |

Production and supporting files changed by the branch:

- `backend/app/Console/Commands/AuditarPagosLegacy.php` (new)
- `backend/app/Exceptions/TicketAnulacionException.php` (new)
- `backend/app/Services/TicketAnulacionService.php` (new)
- `backend/app/Services/PremiosLiquidador.php` (new)
- `backend/app/Support/ReversalPairQuery.php` (new)
- `backend/app/Http/Controllers/Api/PagoController.php`, `Api/TicketController.php`, `Api/ApuestaController.php`
- `backend/app/Policies/ApuestaPolicy.php`
- `backend/app/Services/ApuestaService.php`, `CierreService.php`
- `backend/tests/Unit/ApuestaPolicyAnulacionTest.php` (new)
- `backend/tests/Feature/`: `PagoIngresoRechazadoTest`, `PagoEgresoUnicoMutanteTest`, `PagoDevolucionAnulacionTest`, `PagoDevolucionRechazosTest`, `AuditarPagosLegacyTest` (new); `PagoTipoTest`, `ApuestaTest`, `EliminacionApuestasTest`, `MetodoPagoTest`, `TicketAnuladosVisiblesTest`, `CuadreCajaReportTest`, `CierreCajaTest`, `EstadisticaTest` (modified)
- `collections/Pagos/Registrar Pago (Token required).yml`, `collections/Tickets/Anular Ticket.yml`, `collections/Apuestas/Eliminar Apuesta (Token required - 5 min rule).yml`
- `docs/dev/motor-cierres-comisiones.md` (new on branch; add/add on rebase), `docs/dev/runbook-ops.md`

Main specs updated: `openspec/specs/pagos/spec.md` (new), `metodo-pago`, `reportes-agencia`, `cierre-caja`, `colecciones-api`.

Note: `*.md` is gitignored in this repo (`.gitignore:44`, with `docs/**/*.md` re-included). The orchestrator must `git add -f` the archive folder and the updated/new main specs.

---

## 11. Rollback and release notes

**Rollback (code-only, no migration, no down migration):** revert the chained commits in reverse — WU5 → WU4 → WU3-B → WU3-A → WU2 → WU1. WU4 must not be reverted alone while WU3 is live (reverting it reintroduces `Efectivo = −R`). If a WU5 `--apply` already ran, use `pagos:auditar-legacy --revert --ids=...` **before** reverting code. The `motivoNoAnulable` extraction (WU1) is behavior-neutral and can be reverted independently.

**Release notes for consumers:**

- `POST /api/v1/pagos` is now **egreso-only**. `tipo=ingreso` returns 422 (`El ingreso se registra automáticamente al vender; no se admite por este endpoint.`); `tipo=devolucion` returns 422 (`Las devoluciones se registran anulando el ticket: use DELETE /api/v1/tickets/{id}.`). Income is automatic at sale.
- Refunds only happen through ticket annulment: `DELETE /api/v1/tickets/{id}` now annuls **and** refunds, in one transaction, and is irreversible.
- `DELETE /api/v1/apuestas/{id}` only accepts ticketless sales; a line inside a ticket returns 422 pointing to the ticket endpoint.
- Collections under `collections/` were updated to the new contract (422/403/404, refund semantics, `premio` in the egreso response).

**Release gate:** ship WU3-A + WU3-B + WU4 as one unit. WU5 is off by default and is deployed separately after an owner-reviewed dry-run. Before merge: rebase onto current `main`, resolve `docs/dev/motor-cierres-comisiones.md` by taking the branch file, and run `composer test:parallel` plus `vendor/bin/pint --test`.

---

## 12. Traceability

Engram topics read for this archive (`project: lotto-app`):

| Artifact | Observation |
|---|---|
| `sdd/pago-devoluciones-anulacion/explore` | #687 |
| `sdd/pago-devoluciones-anulacion/proposal` | #689 |
| `sdd/pago-devoluciones-anulacion/spec` | #690 |
| `sdd/pago-devoluciones-anulacion/design` | #695 |
| `sdd/pago-devoluciones-anulacion/tasks` | #700 |
| verify report (round 2) | #710 |

`apply-progress` was read from the OpenSpec artifact (`openspec/changes/pago-devoluciones-anulacion/apply-progress.md`); no Engram observation exists for it. No `reviewGate` / review ledger / receipt topics exist for this candidate — no review was ever started (kill switch off), so archive proceeds under ordinary repository policy. This report is saved as `sdd/pago-devoluciones-anulacion/archive-report`.
