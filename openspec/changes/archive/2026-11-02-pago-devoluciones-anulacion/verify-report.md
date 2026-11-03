```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:aa26558be530e3ec2ce1a7c84714d5a663a27e065f3d5dee9ad7ff952937db93
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 13/13
scenarios: 41/41
test_command: USER-RUN (agent did not execute): cd backend && composer test:parallel
test_exit_code: 0
test_output_hash: sha256:77006e9c122c7222017b462e3b467fc77eb3ff3014222954af7229041db3b728
build_command: N/A (no build step; PHP/Laravel; Pint not run in this phase)
build_exit_code: 0
build_output_hash: sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
```

# Verification Report

**Change**: `pago-devoluciones-anulacion` (issue #66)
**Version**: delta specs as committed at `4b36597` (re-verification round 2 after the two remediation tests)
**Mode**: Strict TDD (active). **The agent executed no test, no `composer test`, no `php artisan test`, and no Pint.** All runtime evidence is user-reported.
**Worktree**: `/home/gzuz/Documentos/lotto-app-worktrees/pago-devoluciones-anulacion` · branch `feature/pago-devoluciones-anulacion` (13 commits ahead of `main`, clean tree; remediation commit `4b36597` adds only two tests)

## Completeness

| Metric | Value |
|--------|-------|
| Implementation tasks total | 25 |
| Implementation tasks complete | 25 |
| Implementation tasks incomplete | 0 |
| Acceptance-checklist items | 13 (13 met) |
| Spec requirements | 13 (13 fully evidenced) |
| Spec scenarios | 41 (41 compliant) |

## Build & Tests Execution

**Build**: ➖ Not applicable — this project has no build step.

**Tests**: ✅ User-reported GREEN — full parallel suite: **1337 tests, 7726 assertions, 2 skipped, OK**. The agent did not run them.

| Evidence | Command (user-run) | Result |
|---|---|---|
| WU1 focused | `cd backend && php artisan test --filter='ApuestaPolicyAnulacion\|EliminacionApuestas\|TicketEliminacion'` | GREEN (user-reported) |
| WU2 focused | `cd backend && php artisan test --filter='PagoIngresoRechazado\|PagoEgresoUnicoMutante\|PagoTipo\|ApuestaTest'` | GREEN (user-reported) |
| WU3-A focused | `cd backend && php artisan test --filter='PagoDevolucionAnulacion\|EliminacionApuestas'` | GREEN (user-reported) |
| WU3-B focused | `cd backend && php artisan test --filter='PagoDevolucionRechazos\|MetodoPago\|TicketAnuladosVisibles'` | GREEN (user-reported) |
| WU4 focused | `cd backend && php artisan test --filter='CuadreCaja\|CierreCaja\|Estadistica'` | GREEN (user-reported) |
| WU5 focused | `cd backend && php artisan test --filter=AuditarPagosLegacy` | GREEN (user-reported) |
| Combined WU suite | focused filters together | GREEN (user-reported) |
| Remediation focused (round 2) | `cd backend && php artisan test --filter='PagoEgresoUnicoMutante\|PagoDevolucionAnulacion'` | GREEN (user-reported) |
| Full parallel suite | `cd backend && composer test:parallel` | **1337 tests, 7726 assertions, 2 skipped, OK** (user-reported) |

`test_output_hash` above is the SHA-256 of this exact evidence record (the raw command output was not produced by the agent):

```text
user-run GREEN evidence: focused PagoEgresoUnicoMutante|PagoDevolucionAnulacion; composer test:parallel = 1337 tests, 7726 assertions, 2 skipped, OK. Agent executed no tests.
```

**Coverage**: ➖ Not available — no coverage tool was run in this phase.

## Spec Compliance Matrix

Statuses: COMPLIANT = covering test exists and was reported green; PARTIAL = covering test exercises only part of the scenario; UNTESTED = behavior present in code but no covering test.

### `pagos` — 8 requirements / 22 scenarios

| # | Scenario | Test evidence | Result |
|---|---|---|---|
| 1 | ingreso rejected | `PagoIngresoRechazadoTest` (96, 116) | COMPLIANT |
| 2 | devolucion rejected at /pagos | `PagoTipoTest` (80) | COMPLIANT |
| 3 | ganadora paid | `PagoPremioSinMontosTest` (105), `PagoEgresoUnicoMutanteTest` (128) | COMPLIANT |
| 4 | legacy pendiente with resultado paid | `PagoEgresoUnicoMutanteTest` (212), `ApuestaTest` (880) | COMPLIANT |
| 5 | no resultado rejected | `ApuestaTest` (904) | COMPLIANT |
| 6 | zero prize rejected | `PagoEgresoUnicoMutanteTest` (296) | COMPLIANT |
| 7 | amount mismatch rejected | `ApuestaTest` (927), `PagoEgresoUnicoMutanteTest` (242) | COMPLIANT |
| 8 | state, prize, cascade | `PagoEgresoUnicoMutanteTest` (128) | COMPLIANT |
| 9 | concurrent double egreso | `PagoEgresoUnicoMutanteTest` (166, 186) | COMPLIANT |
| 10 | ingreso on ganadora leaves prize intact | `PagoIngresoRechazadoTest` (116), `ApuestaTest` (947) | COMPLIANT |
| 11 | devolucion on ganadora leaves prize intact | `PagoTipoTest` (80), `PagoEgresoUnicoMutanteTest` (128) | COMPLIANT |
| 12 | valid refund annuls and refunds | `PagoDevolucionAnulacionTest` (203, 238, 280) | COMPLIANT |
| 13 | whole-ticket all-or-nothing | `PagoDevolucionAnulacionTest` (440), `PagoDevolucionRechazosTest` (453) | COMPLIANT |
| 14 | rollback on failure (mid-cascade) | `PagoDevolucionAnulacionTest` (490) — failure injected at the final log write | COMPLIANT |
| 15 | after draw | `PagoDevolucionRechazosTest` (326) | COMPLIANT |
| 16 | outside the elimination window | `PagoDevolucionRechazosTest` (342, 347, 352) | COMPLIANT |
| 17 | with resultado_id | `PagoDevolucionRechazosTest` (302) | COMPLIANT |
| 18 | non-refundable states | `PagoDevolucionRechazosTest` (222, 238, 254, 270, 286) | COMPLIANT |
| 19 | unknown ticket | `PagoDevolucionRechazosTest` (399) | COMPLIANT |
| 20 | foreign taquilla denied | `ApuestaPolicyAnulacionTest` (257), `PagoDevolucionRechazosTest` (435) | COMPLIANT |
| 21 | ancestor allowed | `ApuestaPolicyAnulacionTest` (243), `EliminacionApuestasTest` (304) | COMPLIANT |
| 22 | refund does not mark paid | `PagoDevolucionAnulacionTest` (308) | COMPLIANT |

### `reportes-agencia` — 1 / 5

| Scenario | Test evidence | Result |
|---|---|---|
| same-window pair nets to zero | `CuadreCajaReportTest` (648) | COMPLIANT |
| cross-window refund stays an outflow | `CuadreCajaReportTest` (704) | COMPLIANT |
| untied refund still counts | `CuadreCajaReportTest` (733) | COMPLIANT |
| timeSeriesData saldo nets to zero | `EstadisticaTest` (245) | COMPLIANT |
| annulled sale with no refund | `CuadreCajaReportTest` (758) | COMPLIANT |

### `cierre-caja` — 1 / 3

| Scenario | Test evidence | Result |
|---|---|---|
| pair nets in cierre | `CierreCajaTest` (1678) | COMPLIANT |
| desglose reconciles | `CierreCajaTest` (1750) | COMPLIANT |
| cross-window refund stays | `CierreCajaTest` (1827) | COMPLIANT |

### `metodo-pago` — 1 / 6

| Scenario | Test evidence | Result |
|---|---|---|
| Pago de premio con método | `MetodoPagoTest` (174) | COMPLIANT |
| Premio USD | `MetodoPagoTest` (200) | COMPLIANT |
| Método inválido (egreso) | `MetodoPagoTest` (257) | COMPLIANT |
| Refund mirrors sale method | `MetodoPagoTest` (308) | COMPLIANT |
| Refund override allowed | `MetodoPagoTest` (328) | COMPLIANT |
| Refund USD forced efectivo | `MetodoPagoTest` (350) | COMPLIANT |
| (extra) Refund invalid method → 422 | `MetodoPagoTest` (374) | COMPLIANT |

### `colecciones-api` — 2 / 5

| Scenario | Test evidence | Result |
|---|---|---|
| Límites sin campos retirados | collection unchanged (`git diff main...HEAD` shows no límites edits) | COMPLIANT (static) |
| Pago autoritativo (egreso) | `Registrar Pago (Token required).yml` docs + `PagoPremioSinMontosTest` | COMPLIANT (static + runtime) |
| Multi-selección | collection unchanged | COMPLIANT (static) |
| ingreso/devolucion rejected | `Registrar Pago (Token required).yml` (422 messages documented) | COMPLIANT (static) |
| annulment documented | `Tickets/Anular Ticket.yml` (200 shape, 403/404/422) | COMPLIANT (static) |

Note: `colecciones-api` is a documentation capability; its scenarios are verified by inspecting the committed collections, which is the correct evidence class for doc-only contracts.

**Compliance summary**: 41/41 scenarios compliant; 13/13 requirements fully evidenced.

## Correctness (Static Evidence)

| Requirement / invariant | Status | Evidence |
|---|---|---|
| `POST /pagos` egreso-only, rejection before any load/transaction/write | ✅ | `PagoController.php:64-78` — type branch returns 422 after validation, before `$user`/`DB::transaction`; no apuesta load, no writes |
| Egreso atomic + row lock | ✅ | `PagoController.php:83-88` (`DB::transaction` + `Apuesta::with(...)->lockForUpdate()->find()`), all checks 90-148, writes 151-222 inside the closure |
| Engine-authoritative amounts | ✅ | `PagoController.php:118`, `135-148` (tolerance only), `151-167` (persist engine values); `detalle` re-asserted 173-179 |
| Response `premio` | ✅ | `PagoController.php:236-239`; pinned by `PagoPremioSinMontosTest:105` |
| Cascade hardening (trashed/`anulada` skipped, `isNotEmpty()`) | ✅ | `PagoController.php:185-204`; tests `PagoEgresoUnicoMutanteTest:258,273,296` |
| Refund = ticket-level annulment only | ✅ | `TicketController.php:236-274` delegates to `TicketAnulacionService`; no other writer of `pagos.tipo=devolucion` |
| One `devolucion` `Pago` per live line, sale mirror | ✅ | `TicketAnulacionService.php:91-100`, `261-285` (`amount_bs/usd`, `exchange_rate_applied`, `moneda` derived, method resolution 270/280) |
| `anulada` + soft delete for apuestas and ticket | ✅ | `TicketAnulacionService.php:311-315`, loop 91-102; test `PagoDevolucionAnulacionTest:280` |
| `premio_ganado(_usd)` untouched by refund | ✅ | No write path in the service; test `PagoDevolucionAnulacionTest:308` |
| One transaction, all-or-nothing | ✅ | `TicketAnulacionService.php:52`, validation before first write `78-83`; tests `PagoDevolucionAnulacionTest:440`, `PagoDevolucionRechazosTest:453` |
| 404 / 422 / 403 mapping | ✅ | `TicketAnulacionException.php:25-39`; service `57-66`, `74-76`, `201-208`; controller `256-261` |
| `DELETE /apuestas` ticketless-only | ✅ | `ApuestaController.php:276-328`; service `145-165`; tests `PagoDevolucionRechazosTest:470,512,556` |
| Cross-tenant 403 before redirect | ✅ | `ApuestaController.php:278-285` (`authorize('view')` first); test `PagoDevolucionRechazosTest:535` |
| Policy codes/order/boundary | ✅ | `ApuestaPolicy.php:73-98`; constants 12-24; `>=` boundary 89; unit tests 137-343 |
| Ownership per role | ✅ | `ApuestaPolicy.php:104-124`; unit tests 243-292 |
| Reports reversal-pair exclusion | ✅ | `ReversalPairQuery.php:29-60`; `ApuestaService.php:965-967`, `1216`; `CierreService.php:259-292` |
| `desglose_metodos` reconciliation | ✅ | `CierreService.php:245-253` unchanged (AD-7), same filtered egresos query feeds totals and breakdown 274-282; tests `CierreCajaTest:1750` |
| WU5 command gated, zero writes by default | ✅ | `AuditarPagosLegacy.php:40-45` signature, `55-91` gating (`--apply`/`--revert` mutually exclusive, `--ids` required), dry-run 94+; tests 194, 258, 363, 400 |
| WU5 never touches `pagos.amount_*`, `cierres_caja`, `comisiones` | ✅ | Only `apuesta`/`detalle`/`ticket` writes (`AuditarPagosLegacy.php:440-452`, revert 664-681); assertions in `AuditarPagosLegacyTest:272,414` |
| No migration / no route change | ✅ | `git diff main...HEAD --name-only` has no `database/`, `routes/`, or `composer.*` entries |

## Coherence (Design)

| Decision | Followed | Notes |
|---|---|---|
| AD-1 `TicketAnulacionService` owns the transition | ✅ | `TicketAnulacionService.php`; controllers are HTTP-only |
| AD-2 reject non-egreso after validation | ✅ | `PagoController.php:56-78` |
| AD-3 persist engine amounts | ✅ | `PagoController.php:151-179` |
| AD-4 `DELETE /apuestas` ticketless-only | ✅ | Service redirect 145-149; controller order 279 |
| AD-5 policy returns codes, one message mapper | ✅ | `ApuestaPolicy::motivoNoAnulable`; `TicketAnulacionService::mensajeRechazo:321-329` |
| AD-6 window-aware `whereNotExists` | ✅ | `ReversalPairQuery` + both services |
| AD-7 `desglose_metodos` untouched, regression assertions | ✅ | `CierreService.php:245-253`; test 1750 |
| AD-8 gated legacy repair | ✅ | Command off by default, `--ids` mandatory |
| AD-9 receipt/reprint deferred | ✅ | `PagoController::showByApuesta:257-267` unchanged |
| D8 / D9 / D10 / AD-4 / AD-9 owner-approved | ✅ (recorded here) | Owner approval is asserted by the orchestrator; the design header/§16 still say "under owner review" (see S3) |
| Design §7.2 `DELETE /tickets` response shape | ✅ | `TicketController.php:263-273` (additive fields only) |
| Design §7.6 audit log contract | ✅ | ticket service 104-112, 337-343; egreso `PagoController.php:207-222` |
| Design §8 lock design | ✅ | Ticket re-fetch 55, lines 69-72, ticketless 139 |
| Design §10.1 C1-C5 queries | ✅ | `AuditarPagosLegacy` dry-run queries |
| WU5 test placement `tests/Feature` instead of `tests/Unit` | ⚠️ Documented deviation | Justified in `tasks.md:193-197` and `apply-progress.md:337-340`; does not break a spec |
| Design §7.5 internal service return shape | ⚠️ Minor drift | Returns `{ticket_id, apuestas[], apuestas_count, devoluciones[], devoluciones_count, total_*}`; HTTP contract §7.2 matches. Doc drift only |

## TDD Compliance (Strict TDD active)

| Check | Result | Details |
|---|---|---|
| TDD evidence reported | ✅ | `apply-progress.md` has per-WU TDD evidence tables (WU2 69-75, WU3-A 136-142, WU3-B 198-203, WU4 267-272, WU5 344-349) |
| All tasks have tests | ✅ | 25/25 implementation tasks map to existing test files (verified on disk) |
| RED confirmed (tests exist) | ✅ | Every RED test named in apply-progress exists (`ApuestaPolicyAnulacionTest`, `PagoIngresoRechazadoTest`, `PagoEgresoUnicoMutanteTest`, `PagoDevolucionAnulacionTest`, `PagoDevolucionRechazosTest`, `AuditarPagosLegacyTest`, plus the modified suites) |
| GREEN confirmed (tests pass) | ✅ (user-reported) | Agent executed nothing; the user reported the focused, combined, full-parallel and round-2 remediation runs GREEN (full suite: 1337 tests, 7726 assertions, 2 skipped, OK) |
| Triangulation adequate | ✅ | The riskiest invariants are triangulated (5/10/15-minute boundaries, 5 non-refundable states, 3 cascade branches, same-window/cross-window/untied pairs, BS/USD breakdown) |
| Safety net for modified files | ⚠️ | `apply-progress` tables have no SAFETY NET column; existing suites were re-run in the combined run, so evidence is indirect |
| Apply-progress GREEN columns | ⚠️ | TDD tables still read `pending` for WU3-A/B, WU4, WU5 and `user-run pending` in the WU2 evidence row even though `tasks.md` and the orchestrator report GREEN; the columns were never refreshed |

**TDD compliance**: 5/7 checks fully passed, 2 warnings (bookkeeping only).

### Test Layer Distribution

| Layer | Tests | Files | Tools |
|---|---|---|---|
| Unit | 17 | 1 (`tests/Unit/ApuestaPolicyAnulacionTest.php`) | PHPUnit |
| Integration (HTTP feature) | 179 methods across the change's feature files | 13 | PHPUnit + Laravel HTTP kernel + MySQL `lotto_test` |
| E2E | 0 | 0 | not installed for backend |
| **Total** | **196** | **14** | |

Critical business logic is exercised at the HTTP layer, not only unit level, which is the right layer for this change. `taquilla/` has Playwright-style helpers (`taquilla/e2e/`) but no E2E spec was added for the annulment flow; the endpoint contract is covered by feature tests.

### Changed File Coverage

Coverage analysis skipped — no coverage tool was run in this phase (agent forbidden from executing tests).

### Assertion Quality

Sampled the new/modified suites for tautologies, ghost loops, type-only assertions, and smoke-test-only cases. Findings:

- No tautologies (`assertTrue(true)`) found.
- No ghost loops: the loops that exist (`foreach ($apuestas as $apuesta)` in `PagoDevolucionAnulacionTest` around 280/440, `foreach ($casos ...)` in `ApuestaPolicyAnulacionTest:257`) iterate over fixtures created in the same test and, in the multi-assert loops, the collection is asserted non-empty elsewhere (e.g., `assertJsonCount(1, 'data.devoluciones')`, `assertCount(2, ...)`).
- Assertions verify behavior and persisted state (`assertDatabaseHas`, `assertSoftDeleted`, `assertJsonPath` on exact messages, `premio_ganado` values), not implementation details.
- One weak spot: `PagoEgresoUnicoMutanteTest:136` asserts only the status for the `devolucion` rejection; the wu-level no-write assertions follow in the same test (137-145), so the scenario is still proven.

The two round-2 tests were audited as well: the zero-prize test asserts the exact 422 message plus four negative database facts; the rollback test asserts the injected exception and six post-rollback facts (zero refunds, two states, two soft-delete flags, zero logs). No tautology, no type-only assertion, no ghost loop.

**Assertion quality**: ✅ No trivial assertions found (0 critical, 0 warning).

### Quality Metrics

**Linter**: ➖ Not run (Pint was explicitly excluded from this phase; the user must run `cd backend && vendor/bin/pint --test` as the CI style gate).
**Type Checker**: ➖ Not available (PHP project; no static analyser configured).

## Issues Found

### BLOCKERS — CLOSED IN ROUND 2

Round 1 raised two evidence-completeness blockers (no behavioral defect). Both were closed by commit `4b36597` (`test(pagos): cubre premio cero y rollback de anulación (verify)`, two test files, +61 lines, no production change).

**B1 (closed) — `zero prize rejected` had no covering test (spec `pagos`, "Egreso prize payment requirements", scenario 6).**
Covered now by `PagoEgresoUnicoMutanteTest::test_egreso_con_premio_cero_rechaza_sin_escrituras` (`tests/Feature/PagoEgresoUnicoMutanteTest.php:296`). It builds a `ganadora` apuesta whose result does not match the combination (engine prize 0), posts `tipo=egreso`, and asserts 422 with `La apuesta no resultó ganadora. No puede pagarse un premio.`, `estado` still `ganadora`, `detalle.premio_ganado` still NULL, zero `pagos`, and zero `pago_premio` logs — matching `PagoController.php:120-125`.

**B2 (closed) — `rollback on failure` (mid-cascade) was only partially evidenced (spec `pagos`, "Pre-draw refund is a ticket-level annulment + refund", scenario 3).**
Covered now by `PagoDevolucionAnulacionTest::test_rollback_si_falla_el_log_no_deja_devolucion_ni_anulacion` (`tests/Feature/PagoDevolucionAnulacionTest.php:490`). A `Log::creating` listener throws on the **last** write of the transaction, after the `devolucion` insert, both `anulada` updates and both soft deletes. (`registrarLog` is the only `Log::create` in that request path — the controller validates and delegates, and no middleware writes `Log` — so the injected failure cannot fire earlier and make the test vacuous.) The test asserts the exception propagates, then verifies zero `devolucion` rows, apuesta and ticket still `pendiente`, neither soft-deleted, and no `ticket_anulado` log — exactly the spec's mid-cascade rollback, not the pre-write validation path.

Both new tests assert persisted behavior (exact message, states, soft-delete flags, row counts), not tautologies. Neither contradicts an implemented behavior, and neither is a regression from `main`.

### WARNINGS

**W3 — `agencia` cannot refund a ticket even though the spec says subtree roles are allowed.**
`routes/api.php:174-181` excludes `agencia` from the tickets group, so the ticket-annulment policy branch for `agencia` (`ApuestaPolicy.php:110-113`) is unreachable via `DELETE /tickets/{id}`. The spec scenario "ancestor allowed" names `banca`/`grupo`, which pass, and the design/collections explicitly document "sin agencia" (`design.md:300`, `collections/Tickets/Anular Ticket.yml`), so this is a pre-existing route restriction, not a defect introduced here. Severity: spec-text vs route mismatch; low impact.

**W4 — Stale SDD bookkeeping.** `apply-progress.md` still carries `pending` GREEN columns for WU3-A/WU3-B/WU4/WU5 and "user-run pending" in the WU2 evidence table; WU3-B/WU4/WU5 notes still say "changes intentionally left uncommitted" while all five WUs are committed. `tasks.md` acceptance checklist is entirely unchecked. This does not affect behavior but will make the archive record misleading unless refreshed at archive time. Evidence: `apply-progress.md:81,138-142,148,200-203,209,269-272,278,347,355`; `tasks.md:218-230`.

**W5 — `docs/dev/motor-cierres-comisiones.md` is an add/add conflict on rebase.**
The merge base is `6feb1fe`, which predates the doc's addition on `main`; this branch adds it at `3ffbb37` and `main` also contains it. The contents differ by +87/−8 lines (the WU4 sections and the Alta #2 rewrite). Rebasing onto current `main` will produce an add/add conflict; the branch version is a strict superset of `main`'s content (the 8 deletions are rewrites inside the same three blocks), so resolution is "take the branch file". Severity: operational, trivially resolvable. Evidence: `git merge-base main HEAD` = `6feb1fe`; `git diff main:docs/dev/... HEAD:docs/dev/... --stat` = 87 insertions, 8 deletions.

**W6 — Size exceptions (owner-approved) are larger than planned.**
WU3-A ≈1.1k changed lines vs 600–660 forecast; WU3-B ≈977 vs 320–400; WU5 above its 310–360 forecast; WU1 above the 400-line inner target. All are recorded as owner-approved `size:exception` in `tasks.md:122,150,212` and `apply-progress`. They remain a review-load risk for the chained PRs, not a correctness risk.

**W7 — `POST /pagos` egreso ownership is still taquilla-only.**
`PagoController.php:98-100` only checks the `taquilla` role; `banca`/`grupo`/`master` can pay an apuesta outside their subtree. This is unchanged from `main` and design §7.4 says "unchanged", but proposal D13's parenthetical ("tightens cross-tenant POST /pagos") overstates what shipped. Severity: pre-existing scope gap, out of the delta specs' ownership requirement (which is refund-only).

### SUGGESTIONS

**S1 — Done (no action).** The two round-1 remediation tests landed in `4b36597`; B1/B2 are closed.
**S2 — Eager-load `taquilla` on the locked lines** in `anularTicket` (`TicketAnulacionService.php:69-72`) to avoid one lazy load per line in `poseeApuesta`; tickets are small, so this is only polish.
**S3 — Record the owner approvals in the change artifacts.** `design.md:5` and §16 still say D8/D9/D10/AD-4/AD-9 are "under owner review" although the owner approved them; the archive note should capture the approval source.
**S4 — Decide the `detalle_apuestas`-missing case on egreso.** `PagoController.php:173-179` silently skips prize evidence when no `detalle` exists; the WU5 audit treats that as manual review. A defensive 422 or an explicit log would make the invariant observable.
**S5 — Refresh the SDD bookkeeping** (W4) before archiving so the report of record matches the commits.

## Explicit Assessments Requested

| Item | Assessment |
|---|---|
| D8 / D9 / D10 / AD-4 / AD-9 owner-approved | Accepted as owner-approved per the orchestrator. Implementation matches all five: egreso-only (`PagoController:69`), ticket-level annulment (`TicketController:236`), window-aware exclusion (`ReversalPairQuery`), ticketless-only `DELETE /apuestas` (`ApuestaController:279`), receipt untouched (`PagoController:257`). Artifacts still show "under review" → S3. |
| WU1 cross-tenant message/status transition (422 → 403 in WU3) | Resolved correctly. WU1 mapped `sin_ownership` to `No autorizado.` at 422; final code maps `sin_ownership` to a 403 exception (`TicketAnulacionService:201-203`, `TicketAnulacionException:31-34`). Final assertions expect 403 (`EliminacionApuestasTest:325`, `PagoDevolucionAnulacionTest:388`). No residual 422 path for ownership on ticket annulment. |
| WU3-A / WU3-B / WU5 size exceptions | Real and owner-approved (W6). WU3-A at ~1.1k lines is the high-water mark; it is one atomic transition (service + exception + controller + tests) that would be hard to split without breaking the all-or-nothing guarantee. Accept, but chained PR #3 should be reviewed as a unit. |
| WU5 gated/off by default; no runtime effect | Verified. No route/console kernel registration invokes it; signature defaults to dry-run (`AuditarPagosLegacy:40-45`), mutation requires `--apply|--revert` plus non-empty `--ids` (`55-91`), and nothing else calls it. Zero runtime effect. |
| AD-9 receipt/reprint deferral | Verified as deferrable. `GET /pagos/{apuesta}` (`PagoController:257-267`) still returns every `Pago` for the apuesta, including the sale `ingreso`; `recibos.ts` sums them (pre-existing inflation). New refund rows cannot reach a reprint because an annulled ticket exposes zero live apuestas (`TicketAnuladosVisiblesTest:71,97`). Follow-up receipt slice still owed. |
| Cross-window / cross-midnight residual | Accepted and documented (`docs/dev/motor-cierres-comisiones.md:305,319`; `design.md:446-451`; test `EstadisticaTest:315`). A pair split across two closures nets −R across periods; the window total still nets zero for cross-midnight inside one window. Schema-level fix (`anulada_at` bucketing) is a separate change. |
| `motor-cierres-comisiones.md` add/add conflict on rebase | Confirmed and bounded (W5). Resolution = keep the branch file. Do this when retargeting WU4's PR onto `main`. |

## Acceptance Checklist Status

| # | Item (`tasks.md:218-230`) | Status | Evidence |
|---|---|---|---|
| 1 | `POST /pagos` accepts only `tipo=egreso`; rejected types 422, no writes | ✅ Met | `PagoController:64-78`; `PagoIngresoRechazadoTest`, `PagoTipoTest:80` |
| 2 | Egreso engine-authoritative; pagable states; 422 no-resultado/zero/mismatch; response `premio` | ✅ Met | `PagoController:104-148,231-240`; `PagoEgresoUnicoMutanteTest:296` (zero prize, no writes) |
| 3 | Egreso atomic + row-locked; double → 1×201, 1×422, 1 row | ✅ Met | `PagoController:83-88`; `PagoEgresoUnicoMutanteTest:166,186` |
| 4 | Refund = ticket-level; one `devolucion` per line; `anulada` + soft delete; prize untouched; one transaction | ✅ Met | `TicketAnulacionService:52,91-102,261-315`; `PagoDevolucionAnulacionTest:203,238,280,308` |
| 5 | All-or-nothing; rollback on mid-cascade failure | ✅ Met | `TicketAnulacionService:52,78-83`; `PagoDevolucionAnulacionTest:440` (pre-write) + `:490` (mid-cascade rollback) |
| 6 | Refund rejected outside pre-draw window | ✅ Met | `PagoDevolucionRechazosTest:222-352` |
| 7 | Ownership reused for all roles; cross-tenant 403 | ✅ Met (agencia route caveat W3) | `ApuestaPolicy:104-124`; `ApuestaPolicyAnulacionTest:243-292`; `EliminacionApuestasTest:304` |
| 8 | RF-05.2/05.3 restated | ✅ Met | `PagoDevolucionAnulacionTest:308` |
| 9 | Refund mirrors sale method; override; USD forced `efectivo` | ✅ Met | `TicketAnulacionService:270,280`; `MetodoPagoTest:308,328,350,374` |
| 10 | `cuadreCaja`/`timeSeriesData` reversal-pair; same-window nets 0; cross-window + untied stay | ✅ Met | `ReversalPairQuery`; `CuadreCajaReportTest:648,704,733`; `EstadisticaTest:245` |
| 11 | Cierre pair excluded; `total_efectivo_*` unchanged; `desglose_metodos` reconciles | ✅ Met | `CierreService:259-292`; `CierreCajaTest:1678,1750,1827` |
| 12 | Collections document egreso-only + annulment/refund + 422/403/404 | ✅ Met | `collections/Pagos/Registrar Pago (Token required).yml`; `collections/Tickets/Anular Ticket.yml`; `collections/Apuestas/Eliminar Apuesta (Token required - 5 min rule).yml` |
| 13 | Existing annulment strings byte-identical | ✅ Met | `TicketAnulacionService:321-329`; `EliminacionApuestasTest:248,263,281`; `TicketEliminacionTest` |

## Residual Risks

1. **Cross-window / cross-period pair** nets `−R` across closings when the sale belongs to an already-closed period (accepted, documented; fix needs `anulada_at` bucketing).
2. **Cross-midnight per-day attribution** is wrong even though the window total nets zero (documented; `EstadisticaTest:315`).
3. **`cuadreCaja` taquilla visibility** (R11): `taquillaIds` comes from the sales query, so a taquilla with only refunds in the window is invisible.
4. **`desglose_metodos` depends on the sale `ingreso` row** (finding #8): a legacy duplicate/missing ingreso still skews the breakdown.
5. **Concurrency evidence is sequential** (R6): the lock is proven by the `for update` query-log assertion, not by a real race; MySQL current-read semantics are the actual guarantee.
6. **Refund is irreversible** (R3): no un-annul endpoint by design; rollback requires a data fix.
7. **Receipt/reprint inflation** (AD-9) is deferred; refund rows cannot reach it today, but any future change that exposes annulled lines to reprints would.
8. **WU5 `--apply` is a production data mutation** gated only by operator discipline: it requires `--ids` and owner approval, but nothing prevents an operator from passing approved-looking ids without a backup. The runbook mandates the backup first (`docs/dev/runbook-ops.md`).

## Verdict

**PASS WITH WARNINGS**

All five critical invariants are implemented and verified against code and the user-reported green suite: `POST /pagos` is egreso-only with atomic/locked engine-authoritative payment; refunds are ticket-level pre-draw annulments with one mirrored `devolucion` per line and untouched prize evidence; `DELETE /apuestas` is ticketless-only with 403-before-422; reports and the cierre exclude the reversal pair while keeping genuine outflows; policy codes/messages, ownership, and byte-identical strings hold; no migration or unrelated writes exist.

The two blockers from round 1 are closed by commit `4b36597`, which adds exactly two tests and nothing else:

- zero-prize egreso rejection (`PagoEgresoUnicoMutanteTest:296`): 422, exact message, no estado/prize/`pagos`/log writes;
- mid-cascade rollback (`PagoDevolucionAnulacionTest:490`): a failure injected at the final log write rolls back the prior refund insert, both `anulada` updates and both soft deletes, leaving no `ticket_anulado` log.

Evidence is now 41/41 scenarios and 13/13 requirements, with the full parallel suite reported as **1337 tests, 7726 assertions, 2 skipped, OK**.

The verdict remains `PASS WITH WARNINGS` (not a clean `pass`) for accepted, non-blocking items only: the pre-existing `agencia` route restriction on ticket annulment (W3), stale SDD bookkeeping that must be refreshed at archive time (W4), the rebase-time add/add doc conflict (W5), the owner-approved size exceptions (W6), and the unchanged taquilla-only egreso ownership scope (W7). None affects the delta specs' observable contracts.

**Note**: this verification agent did not run any test, `composer test`, `php artisan test`, or Pint command. Every runtime result in this report is user-reported evidence.
