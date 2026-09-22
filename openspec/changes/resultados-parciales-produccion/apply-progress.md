# Apply Progress — Slices 1–3 (`resultados-parciales-produccion`)

**Estado**: Slice 1 COMPLETO (tareas 1.1–1.10). Slice 2 COMPLETO (tareas 2.1–2.6). Slice 3 COMPLETO (tareas 3.1–3.3; tarea 3.4 ROLLOUT pendiente de ejecución por el usuario). Slice 4 NO implementado (alertas).

**Modo**: STRICT TDD. Runner: `php artisan test` (canonical; CI usa `php artisan test --display-warnings`).

**Base**: `d250c20` — rama `feat/resultados-parciales-produccion` en el worktree
`lotto-app-worktrees/resultados-parciales-produccion`. Nada fue pusheado ni mergeado.

---

## Archivos cambiados (Slice 1)

| Archivo | Acción | Qué |
|---|---|---|
| `backend/app/Services/ScraperSource.php` | Crear | DTO `ScraperSource{key, scraperClass, slug, juegoIds}` (readonly). |
| `backend/app/Services/ScraperSourceResolver.php` | Crear | `sources()`, `sourceOf(Juego)`, `sourceByKey(key)`, `scraperFor(Juego)`, `scraperClassFor(Juego)`, `instantiateClass(clase, Juego)`. Consolidación: feed `animalitos` → `lottoactivo-animalitos`; trio/terminal → `lottoactivo-trio_activo`/`lottoactivo-terminal_activo`; resto 1 juego por fuente (key = slug). Contrato de clase idéntico al anterior `resolveScraper` (scraper_class > URL > convención). |
| `backend/app/Jobs/ScrapeSourceJob.php` | Crear | `ScrapeSourceJob(sourceKey, fecha, horaObjetivo)`. Guarda: ejecuta solo si ≥1 miembro programado a `horaObjetivo` sin fila `(juego,fecha,hora)`; `horaObjetivo=null` (manual) siempre ejecuta. 1 fetch → `saveResults` por grupo de `juego_id` (upsert intacto) → log `total` + `por_juego` → `verificarGanadores` itera miembros. |
| `backend/app/Jobs/ScrapeResultsJob.php` | Modificar | `handle()` usa `ScraperSourceResolver::scraperFor(Juego)`; `resolveScraper`/`instantiateScraper` quedan como delegados finos (compatibilidad con tests previos por reflexión). |
| `backend/app/Providers/ScheduleServiceProvider.php` | Modificar | Registra por fuente y hora las pasadas `scrape_{sourceKey}_{H:i}[+15|+30|+45]` con `withoutOverlapping(5)`, solo consola. |
| `backend/app/Http/Controllers/Api/ResultadoController.php` | Modificar | `scrapeAll`/`scrape` sin `juego_id` agrupan por fuente → 1 `ScrapeSourceJob::dispatch` por fuente; respuesta JSON desglosada por juego (shape intacto). Path individual con `juego_id` sin cambios (`ScrapeResultsJob`). |
| `backend/tests/Unit/ScraperSourceResolverTest.php` | Crear | 16 tests del resolver (familia, trio/terminal, 1:1, sourceOf, scraperFor, fallbacks, instanciación). |
| `backend/tests/Feature/ScrapeSourceJobTest.php` | Crear | 7 tests del job por fuente (stub `AnimalitosScraperFake` corta solo el fetch; parse y saveResults reales). |
| `backend/tests/Feature/ScheduleTimeZoneTest.php` | Modificar | 5 tests: hora local sin doble conversión, pasadas +15/+30/+45 por fuente, +45 del último sorteo 23:00 → 23:45, familia consolidada (4 pasadas, no 16). |
| `backend/tests/Feature/ResultadoControllerScrapeAllTest.php` | Crear | 3 tests: scrape-all despacha 1 job por fuente (18 en el seed, no 21), scrape sin juego_id igual, 401 sin auth. |
| `openspec/changes/resultados-parciales-produccion/tasks.md` | Modificar | Tasks 1.1–1.10 marcadas `[x]`. |
| `openspec/changes/resultados-parciales-produccion/apply-progress.md` | Crear | Este artefacto. |

## TDD Cycle Evidence (Slice 1)

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| 1.1 | `tests/Unit/ScraperSourceResolverTest.php` | Unit | N/A (nuevo) | ✅ 16 errores (clase no existe) | ✅ 16/16 | ✅ 16 casos (familia, trio/terminal, 1:1, fallbacks) | ✅ `fuenteKeyDe` deduplicado contra `buildSource` |
| 1.2 | (mismo archivo) | Unit | N/A | ✅ (1.1 cubre) | ✅ 16/16 (34 asserts) | ➖ cubierto por 1.1 | ✅ Pint |
| 1.3 | `tests/Feature/ScrapeSourceJobTest.php` | Feature | N/A (nuevo) | ✅ 7 errores (`ScrapeSourceJob` no existe) | ✅ 7/7 | ✅ 7 casos (familia, log por juego, re-run, skip, sin miembros, manual, fuente inexistente) | ✅ fix de doble de contenedor (bind closure vs instance) |
| 1.4 | (mismo archivo) | Feature | N/A | ✅ (1.3 cubre) | ✅ 7/7 (23 asserts) | ➖ cubierto por 1.3 | ✅ Pint |
| 1.5 | `ScraperResolverTest` + `ScrapeResultsJobTest` | Unit/Feature | ✅ 20/21 | ➖ refactor con approval (tests existentes = approval) | ✅ 18/19 (1 skip pre-existente) | ➖ contrato idéntico preservado | ✅ delegación a resolver, -46 líneas |
| 1.6 | `tests/Feature/ScheduleTimeZoneTest.php` | Feature | ✅ 20/21 (previo) | ✅ 4 fallos (provider viejo) | ✅ 5/5 | ✅ 5 casos (hora local, +15/30/45, 23:00+45, familia) | ➖ none |
| 1.7 | (mismo archivo) | Feature | N/A | ✅ (1.6 cubre) | ✅ 5/5 (12 asserts) | ➖ cubierto por 1.6 | ✅ Pint |
| 1.8 | `tests/Feature/ResultadoControllerScrapeAllTest.php` | Feature | N/A (nuevo) | ✅ 0 despachos vs 18 esperados | ✅ 3/3 | ✅ 3 casos (scrape-all, scrape sin id, 401) | ✅ URL corregida a `/api/v1` |
| 1.9 | (mismo archivo) | Feature | N/A | ✅ (1.8 cubre) | ✅ 3/3 (58 asserts) | ➖ cubierto por 1.8 | ✅ Pint |
| 1.10 | suite completa | All | ✅ | — | ✅ 760 tests / 758 passed / 2 skipped pre-existentes / 0 fails | — | ✅ `pint --test` limpio |

---

## Archivos cambiados (Slice 2)

| Archivo | Acción | Qué |
|---|---|---|
| `backend/app/Services/DrawReconciliationService.php` | Crear | `missingByJuego(fecha, graceMin, windowMin)`: cruza `juego_horarios` (active, juegos `requires_scraper` + `active`) contra `resultados` del día. Un sorteo es faltante si su hora ya pasó la gracia (`hora <= now − gracia`), está dentro de la ventana de recuperación (`hora >= now − (gracia + ventana)`; huecos más viejos = gaps permanentes que visibiliza la alerta, no el sweep) y no existe fila `(juego, fecha, hora)`. Devuelve `Collection<int, {juego, horas[]}>`. Índice O(1) de persistidos (`juego_id|H:i`). |
| `backend/app/Console/Commands/ReconciliarSorteos.php` | Crear | `resultados:reconciliar` con flags `--grace=50 --window=180 --max-sources=20 --day-close --dry-run --force`. `--day-close` → gracia 0 + ventana día completo (1440 min). Agrupa faltantes por fuente (`ScraperSourceResolver::sourceOf`) y despacha `ScrapeSourceJob(key, fecha, null)` solo por fuente faltante, con cap `--max-sources` (20). `--dry-run` lista sin despachar. Fuera de producción no despacha sin `--force`. Día sano = 0 despachos (solo lecturas). |
| `backend/app/Providers/ScheduleServiceProvider.php` | Modificar | Registra `resultados:reconciliar` cada 15 min (`withoutOverlapping(10)`) y `resultados:reconciliar --day-close` a las 23:45 (`withoutOverlapping(30)`), solo consola (el provider ya retorna si no corre en consola). |
| `backend/tests/Feature/ReconciliarSorteosCommandTest.php` | Crear | 8 tests (`Bus::fake`, tiempo congelado `Carbon::setTestNow('2026-09-21 14:00')`): solo faltantes, respeta `--grace`/`--window`, `--day-close` (gracia 0 + día completo), `--max-sources` (cap), día sano = 0 despachos, `--dry-run` lista sin despachar, sin `--force` en no-producción no despacha. |
| `backend/tests/Feature/ScheduleTimeZoneTest.php` | Modificar | +2 tests: sweep `reconciliar_resultados` con expresión `*/15 * * * *` y cierre `reconciliar_resultados_cierre` con `45 23 * * *`. |
| `openspec/changes/resultados-parciales-produccion/tasks.md` | Modificar | Tasks 2.1–2.5 `[x]` (2.6 tras suite), anotación de la estimación "≈38" (21 juegos → 18 fuentes). |
| `openspec/changes/resultados-parciales-produccion/apply-progress.md` | Modificar | Este artefacto (merge slice 1 + slice 2). Corrección bookkeeping: GREEN 1.5 `34/35` → `18/19`. |

## TDD Cycle Evidence (Slice 2)

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| 2.1 | `tests/Feature/ReconciliarSorteosCommandTest.php` | Feature | N/A (nuevo) | ✅ 8/8 errores (`The command "resultados:reconciliar" does not exist`) | ✅ 8/8 (30 asserts) | ✅ 8 casos (solo faltantes, gracia, ventana, day-close, max-sources, día sano, dry-run, force) | ✅ fix Bus::fake acumulativo (fake fresco entre corridas); juegos de test con `type=tripletas` para fuente 1:1 |
| 2.2 | (mismo archivo; servicio) | Feature | N/A | ✅ (2.1 cubre) | ✅ 8/8 (30 asserts) | ➖ cubierto por 2.1 | ✅ Pint |
| 2.3 | (mismo archivo; comando) | Feature | N/A | ✅ (2.1 cubre) | ✅ 8/8 (30 asserts) | ➖ cubierto por 2.1 | ✅ Pint; `schedule:list` muestra ambos eventos |
| 2.4 | `tests/Feature/ScheduleTimeZoneTest.php` | Feature | ✅ 5/5 (previo) | ✅ 2 fallos (eventos no registrados) | ✅ 7/7 | ✅ 2 casos (cada 15 min, 23:45) | ➖ none |
| 2.5 | (mismo archivo) | Feature | N/A | ✅ (2.4 cubre) | ✅ 7/7 (16 asserts) | ➖ cubierto por 2.4 | ✅ Pint |
| 2.6 | suite completa | All | ✅ | — | ✅ 770 tests / 768 passed / 2 skipped pre-existentes / 0 fails | — | ✅ `pint --test` limpio |

## Evidencia de ejecución — Slice 2 (comandos + resultados)

- Safety net (ScheduleTimeZoneTest, previo a 2.4): `DB_DATABASE=lotto_test_slice2 php artisan test --filter=ScheduleTimeZoneTest` → 5/5 passed (12 asserts).
- RED 2.1: `php artisan test --filter=ReconciliarSorteosCommandTest` → 8 errores `The command "resultados:reconciliar" does not exist.`
- GREEN 2.2/2.3: mismo filtro → 8/8 passed (30 assertions).
- Regresión ampliada (resolver + job + controller + agenda + sweep): `php artisan test --filter='ReconciliarSorteosCommandTest|ScheduleTimeZoneTest|ScraperSourceResolverTest|ScrapeSourceJobTest|ResultadoControllerScrapeAllTest|ScrapeResultsJobTest'` → 44 tests, 43 passed, 1 skipped pre-existente.
- RED 2.4: `php artisan test --filter=ScheduleTimeZoneTest` → 5 passed / 2 failed (eventos sweep y cierre ausentes).
- GREEN 2.5: mismo filtro → 7/7 passed (16 assertions).
- Runtime harness: `php artisan list | grep reconciliar` → `resultados:reconciliar` registrado; `php artisan schedule:list` → `*/15 * * * * php artisan resultados:reconciliar` y `45 23 * * * php artisan resultados:reconciliar --day-close`.
- Runtime harness con datos reales (DB dev): `php artisan resultados:reconciliar --dry-run --force` → lista 15 fuentes con faltantes sin despachar; `--day-close` → "gracia 0 min, ventana 1440 min".
- **Suite completa (2.6)**: `DB_DATABASE=lotto_test_slice2 php -d memory_limit=1536M artisan test --display-warnings` → `passed, tests 770, passed 768, assertions 3776, skipped 2` (skips pre-existentes: ApuestaServiceTest condicional y PluginIntegrationTest), exit 0, ~20 min.
- **Pint**: `vendor/bin/pint --test` → passed (repo backend completo).

## Commits (Slice 2)

| SHA | Mensaje |
|---|---|
| `d0852fd` | `feat(backend): sweep de reconciliacion resultados:reconciliar con gracia, ventana, cap y day-close` (DrawReconciliationService + ReconciliarSorteos + ReconciliarSorteosCommandTest) |
| `757af23` | `feat(backend): agenda el sweep cada 15 min y el cierre de dia a las 23:45` (ScheduleServiceProvider + ScheduleTimeZoneTest) |
| (siguiente) | `docs(sdd): progreso de apply del slice 2 de resultados-parciales-produccion` (tasks + apply-progress) |

Nada fue pusheado ni mergeado; integración a main es user-gated tras verificación.

---

## Archivos cambiados (Slice 3)

| Archivo | Acción | Qué |
|---|---|---|
| `backend/entrypoint.sh` | Modificar | Guarda de migraciones/`optimize:clear` extendida: se omiten si `RUN_HORIZON=true` **O** `RUN_SCHEDULER=true` (solo la API migra — invariante del repo). Nueva rama: `RUN_SCHEDULER=true` → `exec php artisan schedule:work` (primer plano). |
| `docker-compose.prod.yml` | Modificar | Servicio `scheduler` (`lotto_scheduler_prod`, imagen `ghcr.io/jmxsdev/lotto-app-api:${IMAGE_TAG:-latest}`, `command: /usr/local/bin/entrypoint.sh`, env `RUN_SCHEDULER: "true"`, `env_file: .env.production`, `restart: unless-stopped`, `depends_on` mysql/redis healthy, red `lotto_net`, healthcheck `php artisan schedule:list`). Volumen `/home/deploy/monitoring/textfile:/var/lib/lotto-metrics:rw` montado en `scheduler` y en `api` (transición/rollback: el host cron corría `schedule:run` dentro de la API). |
| `docs/deploy.md` | Modificar | §0 tabla de servicios (fila `scheduler`); Fase 8 nota "solo la API migra (Horizon y scheduler las omiten)"; Fase 9 → 6 contenedores healthy; **§9.1** servicio scheduler (agenda congelada al arrancar → `docker restart lotto_scheduler_prod` tras sembrar horarios, mutex Redis, healthcheck, verificación); **§9.2** rollout desde la cron del host (quitar línea `schedule:run` conservando restic → `up -d` → verificar `crontab -l`/`docker ps`/`schedule:list`; rollback `docker rm -f` + re-agregar cron); §12.7 nota del volumen textfile (`/var/lib/lotto-metrics`, no borrar/chmod); §14.3 nota "la crontab solo contiene backups (restic), la agenda la maneja el scheduler". |
| `docs/runbook-ops.md` | Modificar | Stack actualizado (+`scheduler`); comandos de operación (+`docker logs -f lotto_scheduler_prod`); nueva sección "Scheduler de resultados (agenda por fuente)" con `schedule:list`, `docker restart` (agenda congelada) y rollback completo (`docker rm -f lotto_scheduler_prod` + re-agregar línea cron `schedule:run` conservando restic). |
| `docs/manual-mantenimiento.md` | Modificar (se versiona por 1ª vez) | §8.2 fila de logs del scheduler; §8.3 bullet "Agenda automática de resultados" (`docker exec lotto_scheduler_prod php artisan schedule:list`, reinicio tras sembrar horarios); Registro de verificación (entrypoint: "Horizon y scheduler las omiten"; compose: volumen textfile). |
| `openspec/changes/resultados-parciales-produccion/tasks.md` | Modificar | Tasks 3.1–3.3 `[x]`; 3.4 queda `[ ]` (rollout pendiente de usuario). |
| `openspec/changes/resultados-parciales-produccion/apply-progress.md` | Modificar | Este artefacto (merge slices 1–3). |

## Work Unit Evidence (Slice 3)

| Work unit | Focused test command y resultado | Runtime harness | Rollback boundary |
|---|---|---|---|
| Entrypoint (3.1) | `bash -n backend/entrypoint.sh` → OK; matriz de guarda (4 combos H/S + defaults): solo API migra, Horizon/scheduler/ambos omiten | N/A — el entrypoint corre dentro del contenedor de producción; el design (Threat Matrix) define su verificación en el rollout (3.4) | Revertir la rama `RUN_SCHEDULER` y la condición de la guarda en `entrypoint.sh` |
| Compose (3.2) | `docker compose -f docker-compose.prod.yml config --quiet` (con `.env.production` temporal vacío, eliminado después) → exit 0; render del servicio `scheduler` inspeccionado (depends_on healthy, healthcheck, volumen bind) | N/A — verificación real en rollout (3.4): `up -d` + `docker ps` + `schedule:list` | `docker rm -f lotto_scheduler_prod` (el `up -d` no borra huérfanos) + re-agregar línea cron; quitar volumen del compose |
| Docs (3.3) | `rg` sobre docs/ (worktree y main) por nombres stale `scrape_{slug}_{hora}` → **cero referencias** (no-op documentado; el naming nuevo solo vive en código); revisión manual de §9.1/§9.2/§8 | N/A — docs; el flujo se ejercita en el rollout | Revertir los commits de docs; el archivo `manual-mantenimiento.md` vuelve a estado untracked si se deshace el `git add -f` |
| Suite regresión | `DB_DATABASE=lotto_test_slice2 php -d memory_limit=1536M artisan test --display-warnings` → (resultado en §Evidencia) + `vendor/bin/pint --test` → passed | N/A | N/A |

## Commits (Slice 3)

| SHA | Mensaje |
|---|---|
| `566e61a` | `feat(infra): entrypoint con rama RUN_SCHEDULER para schedule:work y guarda de migraciones` |
| `9aabdad` | `feat(infra): servicio scheduler en docker-compose.prod.yml con healthcheck y volumen de metricas` |
| `790258d` | `docs(deploy): documenta servicio scheduler, volumen textfile y rollout desde la cron del host` |
| `157f826` | `docs(ops): runbook y manual de mantenimiento con operacion del scheduler` |
| (siguiente) | `docs(sdd): progreso de apply del slice 3 de resultados-parciales-produccion` (tasks + apply-progress) |

Nada fue pusheado ni mergeado; integración a main es user-gated tras verificación.

## Desviaciones del diseño (slice 3, documentadas)

8. **`docs/manual-mantenimiento.md` NO estaba versionado**: gitignore tiene `*.md` (`.gitignore:44`) y el archivo vivía solo como copia local untracked del checkout en `main` (fuente de verdad local del usuario). Se copió desde `/home/gzuz/Documentos/lotto-app/docs/manual-mantenimiento.md` al worktree y se versiona por primera vez con `git add -f` (mismo precedente que los `.md` de openspec/). **Implicación de integración**: al fusionar a main, git creará el archivo tracked; la copia untracked local del usuario chocará con el checkout → mover/borrar la copia local (el contenido versionado es idéntico al local salvo §8).
9. **El echo del scheduler no lleva emoji** (`echo "Iniciando scheduler (schedule:work)..."`): el resto del entrypoint usa emojis, pero la regla de artefactos prohíbe añadir emojis nuevos; se respetó la regla sobre la convención del archivo.
10. **La línea cron real del host no estaba versionada** (era justamente el drift que este cambio elimina): el paso 1 del rollout (§9.2/runbook) documenta la forma canónica `* * * * * docker exec lotto_api_prod php artisan schedule:run` con la nota explícita de conservar restic; el usuario debe ajustarla a su crontab real.
11. **Grep de nombres stale `scrape_{slug}_{hora}`**: sin referencias en `docs/` (ni worktree ni main) — la actualización de naming pedida por la tarea 3.3 resultó ser un no-op documentado; el cambio de naming vive solo en `ScheduleServiceProvider` (slice 1).

## Hallazgos (slice 3)

- **`main` avanzó tras la base del branch** (`d250c20`): el `docs/runbook-ops.md` del checkout main tiene la sección "Catálogo bundled" (PRs #11–14, taquilla) que el worktree NO tiene. Este slice editó la versión del worktree (base d250c20); en la integración, el merge 3-way de runbook-ops.md debe combinar ambas (secciones distintas, conflicto bajo esperado).
- `docker compose config` valida el `env_file` solo si el archivo existe: se validó con un `.env.production` temporal vacío (creado y eliminado; nunca se commiteó).
- `php artisan schedule:work` carga la agenda una vez al arrancar (confirmado con el design: "agenda congelada"); por eso todos los docs insisten en `docker restart lotto_scheduler_prod` tras sembrar horarios.
- `docs/manual-mantenimiento.md` del main referencia el cambio `fix-taquilla-produccion` como "en curso"; ese cambio ya está en main (PRs #11–14), pero la línea de estado se dejó intacta (fuera del alcance del slice 3).

## Evidencia de ejecución — Slice 3

- Sintaxis entrypoint: `bash -n backend/entrypoint.sh` → OK. Matriz de guarda simulada (4 combos + defaults) → solo `RUN_HORIZON=false RUN_SCHEDULER=false` (o unset) migra.
- Compose: `docker compose -f docker-compose.prod.yml config --quiet` → exit 0 (warnings solo por vars de secretos no seteadas en el shell); render de `scheduler` verificado (depends_on `service_healthy` para mysql y redis, healthcheck CMD-SHELL `schedule:list`, bind mount textfile).
- Docs: `rg -n -i "scrape_|schedule:run|dailyAt" docs/` (worktree) → sin referencias stale; revisión manual de las secciones nuevas.
- **Suite completa (regresión)**: `DB_DATABASE=lotto_test_slice2 php -d memory_limit=1536M artisan test --display-warnings` → **passed, tests 770, passed 768, assertions 3776, skipped 2** (skips pre-existentes: ApuestaServiceTest condicional y PluginIntegrationTest), exit 0, ~18.5 min (1.110.380 ms). `vendor/bin/pint --test` → passed.

## Desviaciones del diseño (slice 1, documentadas)

1. **`Http::fake` no aplica en este código**: `BaseScraper` (y `AnimalitosScraper::fetch`) usan Guzzle directo, no la facade `Http` de Laravel; `Http::fake()` solo intercepta la facade. Sustituto: doble `AnimalitosScraperFake` que corta SOLO `execute()` (fetch de red) y conserva parse + `saveResults` reales, inyectado vía binding del contenedor.
2. **`ScraperSource` en archivo propio** (`app/Services/ScraperSource.php`): PSR-4 exige una clase por archivo.
3. **Controller despacha async** (`ScrapeSourceJob::dispatch`, no `handle()`): el test de tarea exige "despacha 1 job por fuente" (verificable con `Bus::fake`). El path con `juego_id` sigue síncrono e intacto.
4. **`withoutOverlapping(5)` y `tries=1`** en `ScrapeSourceJob`: las pasadas +15/+30/+45 son el mecanismo de retry.

## Desviaciones del diseño (slice 2, documentadas)

5. **Semántica de `--force`**: el design lista el flag pero no define su comportamiento ("Sweep; `--force` en prod"). Interpretación implementada: fuera del entorno `production` el comando NO despacha (solo lista) a menos que se pase `--force`; en producción despacha por defecto. `--dry-run` nunca despacha. Documentado para que verify lo valide.
6. **Filtro de juegos en el servicio**: `missingByJuego` considera juegos `requires_scraper = true AND active = true` (alineado con `scrapeAll`, que filtra `active`; el resolver por sí solo no filtra `active`). Un juego desactivado no produce fetches del sweep.
7. **Ventana de día completo = 1440 min**: `--day-close` pasa `windowMin = 1440` (24 h), lo que cubre todo el día a cualquier hora ("sin cap de ventana" sin caso especial).

## Work Unit Evidence (por work unit)

| Work unit | Focused test command y resultado | Runtime harness | Rollback boundary |
|---|---|---|---|
| Sweep (2.1–2.3) | `php artisan test --filter=ReconciliarSorteosCommandTest` → 8/8 (30 asserts) | `php artisan resultados:reconciliar --dry-run --force` → lista 15 fuentes faltantes sin despachar; `schedule:list` muestra sweep y cierre | Revertir `DrawReconciliationService.php` + `ReconciliarSorteos.php` + test |
| Agenda (2.4–2.5) | `php artisan test --filter=ScheduleTimeZoneTest` → 7/7 (16 asserts) | `php artisan schedule:list` → `*/15 * * * * resultados:reconciliar` + `45 23 * * * resultados:reconciliar --day-close` | Revertir el bloque sweep/cierre en `ScheduleServiceProvider.php` + 2 tests |
| Suite (2.6) | `DB_DATABASE=lotto_test_slice2 php -d memory_limit=1536M artisan test --display-warnings` → 770/768 (2 skips pre-existentes), exit 0 + `pint --test` passed | N/A | N/A |

Rollback global slice 2: quitar `DrawReconciliationService`/`ReconciliarSorteos` + registros sweep/cierre; las pasadas (slice 1) permanecen.

## Hallazgos

- **Colisión de DB en máquina compartida**: el worktree `investigacion-produccion` corre suites contra `lotto_test` (mismo DB que este worktree en phpunit.xml). `RefreshDatabase` de dos procesos concurrentes sobre el mismo DB → carrera drop/migrate (tablas a medias). Resuelto: este worktree corre los tests con `DB_DATABASE=lotto_test_slice2` (creada + grant con root de `docker-compose.yml`). El otro worktree migró a `lotto_test_motor`. No se tocó `phpunit.xml` ni `.env` (override por env var).
- `Bus::fake()` acumula despachos entre corridas dentro del mismo test: al re-ejecutar el comando con flags distintos hay que volver a `Bus::fake()` para contar limpio.
- Juegos de test con `type=animalitos` + URL arbitraria resuelven a `AnimalitosScraper` (fallback por type existe) → se consolidan en `lottoactivo-animalitos`. Para fuentes 1:1 en tests, `type=tripletas` (clase existe vía convención, key = slug).
- `php artisan schedule:list` funciona con la tabla `juegos` presente y muestra las expresiones cron en hora local (Caracas), sin doble conversión.
- `mysql` CLI no está en PATH del host; `root` sí conecta con la password de `docker-compose.yml` (`root_dev_ed139e2c6c5942770a48a16a`); `lotto_user` no puede crear DBs.

## Siguiente paso

Slice 4: alertas (`resultados:metricas` + reglas Prometheus + docs; tareas 4.1–4.6). Antes, el usuario debe ejecutar el rollout del slice 3 (tarea 3.4) y la integración a main queda user-gated tras verify.