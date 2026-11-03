# Verification Report

**Change**: retiro-ledger-comisiones (Model A — consultive commissions)
**Version**: N/A (OpenSpec delta, no spec version field)
**Mode**: Standard (Strict TDD not active)
**Worktree**: `/home/gzuz/Documentos/lotto-app-worktrees/sdd-issue-67-modelo-a`
**Branch**: `sdd/issue-67-modelo-a-3` (slices 1a → 1b → 2a → 2b → 3)
**Commit under verification**: `760191d` (merge to `main`)

```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:1fd5ce8976d84b1b0fb4342fcfc310a7165d89c49b9eb0f100e0de30b99ec443
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 4/4
scenarios: 17/17
test_command: 'cd backend && composer test:parallel'
test_exit_code: 0
build_command: 'cd panel && npm run build'
build_exit_code: 0
```

> **Evidence-scope note.** The static verification (syntax + forbidden-symbol checks) ran in
> read-only mode against `6b19f72`. The runtime evidence below was produced later by the user
> and by CI/CD on the merged commit `760191d`, and is recorded here at archive time.
> `requirements: 4/4` and `scenarios: 17/17` mean implementation + covering test are present;
> the per-scenario runtime column states what the executed suites actually prove. Two
> scenarios remain `PARTIAL (runtime)` because their covering test is narrower than the
> scenario text — see WARNING 1 and WARNING 2. Neither is a blocker.

## Completeness

| Metric | Value |
|--------|-------|
| Tasks total | 19 |
| Tasks complete | 19 |
| Tasks incomplete | 0 |

All checkboxes in `openspec/changes/retiro-ledger-comisiones/tasks.md` are checked
(`grep -c "^- \[x\]"` → 19; `grep -c "^- \[ \]"` → 0).

## Build & Tests Execution

**Static syntax check** (executed during verify): ✅ Passed, exit 0
```text
php -l backend/app/Services/ComisionService.php                          → No syntax errors detected
php -l backend/app/Http/Controllers/Api/ComisionController.php           → No syntax errors detected
php -l backend/routes/api.php                                            → No syntax errors detected
php -l backend/database/migrations/2026_10_30_000001_archive_and_drop_comisiones_table.php → No syntax errors detected
php -l backend/tests/Feature/ComisionesApiTest.php                       → No syntax errors detected
php -l backend/tests/Unit/ComisionServiceTest.php                        → No syntax errors detected
```

**Static absence check** (executed during verify): ✅ Passed, exit 0 (no ledger/payment
symbols in the panel page or in `ComisionService`).

**Runtime test suite**: ✅ Passed — `cd backend && composer test:parallel`, **1240 tests
green** (user-confirmed). Includes `ComisionServiceTest`, `ComisionesApiTest`,
`ComisionCierreTest`, `ComisionReporteTest` and `CierreCajaTest`.

**Panel build**: ✅ Passed — `cd panel && npm run build`, **27 pages** built.

**Migration replay**: ✅ Covered by the suite — `RefreshDatabase` on `lotto_test` replays the
historical `comisiones` migrations and then the archive+drop migration on every run; the
end-state test asserts `comisiones` absent and `comisiones_archivo_2026` present and empty.

**CI/CD**: ✅ GitHub Actions run
[`41081174437`](https://github.com/jmxsdev/lotto-app/actions/runs/41081174437) — **success**
on head `760191d`, including the **"Deploy al VPS"** job.

**Manual UI check**: ➖ Not performed (optional). The panel build proves compilation, not
browser behavior; the read-only page was not exercised manually as `super_master`/`master`.

**Coverage**: ➖ Not available (no coverage command run).

> **Environment note (not a code defect).** An earlier `composer test:parallel` run failed in
> the worktree because its `vendor/` autoloader was symlinked to the main worktree. It was
> resolved with a local `composer install` inside the worktree; the suite then passed. No code
> change was involved.

## Spec Compliance Matrix

Status semantics: `PASS (runtime)` = the covering test executed green; `PARTIAL (runtime)` =
implementation present and covered, but the covering test is narrower than the scenario text;
`PENDING (runtime)` = the covering test has not been executed.

| # | Requirement | Scenario | Covering test | Static | Runtime |
|---|-------------|----------|---------------|--------|---------|
| R1 | REMOVED: Liquidación en el ledger | (no scenarios in delta) | Removal proven by absence checks + `test_archivo_reemplaza_la_tabla_comisiones` | PASS | PASS |
| R2 | Independencia entre niveles y tope acumulado | Hijo excede al padre con tope | `ComisionServiceTest::test_banca_10_taquilla_100_liquida_90` | PASS | PASS |
| R2 | Independencia entre niveles y tope acumulado | Banca sin ancestros cobra su propia tasa | `ComisionServiceTest::test_banca_liquida_su_tasa_propia_sin_ancestros` | PASS | PASS |
| R2 | Independencia entre niveles y tope acumulado | Suma cero con tres niveles | `ComisionServiceTest::test_suma_cero_banca_grupo_taquilla_10_20_100` | PASS | PASS |
| R2 | Independencia entre niveles y tope acumulado | Suma acumulada ≤ 100 conserva tasas | `ComisionServiceTest::test_suma_acumulada_menor_igual_100_conserva_la_tasa` | PASS | PASS |
| R2 | Independencia entre niveles y tope acumulado | Tope independiente por moneda | `ComisionServiceTest::test_tope_independiente_por_moneda` | PASS | PASS |
| R2 | Independencia entre niveles y tope acumulado | NULL/ausente y herencia | `ComisionServiceTest::test_null_herencia_banca_60_resuelve_60_y_liquida_40` | PASS | PASS |
| R2 | Independencia entre niveles y tope acumulado | animalitos legacy se clampa | `ComisionServiceTest::test_animalitos_legacy_se_clampa_a_16_y_efectiva_intacta` | PASS | PASS |
| R2 | Independencia entre niveles y tope acumulado | tripletas al tope | `ComisionServiceTest::test_tripletas_se_clampa_a_25` | PASS | PASS |
| R2 | Independencia entre niveles y tope acumulado | otros tipos sin tope | `ComisionServiceTest::test_terminales_sin_tope` | PASS | PASS |
| R2 | Independencia entre niveles y tope acumulado | tope por tipo a nivel independiente | `ComisionServiceTest::test_cadena_animalitos_tres_niveles_cada_uno_al_tope_16` | PASS | PASS |
| R3 | Sincronía single/bulk de cálculo | single y bulk coinciden | `ComisionServiceTest::test_single_y_bulk_aplican_el_mismo_tope` | PASS | PASS |
| R3 | Sincronía single/bulk de cálculo | reportes y consulta usan liquidado | `ComisionReporteTest::test_ventas_totales_animalitos_legacy_liquida_al_tope_16`; consulta reuses `tasasLiquidablesBulk` via `filasPorRango` (no consulta-specific clamp test) | PARTIAL | PARTIAL |
| R4 | Consulta de comisión por rango | Tres niveles en una consulta | `ComisionesApiTest::test_consulta_devuelve_tres_niveles_con_monto_string` | PASS | PASS |
| R4 | Consulta de comisión por rango | Sin ventas en el rango | `ComisionesApiTest::test_consulta_rango_sin_ventas_devuelve_data_vacia` | PASS | PASS |
| R4 | Consulta de comisión por rango | Alcance del master | `ComisionesApiTest::test_consulta_master_solo_ve_sus_bancas` | PASS | PASS |
| R4 | Consulta de comisión por rango | Rol no autorizado | `ComisionesApiTest::test_consulta_403_para_roles_no_autorizados` covers banca/grupo/taquilla; `agencia` is enforced by `role:super_master\|master` but not parametrized | PARTIAL | PARTIAL |
| R4 | Consulta de comisión por rango | Sin persistencia (ledger eliminado) | `ComisionesApiTest::test_consulta_no_persiste_en_el_ledger_ni_registra_liquidacion` | PASS | PASS |

**Compliance summary (runtime)**: 15/17 scenarios fully covered and green, 2/17 partial
(covering test narrower than the scenario text), 0 failing, 0 pending.

## Correctness (Static Evidence)

| Requirement / check | Status | Evidence |
|---|---|---|
| `App\Models\Comision` model removed | ✅ | `backend/app/Models/Comision.php` does not exist; no `App\Models\Comision` / `Comision::` references in `backend/app` or `backend/tests` (only `ComisionDefault` remains) |
| `comisiones()` relations removed | ✅ | No `function comisiones` in `backend/app/Models`; `Banca`/`Grupo`/`Taquilla` cleaned |
| Ledger service methods removed | ✅ | No `previsualizar`/`liquidar`/`conflictosPorRango`/`filasLiquidables` in `ComisionService`; only `filasPorRango` remains as the rows-only public method |
| Engine preserved | ✅ | `tasaPropia`, `tasaEfectiva`, `tasaLiquidable`, `topePorTipo`, `comisionEntidad`, `comisionesReporte`, `tasasLiquidablesBulk` still present (`backend/app/Services/ComisionService.php:39-521`) |
| Ledger controller methods removed | ✅ | `ComisionController` exposes only `defaults`, `updateDefaults`, `consulta`, private `bancasEnAlcance` |
| Ledger routes removed | ✅ | `backend/routes/api.php` has only `GET/PUT /comisiones/defaults` and `GET /comisiones/consulta`; no `GET /comisiones`, `POST /comisiones/liquidar`, `PATCH /comisiones/{comision}/pagar` |
| `GET /comisiones/consulta` authorization | ✅ | Route inside `auth:sanctum` + `verify.mac`, `role:super_master\|master`, no `manage_comisiones` (`backend/routes/api.php:242-247`) |
| Validation 422 | ✅ | `desde`/`hasta` required/date, `hasta` `after_or_equal:desde`; test `test_consulta_422_si_faltan_fechas_o_rango_invertido` |
| Master scope / out-of-scope 403 | ✅ | `bancasEnAlcance()` returns `masterBancaIds()->all()` for `master`, `null` for `super_master`; `array_diff` → 403 |
| Response contract | ✅ | `{data:[{nivel,entidad_id,entidad,monto_comision}], meta:{desde,hasta,total}}`; `number_format(..., 2, '.', '')` yields string 2 decimals; no `estado`/`logs`/conflict list |
| Read-only / no persistence | ✅ | `filasPorRango` only reads `Apuesta`/hierarchy/limits; no writes; test asserts `comisiones` absent and no `comision_liquidar` log |
| Panel read-only | ✅ | `panel/src/pages/comisiones.astro` has no Liquidar/Pagar/conflictos/`fetchBody`/`renderConflictos`; calls `GET /comisiones/consulta`; keeps level/entity filters + 15-row client pagination |
| Ledger collections removed | ✅ | `collections/Comisiones/` contains only `Actualizar Defaults.yml`, `Consultar Comisiones.yml`, `folder.yml`, `Ver Defaults.yml` |
| Defaults intact | ✅ | `GET/PUT /comisiones/defaults`, `comision_defaults` migration and tests untouched |
| Close snapshot intact | ✅ | `cierres_caja.comision_bs_equivalent` migration/model/service/test references unchanged; `CierreService::comisionCadena` still calls `comisionEntidad` |
| Reports intact | ✅ | `ApuestaService::comisionesPorEntidad`/`comisionesRollup` still call `comisionesReporte`; `ComisionReporteTest` assertions retained |
| Migration archive+drop | ✅ | `2026_10_30_000001_archive_and_drop_comisiones_table.php`: archive table with no FKs/unique, explicit-column `insertUsing`, count verify before drop, `hasTable` guards, non-empty-archive guard, `down()` recreates empty `comisiones` |
| Migration end-state test | ✅ | `ComisionesApiTest::test_archivo_reemplaza_la_tabla_comisiones` asserts `comisiones` absent, `comisiones_archivo_2026` present and empty |
| Docs updated | ✅ | `docs/dev/motor-cierres-comisiones.md`, `integracion-front-motor-premios.md`, `pendientes-front.md`, `runbook-ops.md`, `estructura.md`, `arquitectura-backend.md`, `CHANGELOG.md` all show the Model A content |

## Coherence (Design)

| Decision | Followed? | Notes |
|---|---|---|
| AD1 — new `GET /comisiones/consulta` reusing one rows-only builder | ✅ Yes | `ComisionService::filasPorRango` |
| AD2 — `monto_comision` string 2 decimals | ✅ Yes | `number_format(..., 2, '.', '')`; test asserts `"10.00"` |
| AD3 — `{data, meta}` envelope | ✅ Yes | `meta: {desde, hasta, total}` |
| AD4 — single public `filasPorRango` (no `previsualizar` wrapper) | ✅ Yes | `previsualizar`/`filasLiquidables` removed |
| AD5 — keep one `ComisionController` with `consulta` + `bancasEnAlcance` | ✅ Yes | |
| AD6 — master out-of-scope `banca_ids` → 403 | ✅ Yes | Controller `array_diff` check |
| AD7 — copy → `comisiones_archivo_2026` → drop | ✅ Yes | Migration `up()` |
| AD8 — `down()` recreates empty `comisiones` | ✅ Yes | Guarded by `hasTable` |
| AD9 — client-side pagination 15/page | ✅ Yes | `POR_PAGINA = 15` |
| AD10 — keep `ComisionesApiTest` name | ✅ Yes | File retained |
| AD11 — copy/update `motor-cierres-comisiones.md` | ✅ Yes | New tracked file, Model A rewrite |
| AD12 — endpoint + panel before removal (1a→1b→2a→2b→3) | ✅ Yes | Apply-progress and commit history |

## Issues Found

**CRITICAL**: None.

**WARNING** (follow-ups, not blockers):

1. **`agencia` 403 is not parametrized in the test.**
   `test_consulta_403_para_roles_no_autorizados` covers `banca`, `grupo`, and `taquilla`; the
   spec scenario also names `agencia`. The route middleware (`role:super_master|master`)
   enforces it, but no test proves it. Follow-up: add `agenciaUser()` to the loop.
2. **`docs/dev/planificacion.md` is stale.** Sprint 13 still describes a `comisiones` ledger
   with `pendiente`/`Pagado` (`docs/dev/planificacion.md:352-353`). The file is explicitly
   marked outdated at the top, but it contradicts Model A. Follow-up cleanup recommended.

**SUGGESTION**:

1. Add a consulta-specific post-tope clamp test (e.g. `animalitos` legacy 40% →
   `monto_comision` 16%) so R3 scenario "reportes y consulta usan liquidado" is covered on
   both surfaces, not only the report. This is the second `PARTIAL` scenario.
2. `docs/dev/estructura.md:32` still lists `Jobs/ (Scrapers, Comisiones, Cierres)` although no
   `Comisiones` job exists. Pre-existing stale line, unrelated to the ledger removal.

## Runtime Evidence

| Check | Command / source | Result |
|---|---|---|
| Backend suite | `cd backend && composer test:parallel` | ✅ 1240 tests green (user-confirmed) |
| Panel build | `cd panel && npm run build` | ✅ 27 pages built |
| CI/CD | GitHub Actions run `41081174437`, head `760191d` | ✅ success, including "Deploy al VPS" |
| Manual `/comisiones` UI | `super_master` / `master` in browser | ➖ Not performed (optional) |

Manual UI checks that were **not** exercised: range with banca/grupo/taquilla rows and
2-decimal amounts, level/entity filters, 15-row pagination, empty-range message,
`hasta < desde` → 422 message, master out-of-scope `banca_ids` → 403 message, and absence of
Liquidar/Pagar controls and `estado` badges. Compilation and the API tests cover the contract;
the visual/interaction pass remains optional.

## Residual Risks

- **Production row count is unknown.** The archive+drop is irreversible without the pre-deploy
  `mysqldump`; the runbook documents the dump/count gate, and the migration throws before
  dropping on a short copy. The deploy completed successfully on `760191d`.
- **`down()` restores schema, not data.** Rollback recreates an empty `comisiones`; historical
  rows come only from the dump.
- **`comisiones_archivo_2026` is not an active store.** Any future code that needs historical
  rows must read the archive explicitly; it has no FKs or unique indexes.
- **`logs.details.comision_id` references** remain historical audit data; accepted in design.
- **Stale `planificacion.md`** could mislead a future contributor into re-creating the ledger.
- **Two scenarios are `PARTIAL`**, not `PASS`: the `agencia` 403 case and the consulta-specific
  post-tope clamp. Both are coverage-width gaps, not observed defects.

## Verdict

**PASS WITH WARNINGS** — all 4 delta requirements are implemented; 15/17 scenarios are fully
covered and green at runtime, 2/17 are covered by a narrower test (`PARTIAL`, no observed
defect), and there are 0 blockers and 0 critical findings. Runtime evidence is green on the
merged commit `760191d` (1240 backend tests, 27-page panel build, CI/CD success including the
VPS deploy). The two warnings are follow-ups, not blockers: the `agencia` 403 test gap and the
stale `docs/dev/planificacion.md`. The manual UI check was not performed and remains optional.
