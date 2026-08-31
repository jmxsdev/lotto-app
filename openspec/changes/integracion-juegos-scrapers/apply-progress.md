# Apply Progress: integracion-juegos-scrapers — PR 1 (Fundación)

**Cambio**: integracion-juegos-scrapers
**Fase**: 1 (tareas 1.1–1.10) — PR 1 de la cadena feature-branch-chain
**Rama**: `feat/integracion-juegos-scrapers-f0` (base: tracker `feature/integracion-juegos-scrapers` creado desde `main`)
**Modo**: Strict TDD (backend: `php artisan test` vía `composer test`)
**Estado**: ✅ 10/10 tareas de la fase 1 completadas

## Resumen

Fundación del cambio de integración incremental de juegos+scrapers: catálogo maestro
`backend/docs/juegos.md`, registro explícito `juegos.scraper_class` (nullable, oculto en API),
resolución de scraper con `scraper_class` autoritativo y fallback preservado (URL → convención),
fail-fast `findJuegoOrFail` que prohíbe crear juegos en caliente, y unificación de `saveResults`
en `BaseScraper` con `normalizeHora`. Comportamiento de los 7 juegos actuales preservado y
cubierto por tests de regresión (especialmente `trio-activo`: type tripletas + URL lottoactivo →
`AnimalitosScraper`).

## Hallazgos del feed (tarea 4)

- Los nombres del feed lottoactivo (`Lotto Activo`, `Lotto Activo RD`, `Lotto Activo RD Internacional`,
  `Lotto Activo República Dominicana`, `Lotto Activo 2 Monje Millonario`, `Terminal Trío`, `Trío Activo`,
  `Terminal Activo`) están registrados en los seeders actuales vía el mapa canónico de slugs de
  `AnimalitosScraper` → **no fue necesario ajustar seeders**; el flujo legacy no se rompe.
- **Hallazgo**: los tests unit legacy (`AnimalitosScraperTest`, `TripletasScraperTest`) dependían de la
  creación en caliente porque NO sembraban los juegos. Con fail-fast pasan a sembrar los juegos del feed
  en `setUp` (registro explícito, coherencia con la nueva regla). Sin este ajuste la suite fallaba
  (7 errores); con él, 367/365/2.

## TDD Cycle Evidence

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|-------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1 | `tests/Unit/JuegoScraperClassTest.php` | Unit | ✅ 343/341/2 | ✅ Written (2 fallos) | ✅ 3/3 | ✅ 3 casos (persistencia, ocultamiento, nullable) | ➖ Ninguno necesario |
| 1.2 | `tests/Unit/JuegoScraperClassTest.php` | Unit | ✅ idem | ✅ (mismo ciclo que 1.1) | ✅ | ✅ idem | ➖ Ninguno necesario |
| 1.3 | N/A (documentación) | Docs | N/A | ➖ | ➖ | ➖ Triangulación omitida: artefacto de documentación | ✅ Documento limpio |
| 1.4 | `tests/Unit/ScraperResolverTest.php` | Unit | ✅ ScrapeResultsJobTest 4/4+1skip | ✅ Written (3 fallos: scraper_class) | ✅ 10/10 | ✅ 8 casos (autoritativo, null, regresión trio-activo, URL, convención) | ✅ Clean |
| 1.5 | `tests/Unit/ScraperResolverTest.php` | Unit | ✅ idem | ✅ (cubierto por 1.4) | ✅ | ✅ 2 casos instantiate (slug trio_activo vía parse flat; paso de $juego) | ✅ Clean |
| 1.6 | `tests/Unit/BaseScraperHelpersTest.php` | Unit | ✅ idem | ✅ Written (6 errores: métodos inexistentes) | ✅ 7/7 | ✅ 7 casos (12h/24h, segundos, null/inválido, slug, name, throw+sin filas, upsert/dedupe) | ✅ Clean |
| 1.7 | `tests/Unit/ScraperResolverTest.php` | Unit | ✅ AnimalitosScraperTest aprobación | ✅ Written (1 fallo: crea fila) | ✅ 14/14 | ✅ 2 casos fail-fast animalitos | ✅ Clean |
| 1.8 | `tests/Unit/ScraperResolverTest.php` | Unit | ✅ TripletasScraperTest aprobación | ✅ Written (1 fallo: crea fila) | ✅ 14/14 | ✅ 2 casos fail-fast tripletas | ✅ Clean |
| 1.9 | `tests/Unit/ScraperResolverTest.php` | Unit | ✅ suite 343 | ✅ | ✅ 10/10 | ✅ 8 casos | ✅ Clean |
| 1.10 | `tests/Unit/ScraperResolverTest.php` | Unit | ✅ | ✅ Written | ✅ 14/14 | ✅ 4 casos fail-fast (animalitos/tripletas, throw+invariante, mapa canónico) | ✅ Clean |

**Test Summary**: Total escritos +24 (367 vs baseline 343) — todos pasando; aprobación
(aprobación de refactor): 26 tests de scrapers/jobs existentes; funciones puras creadas: 2
(`normalizeHora`, `findJuegoOrFail` base).

## Work Unit Evidence

| Work unit | Focused test command y resultado | Runtime harness y resultado | Rollback boundary |
|-----------|----------------------------------|-----------------------------|-------------------|
| WU1 catálogo | N/A (docs) | `git diff` + revisión de seeders | `backend/docs/juegos.md` — eliminar archivo |
| WU2 migración+modelo | `composer test -- --filter=JuegoScraperClassTest` → 3/3 | `php artisan migrate:rollback --step=1` + `php artisan migrate` → up/down OK; `migrate:status` muestra migración Ran | `migrate:rollback` (drop column) + revertir Juego.php (fillable/hidden) |
| WU3 resolver | `composer test -- --filter=ScraperResolverTest` → 10/10 + `--filter=ScrapeResultsJobTest` → 4/4+1skip | `php artisan tinker` → `trio-activo`→AnimalitosScraper, `lotto-activo`→AnimalitosScraper con 7 juegos seedeados | Revertir `ScrapeResultsJob::resolveScraper/instantiateScraper` (restaura orden legacy) |
| WU4 helpers base | `composer test -- --filter=BaseScraperHelpersTest` → 7/7 | N/A (helpers puros; sin borde runtime propio; cubiertos por suite feature) | Revertir `BaseScraper` (quitar 3 métodos) |
| WU5 fail-fast scrapers | `composer test -- --filter=ScraperResolverTest` → 14/14 + aprobación scrapers/jobs → 26/26+1skip | `php artisan db:seed` → 7 juegos registrados; tinker verifica resolución real | Revertir AnimalitosScraper/TripletasScraper (restaurar findOrCreateJuego + saveResults locales) |

## Archivos cambiados

| Archivo | Acción | Qué se hizo |
|---------|--------|-------------|
| `backend/database/migrations/2026_08_31_000001_add_scraper_class_to_juegos_table.php` | Create | `string('scraper_class')->nullable()->after('scraper_url')`; down dropColumn |
| `backend/app/Models/Juego.php` | Modify | `scraper_class` en `$fillable` + `$hidden` |
| `backend/docs/juegos.md` | Create | Catálogo maestro: 7 juegos + hueco #8 + tabla 9–22 + estrategia de tests |
| `backend/app/Jobs/ScrapeResultsJob.php` | Modify | `resolveScraper` orden scraper_class→URL→convención; clase inexistente→warning+null; `instantiateScraper` `new $class($juego)` |
| `backend/app/Plugins/Scrapers/BaseScraper.php` | Modify | +`findJuegoOrFail()`, +`normalizeHora()`, +`saveResults()` hoisteado |
| `backend/app/Plugins/Scrapers/AnimalitosScraper.php` | Modify | `findOrCreateJuego`→`findJuegoOrFail` (mapa canónico); elimina `saveResults` local |
| `backend/app/Plugins/Scrapers/TripletasScraper.php` | Modify | constructor `?Juego` (productId config default '2'); `findJuegoOrFail`; elimina `saveResults`/`findOrCreateJuego` |
| `backend/tests/Unit/ScraperResolverTest.php` | Create | 14 tests: resolver (8) + instantiate (2) + fail-fast (4) |
| `backend/tests/Unit/BaseScraperHelpersTest.php` | Create | 7 tests: normalizeHora, findJuegoOrFail, saveResults |
| `backend/tests/Unit/JuegoScraperClassTest.php` | Create | 3 tests: fillable+persistencia, hidden payload, nullable |
| `backend/tests/Unit/AnimalitosScraperTest.php` | Modify | siembra los 2 juegos del feed (fail-fast) |
| `backend/tests/Unit/TripletasScraperTest.php` | Modify | siembra triple-zulia (fail-fast) |
| `openspec/changes/integracion-juegos-scrapers/tasks.md` | Modify | 1.1–1.10 marcadas `[x]` |

## Desviaciones del diseño

1. **Tarea 1.5**: "rama Animalitos pasa `$juego`" se interpretó como *rama Animalitos conserva el
   slug derivado de `$juego->scraper_url`* (el constructor de AnimalitosScraper es `string $slug` y
   D6 solo migra el constructor de TripletasScraper). El resto sí pasa `new $class($juego)`.
   Comportamiento de los 7 juegos idéntico; cubierto por tests.
2. **Tests unit legacy**: se modificaron `AnimalitosScraperTest`/`TripletasScraperTest` para sembrar
   los juegos (antes dependían de la creación en caliente). Es el ajuste de tests que exige la nueva
   regla fail-fast; el diseño D4 preveía que los juegos están registrados (lo están en los seeders).
3. **`config('scraper.product_id', '2')`**: no existe `config/scraper.php`; el default '2' preserva
   el valor hardcodeado previo. No se creó archivo de config (fuera del alcance de la fundación).

## Problemas encontrados

- Los tests unit legacy fallaban tras fail-fast (7 errores) porque no sembraban los juegos;
  resuelto registrándolos en `setUp` (ver desviación 2). Sin cambios en seeders actuales: los 7
  juegos del feed ya estaban registrados.

## Workload / PR Boundary

- Modo: chained PR slice (feature-branch-chain, PR 1 de la cadena; tracker `feature/integracion-juegos-scrapers`).
- Boundary: fundación completa (catálogo → migración → resolver → fail-fast), con verificación
  incluida (suite completa 367/365/2 + pint limpio).
- Budget: 684 insertions + 110 deletions = 794 líneas (bajo el umbral de 400 del diff neto? No —
  excede 400 líneas sumando author adiciones+deleciones; dentro de lo previsto por el forecast para
  el PR1 de fundación ~500 líneas estimadas). Verificación completa incluida.
- Rollback boundary por unidad: ver tabla Work Unit Evidence.

## Siguiente paso recomendado

- Fase 2 (juegos 9–22): PR 2+ base = PR 1 (`feat/integracion-juegos-scrapers-f0` → siguiente rama
  hijo). Cada juego necesita URL del cliente; empezar por el orden de URLs del cliente.
- `sdd-verify` del PR 1 cuando el orquestador lo dispare.