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

## Next

WU3-A — `TicketAnulacionService` + `TicketAnulacionException` + `TicketController::destroy` delegation.
Not started (out of this batch's scope).
