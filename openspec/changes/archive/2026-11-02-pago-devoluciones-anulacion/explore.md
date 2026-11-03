# Exploration: `pago-devoluciones-anulacion`

Issue: GitHub #66 — `PagoController::store` marks the apuesta `pagada` for every `pagos.tipo`, overwrites `detalle_apuestas.premio_ganado`, and triggers the ticket cascade. Only `egreso` should resolve a prize payment.
Phase: `sdd-explore` (read-only; no source, test, or spec file was modified).
Repo root: `/home/gzuz/Documentos/lotto-app`.
Baseline commit: `main` (working tree only has untracked docs/collections dumps).

---

## 1. Summary

`PagoController::store` has one linear body for the three `pagos.tipo` values
(`backend/app/Http/Controllers/Api/PagoController.php:25-210`). The guard at
`:76-81` only checks `estado ∈ {pendiente, ganadora}`; after that, **every** tipo
falls through to the same tail: `Pago::create` (`:132-148`),
`apuesta.estado='pagada'` (`:151`), `detalle_apuestas.premio_ganado(_usd)`
overwrite (`:153-160`), and the ticket cascade (`:163-177`). There is no
`DB::transaction`, no per-tipo validation, and no duplicate-payment lock.

Consequences confirmed by reading the code:

- `ingreso` and `devolucion` resolve a prize that was never paid, and overwrite
  the engine-computed `premio_ganado` with the request amounts (typically `0`).
- A `devolucion` on a `ganadora` apuesta erases the prize evidence and marks the
  ticket `pagada`.
- Deviations are *retroactive*: the cascade at `:168-172` treats an empty live
  apuesta collection as "all resolved" (`Collection::every()` on an empty
  collection returns `true`), so a ticket whose lines were all annulled becomes
  `pagada`, not `anulada`.

The product decisions D1–D7 are all implementable without schema changes:
`apuestas.estado` already includes `anulada`
(`database/migrations/2026_09_17_000001_add_ganadora_to_apuestas_estado.php:23`),
`tickets.estado` too (`2026_08_11_000004_add_vencido_to_estados.php:15`), both
models already `use SoftDeletes` (`app/Models/Apuesta.php:12`,
`app/Models/Ticket.php:12`), and `pagos.tipo` already allows `devolucion`
(`2026_08_12_000003_alter_pagos_tipo_add_devolucion.php:22`).

Two independent defects surface in the same code path and must be decided with
#66 or explicitly deferred: (a) the missing transaction/row lock (double payout
under concurrency), and (b) the double-subtraction that D1+D4 will introduce in
reports unless D6 is fixed in the same change.

---

## 2. Verified current behavior

### 2.1 Request flow (`PagoController::store`)

| Step | Location | Behavior |
|---|---|---|
| Input validation | `:28-52` | `tipo ∈ {ingreso,egreso,devolucion}`, `moneda ∈ {bs,usd,mixto}`, amounts `nullable|numeric|min:0` |
| Load | `:58` | `Apuesta::with(['juego','resultado','detalles'])` — soft-deleted rows are **404** |
| Ownership | `:68-70` | **Only** `role==='taquilla'` is checked against `taquilla_id`; `banca/grupo/master/super_master` pass for any apuesta |
| State guard | `:76-81` | `estado ∈ {pendiente,ganadora}` else 422 |
| Egreso-only branch | `:84-129` | requires `resultado_id`, engine prize `> 0`, ±0.01 amount check, fills missing amounts from the engine |
| Payment insert | `:132-148` | `Pago::create` — **not** inside a transaction |
| State mutation | `:151` | `estado='pagada'` for every tipo |
| Premio overwrite | `:153-160` | `premio_ganado = amount_bs`, `premio_ganado_usd = amount_usd` for every tipo |
| Ticket cascade | `:163-177` | marks ticket `pagada` when `every()` live apuesta is `pagada/anulada/perdida/vencido` or trashed |
| Audit log | `:180-193` | `action='pago_premio'` for every tipo |

### 2.2 Behavior matrix (`tipo` × `estado`)

| `apuesta.estado` | `egreso` | `devolucion` | `ingreso` |
|---|---|---|---|
| `pendiente`, no `resultado_id` | **422** `:85-90` (no resultado) | **201** → `pagada`, `premio_ganado` overwritten, cascade | **201** → `pagada`, `premio_ganado` overwritten, cascade |
| `pendiente`, with `resultado_id` (legacy) | 201 if engine prize > 0 | **201** → `pagada`, overwrite | **201** → `pagada`, overwrite |
| `ganadora` | 201 (correct path) | **201** → `pagada`, prize evidence **destroyed** | **201** → `pagada`, prize evidence **destroyed** |
| `pagada` | 422 (guard) | 422 (guard) | 422 (guard) |
| `perdida` | 422 (guard) | 422 (guard) | 422 (guard) |
| `vencido` | 422 (guard) | 422 (guard) | 422 (guard) |
| `anulada` | 422 (guard) | 422 (guard) | 422 (guard) |

Note the guard at `:76` **admits `ganadora` for every tipo**, which is the
highest-damage combination: a `devolucion`/`ingreso` request on a pending prize
marks it paid and zeroes the prize, and the cascade then marks the ticket
`pagada` (`:168-175`).

Already documented as finding Alta #2 in
`docs/dev/motor-cierres-comisiones.md:254`, so the issue is acknowledged but
never implemented against.

### 2.3 Existing annulment path (the real "anulación" today)

`DELETE /api/v1/tickets/{ticket}` → `TicketController::destroy`
(`backend/app/Http/Controllers/Api/TicketController.php:223-261`):

1. `ticket.estado === 'pendiente'` else 422 (`:227-229`).
2. `getEffectiveTiempoEliminacion($ticket->taquilla_id)` (`:231-232`).
3. Per apuesta, `Gate::denies('delete', $apuesta)` (`:236`) and map the failure to
   a message by re-deriving the reason (`:237-242`).
4. `DB::transaction`: per apuesta `delete()` (soft delete), `ticket.estado='anulada'`,
   `ticket->delete()` (`:252-258`).

`DELETE /api/v1/apuestas/{apuesta}` → `ApuestaController::destroy`
(`backend/app/Http/Controllers/Api/ApuestaController.php:257-284`): `authorize('delete')`,
`$apuesta->delete()`, audit log. No ticket cascade.

Observations:

- **`apuestas.estado='anulada'` is never written by application code.** Only
  tests set it (`tests/Feature/CierreCajaTest.php:221`,
  `tests/Feature/ComisionCierreTest.php:202`). Annulment is *only* a soft delete;
  the state stays `pendiente`.
- `TicketController::destroy` creates **no `Pago`**; a ticket can be annulled
  with no refund recorded.
- The reasons from `ApuestaPolicy::delete` are duplicated in
  `TicketController::destroy:237-242` (message mapping), which is exactly the
  duplication D7 asks to avoid.

### 2.4 The single source of annulment rules: `ApuestaPolicy::delete`

`backend/app/Policies/ApuestaPolicy.php:43-81`:

| Rule | Line | Detail |
|---|---|---|
| estado | `:45-47` | must be `pendiente` |
| resultado | `:49-51` | `resultado_id` must be `null` |
| draw | `:53-55` | `sorteo_hora` (`?->isPast()`) must be in the future |
| window | `:57-61` | `created_at->diffInMinutes(now()) >= getEffectiveTiempoEliminacion(taquilla_id)` → deny |
| ownership | `:63-80` | taquilla own; agencia own; grupo ancestor; banca ancestor; `super_master|master` |

`getEffectiveTiempoEliminacion` (`backend/app/Services/ApuestaService.php:214-228`)
is the hierarchy cascade `taquilla → grupo → banca → 5`.
`tiempo_eliminacion` is validated `integer|min:1|max:120` at every level
(`BancaController.php:47`, `GrupoController.php:84`, `TaquillaController.php:88`)
and children cannot exceed their parent (`GrupoController.php:121-126`,
`TaquillaController.php:148+`). So a refund is always within **2 hours** of the
sale — relevant for the report window analysis in §5.

The policy returns `bool` only; there is no reason code. `TicketController` needs
messages, which is why it duplicates the rules. This is the method to extract.

### 2.5 States, soft delete, and the cascade blind spot

`Apuesta` and `Ticket` both use `SoftDeletes`. `Ticket::apuestas()`
(`app/Models/Ticket.php:45-48`) therefore **excludes trashed apuestas**, so in
`PagoController:168` `$ticket->apuestas->every(...)` is vacuously `true` for a
ticket whose lines are all trashed → the ticket is marked `pagada`. This matters
as soon as a refund/annulment is performed through `POST /pagos` without deleting
the ticket (the ticket itself is only soft-deleted by `TicketController::destroy`).

### 2.6 Reporting queries that read these states

| Report | Location | Sale filter | Refund source |
|---|---|---|---|
| `cuadreCaja` | `ApuestaService.php:779-928` | base query `where('apuestas.estado','!=','anulada')` `:819`, plus soft-delete global scope | `pagosCuadrePorNivel(..., 'devolucion', ...)` `:869`, `:935-965` |
| `timeSeriesData` | `ApuestaService.php:1104-1240` | `where('apuestas.estado','!=','anulada')` `:1138`, premios `:1154` | `Pago where tipo='devolucion'` by `pagos.created_at` `:1201-1213` |
| `ventasTotales` | `ApuestaService.php:604-701` | `where('apuestas.estado','!=','anulada')` `:615`, `COALESCE(SUM(detalle.premio_ganado),0) as Premio` `:671` | none |
| `CierreService::calcularTotales` | `CierreService.php:230-295` | `where('estado','!=','anulada')` `:232-237`, sales breakdown joins `pagos.tipo='ingreso'` `:243-251` | `whereIn('tipo',['egreso','devolucion'])` `:254-257` |
| commissions | `ComisionService.php:267-270` | `estado != 'anulada'` + soft-delete scope | none |

`Efectivo`/`saldo` arithmetic:

- `cuadreCaja`: `Efectivo = Venta − Pagados − Devoluciones − Vencidos` (`:896`)
- `timeSeriesData`: `saldo = ventas − (premios + pagados + devolucion)` (`:1227`)
- `CierreService`: `total_efectivo_bs/usd = ventas − egresos` (`:290-291`)

One structural quirk to keep in mind: `cuadreCaja` derives
`$taquillaIds = (clone $query)->distinct()->pluck('apuestas.taquilla_id')`
(`:863`) from a query already filtered by `apuestas.fecha_hora`
(`ReporteController.php:48-53`). A taquilla with no sale inside the window is
therefore invisible to the payments query, even if it has refunds in the window.
Pre-existing, out of scope, but it bounds what a report fix can achieve.

---

## 3. Correct state transition and where the refund belongs

### 3.1 Target semantics (D2 + D4)

```
pendiente (no resultado_id, sorteo_hora > now, age < effective minutos)
   └── POST /pagos {tipo: devolucion}  OR  DELETE /tickets/{id}
        ├── Pago(tipo=devolucion, amount_bs/usd = sale amounts, exchange_rate_applied = sale rate)
        ├── apuesta.estado = 'anulada'  then  soft delete
        ├── detalle_apuestas: UNTOUCHED (premio_ganado stays null)
        └── ticket: 'anulada' + soft delete only when no live apuesta remains
```

`ganadora | pagada | perdida | vencido | anulada | pendiente with resultado_id |
sorteo_hora in the past | age >= window` → **422**, no `Pago`, no mutation.

### 3.2 Options for placement

**A — New `ApuestaAnulacionService` called by all three entry points (recommended).**

- `PagoController` `devolucion` branch → `anular($apuesta, $user, motivo, registrarDevolucion: true)`.
- `TicketController::destroy` → per apuesta `anular(..., registrarDevolucion: false|true)` inside one transaction.
- `ApuestaController::destroy` → same service (keeps its `motivo` field and log shape).
- Pros: one transition, one transaction, one cascade; the policy stays the single
  source of truth; both controllers stop duplicating messages; the `Pago` creation
  lives with the state change (atomic by construction).
- Cons: one new class (~120 lines) and two existing controllers change.
- Effort: **Medium**.

**B — `TicketController::destroy` creates the refund, `PagoController` delegates to it.**

- Pros: no new class.
- Cons: a controller calling another controller; `destroy` is ticket-scoped while
  `POST /pagos` is apuesta-scoped; conflicting transactions and guards. Rejected.

**C — Keep the logic in `PagoController` and have `TicketController` replicate it.**

- Cons: contradicts D7, creates a third copy of the window rules. Rejected.

**D — Fat method inside `ApuestaService`.**

- Viable (the class already owns `getEffectiveTiempoEliminacion`), but the file is
  1489 lines and the transition is not a query helper. A dedicated service keeps
  the blast radius readable. Second choice.

### 3.3 The policy extraction (D7)

Add to `ApuestaPolicy`:

```php
/** @return string|null null = annullable; otherwise a stable reason code */
public function motivoNoAnulable(User $user, Apuesta $apuesta): ?string
// codes: 'estado', 'con_resultado', 'sorteo_ocurrido', 'fuera_de_ventana', 'sin_ownership'

public function delete(User $user, Apuesta $apuesta): bool
{
    return $this->motivoNoAnulable($user, $apuesta) === null;
}
```

- `TicketController::destroy` replaces `:234-250` with one call per apuesta and maps
  the code to the current exact messages (`fuera_de_ventana` needs the effective
  minutes, already fetched at `:231`).
- `PagoController` `devolucion` branch uses the same call (no `Gate` string
  duplication); a `null` reason plus the money checks is the only path to 201.
- No behavior change in the policy itself; `delete` keeps working for
  `ApuestaController::destroy` (`:259`) and `TicketController` (`:236`).

### 3.4 Refund amounts must be backend-authoritative

The `egreso` branch already ignores client amounts when omitted and validates
±0.01 when present (`:102-128`). The refund must mirror the sale:

```php
amount_bs           = apuesta.amount_bs
amount_usd          = apuesta.amount_usd
exchange_rate_applied = apuesta.exchange_rate_applied
moneda              = both > 0 ? 'mixto' : (usd > 0 ? 'usd' : 'bs')   // same rule as ApuestaService:490
metodo_pago         = request ?? sale ingreso's metodo_pago ?? 'efectivo'
```

Otherwise a caller can fabricate a refund larger than the sale (today
`amount_bs|usd` are free-form for `devolucion`, `:30-31`).

### 3.5 Transaction and concurrency (must be in scope)

`Pago::create` at `:132` and the state write at `:151` are not atomic: any failure
in between leaves a payment for an apuesta that is still `pendiente`, which the
next request can pay again (`pagos` has no unique index on
`(apuesta_id, tipo)`). The state guard is also read-then-write without a lock.
Recommendation: `DB::transaction` + `Apuesta::lockForUpdate()->find()` inside the
service for `egreso` and `devolucion`. This is a money defect adjacent to #66; it
is cheap to include while the body is being split and expensive to discover later.

---

## 4. Reuse of the annulment window — exact mapping

D2 conditions map 1:1 onto `ApuestaPolicy::delete`:

| D2 condition | Policy line | Reuse |
|---|---|---|
| `estado = pendiente` | `:45-47` | direct |
| no `resultado_id` | `:49-51` | direct |
| `sorteo_hora` in the future | `:53-55` | direct |
| within effective `tiempo_eliminacion` | `:57-61` | direct (`created_at`, not `fecha_hora`) |
| ownership by role | `:63-80` | direct |

Nothing new is needed for the window itself; the only gap is that the policy
cannot explain *why* it denied, and `TicketController` re-derives the reasons
(`:237-242`). Extracting `motivoNoAnulable` (§3.3) closes that gap without
touching the rules.

Note for tests: `ApuestaPolicy` measures `created_at`, while `fecha_hora` is the
sale timestamp used by every report. Tests must set `created_at` explicitly
(as `EliminacionApuestasTest::crearTicketConApuesta` does at `:84-85`) or freeze
time; otherwise a 5-minute window is indistinguishable from "always allowed".

Second note: `diffInMinutes` is absolute. A row with a future `created_at` reads
as a small positive age. Not worth fixing here, but do not build the refund check
on `abs()` semantics by accident.

---

## 5. Reports: the double-count D6 is real, with numbers

### 5.1 Arithmetic

Same-day sale `S = 1000 bs` at 10:00, refund `R = 1000 bs` at 10:03. Report window
covers both.

| Scenario | `Venta` | `Devoluciones` | `Efectivo` | Verdict |
|---|---|---|---|---|
| **Today** (`devolucion` sets `pagada`, `:151`) | 1000 | 1000 | **0** | arithmetically right, state wrong |
| **After D1+D2+D4 without a report fix** (annulled + soft-deleted) | 0 | 1000 | **−1000** | double subtraction |
| **Correct** (reversal pair) | 0 | 0 | **0** | money in and out cancel |

The same shift hits `timeSeriesData` `saldo` (`:1227`) and
`CierreService::total_efectivo_*` (`:290-291`). So **D6 is not optional**: the
state fix converts a hidden state bug into a visible cash error.

Why the sale leaves `Venta`: `cuadreCaja` filters `apuestas.estado != 'anulada'`
(`:819`) and the base query is an Eloquent `Apuesta` query, so the soft-delete
scope also applies (`:815`). Same for `CierreService:232-237` and
`timeSeriesData:1137-1143`.

Extra inconsistency in `CierreService`: the *sales breakdown by method* joins
`pagos` with `tipo='ingreso'` **through the non-deleted, non-annulled apuesta**
(`:243-251`), while totals come from `apuestas`. After annulment both sides must
disappear together, or `desglose_metodos` stops summing to `total_ventas_bs`
(already an untested risk, `docs/dev/motor-cierres-comisiones.md:262-264`).

### 5.2 Recommended fix (least invasive, window-aware)

Exclude a `devolucion` from the outflow **only when its sale is already excluded
from the same report window** — i.e. the sale is annulled and its `fecha_hora`
falls inside the window. Refunds of sales outside the window remain real cash
outflows.

Applied to `pagosCuadrePorNivel` when `$tipo === 'devolucion'`
(`ApuestaService.php:935-965`):

```php
->where(function ($q) use ($desde, $hasta) {
    $q->whereNull('pagos.apuesta_id')
      ->orWhereNotExists(function ($sub) use ($desde, $hasta) {
          $sub->select(DB::raw(1))
              ->from('apuestas as a_sale')          // raw: must see soft-deleted rows
              ->whereColumn('a_sale.id', 'pagos.apuesta_id')
              ->where(function ($w) {
                  $w->where('a_sale.estado', 'anulada')->orWhereNotNull('a_sale.deleted_at');
              })
              ->when($desde, fn ($w) => $w->whereDate('a_sale.fecha_hora', '>=', $desde))
              ->when($hasta, fn ($w) => $w->whereDate('a_sale.fecha_hora', '<=', $hasta));
      });
})
```

- `whereNull('pagos.apuesta_id')` keeps manual/untied refunds (the test helpers
  create them that way, e.g. `CuadreCajaReportTest::crearPago` `:77-89`).
- The `orWhereNotNull('a_sale.deleted_at')` covers legacy annulments (state is
  still `pendiente`) without requiring a backfill.
- `->from('apuestas')` on the query builder bypasses the SoftDeletes scope, which
  is required: the sale row we are matching **is** the trashed one.

Same predicate in `timeSeriesData` step 5 (`:1201-1207`) and in
`CierreService::calcularTotales` (`:254-257`): the latter must split
`whereIn('tipo',[...])` into an unconditional `egreso` term plus a guarded
`devolucion` term with the closing window `[fechaInicio, fechaFin)`.

### 5.3 Alternatives considered

| Option | Pros | Cons |
|---|---|---|
| **A. Window-aware refund exclusion** (recommended) | exact for the same-window reversal; no schema change; touches 3 queries | one `whereNotExists` per query; cross-window residual below |
| B. Count sales gross (include `anulada` in `Venta`) | trivial, no subquery; pair always nets | contradicts the documented "ventas no anuladas" rule (`openspec/specs/comisiones/spec.md:73-85`), would leak into commissions and `ventasTotales`; changes every existing expectation |
| C. Add an `Anuladas` column and subtract nothing | explicit reporting | new API field, front changes (`panel/src/pages/cuadre.astro`), does not fix `saldo`/cierre |
| D. Drop `devolucion` rows that point to annulled sales unconditionally | simplest | loses a real outflow if the sale was in a previous window; still wrong for the closing that already included the sale |

### 5.4 Residual limitation (document, do not solve)

If the sale was included in a **closed** period and the refund lands after that
close, the closed snapshot keeps the sale and the next period carries the refund —
which is economically correct. The problem is that `Venta` is state-based, so after
the annulment the *past* period now reports 0 sales **and** the refund is in the
new period. Option A keeps the refund there (correct as cash), so the pair nets
`−R` across the two periods.

With `tiempo_eliminacion ≤ 120` minutes and refunds only pre-draw, the only way to
straddle periods is an operator closing the box inside the window, or a sale just
before midnight. Recommend: keep Option A, document the residual in
`docs/dev/motor-cierres-comisiones.md`, and note that a closing already emitted
cannot be re-issued for a past day (`CierreService.php:56-76` only re-closes the
current day). A schema-level fix (`anulada_at` + reversal bucketing) is a separate
change.

---

## 6. `ingreso` via `POST /pagos` — recommendation: reject (422)

Evidence:

1. Every sale already writes its `ingreso` row inside `createApuesta`
   (`ApuestaService.php:489-503`: amounts, rate, moneda, `metodo_pago`,
   `concepto='Compra de ticket'`). A second `ingreso` through `POST /pagos` is a
   **duplicate ledger entry for an existing sale**, not a new cash movement.
2. It corrupts state (`:151`, `:153-160`) — the whole point of the issue.
3. It corrupts the closing breakdown: `CierreService:243-251` sums
   `pagos.amount_bs/usd` grouped by `metodo_pago` for sales, while
   `total_ventas_*` comes from `apuestas` (`:232-241`). A manual `ingreso`
   inflates the breakdown without touching the total, so
   `desglose_metodos` no longer reconciles with `total_ventas_bs`.
4. No consumer sends it: `grep` over `taquilla/`, `panel/`, and `collections/`
   finds no `tipo:'ingreso'` call to `/pagos`; `collections/Pagos/*.yml` only
   documents `egreso` (`collections/Pagos/Registrar Pago (Token required).yml:14-19`,
   `docs/dev/integracion-front-motor-premios.md:55-64`).
5. RF-05.3 already states that incomes must not alter the apuesta or the prize
   (`docs/cliente/especificacion-requisitos-software.md:255`).

Options:

| Option | Pros | Cons |
|---|---|---|
| **Reject with 422** (recommended) | removes a whole corruption class; matches RF-05.1/05.2/05.3; smallest code | breaking for any external script (none found); the enum value becomes dead for this endpoint |
| Ledger-only (no mutation) | keeps a manual-income escape hatch | the entry is still a duplicate of the sale `ingreso`; pollutes `CierreService` breakdown; needs a new "is this a sale or a manual income?" discriminator (none exists) |
| Restrict to apuestas without a sale `ingreso` | could model adjustments | same breakdown pollution; added query; no requester |

Recommendation: **reject** with a message that names the sale flow
(`"El ingreso se registra automáticamente al vender; no se admite por este endpoint."`).
If a real manual-income need appears, it belongs to a caja-movement endpoint, not
to `POST /pagos` with `apuesta_id`.

Keep the enum value in the DB (dropping it is a destructive migration with no
benefit; `devolucion` was added the same way at
`2026_08_12_000003_alter_pagos_tipo_add_devolucion.php`).

---

## 7. Historical data: audit and conditional repair (design only)

### 7.1 What was corrupted

| Class | Condition | Why |
|---|---|---|
| C1 | `apuestas.estado='pagada'` with **no** `pagos.tipo='egreso'` | `ingreso`/`devolucion` marked it paid (`:151`) |
| C2 | `detalle_apuestas.premio_ganado(_usd)` overwritten | `:153-160` wrote the request amounts (usually `0`) |
| C3 | `tickets.estado='pagada'` reached through a non-`egreso` payment | cascade `:163-177` |
| C4 | `tickets.estado='pagada'` with zero live apuestas | `every()` on an empty collection (`:168`) |
| C5 | `pagos` rows for an apuesta that was never paid, or an `ingreso` duplicate of the sale | no transaction (`:132` vs `:151`) |

Cross-check available for C2: `tickets.premio_total_bs/usd` is accumulated by
`verificarGanadores` (`ApuestaService.php:1467-1479`) and **never touched by
`PagoController`**, so it survives the corruption and can validate a repair for
single-line tickets. `ventasTotales` also reads `detalle.premio_ganado` (`:671`),
so C2 silently deflates `Premio`/`Utilidad` in that report.

### 7.2 Read-only audit queries

```sql
-- C1 + C2: paid without a prize payout
SELECT a.id AS apuesta_id, a.ticket_id, a.estado, a.resultado_id,
       a.amount_bs, a.amount_usd, a.sorteo_hora, a.created_at,
       d.premio_ganado, d.premio_ganado_usd,
       (SELECT COUNT(*) FROM pagos p WHERE p.apuesta_id = a.id AND p.tipo = 'egreso')          AS egresos,
       (SELECT COUNT(*) FROM pagos p WHERE p.apuesta_id = a.id AND p.tipo = 'ingreso')          AS ingresos,
       (SELECT COUNT(*) FROM pagos p WHERE p.apuesta_id = a.id AND p.tipo = 'devolucion')       AS devoluciones,
       (SELECT GROUP_CONCAT(CONCAT(p.tipo, ':', p.amount_bs, '/', p.amount_usd) ORDER BY p.id)
          FROM pagos p WHERE p.apuesta_id = a.id)                                               AS pagos
FROM apuestas a
LEFT JOIN detalle_apuestas d ON d.apuesta_id = a.id
WHERE a.estado = 'pagada'
  AND NOT EXISTS (SELECT 1 FROM pagos p WHERE p.apuesta_id = a.id AND p.tipo = 'egreso');

-- C3 + C4: tickets that became 'pagada' through a bogus line
SELECT t.id AS ticket_id, t.ticket_code, t.estado, t.premio_total_bs, t.premio_total_usd,
       SUM(a.estado = 'pagada'
           AND NOT EXISTS (SELECT 1 FROM pagos p WHERE p.apuesta_id = a.id AND p.tipo = 'egreso')
          ) AS lineas_pagadas_sin_egreso,
       COUNT(a.id) AS lineas_vivas
FROM tickets t
LEFT JOIN apuestas a ON a.ticket_id = t.id AND a.deleted_at IS NULL
WHERE t.estado = 'pagada'
GROUP BY t.id, t.ticket_code, t.estado, t.premio_total_bs, t.premio_total_usd
HAVING lineas_pagadas_sin_egreso > 0 OR lineas_vivas = 0;

-- C5: duplicate 'ingreso' rows for the same apuesta (sale + manual)
SELECT apuesta_id, COUNT(*) AS ingresos
FROM pagos WHERE tipo = 'ingreso' AND apuesta_id IS NOT NULL
GROUP BY apuesta_id HAVING COUNT(*) > 1;

-- Volume by class, to size the repair
SELECT
  (SELECT COUNT(*) FROM apuestas a WHERE a.estado='pagada'
     AND NOT EXISTS (SELECT 1 FROM pagos p WHERE p.apuesta_id=a.id AND p.tipo='egreso'))       AS c1,
  (SELECT COUNT(*) FROM pagos WHERE tipo='devolucion')                                        AS devoluciones,
  (SELECT COUNT(*) FROM tickets WHERE estado='pagada')                                        AS tickets_pagados;
```

### 7.3 Conditional repair strategy (not implemented here)

1. `php artisan pagos:auditar-legacy --dry-run` → the queries above, per taquilla and
   CSV export; no writes.
2. `--apply --ids=...` (explicit ids only; never a blanket update):
   - recompute the prize with the existing engine path
     (`JuegoPluginManager::calcularPremio` / `calcularPremioMultiSorteo` with
     `detalle.premios_snapshot`), exactly as `PagoController::calcularPremio:234-305`;
   - set `estado` back to `ganadora` (prize > 0) or `perdida` (prize = 0) — never to
     `pendiente`, the result already happened;
   - restore `premio_ganado(_usd)`;
   - recompute the ticket estado (`anulada` if 0 live lines, `ganador` if any
     unpaid winner, `pagada` if all paid);
   - keep the offending `pagos` rows (they are cash evidence) and write one `logs`
     entry per change (`action='auditoria.pago_legacy'`, old/new JSON, operator user).
3. Cross-check with `tickets.premio_total_*`; for multi-line tickets that do not
   reconcile, list them for manual review instead of writing.
4. Never touch `pagos.amount_*`, `cierres_caja`, or `comisiones` (already settled).
5. Rollback: the command stores the `old_values` JSON in `logs`, so a paired
   `--revert` is possible from the log.

Decision needed before design: is the repair part of this change (a separate
work unit / PR slice), or a follow-up ops task documented in
`docs/dev/runbook-ops.md`? It cannot be safely automated without a dry-run review.

---

## 8. Test matrix and work-unit forecast

### 8.1 Tests to add or modify

**Modify**

| File | Change |
|---|---|
| `tests/Feature/PagoTipoTest.php:31-71` | The current test creates an apuesta with no `sorteo_hora` and expects 201 — it will now be 422. Rewrite into: valid pre-draw refund → 201 + `Pago` + `anulada` + ticket cascade; and one 422 case per rejection reason |
| `tests/Feature/MetodoPagoTest.php:174-198` | Only `egreso` today; add the refund path (method mirrored from the sale, USD forced to `efectivo`) |
| `tests/Feature/ApuestaTest.php:851-945` | Add `ingreso` rejection (on `pendiente` and on `ganadora`), assert `premio_ganado` untouched |
| `tests/Feature/EliminacionApuestasTest.php:180-228` | Assert the message mapping still comes from the extracted policy (same strings) and that `DELETE /tickets` still annuls without a `Pago` (or with one, per the open question) |

**Add**

| File (new) | Tests |
|---|---|
| `PagoDevolucionAnulacionTest` | valid refund → `pagos` row with sale amounts; `apuesta.estado='anulada'` + soft deleted; `detalle.premio_ganado` stays `null`; ticket `anulada` + soft deleted when single-line; `logs` entry; client amount mismatch → 422; second refund → 404; ownership 403 for a foreign taquilla; banca/grupo in chain allowed |
| `PagoDevolucionRechazosTest` (or same file) | `ganadora`, `pagada`, `perdida`, `vencido`, `anulada`; `pendiente` with `resultado_id`; `sorteo_hora` past; `created_at` at `>=` effective window (boundary, `Carbon::setTestNow`); dynamic message uses the effective minutes (10 / 15 / 5) |
| `PagoIngresoRechazadoTest` | 422 for `ingreso` on `pendiente` and on `ganadora`; no `Pago` row; no state/premio change; ticket cascade untouched |
| `PagoEgresoUnicoMutanteTest` | `egreso` still the only path that sets `pagada`; `devolucion` after a paid `egreso` → 422 (no refund of paid prizes) |
| `tests/Unit/ApuestaPolicyAnulacionTest` | `motivoNoAnulable` codes; hierarchy `taquilla → grupo → banca → 5`; boundary `>=`; ownership per role |
| `tests/Unit/ApuestaAnulacionServiceTest` (optional) | transaction shape: refund insert + state + soft delete roll back together when the cascade throws |

**Report tests**

| File | Test |
|---|---|
| `tests/Feature/CuadreCajaReportTest.php` | annulled sale + its refund in the same window → `Venta` excludes the sale, `Devoluciones` excludes the refund, `Efectivo = 0`; refund with `apuesta_id = null` still counts; refund of a sale outside the window still counts |
| `tests/Feature/CierreCajaTest.php:213-258` | extend `test_cierre_agrega_apuestas_y_egresos_del_periodo` with an annulled sale + refund pair: `total_ventas_*` and `total_egresos_*` both exclude their side, `total_efectivo_*` unchanged, `desglose_metodos` reconciles with `total_ventas_bs` |
| `tests/Feature/EstadisticaTest.php` | same-day pair → `ventas 0`, `devolucion 0`, `saldo 0`; the six series keys are unchanged (`:81-90`) |
| `tests/Feature/CuadreCajaReportTest.php` (guard) | an annulled sale with **no** refund keeps `Venta` excluded and `Devoluciones 0` (no regression on the current behavior) |

Commands: `composer test` (project contract:
`php artisan config:clear && php artisan test`, MySQL `lotto_test`, `phpunit.xml`),
plus focused runs `php artisan test --filter=PagoDevolucion` while iterating and
`vendor/bin/pint --test` (CI-enforced).

### 8.2 Forecast

| Slice | Files | Est. changed lines (additions + deletions) |
|---|---|---|
| PR1 — policy reason extraction (no behavior change) | `ApuestaPolicy.php`, `TicketController.php`, `ApuestaController.php`, `tests/Unit/ApuestaPolicyAnulacionTest.php`, `EliminacionApuestasTest.php` | ~190 |
| PR2 — `ingreso` rejection + `egreso`-only mutation | `PagoController.php` (partial), `PagoIngresoRechazadoTest.php`, `PagoEgresoUnicoMutanteTest.php`, `ApuestaTest.php` | ~160 |
| PR3 — `devolucion` = annulment + refund | `ApuestaAnulacionService.php` (new), `PagoController.php` (devolucion branch), `PagoTipoTest.php`, `PagoDevolucionAnulacionTest.php`, `MetodoPagoTest.php`, `TicketController.php` (cascade) | ~470 |
| PR4 — reports reversal pair | `ApuestaService.php` (`cuadreCaja`, `timeSeriesData`), `CierreService.php`, `CuadreCajaReportTest.php`, `CierreCajaTest.php`, `EstadisticaTest.php`, docs | ~240 |
| (optional) PR5 — legacy audit command | new `app/Console/Commands/AuditarPagosLegacy.php`, unit test, `runbook-ops.md` | ~220 |

Total ≈ **1060 lines / 8–9 files** excluding specs. That exceeds the 800-line
session budget, so chained PRs are required. Slice order matters: PR1 is a pure
refactor (safe to merge alone), PR3 depends on PR1, PR4 depends on PR3 for its
regression tests.

```
Decision needed before apply: Yes
Chained PRs recommended: Yes
800-line budget risk: High
```

---

## 9. Risks and open questions

### Risks

- **R-1 (high).** Without the §5 fix, D1+D4 turn a hidden state bug into a visible
  cash error (`Efectivo = −R` for a same-window refund). The report fix and the
  state fix must land in the same release.
- **R-2 (high).** `POST /pagos` becomes irreversible for the apuesta (soft delete).
  A mis-click refunds money and hides the sale. Today the same endpoint is
  "reversible" only in the sense that nothing was really annulled. Mitigation:
  policy re-check inside the transaction, audit log, and 404 on retry.
- **R-3 (high).** `ingreso` rejection is a breaking API change. Verified no
  in-repo consumer, but external scripts are unaudited. Release note required.
- **R-4 (medium).** Policy reason extraction changes two controllers; the exact
  strings are pinned by `EliminacionApuestasTest:200-201` and
  `TicketEliminacionTest`. Keep the strings byte-identical.
- **R-5 (medium).** Missing transaction/row lock (`:132` vs `:151`) allows a double
  payout under concurrency. If not fixed now it stays a money bug next to the code
  being rewritten.
- **R-6 (medium).** Ticket cascade semantics for partial annulment: marking the
  ticket `anulada` while other lines are live would hide them from the history
  (`TicketController::index:32-39` shows only `anulada` or non-deleted tickets).
  Decide per §9 Q-1 before coding.
- **R-7 (medium).** Unit/feature tests rely on "now is inside 5 minutes";
  boundary tests need `Carbon::setTestNow`, or they pass for the wrong reason.
- **R-8 (low).** `Apuesta::pago()` is `hasOne` (`app/Models/Apuesta.php:67-70`) but
  an apuesta has at least two `pagos` (sale + prize). `ApuestaController::show:148`
  loads it, so the receipt in `GET /apuestas/{id}` may show the sale payment.
  Pre-existing; do not build the refund on this relation.
- **R-9 (low).** `docs/cliente/especificacion-requisitos-software.md:255` (RF-05.3)
  says devoluciones MUST NOT alter the apuesta state, which conflicts with D4. The
  spec delta must restate it as "must not mark it paid or modify the prize; a
  pre-draw refund annuls the sale".
- **R-10 (low).** No migration is needed (all enums already contain the target
  states), so the change is code-only; keep it that way to avoid a deploy window.

### Open questions (must be closed before proposal/design)

1. **Partial ticket refund.** Should `devolucion` annul one line (ticket stays
   `pendiente` unless every line is annulled), or be allowed only when it is the
   whole ticket? Recommendation: per line + ticket annulled only when no live line
   remains, and recompute/leave `tickets.total_bs` consistently (today it is never
   recomputed).
2. **Does `DELETE /tickets/{id}` also record a `devolucion` `Pago`?** D4 only
   describes the `POST /pagos` direction; the taquilla UI only uses `DELETE`
   (`taquilla/src/pages/dashboard.astro:2668`, `historial.astro:251`). Keeping
   `DELETE` money-free preserves today's semantics; making it money-aware makes the
   two endpoints equivalent. Recommendation: `DELETE` stays state-only, documented.
3. **Residual cross-window double count (§5.4).** Accept and document, or add an
   `anulada_at` column to bucket the reversal correctly? Recommendation: accept and
   document for 2026; revisit if `tiempo_eliminacion > 60` is ever used in production.
4. **Historical repair ownership.** In-change PR5, or an ops task? Recommendation:
   separate slice, gated by a dry-run review with the product owner.
5. **Refund method.** Mirror the sale's `metodo_pago` automatically, or require the
   operator to declare it? Recommendation: mirror by default, allow override, force
   `efectivo` for USD (same as `Pago::resolverMetodoPago:51-64`).
6. **Who may refund.** `POST /pagos` accepts `banca/grupo/master/super_master` with
   no ownership check today (`:68-70`). Reusing `ApuestaPolicy::delete` adds chain
   ownership for all of them. Confirm this tightening is intended (it is D7-compliant
   and matches `DELETE /tickets`).

---

## 10. Ready for proposal

**Yes.** The defect, its per-`tipo` behavior, the target transition, the report
double count with concrete arithmetic, the exact reusable policy, the `ingreso`
recommendation, the audit/repair shape, and a sliced test/work-unit forecast with
file and line evidence are established from real code.

The proposal must carry open questions 1 (partial ticket), 2 (`DELETE /tickets` and
money) and 4 (repair ownership) as product decisions, and must state that the
report fix (D6) is mandatory in the same release as D1/D4.
