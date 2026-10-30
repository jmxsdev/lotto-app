# Design: Pago, devoluciones y anulación

**Change**: `pago-devoluciones-anulacion` · **Phase**: design · **Status**: ready for owner review
**Inputs**: `explore.md`, `proposal.md`, delta specs (`pagos`, `metodo-pago`, `reportes-agencia`, `cierre-caja`, `colecciones-api`).
**Decisions under owner review**: D8 (egreso-only), D9 (ticket-level annulment + refund), D10 (reversal-pair exclusion).
**Hard constraints**: no schema migration; code-only; strict TDD (`composer test` = `php artisan config:clear && php artisan test`, MySQL `lotto_test`); no historical data mutation in this change (D11).

---

## 1. Technical approach

Make `POST /api/v1/pagos` a single-purpose prize-payment pipeline (`egreso` only) executed inside one transaction with a row lock. Move the pre-draw refund out of `/pagos` into a new `TicketAnulacionService` that performs the whole-ticket annulment, writes one `devolucion` `Pago` per live line mirroring the sale, flips `apuestas.estado`/`tickets.estado` to `anulada`, soft-deletes, and audits — all atomically. Extract the annulment rules from `ApuestaPolicy::delete` into reason codes so the policy stays the single source of truth and the existing messages live in exactly one mapper. Ship the report reversal-pair predicate in the same release, because D1+D4 without D6 turns a hidden state bug into a visible cash error.

No new tables, columns, enums, routes, or indexes.

### 1.1 Before / after (observable behavior)

| # | Flow | Before (`main`) | After (this change) |
|---|---|---|---|
| 1 | `POST /pagos {tipo:ingreso}` on a `ganadora` apuesta with prize 300 | 201: `Pago(tipo=ingreso)`, `apuesta.estado='pagada'`, `detalle.premio_ganado` overwritten with the request amount (usually 0), ticket cascade | 422 `"El ingreso se registra automáticamente al vender; no se admite por este endpoint."`; no `Pago`, no state/prize/cascade change |
| 2 | `POST /pagos {tipo:devolucion}` on a `pendiente` apuesta | 201: `Pago(tipo=devolucion)`, `estado='pagada'`, prize overwritten, ticket cascade | 422 `"Las devoluciones se registran anulando el ticket: use DELETE /api/v1/tickets/{id}."`; refunds only through ticket annulment |
| 3 | `DELETE /tickets/{id}` for a valid pre-draw return | 200, soft delete only: no `Pago`, `estado` stays `pendiente`, money leaves with no ledger row | 200: one `devolucion` `Pago` per line (sale amounts/rate/method), apuestas + ticket `anulada` + soft-deleted, `ticket_anulado` log, one transaction |
| 4 | Two concurrent `egreso` requests for the same apuesta | Both can insert a `Pago` and set `pagada` (no transaction, no lock) | One 201, one 422 (`pagada`); exactly one `egreso` row |
| 5 | Report window containing sale 1000 bs @10:00 + refund 1000 bs @10:03 | `Venta=1000`, `Devoluciones=1000`, `Efectivo=0` (state is wrong: apuesta `pagada`, not annulled) | `Venta=0`, `Devoluciones=0`, `Efectivo=0`; `desglose_metodos` sums to `total_ventas_*` |
| 6 | Refund after draw / outside the effective window / on `ganadora`/`pagada`/`perdida`/`vencido` | `/pagos` accepted it (201, corrupted state) or `DELETE /tickets` returned a generic 422 | 422 with the specific reason; no `Pago`, no state/soft-delete/log change |

Note for #5: the intermediate state after WU3 without WU4 is `Venta=0`, `Devoluciones=1000`, `Efectivo=−1000`; that is why WU4 is release-gated with WU3.

---

## 2. Architecture and responsibility boundaries

| Component | Owns | Must not |
|---|---|---|
| `pagos` ledger | Append-only cash movements: `ingreso` = sale (written only by `ApuestaService::createApuesta`), `egreso` = prize (only `PagoController`), `devolucion` = refund (only `TicketAnulacionService`). | Decide sale states; be mutated by non-`egreso` requests. |
| `apuestas.estado` | Commercial lifecycle: `pendiente → ganadora/ perdida/ vencido` (result), `ganadora → pagada` (egreso), `pendiente → anulada` (refund, pre-draw). | Be written by `ingreso` or by a refund that is not pre-draw. |
| `detalle_apuestas.premio_ganado(_usd)` | Liquidation evidence. Written by `verificarGanadores` and re-asserted by `egreso` with engine values. | Be touched by a refund or by any non-`egreso` request. |
| `tickets.estado` | Aggregate of lines: `pendiente → ganador` (liquidation), `→ pagada` (cascade after egreso), `→ anulada` (whole-ticket annulment), `→ vencido` (expiry job). | Be partially annulled: annulment is whole-ticket only (D9). |
| `logs` | Audit trail (`pago_premio`, `ticket_anulado`, `apuesta.anulada`). | Be the only evidence of a money movement. |

**Annulment unit**: the ticket is the unit of sale and refund. A ticketless apuesta (`POST /apuestas`, `ticket_id = null`) is treated as an implicit one-line ticket for `DELETE /apuestas/{id}` (decision AD-4).

---

## 3. Architecture decisions

| # | Decision | Options considered | Choice and rationale |
|---|---|---|---|
| AD-1 | Where the refund transition lives | (a) new `TicketAnulacionService`; (b) controller-calls-controller; (c) duplicate logic in each controller; (d) fat `ApuestaService` | **(a)**. One transition, one transaction, one cascade; policy remains the rule source; controllers keep HTTP concerns only. (b) mixes request/transaction scopes; (c) creates a third copy of window rules; (d) puts a state transition inside a 1.4k-line query helper. |
| AD-2 | When non-egreso types are rejected | (a) after loading/guarding the apuesta; (b) immediately after input validation | **(b)**. Deterministic 422 independent of apuesta state/ownership; proves D5 (no mutation). The enum stays in validation so unknown values still yield the generic validation error and the DB enum stays untouched. |
| AD-3 | Authoritative egreso amounts | (a) persist request amounts after ±0.01; (b) persist engine amounts, treat request amounts as tolerance check only | **(b)**. Spec says the `Pago` persists the engine prize; removes a class of rounding drift between `pagos`, `detalle_apuestas` and the response. Existing exact-amount tests keep passing. |
| AD-4 | `DELETE /apuestas/{id}` | (a) route to whole-ticket annulment; (b) restrict to ticketless and refund there; (c) leave as soft-delete without refund | **(b)**. No whole-ticket side effect from a line-level URL; closes "annulment without refund"; covers the standalone-sale case that ticket-level annulment cannot reach. (c) violates the product rule. Impact: a line inside a ticket now returns 422 pointing to `DELETE /tickets/{id}`; no in-repo consumer calls this endpoint (taquilla/panel use `/tickets`; only `collections/Apuestas/Eliminar Apuesta…yml` documents it). |
| AD-5 | Reason codes vs message strings | (a) policy returns bool, callers re-derive strings; (b) policy returns codes, one mapper owns copy | **(b)** (D7). Kills the duplication in `TicketController::destroy`, keeps `EliminacionApuestasTest`/`TicketEliminacionTest` strings byte-identical; adding an optional precomputed `$minutosEfectivos` avoids the N+1 hierarchy query. |
| AD-6 | Report fix shape | (a) window-aware `whereNotExists`; (b) count sales gross; (c) new `Anuladas` column; (d) drop all refunds of annulled sales | **(a)**. Exact for same-window pairs, no schema/API change, keeps cross-window refunds as real outflows. (b) contradicts the "ventas no anuladas" rule; (c) needs front changes and does not fix `saldo`; (d) loses real cash. |
| AD-7 | `desglose_metodos` reconciliation | (a) change the breakdown query; (b) rely on the existing join | **(b)**. The breakdown already runs through `Apuesta` (soft-delete scope) + `estado != anulada`, so an annulled sale and its `ingreso` disappear together. Only a regression assertion is added. |
| AD-8 | Historical repair | (a) in-change backfill; (b) gated dry-run command (WU5) | **(b)**. D11; cannot be automated safely without a reviewed dry-run. |
| AD-9 | Receipt/reprint `GET /pagos/{apuesta}` | (a) fix here; (b) defer with evidence | **(b)**. See §13 R8: the inflation by the sale `ingreso` is pre-existing (`recibos.ts:86-95` sums every row), unrelated to #66; the new refund rows cannot reach a reprint because an annulled ticket exposes zero live apuestas (`TicketAnuladosVisiblesTest:88`). Fix belongs to a receipt slice with taquilla tests. |

---

## 4. State transitions

### 4.1 `apuestas.estado`

| From | Trigger | To | Ledger | Audit |
|---|---|---|---|---|
| — | sale (`createApuesta`) | `pendiente` | `ingreso` | — |
| `pendiente` | result, prize > 0 | `ganadora` | — | — |
| `pendiente` | result, prize = 0 | `perdida` | — | — |
| `ganadora` | unclaimed-prize job (ticket `ganador` → `vencido`) | `vencido` | — | — |
| `ganadora` | `POST /pagos` egreso | `pagada` | `egreso` | `pago_premio` |
| `pendiente` (legacy, with `resultado_id`) | `POST /pagos` egreso | `pagada` | `egreso` | `pago_premio` |
| `pendiente` | `DELETE /tickets/{id}` | `anulada` + soft delete | `devolucion` (one per line) | `ticket_anulado` |
| `pendiente`, `ticket_id = null` | `DELETE /apuestas/{id}` | `anulada` + soft delete | `devolucion` | `apuesta.anulada` |

Rejected (422, no writes): egreso from `pendiente` without `resultado_id`, engine prize 0, amount mismatch > ±0.01, or any egreso from `pagada`/`perdida`/`vencido`/`anulada`/trashed. A refund is rejected when the apuesta is not `pendiente`, has a `resultado_id`, has a past `sorteo_hora`, or is outside the effective `tiempo_eliminacion` window (D2). No post-resolution refund (D3).

```
pendiente ──result(prize>0)──▶ ganadora ──egreso──▶ pagada
    │                              │
    │                              └──job(ticket vencido)──▶ vencido
    ├──result(prize=0)──▶ perdida
    └──annulment/refund──▶ anulada (soft-deleted)
                              ▲
             ganadora/pagada/perdida/vencido/anulada ──✗──┘ (never)
```

### 4.2 `tickets.estado`

| From | Trigger | To |
|---|---|---|
| — | `POST /tickets` | `pendiente` |
| `pendiente` | any line liquidates as `ganadora` | `ganador` |
| `pendiente`/`ganador` | cascade after an egreso: every live line ∈ `pagada, anulada, perdida, vencido` and at least one live line exists | `pagada` |
| `pendiente` | `DELETE /tickets/{id}` (whole ticket) | `anulada` + soft delete |
| `ganador` | `ExpireUnclaimedPrizesJob` | `vencido` |

Cascade hardening (part of WU2): skip trashed/`anulada` tickets and require `apuestas->isNotEmpty()`, closing the `Collection::every()`-on-empty blind spot in the current `PagoController::store` cascade.

### 4.3 `pagos.tipo`

| Type | Only writer | Via API |
|---|---|---|
| `ingreso` | `ApuestaService::createApuesta` (sale) | rejected 422 on `/pagos` (D8) |
| `egreso` | `PagoController::store` | 201 (only accepted type) |
| `devolucion` | `TicketAnulacionService` | rejected 422 on `/pagos`; created by annulment (D9) |

The DB enum keeps all three values (no destructive migration). `ingreso`/`devolucion` stay in the validation rule so the explicit 422 messages are reachable.

---

## 5. Sequence flows

### 5.1 Egreso happy path

```
Taquilla        PagoController                    DB transaction
   | POST /pagos {tipo:egreso} |                        |
   |-------------------------->| validate input          |
   |                           | tipo != egreso? -> 422  |
   |                           |-- BEGIN --------------->|
   |                           |  SELECT apuesta FOR UPDATE
   |                           |  ownership (taquilla)
   |                           |  estado ∈ {pendiente,ganadora}
   |                           |  resultado_id required
   |                           |  premio = engine(snapshot)
   |                           |  |request| - premio| <= 0.01
   |                           |  INSERT pagos(egreso, engine amounts)
   |                           |  UPDATE apuestas estado=pagada
   |                           |  UPDATE detalle premio_ganado(_usd)=engine
   |                           |  cascade ticket -> pagada (guarded)
   |                           |  INSERT logs(pago_premio)
   |                           |-- COMMIT -------------->|
   |<-- 201 {data, premio} ----|
```

### 5.2 Concurrent double egreso

```
T1: BEGIN; SELECT ... FOR UPDATE          -- locks the apuesta row
T2: BEGIN; SELECT ... FOR UPDATE          -- blocks on T1
T1: INSERT pago; UPDATE estado=pagada; COMMIT
T2: lock released; reads estado=pagada -> 422; COMMIT (zero writes)
Result: exactly one egreso row, exactly one 201
```

### 5.3 Ticket annulment + refund happy path

```
Client          TicketController          TicketAnulacionService              DB transaction
  | DELETE /tickets/9 |                          |                                  |
  |------------------>| validate optional body    |                                  |
  |                   | anularTicket(ticket,user) |                                  |
  |                   |-------------------------->| BEGIN -------------------------->|
  |                   |                           | lock ticket (no trashed scope)    |
  |                   |                           | null -> 404; estado != pendiente -> 422 |
  |                   |                           | lock live apuestas (order by id)  |
  |                   |                           | empty? -> 422                     |
  |                   |                           | minutos = effective(taquilla)     |
  |                   |                           | policy reason per line -> 403/422 |
  |                   |                           | batch sale metodo_pago lookup     |
  |                   |                           | per line: INSERT devolucion       |
  |                   |                           |           estado=anulada          |
  |                   |                           |           soft delete             |
  |                   |                           | ticket: anulada + soft delete     |
  |                   |                           | INSERT logs(ticket_anulado)       |
  |                   |                           | COMMIT -------------------------->|
  |<-- 200 {message, data:{devoluciones,totales,apuestas}}
```

### 5.4 All-or-nothing failure

```
lines: [A ok, B outside window]
lock ticket + both lines; validate A (ok); validate B -> fuera_de_ventana
throw TicketAnulacionException(422) -> rollback
assert: 0 pagos, A/B estado unchanged, no deleted_at, no log
```

### 5.5 Rejected `ingreso`/`devolucion` via `/pagos`

```
POST /pagos {tipo: ingreso|devolucion}
validate -> tipo != egreso -> 422 with flow-specific message
return before any apuesta load, transaction, or write
```

---

## 6. File-by-file changes

| File | Action | Methods / edits | Est. lines | WU |
|---|---|---|---|---|
| `backend/app/Policies/ApuestaPolicy.php` | Modify | `motivoNoAnulable(User, Apuesta, ?int $minutosEfectivos = null): ?string` (codes: `estado`, `con_resultado`, `sorteo_ocurrido`, `fuera_de_ventana`, `sin_ownership`); constants; `delete()` delegates. Rule order and behavior unchanged. | +60/−42 | WU1 |
| `backend/app/Http/Controllers/Api/TicketController.php` | Modify twice | WU1: replace the per-line `Gate::denies` rejection block with `motivoNoAnulable` + private `mensajeRechazo(code, minutos)`; strings byte-identical. WU3-A: replace the whole `destroy` body with `TicketAnulacionService` + exception mapping; remove mapper and `Gate` usage. | WU1 +30/−28 · WU3-A +30/−40 | WU1, WU3-A |
| `backend/app/Services/TicketAnulacionService.php` | Create | `anularTicket(Ticket, User, array $opciones): array`; `anularApuestaSuelta(Apuesta, User, array $opciones): Pago`; private `assertAnulable`, `registrarDevolucion`, `metodosVenta`, `marcarAnulada`, `mensajeRechazo`, `registrarLog`. Constructor: `ApuestaService`, `ApuestaPolicy`. Transaction and locks live here, not in the controller. | +240 new | WU3-A |
| `backend/app/Exceptions/TicketAnulacionException.php` | Create | `RuntimeException` with `public readonly int $status` (403/404/422) and `?string $motivo`; named constructors. | +35 new | WU3-A |
| `backend/app/Http/Controllers/Api/PagoController.php` | Modify | `store`: early non-egreso rejection; single-transaction egreso pipeline with `lockForUpdate`; engine-authoritative writes; guarded cascade; `pago_premio` log only. Keep `calcularPremio`/`esMultiSorteo` for WU2; WU5 extracts them into `PremiosLiquidador`. `showByApuesta` untouched (AD-9). Update class docblock. | +170/−150 | WU2 |
| `backend/app/Http/Controllers/Api/ApuestaController.php` | Modify | `destroy`: `authorize('view', $apuesta)` first (tree ownership; 403 cross-tenant); validate optional `metodo_pago`/`referencia`/`motivo`; `ticket_id !== null` → 422 pointing at `/tickets/{id}` (no writes); else `anularApuestaSuelta` (policy re-checked inside the transaction); map exception. Drop `authorize('delete')` (the service owns annulment eligibility). | +40/−30 | WU3-B |
| `backend/app/Services/ApuestaService.php` | Modify | `pagosCuadrePorNivel`: add `whereNotExists` when `$tipo === 'devolucion'`. `timeSeriesData` step 5: same predicate. No changes to sales queries. | +50 | WU4 |
| `backend/app/Services/CierreService.php` | Modify | `calcularTotales`: replace `whereIn('tipo',['egreso','devolucion'])` with grouped `egreso OR (devolucion AND NOT EXISTS sale-in-window)`; update docblock. `$ventasDesglose` unchanged (AD-7). | +30/−6 | WU4 |
| `backend/app/Support/ReversalPairQuery.php` | Create | `aplicar(Builder $query, DateTimeInterface\|string\|null $desde, DateTimeInterface\|string\|null $hasta, bool $halfOpen = false): Builder` — the single `whereNotExists` predicate used by `ApuestaService` and `CierreService`. | +45 | WU4 |
| `backend/app/Services/PremiosLiquidador.php` | Create (WU5) | Extract `calcularPremio`/`esMultiSorteo` from `PagoController`; one engine path for the controller and the audit command. | +70 | WU5 |
| `backend/app/Models/{Pago,Apuesta,Ticket,DetalleApuesta}.php` | No change | Refund lookup uses `Pago::where('apuesta_id')->where('tipo','ingreso')`; legacy `Apuesta::pago()` hasOne untouched (R8/AD-9). No new relations, no casts. | 0 | — |
| `backend/tests/Unit/ApuestaPolicyAnulacionTest.php` | Create | Codes, order, boundary `>=`, hierarchy `taquilla → grupo → banca → 5`, ownership per role, optional minutes. | +110 | WU1 |
| `backend/tests/Feature/PagoTipoTest.php` | Rewrite | `devolucion` via `/pagos` → 422, no writes; annulment path covered in the new refund test. | +50/−45 | WU2 |
| `backend/tests/Feature/PagoIngresoRechazadoTest.php` | Create | 422 on `pendiente` and `ganadora`; no `Pago`; no state/prize/cascade change. | +70 | WU2 |
| `backend/tests/Feature/PagoEgresoUnicoMutanteTest.php` | Create | Only egreso mutates; sequential double egreso → one 201 + one 422 + one row; `for update` seen via `DB::listen`; legacy `pendiente`+resultado; detail uses engine value within tolerance. | +85 | WU2 |
| `backend/tests/Feature/ApuestaTest.php` | Modify | `ingreso` rejection on `ganadora` leaves `premio_ganado` intact; prize evidence asserts. | +45 | WU2 |
| `backend/tests/Feature/PagoDevolucionAnulacionTest.php` | Create | Refund mirrors sale amounts/rate/method; `anulada` + soft delete; ticket `anulada`; detail `null` untouched; log; retry 404; ownership 403; empty ticket 422; all-or-nothing; single-apuesta ticketless refund. | +240 | WU3-A |
| `backend/tests/Feature/PagoDevolucionRechazosTest.php` | Create | One case per rejection reason + boundary with `Carbon::setTestNow`; dynamic minutes 10/15/5. | +150 | WU3-B |
| `backend/tests/Feature/MetodoPagoTest.php` | Modify | Refund mirrors sale method; override; USD forced `efectivo`; invalid override 422. | +80 | WU3-B |
| `backend/tests/Feature/EliminacionApuestasTest.php` | Modify | Same strings; now a `devolucion` row exists after `DELETE /tickets`; window boundary. | +35 | WU1/WU3-A |
| `backend/tests/Feature/TicketAnuladosVisiblesTest.php` | Modify | Annulment still keeps the ticket visible; zero live apuestas; refund does not alter the shape. | +20 | WU3-B |
| `backend/tests/Feature/CuadreCajaReportTest.php` | Modify | Same-window pair → 0/0/0; cross-window refund counts; untied refund counts; annulled sale without refund no regression. | +100 | WU4 |
| `backend/tests/Feature/CierreCajaTest.php` | Modify | Pair excluded from totals; `total_efectivo_*` unchanged; `desglose_metodos` sums equal `total_ventas_*`; cross-window refund stays. | +70 | WU4 |
| `backend/tests/Feature/EstadisticaTest.php` | Modify | Same-day pair → `ventas 0`, `devolucion 0`, `saldo 0`; six keys unchanged; cross-midnight residual documented. | +55 | WU4 |
| `collections/Pagos/Registrar Pago (Token required).yml` | Modify | egreso-only, 422 messages, response `premio`, no `ingreso`/`devolucion`. | +12/−8 | WU2 |
| `collections/Tickets/Anular Ticket.yml` | Modify | Annulment + refund, body `metodo_pago`/`referencia`/`motivo`, 200 shape, 403/404/422. | +15/−4 | WU3-B |
| `collections/Apuestas/Eliminar Apuesta (Token required - 5 min rule).yml` | Modify | Ticketless-only refund; ticket lines → 422 redirect; cross-tenant 403. | +12/−4 | WU3-B |
| `docs/dev/motor-cierres-comisiones.md` | Modify | Reversal-pair rule and cross-window residual; update finding Alta #2 as fixed. | +12/−4 | WU4 |
| `backend/app/Console/Commands/AuditarPagosLegacy.php` | Create (gated) | Dry-run audit + explicit-id apply/revert; reuses the extracted `PremiosLiquidador`. | +170 | WU5 |
| `backend/tests/Unit/AuditarPagosLegacyTest.php` | Create (gated) | C1-C5 dry-run output; zero writes by default; apply refuses without explicit ids; revert reads `old_values`. | +70 | WU5 |
| `docs/dev/runbook-ops.md` | Modify (gated) | Dry-run/apply/revert procedure, owner approval. | +25 | WU5 |

**Net**: 5 production files created (annulment service, domain exception, reversal-pair helper, `PremiosLiquidador`, gated command), ~12 modified, 0 deleted, 0 migrations; the remaining new files are tests. WU3 is one deliverable split into two chained PRs (WU3-A service/cascade, WU3-B restriction/rejections/collections).

---

## 7. Contracts

### 7.1 `POST /api/v1/pagos`

Request (egreso): `apuesta_id` required|exists, `tipo` required (enum kept), `moneda` required (`bs|usd|mixto`), `amount_bs`/`amount_usd` nullable numeric min 0 (validation-only), `metodo_pago` nullable enum, `referencia` nullable string ≤255, `concepto` nullable string ≤255.

Response 201:

```json
{
  "success": true,
  "message": "Pago registrado exitosamente.",
  "data": { "...Pago con apuesta y creador..." },
  "premio": { "premio_bs": 300.0, "premio_usd": 0.0 }
}
```

`premio` and the persisted `pagos.amount_*` come from the engine. Request amounts are only a ±0.01 tolerance check; `detalle_apuestas.premio_ganado(_usd)` gets the engine values.

### 7.2 `DELETE /api/v1/tickets/{ticket}`

Optional body: `metodo_pago` (nullable enum), `referencia` (nullable string ≤255), `motivo` (nullable string ≤255). Response 200:

```json
{
  "success": true,
  "message": "Ticket anulado correctamente.",
  "data": {
    "ticket_id": 9,
    "apuestas": 2,
    "devoluciones": [{ "id": 41, "apuesta_id": 7, "amount_bs": "1000.00", "amount_usd": "0.00", "metodo_pago": "pago_movil", "moneda": "bs" }],
    "total_bs": 2000.0,
    "total_usd": 0.0
  }
}
```

`metodo_pago` resolution per line: explicit override → sale `ingreso.metodo_pago` (lowest `pagos.id` for `(apuesta_id, ingreso)`) → `efectivo`; then `Pago::resolverMetodoPago($metodo, $moneda, $amountUsd)` forces `efectivo` when USD is involved. `moneda` derives from the refund amounts (`mixto` if both > 0, `usd` if only USD, else `bs`), same rule as `createApuesta` (D12).

### 7.3 `DELETE /api/v1/apuestas/{apuesta}`

- The controller first calls `$this->authorize('view', $apuesta)` (tree ownership only; foreign caller → 403) so a line-level URL never leaks or mutates a ticket it does not own. The annulment window/state rules stay in `motivoNoAnulable` and are evaluated inside the transaction.
- `ticket_id !== null` → 422 `"La anulación se realiza a nivel de ticket: use DELETE /api/v1/tickets/{ticket_id}."` (no writes).
- Ticketless → policy-checked, `devolucion` `Pago`, `estado='anulada'`, soft delete, `apuesta.anulada` log, 200 `"Apuesta anulada correctamente."` + `data.devolucion`.

Ownership is checked for all roles via `ApuestaPolicy` (D13): `taquilla` own, `agencia`/`grupo`/`banca` subtree, `master`/`super_master` any. A foreign caller gets 403 before the ticket-level 422, so cross-tenant probing does not reveal whether the line belongs to a ticket.

### 7.4 Error contract

| Case | Status | Message |
|---|---|---|
| `tipo=ingreso` | 422 | `El ingreso se registra automáticamente al vender; no se admite por este endpoint.` |
| `tipo=devolucion` | 422 | `Las devoluciones se registran anulando el ticket: use DELETE /api/v1/tickets/{id}.` |
| `tipo` outside enum | 422 | Validation `tipo.in` (existing). |
| Apuesta non-existent / trashed | 422 validation / 404 | `apuesta_id.exists` / `Apuesta no encontrada.` (unchanged). |
| Cross-tenant egreso (taquilla) | 403 | `No autorizado.` (unchanged). |
| Non-payable state | 422 | `No se puede pagar una apuesta {estado}.` (unchanged). |
| No `resultado_id` | 422 | `No se puede pagar un premio sin un resultado asignado a la apuesta.` |
| Engine prize 0 | 422 | `La apuesta no resultó ganadora. No puede pagarse un premio.` |
| Amount mismatch > ±0.01 | 422 | `El monto del premio no coincide con lo calculado.` + `premio_esperado_*`, `monto_enviado_*`, `sugerencia`. |
| Unknown / trashed ticket | 404 | Route binding. |
| Ticket not `pendiente` | 422 | `Solo se pueden anular tickets pendientes.` |
| Ticket with no live apuestas | 422 | `El ticket no tiene jugadas activas para anular.` |
| `fuera_de_ventana` | 422 | `El ticket excedió los {N} minutos para ser anulado.` |
| `sorteo_ocurrido` | 422 | `El sorteo de una o mas jugadas ya ocurrio, no se puede anular.` (byte-identical, no accents) |
| `estado` / `con_resultado` line | 422 | `No se puede anular el ticket.` |
| `sin_ownership` | 403 | `No autorizado.` |
| `DELETE /apuestas` cross-tenant (view policy) | 403 | `No autorizado.` (checked before the ticket-level 422) |
| `DELETE /apuestas` on a ticket line | 422 | `La anulación se realiza a nivel de ticket: use DELETE /api/v1/tickets/{ticket_id}.` |

Precedence inside `motivoNoAnulable` is unchanged: `estado → con_resultado → sorteo_ocurrido → fuera_de_ventana → sin_ownership`. For ticket annulment (`DELETE /tickets`), a foreign line that is also outside the window therefore returns 422 (window code precedes ownership), exactly as today; a foreign line inside the window returns 403 (D13). For `DELETE /apuestas`, the controller's `view` pre-check runs first, so a cross-tenant line is always 403 before the ticket-level 422. Input validation (including `apuesta_id.exists`) runs before the type branch, so a malformed `ingreso` request can still return a validation 422; the flow-specific message is reached with a well-formed payload.

### 7.5 Service signatures (design-level)

```php
final class TicketAnulacionService
{
    public function __construct(ApuestaService $apuestaService, ApuestaPolicy $policy) {}

    /** @return array{ticket: Ticket, pagos: Collection<int,Pago>, apuestas: int, total_bs: float, total_usd: float} */
    public function anularTicket(Ticket $ticket, User $user, array $opciones = []): array;

    public function anularApuestaSuelta(Apuesta $apuesta, User $user, array $opciones = []): Pago;

    // private: assertAnulable, metodosVenta, registrarDevolucion, marcarAnulada, mensajeRechazo, registrarLog
}
```

`$opciones = ['metodo_pago' => ?string, 'referencia' => ?string, 'motivo' => ?string, 'ip' => ?string, 'user_agent' => ?string]`.

### 7.6 Audit log contract

`PagoController::store` (egreso only) writes exactly one `logs` row:

```json
{
  "action": "pago_premio",
  "details": {
    "apuesta_id": 7, "ticket_code": "ABC12345", "animal": "Perro",
    "premio_bs": 300.0, "premio_usd": 0.0, "tipo": "egreso",
    "pago_id": 41, "premio_fuente": "motor"
  }
}
```

`TicketAnulacionService::anularTicket` writes exactly one `logs` row; refund details are in the log, not only in `pagos`:

```json
{
  "action": "ticket_anulado",
  "details": {
    "ticket_id": 9, "ticket_code": "ABC12345", "motivo": "Error del operador",
    "apuestas": [7, 8],
    "devoluciones": [
      { "pago_id": 51, "apuesta_id": 7, "sale_pago_id": 11,
        "amount_bs": "1000.00", "amount_usd": "0.00",
        "exchange_rate_applied": "36.5000", "metodo_pago": "pago_movil", "moneda": "bs" }
    ],
    "total_bs": 2000.0, "total_usd": 0.0
  }
}
```

`anularApuestaSuelta` writes `action='apuesta.anulada'` with the same per-refund detail for one line (it replaces the old `apuesta.deleted` action on this endpoint; no consumer was found). A rejected request writes no `logs` row, and no flow writes `pago_premio` for a non-`egreso` type.

---

## 8. Transaction and lock design

| Operation | Locked rows | Re-checked inside the transaction | On failure |
|---|---|---|---|
| Egreso | `apuestas` row (`lockForUpdate`) | existence, ownership, `estado`, `resultado_id`, engine prize, ±0.01 | Closure returns an error tuple; the transaction commits nothing (no writes before validation). 404/403/422. |
| Ticket annulment | `tickets` row re-fetched **without** the soft-delete scope (`Ticket::whereKey($id)->lockForUpdate()->first()`), all live `apuestas` of the ticket (`lockForUpdate()->orderBy('id')`) | ticket exists (else 404), `estado='pendiente'`, non-empty live lines, policy per line, effective minutes | `TicketAnulacionException` → rollback of pagos, state, soft deletes, logs. 403/404/422. |
| Ticketless annulment | `apuestas` row | existence, ownership (view pre-check + policy), `ticket_id === null`, policy, effective minutes | Exception → rollback. |

Details:
- The lock is taken **before** any check that depends on row state; there is no read-then-write window.
- `lockForUpdate` on MySQL is a current read under `REPEATABLE READ`, so the second concurrent egreso sees the committed `pagada`.
- A retry after a committed annulment hits the route-binding 404 (ticket soft-deleted); the in-lock re-fetch also returns `null` → 404, so a stale model can never re-run the transition.
- The ticket cascade is intentionally not ticket-locked: it is idempotent and last-writer-wins; locking it would serialize unrelated egresos on the same ticket for no correctness gain. It skips `pagada`/`anulada` tickets and empty live-line sets.
- `Pago` inserts are append-only; no lock needed. Refund lookup of the sale `ingreso` is a single batched query (`whereIn(apuesta_id)`) before the loop.
- Failure leaves no partial artifacts: every write (refund, state, soft delete, log) is inside the closure.

---

## 9. Reports: exact queries and arithmetic

### 9.1 Reversal-pair predicate (single helper)

Implemented in `backend/app/Support/ReversalPairQuery.php` and consumed by both services. Applied to `Pago` queries only for `tipo = 'devolucion'`.

```php
->whereNotExists(function ($sub) use ($desde, $hasta) {
    $sub->selectRaw('1')
        ->from('apuestas as a_sale')            // raw: must see trashed sales
        ->whereColumn('a_sale.id', 'pagos.apuesta_id')
        ->where(fn ($w) => $w->where('a_sale.estado', 'anulada')
                              ->orWhereNotNull('a_sale.deleted_at'))
        ->when($desde, fn ($w) => $w->whereDate('a_sale.fecha_hora', '>=', $desde))
        ->when($hasta, fn ($w) => $w->whereDate('a_sale.fecha_hora', '<=', $hasta));
})
```

Properties:
- `from('apuestas as a_sale')` bypasses the `SoftDeletes` global scope (required: the matched sale is the trashed one).
- `pagos.apuesta_id IS NULL` → no match → `NOT EXISTS` true → untied refunds still count (no explicit `whereNull` branch needed).
- Date filters use the **sale** `fecha_hora`, not the refund `created_at`. When `$desde`/`$hasta` are absent, the report covers all time and any annulled/trashed sale is in scope.
- `desde`/`hasta` semantics per caller: `cuadreCaja`/`timeSeriesData` use `whereDate` (inclusive); `CierreService` uses half-open `[fechaInicio, fechaFin)`. The helper exposes `halfOpen: true` to switch to `>=`/`<` on `a_sale.fecha_hora`.

### 9.2 `ApuestaService::cuadreCaja` / `pagosCuadrePorNivel`

`pagosCuadrePorNivel(..., $tipo, ...)` adds the predicate only when `$tipo === 'devolucion'`. `Egreso` stays untouched. `Efectivo = Venta − Pagados − Devoluciones − Vencidos` remains arithmetic on the filtered sums.

### 9.3 `ApuestaService::timeSeriesData`

Step 5 refund query (`Pago::whereIn('taquilla_id',$taquillaIds)->where('tipo','devolucion')...`) adds the same predicate. `saldo = ventas − (premios + pagados + devolucion)`; the sales series already excludes the annulled sale.

### 9.4 `CierreService::calcularTotales`

Replace `whereIn('tipo', ['egreso','devolucion'])` with:

```php
->where(function ($q) use ($fechaInicio, $fechaFin) {
    $q->where('pagos.tipo', 'egreso')
      ->orWhere(function ($q2) use ($fechaInicio, $fechaFin) {
          $q2->where('pagos.tipo', 'devolucion')
             ->whereNotExists(fn ($sub) => $sub->selectRaw('1')
                 ->from('apuestas as a_sale')
                 ->whereColumn('a_sale.id', 'pagos.apuesta_id')
                 ->where(fn ($w) => $w->where('a_sale.estado', 'anulada')
                                       ->orWhereNotNull('a_sale.deleted_at'))
                 ->where('a_sale.fecha_hora', '>=', $fechaInicio)
                 ->where('a_sale.fecha_hora', '<', $fechaFin));
      });
})
```

The same filtered query feeds `total_egresos_*` and `egresosDesglose`, so totals and breakdown reconcile by construction.

### 9.5 `desglose_metodos` reconciliation

`$ventasDesglose` already runs through `Apuesta::where(...)->where('apuestas.estado','!=','anulada')`, so both the soft-delete scope and the state filter apply. An annulled sale and its `ingreso` disappear together. No code change; add `Σ desglose[bs].ventas === total_ventas_bs` (and USD→`efectivo`) assertions. Caveat: reconciliation assumes one `ingreso` per sale (guaranteed by `createApuesta`); legacy duplicate `ingreso` rows remain a pre-existing discrepancy (finding #8, out of D6).

### 9.6 Arithmetic examples (bs)

| Scenario | Venta | Devoluciones | Efectivo | Note |
|---|---|---|---|---|
| Sale 1000 @10:00 + refund 1000 @10:03, both in window | 0 | 0 | 0 | Reversal pair: both sides excluded. |
| Annulled sale, no refund | 0 | 0 | 0 | No regression (sale already excluded today). |
| Refund of a sale **outside** the window | 0 | 1000 | −1000 | Real cash outflow. |
| Untied refund (`apuesta_id = null`) | 0 | 500 | −500 | Still counts. |
| Cross-midnight pair inside one window | 0 | 0 | 0 (window) | Sale buckets to day1, refund to day2; per-day `saldo` is misattributed, window nets zero. |
| Same pair without D6 | 0 | 1000 | −1000 | The bug D10 prevents. |

`CierreService` uses the same arithmetic: a same-window pair leaves `total_ventas_*` and `total_egresos_*` unchanged by the pair, so `total_efectivo_*` is unchanged; a refund whose sale is in a previous closing remains an egreso of the new closing.

### 9.7 Residual limitations (documented, accepted)

1. Cross-window / cross-period: if the sale was in a previous closing and the refund lands after it, the past period reports 0 sales (state-based `Venta`) and the new period carries the refund. The pair nets `−R` across periods. With `tiempo_eliminacion ≤ 120` minutes this requires a closing inside the window or a sale near midnight. A closing already emitted cannot be re-issued for a past day. Fix requires `anulada_at` + reversal bucketing (separate change).
2. Cross-midnight inside one window: window totals net to zero, per-day series misattributes the refund day (see §9.6).
3. `cuadreCaja` derives `taquillaIds` from the sales query; a taquilla with no sale in the window is invisible to the payments query even if it has refunds in the window (pre-existing, explore §2.6).
4. `desglose_metodos` still depends on the sale's `ingreso` row existing (finding #8).

---

## 10. Legacy data: read-only audit + gated repair (design only)

### 10.1 Read-only audit queries

```sql
-- C1 + C2: paid without a prize payout (prize evidence at risk)
SELECT a.id AS apuesta_id, a.ticket_id, a.estado, a.resultado_id,
       a.amount_bs, a.amount_usd, a.sorteo_hora, a.created_at,
       d.premio_ganado, d.premio_ganado_usd,
       (SELECT COUNT(*) FROM pagos p WHERE p.apuesta_id = a.id AND p.tipo = 'egreso')    AS egresos,
       (SELECT COUNT(*) FROM pagos p WHERE p.apuesta_id = a.id AND p.tipo = 'ingreso')    AS ingresos,
       (SELECT COUNT(*) FROM pagos p WHERE p.apuesta_id = a.id AND p.tipo = 'devolucion') AS devoluciones
FROM apuestas a
LEFT JOIN detalle_apuestas d ON d.apuesta_id = a.id
WHERE a.estado = 'pagada'
  AND NOT EXISTS (SELECT 1 FROM pagos p WHERE p.apuesta_id = a.id AND p.tipo = 'egreso');

-- C3 + C4: tickets 'pagada' through a bogus line or with zero live lines
SELECT t.id AS ticket_id, t.ticket_code, t.estado, t.premio_total_bs, t.premio_total_usd,
       SUM(a.estado = 'pagada'
           AND NOT EXISTS (SELECT 1 FROM pagos p WHERE p.apuesta_id = a.id AND p.tipo = 'egreso')) AS lineas_pagadas_sin_egreso,
       COUNT(a.id) AS lineas_vivas
FROM tickets t
LEFT JOIN apuestas a ON a.ticket_id = t.id AND a.deleted_at IS NULL
WHERE t.estado = 'pagada'
GROUP BY t.id, t.ticket_code, t.estado, t.premio_total_bs, t.premio_total_usd
HAVING lineas_pagadas_sin_egreso > 0 OR lineas_vivas = 0;

-- C5: duplicated sale ingreso per apuesta
SELECT apuesta_id, COUNT(*) AS ingresos
FROM pagos WHERE tipo = 'ingreso' AND apuesta_id IS NOT NULL
GROUP BY apuesta_id HAVING COUNT(*) > 1;
```

### 10.2 Gated repair strategy (not implemented here)

1. `php artisan pagos:auditar-legacy --dry-run` → runs the queries, groups by taquilla, exports CSV; zero writes.
2. `--apply --ids=…` with explicit ids only (never a blanket update), after owner review:
   - **prerequisite:** extract `PagoController::calcularPremio` + `esMultiSorteo` into `App\Services\PremiosLiquidador` in WU5 so the command and the controller share one engine path (behavior-neutral extraction; controller delegates);
   - recompute the prize with the existing engine path (`calcularPremio`/`calcularPremioMultiSorteo` + `detalle.premios_snapshot`);
   - set `estado` to `ganadora` (prize > 0) or `perdida` (prize = 0), never back to `pendiente`;
   - restore `premio_ganado(_usd)`;
   - recompute ticket `estado` (`anulada` if zero live lines, `ganador` if any unpaid winner, `pagada` if all paid);
   - keep the offending `pagos` rows (cash evidence) and write one `logs` entry per change;
   - never touch `pagos.amount_*`, `cierres_caja`, or `comisiones`.
3. Cross-check against `tickets.premio_total_*`; multi-line tickets that do not reconcile go to manual review, not automatic writes.
4. `--revert` reads the stored `old_values` JSON from `logs`. Gated by owner approval; WU5.

---

## 11. Test matrix

| Area | Cases | File |
|---|---|---|
| Type rejection | `ingreso` and `devolucion` on `/pagos` → 422, message, zero `Pago`, estado/prize/cascade untouched; `ingreso` on `ganadora` keeps `premio_ganado` | `PagoIngresoRechazadoTest`, `ApuestaTest`, rewritten `PagoTipoTest` |
| Egreso | happy path; legacy `pendiente`+resultado; no resultado; zero prize; mismatch > ±0.01; tolerance ≤ ±0.01 persists engine value; response `premio`; detail uses engine value | `PagoPremioSinMontosTest` (unchanged), `ApuestaTest`, `PagoEgresoUnicoMutanteTest` |
| Concurrency | sequential double egreso → one 201, one 422, one row; `DB::listen` sees `for update`; cascade guard skips empty/annulled tickets | `PagoEgresoUnicoMutanteTest` |
| Annulment happy | single-line and multi-line ticket; sale amounts/rate; method mirrored; override; USD→`efectivo`; `apuestas`+`ticket` `anulada` + soft-deleted; `detalle` untouched; `ticket_anulado` log; retry → 404; `TicketAnuladosVisiblesTest` shape | `PagoDevolucionAnulacionTest`, `MetodoPagoTest`, `EliminacionApuestasTest`, `TicketAnuladosVisiblesTest` |
| Annulment rejection | `ganadora`, `pagada`, `perdida`, `vencido`, `anulada`; `pendiente` + `resultado_id`; `sorteo_hora` past; `created_at` boundary `>=` with `Carbon::setTestNow`; dynamic minutes 5/10/15; cross-tenant 403; unknown ticket 404; ticket with zero live lines 422; one bad line → whole ticket untouched | `PagoDevolucionRechazosTest`, `EliminacionApuestasTest` |
| `DELETE /apuestas` | ticketless → 200 + `devolucion` + `anulada` + soft delete; ticket line → 422 redirect; cross-tenant → 403 (before redirect); out-of-window ticketless → 422; no partial writes | `PagoDevolucionAnulacionTest`, `PagoDevolucionRechazosTest` |
| Policy unit | every code; ordering; boundary; hierarchy cascade; role ownership; optional precomputed minutes | `tests/Unit/ApuestaPolicyAnulacionTest` |
| Reports | same-window pair 0/0/0; annulled sale no refund; cross-window refund counts; untied refund counts; cross-midnight residual; cierre totals + `desglose_metodos` reconciliation; six series keys | `CuadreCajaReportTest`, `CierreCajaTest`, `EstadisticaTest` |

Commands:

```bash
cd backend
composer test                                              # project contract
php artisan test --filter=PagoDevolucion                   # refund slice
php artisan test --filter='Pago|TicketAnulacion|ApuestaPolicy|CuadreCaja|CierreCaja|Estadistica'
vendor/bin/pint --test                                     # CI-enforced style
```

Note on concurrency: PHPUnit runs one connection, so a true race is not reproducible. Determinism comes from the sequential double-pay test plus a query-log assertion that the egreso path issues a `for update` select; the lock semantics themselves are MySQL's.

---

## 12. Work units and PR slices (`auto-chain`, 800-line review budget)

| WU | Content | Depends on | Est. changed lines | Verification / rollback boundary |
|---|---|---|---|---|
| WU1 | `ApuestaPolicy::motivoNoAnulable` + `delete()` delegation + `TicketController` reuse + unit/reason tests. Behavior-neutral (same strings, same outcomes). | — | 280–320 | `php artisan test --filter='ApuestaPolicyAnulacion|EliminacionApuestas'` + `vendor/bin/pint --test`. Revert alone; `delete()` semantics restored, no data effect. |
| WU2 | `PagoController` egreso-only + transaction/lock + engine-authoritative writes + cascade guard + tests + payments collection. | — (parallel to WU1) | 560–650 | `php artisan test --filter='Pago|ApuestaTest'` + `vendor/bin/pint --test`. Revert restores multi-tipo behavior; no data effect if no refunds exist. |
| WU3-A | `TicketAnulacionService` + exception + `TicketController::destroy` delegation + happy-path refund tests. | WU1 | 600–660 | `php artisan test --filter=PagoDevolucion` + `vendor/bin/pint --test`. Revert reopens ticket annulment without refund; safe only if no production refunds recorded. |
| WU3-B | `ApuestaController::destroy` restriction + rejection matrix + metodo-pago refund tests + collections. | WU3-A | 320–400 | `php artisan test --filter='PagoDevolucion|MetodoPago|AgenciaScope'` + `vendor/bin/pint --test`. Revert alone leaves ticket refunds working; restores line-level soft delete (undesirable). |
| WU4 | Report reversal-pair predicate (`cuadreCaja`, `timeSeriesData`, `CierreService`, `ReversalPairQuery`) + report tests + docs. | WU3-A/WU3-B (regression fixtures) | 340–390 | `php artisan test --filter='CuadreCaja|CierreCaja|Estadistica'` + `vendor/bin/pint --test`. **Must ship in the same release as WU3-A/WU3-B.** Reverting WU4 alone reintroduces `Efectivo = −R`. |
| WU5 (gated) | `pagos:auditar-legacy` dry-run/apply/revert command + `PremiosLiquidador` extraction + unit test + runbook. | WU2/WU3 | 310–360 | `php artisan test --filter=AuditarPagosLegacy`; dry-run review before any `--apply`. Off by default; no runtime effect; owner-approved execution only. |

WU3 is one deliverable split into two chained PRs (WU3-A = service + cascade; WU3-B = endpoint restriction + rejection/collection surface). Total forecast ≈ **2.3k–2.7k changed lines** across 5 work units / 6 chained PRs, each under 800. Chain PRs onto a feature/tracker branch; cut the release only after WU4. Never merge WU3-A/WU3-B directly to a deployable `main` without WU4.

Release gating (D6/R1): WU3-A + WU3-B + WU4 form one release unit. WU5 is a separate, owner-gated ops slice. Every slice ends with `cd backend && composer test` (the project contract); focused filters above are for the inner TDD loop.

---

## 13. Risks and tradeoffs

| # | Risk | Severity | Mitigation / decision |
|---|---|---|---|
| R1 | D1+D4 without D6 → visible cash error (`Efectivo = −R`) | High | WU4 in the same release; release gate documented in §12. |
| R2 | D8 is a breaking API change (`ingreso`/`devolucion` → 422) | High | No in-repo consumer found (taquilla/panel only send `egreso`; collections document `egreso`); release note required; owner review. |
| R3 | Refund is irreversible (money out + soft delete) | High | Policy re-check inside the transaction, explicit response, audit log, retry → 404; no un-refund endpoint by design; owner review D9. |
| R4 | Cross-window/cross-period residual double count | Medium | Accepted + documented (§9.7); schema-level fix is a separate change. |
| R5 | Policy message drift | Medium | Codes + one mapper; existing strings pinned by `EliminacionApuestasTest`/`TicketEliminacionTest`; unit tests on codes. |
| R6 | Concurrency tests are sequential | Medium | Row lock + query-log assertion + code review; MySQL current-read semantics. |
| R7 | `DELETE /apuestas` behavior change (line inside a ticket → 422; ticketless out-of-window → 422 instead of 403; cross-tenant remains 403) | Medium | No in-repo consumer; collection updated (WU3-B); owner decision. The `colecciones-api` delta does not mention this endpoint — recommend a one-line delta or doc-only sign-off. |
| R8 | Receipt/reprint `GET /pagos/{apuesta}` returns `ingreso`+`egreso` and `recibos.ts` sums them → inflated prize | Medium (pre-existing) | Deferred (AD-9). Evidence: the sale `ingreso` has always been in the array; the refund cannot reach a reprint because an annulled ticket exposes zero live apuestas. Follow-up receipt slice. |
| R9 | Sale `ingreso` missing (legacy C5) → refund method falls back to `efectivo` | Low | Explicit fallback + audit detail records the resolved method; WU5 audit surfaces the class. |
| R10 | `tickets.total_bs/usd` and `premio_total_*` are not recomputed on annulment | Low | Intentional: they are historical sale/prize evidence; reports read `apuestas`. |
| R11 | `cuadreCaja` taquilla-visibility quirk hides refunds of taquillas with no sales in the window | Low | Pre-existing, out of scope; documented. |
| R12 | `diffInMinutes` is absolute; a future `created_at` reads as a small age | Low | Not fixed; tests freeze time; refund checks use the policy as-is. |
| R13 | `metodo_pago` override is not exposed in the taquilla UI | Low | Default mirrors the sale; override available for API clients. |
| R14 | A code rollback after production refunds leaves annulled sales + `devolucion` rows | High (ops) | Forward-fix preferred. If rollback is unavoidable: keep WU4 deployed, revert WU3 last, and expect the old `/pagos` bug to return. Document in the release note. |

### Owner-review decisions embedded in this design

- **D8** (egreso-only) — affects WU2 and the public contract.
- **D9** (ticket-level annulment + refund; irreversible) — affects WU3-A/WU3-B.
- **D10** (window-aware exclusion; cross-window residual accepted) — affects WU4.
- **AD-4** (`DELETE /apuestas` restricted to ticketless) — needs sign-off and a doc delta decision.
- **AD-9** (receipt deferral) — needs sign-off on the deferred follow-up.

---

## 14. Migration / rollout

- **No migration.** All target enum values already exist (`apuestas`: `anulada`; `tickets`: `anulada`; `pagos`: `devolucion`). Soft deletes already present. No index changes.
- Deploy WU1 → WU2 → WU3-A → WU3-B → WU4 as one release unit; WU5 separately gated.
- Front compatibility: taquilla sends `{apuesta_id, tipo:'egreso', moneda}` and `DELETE /tickets/{id}`; both keep their status/message contracts (the refund adds fields). Panel only reads report shapes that do not change.
- Expected observable change on deploy: `POST /pagos` with `ingreso`/`devolucion` returns 422; ticket annulment writes refund rows; reports may change for legacy annulled sales (more correct) as soon as WU4 ships.
- Release note: "`POST /pagos` is now egreso-only; income is recorded automatically at sale; refunds happen only through ticket annulment (`DELETE /tickets/{id}`)."

---

## 15. Threat matrix

N/A — no new routing, shell commands, subprocesses, VCS/PR automation, executable-file classification, or process-integration boundary. Existing routes change behavior only.

---

## 16. Open questions (owner)

1. Approve **D8** (breaking egreso-only contract) before WU2.
2. Approve **D9** (whole-ticket annulment + refund, irreversible) before WU3-A.
3. Approve **D10** and the accepted cross-window/cross-midnight residual before WU4.
4. Approve **AD-4** (`DELETE /apuestas` restricted to ticketless sales) and decide whether the `colecciones-api` delta needs a one-line addition or doc-only sign-off.
5. Confirm **AD-9** (receipt/reprint fix deferred to a separate slice).
6. Confirm **WU5** ownership and gating (dry-run review before any `--apply`).
