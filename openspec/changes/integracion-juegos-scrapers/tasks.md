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

### Juego 9 — Migración a fuente oficial (PR 8, rama f7-tc-oficial, base f6-guacharo-activo) — ✅ COMPLETADO
- [x] 9g. Scraper `TripleCalienteOficialScraper.php` (extiende BaseScraper): POST al API oficial `https://triplecaliente.com/api/gaming/results/product` con `game_product_id` (constante `'4'` con override vía `config['scraper']['product_id']` del juego), parse de sorteos con fecha/hora local America/Caracas desde `event_timestamp.seconds`, A/B/C+signo al esquema tripletas, `sorteo_id_externo` = primer `event`, `findJuegoOrFail` fail-fast, y `execute` filtra el histórico por fecha (patrón `TripletasScraper`, misma familia de API).
- [x] 9h. Seeder `TripleCalienteSeeder.php` migrado: `updateOrCreate` (aplica el cambio sobre el juego ya registrado) con `scraper_url` = API oficial y `scraper_class` = TripleCalienteOficialScraper; horarios 13:00/16:30/19:10 y límites/plugin/signos intactos. `LoteriaDeHoyScraper` NO se borra (queda para Cazaloton/Triple Chance/El Guacharito/Guacharo Activo y respaldo).
- [x] 9i. Fixture real `backend/tests/Fixtures/triplecaliente_oficial.json` (snapshot del API oficial: 6 sorteos reales, 2 días × 3 horarios).
- [x] 9j. RED→GREEN `TripleCalienteOficialScraperTest.php` (unit, 11: parse, epoch→Caracas, A/B/C+signo, dedupe por fecha, fail-fast, product_id desde config, JSON inválido/vacío) + `TripleCalienteResultsTest.php` actualizado (6 feature: nueva fuente, 3 sorteos persistidos, dedupe, resolver). `composer test -- --filter=TripleCaliente` → 25/25. Suite completa 486/484/2 + pint limpio.
- [x] 9k. Fila en `backend/docs/juegos.md` (juego 9: fuente API oficial, estado "verificado con datos reales") + nota del scraper.
- [x] 9l. CARGA REAL EN BD LOCAL: `php artisan db:seed --class=TripleCalienteSeeder --force` actualiza el juego; scraper contra el API real persiste **3 sorteos** del 2026-09-01 (13:00/16:30/19:10) en `resultados`; rescrape verifica dedupe (sigue en 3). HOY 02-09 aún sin sorteos (primer sorteo 13:00, ejecución 12:16 Caracas — resultados parciales correctos).

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

### Juego 13 (El Guacharito Millonario) — completado en PR 6 (rama f5-el-guacharito, base f4-el-arrejuntado)
- [x] 13a. Seeder `ElGuacharitoSeeder.php`: slug `el-guacharito`, type `animalitos`, `premio_multiplo` 30, `scraper_url` https://loteriadehoy.com/animalito/elguacharitomillonario/resultados/, `scraper_class` LoteriaDeHoyScraper, `requires_scraper` true, `JuegoLimite` banca/bs/3600, `PluginJuego` Animalitos, `JuegoHorario` 08:30–19:30 (:30 cada hora, 12); registrado en `DatabaseSeeder`.
- [x] 13b. Reutiliza `LoteriaDeHoyScraper::parseAnimalitos` (mismo patrón Cazaloton: bloques número+animal+hora 12h→H:i, resultados parciales) — sin cambio de código.
- [x] 13c. Fixture real `backend/tests/Fixtures/loteriadehoy_elguacharito.html` (snapshot del 2026-09-01 con 4 bloques: 08:30–11:30).
- [x] 13d. RED→GREEN `ElGuacharitoScraperTest.php` (unit, 7) + `ElGuacharitoResultsTest.php` (feature, 6) → `composer test -- --filter=Guacharito` 13/13. `LimitesScopedApiTest` conteos 11→12 juegos/22→24 límites+origen/44→48 scope, mixto 22→24.
- [x] 13e. Fila en `backend/docs/juegos.md` (juego 13, type animalitos, 12 horarios, fuente loteriadehoy) + removido de pendientes.
- [ ] 13f. Verificación funcional con URL real pendiente del cliente (datos reales).

### Juego 14 (Guacharo Activo) — completado en PR 7 (rama f6-guacharo-activo, base f5-el-guacharito)
- [x] 14a. Seeder `GuacharoActivoSeeder.php`: slug `guacharo-activo`, type `animalitos`, `premio_multiplo` 30, `scraper_url` https://loteriadehoy.com/animalito/guacharoactivo/resultados/, `scraper_class` LoteriaDeHoyScraper, `requires_scraper` true, `JuegoLimite` banca/bs/3600, `PluginJuego` Animalitos, `JuegoHorario` 08:00–19:00 (:00 cada hora, 12); registrado en `DatabaseSeeder`.
- [x] 14b. Reutiliza `LoteriaDeHoyScraper::parseAnimalitos` (mismo patrón Cazaloton/El Guacharito: bloques número+animal+hora 12h→H:i, resultados parciales) — sin cambio de código.
- [x] 14c. Fixture real `backend/tests/Fixtures/loteriadehoy_guacharo.html` (snapshot del 2026-09-01 con 5 bloques: 08:00–12:00).
- [x] 14d. RED→GREEN `GuacharoScraperTest.php` (unit, 7) + `GuacharoResultsTest.php` (feature, 6) → `composer test -- --filter=Guacharo` 13/13. `LimitesScopedApiTest` conteos 12→13 juegos/24→26 límites+origen/48→52 scope, mixto 24→26.
- [x] 14e. Fila en `backend/docs/juegos.md` (juego 14, type animalitos, 12 horarios, fuente loteriadehoy) + removido de pendientes.
- [ ] 14f. Verificación funcional con URL real pendiente del cliente (datos reales).

### WU f10 — La Granjita con API oficial (PR 11, rama f10-la-granjita, base f9-catalogo-json) — ✅ COMPLETADO
- [x] f10.1 Scraper `LaGranjitaScraper.php` (extiende BaseScraper): GET `https://www.lagranjita.com/api/results.json?date=YYYY-MM-DD&productId=1` (sin auth ni anti-bot; soporta fechas pasadas), parse con `product_id` constante '1' y override `config['scraper']['product_id']`, clave del objeto = nombre del producto tomando el PRIMER valor (no hardcodeada), skip de sorteos no ocurridos (`result_id: null`), numero/animal desde `result_value`/`result_name`, hora 12h→H:i (`normalizeHora`), `sorteo_id_externo = result_id`, `findJuegoOrFail` fail-fast, `saveResults` heredado (dedupe). `execute($fecha)` carga la fecha pedida (el API soporta fechas, sin filtrar). Maneja JSON inválido/vacío y respuesta sin la estructura esperada.
- [x] f10.2 Seeder `LaGranjitaSeeder.php`: slug `la-granjita`, name "La Granjita", type `animalitos`, `premio_multiplo` 30, `scraper_url` `https://www.lagranjita.com/api/results.json?productId=1`, `scraper_class` LaGranjitaScraper, `requires_scraper` true, JuegoLimite banca/bs/3600, PluginJuego Animalitos, JuegoHorario 08:00–19:00 (12); registrado en `DatabaseSeeder` (14º juego).
- [x] f10.3 Fixture real `backend/tests/Fixtures/lagranjita_results.json` (día completo 2026-09-11, 12 sorteos) + `lagranjita_parcial.json` (2026-09-12: 4 sorteos + 8 nulls). Snapshots literales del API (captura documentada en el docblock del test).
- [x] f10.4 RED→GREEN `LaGranjitaScraperTest.php` (unit, 12: parse día completo, horas H:i, numero/animal incl. DELFIN=0, skip nulls, result_id como externo, estructura, fail-fast, product_id config, JSON inválido, respuesta vacía/sin estructura, clave distinta) + `LaGranjitaResultsTest.php` (feature, 6: seeder, límite+plugin, 12 horarios, persistencia 12, dedupe, resolver). `composer test -- --filter=LaGranjita` → 18/18.
- [x] f10.5 Regresión: `JuegosJsonTest` 13→14 juegos (SLUGS_POR_ID + la-granjita en animalitos sin tabla 38 opciones) y `LimitesScopedApiTest` 13→14 juegos/26→28 límites+origen/52→56 scope/mixto 26→28.
- [x] f10.6 REGENERADO `docs/juegos.json` con `php artisan juegos:export` (14 juegos, id 14 = la-granjita, 38 opciones vía plugin Animalitos, horarios 08:00–19:00) y COMMITEADO; determinista (2 ejecuciones = mismo md5).
- [x] f10.7 CARGA REAL: `ScrapeResultsJob` contra el API real → HOY 2026-09-12: 4 resultados parciales (08:00 GALLINA 25, 09:00 RATON 8, 10:00 MONO 13, 11:00 LAPA 31); AYER 2026-09-11: 12 resultados (día completo). Rescrape idempotente (4/12, 16 únicos, 0 errores). BD local total 128.
- [x] f10.8 Docs: fila 15 en `backend/docs/juegos.md` (type animalitos, 12 horarios, fuente API oficial, estado "verificado con datos reales 12-sep") + nota del scraper y de la plataforma (productId, otros productos del portal). tasks.md + apply-progress (merge) + commits work-unit en español. NO se abren PRs.

Plantilla para los juegos restantes (#16–22):

- [ ] a. Seeder `backend/database/seeders/<Xxx>Seeder.php`: `Juego::firstOrCreate(['slug'])` + `scraper_class` + `JuegoLimite` (banca/bs/3600) + `PluginJuego` (reusa clase por type) + `JuegoOpcion*` + `JuegoHorario` (`firstOrCreate(['juego_id','hora'])`); registrar en `DatabaseSeeder`.
- [ ] b. Scraper `backend/app/Plugins/Scrapers/<Xxx>Scraper.php` (solo fetch+parse+constructor) según fuente.
- [ ] c. Fixture real `backend/tests/Fixtures/<xxx>_*.{json,html}`.
- [ ] d. RED→GREEN `tests/Unit/Juego<Xxx>ScraperTest.php` (Reflection sobre parse) + `tests/Feature/Juego<Xxx>ResultsTest.php` (RefreshDatabase, saveResults+dedupe). Comando: `composer test -- --filter=Juego<Xxx>`.
- [ ] e. Fila en `backend/docs/juegos.md` (mismo WU).
- [ ] f. Verificación funcional con URL real (`php artisan tinker` → fetch+parse) antes del siguiente juego.

| # | Juego | slug | type (fuente) | Flag |
|---|-------|------|---------------|------|
| 9 | Triple Caliente | triple-caliente | tripletas (API oficial productId) | ✅ integrado (PR 2) + fuente oficial (PR 8) |
| 10 | Cazaloton | cazaloton | animalitos | ✅ integrado (PR 3) |
| 11 | Triple Chance | triple-chance | tripletas (API productId) | ✅ integrado (PR 4) |
| 12 | El Arrejuntado | el-arrejuntado | tripletas (API serviciosintegradostriple7) | ✅ integrado (PR 5) |
| 13 | El Guacharito | el-guacharito | animalitos (loteriadehoy) | ✅ integrado (PR 6) |
| 14 | Guacharo Activo | guacharo-activo | animalitos (loteriadehoy) | ✅ integrado (PR 7) |
| 15 | La Granjita | la-granjita | animalitos (API oficial lagranjita.com) | ✅ integrado (PR 11) |
| 16 | La Ricachona | la-ricachona | según URL cliente | |
| 17 | Loto Chaima | loto-chaima | según URL cliente | |
| 18 | Mega Animal 40 | mega-animal-40 | animalitos (lottoactivo) | |
| 19 | Selva Plus | selva-plus | según URL cliente | |
| 20 | Triple Tachira | triple-tachira | tripletas (API productId) | |
| 21 | Triple Facil | triple-facil (+terminal) | tripletas/terminales | **CONDICIONAL** (doble) |
| 22 | Triple Zamorano | triple-zamorano | tripletas (API productId) | |

**Condicionales**: `#21 Triple Facil` — decisión cliente (D8: dos juegos `triple-facil`/`triple-facil-terminal` + un `TripleFacilScraper` que emite ambas y filtra por `config['scraper']['modalidad']`). `#8` hueco — confirmar al integrar juego 9. Cada tarea `b` debe indicar al apply qué información pedir al cliente (URL + estructura + productId + type).

### WU f8 — Familia Lotto Activo: estabilización y datos reales (PR 9, rama f8-lottoactivo, base f7-tc-oficial) — ✅ COMPLETADO
- [x] f8.1 Auditoría de BD local `resultados`: listar filas por juego con created_at clasificando demo vs real; imprimir evidencia ANTES de borrar.
- [x] f8.2 Eliminar SOLO las 32 filas demo (lotto-activo #1–18, triple-zulia #19–29, terminal-activo #30–32, created_at 2026-09-02 10:06:38/39); reportar otras sospechosas con evidencia (no había).
- [x] f8.3 Re-auditar conteos por juego post-limpieza: 85 filas, 100 % origen scraper, 0 residuales demo.
- [x] f8.4 Documentar seeders que generan filas demo en `resultados` (`ResultadoTestSeeder` crea — NO registrado en DatabaseSeeder, referencia slug `animalitos` inexistente; `TicketsGanadoresDemoSeeder`/`ApuestaGanadoraSeeder` solo leen — NO registrados). Sin borrarlos.
- [x] f8.5 Verificación en vivo + recarga real 2026-09-12: `ScrapeResultsJob` por juego (13 juegos, secuencial) → 27 resultados reales, 0 errores; dedupe idempotente (total BD 112).
- [x] f8.6 Cobertura TDD rutas `terminal_activo`/`trio_activo`/monje/RD de `AnimalitosScraper` con fixtures REALES (`lottoactivo_*`): +5 tests → `composer test -- --filter=AnimalitosScraperTest` 10/10; suite completa 491/489/2; pint limpio.
- [x] f8.7 Docs: `backend/docs/juegos.md` — familia lottoactivo "✅ Verificado con datos reales (12-sep)" + nota feed anidado/formato plano/mapeo slugs.
- [x] f8.8 tasks.md + apply-progress (sección "Familia Lotto Activo — estabilización") + commits work-unit en español. NO se abren PRs. Catálogo JSON = WU f9 (fuera de alcance).

### WU f9 — Catálogo JSON para el front/taquilla (PR 10, rama f9-catalogo-json, base f8-lottoactivo) — ✅ COMPLETADO
- [x] f9.1 RED→GREEN `JuegosExportCommand` (`php artisan juegos:export`) + servicio reutilizable `App\Services\JuegoCatalogoService` (comando y test comparten la lógica; el controller NO se toca — su payload de opciones es el modelo completo y cambiarlo rompería el contrato API). Semántica de opciones idéntica a `JuegoController::opciones` (tabla `juego_opciones` → fallback plugin).
- [x] f9.2 RED→GREEN `backend/tests/Feature/JuegosJsonTest.php` (3 tests, 309 assertions): consistencia con el archivo commiteado (comparación estable por slug y valores, sin id), esquema mínimo (version=1, 13 juegos, campos, horarios H:i ordenados, premio_multiplo) y conteos por tipo (lotto-activo 38, terminal-activo 100, tripletas con tabla 12, animalitos sin tabla 38 vía plugin, trio-activo 12 vía plugin).
- [x] f9.3 Generado `docs/juegos.json` (raíz del repo, contrato front) con el comando contra la BD local real y COMMITEADO; pretty-print `JSON_UNESCAPED_UNICODE|UNESCAPED_SLASHES|PRETTY_PRINT` + newline final; determinista e idempotente (2 ejecuciones = mismo md5).
- [x] f9.4 Harness runtime: `php artisan juegos:export` contra BD local (13 juegos, ids 1-13) → `docs/juegos.json` (68.788 B) + `--path` opcional verificado con salida idéntica. Suite completa **494/492/2** (491/489/2 +3) + `pint --test` limpio.
- [x] f9.5 Docs: nota en `backend/docs/juegos.md` (contrato JSON + regeneración), tasks.md + apply-progress (merge) + Engram `apply-progress-f9`. Commits work-unit en español. NO se abren PRs.
- [x] f9.6 INVESTIGACIÓN ids test vs archivo (documentada): la BD de tests comparte auto-increment de MySQL que NO retrocede con el rollback de RefreshDatabase → ids corridos (1-13 en test limpio, 14-26, 27-39 según posición en la suite). Resuelto sin romper el contrato: el archivo mantiene ids 1-13 (BD real); el test compara por slug (estructura+valores, id excluido) y verifica orden relativo + consecutividad del generado. Discrepancia de conteo: el plugin Animalitos tiene 38 animales (ballena+delfin comparten numero 0), no 37 como se estimó — el archivo refleja el plugin (38) y el test lo valida.

## Phase 3: Verificación / cierre

- [ ] 3.1 Suite general completa al integrar 10 juegos (criterio cliente), documentado en `docs/juegos.md`.
- [ ] 3.2 `vendor/bin/pint --test` (CI) limpio.
- [ ] 3.3 Confirmar `panel/` y contratos API intactos.

## Rollback por unidad

- Juego: eliminar seeder + clase scraper + fixtures + fila `juegos`; sin afectar otros juegos.
- Fundación: `php artisan migrate:rollback` (drop column) restaura resolver legacy.
