```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:e6dae88fded49b35ae092fdc9b27a4b1e682aa4e72eed7866160538aeda39cfe
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 45/45
scenarios: 91/91
test_command: cd backend && composer test
test_exit_code: 0
test_output_hash: sha256:3d318ae2a737300a83978ede9a05aca341ec5fa919fb3265b73f175054eab3ba
build_command: cd backend && ./vendor/bin/pint --test && cd panel && npm run build
build_exit_code: 0
build_output_hash: sha256:206c4e22bcb043d3e1dbe9298e51b12dadcbacbbb7226cdd956a983b27c5938c
```

# Verification Report — auditoria-trazabilidad-89

## Round 2 (re-verification after critical fixes)

Round 1 was **BLOCKED** with 3 CRITICAL findings. This round re-verifies commit `65ad919`
(`fix(auditoria): cierra críticos de verify (permisos, PII y motivo)`), which also carries the
merge with `main` (#80, `a163ba8`) and the manual-scrape audit gap fix (`34ee0f8`).

**Previous criticals — all resolved.** Evidence below in *Previous criticals* and the findings
section. No production code was modified by this verifier. Test, Pint and panel-build commands were
not executed by this verifier (explicit scope restriction); the runtime evidence is the user-run
suite on this exact candidate.

## Metadata

| Field | Value |
|---|---|
| Change | `auditoria-trazabilidad-89` (#89) |
| Branch | `feat/89-wu-13-hardening` |
| Commit (HEAD) | `65ad919a4827682ddbc376740c788b78886bfe77` |
| Date | 2026-11-08 |
| Mode | Standard (Strict TDD not active) |
| Artifact store | OpenSpec + Engram (`lotto-app`) |
| Runtime evidence | User-run suite: **1.853 tests / 11.748 assertions / 2 skipped / 0 failures**; Pint passed; panel build passed. |
| Hash note | `test_output_hash` = SHA-256 of the exact user-reported string `1.853 tests, 11.748 assertions, 2 skipped, 0 failures (user-run)`. `build_output_hash` = SHA-256 of `Pint passed; panel build passed (user-run)`. `evidence_revision` = SHA-256 over `HEAD` + the per-file SHA-256 of the six `specs/*/spec.md` files in alphabetical path order. |
| Authoritative counts | 45 requirements / 91 scenarios (counted from the six spec files; unchanged since round 1). |

## Previous criticals — resolution evidence

| # | Round 1 critical | Status | Evidence |
|---|---|---|---|
| C1 | `user.permissions_changed` had no production call site | ✅ RESOLVED | `UserController::update` emits the event inside the same `DB::transaction` as the mutation and the role change: `backend/app/Http/Controllers/Api/UserController.php:231` (transaction), `:249-263` (effective-permission snapshots around `syncRoles`), `:297-313` (`user.role_changed` + `user.permissions_changed`). Recorder path is P0 (`AuditAction::priority()` lists the case at `backend/app/Audit/AuditAction.php:113`); reason is `Required` (`backend/app/Audit/ReasonPolicy.php:24`). Covering test: `backend/tests/Feature/AuditoriaRolTest.php:42` asserts the P0 event, actor/entity/scope, required reason, and the `before['permissions']`/`after['permissions']` diff (`:70-92`); negative cases at `:113,161` (no event without a real role change / on 403). |
| C2 | `actor_label` persisted raw emails (PII redaction violated) | ✅ RESOLVED | `AuditRecorder::resolveActor` masks every label that contains `@` via `AuditRedactor::maskEmail` before the row is built: `backend/app/Audit/AuditRecorder.php:187,212,221-228`; masked value is then truncated at `:157`. `maskEmail` emits `j***@example.com` and is idempotent (`backend/app/Audit/AuditRedactor.php:66-92`). Backfill already masked (`backend/app/Console/Commands/AuditBackfill.php:228-230,262-264`); legacy migration writes `null` (`backend/database/migrations/2026_11_05_000003_migrate_juego_auditoria_to_audit_events.php:77`). Covering tests: `backend/tests/Unit/Audit/AuditRecorderTest.php:168` (explicit label, asserts the raw email appears nowhere in the persisted attributes) and `:141` (implicit actor); feature assertions updated in `AuditoriaAuthTest.php:101,249`, `AuditoriaUsuariosTest.php:74,131`, `AuditoriaBancaTest.php:108`, `AuditoriaGrupoTest.php:113`, `AuditoriaDineroTest.php:92`, `AuditoriaPagosTest.php:165`, `AuditoriaJuegosTest.php:90`, `AuditoriaAnulacionesTest.php:186`, `ClaveCierreTest.php:233`, `AuditableTest.php:63`. |
| C3 | `game.limit_deleted` did not require a reason | ✅ RESOLVED | `JuegoController::destroyLimite` now validates `reason_code` as `required` + `Rule::enum(AuditReason::class)` **before** the delete and removes the `Unspecified` fallback: `backend/app/Http/Controllers/Api/JuegoController.php:900-906` (validation), `:910-914` (snapshot, then delete), `:918-927` (event with the validated reason). Missing/invalid reason → 422 with no row mutation and no audit event: `backend/tests/Feature/AuditoriaLimitesConfigTest.php:307,321` and `backend/tests/Feature/LimitesApiTest.php:358,371`. Existing success paths pass a reason (`AuditoriaLimitesConfigTest.php:266,300`; `LimitesApiTest.php:344,393`). |

No new CRITICAL was introduced by the fixes. Regression checks on the fix surface:

- Panel delete flow already opens a `required: true` reason modal for `game.limit_deleted`
  (`panel/src/utils/limites.ts:242-249`), so the stricter backend does not break the UI; the three
  detail pages use the shared table callback (`panel/src/pages/{bancas,grupos,taquillas}/detalle.astro`).
- Role changes from the panel already send a required reason
  (`panel/src/pages/usuarios.astro:182-188`, `userUpdateReason`), so the new permissions event does not
  add a client-side requirement beyond the existing `user.role_changed` contract.
- No duplicate event: `user.updated` still only audits `name/email/banca_id/grupo_id/agencia_id/taquilla_id`
  (`UserController.php:265-287`), while role/permission/active/password keep their own events.
- Masking only touches labels containing `@`; system/job/command/device/taquilla labels pass through
  unchanged (`AuditRecorder.php:221-228`), and all such assertions remain green.

## Summary

| Metric | Value |
|---|---:|
| Capabilities | 6 |
| Requirements verified | 45 |
| Scenarios verified | 91 |
| Requirements completed (every requirement scenario has a passing covering test) | 45 / 45 |
| Requirements with a design deviation noted as WARNING (no failing scenario) | 1 (`Mecanismos de captura`, WARNING-2) |
| Scenarios compliant | 91 / 91 |
| CRITICAL findings | 0 |
| WARNING findings | 6 |
| SUGGESTION findings | 10 |
| Verdict | **PASS WITH WARNINGS** |

## Completeness (tasks)

All 13 WUs are marked implemented in `tasks.md` (`:224`, `Estado`), including WU-13 (suite, merge with
`main`). No pending implementation task was found. The per-section checkboxes remain stale planning
markers and are not used as the completion source of truth. The task progress table still records the
pre-fix suite (`tasks.md:103-104`, 1.848 / 11.702); the corrected count is a SUGGESTION (S10), not a
task blockage.

## Spec compliance matrix

Status legend: ✅ COMPLIANT · ⚠️ PARTIAL · ❌ NOT COMPLIANT.

### `auditoria-eventos` (9 requirements / 16 scenarios)

| Requirement | Status | Evidence |
|---|---|---|
| Envelope común | ✅ | `backend/app/Audit/AuditEventData.php:128-157`; `backend/app/Audit/AuditRecorder.php:138-174`; `tests/Unit/Audit/AuditRecorderTest.php`; `tests/Feature/AuditoriaMiddlewareTest.php` |
| Taxonomía cerrada `domain.verb` | ✅ | `backend/app/Audit/AuditAction.php` (67 cases); `AuditRecorder::assertActionMatches` (`AuditRecorder.php:322-327`); `tests/Unit/Audit/AuditActionTest.php` |
| Cobertura por dominio | ✅ | All design §6 actions now have a production call site. `user.permissions_changed` is emitted at `UserController.php:297-313` and covered by `AuditoriaRolTest.php:42-92`. `draw.result_corrected` remains intentionally reserved (`design.md:813-814`). Scrape requests covered by `AuditoriaResultadosScrapeTest.php` (commit `34ee0f8`). |
| Mecanismos de captura | ✅ (WARNING-2) | `AuditContext`/`AuditRecorder`/middleware/binding verified; no global observer (`tests/Feature/AuditArchitectureTest.php:41-65`); trait `Auditable` implemented (`backend/app/Audit/Auditable.php:22`) but applied only by a test probe, not by production models. Design deviation tracked as WARNING-2; no spec scenario fails. |
| Auth solo API | ✅ | `backend/app/Http/Controllers/Api/AuthController.php:20-250`; `tests/Feature/AuditoriaAuthTest.php` (10 tests); no legacy login routes exist |
| Semántica de fallo | ✅ | `AuditRecorder::critical/bestEffort/recordMany` (`AuditRecorder.php:41-136`); P0 rollback tests in `AuditoriaCierreTest`, `AuditoriaPagosTest`, `AuditoriaJobsDineroTest`; P1 degradation tests |
| Motivo | ✅ (WARNING-6) | Enum validation + 422 for required reasons verified (`AuditRecorder.php:230-248`; `JuegoController.php:900-906`). The closed enum still accepts `unspecified`/`other` at the API boundary (WARNING-6); every spec scenario passes. |
| Redacción de PII | ✅ | `AuditRedactor` redacts before/after/context/reason; `actor_label` is masked in the recorder (`AuditRecorder.php:187,212,221-228`); covering tests listed under C2. |
| Before/after | ✅ | Diff/snapshot/null-before rules verified in `AuditEventData` and domain controllers; `tests/Unit/Audit/AuditEventDataTest.php`, `AuditoriaUsuariosTest`, `AuditoriaBancaTest` |

### `auditoria-lectura` (7 requirements / 16 scenarios)

| Requirement | Status | Evidence |
|---|---|---|
| Endpoint de lectura con cursor pagination | ✅ | `backend/app/Services/AuditQueryService.php:47-118`; `tests/Feature/AuditoriaLecturaTest.php` (cursor order/tie-break, filters, per_page cap, invalid cursor 422) |
| Autorización por scope | ✅ | `backend/app/Support/Scope/AlcanceService.php:136-139,217-234`; tests `super_master_ve_todas_las_bancas...`, `master_ve_solo_los_eventos_de_sus_bancas`, `roles_sin_permiso_reciben_403` |
| Redacción por rol | ✅ | No `User` relation is hydrated (`AuditQueryService.php:126-167`); before/after/context are redacted at read time; `actor_label`/`actor_roles` are preserved snapshots that are now redacted at write time (C2). `AuditoriaLecturaTest.php:288` asserts a masked label. |
| Export CSV | ✅ | `backend/app/Services/AuditExportService.php` (columns, BOM, formula escape, max rows, `audit.exported`); `tests/Feature/AuditoriaExportTest.php` (7 targeted tests) |
| Visor panel `/auditoria` | ✅ | `panel/src/pages/auditoria.astro` (filters, cursor stack, diff drawer, export, 403/error/empty; no `innerHTML`); `AuditArchitectureTest.php:162-177`; panel build passed (user-run) |
| `/logs` operativo | ✅ | `backend/app/Http/Controllers/Api/LogController.php` (allowlist, paginator, `view_logs`); `panel/src/pages/logs.astro` reads `res.data`, banner, textContent; `tests/Feature/LogsOperativoTest.php` (6 tests) |
| Permisos en catálogo | ✅ | `backend/database/seeders/RolesAndPermissionsSeeder.php:61-62,91-92`; `tests/Feature/PermissionCatalogTest.php` (36 permissions / master 35) |

### `auditoria-integridad` (7 requirements / 15 scenarios)

| Requirement | Status | Evidence |
|---|---|---|
| Append-only | ✅ | `backend/app/Models/AuditEvent.php:38-47`; MySQL triggers `2026_11_05_000005_add_audit_append_only_guards.php`; grants `database/sql/audit_events_grants.sql`; `tests/Feature/AuditoriaIntegridadTest.php:38-80` |
| Índices mínimos | ✅ | `2026_11_05_000001_create_audit_events_table.php:39-44` (all six) |
| Retención y archivo | ✅ | `app/Console/Commands/AuditArchive.php`, `AuditPrune.php`; `tests/Feature/AuditoriaRetencionTest.php`; docs restore procedure `docs/dev/auditoria-sistema.md:208-230` |
| Verificación de integridad | ✅ | `app/Console/Commands/AuditVerify.php`; `tests/Feature/AuditoriaVerifyTest.php` (checksum/NDJSON/hash-chain, exit failure); docs declaration `docs/dev/auditoria-sistema.md:155-160` |
| Migración de `juego_auditoria` | ✅ | `2026_11_05_000003_migrate_juego_auditoria_to_audit_events.php`; `JuegoAuditoria` read-only (`app/Models/JuegoAuditoria.php:27-40`); `tests/Feature/AuditoriaMigracionJuegoTest.php`. Design-fidelity gaps recorded as WARNING-5. |
| FKs que preservan evidencia | ✅ | `2026_11_05_000004_harden_audit_evidence_foreign_keys.php`; `AuditoriaIntegridadTest.php:82-90` verifies `SET NULL` rules. End-to-end deletion test is SUGGESTION 9. |
| Documentación del sistema | ✅ | `docs/dev/auditoria-sistema.md` (348 lines: coverage, read, retention, verify, deploy, rollback, restore); `docs/dev/auditoria-logs.md` unchanged vs `main` |

### `catalogo-permisos` (7 requirements / 11 scenarios)

| Requirement | Status | Evidence |
|---|---|---|
| Catálogo por capability | ✅ | `RolesAndPermissionsSeeder.php:17-66`; `PermissionCatalogTest` asserts exactly 36 with guard `api` |
| Matriz capability × rol | ✅ | `RolesAndPermissionsSeeder.php:75-148`; `docs/dev/permisos-capabilities.md:41,73`; `PermissionCatalogTest` role counts |
| Reconciliación con acceso efectivo | ✅ | `PermissionCatalogTest` master 35 / banca 24 / grupo 21 / agencia 17 / taquilla 12; explicit access-change cases covered |
| Bypass total para `super_master` | ✅ | `AppServiceProvider::boot()` `Gate::before` (`app/Providers/AppServiceProvider.php:64-74`); `PermissionCatalogTest` bypass test |
| Asignación solo vía roles | ✅ | No `givePermissionTo`/`syncPermissions` on `User` in `app/` (scan); seeder syncs roles only |
| Seeder idempotente | ✅ | `firstOrCreate` + `syncPermissions` + `forgetCachedPermissions` (`RolesAndPermissionsSeeder.php:159-206`); re-run test |
| Concesiones puntuales | ✅ | Matrix + `PermissionCatalogTest` (`manage_config` master, `view_reports`/`download_release` taquilla) |

### `colecciones-api` (8 requirements / 18 scenarios)

| Requirement | Status | Evidence |
|---|---|---|
| Entornos Local y Produccion | ✅ | `collections/environments/{Local,Produccion}.yml`; `collections/opencollection.yml` |
| Convención de URLs | ✅ | `collections/Auditoria/*.yml` use `{{baseUrl}}/api/v1/...`; no hardcoded hosts |
| Cobertura de endpoints | ✅ | Existing collection parity plus `collections/Auditoria/` |
| Requests de auditoría | ✅ | `collections/Auditoria/Listar Eventos de Auditoría.yml`, `Exportar Auditoría CSV.yml` document filters, cursor, permissions, CSV columns |
| Contratos de payload y respuesta | ✅ | Collection contracts match controllers (pre-existing; not regressed by #89) |
| Credenciales y secretos | ✅ | No real secrets in `collections/`; `secret: true` pattern preserved |
| Calidad del artefacto | ✅ | OpenCollection 1.0.0 structure (`info`/`http`/`settings`/`runtime`) |
| Annulment + refund contract | ✅ | Documented in Tickets collection (pre-existing contract) |

### `panel-jerarquia` (7 requirements / 15 scenarios)

| Requirement | Status | Evidence |
|---|---|---|
| Renames y labels de navegación | ✅ | `panel/src/layouts/AdminLayout.astro`; `ROLE_LABELS` |
| Sidebar por rol | ✅ | `AdminLayout.astro:311-327`; role-gated links |
| Login y payload con `agencia_id` | ✅ | `AuthController::login/user` payload (`AuthController.php:129-135,178-185`); `panel/src/pages/login.astro:123` persists `permissions` |
| Creación de taquillas por agencia | ✅ | Pre-existing controller policy; regression suite green (user-run) |
| Selectores y formularios | ✅ | Pre-existing pages; no regression from #89 |
| Página de auditoría y logs operativo | ✅ | `auditoria.astro`, `logs.astro`, nav link by `can('view_audit')`, `/logs` banner + `view_logs`; `AuditArchitectureTest.php:162-177` |
| Actualización de tests de terminología | ✅ | `TerminologiaTest` and login X-Panel tests in suite (0 failures, user-run) |

## Findings

### CRITICAL

None. The three round-1 criticals are resolved (see *Previous criticals*) and no new critical was
introduced by the fixes.

### WARNING

#### WARNING-1 — `ExpireUnclaimedPrizesJob` does not emit `bet.expired` per affected bet

- **Requirement / scenario**: design §4.3 (`design.md:557-560` explicitly promises one `bet.expired` per
  affected bet) and §6.7 (`design.md:822-823`); `bet.expired` is a P0 catalog event
  (`specs/auditoria-eventos/spec.md:49`). No dedicated spec scenario names the job.
- **Evidence**: The job updates all ticket bets to `vencido`
  (`backend/app/Jobs/ExpireUnclaimedPrizesJob.php:111-115`) and emits only `ticket.expired`
  (`:117-137`); the code comment (`:117-119`) explicitly defers `bet.expired` to
  `MarcarApuestasVencidasJob`. `AuditoriaJobsDineroTest.php:288-318` asserts ticket expiry and bet
  state, not a `bet.expired` event for this job.
- **Impact**: Bet-level state changes caused by prize expiry have no bet-granularity evidence; only the
  ticket event’s `context.apuestas` list records the affected IDs. This is a design contradiction with
  §4.3.
- **Recommended action**: Emit one `bet.expired` P0 per affected bet inside the ticket transaction, or
  amend design §4.3 to the implemented ticket-level semantics.

#### WARNING-2 — The `Auditable` trait is not used by any production model

- **Requirement / scenario**: `auditoria-eventos` → “Mecanismos de captura”
  (`specs/auditoria-eventos/spec.md:63-65`); design §6 marks capture `trait` for
  users/entities/games (`design.md:750-771`); task WU-7 (`tasks.md:480,586`).
- **Evidence**: The trait is implemented (`backend/app/Audit/Auditable.php:22`) but
  `grep -rn "use Auditable" backend/app` returns no production model; the only consumers are the test
  probe models (`backend/tests/Feature/AuditableTest.php:109,139`). Production capture uses explicit
  recorder calls in controllers/services (e.g. `UserController.php:231-313`; `BancaController.php:120-142`).
- **Impact**: The mandated capture mechanism is exercised only by tests. Events are covered, but the
  mechanism-level MUST is a design deviation; a future developer applying the trait alongside explicit
  calls could duplicate events.
- **Recommended action**: Apply the trait to the designated models, or amend the spec/design to make
  explicit calls the accepted mechanism; add an architecture test that no model both uses the trait and
  emits the same action explicitly.

#### WARNING-3 — Backfilled `logs` events lose hierarchy scope and are invisible to `master`

- **Requirement / scenario**: `auditoria-lectura` → “Autorización por scope”
  (`specs/auditoria-lectura/spec.md:33-53`); design §7.4 scope table (`design.md:917-925`) and
  null-scope decision (`:941`).
- **Evidence**: `AuditBackfill::row` hardcodes `banca_id/grupo_id/agencia_id/taquilla_id` to null for
  every row (`backend/app/Console/Commands/AuditBackfill.php:311-314`); `mapLog` does not derive scope
  (`:207-244`). `AlcanceService::applyToAuditoria` excludes null-scope rows for `master`
  (`backend/app/Support/Scope/AlcanceService.php:229-233`).
- **Impact**: Historical `pago.prize_paid`, `ticket.voided`, `bet.voided`, `pago.legacy_reconciled` and
  activation events backfilled from `logs` are readable only by `super_master`; a `master` cannot read
  historical evidence for its own bancas.
- **Recommended action**: Derive scope from `taquilla_id`/`apuesta`/`pago`/target user during backfill,
  or document the null-scope historical limitation in `docs/dev/auditoria-sistema.md`.

#### WARNING-4 — `auth.login_failed` events carry no scope for known users

- **Requirement / scenario**: design §7.4 says auth/session events persist the target/actor chain
  (`design.md:919`); `auditoria-lectura` → “Autorización por scope”
  (`specs/auditoria-lectura/spec.md:33-53`).
- **Evidence**: `AuthController::recordLoginFailed` builds `AuditEventData` with context only and no
  `scope` (`backend/app/Http/Controllers/Api/AuthController.php:231-253`); `authEventData` (used for
  success/logout) does use the resolver (`:206-226`).
- **Impact**: Failed-login evidence for a master’s users is null-scoped and visible only to
  `super_master`, reducing tenant-level security monitoring.
- **Recommended action**: Pass `scopeResolver->forModel($user)` when the attempted email resolves to a
  known user; keep null scope for unknown emails.

#### WARNING-5 — Migration 000003 has lower evidence fidelity than design §10.3

- **Requirement / scenario**: `auditoria-integridad` → “Migración de `juego_auditoria`”
  (`specs/auditoria-integridad/spec.md:75-89`); design §10.3 (`design.md:1163-1175`).
- **Evidence**: `2026_11_05_000003_migrate_juego_auditoria_to_audit_events.php:77` always sets
  `actor_label` to null; `:86-87` derives `before`/`after` only from `cambios` (no synthesized `active`
  transition for `activar`/`desactivar`); `:88-92` uses `legacy_source` instead of the design’s
  `source` key. `AuditoriaMigracionJuegoTest.php:106,117-124` asserts the null label and passthrough.
- **Impact**: Migrated toggle events may have null `before`/`after` when the legacy `cambios` was empty,
  and historical actor labels are absent. No legacy field is dropped, so the spec’s “no loss” is met,
  but design fidelity is lower.
- **Recommended action**: Synthesize `active` before/after for `activar`/`desactivar`, resolve a masked
  actor label when the user still exists, align the context key, or update design §10.3.

#### WARNING-6 — Required-reason enforcement still accepts `unspecified` (and `other` without text) at the API boundary

- **Requirement / scenario**: `auditoria-eventos` → “Motivo”
  (`specs/auditoria-eventos/spec.md:105-113`); design §5.10 reason-code list
  (`design.md:668-671`) does not include `unspecified`; design §6.5 marks `game.limit_deleted` motivo
  `req` (`design.md:796`).
- **Evidence**: `destroyLimite` validates with `Rule::enum(AuditReason::class)`
  (`backend/app/Http/Controllers/Api/JuegoController.php:901`). The enum still ships
  `Unspecified = 'unspecified'` (`backend/app/Audit/AuditReason.php:20`) and config derives the accepted
  list from the enum (`backend/config/audit.php:68-71`). `AuditRecorder::resolveReason` only checks
  presence for `Required` policies (`AuditRecorder.php:238-243`), so a direct API client can delete a
  limit with `reason_code=unspecified` (or `other` with empty `reason_text`). The panel prevents this
  only client-side (`panel/src/utils/reason-modal.ts:123`).
- **Impact**: The strictness gained by C3 can be bypassed by a direct API caller using the escape-hatch
  code; the destructive action then has no meaningful reason. Not a CRITICAL because the spec scenario
  (absent reason → rejected) and the enum-validation MUST are satisfied and the shipped panel cannot
  produce the bypass.
- **Recommended action**: When the policy is `Required`, reject `unspecified` (and `other` without
  `reason_text`) in the controller/form-request or in `AuditRecorder::resolveReason`; add a
  covering test.

### SUGGESTION

1. **Reason-code set drift**: `AuditReason` (`backend/app/Audit/AuditReason.php:11-22`) and the panel
   (`panel/src/utils/reason-modal.ts:9-19`) use `duplicate_account`, `employee_offboarding`,
   `fraud_suspicion`, `business_decision`, `security_incident`, `unspecified`, etc., while design
   §5.10/§8.4 lists `destructive`, `security`, `access_change`, `customer_request`,
   `legal_requirement` (`design.md:669,1011-1012`). Update the design to the shipped enum or vice versa.
2. **Export limit default**: `config/audit.php:57` defaults to 50.000 rows; design §5.10/§7.5 says
   100.000 (`design.md:665,946`). Docs and collections already say 50.000; align design or config.
3. **SQLite append-only fallback**: migration 000005 skips all non-MySQL drivers
   (`2026_11_05_000005_add_audit_append_only_guards.php:20-22`), while design §9.1 says SQLite uses
   `RAISE(ABORT)` (`design.md:1068`). Production is MySQL and the tests correctly skip, but add a SQLite
   guard or document the limitation explicitly.
4. **Fingerprint hashing**: `AuditRedactor::hashFingerprint` uses plain `sha256`
   (`backend/app/Audit/AuditRedactor.php:129-146`); design §5.6 specifies HMAC-SHA256 with `app.key`
   and a `sha256:` prefix (`design.md:559`). Harden to the designed HMAC.
5. **Job batching semantics**: `MarcarApuestasVencidasJob` uses one transaction per bet
   (`backend/app/Jobs/MarcarApuestasVencidasJob.php:122-152`) instead of the chunk-level
   `recordMany('bet.expired')` described in design §4.3 (`design.md:314-315`). The per-item behaviour
   is safer for blast radius; document the deviation or align.
6. **`user_agent` redaction**: the recorder truncates but does not redact the UA
   (`backend/app/Audit/AuditRecorder.php:168`); design §5.6 says “Truncado a 512; sin secretos”
   (`design.md:563`). Run the UA through `AuditRedactor`.
7. **`/logs` nav by capability**: `panel/src/layouts/AdminLayout.astro:324` gates the link by role array
   instead of `can('view_logs')` (`design.md:1048`). Functionally equivalent for the current matrix; use
   the capability to avoid drift.
8. **Release reason default**: `ReleasePublishCommand` silently falls back to `business_decision` for a
   missing/invalid `--reason-code` (`backend/app/Console/Commands/ReleasePublishCommand.php:109-110`)
   although design marks `release.published` reason required (`design.md:806`). Consider failing the
   command or documenting the default explicitly.
9. **FK preservation test depth**: `AuditoriaIntegridadTest` verifies `information_schema` DELETE_RULE
   but does not delete a user and assert the payment/closure rows survive
   (`backend/tests/Feature/AuditoriaIntegridadTest.php:82-90`); design §10.4 recommends an end-to-end
   check (`design.md:1194`). Add the deletion test.
10. **Stale task progress counts**: `tasks.md:103-104` and `:224` still report 1.848 tests / 11.702
    assertions after commit `65ad919` added five tests; the user-run candidate suite is 1.853 / 11.748.
    Update the task record so the artifact matches the verified candidate.

## Design coherence

| Decision (design) | Followed? | Notes |
|---|---|---|
| 2.1 New `audit_events` table; `logs` operational | ✅ Yes | Table/model/migrations present; `/logs` separated |
| 2.2 Trait opt-in + explicit calls; no global observer | ⚠️ Partial | No observer; trait defined but unused in production (WARNING-2) |
| 2.3 Middleware by class in `routes/api.php` | ✅ Yes | `routes/api.php:26,30`; `AuditArchitectureTest.php:10-31` |
| 2.4 Fail-closed P0; best-effort P1; no outbox | ✅ Yes | `AuditRecorder`; P0 rollback tests |
| 2.5 `view_audit` + `AlcanceService`; others 403 | ✅ Yes | `AlcanceService::applyToAuditoria`; 403 tests |
| 2.6 Reason transport (query/body) | ✅ Yes | Query on DELETE, body elsewhere; enum validation; `game.limit_deleted` now requires the reason (WARNING-6 for the `unspecified` hatch) |
| 2.7 App + triggers/grants; hash chain optional | ✅ Yes | Model, MySQL triggers, grants file, `AUDIT_HASH_CHAIN` |
| 2.8 12m hot + 5y NDJSON.gz + SHA-256 | ✅ Yes | Archive/prune/verify commands and docs |
| 2.9 Migrate `juego_auditoria`, read-only | ✅ Yes | Migration + read-only model; fidelity notes WARNING-5 |
| 2.10 `/logs` operational with allowlist/banner | ✅ Yes | `LogController`, `logs.astro`, tests |
| 2.11 `nullOnDelete` for evidence FKs; `logs` set null | ✅ Yes | Migration 000004; schema test |
| 2.12 Denormalized actor/scope snapshots without FK | ✅ Yes | `audit_events` schema; no actor FK |

## Exclusion checks

| Exclusion | Result |
|---|---|
| No admin legacy references in the new system | ✅ No `Admin/**` or `routes/web.php` refs in audit files; legacy routes no longer exist |
| No `FetchResultsJob` | ✅ No references in `app/`, `routes/`, `config/` |
| No global Eloquent observer | ✅ No `observe(`/observers; `AuditArchitectureTest.php:41-65` |
| Direct `audit_events` writes only in allowed writers | ✅ Only recorder + `audit:archive`/`audit:backfill` (`AuditArchitectureTest.php:130-160`) |
| No writes to `juego_auditoria` outside migration/backfill | ✅ Model is read-only; `AuditArchitectureTest.php:109-128` |
| No `innerHTML` with data in `auditoria.astro`/`logs.astro` | ✅ No `innerHTML` occurrences; `AuditArchitectureTest.php:162-177` |
| No `ticket.created`/`bet.created`/`pago.income_created` audit events | ✅ Absent from `AuditAction` |
| No raw email persisted as `actor_label` | ✅ Recorder masks before persistence (`AuditRecorder.php:187,212,221-228`); backfill masks (`AuditBackfill.php:228-230,262-264`); migration writes null (`000003:77`) |
| `docs/dev/auditoria-logs.md` untouched | ✅ No diff vs `main` |

## Verdict

**PASS WITH WARNINGS.** All 45 requirements and all 91 scenarios are verified with passing covering
tests; the three round-1 CRITICALs are resolved in code and covered by new/updated tests, with no
regression discovered on the fix surface. Six WARNINGs remain (five carried from round 1 plus the
`unspecified` escape hatch), and none blocks the change under the agreed severity taxonomy. Per
instructions, no production code was modified by this verifier.
