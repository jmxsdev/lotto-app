# Tasks: Pago, devoluciones y anulación

Change: `pago-devoluciones-anulacion` · Issue #66 · Phase: tasks (ready for apply)
Strict TDD: **active**. The agent MUST NOT run any test; the user runs every command.

## Review Workload Forecast

```text
Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: High
```

| Field | Value |
|-------|-------|
| Estimated changed lines | ~2.3k–2.7k total (additions + deletions), 6 slices |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Chain strategy | feature-branch-chain (tracker `feature/pago-devoluciones-anulacion`) |
| Delivery strategy | auto-chain |
| Decision needed before apply | No (auto-chain + feature-branch-chain approved) |

| Slice | WU | Est. lines | Base branch | Depends on |
|-------|-----|-----------|-------------|-----------|
| PR #1 | WU1 policy reason extraction | 280–320 | tracker | — |
| PR #2 | WU2 egreso-only | 560–650 | tracker (parallel to WU1) | — |
| PR #3 | WU3-A annulment service | 600–660 | PR #1 branch | WU1 |
| PR #4 | WU3-B endpoint restrictions | 320–400 | PR #3 branch | WU3-A |
| PR #5 | WU4 reports reversal pair | 340–390 | PR #4 branch | WU3-A/WU3-B |
| PR #6 | WU5 legacy audit (gated) | 310–360 | PR #5 branch | WU2/WU3 |

Release gate: **WU3-A + WU3-B + WU4 = one release unit**; never merge WU3 without WU4. WU5 is a separate owner-gated ops slice.

---

## Strict TDD checkpoints (per WU)

Every WU follows: (1) agent writes the RED test file(s) and STOPS, asking the user to run the RED command; (2) user reports RED; (3) agent implements and STOPS, asking the user to run the GREEN command; (4) user reports GREEN; (5) agent refactors, user re-runs. The agent never executes tests.

---

## WU1 — `ApuestaPolicy` reason extraction (behavior-neutral)

Files:
- `backend/app/Policies/ApuestaPolicy.php` (modify)
- `backend/app/Http/Controllers/Api/TicketController.php` (modify)
- `backend/tests/Unit/ApuestaPolicyAnulacionTest.php` (create)
- `backend/tests/Feature/EliminacionApuestasTest.php` (modify)
- `backend/tests/Feature/TicketEliminacionTest.php` (modify)

RED — write first:
- `backend/tests/Unit/ApuestaPolicyAnulacionTest.php` (codes, order, boundary `>=`, hierarchy taquilla→grupo→banca→5, ownership per role, optional `$minutosEfectivos`)
- Update `backend/tests/Feature/EliminacionApuestasTest.php` + `TicketEliminacionTest.php` to assert the mapped messages come from the extracted policy (same byte-identical strings).
- Command: `cd backend && php artisan test --filter='ApuestaPolicyAnulacion|EliminacionApuestas|TicketEliminacion'` → expect RED.

Implementation tasks:
- [x] 1.1 Add `motivoNoAnulable(User $user, Apuesta $apuesta, ?int $minutosEfectivos = null): ?string` to `ApuestaPolicy` with codes `estado`, `con_resultado`, `sorteo_ocurrido`, `fuera_de_ventana`, `sin_ownership`; add code constants; rule order unchanged.
- [x] 1.2 Delegate `delete()` to `return $this->motivoNoAnulable($user, $apuesta) === null;`.
- [x] 1.3 In `TicketController::destroy`, replace the per-line `Gate::denies` + re-derived-message block (`:234-250`) with one `motivoNoAnulable` call + private `mensajeRechazo(code, minutos)`; reuse already-fetched `minutosEfectivos`.
- [x] 1.4 Preserve exact strings: `fuera_de_ventana` → `El ticket excedió los {N} minutos para ser anulado.`; `sorteo_ocurrido` → `El sorteo de una o mas jugadas ya ocurrio, no se puede anular.`; `estado`/`con_resultado` → `No se puede anular el ticket.`; `sin_ownership` → `No autorizado.`

GREEN — `cd backend && php artisan test --filter='ApuestaPolicyAnulacion|EliminacionApuestas|TicketEliminacion'` → GREEN.
Rollback: revert `ApuestaPolicy` + `TicketController`; no data effect.

---

## WU2 — `PagoController` egreso-only

Files:
- `backend/app/Http/Controllers/Api/PagoController.php` (modify)
- `backend/tests/Feature/PagoTipoTest.php` (rewrite)
- `backend/tests/Feature/PagoIngresoRechazadoTest.php` (create)
- `backend/tests/Feature/PagoEgresoUnicoMutanteTest.php` (create)
- `backend/tests/Feature/ApuestaTest.php` (modify)
- `collections/Pagos/Registrar Pago (Token required).yml` (modify)

RED — write first:
- `PagoIngresoRechazadoTest.php` (422 on `pendiente`/`ganadora`, zero `Pago`, no state/prize/cascade)
- `PagoEgresoUnicoMutanteTest.php` (only egreso mutates; sequential double egreso → one 201 + one 422 + one row; `DB::listen` sees `for update`; detail uses engine value)
- Rewrite `PagoTipoTest.php` (`devolucion` via `/pagos` → 422 no writes); add `ApuestaTest` `ingreso`-on-`ganadora` keeps `premio_ganado`.
- Command: `cd backend && php artisan test --filter='PagoIngresoRechazado|PagoEgresoUnicoMutante|PagoTipo|ApuestaTest'` → expect RED.

Implementation tasks:
- [ ] 2.1 Add early non-egreso rejection in `PagoController::store` immediately after input validation: `ingreso` → 422 `El ingreso se registra automáticamente al vender; no se admite por este endpoint.`; `devolucion` → 422 `Las devoluciones se registran anulando el ticket: use DELETE /api/v1/tickets/{id}.` (no apuesta load, no writes).
- [ ] 2.2 Wrap the egreso path in `DB::transaction` with `Apuesta::lockForUpdate()->find($id)`; re-check existence, ownership (taquilla), `estado ∈ {pendiente,ganadora}`, `resultado_id`, engine prize, ±0.01 tolerance inside the lock.
- [ ] 2.3 Persist engine-authoritative amounts (`Pago` amounts + `detalle_apuestas.premio_ganado(_usd)` = engine); request amounts are tolerance-only; return `premio` in the 201 response.
- [ ] 2.4 Harden the ticket cascade: skip trashed/`anulada` tickets and require `apuestas->isNotEmpty()` before the `every()` check.
- [ ] 2.5 Keep `pago_premio` audit log for egreso only; update class docblock; update `collections/Pagos/Registrar Pago (Token required).yml` (egreso-only, 422 messages, `premio` field).

GREEN — `cd backend && php artisan test --filter='PagoIngresoRechazado|PagoEgresoUnicoMutante|PagoTipo|ApuestaTest'` → GREEN.
Rollback: revert `PagoController` + collection; no data effect if no refunds exist yet.

---

## WU3-A — `TicketAnulacionService` (annulment + refund)

Files:
- `backend/app/Services/TicketAnulacionService.php` (create)
- `backend/app/Exceptions/TicketAnulacionException.php` (create)
- `backend/app/Http/Controllers/Api/TicketController.php` (modify)
- `backend/tests/Feature/PagoDevolucionAnulacionTest.php` (create)
- `backend/tests/Feature/EliminacionApuestasTest.php` (modify)

RED — write first:
- `PagoDevolucionAnulacionTest.php` (refund mirrors sale amounts/rate/method; apuestas+ticket `anulada` + soft-deleted; `premio_ganado` untouched; `ticket_anulado` log; retry 404; ownership 403; empty ticket 422; all-or-nothing; ticketless refund)
- Update `EliminacionApuestasTest.php` (a `devolucion` row now exists after `DELETE /tickets`).
- Command: `cd backend && php artisan test --filter=PagoDevolucionAnulacion` → expect RED.

Implementation tasks:
- [ ] 3.1 Create `TicketAnulacionException` (extends `RuntimeException`) with `public readonly int $status` (403/404/422) and `?string $motivo`; named constructors.
- [ ] 3.2 Create `TicketAnulacionService` (ctor `ApuestaService`, `ApuestaPolicy`): `anularTicket(Ticket, User, array $opciones): array`; `anularApuestaSuelta(Apuesta, User, array $opciones): Pago`; private `assertAnulable`, `metodosVenta`, `registrarDevolucion`, `marcarAnulada`, `mensajeRechazo`, `registrarLog`.
- [ ] 3.3 `anularTicket`: lock ticket re-fetched without soft-delete scope (`Ticket::whereKey($id)->lockForUpdate()->first()`), lock live apuestas `orderBy('id')`; null → 404; `estado !== 'pendiente'` → 422; empty live lines → 422; `minutosEfectivos` + `motivoNoAnulable` per line; one `devolucion` `Pago` per line mirroring sale (`amount_bs/usd`, `exchange_rate_applied`, `metodo_pago` mirror sale `ingreso` else `efectivo`, `moneda` derived); set line + ticket `anulada` + soft delete; write `ticket_anulado` log; all inside one `DB::transaction`.
- [ ] 3.4 `TicketController::destroy`: replace whole body with `TicketAnulacionService::anularTicket` + exception mapping; return 200 shape `{message, data:{ticket_id, apuestas, devoluciones, total_bs, total_usd}}`.
- [ ] 3.5 `anularApuestaSuelta` for ticketless lines (used by WU3-B): policy + `ticket_id === null` + `devolucion` + `anulada` + soft delete + `apuesta.anulada` log.

GREEN — `cd backend && php artisan test --filter=PagoDevolucionAnulacion` → GREEN.
Rollback: revert service + controller; safe only if no production refunds recorded.

---

## WU3-B — endpoint restriction / rejection matrix

Files:
- `backend/app/Http/Controllers/Api/ApuestaController.php` (modify)
- `backend/tests/Feature/PagoDevolucionRechazosTest.php` (create)
- `backend/tests/Feature/MetodoPagoTest.php` (modify)
- `backend/tests/Feature/TicketAnuladosVisiblesTest.php` (modify)
- `collections/Tickets/Anular Ticket.yml` (modify)
- `collections/Apuestas/Eliminar Apuesta (Token required - 5 min rule).yml` (modify)

RED — write first:
- `PagoDevolucionRechazosTest.php` (one case per rejection reason + boundary with `Carbon::setTestNow`; dynamic minutes 5/10/15; cross-tenant 403; unknown ticket 404; zero-live-lines 422; one bad line → whole ticket untouched; `DELETE /apuestas` ticketless 200 / ticket-line 422 / cross-tenant 403)
- `MetodoPagoTest.php` (refund mirrors sale method; override; USD forced `efectivo`; invalid override 422)
- `TicketAnuladosVisiblesTest.php` (annulment keeps ticket visible; zero live apuestas)
- Command: `cd backend && php artisan test --filter='PagoDevolucionRechazos|MetodoPago|TicketAnuladosVisibles'` → expect RED.

Implementation tasks:
- [ ] 4.1 `ApuestaController::destroy`: `authorize('view', $apuesta)` first (tree ownership → 403 cross-tenant); validate optional `metodo_pago`/`referencia`/`motivo`; `ticket_id !== null` → 422 `La anulación se realiza a nivel de ticket: use DELETE /api/v1/tickets/{ticket_id}.` (no writes); else delegate to `TicketAnulacionService::anularApuestaSuelta`; drop `authorize('delete')`.
- [ ] 4.2 Complete rejection matrix per design §7.4 (error statuses/messages), mirroring sale `metodo_pago` with override, USD forced `efectivo` via `Pago::resolverMetodoPago`.
- [ ] 4.3 Update both collections (annulment + refund semantics, 422/403/404 codes, ticketless-only redirect).

GREEN — `cd backend && php artisan test --filter='PagoDevolucionRechazos|MetodoPago|TicketAnuladosVisibles'` → GREEN.
Rollback: revert `ApuestaController` + collections; leaves ticket refunds working but restores line-level soft delete (undesirable).

---

## WU4 — reports reversal-pair exclusion (release-gated with WU3)

Files:
- `backend/app/Support/ReversalPairQuery.php` (create)
- `backend/app/Services/ApuestaService.php` (modify)
- `backend/app/Services/CierreService.php` (modify)
- `backend/tests/Feature/CuadreCajaReportTest.php` (modify)
- `backend/tests/Feature/CierreCajaTest.php` (modify)
- `backend/tests/Feature/EstadisticaTest.php` (modify)
- `docs/dev/motor-cierres-comisiones.md` (modify)

RED — write first:
- `CuadreCajaReportTest.php` (same-window pair → `Venta=0, Devoluciones=0, Efectivo=0`; cross-window refund counts; untied refund counts; annulled sale no refund → no regression)
- `CierreCajaTest.php` (pair excluded from totals; `total_efectivo_*` unchanged; `desglose_metodos` sums = `total_ventas_*`; cross-window refund stays)
- `EstadisticaTest.php` (same-day pair → `ventas 0, devolucion 0, saldo 0`; six series keys unchanged; cross-midnight residual documented)
- Command: `cd backend && php artisan test --filter='CuadreCaja|CierreCaja|Estadistica'` → expect RED.

Implementation tasks:
- [ ] 5.1 Create `ReversalPairQuery::aplicar(Builder, $desde, $hasta, bool $halfOpen = false): Builder` — the `whereNotExists` predicate (`from('apuestas as a_sale')`, `estado='anulada'` or `deleted_at` not null, sale `fecha_hora` window; `whereDate` vs half-open `>=`/`<`).
- [ ] 5.2 `ApuestaService::pagosCuadrePorNivel` + `timeSeriesData` step 5: apply predicate only when `$tipo === 'devolucion'`; `Efectivo`/`saldo` arithmetic unchanged.
- [ ] 5.3 `CierreService::calcularTotales`: replace `whereIn('tipo',['egreso','devolucion'])` with grouped `egreso OR (devolucion AND NOT EXISTS sale-in-window)` using half-open `[fechaInicio, fechaFin)`; keep `$ventasDesglose` unchanged (AD-7).
- [ ] 5.4 Document reversal-pair rule + cross-window/cross-midnight residual in `docs/dev/motor-cierres-comisiones.md`; mark Alta #2 fixed.

GREEN — `cd backend && php artisan test --filter='CuadreCaja|CierreCaja|Estadistica'` → GREEN.
Rollback: **must not ship without WU3-A/B**; reverting WU4 alone reintroduces `Efectivo = −R`.

---

## WU5 (gated) — legacy audit

Files:
- `backend/app/Services/PremiosLiquidador.php` (create — extract `calcularPremio`/`esMultiSorteo`)
- `backend/app/Console/Commands/AuditarPagosLegacy.php` (create)
- `backend/tests/Unit/AuditarPagosLegacyTest.php` (create)
- `docs/dev/runbook-ops.md` (modify)

RED — write first:
- `AuditarPagosLegacyTest.php` (C1-C5 dry-run output; zero writes by default; `--apply` refuses without explicit `--ids`; `--revert` reads `old_values` from `logs`)
- Command: `cd backend && php artisan test --filter=AuditarPagosLegacy` → expect RED.

Implementation tasks (owner-gated; only after dry-run review):
- [ ] 6.1 Extract `calcularPremio` + `esMultiSorteo` from `PagoController` into `PremiosLiquidador` (behavior-neutral; controller delegates).
- [ ] 6.2 Create `pagos:auditar-legacy` command: `--dry-run` (queries grouped by taquilla + CSV, zero writes), `--apply --ids=` (explicit ids only: recompute prize, `estado` → `ganadora`/`perdida`, restore `premio_ganado`, recompute ticket estado, keep `pagos` rows, one `logs` entry), `--revert`.
- [ ] 6.3 Never touch `pagos.amount_*`, `cierres_caja`, `comisiones`; multi-line tickets that don't reconcile → manual review, no write.
- [ ] 6.4 Document dry-run/apply/revert + owner approval in `docs/dev/runbook-ops.md`.

GREEN — `cd backend && php artisan test --filter=AuditarPagosLegacy` → GREEN.
Rollback: command off by default; no runtime effect; `--revert` from `logs`.

---

## Acceptance checklist (spec mapping)

- [ ] `POST /pagos` accepts only `tipo=egreso`; `ingreso`/`devolucion` → 422 with flow message; no `Pago`/state/prize/cascade (pagos: egreso-only, rejected-types).
- [ ] Egreso: engine-authoritative amounts; `ganadora` + legacy `pendiente`+`resultado_id`; no-resultado / zero-prize / ±0.01 mismatch → 422; response `premio` (pagos: egreso requirements).
- [ ] Egreso is atomic + row-locked; concurrent double egreso → one 201, one 422, one row (pagos: atomic/no double payment).
- [ ] Refund = ticket-level annulment via `DELETE /tickets/{id}`; one `devolucion` `Pago` per line mirroring sale; apuestas+ticket `anulada` + soft-deleted; `premio_ganado` untouched; one transaction (pagos: pre-draw refund).
- [ ] All-or-nothing: one bad line → whole ticket untouched; rollback on mid-cascade failure (pagos: whole-ticket / rollback).
- [ ] Refund rejected outside pre-draw window (non-`pendiente`, `resultado_id`, past `sorteo_hora`, age ≥ effective minutes); no post-resolution refund (pagos: rejected outside window).
- [ ] Ownership reused for all roles; cross-tenant → 403 (pagos: ownership, D13).
- [ ] RF-05.2/05.3 restated: refund never marks `pagada`, never modifies prize (pagos: RF restated).
- [ ] Refund mirrors sale `metodo_pago`; override allowed; USD forced `efectivo` (metodo-pago).
- [ ] `cuadreCaja`/`timeSeriesData` reversal-pair exclusion; same-window pair nets 0; cross-window + untied refunds stay outflows (reportes-agencia).
- [ ] `CierreService` pair excluded; `total_efectivo_*` unchanged; `desglose_metodos` reconciles with `total_ventas_*` (cierre-caja).
- [ ] Collections document egreso-only + annulment/refund contracts + 422/403/404 (colecciones-api).
- [ ] Existing annulment strings byte-identical (`EliminacionApuestasTest`, `TicketEliminacionTest`).

## Test command inventory (user-run only)

Focused (inner loop):
- WU1: `cd backend && php artisan test --filter='ApuestaPolicyAnulacion|EliminacionApuestas|TicketEliminacion'`
- WU2: `cd backend && php artisan test --filter='PagoIngresoRechazado|PagoEgresoUnicoMutante|PagoTipo|ApuestaTest'`
- WU3-A: `cd backend && php artisan test --filter=PagoDevolucionAnulacion`
- WU3-B: `cd backend && php artisan test --filter='PagoDevolucionRechazos|MetodoPago|TicketAnuladosVisibles'`
- WU4: `cd backend && php artisan test --filter='CuadreCaja|CierreCaja|Estadistica'`
- WU5: `cd backend && php artisan test --filter=AuditarPagosLegacy`
- Style (CI-enforced, each slice): `cd backend && vendor/bin/pint --test`

Slice end (full contract): `cd backend && composer test` (= `php artisan config:clear && php artisan test`, MySQL `lotto_test`).

## Notes for apply

- Isolated worktree: `/home/gzuz/Documentos/lotto-app-worktrees/pago-devoluciones-anulacion` on tracker branch `feature/pago-devoluciones-anulacion`. Do not create it here; reflect it in branch targeting.
- Commit per work unit (conventional, Spanish): e.g. `feat(pagos): restringir POST /pagos a egreso`, `feat(tickets): anulación con devolución`, `fix(reportes): exclusión de pares de reversión`.
- Strict TDD: agent NEVER runs tests; stop-and-ask after each RED write and each GREEN implementation; user runs every command.
- Feature-branch-chain: PR #1/#2 target tracker; PR #3 → PR #1; PR #4 → PR #3; PR #5 → PR #4; PR #6 (gated) → PR #5. Retarget/rebase a child if its diff shows a previous slice.
- Release unit: never merge WU3-A/WU3-B to deployable `main` without WU4. WU5 is owner-gated (dry-run review before any `--apply`).
- No source changes in this phase; no migration.
