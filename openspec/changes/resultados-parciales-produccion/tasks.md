# Tasks: Resultados parciales en producción (`resultados-parciales-produccion`)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1.100–1.300 (authored: ~650 código/tests + ~250 docs/infra) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (slice 1) → PR 2 (slice 2) → PR 3 (slice 3) → PR 4 (slice 4) |
| Delivery strategy | auto-chain |
| Chain strategy | pending (recomendado: stacked-to-main; slices desplegables en independiente) |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Resolver + `ScrapeSourceJob` + pasadas + dedupe manual por fuente | PR 1 | `php artisan test --filter='ScraperSourceResolverTest\|ScrapeSourceJobTest\|ResultadoControllerScrapeAllTest\|ScrapeResultsJobTest\|ScheduleTimeZoneTest'` | N/A — la agenda no vive hasta el slice 3 (`schedule:list` se verifica allí) | Revertir `ScraperSourceResolver`, `ScrapeSourceJob`, provider y controller → vuelve `dailyAt` por juego + dispatch manual por juego |
| 2 | Sweep `resultados:reconciliar` + day-close | PR 2 | `php artisan test --filter='ReconciliarSorteosCommandTest\|ScheduleTimeZoneTest'` | `php artisan resultados:reconciliar --dry-run` (lista faltantes sin despachar) | Quitar servicio + comando + registros sweep/cierre; las pasadas (slice 1) permanecen |
| 3 | Servicio `scheduler` en compose + entrypoint + docs | PR 3 | `php artisan test` (sin tests nuevos; verificación en rollout) | `docker compose --env-file .env.production -f docker-compose.prod.yml up -d` + `docker exec lotto_scheduler_prod php artisan schedule:list` | `docker rm -f lotto_scheduler_prod` + re-agregar línea cron |
| 4 | `resultados:metricas` + reglas Prometheus + docs | PR 4 | `php artisan test --filter='ResultadosMetricasCommandTest'` | `php artisan resultados:metricas` + inspección de `*.prom` | Quitar reglas de `alerts.yml` + `docker restart lotto_prometheus` |

---

## Slice 1: Resolver + `ScrapeSourceJob` + provider + dedupe manual por fuente

- [x] 1.1 RED — crear `backend/tests/Unit/ScraperSourceResolverTest.php`: familia lottoactivo (4 juegos) → 1 fuente `lottoactivo-animalitos`; trio/terminal slug propio → fuentes distintas; resto 1 juego por fuente. `php artisan test --filter=ScraperSourceResolverTest` → RED.
- [x] 1.2 GREEN — crear `backend/app/Services/ScraperSourceResolver.php` (`sources()`, `sourceOf(Juego)`, `scraperFor(Juego)`, DTO `ScraperSource{key,scraperClass,slug,juegoIds}`) → GREEN.
- [x] 1.3 RED — crear `backend/tests/Feature/ScrapeSourceJobTest.php` (`Http::fake`): 1 fetch persiste toda la familia (cada `juego_id`); `guardados` por juego; sin duplicados al re-ejecutar; skip si todos los miembros tienen fila `(juego,fecha,hora)`. RED.
- [x] 1.4 GREEN — crear `backend/app/Jobs/ScrapeSourceJob.php` (`ScrapeSourceJob(sourceKey, fecha, horaObjetivo)`): guarda, `saveResults` por grupo de `juego_id`, `verificarGanadores` itera miembros. GREEN.
- [x] 1.5 REFACTOR — modificar `backend/app/Jobs/ScrapeResultsJob.php`: delegar `resolveScraper`/`instantiateScraper` a `ScraperSourceResolver::scraperFor(Juego)`; actualizar `ScrapeResultsJobTest`/`ScraperResolverTest` → verde.
- [x] 1.6 RED — ampliar `backend/tests/Feature/ScheduleTimeZoneTest.php`: pasadas `scrape_{key}_{H:i}` `+15/+30/+45` (expresiones `15 8 * * *`, `30 8 * * *`, `45 8 * * *`), nombradas por fuente. RED.
- [x] 1.7 GREEN — modificar `backend/app/Providers/ScheduleServiceProvider.php`: registrar por fuente y hora pasadas `hora/+15/+30/+45` con `withoutOverlapping(5)`. GREEN.
- [x] 1.8 RED — crear `backend/tests/Feature/ResultadoControllerScrapeAllTest.php`: `scrape-all` sin `juego_id` despacha 1 job por fuente y respuesta JSON intacta. RED. (Nota: la estimación "≈38, no 50" del design refiere a producción; el seed local tiene 21 juegos con `requires_scraper` → 18 fuentes, verificado en slice 1.)
- [x] 1.9 GREEN — modificar `backend/app/Http/Controllers/Api/ResultadoController.php`: `scrapeAll`/`scrape` sin `juego_id` agrupan por fuente (`ScrapeSourceJob`), respuesta intacta. GREEN.
- [x] 1.10 — `php artisan test` completo + `vendor/bin/pint` (Pint enforced en CI) → verde y limpio.

## Slice 2: Sweep (`resultados:reconciliar` + day-close)

- [x] 2.1 RED — crear `backend/tests/Feature/ReconciliarSorteosCommandTest.php` (`Bus::fake`): solo faltantes (`juego_horarios` hora ≤ now−gracia vs `resultados` hoy); respeta `--grace/--window/--max-sources`; cap 20; `--day-close` = gracia 0 + ventana día completo; día sano = 0 despachos. RED.
- [x] 2.2 GREEN — crear `backend/app/Services/DrawReconciliationService.php` (`missingByJuego(fecha, graceMin, windowMin)`). GREEN.
- [x] 2.3 GREEN — crear `backend/app/Console/Commands/ReconciliarSorteos.php` (`resultados:reconciliar`, flags `--grace=50 --window=180 --max-sources=20 --day-close --dry-run --force`), despacha `ScrapeSourceJob` por fuente faltante. GREEN.
- [x] 2.4 RED — ampliar `ScheduleTimeZoneTest`: comandos `reconciliar_resultados` (cada 15 min) y `reconciliar_resultados_cierre` (23:45). RED.
- [x] 2.5 GREEN — modificar `ScheduleServiceProvider`: sweep cada 15 min (`withoutOverlapping(10)`) y cierre 23:45 (`withoutOverlapping(30)`), solo consola. GREEN.
- [x] 2.6 — `php artisan test` + `vendor/bin/pint`.

## Slice 3: Servicio `scheduler` (compose + entrypoint + docs + rollout)

- [x] 3.1 — modificar `backend/entrypoint.sh`: rama `RUN_SCHEDULER=true` → `exec php artisan schedule:work`; omitir migraciones/`optimize:clear` con `RUN_HORIZON` o `RUN_SCHEDULER` (solo API migra).
- [x] 3.2 — modificar `docker-compose.prod.yml`: servicio `scheduler` (`lotto_scheduler_prod`, imagen api, `RUN_SCHEDULER: "true"`, healthcheck `schedule:list`, depends_on mysql/redis healthy) + volumen `/home/deploy/monitoring/textfile:/var/lib/lotto-metrics:rw` en `scheduler` (y `api` en transición).
- [x] 3.3 — actualizar `docs/deploy.md` (servicio scheduler, reinicio tras sembrar horarios, volumen textfile), `docs/manual-mantenimiento.md` (§8 operación, `schedule:list`) y `docs/runbook-ops.md` (comandos/rollback).
- [ ] 3.4 ROLLOUT (usuario) — retirar del host la línea cron `schedule:run` (`crontab -e`; conservar restic); `docker compose --env-file .env.production -f docker-compose.prod.yml up -d`; verificar `crontab -l` (sin la línea), `docker ps` (`lotto_scheduler_prod`), `docker exec lotto_scheduler_prod php artisan schedule:list`. Nota: mutex Redis (`withoutOverlapping`) evita doble dispatch durante la transición.

## Slice 4: Alertas (`resultados:metricas` + reglas Prometheus + docs)

- [ ] 4.1 RED — crear `backend/tests/Feature/ResultadosMetricasCommandTest.php`: `*.prom` atómico y correcto (métricas `lotto_draws_expected_today|persisted_today|missing|pending_seconds|daily_incomplete|metrics_timestamp`, label `juego=slug`); falla si falta el dir. RED.
- [ ] 4.2 GREEN — crear `backend/app/Console/Commands/ResultadosMetricas.php` (`resultados:metricas`): textfile atómico (tmp+rename), falla si falta el dir. GREEN.
- [ ] 4.3 RED — ampliar `ScheduleTimeZoneTest`: comando `resultados_metricas` cada 15 min. RED.
- [ ] 4.4 GREEN — modificar `ScheduleServiceProvider`: registrar `resultados:metricas` cada 15 min (`withoutOverlapping(5)`). GREEN.
- [ ] 4.5 — actualizar `docs/deploy.md` §12.4 `alerts.yml`: reglas `MissingDraw` (`pending_seconds>3600`, 5m), `DailyDrawsIncomplete` (`daily_incomplete>0`, 30m), `DrawMetricsStale` (>1h, 15m) (convención `BackupNotRun`).
- [ ] 4.6 ROLLOUT (usuario) — aplicar reglas a `/home/deploy/monitoring/alerts.yml` + `docker restart lotto_prometheus`.

## Rollback por slice

- Slice 1: revertir `ScraperSourceResolver`/`ScrapeSourceJob`/provider/controller + tests → `dailyAt` por juego y dispatch manual por juego.
- Slice 2: quitar `DrawReconciliationService`/`ReconciliarSorteos` + registros sweep/cierre; las pasadas permanecen.
- Slice 3: `docker rm -f lotto_scheduler_prod` (el `up -d` no borra huérfanos) + re-agregar línea cron; quitar volumen/rutas.
- Slice 4: quitar reglas de `alerts.yml` + `docker restart lotto_prometheus`; quitar registro `resultados:metricas`.
- Upsert idempotente (`saveResults`) hace seguro re-ejecutar sin duplicados.
