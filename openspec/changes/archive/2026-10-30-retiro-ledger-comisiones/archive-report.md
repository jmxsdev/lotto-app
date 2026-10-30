# Archive Report: retiro-ledger-comisiones

**Change**: retiro-ledger-comisiones (Modelo A — comisiones consultivas)
**Archived**: 2026-10-30
**Archive path**: `openspec/changes/archive/2026-10-30-retiro-ledger-comisiones/`
**Merged commit**: `760191d` — `chore: integra retiro del ledger de comisiones (Modelo A)`
**Deployed**: yes (GitHub Actions run `41081174437`, including "Deploy al VPS")
**Verdict at close**: PASS WITH WARNINGS (0 blockers, 0 critical findings)

## Change Summary

Model A makes commissions consultative. The settlement ledger was removed: the `comisiones`
table, the `Comision` model and its relations, the `index`/`liquidar`/`pagar` controller
methods, the ledger routes, the `liquidar`/`previsualizar`/`conflictosPorRango` service
methods, the ledger UI, ledger tests, ledger collections, and the ledger documentation.

The read surface moved to a read-only `GET /api/v1/comisiones/consulta` backed by
`ComisionService::filasPorRango`, which returns one row per entity with sales
(`nivel`, `entidad_id`, `entidad`, `monto_comision` as a 2-decimal string) for an inclusive
date range. Authorization is `super_master` (global) and `master` (limited to
`masterBancaIds()`); other roles get 403. Nothing is persisted.

The calculation engine, the close snapshot (`cierres_caja.comision_bs_equivalent`),
`comision_defaults` and `GET/PUT /comisiones/defaults` are unchanged. Historical rows were
copied to `comisiones_archivo_2026` and the live table was dropped by migration
`2026_10_30_000001_archive_and_drop_comisiones_table.php`.

## Final State

| Area | State at close |
|---|---|
| Backend code | Ledger model, relations, controller methods, routes and service methods removed; engine untouched |
| Schema | `comisiones` dropped; `comisiones_archivo_2026` created (no FKs, no unique indexes, explicit-column copy, count guard before drop) |
| API | `GET /comisiones/consulta` added; `GET/PUT /comisiones/defaults` intact; ledger routes gone |
| Panel | `panel/src/pages/comisiones.astro` read-only consultation (no Liquidar/Pagar/estado/conflictos); 15-row client pagination |
| Collections | Ledger YAMLs removed; `Consultar Comisiones.yml` added; defaults YAMLs kept |
| Docs | `motor-cierres-comisiones.md`, `integracion-front-motor-premios.md`, `pendientes-front.md`, `runbook-ops.md`, `estructura.md`, `arquitectura-backend.md` and `CHANGELOG.md` updated to Model A |
| Tasks | 19/19 checked (`grep -c "^- \[ \]"` → 0) |

## Spec Deltas Applied

Merged into `openspec/specs/comisiones/spec.md`:

| Action | Requirement | Result |
|---|---|---|
| MODIFIED | Purpose | Restated as consultative calculation; no ledger, no `pendiente`/`pagado`; close snapshot and `comision_defaults` explicitly kept |
| REMOVED | Liquidación en el ledger | Requirement and its 4 scenarios deleted (Reason/Migration recorded in the delta) |
| MODIFIED | Independencia entre niveles y tope acumulado | Rule 2 now says the banca "recibe su monto liquidable en el cálculo y los reportes (sin persistencia)" |
| RENAMED + MODIFIED | Sincronía single/bulk de liquidación → Sincronía single/bulk de cálculo | "Reportes, preview y liquidación" → "Reportes y consulta"; scenario now covers reporte, consulta o cierre |
| ADDED | Consulta de comisión por rango | Added with 5 scenarios: three levels, empty range, master scope, unauthorized role (403), no persistence |

Post-merge grep of the canonical spec (`liquidar|ledger|pendiente|pagado`) leaves only
intentional references: the Purpose negation ("sin persistir un ledger de liquidación, sin
transicionar a `pagado`"), the `MUST NOT exponer estado/pendiente/pagado` clause of the new
requirement, the "Sin persistencia (ledger eliminado)" scenario, and the pre-existing
calculation verb "usada para liquidar" in "Resolución de tasa efectiva" (not a ledger
reference; the delta did not modify that requirement). The `independiente` matches are
substring false positives of `pendiente`.

No delta was required for `cierre-caja` or `reportes-agencia`: both reference the `comisiones`
capability, not the ledger, and the engine/topes are unchanged.

## Verification Evidence

| Check | Command / source | Result |
|---|---|---|
| Backend suite | `cd backend && composer test:parallel` | ✅ 1240 tests green (user-confirmed) |
| Panel build | `cd panel && npm run build` | ✅ 27 pages |
| Migration replay | `RefreshDatabase` on `lotto_test` + `test_archivo_reemplaza_la_tabla_comisiones` | ✅ `comisiones` absent, archive present and empty |
| CI/CD | [Run `41081174437`](https://github.com/jmxsdev/lotto-app/actions/runs/41081174437), head `760191d` | ✅ success, including "Deploy al VPS" |
| Manual UI check | Browser as `super_master`/`master` | ➖ Not performed (optional) |

Scenario coverage at close: 15/17 fully covered and green at runtime; 2/17 `PARTIAL`
(covering test narrower than the scenario text, no observed defect).

Environment note: an earlier parallel suite failure was caused by a `vendor/` autoloader
symlinked to the main worktree; a local `composer install` in the worktree fixed it. It was an
environment issue, not a code defect.

## Warnings and Follow-ups (not blockers)

1. **`agencia` 403 not parametrized** — `test_consulta_403_para_roles_no_autorizados` covers
   `banca`/`grupo`/`taquilla`; `agencia` is enforced by `role:super_master|master` but has no
   test. Add `agenciaUser()` to the loop.
2. **`docs/dev/planificacion.md` stale** — Sprint 13 still describes a `comisiones` ledger with
   `pendiente`/`Pagado` (`:352-353`). The file is marked outdated at the top, but it
   contradicts Model A. Follow-up cleanup recommended.
3. **Consulta-specific post-tope clamp test missing** — the "reportes y consulta usan
   liquidado" scenario is proven on the report surface only. Suggested follow-up: assert an
   `animalitos` legacy 40% row yields `monto_comision` at 16% through the consultation endpoint.
4. **Manual UI check not performed** — compilation and API tests cover the contract; the
   visual/interaction pass remains optional.

## Rollback

- **Code**: `git revert 760191d` (the merge commit) restores the model, relations, controller
  methods, routes, page, tests and collections. Revert them together: a restored page against
  removed routes returns 404/500.
- **Schema**: the migration `down()` re-creates an empty `comisiones` with the original shape
  (safe, because the old table and its schema-wide FK names are gone). It never restores rows
  and never drops `comisiones_archivo_2026`.
- **Data**: restoring historical rows requires the pre-deploy `mysqldump comisiones` described
  in `docs/dev/runbook-ops.md`. The production row count and the actual dump execution were not
  captured in the evidence available to this archive; confirm with ops before relying on a
  data-level rollback.
- **Spec**: this archive removed "Liquidación en el ledger" from the canonical spec. Restoring
  it is manual; the removed text is preserved in
  `openspec/changes/archive/2026-10-30-retiro-ledger-comisiones/specs/comisiones/spec.md`.

## Traceability

Engram observations read for this archive (project `lotto-app`):

| Topic | Observation |
|---|---|
| `sdd/retiro-ledger-comisiones/explore` | #693 |
| `sdd/retiro-ledger-comisiones/proposal` | #696 |
| `sdd/retiro-ledger-comisiones/spec` | #697 |
| `sdd/retiro-ledger-comisiones/design` | #698 |
| `sdd/retiro-ledger-comisiones/tasks` | #699 |
| `sdd/retiro-ledger-comisiones/apply-progress` | #701 |
| `sdd/retiro-ledger-comisiones/verify-report` | #702 |
| `sdd/retiro-ledger-comisiones/archive-report` | this report |

Note: `verify-report.md` existed only in Engram (#702) at archive time. It was materialized on
disk inside this change folder with the runtime evidence recorded above, so the repository
audit trail is complete.

## Mechanical Archive Readback

The change folder was moved with `git mv` after a recursive pre-move snapshot. `diff -r`
between the snapshot and the archived tree produced no output and exit status 0 (byte-identical).
`archive-report.md` and the materialized `verify-report.md` are additive files, excluded from
the source/destination comparison.
