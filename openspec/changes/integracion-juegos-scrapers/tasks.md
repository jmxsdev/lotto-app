# Tasks: Integración de juegos + scrapers (incremental)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~6.000 (fundación ~500 + 14 juegos × ~400) |
| 800-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR1 fundación → PR2..15 (1 juego/PR) |
| Delivery strategy | auto-chain |
| Chain strategy | feature-branch-chain |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Fundación: catálogo + migración + resolver + fail-fast | PR 1 (base=tracker) | `composer test -- --filter=ScraperResolverTest` | `php artisan migrate` + `php artisan db:seed` | Revertir migración + restaurar resolveScraper legacy |
| 2..15 | Un juego c/u (#9–22) | PR 2..15 (base=PR anterior) | `composer test -- --filter=Juego<Xxx>` | `php artisan tinker` → fetch+parse con URL real | Eliminar seeder + clase + fixtures + fila juego |

## Phase 1: Fundación (PR 1)

- [x] 1.1 Migración `backend/database/migrations/*_add_scraper_class_to_juegos_table.php`: `string('scraper_class')->nullable()`; down `dropColumn`.
- [x] 1.2 `backend/app/Models/Juego.php`: `scraper_class` en `$fillable` y `$hidden` (no exponer en payload API).
- [x] 1.3 Catálogo `backend/docs/juegos.md`: 7 juegos actuales + hueco `#8` (nombre, slug, type, horarios, fuente, estado).
- [x] 1.4 `backend/app/Jobs/ScrapeResultsJob.php` `resolveScraper`: orden `scraper_class` → match URL (lottoactivo/triplezulia) → convención `{Studly(type)}Scraper`. Clase inexistente → warning + null.
- [x] 1.5 `instantiateScraper`: rama Animalitos pasa `$juego`; resto `new $class($juego)`.
- [x] 1.6 `backend/app/Plugins/Scrapers/BaseScraper.php`: hoistear `saveResults()` (upsert juego+fecha+hora), `findJuegoOrFail()` (slug→name→throw "juego no registrado; ejecuta su seeder"), `normalizeHora()` ("10:00 AM"|"H:i:s"→"H:i" America/Caracas).
- [x] 1.7 `AnimalitosScraper.php`: `findOrCreateJuego` → `findJuegoOrFail` (conserva mapa canónico slug); elimina `saveResults` local.
- [x] 1.8 `TripletasScraper.php`: constructor `__construct(?Juego $juego = null)` (productId desde `config['scraper']['product_id']` default '2'); migra a `findJuegoOrFail`; elimina `saveResults` local.
- [x] 1.9 RED→GREEN `backend/tests/Unit/ScraperResolverTest.php`: scraper_class gana; clase inexistente→null; `trio-activo`→AnimalitosScraper (orden URL→convención); convención por type.
- [x] 1.10 RED→GREEN fail-fast: nombre desconocido lanza `RuntimeException` y NO crea filas (`Juego::count()` invariante).

## Phase 2: Juegos #9–22 (1 work unit por juego, orden de URLs del cliente)

Plantilla por juego (RED→GREEN, TDD estricto). Juego 9 (Triple Caliente) completado
en el PR 2 de la cadena (rama `feat/integracion-juegos-scrapers-f1-triple-caliente`, base f0):

- [x] 9a. Seeder `TripleCalienteSeeder.php`: `firstOrCreate(['slug'])` + `scraper_class` + `JuegoLimite` (banca/bs/3600) + `PluginJuego` (Tripletas) + `JuegoOpcion*` (signos) + `JuegoHorario` 13:00/16:30/19:10; registrado en `DatabaseSeeder`.
- [x] 9b. Scraper `LoteriaDeHoyScraper.php` (parametrizado por juego, fetch+parse+constructor) para loteriadehoy.com.
- [x] 9c. Fixture real `backend/tests/Fixtures/loteriadehoy_triplecaliente.html`.
- [x] 9d. RED→GREEN `TripleCalienteScraperTest.php` (unit, 8) + `TripleCalienteResultsTest.php` (feature, 6). Comando: `composer test -- --filter=TripleCaliente` → 14/14.
- [x] 9e. Fila en `backend/docs/juegos.md` (mismo WU).
- [ ] 9f. Verificación funcional con URL real (`php artisan tinker` → fetch+parse) pendiente del cliente (datos reales).

Juego 10 (Cazaloton) completado en el PR 3 de la cadena (rama
`feat/integracion-juegos-scrapers-f2-cazaloton`, base f1-triple-caliente):

- [x] 10a. Seeder `CazalotonSeeder.php`: `firstOrCreate(['slug'])` + `scraper_class` (LoteriaDeHoyScraper) + `JuegoLimite` (banca/bs/3600) + `PluginJuego` (Animalitos) + `JuegoHorario` 09:00–19:00 (11); registrado en `DatabaseSeeder`.
- [x] 10b. Extensión `LoteriaDeHoyScraper.php` con modo animalitos (`div.js-con`, bloques número+animal+hora 12h) vía `parseAnimalitos`; mantiene intacto el modo tripletas (`parseTripletas`). Maneja resultados parciales.
- [x] 10c. Fixture real `backend/tests/Fixtures/loteriadehoy_cazaloton.html` (snapshot con 2 bloques).
- [x] 10d. RED→GREEN `CazalotonScraperTest.php` (unit, 7) + `CazalotonResultsTest.php` (feature, 6). Comando: `composer test -- --filter=Cazaloton` → 13/13. Conteos de `LimitesScopedApiTest` actualizados por el 9º juego.
- [x] 10e. Fila en `backend/docs/juegos.md` (mismo WU).
- [ ] 10f. Verificación funcional con URL real (`php artisan tinker` → fetch+parse) pendiente del cliente (datos reales).

Juego 11 (Triple Chance) completado en el PR 4 de la cadena (rama
`feat/integracion-juegos-scrapers-f3-triple-chance`, base f2-cazaloton):

- [x] 11a. Seeder `TripleChanceSeeder.php`: `firstOrCreate(['slug'])` + `scraper_class` (LoteriaDeHoyScraper) + `JuegoLimite` (banca/bs/3600) + `PluginJuego` (Tripletas) + `JuegoOpcion*` (signos) + `JuegoHorario` 09:00–19:00 (11); registrado en `DatabaseSeeder`.
- [x] 11b. Confirmado que `LoteriaDeHoyScraper::parseTripletas` ignora los bloques de hora sin resultado (filas con solo `<td>` de hora, `<5` celdas) — no genera resultado vacío ni error; confirmado con test (sin cambio de código).
- [x] 11c. Fixture real `backend/tests/Fixtures/loteriadehoy_triplechance.html` (snapshot con 2 bloques con resultado y 9 bloques de hora sin resultado).
- [x] 11d. RED→GREEN `TripleChanceScraperTest.php` (unit, 8) + `TripleChanceResultsTest.php` (feature, 6). Comando: `composer test -- --filter=TripleChance` → 14/14. Conteos de `LimitesScopedApiTest` actualizados por el 10º juego (9→10 juegos, 18→20, 36→40).
- [x] 11e. Fila en `backend/docs/juegos.md` (mismo WU).
- [ ] 11f. Verificación funcional con URL real (`php artisan tinker` → fetch+parse) pendiente del cliente (datos reales).

### Juego 12 (El Arrejuntado) — completado en PR 5 (rama f4-el-arrejuntado, base f3-triple-chance)
- [x] 12a. Seeder `ElArrejuntadoSeeder.php`: slug `el-arrejuntado`, type `tripletas`, `premio_multiplo` 30, `scraper_url` https://backend.serviciosintegradostriple7.com/api/v1/products/el-arrejuntao/results/, `scraper_class` ElArrejuntaoScraper, `requires_scraper` true, `JuegoLimite` banca/bs/3600, `PluginJuego` Tripletas, `JuegoOpcion` 12 signos, `JuegoHorario` 10:00/13:00/16:00/19:00/23:00 (5); registrado en `DatabaseSeeder`.
- [x] 12b. Scraper `ElArrejuntaoScraper.php` (extiende BaseScraper): fetch del endpoint JSON por fecha (`?date=`), parsea draws con `is_published=true`, normaliza `draw_time` 12h→H:i (`normalizeHora`), mapea las 6 modalidades a `numeros_ganadores` (array JSON flexible), `findJuegoOrFail` fail-fast, `saveResults` heredado (dedupe).
- [x] 12c. Fixture real `backend/tests/Fixtures/elarrejuntao_results.json` (snapshot del endpoint 2026-09-01: 1 draw publicado con 6 modalidades).
- [x] 12d. RED→GREEN `ElArrejuntadoScraperTest.php` (unit, 7) + `ElArrejuntadoResultsTest.php` (feature, 6) → `composer test -- --filter=Arrejuntado` 13/13. `LimitesScopedApiTest` conteos 10→11 juegos/20→22 límites+origen/40→44 scope, mixto 20→22.
- [x] 12e. Fila en `backend/docs/juegos.md` (juego 12, type tripletas, 5 horarios, fuente API) + nota de la estructura multi-modalidad.
- [ ] 12f. Verificación funcional con URL real pendiente del cliente (datos reales).

Plantilla para los juegos restantes (#13–22):

- [ ] a. Seeder `backend/database/seeders/<Xxx>Seeder.php`: `Juego::firstOrCreate(['slug'])` + `scraper_class` + `JuegoLimite` (banca/bs/3600) + `PluginJuego` (reusa clase por type) + `JuegoOpcion*` + `JuegoHorario` (`firstOrCreate(['juego_id','hora'])`); registrar en `DatabaseSeeder`.
- [ ] b. Scraper `backend/app/Plugins/Scrapers/<Xxx>Scraper.php` (solo fetch+parse+constructor) según fuente.
- [ ] c. Fixture real `backend/tests/Fixtures/<xxx>_*.{json,html}`.
- [ ] d. RED→GREEN `tests/Unit/Juego<Xxx>ScraperTest.php` (Reflection sobre parse) + `tests/Feature/Juego<Xxx>ResultsTest.php` (RefreshDatabase, saveResults+dedupe). Comando: `composer test -- --filter=Juego<Xxx>`.
- [ ] e. Fila en `backend/docs/juegos.md` (mismo WU).
- [ ] f. Verificación funcional con URL real (`php artisan tinker` → fetch+parse) antes del siguiente juego.

| # | Juego | slug | type (fuente) | Flag |
|---|-------|------|---------------|------|
| 9 | Triple Caliente | triple-caliente | tripletas (API productId) | ✅ integrado (PR 2) |
| 10 | Cazaloton | cazaloton | animalitos | ✅ integrado (PR 3) |
| 11 | Triple Chance | triple-chance | tripletas (API productId) | ✅ integrado (PR 4) |
| 12 | El Arrejuntado | el-arrejuntado | tripletas (API serviciosintegradostriple7) | ✅ integrado (PR 5) |
| 13 | El Guacharito | el-guacharito | según URL cliente | |
| 14 | Guacharo Activo | guacharo-activo | según URL cliente | |
| 15 | La Granjita | la-granjita | según URL cliente | |
| 16 | La Ricachona | la-ricachona | según URL cliente | |
| 17 | Loto Chaima | loto-chaima | según URL cliente | |
| 18 | Mega Animal 40 | mega-animal-40 | animalitos (lottoactivo) | |
| 19 | Selva Plus | selva-plus | según URL cliente | |
| 20 | Triple Tachira | triple-tachira | tripletas (API productId) | |
| 21 | Triple Facil | triple-facil (+terminal) | tripletas/terminales | **CONDICIONAL** (doble) |
| 22 | Triple Zamorano | triple-zamorano | tripletas (API productId) | |

**Condicionales**: `#21 Triple Facil` — decisión cliente (D8: dos juegos `triple-facil`/`triple-facil-terminal` + un `TripleFacilScraper` que emite ambas y filtra por `config['scraper']['modalidad']`). `#8` hueco — confirmar al integrar juego 9. Cada tarea `b` debe indicar al apply qué información pedir al cliente (URL + estructura + productId + type).

## Phase 3: Verificación / cierre

- [ ] 3.1 Suite general completa al integrar 10 juegos (criterio cliente), documentado en `docs/juegos.md`.
- [ ] 3.2 `vendor/bin/pint --test` (CI) limpio.
- [ ] 3.3 Confirmar `panel/` y contratos API intactos.

## Rollback por unidad

- Juego: eliminar seeder + clase scraper + fixtures + fila `juegos`; sin afectar otros juegos.
- Fundación: `php artisan migrate:rollback` (drop column) restaura resolver legacy.
