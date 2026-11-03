# pagos Specification

**Estado**: draft

## Purpose

Defines prize payment (`egreso`) and pre-draw annulment-with-refund. `POST /api/v1/pagos` registers prize payments only (`egreso`); a pre-draw return is a ticket-level annulment that records `devolucion` `Pago`(s) and marks the affected apuestas and ticket `anulada` (soft-deleted) in one transaction. Non-`egreso` types never mutate state, prize, or ticket.

Annulment is folded into this spec (not a separate `anulacion` capability) because refund and annulment are one atomic transition (D4/D9) and the proposal lists `pagos` as the single new capability covering both.

> **Decisions**: D8 (`POST /pagos` egreso-only; `ingreso`/`devolucion` → 422) and D9 (refund = ticket-level annulment via `DELETE /api/v1/tickets/{id}`) are owner-approved. Absorbed from change `pago-devoluciones-anulacion` and verified on 2026-11-02 (13/13 requirements, 41/41 scenarios).

## Requirements

### Requirement: POST /pagos is egreso-only

`POST /api/v1/pagos` MUST accept only `tipo=egreso`. A request with `tipo=ingreso` or `tipo=devolucion` MUST be rejected with 422 and a message naming the intended flow (income is recorded automatically at sale; refunds go through ticket annulment). The enum values MUST remain in the schema.

#### Scenario: ingreso rejected

- GIVEN a request `POST /pagos` with `tipo=ingreso`
- WHEN the controller processes it
- THEN it responds 422 naming the automatic sale-income flow
- AND no `Pago`, state, prize, or ticket change occurs

#### Scenario: devolucion rejected at /pagos

- GIVEN a request `POST /pagos` with `tipo=devolucion`
- WHEN the controller processes it
- THEN it responds 422 directing refunds to ticket annulment
- AND no `Pago`, state, prize, or ticket change occurs

### Requirement: Egreso prize payment requirements

An `egreso` payment MUST be allowed only when the apuesta is `ganadora`, or a legacy `pendiente` with a `resultado_id` and a positive engine prize. Amounts are backend-authoritative: if provided they MUST match the engine prize within ±0.01 per currency; if omitted they MUST be filled from the engine. A 201 response MUST include the resolved `premio`.

#### Scenario: ganadora paid

- GIVEN an apuesta in state `ganadora` with engine prize > 0
- WHEN an `egreso` is registered
- THEN 201, the `Pago` persists the engine prize, and the response includes `premio`

#### Scenario: legacy pendiente with resultado paid

- GIVEN a `pendiente` apuesta with a `resultado_id` and engine prize > 0
- WHEN an `egreso` is registered
- THEN 201 with the engine prize applied

#### Scenario: no resultado rejected

- GIVEN a `pendiente` apuesta without `resultado_id`
- WHEN an `egreso` is registered
- THEN 422 (no result)

#### Scenario: zero prize rejected

- GIVEN an apuesta whose engine prize is 0
- WHEN an `egreso` is registered
- THEN 422

#### Scenario: amount mismatch rejected

- GIVEN an `egreso` whose provided amount differs from the engine prize by more than ±0.01
- WHEN the payment is registered
- THEN 422

### Requirement: Egreso mutates state atomically and without double payment

Only an `egreso` payment MUST set the apuesta `estado='pagada'`, write `detalle_apuestas.premio_ganado(_usd)`, and trigger the ticket cascade. The `Pago` insert and the state writes MUST occur in one transaction with a row lock on the apuesta, so a second concurrent `egreso` cannot pay the same apuesta twice.

#### Scenario: state, prize, cascade

- GIVEN a valid `egreso`
- WHEN it is processed
- THEN the apuesta becomes `pagada`, `premio_ganado(_usd)` is written, and the ticket cascade runs

#### Scenario: concurrent double egreso

- GIVEN two concurrent `egreso` requests for the same apuesta
- WHEN both are processed
- THEN exactly one succeeds (201) and the other fails
- AND only one `Pago` row of tipo `egreso` exists for that apuesta

### Requirement: Rejected types never mutate state, prize, or ticket

A rejected `ingreso` or `devolucion` request MUST NOT create a `Pago`, MUST NOT change `apuestas.estado`, MUST NOT modify `detalle_apuestas.premio_ganado(_usd)`, and MUST NOT trigger the ticket cascade (D5).

#### Scenario: ingreso on ganadora leaves prize intact

- GIVEN a `ganadora` apuesta with `premio_ganado` set
- WHEN an `ingreso` is attempted via `/pagos`
- THEN 422, `premio_ganado` unchanged, estado unchanged, no `Pago`, no cascade

#### Scenario: devolucion on ganadora leaves prize intact

- GIVEN a `ganadora` apuesta with prize evidence
- WHEN a `devolucion` is attempted via `/pagos`
- THEN 422 and the prize evidence, estado, and ticket are unchanged

### Requirement: Pre-draw refund is a ticket-level annulment + refund

A valid refund MUST be performed as a ticket-level annulment via `DELETE /api/v1/tickets/{ticket}`: validate the annulment policy for every live apuesta, record `devolucion` `Pago`(s) with backend-authoritative amounts that mirror the sale (`amount_bs/usd`, `exchange_rate_applied`), set each apuesta and the ticket to `anulada`, soft-delete them, and write audit entries — all in one transaction (D4, D9). `detalle_apuestas.premio_ganado(_usd)` MUST NOT be modified by a refund.

#### Scenario: valid refund annuls and refunds

- GIVEN a ticket whose apuestas are all `pendiente`, no `resultado_id`, future `sorteo_hora`, within the effective window
- WHEN `DELETE /tickets/{id}` is called
- THEN a `devolucion` `Pago` is recorded with the sale amounts and method
- AND apuestas + ticket become `anulada` and soft-deleted, with audit entries, in one transaction

#### Scenario: whole-ticket all-or-nothing

- GIVEN a ticket where one apuesta is outside the window and the rest are refundable
- WHEN `DELETE /tickets/{id}` is called
- THEN the whole operation fails with 422 and NO apuesta, ticket, or `Pago` is changed

#### Scenario: rollback on failure

- GIVEN a valid annulment whose transaction fails mid-cascade
- WHEN the operation errors
- THEN the `Pago` insert, state changes, and soft deletes all roll back together

### Requirement: Refund rejected outside the pre-draw window

A refund MUST be rejected (422) when the apuesta is not `pendiente`, has a `resultado_id`, has `sorteo_hora` in the past, or its `created_at` age reaches the effective `tiempo_eliminacion` (default 5 minutes; hierarchy cascade taquilla → grupo → banca → 5) (D2, D3). No post-resolution refund SHALL exist.

#### Scenario: after draw

- GIVEN a `pendiente` apuesta whose `sorteo_hora` is in the past
- WHEN a refund is attempted
- THEN 422 (draw already occurred)

#### Scenario: outside the elimination window

- GIVEN an apuesta created more than the effective minutes ago
- WHEN a refund is attempted
- THEN 422 (outside window)

#### Scenario: with resultado_id

- GIVEN a `pendiente` apuesta with a `resultado_id`
- WHEN a refund is attempted
- THEN 422 (already resolved)

#### Scenario: non-refundable states

- GIVEN an apuesta in `ganadora`, `pagada`, `perdida`, `vencido`, or `anulada`
- WHEN a refund is attempted
- THEN 422 and no mutation

#### Scenario: unknown ticket

- GIVEN a `DELETE /tickets/{id}` for a non-existent ticket
- WHEN the request is processed
- THEN 404

### Requirement: Refund ownership follows the annulment policy

Refund authorization MUST reuse the annulment policy ownership for all roles: `taquilla` only its own, `agencia`/`grupo`/`banca` within their subtree, `super_master`/`master` any. A cross-tenant attempt MUST return 403 (D13).

#### Scenario: foreign taquilla denied

- GIVEN a `taquilla` role refunding an apuesta of another taquilla
- WHEN the request is processed
- THEN 403

#### Scenario: ancestor allowed

- GIVEN a `banca`/`grupo` role refunding an apuesta inside its own subtree
- WHEN the request is processed
- THEN the ownership check passes

### Requirement: RF-05.2/05.3 restated

Only `egreso` SHALL pay a prize and modify `premio_ganado`. A refund MUST NOT mark an apuesta `pagada` nor modify its prize; a valid pre-draw refund SHALL annul the sale (state `anulada` + soft delete). This reconciles RF-05.2/05.3 with D4.

#### Scenario: refund does not mark paid

- GIVEN a valid pre-draw refund
- WHEN it completes
- THEN the apuesta is `anulada`, never `pagada`, and `premio_ganado` is untouched
