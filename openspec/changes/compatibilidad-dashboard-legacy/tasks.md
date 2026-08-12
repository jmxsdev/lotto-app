# Tasks: Compatibilidad Dashboard Legacy (Fase 2 — Paridad Maxplay)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~2,400–2,600 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 → PR 2 → PR 3 → PR 4 → PR 5 → PR 6 → PR 7 |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | DB foundation: 5 migrations + models | PR 1 | `composer test -- --filter="test_migrate\|test_model"` | `php artisan migrate` — verify schema | Rollback all 5 migrations; drop new models |
| 2 | Moneda/limite inheritance + validation in createApuesta | PR 2 | `composer test -- --filter="Moneda\|Limite\|Inheritance\|Apuesta"` | `POST /api/apuestas` with disabled currency → 422 | Revert ApuestaService methods, keep models |
| 3 | Limits CRUD API + panel page | PR 3 | `composer test -- --filter="Limite"` | Panel: configure limit → test via apuesta rejection | Drop routes, delete limites.astro |
| 4 | Report endpoints + service aggregation methods | PR 4 | `composer test -- --filter="Reporte\|Ventas\|Tickets\|Taquilla"` | `GET /api/reportes/ventas-totales` returns JSON | Drop ReporteController, remove routes |
| 5 | Charts API + panel chart page | PR 5 | `composer test -- --filter="Estadistica\|TimeSeries"` | Panel: rendimiento renders Line+Bar charts | Drop EstadisticaController, delete page, remove CDN scripts |
| 6 | Expired prizes job + integration | PR 6 | `composer test -- --filter="ExpireUnclaimed\|Vencido"` | Manual trigger job; verify estado change | Drop job, remove console.php schedule line |
| 7 | 4 report panel pages | PR 7 | N/A — UI only | Panel: navigate sub-pages render tables | Delete 4 report .astro files |

## Phase 1: Database Foundation (PR #1)

- [x] 1.1 Create migration `add_monedas_permitidas_to_bancas_and_grupos`: JSON columns `monedas_permitidas` (nullable, default=null=both) on `bancas` and `grupos`
- [x] 1.2 Create migration `create_juego_limites_table`: columns per design §Database Schema Migration 2; unique index `(juego_id, moneda, banca_id, COALESCE(grupo_id,-1), COALESCE(taquilla_id,-1))`
- [x] 1.3 Create migration `add_vigencia_premios_to_grupos`: column `vigencia_premios INT NULL` on `grupos` (NULL = never expires)
- [x] 1.4 Create migration `add_vencido_to_estados`: ALTER ENUM `apuestas.estado` + `'vencido'`, `tickets.estado` + `'vencido'`
- [x] 1.5 Create migration `migrate_costo_minimo_to_limites`: copy `juegos.costo_minimo` → `juego_limites.limite_minimo` per banca, moneda='bs'; drop `costo_minimo` from `juegos`
- [x] 1.6 Create model `backend/app/Models/JuegoLimite.php`: fillable guard, casts, `belongsTo`(Juego,Banca,Grupo,Taquilla)
- [x] 1.7 Update `Banca` model: `$fillable += monedas_permitidas`, `$casts += monedas_permitidas=>array`, `hasMany(JuegoLimite)`
- [x] 1.8 Update `Grupo` model: `$fillable += [monedas_permitidas, vigencia_premios]`, `$casts += [monedas_permitidas=>array, vigencia_premios=>integer]`, `hasMany(JuegoLimite)`
- [x] 1.9 Update `Taquilla` + `Juego` models: `hasMany(JuegoLimite)` relationship
- [x] 1.10 Run `php artisan migrate` and verify schema

## Phase 2: Inheritance Resolvers + Validation (PR #2)

- [x] 2.1 `ApuestaService::getEffectiveMonedas(int $taquillaId): array` — resolve banca∩grupo currency intersection; null=both enabled
- [x] 2.2 `ApuestaService::getEffectiveLimit(int $taquillaId, int $juegoId, string $moneda): ?JuegoLimite` — cascade: taquilla→grupo→banca via single COALESCE query per design pseudocode
- [x] 2.3 `ApuestaService::validarMonedaYPremios(int $taquillaId, int $juegoId, float $bs, float $usd): array` — wraps resolvers, returns `['valid'=>bool,'message'=>string]`
- [x] 2.4 Integrate moneda+límite validation into `createApuesta()` before existing `validarCostoMinimo` call, inside existing flow
- [x] 2.5 RED: `test_rechaza_apuesta_en_moneda_deshabilitada` — POST with USD on grupo where usd=false → expects 422
- [x] 2.6 RED: `test_rechaza_apuesta_que_excede_limite_maximo` — POST above max → 422
- [x] 2.7 RED: `test_limite_taquilla_prevalece_sobre_grupo` — taquilla limit=50, grupo=100, bet 60 → 422
- [x] 2.8 GREEN: Implement validation logic, all tests pass
- [x] 2.9 RED+: `test_apuesta_mixta_requiere_ambas_monedas` — mixed currency rejected if either is disabled

## Phase 3: Limits API + Panel (PR #3)

- [x] 3.1 `JuegoController::limites(Juego $juego)` — GET `/api/limites/{juego}` returns limits scoped by user hierarchy
- [x] 3.2 `JuegoController::updateLimites(Juego $juego)` — PUT upsert, validates restrictiveness (child≤parent)
- [x] 3.3 `JuegoController::batchLimites()` — POST `/api/limites/batch` atomic upsert array of limits
- [x] 3.4 Add routes: `GET|PUT /api/limites/{juego}`, `POST /api/limites/batch` inside auth:sanctum,verify.mac group
- [x] 3.5 `GrupoController::store/update` — accept `monedas_permitidas`, `vigencia_premios` in validation
- [x] 3.6 Panel: `panel/src/pages/limites.astro` — game selector + banca selector + moneda toggle + limit form + batch button
- [x] 3.7 Panel: add BS/USD checkboxes to `grupos.astro` edit form
- [x] 3.8 Panel: `AdminLayout.astro` — add "🎯 Límites" nav link for super_master|master|banca roles
- [x] 3.9 RED: `test_grupo_no_excede_limite_banca` — grupo attempts max=200 when banca max=100 → 422

## Phase 4: Reports API (PR #4)

- [x] 4.1 Create `backend/app/Http/Controllers/Api/ReporteController.php` — inject ApuestaService, hierarchical scope per role
- [x] 4.2 `ApuestaService::ventasTotales(array $filters): array` — SUM by banca with Venta/Premio/Utilidad/Participacion
- [x] 4.3 `ApuestaService::relacionTickets(array $filters): Paginator` — tickets with Usuario, Sorteos count, Jugadas count, Tipo
- [x] 4.4 `ApuestaService::rendimientoTaquillas(array $filters): array` — per-taquilla: Venta, Anulado, Premio, Ganancia, %Peso
- [x] 4.5 Add routes: `GET /api/reportes/{ventas-totales,relacion-tickets,rendimiento-taquillas,vencidos}`
- [x] 4.6 RED: `test_ventas_totales_agrupa_por_banca` — multiple bets across bancas → correct aggregation
- [x] 4.7 RED: `test_taquilla_solo_ve_sus_datos_en_reportes` — role=taquilla sees only own data
- [x] 4.8 RED: `test_rango_vacio_retorna_200` — empty date range → 200 with empty array

## Phase 5: Charts API + Panel (PR #5)

- [x] 5.1 Create `backend/app/Http/Controllers/Api/EstadisticaController.php` — `rendimiento()` method, time-series
- [x] 5.2 `ApuestaService::timeSeriesData(array $filters): array` — GROUP BY DATE(fecha_hora), 6 daily series: ventas,premios,pagados,vencidos,devolucion,saldo; zero-fill gaps
- [x] 5.3 Add route: `GET /api/estadisticas/rendimiento`
- [x] 5.4 Panel: `panel/src/pages/rendimiento.astro` — Line chart (6 series) + Bar chart (totals) via Chart.js CDN
- [x] 5.5 Panel: `AdminLayout.astro` — add "📈 Rendimiento" nav link for all roles
- [x] 5.6 RED: `test_time_series_zero_fills_gaps` — bets on days 1,3,5 → 5 buckets, zeros on days 2,4
- [x] 5.7 RED: `test_rango_vacio_rendimiento_retorna_200` — empty date range → 200 with zero-filled series

## Phase 6: Expired Prizes (PR #6)

- [x] 6.1 Create `backend/app/Jobs/ExpireUnclaimedPrizesJob.php` — ShouldQueue, query tickets estado='ganador' with expired `vigencia_premios`, update to 'vencido' inside DB::transaction()
- [x] 6.2 Register schedule in `console.php`: `Schedule::job(new ExpireUnclaimedPrizesJob)->dailyAt('01:00')`
- [x] 6.3 Integrate `vencido` state into `ventasTotales` (deduct from utilidad) and `timeSeriesData` (vencidos series) — timeSeriesData vencidos series done in PR #5; ventasTotales includes vencidos in Venta; utilidad deduction pending
- [x] 6.4 RED: `test_marca_como_vencido_ticket_ganador_expirado` — ticket 35 days old, vigencia=30 → estado changes to vencido (plus 3 additional tests per orchestrator)
- [x] 6.5 RED: `test_job_es_idempotente` — running job twice does not reprocess already-vencido tickets

## Phase 7: Report Panel Pages (PR #7)

- [ ] 7.1 Panel: `panel/src/pages/reportes/ventas.astro` — date filter + banca aggregation table
- [ ] 7.2 Panel: `panel/src/pages/reportes/tickets.astro` — paginated ticket table with computed columns
- [ ] 7.3 Panel: `panel/src/pages/reportes/taquillas.astro` — taquilla performance table with % weights
- [ ] 7.4 Panel: `panel/src/pages/reportes/vencidos.astro` — expired ticket list with grupo/vigencia info
- [ ] 7.5 Panel: `AdminLayout.astro` — add "📊 Reportes" dropdown with sub-links for all roles
