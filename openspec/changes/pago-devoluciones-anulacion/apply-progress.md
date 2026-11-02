# Apply Progress — `pago-devoluciones-anulacion`

Worktree: `/home/gzuz/Documentos/lotto-app-worktrees/pago-devoluciones-anulacion`
Branch: `feature/pago-devoluciones-anulacion`
Strategy: `feature-branch-chain` / `auto-chain`
Test execution: user-run only (the agent never executes tests).

## WU1 — `ApuestaPolicy` reason extraction

Status: **complete / GREEN (user-reported)**

Files:
- `backend/app/Policies/ApuestaPolicy.php`
- `backend/app/Http/Controllers/Api/TicketController.php`
- `backend/tests/Feature/EliminacionApuestasTest.php`
- `backend/tests/Unit/ApuestaPolicyAnulacionTest.php` (new)

Behavior:
- `motivoNoAnulable(User, Apuesta, ?int $minutosEfectivos): ?string` added with codes:
  `estado`, `con_resultado`, `sorteo_ocurrido`, `fuera_de_ventana`, `sin_ownership`.
- Precedence preserved: estado -> con_resultado -> sorteo_ocurrido -> fuera_de_ventana -> sin_ownership.
- `delete()` delegates to the new method; rule order/behavior unchanged.
- `TicketController::destroy` uses one mapper (`mensajeRechazo`) instead of re-deriving reasons per line.
- Existing message strings preserved byte-identical except the new `sin_ownership` mapping described below.

Test command (user-run):
```bash
cd backend && php artisan test --filter='ApuestaPolicyAnulacion|EliminacionApuestas|TicketEliminacion'
```
Result: **GREEN** (user-reported).

Deviations / notes:
- WU1 maps `sin_ownership` to `No autorizado.` while keeping status **422**. WU3-A will flip the status to 403 when `TicketController::destroy` delegates to `TicketAnnulacionService`/`TicketAnulacionException`.
- The cross-tenant test uses a `grupo` user from another grupo because a `taquilla` user requires `X-Device-MAC` (`VerifyMac`) and an `agencia` user is blocked by the route role middleware before reaching the policy.
- Review size is above the 400-line inner target for WU1 (new unit test scaffolding); still below the 800-line session budget.

## WU2 — `PagoController` egreso-only

Status: **complete / GREEN (user-reported)**

Files:
- `backend/app/Http/Controllers/Api/PagoController.php` (modified)
- `backend/tests/Feature/PagoTipoTest.php` (rewritten)
- `backend/tests/Feature/PagoIngresoRechazadoTest.php` (new)
- `backend/tests/Feature/PagoEgresoUnicoMutanteTest.php` (new)
- `backend/tests/Feature/ApuestaTest.php` (modified)
- `collections/Pagos/Registrar Pago (Token required).yml` (modified)

Behavior:
- `POST /api/v1/pagos` is now egreso-only. After input validation (so `apuesta_id.exists`,
  `tipo.in`, `moneda.in` still answer first), `tipo=ingreso` / `tipo=devolucion` return 422 with the
  flow message and **no** apuesta load, transaction, or write:
  - `ingreso`: `El ingreso se registra automáticamente al vender; no se admite por este endpoint.`
  - `devolucion`: `Las devoluciones se registran anulando el ticket: use DELETE /api/v1/tickets/{id}.`
- Egreso runs inside one `DB::transaction` with `Apuesta::with(['juego','resultado','detalles'])->lockForUpdate()->find()`;
  existence (404), taquilla ownership (403), `estado ∈ {pendiente,ganadora}`, `resultado_id`,
  engine prize > 0 and ±0.01 tolerance are all re-checked inside the lock. Validation failures return
  an error tuple from the closure -> nothing is written.
- Engine-authoritative persistence (AD-3): `pagos.amount_bs/usd` and
  `detalle_apuestas.premio_ganado(_usd)` are the engine values; request amounts are tolerance-only.
  201 response includes `premio` (egreso only).
- `pago_premio` log only for egreso, now with structured `details` (array cast) including
  `pago_id` and `premio_fuente: motor`.
- Ticket cascade hardened: skips `pagada`/`anulada` and soft-deleted tickets, evaluates only live
  lines, and requires `isNotEmpty()` before `every()` (an empty collection must never resolve the ticket).

TDD evidence (Strict TDD active; the agent never runs tests):

| Task | RED (test written first) | GREEN (user-run) | REFACTOR |
|---|---|---|---|
| 2.1 | `PagoTipoTest` (devolucion 422, no writes), `PagoIngresoRechazadoTest` (pendiente/ganadora), `ApuestaTest::test_pago_ingreso_rechazado_en_ganadora_preserva_premio` | GREEN (user-reported) | n/a |
| 2.2 | `PagoEgresoUnicoMutanteTest::test_doble_egreso_secuencial_*`, `test_el_camino_de_egreso_bloquea_la_apuesta_con_for_update` | GREEN (user-reported) | n/a |
| 2.3 | `test_egreso_persiste_el_valor_del_motor_dentro_de_la_tolerancia`, `test_monto_fuera_de_tolerancia_rechaza_sin_escrituras` | GREEN (user-reported) | n/a |
| 2.4 | `test_cascada_ignora_tickets_anulados`, `test_cascada_solo_considera_lineas_vivas_del_ticket`, `test_cascada_ignora_tickets_soft_deleted` | GREEN (user-reported) | n/a |
| 2.5 | `test_solo_el_egreso_muta_estado_premio_y_ticket` (log `pago_premio` + `pago_id`) | GREEN (user-reported) | n/a |

Work Unit Evidence (WU2):

| Evidence | Value |
|---|---|
| Focused test command | `cd backend && php artisan test --filter='PagoIngresoRechazado\|PagoEgresoUnicoMutante\|PagoTipo\|ApuestaTest'` -> **user-run pending** |
| Runtime harness | HTTP feature tests through `/api/v1/pagos` (Sanctum + role middleware + MySQL `lotto_test`), real engine, real transaction/lock; `DB::getQueryLog()` asserts the `select ... for update` |
| Rollback boundary | `PagoController::store` + `collections/Pagos/Registrar Pago (Token required).yml` + the WU2 tests; no data effect (no refunds exist yet) |

Deviations / notes:
- The `apuestas->isNotEmpty()` guard is defense-in-depth: with a live target apuesta the live
  collection always contains at least that line, so the empty branch is not reachable through the
  route. Tests pin the reachable cascade branches (skip `anulada`, skip soft-deleted ticket,
  live-only evaluation); the `isNotEmpty()` guard itself is covered by code review.
- `Log::details` is now written as an array (the model already casts `details` to array) instead of
  the previous double-encoded `json_encode(...)` string. This matches `ApuestaController` and the
  design §7.6 contract, and is what the future WU5 `--revert` needs (`details.old_values`).
- `Resultado` is read from the eager-loaded relation inside the lock instead of a fresh `find()`;
  same engine input, one less query.
- The ±0.01 comparison rounds the delta to cents (`round(abs(...), 2)`) so the exact boundary is
  deterministic (e.g. `300.01 - 300.0` is `0.01000000000005` in binary floating point and would
  otherwise be rejected). Deeper fractional deviations behave exactly as before.
- `PagoPremioSinMontosTest` and `MetodoPagoTest` were not modified and their expectations hold
  (engine amounts are the same values those tests already asserted).

## WU3-A — `TicketAnulacionService` (annulment + refund)

Status: **complete / GREEN (user-reported)**. **size:exception approved by owner** for ~1.1k changed lines (forecast 600–660).

Files:
- `backend/app/Exceptions/TicketAnulacionException.php` (new)
- `backend/app/Services/TicketAnulacionService.php` (new)
- `backend/app/Http/Controllers/Api/TicketController.php` (modified)
- `backend/tests/Feature/PagoDevolucionAnulacionTest.php` (new)
- `backend/tests/Feature/EliminacionApuestasTest.php` (modified)

Behavior:
- `DELETE /api/v1/tickets/{id}` delegates to `TicketAnulacionService::anularTicket`.
  The service owns the transaction and locks: ticket re-fetched with
  `Ticket::whereKey(...)->lockForUpdate()->first()` (SoftDeletes scope, no
  `withTrashed`), live lines with `orderBy('id')->lockForUpdate()`. Null -> 404,
  non-`pendiente` -> 422, zero live lines -> 422.
- Every live line is validated with `ApuestaPolicy::motivoNoAnulable` before any
  write (all-or-nothing): `sin_ownership` -> 403 `No autorizado.`; the other
  codes -> 422 with the historical byte-identical messages.
- One `pagos.tipo=devolucion` row per live line mirrors the sale: `amount_bs`,
  `amount_usd`, `exchange_rate_applied`; `moneda` derived (`mixto`/`usd`/`bs`);
  `metodo_pago` = override -> sale `ingreso` (lowest `pagos.id`) -> `efectivo`,
  then `Pago::resolverMetodoPago` forces `efectivo` for USD. `concepto` defaults
  to `Devolución de ticket`.
- Apuestas and ticket are set to `estado='anulada'` and soft-deleted.
  `detalle_apuestas.premio_ganado(_usd)` is never touched.
- One `logs` row `ticket_anulado` with structured `details` (ticket, apuesta ids,
  per-refund detail, totals). `anularApuestaSuelta` writes `apuesta.anulada`.
- Controller returns 200 `{success, message, data:{ticket_id, apuestas, devoluciones, total_bs, total_usd}}`;
  `TicketAnulacionException` maps to `{success:false, message}` with its status.
  The WU1 message strings are unchanged; only `sin_ownership` moves from 422 to 403.

TDD evidence (Strict TDD active; the agent never runs tests):

| Task | RED (test written first) | GREEN (user-run) | REFACTOR |
|---|---|---|---|
| 3.1 | `PagoDevolucionAnulacionTest` (403/404/422 paths through the controller) | pending | n/a |
| 3.2 | `test_anulacion_registra_devolucion_espejo_de_la_venta`, `test_anulacion_multilinea_registra_una_devolucion_por_linea` | pending | n/a |
| 3.3 | `test_apuestas_y_ticket_quedan_anulados_y_soft_deleted`, `test_la_devolucion_no_toca_el_premio_ganado`, `test_anulacion_registra_el_log_ticket_anulado`, `test_reintento_tras_la_anulacion_devuelve_404`, `test_ticket_de_otro_grupo_devuelve_403`, `test_ticket_sin_jugadas_vivas_devuelve_422`, `test_all_or_nothing_una_linea_mala_no_escribe_nada`, `test_ticket_no_pendiente_devuelve_422_sin_escrituras` | pending | n/a |
| 3.4 | `EliminacionApuestasTest` updated (refund row after `DELETE /tickets`, 403 ownership) | pending | n/a |
| 3.5 | `test_anular_apuesta_suelta_registra_devolucion_y_la_anula` | pending | n/a |

Work Unit Evidence (WU3-A):

| Evidence | Value |
|---|---|
| Focused test command | `cd backend && php artisan test --filter='PagoDevolucionAnulacion\|EliminacionApuestas'` -> **user-run pending** |
| Runtime harness | HTTP feature tests through `DELETE /api/v1/tickets/{id}` (Sanctum + role middleware + MySQL `lotto_test`), real policy, real transaction/lock, plus a direct service call for the ticketless path |
| Rollback boundary | `TicketAnulacionService`, `TicketAnulacionException`, `TicketController::destroy`, `PagoDevolucionAnulacionTest`, `EliminacionApuestasTest`; safe only if no production refunds were recorded |

Deviations / notes:
- The optional `metodo_pago` override is accepted and passed through
  `Pago::resolverMetodoPago`; the WU3-B validation surface (invalid override 422,
  USD method rules) is deliberately not built here.
- A stale ticket model cannot re-run the transition: the in-lock re-fetch returns
  `null` (soft-deleted) -> 404, matching the route-binding 404 on retry.
- `EliminacionApuestasTest` ownership expectation moved from 422 to 403 as design
  §7.4 requires; all other WU1 strings remain byte-identical.
- No migrations, routes, model changes, `ApuestaController`, reports, or WU3-B/WU4
  files were touched.

## WU3-B — endpoint restriction / rejection matrix

Status: **complete / GREEN (user-reported)**. The agent never executes tests. **size:exception approved by owner** for the ~977-line slice.

Files:
- `backend/app/Http/Controllers/Api/ApuestaController.php` (modified)
- `backend/tests/Feature/PagoDevolucionRechazosTest.php` (new)
- `backend/tests/Feature/MetodoPagoTest.php` (modified)
- `backend/tests/Feature/TicketAnuladosVisiblesTest.php` (modified)
- `collections/Tickets/Anular Ticket.yml` (modified)
- `collections/Apuestas/Eliminar Apuesta (Token required - 5 min rule).yml` (modified)

Behavior:
- `DELETE /api/v1/apuestas/{id}` now starts with `authorize('view', $apuesta)` (tree
  ownership; cross-tenant -> 403 `No autorizado.`) **before** any ticket-level
  redirect, so a foreign caller cannot probe whether the line belongs to a ticket.
- Optional body validation: `metodo_pago` (`Rule::in(Pago::METODOS_PAGO)`),
  `referencia`/`motivo` (string ≤255). Invalid values -> 422 with no writes.
- `ticket_id !== null` -> delegated service rejects with 422
  `La anulación se realiza a nivel de ticket: use DELETE /api/v1/tickets/{ticket_id}.`
  and no writes. Ticketless -> `TicketAnulacionService::anularApuestaSuelta`
  (devolución + `anulada` + soft delete + `apuesta.anulada` log, one transaction).
- Response 200 `{success, message, data:{devolucion:{pago_id, apuesta_id,
  amount_bs, amount_usd, exchange_rate_applied, metodo_pago, moneda}}}`;
  `TicketAnulacionException` maps to `{success:false, message}` + its status.
- The old `authorize('delete')` + direct soft-delete + `apuesta.deleted` log path
  was removed; the service owns audit semantics.
- Refund method (already in the WU3-A service, now pinned by tests): override ->
  sale `ingreso.metodo_pago` -> `efectivo`, then `Pago::resolverMetodoPago`
  forces `efectivo` for USD. Invalid override -> 422 before any write.
- Collections document the annulment + refund contract (200 shape, 403/404/422)
  and the ticketless-only `DELETE /apuestas` behavior.

TDD evidence (Strict TDD active; the agent never runs tests):

| Task | RED (test written first) | GREEN (user-run) | REFACTOR |
|---|---|---|---|
| 4.1 | `PagoDevolucionRechazosTest` (ticketless 200, ticket-line 422 redirect, cross-tenant 403 before redirect, out-of-window 422, no partial writes) | pending | n/a |
| 4.2 | `PagoDevolucionRechazosTest` (states `ganadora`/`pagada`/`perdida`/`vencido`/`anulada`, `resultado_id`, sorteo ocurrido, window boundary 5/10/15 with `Carbon::setTestNow`, unknown ticket 404, zero live lines 422, cross-tenant 403, all-or-nothing), `MetodoPagoTest` (mirror/override/USD/invalid override) | pending | n/a |
| 4.3 | n/a (documentation; verified against controller/tests) | pending | n/a |
| visible | `TicketAnuladosVisiblesTest` (multi-line annulment stays visible, zero live lines, one refund per line) | pending | n/a |

Work Unit Evidence (WU3-B):

| Evidence | Value |
|---|---|
| Focused test command | `cd backend && php artisan test --filter='PagoDevolucionRechazos\|MetodoPago\|TicketAnuladosVisibles'` -> **user-run pending** |
| Runtime harness | HTTP feature tests through `DELETE /api/v1/apuestas/{id}` and `DELETE /api/v1/tickets/{id}` (Sanctum + role/MAC middleware, MySQL `lotto_test`), real policy, real service transaction/lock |
| Rollback boundary | `ApuestaController::destroy` + the two collections + WU3-B tests; ticket refunds from WU3-A stay working |

Deviations / notes:
- The controller builds the single-refund response shape from the returned
  `Pago` (the service keeps its private audit-detail mapper untouched, as WU3-A
  behavior must remain intact).
- `Carbon::setTestNow()` is used for the window boundary tests; `ApuestaPolicy`
  uses `diffInMinutes` with the project's Carbon 3 float semantics, and the
  test pins the exact `>=` edge.
- `AgenciaScopeTest`'s ticketless delete expectation stays valid: its fixture
  lines have no ticket, no `resultado_id`, a fresh `created_at` and a null
  `sorteo_hora`, so the service annuls + refunds them.
- No migrations, routes, model changes, `PagoController`, reports, or WU4 files
  were touched. Changes are intentionally left uncommitted.

## WU4 — report reversal-pair exclusion

Status: **complete / GREEN (user-reported)**. The agent never executes tests.

Files:
- `backend/app/Support/ReversalPairQuery.php` (new)
- `backend/app/Services/ApuestaService.php` (modified)
- `backend/app/Services/CierreService.php` (modified)
- `backend/tests/Feature/CuadreCajaReportTest.php` (modified)
- `backend/tests/Feature/CierreCajaTest.php` (modified)
- `backend/tests/Feature/EstadisticaTest.php` (modified)
- `docs/dev/motor-cierres-comisiones.md` (integrated from `main`; see deviation)

Behavior:
- `ReversalPairQuery::aplicar(Builder, $desde, $hasta, bool $halfOpen = false)`
  adds one `whereNotExists` over `apuestas as a_sale` with a raw `from()`
  (bypasses the SoftDeletes global scope), matches
  `a_sale.id = pagos.apuesta_id`, requires `estado='anulada'` OR
  `deleted_at IS NOT NULL`, and filters the sale `fecha_hora` with `whereDate`
  (inclusive) or `[desde, hasta)` when `$halfOpen = true`.
  `pagos.apuesta_id IS NULL` yields no match, so untied refunds still count.
- `ApuestaService::pagosCuadrePorNivel` applies the predicate only for
  `$tipo === 'devolucion'`; `egreso` is untouched and
  `Efectivo = Venta − Pagados − Devoluciones − Vencidos` is unchanged.
- `ApuestaService::timeSeriesData` step 5 applies the predicate to the
  `devolucion` query; the six series keys and
  `saldo = ventas − (premios + pagados + devolucion)` are unchanged.
- `CierreService::calcularTotales` replaces `whereIn('tipo', ['egreso','devolucion'])`
  with `egreso OR (devolucion AND NOT EXISTS sale-in-window)` using the
  half-open `[fechaInicio, fechaFin)`. The same filtered query feeds
  `total_egresos_*` and `egresosDesglose` (reconciliation by construction);
  `$ventasDesglose` is unchanged (AD-7).
- Tests cover: same-window pair 0/0/0, soft-deleted sale pair, cross-window
  refund as real outflow, untied refund, annulled sale without refund (no
  regression); cierre pair exclusion + unchanged cash + `desglose_metodos`
  reconciliation (BS/USD→efectivo) + cross-window refund still an egreso;
  same-day time-series pair 0/0/0 with the six keys and the cross-midnight
  residual documented.

TDD evidence (Strict TDD active; the agent never runs tests):

| Task | RED (test written first) | GREEN (user-run) | REFACTOR |
|---|---|---|---|
| 5.1 | `CuadreCajaReportTest::test_cuadre_excluye_par_de_reversion_*`, `test_cuadre_cuenta_devolucion_de_venta_fuera_de_la_ventana`, `test_cuadre_cuenta_devolucion_sin_apuesta_asociada`, `test_cuadre_venta_anulada_sin_devolucion_no_regresiona` | pending | n/a |
| 5.2 | `EstadisticaTest::test_time_series_excluye_par_de_reversion_y_conserva_las_seis_series`, `test_time_series_par_cross_midnight_nets_cero_en_la_ventana` | pending | n/a |
| 5.3 | `CierreCajaTest::test_cierre_excluye_par_de_reversion_y_conserva_el_efectivo`, `test_cierre_desglose_metodos_cuadra_con_ventas_incluyendo_usd`, `test_cierre_cuenta_devolucion_de_venta_fuera_de_la_ventana` | pending | n/a |
| 5.4 | n/a (documentation; verified against helper/services) | pending | n/a |

Work Unit Evidence (WU4):

| Evidence | Value |
|---|---|
| Focused test command | `cd backend && php artisan test --filter='CuadreCaja\|CierreCaja\|Estadistica'` -> **user-run pending** |
| Runtime harness | HTTP feature tests through `GET /api/v1/reportes/cuadre-caja`, `GET /api/v1/estadisticas/rendimiento`, `POST /api/v1/cierre` (Sanctum + MySQL `lotto_test`), real service queries against `pagos`/`apuestas` |
| Rollback boundary | `ReversalPairQuery`, the two report/cierre service edits, the three test files and the doc; **must not revert WU4 alone** (reintroduces `Efectivo = −R`) |

Deviations / notes:
- `docs/dev/motor-cierres-comisiones.md` does not exist at this branch base
  (`6feb1fe`); it was added on `main` later (`72bc1c6`, `47fa94d`). To keep one
  coherent document instead of a second fragment, the branch now carries the
  current `main` version with the WU4 content integrated in place: §4.3
  (egresos del cierre), §7.1–§7.2 (regla, consumidores, aritmética y
  residuales), §8 (assertions added to `CierreCajaTest`/`CuadreCajaReportTest`)
  and §9 Alta #2 marked **FIXED** (WU2 + WU3 + WU4). When the chain is rebased
  onto current `main`, the doc merge is therefore conflict-free or trivially
  identical.
- The report tests use an `anulada` sale **without soft delete** for the
  same-window pair so `cuadreCaja`/`timeSeriesData` still derive the taquilla
  from the sales query; the soft-deleted branch is covered by
  `test_cuadre_excluye_par_de_reversion_con_venta_soft_deleted` with a live
  sale keeping the taquilla visible (pre-existing R11 visibility quirk).
- No migrations, routes, model changes, `PagoController`, `TicketAnulacionService`,
  `ApuestaController`, or WU5 files were touched. Changes are left uncommitted.

## Next

WU5 — legacy audit (owner-gated). Not started (out of this batch's scope).
