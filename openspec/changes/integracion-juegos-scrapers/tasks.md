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

Plantilla para los juegos restantes (#18–22):

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
| 16 | La Ricachona | la-ricachona | tripletas (HTML oficial laricachona.com) | ✅ integrado (PR 12) |
| 17 | Loto Chaima | loto-chaima | animalitos (API oficial lotterly.co) | ✅ integrado (PR 13) |
| 18 | Mega Animal 40 | mega-animal-40 | animalitos (resultadosvenezuela.com) | ✅ integrado (WU f14) |
| 19 | Selva Plus | selva-plus | animalitos (API oficial lotterly.co) | ✅ integrado (WU f16) |
| 20 | Triple Tachira | triple-tachira | tripletas (sitio oficial tripletachira.com) | ✅ integrado (WU f18) |
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

### WU f12 — La Ricachona versión triples (PR 12, rama f12-la-ricachona, base f11-docs-plataformas) — ✅ COMPLETADO
- [x] f12.1 Rama `feat/integracion-juegos-scrapers-f12-la-ricachona` creada desde f11. Scraper `LaRicachonaScraper` (extiende BaseScraper): `fetch` construye `https://laricachona.com/?date=<fecha>`, parse de `article.tripleResultArticle` (hora del `<h1>` con `normalizeHora`, número del `<p>` del MEDIO con ceros a la izquierda como STRING, saltar `--`/`---`), `findJuegoOrFail` fail-fast, `saveResults` heredado. Maneja HTML sin artículos, HTML de error y respuestas vacías (RuntimeException).
- [x] f12.2 Seeder `LaRicachonaSeeder`: slug `la-ricachona`, name "La Ricachona", type `tripletas`, `premio_multiplo` 30, `modalidades_permitidas: ["triple_a"]`, `scraper_url` = `https://laricachona.com/`, `scraper_class` = LaRicachonaScraper, `requires_scraper` true, JuegoLimite banca/bs/3600, PluginJuego Tripletas, JuegoHorario 08:05–19:05 (12, cada hora `:05`). Registrado en `DatabaseSeeder`.
- [x] f12.3 Fixtures reales `backend/tests/Fixtures/laricachona_results.html` (día completo 2026-09-11, 12 sorteos) + `laricachona_parcial.html` (hoy con `--`/`---`).
- [x] f12.4 RED→GREEN `LaRicachonaScraperTest.php` (unit, 9) + `LaRicachonaResultsTest.php` (feature, 6) → `composer test -- --filter=Ricachona` 15/15 (47 assertions). `JuegosJsonTest` (15 juegos + conteos) y `LimitesScopedApiTest` (15/30/60, mixto 30) actualizados → 3/3 y 30/30.
- [x] f12.5 REGENERADO `docs/juegos.json` con `php artisan juegos:export` (15 juegos, id 15 = la-ricachona, 12 opciones vía plugin Tripletas, horarios 08:05–19:05) y COMMITEADO; determinista (2 ejecuciones = mismo md5 4cb93cd6...).
- [x] f12.6 CARGA REAL: `ScrapeResultsJob` contra el HTML real → HOY 2026-09-12: 5 resultados parciales (08:05→900, 09:05→962, 10:05→204, 11:05→370, 12:05→418); AYER 2026-09-11: 12 resultados (día completo). Rescrape idempotente (5/12, 17 total, 0 errores). BD local total 145.
- [x] f12.7 Docs: fila 16 en `backend/docs/juegos.md` (type tripletas, 12 horarios, fuente HTML oficial, estado "verificado con datos reales 12-sep") + nota del scraper; `docs/plataformas-juegos.md` actualizado (La Ricachona triples → integrado; animalitos sigue candidato). tasks.md + apply-progress (merge) + commits work-unit en español. NO se abren PRs.

### WU f13 — Loto Chaima con API oficial de lotterly.co (PR 13, rama f13-loto-chaima, base f12-la-ricachona) — ✅ COMPLETADO
- [x] f13.1 Rama `feat/integracion-juegos-scrapers-f13-loto-chaima` creada desde f12. Scraper `LotoChaimaScraper` (extiende BaseScraper): `fetch` construye `https://api.lotterly.co/v1/results/loto-chaima/?exact_date=<fecha>`, parse del array JSON (numero `int` desde `result`, `nombre_animal` por lookup del mapa `ZOOLOGICO` de 57 animales con fallback de padding, hora `normalizeHora` de `HH:MM:SS`), `findJuegoOrFail` fail-fast, `saveResults` heredado (`numeros_ganadores = {"pais":"VE","numero":N,"nombre_animal":"X"}`). Maneja respuesta vacía/inválida/sin entradas (RuntimeException).
- [x] f13.2 Seeder `LotoChaimaSeeder`: slug `loto-chaima`, name "Loto Chaima", type `animalitos`, `premio_multiplo` 30, `scraper_url` = `https://api.lotterly.co/v1/results/loto-chaima/`, `scraper_class` = LotoChaimaScraper, `requires_scraper` true, JuegoLimite banca/bs/3600, PluginJuego Animalitos, JuegoHorario 08:00–19:00 (12), y **57 filas de JuegoOpcion** (label con acentos, `value` = Str::slug sin acentos, `numero`, ballena y delfín con numero 0, `sort_order` determinista del mapa). Registrado en `DatabaseSeeder` (16º juego).
- [x] f13.3 Fixtures reales `backend/tests/Fixtures/lotochaima_results.json` (día completo 2026-09-11, 12 sorteos, incluye `"0"`→Delfín) + `lotochaima_parcial.json` (2026-09-12: 5 sorteos). Snapshots literales del API.
- [x] f13.4 RED→GREEN `LotoChaimaScraperTest.php` (unit, 15: parse día completo, horas H:i, numero/animal Tortuga=37/Cebra=23, lookup `"0"`→Delfín, `"00"`→Ballena, padding `"4"`→Alacrán, acentos Ciempiés/Búfalo, parcial, estructura, entradas sin resultado, fail-fast, JSON inválido, vacío, array vacío, sin estructura) + `LotoChaimaResultsTest.php` (feature, 7: seeder, límite+plugin, 12 horarios, 57 opciones, persistencia 12, dedupe, resolver). `composer test -- --filter=Chaima` → 22/22 (77 assertions).
- [x] f13.5 Regresión: `JuegosJsonTest` 15→16 juegos (SLUGS_POR_ID + loto-chaima, 57 opciones desde tabla con acentos) y `LimitesScopedApiTest` 15→16 juegos/30→32 límites+origen/60→64 scope/mixto 30→32.
- [x] f13.6 REGENERADO `docs/juegos.json` con `php artisan juegos:export` (16 juegos, id 16 = loto-chaima, **57 opciones** propias, horarios 08:00–19:00) y COMMITEADO; determinista (2 ejecuciones = mismo md5 c9ed8e26).
- [x] f13.7 CARGA REAL: `ScrapeResultsJob` contra el API real → HOY 2026-09-12: 5 resultados parciales (08:00 Lapa 31, 09:00 Puma 46, 10:00 Pescado 33, 11:00 Alacrán 4, 12:00 Lechuza 39); AYER 2026-09-11: 12 resultados (día completo, incl. 13:00 Delfín 0). Rescrape idempotente (5/12, 17 únicos, 0 errores). BD local total 162.
- [x] f13.8 Docs: fila 17 en `backend/docs/juegos.md` (type animalitos, 57 animales propios, 12 horarios, fuente API lotterly, estado "verificado con datos reales 12-sep") + nota del scraper y del zoológico propio; `docs/plataformas-juegos.md` → Plataforma 3 (lotterly.co, API por product_slug + exact_date, Loto Chaima integrado). tasks.md + apply-progress (merge) + commits work-unit en español. NO se abren PRs.


### WU f14 — Mega Animal 40 con resultadosvenezuela.com + investigación del proveedor + comparación (PR 14, rama f14-mega-animal-40, base f13-loto-chaima) — ✅ COMPLETADO
- [x] f14.1 Rama `feat/integracion-juegos-scrapers-f14-mega-animal-40` creada desde f13. Scraper `MegaAnimal40Scraper` (extiende BaseScraper): `fetch` construye `https://resultadosvenezuela.com/lottery/mega-animal-40?date=<fecha>`, parse de las cards `.result-card` (`.card-time` 12h → `normalizeHora`, `.card-number`, `.card-name`), `findJuegoOrFail` fail-fast, `saveResults` heredado (`numeros_ganadores = {"pais":"VE","numero":N,"nombre_animal":"X"}`). Defensivo: cards "Pendiente"/sin `card-number` se saltan; fecha sin sorteos ocurridos (página SIN cards) devuelve `[]` (estado válido); cuerpo vacío → RuntimeException. Comodín "MEGA" NO aparece como marcador en las cards (~11 fechas escaneadas) → hallazgo documentado, sin lógica de comodín.
- [x] f14.2 Seeder `MegaAnimal40Seeder`: slug `mega-animal-40`, name "Mega Animal 40", type `animalitos`, `premio_multiplo` 30, `scraper_url` = `https://resultadosvenezuela.com/lottery/mega-animal-40`, `scraper_class` = MegaAnimal40Scraper, `requires_scraper` true, JuegoLimite banca/bs/3600, PluginJuego Animalitos, JuegoHorario 09:00–20:00 (12). SIN `JuegoOpcion` propias: el zoológico del proveedor es el canónico de 38 (coincide con el plugin Animalitos) → el catálogo cae al plugin por fallback. Registrado en `DatabaseSeeder` (17º juego).
- [x] f14.3 Fixtures reales `backend/tests/Fixtures/megaanimal40_results.html` (día completo 2026-09-11, 12 cards), `megaanimal40_parcial.html` (2026-09-12: 4 cards) y `megaanimal40_sin_cards.html` (2026-09-13: página sin cards, "No se encontraron sorteos"). Snapshots reales del markup (bloque `.result-card`).
- [x] f14.4 RED→GREEN `MegaAnimal40ScraperTest.php` (unit, 10: parse día completo, horas 12h→H:i en orden del documento, numero/animal Oso=16/Elefante=29, parcial, día sin sorteos → `[]`, cards pendientes saltadas, estructura completa, fail-fast, respuesta vacía, espacios en blanco) + `MegaAnimal40ResultsTest.php` (feature, 7: seeder, límite+plugin, 12 horarios, 0 opciones propias/fallback plugin, persistencia 12, dedupe, resolver). `composer test -- --filter=MegaAnimal` → 17/17 (56 assertions).
- [x] f14.5 Regresión: `JuegosJsonTest` 16→17 juegos (SLUGS_POR_ID + mega-animal-40, 38 opciones vía plugin con labels sin acentos 'Delfin') y `LimitesScopedApiTest` 16→17 juegos/32→34 límites+origen/64→68 scope/mixto 32→34. 3/3 (420) y 30/30 (361).
- [x] f14.6 REGENERADO `docs/juegos.json` con `php artisan juegos:export` (17 juegos, id 17 = mega-animal-40, 38 opciones vía plugin, horarios 09:00–20:00) y COMMITEADO; determinista (2 ejecuciones = mismo md5 b201bb99). Suite completa **566/564/2** (2677 assertions) + `pint --test` limpio.
- [x] f14.7 CARGA REAL: `ScrapeResultsJob` contra el HTML real → HOY 2026-09-12: 5 resultados parciales (09:00 Burro 18, 10:00 Gallina 25, 11:00 Ardilla 32, 12:00 Caimán 30, 13:00 Camello 22); AYER 2026-09-11: 12 resultados (día completo 09:00–20:00). Rescrape idempotente (5/12, 17 únicos, 0 errores). BD local total 179 (17 juegos).
- [x] f14.8 Docs: fila 18 en `backend/docs/juegos.md` (type animalitos, 12 horarios 09:00–20:00, fuente HTML agregador, estado "verificado con datos reales 12-sep") + nota del scraper y del comodín MEGA; `docs/plataformas-juegos.md` → **Plataforma 4** (resultadosvenezuela.com: patrón `?date=`, cards, sin API pública, robots.txt con rutas prohibidas — nunca tocar `result.php` —, catálogo de 35 juegos, agregador: preferir fuentes oficiales); NUEVO `docs/comparacion-juegos.md` (comparación a 3 niveles ResultadosVenezuela vs nuestro sistema + hallazgos y decisiones pendientes H1–H7). tasks.md + apply-progress (merge) + commits work-unit en español. NO se abren PRs.

### WU f16 — Selva Plus con API oficial de lotterly.co (PR 16, rama f16-selva-plus, base f15-docs-estrategia) — ✅ COMPLETADO
- [x] f16.1 Rama `feat/integracion-juegos-scrapers-f16-selva-plus` creada desde f15. Scraper `SelvaPlusScraper` (extiende BaseScraper): `fetch` construye `https://api.lotterly.co/v1/results/selva-plus/?exact_date=<fecha>`, parse del array JSON (`result` numérico 00-99, `nombre_animal` por lookup del mapa `ZOOLOGICO` de **101 figuras** con fallback de padding `"8"`→"08"→Ratón, `"0"`→Delfín, `"00"`→Ballena, hora `normalizeHora` de `HH:MM:SS` → "08:15"), `findJuegoOrFail` fail-fast, `saveResults` heredado. **Defensivo comodines**: la representación en `result` NO se observó (65 sorteos del 07-11 sep numéricos) → `result` no numérico guarda valor crudo en `numeros_ganadores` (`resultado_crudo`) + log warning, sin mapeos inventados. Maneja `[]`/inválido/sin estructura (RuntimeException; `[]` legítimo antes del lanzamiento 2026-09-07).
- [x] f16.2 Seeder `SelvaPlusSeeder`: slug `selva-plus`, name "Selva Plus", type `animalitos`, `config = {"premio_multiplo": 80, "comodines": {"comodin-a": {"nombre": "Leoncito", "premio_multiplo": 160}, "comodin-b": {"nombre": "Selva Plus", "premio_multiplo": 200}}}` (valor REAL para front y futuro motor; el motor actual NO usa `premio_multiplo` — gap documentado en `docs/estrategia-scrapers-premios.md`), `scraper_url` = API, `scraper_class` = SelvaPlusScraper, `requires_scraper` true, JuegoLimite banca/bs/3600, PluginJuego Animalitos, JuegoHorario 08:15–20:15 (13), y **103 filas de JuegoOpcion** (101 figuras: label con acentos, `value` = Str::slug, `numero` int, Ballena y Delfín con numero 0, `sort_order` del mapa; + 2 comodines: `numero` null, `value` `comodin-a`/`comodin-b`, label "Leoncito (comodín A)"/"Selva Plus (comodín B)"). Registrado en `DatabaseSeeder` (18º juego).
- [x] f16.3 Fixtures reales `backend/tests/Fixtures/selvaplus_results.json` (día completo 2026-09-11, 13 sorteos 08:15–20:15), `selvaplus_parcial.json` (2026-09-12: 7 sorteos) y `selvaplus_vacio.json` (`[]`). Snapshots literales del API.
- [x] f16.4 RED→GREEN `SelvaPlusScraperTest.php` (unit, 15: parse día completo 13, horas 08:15–20:15, numero/figura Perro=27/Caballito de Mar=96, lookup `"0"`→Delfín, `"00"`→Ballena, normalización `"8"`→"08"→Ratón, parcial 7 con Cabra=87/Camaleón=60, defensivo no numérico `COMODIN_A`→crudo sin mapeo, estructura, entradas sin resultado, fail-fast, JSON inválido, vacío, array vacío, sin estructura) + `SelvaPlusResultsTest.php` (feature, 7: seeder+config comodines, límite+plugin, 13 horarios, 103 opciones con comodines al final, persistencia 13, dedupe, resolver). `composer test -- --filter=Selva` → 22/22 (93 assertions).
- [x] f16.5 Regresión: `JuegosJsonTest` 17→18 juegos (SLUGS_POR_ID + selva-plus; **103 opciones** propias — comodines con numero null al inicio por orden MySQL —, premio 80) y `LimitesScopedApiTest` 17→18 juegos/34→36 límites+origen/68→72 scope/mixto 34→36. JuegosJsonTest 3/3 (456) y LimitesScopedApiTest 30/30 (375).
- [x] f16.6 REGENERADO `docs/juegos.json` con `php artisan juegos:export` (18 juegos, id 18 = selva-plus, **103 opciones** (101 figuras + 2 comodines), premio 80, 13 horarios 08:15–20:15) y COMMITEADO; determinista (2 ejecuciones = mismo md5 94e2b8ce).
- [x] f16.7 CARGA REAL: `ScrapeResultsJob` contra el API real → HOY 2026-09-12: 7 resultados parciales (08:15 Cabra 87, 09:15 Camaleón 60, 10:15 Gaviota 93, 11:15 Bisonte 70, 12:15 Lechuza 39, 13:15 Tortuga 37, 14:15 Hurón 90); AYER 2026-09-11: 13 resultados (día completo 08:15–20:15, 27 Perro … 96 Caballito de Mar). Rescrape idempotente (7/13, 20 únicos, 0 errores). BD local total 199 (18 juegos).
- [x] f16.8 Docs: fila 19 en `backend/docs/juegos.md` (type animalitos, **101 figuras + 2 comodines**, 13 horarios 08:15–20:15, premio 80×, API lotterly, estado "verificado con datos reales 12-sep") + nota del scraper (zoo propio, comodines defensivos, gap del motor de premios); `docs/plataformas-juegos.md` → **Plataforma 3 con DOS productos verificados** (loto-chaima + selva-plus); `docs/comparacion-juegos.md` → fila Selva Plus en mapa/Nivel 1/Nivel 2 + **hallazgo H8: el proveedor resultadosvenezuela está EQUIVOCADO para selva-plus** (declara 38 animalitos/30×/11 sorteos; la verdad oficial es 101+2 comodines/80×/13 sorteos). tasks.md + apply-progress (merge) + commits work-unit en español. NO se abren PRs.

### WU f18 — Triple Táchira con el sitio oficial tripletachira.com (rama f18-triple-tachira, base f17-docs-fuentes) — ✅ COMPLETADO
- [x] f18.1 Rama `feat/integracion-juegos-scrapers-f18-triple-tachira` creada desde f17. Scraper `TripleTachiraScraper` (extiende BaseScraper): `fetch` construye `https://tripletachira.com/pruebah.php?bt=DD/MM/YYYY&bt2=DD/MM/YYYY`, parse de la tabla semanal `#main-table` localizando la columna por su FECHA en el header (`<th>Lunes<br>11/09/2026</th>`; el nombre del día es FIJO, no el día real — nunca se usa), filas A/B/ZODI agrupadas por hora, skip de `--------`, hora 12h sin AM/PM → 24h (01:15→13:15, 04:45→16:45, 10:10→22:10 — todos PM, verificado con la home "1:15PM" y el reglamento), ZODI `160 <br>PIC.` → `triple_c` + `signo` (PIC→PIS, resto igual, sigla desconocida defensiva), `findJuegoOrFail` fail-fast, `saveResults` heredado. Maneja HTML vacío/sin tabla (RuntimeException) y fecha sin datos (tabla toda `--------` → `[]`, estado válido).
- [x] f18.2 Seeder `TripleTachiraSeeder`: slug `triple-tachira`, name "Triple Táchira", type `tripletas`, config con **premios OFICIALES del reglamento G-20004065-3** (Lotería del Táchira, PDF parseable con pdftotext): `{"premio_multiplo": 500, "modalidades": {"cola": 50, "zodiacal": 5000}}` — la informativa declara 600/60/6.000 (desajuste H9), `scraper_url` = `https://tripletachira.com/pruebah.php`, `scraper_class` = TripleTachiraScraper, `requires_scraper` true, JuegoLimite banca/bs/3600, PluginJuego Tripletas, JuegoOpcion 12 signos, JuegoHorario **13:15/16:45/22:10** (3). Registrado en `DatabaseSeeder` (19º juego).
- [x] f18.3 Fixtures reales `backend/tests/Fixtures/tripletachira_semana.html` (semana 11-17-sep: 11/09 completo 3 sorteos, 12/09 parcial 1 sorteo, 13/09 domingo vacío), `tripletachira_domingo.html` (semana 01-07-sep: domingo real 06/09 con solo 22:10) y `tripletachira_sin_datos.html` (fecha lejana: tabla toda `--------` → `[]`). Snapshots literales del sitio.
- [x] f18.4 RED→GREEN `TripleTachiraScraperTest.php` (unit, 15: día completo por fecha, horas 13:15/16:45/22:10, A/B/C+signo PIC→PIS ×3 sorteos, parcial, domingo sin sorteos → [], domingo solo 22:10, primera columna sin fecha, fecha sin datos → [], horaTablaA24h pura ×6, mapearSigno ×12, estructura, fail-fast, vacío, sin tabla, fecha no encontrada) + `TripleTachiraResultsTest.php` (feature, 7: seeder+premios oficiales, límite+plugin, 3 horarios, 12 opciones, persistencia 3, dedupe, resolver). `composer test -- --filter=Tachira` → 22/22 (93 assertions).
- [x] f18.5 Regresión: `JuegosJsonTest` 18→19 juegos (SLUGS_POR_ID + triple-tachira; **12 opciones** desde tabla, premio 500, horarios 13:15/16:45/22:10) y `LimitesScopedApiTest` 18→19 juegos/36→38 límites+origen/72→76 scope/mixto 36→38. JuegosJsonTest 3/3 y LimitesScopedApiTest 30/30.
- [x] f18.6 REGENERADO `docs/juegos.json` con `php artisan juegos:export` (19 juegos, id 19 = triple-tachira, 12 opciones, premio 500, horarios 13:15/16:45/22:10) y COMMITEADO; determinista (2 ejecuciones = mismo md5 23894802).
- [x] f18.7 CARGA REAL: `ScrapeResultsJob` contra el sitio real → AYER 2026-09-11: 3 resultados (13:15 245/998/160-PIS, 16:45 572/033/981-GEM, 22:10 623/539/998-ACU); HOY 2026-09-12: 1 parcial (13:15 203/894/094-SAG). Rescrape idempotente (4 filas, 0 errores). BD local total **203** (199 previos + 4).
- [x] f18.8 Docs: fila 20 en `backend/docs/juegos.md` (type tripletas, 3 horarios 13:15/16:45/22:10, sitio oficial, estado "verificado con datos reales 12-sep") + nota del scraper y del reglamento; `docs/fuentes-oficiales.md` → fila 19 triple-tachira **verificada** con desajustes H9; `docs/comparacion-juegos.md` → fila Triple Táchira en mapa/Nivel 1/Nivel 2/operador + **hallazgo H9 completo** (3er sorteo 19:20 vs 22:10 oficial; premios 600/60/6.000 vs 500/50/5.000 del reglamento; comportamiento dominical NO uniforme — 06-sep solo 22:10, 13-sep ninguno — pendiente). tasks.md + apply-progress (merge) + commits work-unit en español. NO se abren PRs.

## Phase 3: Verificación / cierre

- [ ] 3.1 Suite general completa al integrar 10 juegos (criterio cliente), documentado en `docs/juegos.md`.
- [ ] 3.2 `vendor/bin/pint --test` (CI) limpio.
- [ ] 3.3 Confirmar `panel/` y contratos API intactos.

## Rollback por unidad

- Juego: eliminar seeder + clase scraper + fixtures + fila `juegos`; sin afectar otros juegos.
- Fundación: `php artisan migrate:rollback` (drop column) restaura resolver legacy.
