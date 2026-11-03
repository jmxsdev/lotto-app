# Archive Report — `autorizacion-rol-unico`

**Change**: `autorizacion-rol-unico` · Issue #69
**Archived**: 2026-11-03
**Archived to**: `openspec/changes/archive/2026-11-03-autorizacion-rol-unico/`
**Head at archive**: `5f57c98` (`feat(rol): elimina users.role (WU-7)`), Merge 2 already deployed on `main`
**Final status**: **PASS WITH WARNINGS** — `sdd-verify`: `verdict: pass_with_warnings`, 0 blockers, 0 critical findings, 10/10 requirements, 20/20 scenarios.

> This report is the terminal record of the cycle. `apply-progress.md` (#740) and `verify-report.md` (#756) are intermediate snapshots; this report was reconciled against the commits, the synced specs, the verified evidence and the user-reported operational facts at close.

---

## 1. Scope closed

Role unification: Spatie (`roles` / `model_has_roles`) became the single source of truth for the user role, the legacy `users.role` column was dropped, the API contract kept a computed `role` attribute, and role changes are audited. The change also synced the `jerarquia-agencias` capability, whose requirement no longer allows the column as a role source.

| Area | Behavior at close |
|---|---|
| Role source | `hasRole` / `hasAnyRole` / `getRoleNames()` / Spatie `role()` scopes only. No read or write of `users.role` remains in `app/`. |
| API contract | `User` exposes `role` via `$appends` + `getRoleAttribute()` + `primaryRole()` (priority `super_master > master > banca > grupo > agencia > taquilla`, `null` when no Spatie role). Login and `me` payloads keep the same shape. |
| Authorization | Fail-closed: no role ⇒ 403 at login and protected resources, or an empty scope. No global fallback, no column fallback. |
| Hidden queries | The 6 raw `where('role', ...)` reads migrated to Spatie scopes (`CierreService`, `AgenciaController::destroy`, `UserController::index`, `AgenciasBackfill`, `E2eSeeder`). |
| Audit | `UserController::update` writes a `user.role_changed` log entry inside the transaction with actor, target, `before`, `after`, IP and user-agent; it does not fire when the role set does not change. |
| Schema | `users.role` dropped by `2026_11_03_000001_drop_role_from_users_table.php` (idempotent `Schema::hasColumn` guards in `up()` and `down()`), plus `$fillable` / casts / seeders cleanup. |

### Decisions taken

- **D1 — Spatie is the single role source.** The column is no longer read or written for authorization or scoping.
- **D2 — Computed `role` accessor instead of a persisted column.** `$appends` + `getRoleAttribute()` + `primaryRole()`; `role` stays out of `$fillable` to prevent accidental writes.
- **D3 — Fixed `primaryRole()` priority** with alphabetical fallback, so multi-role users serialize deterministically.
- **D4 — Fail-closed everywhere.** No role ⇒ `null` in the accessor, 403 in auth, empty scope in queries.
- **D5 — `user.role_changed` audit** emitted from `UserController::update` inside the existing transaction, with before/after set comparison.
- **D6 — Rollout in two merges (expand/contract).** Merge 1 (`4a2f183`) made Spatie authoritative while keeping the physical column; Merge 2 (`5f57c98`) dropped it. Previous image remains deployable between merges.
- **D7 — Dev reconstruction by `migrate:fresh --seed`** on the dev VPS; no permanent backfill in production.
- **D8 — Out of scope, by design:** #84 (global scoping for `master`/`agencia` and banca cross-tenant), #85, #89 (audit on user creation), BE-04.

---

## 2. Native status anomaly (documented, user-authorized archive)

The native SDD status projection reported `verify: ready` but `archive: blocked`, citing a `dependencies.archive` state that does not reflect the persisted, valid `verify-report`. This is a **projection anomaly, not a content gate**:

- The `verify-report` is structurally valid (`gentle-ai.verify-result/v1` header) with `verdict: pass_with_warnings`, `blockers: 0`, `critical_findings: 0`, `requirements: 10/10`, `scenarios: 20/20`.
- `tasks.md` shows 38/38 completed tasks and `apply-progress.md` shows 7/7 WUs complete.
- No `reviewGate` exists for this candidate (RDD not enabled), so there is no receipt to validate or block on.

Per the explicit user authorization for closure, archive proceeded. This anomaly is an orchestrator/tooling follow-up, not an implementation gap.

---

## 3. Work units and commits

| WU | Content | Commits |
|---|---|---|
| WU-0 | SDD planning artifacts (proposal, specs, design, tasks) | `5ac392e` docs(sdd): planifica autorizacion-rol-unico (#69) |
| WU-1 | `User` accessor/`primaryRole`, factory without implicit role, fixtures to `withRole()` | `fc5179b` feat(rol): unifica el rol en Spatie (WU-1) |
| WU-2 | Writes through Spatie in `UserController`/entities/seeders, `index` scope, no column writes | `b3de94c` docs(sdd): registra WU-2 · `174d67b` feat(rol): escribe rol solo en Spatie (WU-2) |
| WU-3 | `user.role_changed` audit with actor/target/before/after/IP/UA | `b3f2a17` feat(rol): audita cambios de rol (WU-3) · `430d41c` docs(sdd): registra WU-3 |
| WU-4 | Spatie scoping for apuestas, tickets, reportes, estadísticas, pagos and `ApuestaPolicy` | `8d565dd` feat(rol): usa Spatie en el scoping de apuestas y reportes (WU-4) · `945c96f` docs(sdd): registra WU-4 |
| WU-5 | Spatie in `JuegoController` / límites | `9266145` feat(rol): usa Spatie en JuegoController y límites (WU-5) · `2476d1b` docs(sdd): registra WU-5 |
| WU-6 | Auth/panel, hidden queries, divergence tests (Merge 1 head) | `4a2f183` feat(rol): usa Spatie en auth y queries ocultas (WU-6) · `dafd0a9` docs(sdd): registra WU-6 |
| WU-7 | Contract: drop `users.role`, cleanup and test retirement (Merge 2 head) | `5f57c98` feat(rol): elimina users.role (WU-7) · `025eed1` docs(sdd): registra WU-7 |

Full relevant commit list: `5ac392e`, `fc5179b`, `b3de94c`, `174d67b`, `430d41c`, `b3f2a17`, `945c96f`, `8d565dd`, `2476d1b`, `9266145`, `dafd0a9`, `4a2f183`, `025eed1`, `5f57c98`.

---

## 4. Final evidence

| Evidence | Result |
|---|---|
| `composer test` | **1355 tests, 7794 assertions, 2 skipped expected** (`PluginIntegrationTest`), OK |
| `vendor/bin/pint --test` | PASS (373 files) |
| CI run `41667153232` (Merge 1, `4a2f183`) | tests, front, E2E, build and deploy: **success** |
| CI run `41669583139` (Merge 2, `5f57c98`) | tests, front, E2E, build and deploy: **success** |
| Build artifact | `ghcr.io/jmxsdev/lotto-app-api:5f57c98` + `:latest`, build exit 0 |
| Deploy | Containers `lotto_api_prod`, `lotto_mysql_prod`, `lotto_caddy_prod`, `lotto_horizon_prod`, `lotto_scheduler_prod` up; healthcheck OK |
| VPS dev | `php artisan migrate:fresh --seed` OK; panel login `super@lotto.com` OK; new user creation OK |
| Pre-change backup | `/home/deploy/backups/lotto-pre-rol-unico-20261103-155003.sql.gz`, SHA-256 `332ba744...`, 37 tables, `gzip -t` OK |

Test log hash: `sha256:d6f893aea0b2b94db21697da920cb92c9bc4974f6c3b8f224278fd00cda2882b`. Build log hash: `sha256:ac96dc4965c4fd3544d4b7d24968106171afc24a1d8cd6dbef61332740d6c0f5`.

---

## 5. Specs synchronized (source of truth)

| Domain | Action | Details |
|---|---|---|
| `autorizacion-rol-unico` | **Created** `openspec/specs/autorizacion-rol-unico/spec.md` | 9 requirements, 18 scenarios. Mechanical shell copy of the delta body; change frontmatter removed and `**Estado**: draft` added to match repo convention. Body beyond the frontmatter is byte-identical (verified by diff; empty body-only diff). |
| `jerarquia-agencias` | **Updated** `openspec/specs/jerarquia-agencias/spec.md` | `### Requirement: Rol agencia en ambas fuentes` (2 scenarios) replaced by `### Requirement: Rol agencia en Spatie` (2 scenarios) from the delta. Requirement count stays at 6; the other five requirements are untouched. The delta-only `(Previously: ...)` annotation was not carried into the main spec. |

Verification: `grep -rni 'ambas fuentes' openspec/specs/` returns **no matches**. The base spec no longer asks for a column source.

---

## 6. Archive move evidence

- Whole change folder moved with `git mv` to `openspec/changes/archive/2026-11-03-autorizacion-rol-unico/`; the active change directory no longer exists.
- `verify-report.md` is gitignored (`*.md`), so it moved physically with the directory and remains untracked; it is present in the archive.
- Pre-move recursive snapshot compared against the archived tree with `diff -r`: **empty output, exit 0**. No artifact content was modified.
- Archived `tasks.md`: **38/38 checked, 0 unchecked**.

---

## 7. Residual warnings

1. **Rollback M1 → pre-M1 not drilled.** Only the M1 image (`4a2f183`) is a safe rollback target from M2; going back further requires `down()` plus a dump restore. Documented procedure, untested.
2. **`down()` not executed.** The drop migration is reversible by inspection (`Schema::hasColumn` + nullable/default re-add) but was never run; it restores an empty column, not data.
3. **`AuditoriaRolTest` does not assert `ip`/`user_agent`.** The code writes both and `logs` persists them; this is an assertion gap, not an implementation gap.
4. **Post-contract divergence scenarios are descriptive.** With the column gone, "divergent column" scenarios are not reproducible; tests assert the Spatie contract instead.
5. **Accessor N+1.** Serializing user lists calls `getRoleNames()` per row; existing endpoints eager-load `roles`, new endpoints must do the same.
6. **CI does not gate feature branches.** Verification happened on `main` runs; feature-branch pushes are not gated.
7. **Out of scope, unchanged by design:** #84, #85, #89 and BE-04.
8. **Native status projection anomaly** documented in §2; user-authorized archive with valid verification and no CRITICAL findings.
9. **Untracked/ignored artifacts need `git add -f`.** The new `openspec/specs/autorizacion-rol-unico/spec.md`, this `archive-report.md` and the archived `verify-report.md` match `*.md` in `.gitignore`; the orchestrator must force-add them when committing the closure.

---

## 8. Traceability (Engram)

| Artifact | Observation |
|---|---|
| proposal | #730 |
| spec | #731 |
| design | #732 |
| tasks | #733 |
| apply-progress | #740 |
| verify-report | #756 |
| archive-report | #757 (`sdd/autorizacion-rol-unico/archive-report`) |

No `reviewGate` was present for this candidate (RDD not enabled); no transaction, ledger or receipt topics exist to validate.

---

## 9. Cycle status

`sdd-propose` → `sdd-spec` → `sdd-design` → `sdd-tasks` → `sdd-apply` (7/7 WUs) → `sdd-verify` (PASS WITH WARNINGS) → `sdd-archive` (this report). The change is closed; `autorizacion-rol-unico` is now a main capability and `jerarquia-agencias` reflects Spatie as the only role source.

**Intentional-with-warnings archive**: authorized by the user, backed by a valid verify report (0 blockers, 0 CRITICAL, 10/10 requirements, 20/20 scenarios) and 38/38 completed tasks.
