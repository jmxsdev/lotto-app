# Apply Progress: integracion-juegos-scrapers — PR 1 (Fundación) + PR 2 (Juego 1: Triple Caliente)

**Cambio**: integracion-juegos-scrapers
**Modo**: Strict TDD (backend: `composer test` vía `php artisan test`)
**Cadena**: feature-branch-chain (tracker `feature/integracion-juegos-scrapers` desde `main`)

---

# Sección PR 1 — Fundación (tareas 1.1–1.10)

**Rama**: `feat/integracion-juegos-scrapers-f0` (base: tracker)
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
  en `setUp`. Sin este ajuste la suite fallaba (7 errores); con él, 367/365/2.

## TDD Cycle Evidence (PR 1)

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|-------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1+1.2 | `tests/Unit/JuegoScraperClassTest.php` | Unit | ✅ 343/341/2 | ✅ (2 fallos) | ✅ 3/3 | ✅ 3 casos | ➖ Ninguno |
| 1.3 | N/A (docs) | Docs | N/A | ➖ | ➖ | ➖ Omitida: documentación | ✅ |
| 1.4+1.5 | `tests/Unit/ScraperResolverTest.php` | Unit | ✅ 4/4+1skip | ✅ (3 fallos) | ✅ 10/10 | ✅ 8+2 casos | ✅ Clean |
| 1.6 | `tests/Unit/BaseScraperHelpersTest.php` | Unit | ✅ idem | ✅ (6 errores) | ✅ 7/7 | ✅ 7 casos | ✅ Clean |
| 1.7+1.8 | `tests/Unit/ScraperResolverTest.php` | Unit | ✅ aprobación scrapers | ✅ (2 fallos) | ✅ 14/14 | ✅ 4 casos fail-fast | ✅ Clean |
| 1.9 | `tests/Unit/ScraperResolverTest.php` | Unit | ✅ suite 343 | ✅ | ✅ 10/10 | ✅ 8 casos | ✅ Clean |
| 1.10 | `tests/Unit/ScraperResolverTest.php` | Unit | ✅ | ✅ | ✅ 14/14 | ✅ 4 casos | ✅ Clean |

**Test Summary (PR 1)**: +24 tests (367 vs 343 baseline), todos pasando; aprobación de refactor:
26 tests de scrapers/jobs existentes; funciones puras: `normalizeHora`, `findJuegoOrFail` base.

## Work Unit Evidence (PR 1)

| Work unit | Focused test command y resultado | Runtime harness y resultado | Rollback boundary |
|-----------|----------------------------------|-----------------------------|-------------------|
| WU1 catálogo | N/A (docs) | `git diff` + revisión de seeders | `backend/docs/juegos.md` — eliminar archivo |
| WU2 migración+modelo | `composer test -- --filter=JuegoScraperClassTest` → 3/3 | `php artisan migrate:rollback --step=1` + `php artisan migrate` → up/down OK | `migrate:rollback` (drop column) + revertir Juego.php |
| WU3 resolver | `composer test -- --filter=ScraperResolverTest` → 10/10 + `--filter=ScrapeResultsJobTest` → 4/4+1skip | `php artisan tinker` → `trio-activo`→AnimalitosScraper, `lotto-activo`→AnimalitosScraper con 7 juegos seedeados | Revertir `ScrapeResultsJob::resolveScraper/instantiateScraper` |
| WU4 helpers base | `composer test -- --filter=BaseScraperHelpersTest` → 7/7 | N/A (helpers puros; sin borde runtime propio; cubiertos por suite feature) | Revertir `BaseScraper` (quitar 3 métodos) |
| WU5 fail-fast scrapers | `composer test -- --filter=ScraperResolverTest` → 14/14 + aprobación scrapers/jobs → 26/26+1skip | `php artisan db:seed` → 7 juegos registrados; tinker verifica resolución real | Revertir AnimalitosScraper/TripletasScraper |

## Desviaciones del diseño (PR 1)

1. **Tarea 1.5**: "rama Animalitos pasa `$juego`" se interpretó como *rama Animalitos conserva el
   slug derivado de `$juego->scraper_url`* (constructor AnimalitosScraper es `string $slug`; D6 solo
   migra el constructor de TripletasScraper). El resto pasa `new $class($juego)`.
2. **Tests unit legacy**: se sembraron los juegos en `setUp` (antes dependían de la creación en
   caliente). Los juegos ya estaban registrados en los seeders; no se tocaron seeders.
3. **`config('scraper.product_id', '2')`**: no existe `config/scraper.php`; el default '2' preserva
   el valor hardcodeado previo. No se creó el archivo de config.

---

# Sección Juego 1 — Triple Caliente (PR 2, tareas 9a–9f)

**Rama**: `feat/integracion-juegos-scrapers-f1-triple-caliente` (base: `feat/integracion-juegos-scrapers-f0`)
**Estado**: ✅ 9a–9e completadas; ⏳ 9f (verificación con URL real) pendiente del cliente

## Resumen

Integración del juego 9 (Triple Caliente): seeder completo (slug `triple-caliente`, type `tripletas`,
`premio_multiplo` 30, límite default banca/bs/3600, plugin Tripletas, opciones de 12 signos zodiacales,
horarios 13:00/16:30/19:10, `scraper_url` de loteriadehoy.com y `scraper_class` LoteriaDeHoyScraper),
scraper parametrizado `LoteriaDeHoyScraper` (reutilizable por cualquier juego de loteriadehoy.com vía
`scraper_url` + slug/name del juego, fail-fast `findJuegoOrFail`, `normalizeHora` 12h→H:i, números
A/B/C de 3 cifras y signo zodiacal), fixture real de la página de resultados, y tests unit + feature.
El parseo se verifica contra el fixture real (3 sorteos: 13:00/16:30/19:10 con números y signos).

## Hallazgo: conteos de juegos en LimitesScopedApiTest

Al registrar el octavo juego en `DatabaseSeeder`, la matriz juego×moneda de `/api/v1/limites` pasa de
7 a 8 juegos. Se actualizaron las aserciones de conteo en `LimitesScopedApiTest` (juegos 7→8,
límites/origen 14→16, scope de entidades 28→32) — comportamiento probado sin cambios. Sin esta
actualización la suite completa fallaba (6 errores).

## TDD Cycle Evidence (Juego 1)

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|-------|-----------|-------|------------|-----|-------|-------------|----------|
| 9a (seeder) | `tests/Feature/TripleCalienteResultsTest.php` | Feature | ✅ suite 367/365/2 | ✅ (3 fallos: juego/limite/horarios) | ✅ 6/6 | ✅ 6 casos (juego+scraper_class, límite+plugin, horarios, persistencia, dedupe, resolver) | ✅ Clean |
| 9b+9c (scraper+fixture) | `tests/Unit/TripleCalienteScraperTest.php` | Unit | ✅ idem | ✅ (5 fallos: parseo/horas/números/signo/estructura) | ✅ 8/8 | ✅ 8 casos (3 bloques, horas H:i, A/B/C con cero inicial, signo, estructura, fail-fast, sin resultados, filas malformadas) | ✅ Clean |
| 9d (tests) | idem | Unit+Feature | ✅ `--filter=TripleCaliente` 14/14 | ✅ | ✅ 14/14 | ✅ 14 casos | ✅ Clean |
| 9e (docs) | N/A (docs) | Docs | N/A | ➖ | ➖ | ➖ Omitida: documentación | ✅ fila en catálogo |
| 9f (URL real) | N/A | Runtime | N/A | ➖ | ➖ | ➖ Pendiente del cliente | ➖ |

**Test Summary (Juego 1)**: +14 tests (381 vs 367 baseline = 14 nuevos); suite completa 381/379/2
(2 skips pre-existentes) + pint limpio.

## Work Unit Evidence (Juego 1)

| Work unit | Focused test command y resultado | Runtime harness y resultado | Rollback boundary |
|-----------|----------------------------------|-----------------------------|-------------------|
| WU1 seeder+scraper+fixture | `composer test -- --filter=TripleCaliente` → 14/14 (56 assertions) | N/A — parseo verificado contra fixture real; fetch con URL real pendiente del cliente (9f) | Eliminar `TripleCalienteSeeder` + `LoteriaDeHoyScraper` + fixture + revertir `DatabaseSeeder` |
| WU2 feature persistencia | `composer test -- --filter=TripleCalienteResultsTest` → 6/6 | `php artisan tinker` → `new LoteriaDeHoyScraper($juego)` + parse/saveResults contra fixture (reproducible) | Idem WU1 + filas `resultados` de triple-caliente |
| WU3 conteos LimitesScopedApiTest | `vendor/bin/phpunit --filter=LimitesScopedApiTest` → 30/30 (235 assertions) | N/A (regresión de API cubierta por suite feature) | Revertir solo las aserciones de conteo (8→7, 16→14, 32→28) |

## Archivos cambiados (Juego 1)

| Archivo | Acción | Qué se hizo |
|---------|--------|-------------|
| `backend/app/Plugins/Scrapers/LoteriaDeHoyScraper.php` | Create | Scraper parametrizado: constructor `?Juego`, `fetch` (scraper_url + fecha), `parse` (tabla `table.resultados`, hora 12h→H:i, A/B/C 3 cifras, signo, `findJuegoOrFail`) |
| `backend/database/seeders/TripleCalienteSeeder.php` | Create | Juego `triple-caliente` + JuegoLimite banca/bs/3600 + PluginJuego Tripletas + JuegoOpcion signos + JuegoHorario 13:00/16:30/19:10 + `scraper_class` |
| `backend/database/seeders/DatabaseSeeder.php` | Modify | Registra `TripleCalienteSeeder` |
| `backend/tests/Unit/TripleCalienteScraperTest.php` | Create | 8 tests unit (parseo con fixture real, horas, números, signo, estructura, fail-fast, sin resultados, filas malformadas) |
| `backend/tests/Feature/TripleCalienteResultsTest.php` | Create | 6 tests feature (seeder, límite+plugin, horarios, persistencia, dedupe, resolver) |
| `backend/tests/Fixtures/loteriadehoy_triplecaliente.html` | Create | Snapshot real de `https://loteriadehoy.com/loteria/triplecaliente/resultados/` (3 sorteos) |
| `backend/tests/Feature/LimitesScopedApiTest.php` | Modify | Conteos de la matriz juego×moneda: 7→8 juegos, 14→16 límites/origen, 28→32 scope |
| `backend/docs/juegos.md` | Modify | Triple Caliente movido de pendientes a "Juegos integrados" con horarios, fuente, clase scraper y estado |
| `openspec/changes/integracion-juegos-scrapers/tasks.md` | Modify | 9a–9e marcadas `[x]`; 9f pendiente; fila 9 ✅ integrado (PR 2) |

## Desviaciones del diseño (Juego 1)

1. **LoteriaDeHoyScraper sin `baseUrl`**: el scraper usa `$this->juego->scraper_url` como base
   (parametrización por juego, alineado con D6). No declara `baseUrl` propia porque la fuente se
   registra por juego; el fetch construye `{scraper_url}/{fecha}/`.
2. **Config `premio_multiplo` en `config['premio_multiplo']`**: el seeder guarda `premio_multiplo` 30
   dentro de la columna `config` (patrón de los 7 juegos existentes), no como columna dedicada.
3. **Horarios 13:00/16:30/19:10**: confirmados con el cliente según el alcance (no 12:45/16:45/19:05
   como Triple Zulia); el fixture real muestra 01:00 PM/04:30 PM/07:10 PM → 13:00/16:30/19:10.

## Problemas encontrados (Juego 1)

- **Suite completa roja tras integrar el 8º juego**: `LimitesScopedApiTest` asumía 7 juegos
  sembrados. Resuelto actualizando los conteos (ver hallazgo). Es un ajuste legítimo de regresión,
  no un cambio de comportamiento.
- **Seeders demo descartados**: `ResultadosHoySeeder`, `CierresCajaDemoSeeder`, `PagosDemoSeeder`,
  `TicketsHoySeeder` quedaron de una corrida anterior sin referencia; el feature test no los necesita
  (usa `TripleCalienteSeeder` + su propia Banca). Se descartaron del work unit.
- **`docs/deploy.md` revertido**: tenía un cambio ajeno (config de mysql_exporter) que no pertenece
  a este work unit; se restauró a HEAD.

## Workload / PR Boundary (Juego 1)

- Modo: chained PR slice (feature-branch-chain, PR 2 de la cadena; base = PR 1 `feat/integracion-juegos-scrapers-f0`).
- Boundary: integración completa del juego 9 (seeder → scraper → fixture → tests → docs) con
  verificación incluida (suite completa 381/379/2 + pint limpio).
- Budget: 4 commits, ~950 líneas (927 insertions del feat + 14/14 del test de conteos + 11/2 docs
  + 10/2 tasks) — coherente con el forecast de ~400 líneas por juego (el fixture HTML real aporta
  ~440 líneas de snapshot).
- Rollback boundary por unidad: ver tabla Work Unit Evidence (Juego 1).

## Siguiente paso recomendado

- Juego 10 (Cazaloton): requiere URL y estructura de la fuente del cliente antes de aplicar.
- `9f` — verificación funcional con URL real (`php artisan tinker` → fetch+parse) pendiente del
  cliente; no bloquea los siguientes juegos pero debe cerrarse antes de marcar Triple Caliente
  como verificado con datos reales.
- `sdd-verify` del PR 2 cuando el orquestador lo dispare (o del PR 1 si aún no se verificó).

---

# Sección Juego 2 — Cazaloton (PR 3, tareas 10a–10f)

**Rama**: `feat/integracion-juegos-scrapers-f2-cazaloton` (base: `feat/integracion-juegos-scrapers-f1-triple-caliente`)
**Estado**: ✅ 10a–10e completadas; ⏳ 10f (verificación con URL real) pendiente del cliente

## Resumen

Integración del juego 10 (Cazaloton): seeder (slug `cazaloton`, type `animalitos`, `premio_multiplo`
30, límite default banca/bs/3600, plugin Animalitos, horarios 09:00–19:00 (11), `scraper_url` y
`scraper_class` LoteriaDeHoyScraper), extensión de `LoteriaDeHoyScraper` con modo animalitos
(`parseAnimalitos`: `div.js-con`, bloques número+animal+hora 12h), fixture real, y tests unit+feature.

## TDD Cycle Evidence (Juego 2)

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|-------|-----------|-------|------------|-----|-------|-------------|----------|
| 10a/10d | `tests/Feature/CazalotonResultsTest.php` | Feature | ✅ TripleCaliente 14/14 | ✅ | ✅ 6/6 | ✅ 6 casos (juego+scraper_class, límite+plugin, 11 horarios, persistencia, dedupe, resolver) | ✅ Clean |
| 10b/10c/10d | `tests/Unit/CazalotonScraperTest.php` | Unit | ✅ idem | ✅ | ✅ 7/7 | ✅ 7 casos (parcial, numero/animal, horas, estructura, fail-fast, sin bloques, malformado) | ✅ Clean |
| 10d | `tests/Feature/LimitesScopedApiTest.php` | Feature | ✅ previo | N/A (ajuste conteos) | ✅ 30/30 | ✅ conteos 9/18/36 | ✅ |

**Test Summary (Juego 2)**: +13 tests (394 vs 381 baseline); suite completa 394/392/2 + pint limpio.

## Work Unit Evidence (Juego 2)

| Work unit | Focused test command y resultado | Runtime harness y resultado | Rollback boundary |
|-----------|----------------------------------|-----------------------------|-------------------|
| WU1 seeder+scraper+fixture | `composer test -- --filter=Cazaloton` → 13/13 | N/A — parseo contra fixture real; fetch con URL real pendiente (10f) | Eliminar `CazalotonSeeder` + modo animalitos + fixture + revertir `DatabaseSeeder` |
| WU2 feature persistencia | `composer test -- --filter=CazalotonResultsTest` → 6/6 | `php artisan tinker` → `new LoteriaDeHoyScraper($juego)` + parse/saveResults contra fixture | Idem WU1 + filas `resultados` de cazaloton |
| WU3 conteos LimitesScopedApiTest | `--filter=LimitesScopedApiTest` → 30/30 | N/A (regresión de API) | Revertir solo las aserciones de conteo (9→8, 18→16, 36→32) |

---

# Sección Juego 3 — Triple Chance (PR 4, tareas 11a–11f)

**Rama**: `feat/integracion-juegos-scrapers-f3-triple-chance` (base: `feat/integracion-juegos-scrapers-f2-cazaloton`)
**Estado**: ✅ 11a–11e completadas; ⏳ 11f (verificación con URL real) pendiente del cliente

## Resumen

Integración del juego 11 (Triple Chance): seeder (slug `triple-chance`, type `tripletas`,
`premio_multiplo` 30, límite default banca/bs/3600, plugin Tripletas, opciones de 12 signos,
horarios 09:00–19:00 (11), `scraper_url` y `scraper_class` LoteriaDeHoyScraper), fixture real de la
página de resultados, y tests unit+feature. NO requirió cambio de código en el scraper: se confirmó
que `parseTripletas` ya ignora los bloques de hora sin resultado.

## Hallazgo: bloques de hora sin resultado en el modo tripletas

La página de Triple Chance lista los 11 bloques de horario del día (09:00–19:00) como filas de
`table.resultados tbody tr`, pero solo los ya sorteados traen las celdas A/B/C + signo; los horarios
futuros (11:00 AM – 07:00 PM) aparecen como filas con un único `<td>` de hora. El `parseTripletas`
actual descarta esas filas por `count($celdas) < 5` (y por la guarda `! $hora || ! $tripleA ||
! $tripleB || ! $tripleC`), por lo que no genera resultado vacío ni error — se confirmó con un test
sin cambiar el código. El fixture real del snapshot captura ambos casos (2 bloques con resultado y 9
sin resultado).

## Hallazgo: conteos de juegos en LimitesScopedApiTest

Al registrar el décimo juego en `DatabaseSeeder`, la matriz juego×moneda de `/api/v1/limites` pasa
de 9 a 10 juegos. Se actualizaron las aserciones de conteo (juegos 9→10, límites/origen 18→20,
scope de entidades 36→40, `mixto` 18→20) — comportamiento probado sin cambios.

## TDD Cycle Evidence (Juego 3)

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|-------|-----------|-------|------------|-----|-------|-------------|----------|
| 11b/11c/11d | `tests/Unit/TripleChanceScraperTest.php` | Unit | ✅ TripleCaliente+Cazaloton 27/27 | ✅ | ✅ 8/8 | ✅ 8 casos (solo bloques con resultado, ignora hora sin resultado, horas H:i, A/B/C, signo, estructura, fail-fast, malformado) | ✅ Clean |
| 11a/11d | `tests/Feature/TripleChanceResultsTest.php` | Feature | ✅ idem | ✅ | ✅ 6/6 | ✅ 6 casos (juego+scraper_class, límite+plugin, 11 horarios, persistencia, dedupe, resolver) | ✅ Clean |
| 11d | `tests/Feature/LimitesScopedApiTest.php` | Feature | ✅ previo | N/A (ajuste conteos) | ✅ 30/30 | ✅ conteos 10/20/40 | ✅ |

**Test Summary (Juego 3)**: +14 tests (408 vs 394 baseline); suite completa 408/406/2 + pint limpio.

## Work Unit Evidence (Juego 3)

| Work unit | Focused test command y resultado | Runtime harness y resultado | Rollback boundary |
|-----------|----------------------------------|-----------------------------|-------------------|
| WU1 seeder+fixture | `composer test -- --filter=TripleChance` → 14/14 (54 assertions) | N/A — parseo contra fixture real (snapshot del 2026-09-01); fetch con URL real pendiente (11f) | Eliminar `TripleChanceSeeder` + fixture + revertir `DatabaseSeeder` |
| WU2 feature persistencia | `composer test -- --filter=TripleChanceResultsTest` → 6/6 | `php artisan tinker` → `new LoteriaDeHoyScraper($juego)` + parse/saveResults contra fixture | Idem WU1 + filas `resultados` de triple-chance |
| WU3 conteos LimitesScopedApiTest | `--filter=LimitesScopedApiTest` → 30/30 | N/A (regresión de API) | Revertir solo las aserciones de conteo (10→9, 20→18, 40→36) |

## Archivos cambiados (Juego 3)

| Archivo | Acción | Qué se hizo |
|---------|--------|-------------|
| `backend/database/seeders/TripleChanceSeeder.php` | Create | Juego `triple-chance` + JuegoLimite banca/bs/3600 + PluginJuego Tripletas + JuegoOpcion signos + JuegoHorario 09:00–19:00 (11) + `scraper_class` |
| `backend/database/seeders/DatabaseSeeder.php` | Modify | Registra `TripleChanceSeeder` |
| `backend/tests/Fixtures/loteriadehoy_triplechance.html` | Create | Snapshot real de `https://loteriadehoy.com/loteria/triplechance/resultados/` (2 bloques con resultado + 9 bloques de hora sin resultado) |
| `backend/tests/Unit/TripleChanceScraperTest.php` | Create | 8 tests unit (parseo, ignora bloques sin resultado, horas, números, signo, estructura, fail-fast, malformado) |
| `backend/tests/Feature/TripleChanceResultsTest.php` | Create | 6 tests feature (seeder, límite+plugin, horarios, persistencia, dedupe, resolver) |
| `backend/tests/Feature/LimitesScopedApiTest.php` | Modify | Conteos de la matriz juego×moneda: 9→10 juegos, 18→20 límites/origen, 36→40 scope, mixto 18→20 |
| `backend/docs/juegos.md` | Modify | Triple Chance movido de pendientes a "Juegos integrados" + nota del formato tripletas con bloques sin resultado |
| `openspec/changes/integracion-juegos-scrapers/tasks.md` | Modify | 11a–11e marcadas `[x]`; 11f pendiente; fila 11 ✅ integrado (PR 4) |

## Desviaciones del diseño (Juego 3)

None — implementation matches design. Triple Chance usa el tipo `tripletas` existente: seeder con
plugin Tripletas + opciones de signos (patrón TripleCaliente) y horarios 09:00–19:00 (11, patrón
Cazaloton). El scraper no cambió porque `parseTripletas` ya manejaba los bloques sin resultado.

## Problemas encontrados (Juego 3)

- **Suite completa roja tras integrar el 10º juego**: `LimitesScopedApiTest` asumía 9 juegos
  sembrados. Resuelto actualizando los conteos (ver hallazgo). Es un ajuste legítimo de regresión.
- **Cambios ajenos del checkout compartido**: `backend/.env.example` y `panel/.astro/settings.json`
  (modificados) y `.atl/`, `.codegraph/`, `openspec/config.yaml` (sin seguimiento) no pertenecen al
  work unit; se dejaron fuera de los commits.

## Workload / PR Boundary (Juego 3)

- Modo: chained PR slice (feature-branch-chain, PR 4 de la cadena; base = PR 3 `feat/integracion-juegos-scrapers-f2-cazaloton`).
- Boundary: integración completa del juego 11 (seeder → fixture → tests → docs) con verificación
  incluida (suite completa 408/406/2 + pint limpio).
- Rollback boundary por unidad: ver tabla Work Unit Evidence (Juego 3).

## Siguiente paso recomendado

- Juego 12 (El Arrejuntado): requiere URL y estructura de la fuente del cliente antes de aplicar.
- `11f` — verificación funcional con URL real (`php artisan tinker` → fetch+parse) pendiente del
  cliente; no bloquea los siguientes juegos pero debe cerrarse antes de marcar Triple Chance como
  verificado con datos reales.
- `sdd-verify` del PR 4 cuando el orquestador lo dispare.

---

# Sección Juego 4 — El Arrejuntado (PR 5, tareas 12a–12f)

**Rama**: `feat/integracion-juegos-scrapers-f4-el-arrejuntado` (base: `feat/integracion-juegos-scrapers-f3-triple-chance`)
**Estado**: ✅ 12a–12e completadas; ⏳ 12f (verificación con URL real) pendiente del cliente

## Resumen

Integración del juego 12 (El Arrejuntado): seeder (slug `el-arrejuntado`, type `tripletas`,
`premio_multiplo` 30, límite default banca/bs/3600, plugin Tripletas, opciones de 12 signos,
horarios 10:00/13:00/16:00/19:00/23:00 (5), `scraper_url` de la API JSON de
serviciosintegradostriple7.com y `scraper_class` ElArrejuntaoScraper), scraper dedicado
`ElArrejuntaoScraper` (fetch del endpoint por fecha, parsea draws publicados, mapea las 6
modalidades a `numeros_ganadores`, fail-fast `findJuegoOrFail`, `normalizeHora` 12h→H:i,
`saveResults` heredado con dedupe), fixture real (snapshot del endpoint 2026-09-01) y tests
unit + feature.

## Decisión de mapeo (multi-modalidad)

La tabla del cliente registra El Arrejuntado con type `tripletas`; la fuente API expone 6
modalidades por draw (`animalito`, `el-arrimao`, `el-pegadito`, `triple-a`, `triple-b`,
`triple-signo`). El modelo `Resultado.numeros_ganadores` es un **array JSON flexible** (cast
`array`), por lo que se persiste CADA draw como UN resultado cuya `numeros_ganadores` conserva
**las 6 modalidades**:

- `triple-a` → `triple_a` ("894")
- `triple-b` → `triple_b` ("082")
- `triple-signo` "259 LEO" → `triple_c` ("259") + `signo` ("LEO") — se divide para ser
  compatible con el esquema tripletas que renderiza el panel (A/B/C + signo).
- `animalito` → `animalito` ("73"), `el-arrimao` → `arrimao` ("1825"), `el-pegadito` →
  `pegadito` ("10503") — modalidades adicionales conservadas en el mismo array (no consumidas
  por la renderización tripletas en esta iteración, pero persisten).

Esta decisión agota lo que el modelo actual soporta (array JSON flexible) SIN inventar tablas
nuevas. Si más adelante el sistema consume `animalito`/`arrimao`/`pegadito`, ya están
persistidos en `numeros_ganadores`.

## Hallazgo: conteos de juegos en LimitesScopedApiTest

Al registrar el undécimo juego en `DatabaseSeeder`, la matriz juego×moneda de `/api/v1/limites`
pasa de 10 a 11 juegos. Se actualizaron las aserciones de conteo (juegos 10→11,
límites/origen 20→22, scope de entidades 40→44, `mixto` 20→22) — comportamiento probado sin cambios.

## TDD Cycle Evidence (Juego 4)

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|-------|-----------|-------|------------|-----|-------|-------------|----------|
| 12b/12c/12d | `tests/Unit/ElArrejuntadoScraperTest.php` | Unit | ✅ TripleChance 14/14 | ✅ 7 errores (clase inexistente) | ✅ 7/7 | ✅ 7 casos (draws publicados, ignora no publicados, hora H:i, 6 modalidades, estructura, fail-fast, draw sin modalidades) | ✅ Pint clean |
| 12a/12d | `tests/Feature/ElArrejuntadoResultsTest.php` | Feature | ✅ idem | ✅ 6 errores (seeder inexistente) | ✅ 6/6 | ✅ 6 casos (juego+scraper_class, límite+plugin, 5 horarios, persistencia, dedupe, resolver) | ✅ Pint clean |
| 12d | `tests/Feature/LimitesScopedApiTest.php` | Feature | ✅ previo | N/A (ajuste conteos) | ✅ 30/30 | ✅ conteos 11/22/44 | ✅ |

**Test Summary (Juego 4)**: +13 tests (421 vs 408 baseline); suite completa 421/419/2 + pint limpio.

## Work Unit Evidence (Juego 4)

| Work unit | Focused test command y resultado | Runtime harness y resultado | Rollback boundary |
|-----------|----------------------------------|-----------------------------|-------------------|
| WU1 seeder+scraper+fixture | `composer test -- --filter=Arrejuntado` → 13/13 (51 assertions) | N/A — parseo contra fixture real (snapshot del endpoint 2026-09-01); fetch con URL real verificado (el endpoint respondió el snapshot); persisten 12f para datos reales del día | Eliminar `ElArrejuntadoSeeder` + `ElArrejuntaoScraper` + fixture + revertir `DatabaseSeeder` |
| WU2 feature persistencia | `composer test -- --filter=ElArrejuntadoResultsTest` → 6/6 | `php artisan tinker` → `new ElArrejuntaoScraper($juego)` + parse/saveResults contra fixture | Idem WU1 + filas `resultados` de el-arrejuntado |
| WU3 conteos LimitesScopedApiTest | `--filter=LimitesScopedApiTest` → 30/30 | N/A (regresión de API) | Revertir solo las aserciones de conteo (11→10, 22→20, 44→40) |

## Archivos cambiados (Juego 4)

| Archivo | Acción | Qué se hizo |
|---------|--------|-------------|
| `backend/database/seeders/ElArrejuntadoSeeder.php` | Create | Juego `el-arrejuntado` + JuegoLimite banca/bs/3600 + PluginJuego Tripletas + JuegoOpcion signos + JuegoHorario 10:00/13:00/16:00/19:00/23:00 (5) + `scraper_class` |
| `backend/app/Plugins/Scrapers/ElArrejuntaoScraper.php` | Create | Scraper dedicado (API JSON por fecha, draws publicados, 6 modalidades a `numeros_ganadores`, fail-fast, normalizeHora) |
| `backend/database/seeders/DatabaseSeeder.php` | Modify | Registra `ElArrejuntadoSeeder` |
| `backend/tests/Fixtures/elarrejuntao_results.json` | Create | Snapshot real del endpoint (2026-09-01: 1 draw publicado con 6 modalidades) |
| `backend/tests/Unit/ElArrejuntadoScraperTest.php` | Create | 7 tests unit (parseo, ignora no publicados, hora, 6 modalidades, estructura, fail-fast, draw sin modalidades) |
| `backend/tests/Feature/ElArrejuntadoResultsTest.php` | Create | 6 tests feature (seeder, límite+plugin, horarios, persistencia, dedupe, resolver) |
| `backend/tests/Feature/LimitesScopedApiTest.php` | Modify | Conteos de la matriz juego×moneda: 10→11 juegos, 20→22 límites/origen, 40→44 scope, mixto 20→22 |
| `backend/docs/juegos.md` | Modify | El Arrejuntado movido de pendientes a "Juegos integrados" + nota de la estructura multi-modalidad |
| `openspec/changes/integracion-juegos-scrapers/tasks.md` | Modify | 12a–12e marcadas `[x]`; 12f pendiente; fila 12 ✅ integrado (PR 5) |

## Desviaciones del diseño (Juego 4)

None — implementation matches design. El Arrejuntado usa el tipo `tripletas` existente (según la
tabla del cliente) con un scraper dedicado para su API JSON multi-modalidad; el modelo
`numeros_ganadores` (array JSON flexible) permite conservar las 6 modalidades sin tablas nuevas.

## Problemas encontrados (Juego 4)

- **Suite completa roja tras integrar el 11º juego**: `LimitesScopedApiTest` asumía 10 juegos
  sembrados. Resuelto actualizando los conteos (ver hallazgo). Es un ajuste legítimo de regresión.
- **Cambios ajenos del checkout compartido**: `backend/.env.example` y `panel/.astro/settings.json`
  (modificados) y `.atl/`, `.codegraph/`, `openspec/config.yaml` (sin seguimiento) no pertenecen al
  work unit; se dejaron fuera de los commits.
- **`ReflectionMethod::setAccessible()` deprecado en PHP 8.5**: warning pre-existente en el patrón de
  tests (sin efecto desde 8.1); no introducido por este WU.

## Workload / PR Boundary (Juego 4)

- Modo: chained PR slice (feature-branch-chain, PR 5 de la cadena; base = PR 4 `feat/integracion-juegos-scrapers-f3-triple-chance`).
- Boundary: integración completa del juego 12 (seeder → scraper → fixture → tests → docs) con
  verificación incluida (suite completa 421/419/2 + pint limpio). NO se abrieron PRs.
- Rollback boundary por unidad: ver tabla Work Unit Evidence (Juego 4).

## Siguiente paso recomendado

- Juego 13 (El Guacharito): requiere URL y estructura de la fuente del cliente antes de aplicar.
- `12f` — verificación funcional con URL real (`php artisan tinker` → fetch+parse) pendiente del
  cliente; no bloquea los siguientes juegos pero debe cerrarse antes de marcar El Arrejuntado como
  verificado con datos reales.
- `sdd-verify` del PR 5 cuando el orquestador lo dispare.

---

# Sección Juego 5 — El Guacharito Millonario (PR 6, tareas 13a–13f)

**Rama**: `feat/integracion-juegos-scrapers-f5-el-guacharito` (base: `feat/integracion-juegos-scrapers-f4-el-arrejuntado`)
**Estado**: ✅ 13a–13e completadas; ⏳ 13f (verificación con URL real) pendiente del cliente

## Resumen

Integración del juego 13 (El Guacharito Millonario): seeder (slug `el-guacharito`, type
`animalitos`, `premio_multiplo` 30, límite default banca/bs/3600, plugin Animalitos, horarios
08:30–19:30 (:30 cada hora, 12 sorteos/día), `scraper_url` de loteriadehoy.com y `scraper_class`
LoteriaDeHoyScraper), fixture real de la página de resultados, y tests unit+feature. NO requirió
cambio de código en el scraper: el juego usa el MISMO patrón que Cazaloton (type `animalitos`,
misma fuente loteriadehoy.com), por lo que `LoteriaDeHoyScraper::parseAnimalitos` lo cubre tal cual.

## Hallazgo: mismo patrón animalitos que Cazaloton

La página `https://loteriadehoy.com/animalito/elguacharitomillonario/resultados/` tiene la misma
estructura que Cazaloton: bloques de `div.js-con div.mb-5` con número + animal + hora (12h), y solo
renderiza los sorteos ya ocurridos del día (resultados parciales). El snapshot real del 2026-09-01
muestra 4 bloques (08:30, 09:30, 10:30 y 11:30) de los 12 horarios del día. Se verificó con el
snapshot real descargado que `parseAnimalitos` los parsea sin cambios de código.

## Hallazgo: conteos de juegos en LimitesScopedApiTest

Al registrar el duodécimo juego en `DatabaseSeeder`, la matriz juego×moneda de `/api/v1/limites`
pasa de 11 a 12 juegos. Se actualizaron las aserciones de conteo (juegos 11→12, límites/origen
22→24, scope de entidades 44→48, `mixto` 22→24) — comportamiento probado sin cambios.

## TDD Cycle Evidence (Juego 5)

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|-------|-----------|-------|------------|-----|-------|-------------|----------|
| 13b/13c/13d | `tests/Unit/ElGuacharitoScraperTest.php` | Unit | ✅ suite 421/419/2 | ✅ 0 fallos (parse ya cubierto; se verificó el fixture) | ✅ 7/7 | ✅ 7 casos (parcial, numero/animal, horas H:i, estructura, fail-fast, sin bloques, malformado) | ✅ Pint clean |
| 13a/13d | `tests/Feature/ElGuacharitoResultsTest.php` | Feature | ✅ idem | ✅ 6 errores (seeder inexistente) | ✅ 6/6 | ✅ 6 casos (juego+scraper_class, límite+plugin, 12 horarios, persistencia, dedupe, resolver) | ✅ Pint clean |
| 13d | `tests/Feature/LimitesScopedApiTest.php` | Feature | ✅ previo | N/A (ajuste conteos) | ✅ 30/30 | ✅ conteos 12/24/48 | ✅ |

**Test Summary (Juego 5)**: +13 tests (434 vs 421 baseline); suite completa 434/432/2 + pint limpio.

## Work Unit Evidence (Juego 5)

| Work unit | Focused test command y resultado | Runtime harness y resultado | Rollback boundary |
|-----------|----------------------------------|-----------------------------|-------------------|
| WU1 seeder+fixture | `composer test -- --filter=Guacharito` → 13/13 (45 assertions) | N/A — parseo contra fixture real (snapshot descargado del 2026-09-01, 4 bloques); fetch con URL real verificado (la URL respondió el snapshot); persisten 13f para datos reales del día | Eliminar `ElGuacharitoSeeder` + fixture + revertir `DatabaseSeeder` |
| WU2 feature persistencia | `composer test -- --filter=ElGuacharitoResultsTest` → 6/6 | `php artisan tinker` → `new LoteriaDeHoyScraper($juego)` + parse/saveResults contra fixture | Idem WU1 + filas `resultados` de el-guacharito |
| WU3 conteos LimitesScopedApiTest | `--filter=LimitesScopedApiTest` → 30/30 | N/A (regresión de API) | Revertir solo las aserciones de conteo (12→11, 24→22, 48→44) |

## Archivos cambiados (Juego 5)

| Archivo | Acción | Qué se hizo |
|---------|--------|-------------|
| `backend/database/seeders/ElGuacharitoSeeder.php` | Create | Juego `el-guacharito` + JuegoLimite banca/bs/3600 + PluginJuego Animalitos + JuegoHorario 08:30–19:30 (:30, 12) + `scraper_class` |
| `backend/database/seeders/DatabaseSeeder.php` | Modify | Registra `ElGuacharitoSeeder` |
| `backend/tests/Fixtures/loteriadehoy_elguacharito.html` | Create | Snapshot real de `https://loteriadehoy.com/animalito/elguacharitomillonario/resultados/` (2026-09-01: 4 bloques 08:30–11:30) |
| `backend/tests/Unit/ElGuacharitoScraperTest.php` | Create | 7 tests unit (parseo, horas, números/animales, estructura, fail-fast, sin bloques, malformado) |
| `backend/tests/Feature/ElGuacharitoResultsTest.php` | Create | 6 tests feature (seeder, límite+plugin, 12 horarios, persistencia, dedupe, resolver) |
| `backend/tests/Feature/LimitesScopedApiTest.php` | Modify | Conteos de la matriz juego×moneda: 11→12 juegos, 22→24 límites/origen, 44→48 scope, mixto 22→24 |
| `backend/docs/juegos.md` | Modify | El Guacharito Millonario movido de pendientes a "Juegos integrados" + removido de la tabla de pendientes |
| `openspec/changes/integracion-juegos-scrapers/tasks.md` | Modify | 13a–13e marcadas `[x]`; 13f pendiente; fila 13 ✅ integrado (PR 6) |

## Desviaciones del diseño (Juego 5)

None — implementation matches design. El Guacharito Millonario usa el tipo `animalitos` existente
(según la tabla del cliente y la URL de la fuente) con el `LoteriaDeHoyScraper::parseAnimalitos`
reutilizado (patrón Cazaloton). El slug `el-guacharito` sigue el patrón de slugs del sistema (nombre
del juego en kebab-case, sin el sufijo "millonario").

## Problemas encontrados (Juego 5)

- **Suite completa roja tras integrar el 12º juego**: `LimitesScopedApiTest` asumía 11 juegos
  sembrados. Resuelto actualizando los conteos (ver hallazgo). Es un ajuste legítimo de regresión.
- **Cambios ajenos del checkout compartido**: `backend/.env.example` y `panel/.astro/settings.json`
  (modificados) y `.atl/`, `.codegraph/`, `openspec/config.yaml` (sin seguimiento) no pertenecen al
  work unit; se dejaron fuera de los commits.

## Workload / PR Boundary (Juego 5)

- Modo: chained PR slice (feature-branch-chain, PR 6 de la cadena; base = PR 5 `feat/integracion-juegos-scrapers-f4-el-arrejuntado`).
- Boundary: integración completa del juego 13 (seeder → fixture → tests → docs) con verificación
  incluida (suite completa 434/432/2 + pint limpio). NO se abrieron PRs.
- Rollback boundary por unidad: ver tabla Work Unit Evidence (Juego 5).

## Siguiente paso recomendado

- Juego 14 (Guacharo Activo): requiere URL y estructura de la fuente del cliente antes de aplicar.
- `13f` — verificación funcional con URL real (`php artisan tinker` → fetch+parse) pendiente del
  cliente; no bloquea los siguientes juegos pero debe cerrarse antes de marcar El Guacharito
  Millonario como verificado con datos reales.
- `sdd-verify` del PR 6 cuando el orquestador lo dispare.
---

# Sección Juego 6 — Guacharo Activo (PR 7, tareas 14a–14f)

**Rama**: `feat/integracion-juegos-scrapers-f6-guacharo-activo` (base: `feat/integracion-juegos-scrapers-f5-el-guacharito`)
**Estado**: ✅ 14a–14e completadas; ⏳ 14f (verificación con URL real) pendiente del cliente

## Resumen

Integración del juego 14 (Guacharo Activo): seeder (slug `guacharo-activo`, type `animalitos`,
`premio_multiplo` 30, límite default banca/bs/3600, plugin Animalitos, horarios 08:00–19:00
(`:00` cada hora, 12 sorteos/día), `scraper_url` de loteriadehoy.com y `scraper_class`
LoteriaDeHoyScraper), fixture real de la página de resultados, y tests unit+feature. NO requirió
cambio de código en el scraper: el juego usa el MISMO patrón que Cazaloton y El Guacharito
(type `animalitos`, misma fuente loteriadehoy.com), por lo que
`LoteriaDeHoyScraper::parseAnimalitos` lo cubre tal cual.

## Hallazgo: mismo patrón animalitos que Cazaloton/El Guacharito

La página `https://loteriadehoy.com/animalito/guacharoactivo/resultados/` tiene la misma
estructura que Cazaloton y El Guacharito: bloques de `div.js-con div.mb-5` con número + animal +
hora (12h), y solo renderiza los sorteos ya ocurridos del día (resultados parciales). El snapshot
real descargado del 2026-09-01 muestra 5 bloques (08:00, 09:00, 10:00, 11:00 y 12:00) de los 12
horarios del día. Se verificó con el snapshot real descargado que `parseAnimalitos` los parsea
sin cambios de código.

## Hallazgo: conteos de juegos en LimitesScopedApiTest

Al registrar el decimotercer juego en `DatabaseSeeder`, la matriz juego×moneda de `/api/v1/limites`
pasa de 12 a 13 juegos. Se actualizaron las aserciones de conteo (juegos 12→13, límites/origen
24→26, scope de entidades 48→52, `mixto` 24→26) — comportamiento probado sin cambios.

## TDD Cycle Evidence (Juego 6)

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|-------|-----------|-------|------------|-----|-------|-------------|----------|
| 14b/14c/14d | `tests/Unit/GuacharoScraperTest.php` | Unit | ✅ suite 434/432/2 | ✅ 5 errores (fixture inexistente) | ✅ 7/7 | ✅ 7 casos (parcial, numero/animal, horas H:i, estructura, fail-fast, sin bloques, malformado) | ✅ Pint clean |
| 14a/14d | `tests/Feature/GuacharoResultsTest.php` | Feature | ✅ idem | ✅ 6 errores (seeder inexistente) | ✅ 6/6 | ✅ 6 casos (juego+scraper_class, límite+plugin, 12 horarios, persistencia, dedupe, resolver) | ✅ Pint clean |
| 14d | `tests/Feature/LimitesScopedApiTest.php` | Feature | ✅ previo | N/A (ajuste conteos) | ✅ 30/30 | ✅ conteos 13/26/52 | ✅ |

**Test Summary (Juego 6)**: +13 tests (447 vs 434 baseline); suite completa 447/445/2 + pint limpio.
Comando focused: `composer test -- --filter=Guacharo` → 13/13 (45 assertions).

## Work Unit Evidence (Juego 6)

| Work unit | Focused test command y resultado | Runtime harness y resultado | Rollback boundary |
|-----------|----------------------------------|-----------------------------|-------------------|
| WU1 seeder+fixture | `composer test -- --filter=Guacharo` → 13/13 (45 assertions) | N/A — parseo contra fixture real (snapshot descargado del 2026-09-01, 5 bloques); fetch con URL real verificado (la URL respondió el snapshot); persisten 14f para datos reales del día | Eliminar `GuacharoActivoSeeder` + fixture + revertir `DatabaseSeeder` |
| WU2 feature persistencia | `composer test -- --filter=GuacharoResultsTest` → 6/6 | `php artisan tinker` → `new LoteriaDeHoyScraper($juego)` + parse/saveResults contra fixture | Idem WU1 + filas `resultados` de guacharo-activo |
| WU3 conteos LimitesScopedApiTest | `--filter=LimitesScopedApiTest` → 30/30 (305 assertions) | N/A (regresión de API) | Revertir solo las aserciones de conteo (13→12, 26→24, 52→48) |

## Archivos cambiados (Juego 6)

| Archivo | Acción | Qué se hizo |
|---------|--------|-------------|
| `backend/database/seeders/GuacharoActivoSeeder.php` | Create | Juego `guacharo-activo` + JuegoLimite banca/bs/3600 + PluginJuego Animalitos + JuegoHorario 08:00–19:00 (`:00`, 12) + `scraper_class` |
| `backend/database/seeders/DatabaseSeeder.php` | Modify | Registra `GuacharoActivoSeeder` |
| `backend/tests/Fixtures/loteriadehoy_guacharo.html` | Create | Snapshot real de `https://loteriadehoy.com/animalito/guacharoactivo/resultados/` (2026-09-01: 5 bloques 08:00–12:00) |
| `backend/tests/Unit/GuacharoScraperTest.php` | Create | 7 tests unit (parseo, horas, números/animales, estructura, fail-fast, sin bloques, malformado) |
| `backend/tests/Feature/GuacharoResultsTest.php` | Create | 6 tests feature (seeder, límite+plugin, 12 horarios, persistencia, dedupe, resolver) |
| `backend/tests/Feature/LimitesScopedApiTest.php` | Modify | Conteos de la matriz juego×moneda: 12→13 juegos, 24→26 límites/origen, 48→52 scope, mixto 24→26 |
| `backend/docs/juegos.md` | Modify | Guacharo Activo movido de pendientes a "Juegos integrados" + removido de la tabla de pendientes |
| `openspec/changes/integracion-juegos-scrapers/tasks.md` | Modify | 14a–14e marcadas `[x]`; 14f pendiente; fila 14 ✅ integrado (PR 7) |
| `openspec/changes/integracion-juegos-scrapers/apply-progress.md` | Modify | Sección Juego 6 (este bloque) |

## Desviaciones del diseño (Juego 6)

None — implementation matches design. Guacharo Activo usa el tipo `animalitos` existente (según la
tabla del cliente y la URL de la fuente) con el `LoteriaDeHoyScraper::parseAnimalitos` reutilizado
(patrón Cazaloton/El Guacharito). El slug `guacharo-activo` sigue el patrón de slugs del sistema
(kebab-case del nombre).

## Problemas encontrados (Juego 6)

- **Suite completa roja tras integrar el 13º juego**: `LimitesScopedApiTest` asumía 12 juegos
  sembrados. Resuelto actualizando los conteos (ver hallazgo). Es un ajuste legítimo de regresión.
- **Cambios ajenos del checkout compartido**: `backend/.env.example` y `panel/.astro/settings.json`
  (modificados) y `.atl/`, `.codegraph/`, `openspec/config.yaml` (sin seguimiento) no pertenecen al
  work unit; se dejaron fuera de los commits.

## Workload / PR Boundary (Juego 6)

- Modo: chained PR slice (feature-branch-chain, PR 7 de la cadena; base = PR 6 `feat/integracion-juegos-scrapers-f5-el-guacharito`).
- Boundary: integración completa del juego 14 (seeder → fixture → tests → docs) con verificación
  incluida (suite completa 447/445/2 + pint limpio). NO se abrieron PRs.
- Rollback boundary por unidad: ver tabla Work Unit Evidence (Juego 6).

## Siguiente paso recomendado

- Juego 15 (La Granjita): requiere URL y estructura de la fuente del cliente antes de aplicar.
- `14f` — verificación funcional con URL real (`php artisan tinker` → fetch+parse) pendiente del
  cliente; no bloquea los siguientes juegos pero debe cerrarse antes de marcar Guacharo Activo
  como verificado con datos reales.
- `sdd-verify` del PR 7 cuando el orquestador lo dispare.

---

# Sección Triple Caliente — fuente oficial (PR 8, tareas 9g–9l) — ✅ COMPLETADO

**Rama**: `feat/integracion-juegos-scrapers-f7-tc-oficial` (base: `feat/integracion-juegos-scrapers-f6-guacharo-activo`)
**Estado**: ✅ 9g–9l completadas (migración de fuente de Triple Caliente al API oficial)

## Resumen

El scraper de Triple Caliente usaba `LoteriaDeHoyScraper` (HTML de loteriadehoy.com), BLOQUEADO por
el challenge de Cloudflare (verificado en local y VPS). Se descubrió el API oficial de
triplecaliente.com (`POST /api/gaming/results/product`, body `{"game_product_id":"4"}`, sin auth ni
anti-bot) y se migró el juego a la nueva fuente: nuevo `TripleCalienteOficialScraper`, seeder
actualizado, fixture real, tests RED→GREEN y docs. `LoteriaDeHoyScraper` NO se borra (sigue para
Cazaloton, Triple Chance, El Guacharito y Guacharo Activo, y como respaldo).

## Decisión: game_product_id

Convención documentada en el docblock de la clase y en `docs/juegos.md`: el `game_product_id` se lee
de `config['scraper']['product_id']` del juego registrado, con default la constante del scraper
`GAME_PRODUCT_ID = '4'`. El seeder registra `config => ['premio_multiplo' => 30, 'scraper' =>
['product_id' => '4']]`. Alternativas descartadas: config global `config('scraper.product_id')`
(no existe `config/scraper.php` en el proyecto; TripletasScraper usa fallback '2') y hardcodear '4'
en el scraper (menos configurable).

## Hallazgo: misma familia de API que Triple Zulia

`TripletasScraper` (legacy) ya consume el MISMO endpoint (`/api/gaming/results/product` con
`game_product_id`) contra resultadostriplezulia.com. Por eso el nuevo scraper sigue su patrón:
`execute` filtra el histórico devuelto por la API (los últimos N sorteos) a la fecha solicitada
antes de `saveResults`, y `sorteo_id_externo` usa el primer id del array `events` (ids únicos por
sorteo). Timestamps verificados: `1788304200` → 2026-09-01 19:10 America/Caracas (UTC-4), que
coincide con los horarios oficiales 13:00/16:30/19:10.

## Hallazgo: C incluye el signo (formato "589-ESC")

El campo C de la API oficial viene como `"589-ESC"` (número + guión + signo de 3 letras), igual que
el `triple-signo "259 LEO"` de ElArrejuntao pero con guión. El scraper divide en `triple_c` ("589")
+ `signo` ("ESC") con `splitTripleSigno` (regex `^(\d+)-([A-Za-z]+)$`), compatible con el esquema
tripletas que renderiza el panel.

## Hallazgo: seeder con updateOrCreate para migrar fuente

El juego ya existía en BD (integración PR 2). `firstOrCreate` no aplicaría el cambio de fuente sobre
la fila existente, así que el seeder pasa a `updateOrCreate(['slug'], [...])` — única diferencia
frente al patrón de los otros seeders, necesaria para que la migración de fuente sea idempotente y
aplique sobre el juego ya registrado (verificado en BD local: id=8, fuente oficial).

## TDD Cycle Evidence (Juego 9 fuente oficial)

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|-------|-----------|-------|------------|-----|-------|-------------|----------|
| 9g/9i/9j | `tests/Unit/TripleCalienteOficialScraperTest.php` | Unit | ✅ suite 475/473/2 + TripleCaliente 14/14 | ✅ 11 errores (clase no existe) | ✅ 11/11 (35 assertions) | ✅ 11 casos (parse 6, epoch→Caracas, A/B/C+signo×2, events, estructura, fail-fast, filtrarPorFecha×2 fechas, product_id config, JSON inválido, vacío) | ✅ Pint clean |
| 9h/9j | `tests/Feature/TripleCalienteResultsTest.php` | Feature | ✅ idem | ✅ 3 errores (URL/clase vieja) | ✅ 6/6 | ✅ 6 casos (seeder nueva fuente, límite+plugin, 3 horarios, persistencia 3 sorteos, dedupe, resolver) | ✅ Pint clean |
| 9k | N/A (docs) | Docs | ✅ | ➖ | ➖ | Omitida | ✅ |
| 9l | Runtime real | Harness | ✅ | ➖ | ✅ 3 sorteos persistidos | ✅ dedupe rescrape (sigue 3) | ➖ |

**Test Summary (Juego 9 fuente oficial)**: +11 tests (486 vs 475 baseline); suite completa 486/484/2
+ pint limpio. Comando focused: `composer test -- --filter=TripleCaliente` → 25/25 (92 assertions).

## Work Unit Evidence (Juego 9 fuente oficial)

| Work unit | Focused test command y resultado | Runtime harness y resultado | Rollback boundary |
|-----------|----------------------------------|-----------------------------|-------------------|
| WU1 scraper+fixture | `composer test -- --filter=TripleCalienteOficialScraperTest` → 11/11 (35 assertions) | `php artisan tinker` → `new TripleCalienteOficialScraper($juego)` + `execute('2026-09-01')` contra el API real → 3 resultados (13:00/16:30/19:10, eventos 132355/132396/132401) | Eliminar `TripleCalienteOficialScraper.php` + fixture + test unit |
| WU2 seeder+feature | `composer test -- --filter=TripleCalienteResultsTest` → 6/6 | `php artisan db:seed --class=TripleCalienteSeeder --force` → juego actualizado (fuente oficial); scraper real → `saveResults` persiste **3 sorteos** en `resultados`; rescrape mantiene 3 (dedupe upsert) | Revertir seeder a fuente loteriadehoy + revertir feature test |
| WU3 docs | N/A | N/A (docs) | Revertir solo la fila 9 y la nota en `docs/juegos.md` |

## Archivos cambiados (Juego 9 fuente oficial)

| Archivo | Acción | Qué se hizo |
|---------|--------|-------------|
| `backend/app/Plugins/Scrapers/TripleCalienteOficialScraper.php` | Create | Scraper del API oficial: POST `/api/gaming/results/product` con `game_product_id`, parse epoch→Caracas (fecha+hora), A/B/C+signo, `sorteo_id_externo`=events[0], `filtrarPorFecha`, fail-fast |
| `backend/database/seeders/TripleCalienteSeeder.php` | Modify | `updateOrCreate` + `scraper_url` API oficial + `scraper_class` TripleCalienteOficialScraper + `config['scraper']['product_id']='4'`; horarios intactos |
| `backend/tests/Fixtures/triplecaliente_oficial.json` | Create | Snapshot real del API oficial (2026-09-01/08-31, 6 sorteos: 013/511/589-ESC, 779/646/237-LIB, 758/073/439-ACU, 319/076/408-LEO, 514/634/062-ARI, 465/764/946-VIR) |
| `backend/tests/Unit/TripleCalienteOficialScraperTest.php` | Create | 11 tests unit (parse, horas/fechas Caracas desde epoch, A/B/C+signo con cero inicial, events, estructura, fail-fast, filtro por fecha, product_id config, JSON inválido/vacío) |
| `backend/tests/Feature/TripleCalienteResultsTest.php` | Modify | Nueva fuente en aserciones del seeder, persistencia con fixture JSON (3 sorteos), dedupe, resolver → TripleCalienteOficialScraper |
| `backend/tests/Feature/ResultadoAparicionesTest.php` | Modify | Newline final (pint --test, tarea 3.2); formateo puro pre-existente, sin lógica |
| `backend/docs/juegos.md` | Modify | Fila 9 → fuente API oficial + estado "verificado con datos reales" + nota del scraper y convención product_id |
| `openspec/changes/integracion-juegos-scrapers/tasks.md` | Modify | 9g–9l marcadas `[x]`; fila 9 "✅ integrado (PR 2) + fuente oficial (PR 8)" |
| `openspec/changes/integracion-juegos-scrapers/apply-progress.md` | Modify | Sección Triple Caliente fuente oficial añadida (merge) |

## Desviaciones del diseño (Juego 9 fuente oficial)

- Seeder `TripleCalienteSeeder` usa `updateOrCreate` en lugar de `firstOrCreate` (patrón de los
  demás seeders): necesario para que la migración de fuente aplique sobre el juego ya registrado.
  Única desviación; el resto del patrón (JuegoLimite, PluginJuego, JuegoOpcion, JuegoHorario) intacto.
- `TripleCalienteOficialScraper::execute` sobrescribe el `execute` de `BaseScraper` para filtrar el
  histórico por fecha (patrón `TripletasScraper`, misma familia de API): la API devuelve los últimos
  N sorteos, no solo los del día; sin el filtro, `saveResults` perseguiría fechas pasadas con la
  fecha del job (upsert incorrecto). Documentado en el docblock.

## Problemas encontrados (Juego 9 fuente oficial)

- **HOY (02-09) sin sorteos al momento de la carga real**: la ejecución fue a las 12:16 Caracas y el
  primer sorteo del día es 13:00; la API solo devuelve sorteos ya ocurridos. `execute('2026-09-02')`
  → 0 resultados (comportamiento correcto de resultados parciales, mismo patrón que loteriadehoy
  modo animalitos). La carga real se verificó con el último día completo disponible (2026-09-01).
- **pint tocó `ResultadoAparicionesTest.php`** (newline final pre-existente): se incluyó en un commit
  de estilo separado y documentado para mantener `pint --test` limpio (tarea 3.2).
- **Cambios ajenos del checkout compartido**: `backend/.env.example` y `panel/.astro/settings.json`
  (modificados) y `.atl/`, `.codegraph/`, `openspec/config.yaml` (sin seguimiento) quedaron fuera de
  los commits.

## Workload / PR Boundary (Juego 9 fuente oficial)

- Modo: chained PR slice (feature-branch-chain, PR 8 de la cadena; base = PR 7 `feat/integracion-juegos-scrapers-f6-guacharo-activo`).
- Boundary: migración completa de la fuente de Triple Caliente (scraper → seeder → fixture → tests →
  docs → carga real en BD local) con verificación incluida (suite completa 486/484/2 + pint limpio).
  NO se abrieron PRs.
- Rollback boundary por unidad: ver tabla Work Unit Evidence (Juego 9 fuente oficial).

## Siguiente paso recomendado

- Juego 15 (La Granjita): requiere URL y estructura de la fuente del cliente antes de aplicar.
- `9f` original (verificación con URL real de la fuente ANTERIOR) queda cerrado por sustitución:
  la nueva fuente se verificó con datos reales en este PR (3 sorteos persistidos en BD local).
- `sdd-verify` del PR 8 cuando el orquestador lo dispare.

---

# Sección Familia Lotto Activo — estabilización y datos reales (PR 9, WU f8) — ✅ COMPLETADO

**Rama**: `feat/integracion-juegos-scrapers-f8-lottoactivo` (base: `feat/integracion-juegos-scrapers-f7-tc-oficial`)
**Estado**: ✅ COMPLETADO — auditoría/limpieza de BD local, recarga real 12-sep (13 juegos), cobertura de tests terminal/trio/monje/RD con fixtures reales y docs.

## Resumen

Requisito del cliente: sin datos de seeder/tests en `resultados`. Se auditaron las 117 filas de la BD local (MySQL `lotto_db`), se identificaron y eliminaron las **32 filas DEMO** (creadas 2026-09-02 10:06:38/39, previas al batch real de scrape) y se verificó que las 85 filas restantes son 100 % de origen scraper (batch 2-sep 15:36–15:38 + 3 sorteos oficiales de Triple Caliente del 1-sep). Se ejecutó el batch en vivo del 12-sep-2026 (mismo mecanismo: `ScrapeResultsJob` por juego, secuencial): los 13 juegos corrieron SIN errores y se cargaron 27 resultados reales del día (parciales, 10:26 Caracas). La familia lottoactivo quedó **verificada con datos reales** y se amplió la cobertura de tests con fixtures reales de las rutas `terminal_activo`, `trio_activo` y el mapeo canónico monje/RD.

## Auditoría y limpieza de BD local (tarea 2)

### Evidencia pre-borrado (imprimida antes de eliminar; copia local en `backend/storage/app/auditoria_demo_antes_borrado.txt`, gitignored)

| Rango IDs | Juego | Filas | created_at | Firma |
|-----------|-------|-------|------------|-------|
| 1–18 | lotto-activo | 18 | 2026-09-02 10:06:38/39 | Valores repetidos (Tigre=23 a las 14:00 en 6 días; Ratón=7; León=15; Elefante=19; Halcón=31; Venado=42; Perico=5), patrón de 3 sorteos/día 10:00/12:00/14:00 vs. reales por hora 08:00–19:00 |
| 19–29 | triple-zulia | 11 | 2026-09-02 10:06:39 | 05/12/34 LEO y 21/08/34 TAU repetidos diariamente, 2 sorteos/día |
| 30–32 | terminal-activo | 3 | 2026-09-02 10:06:39 | `nombre_animal` presente (formato INCORRECTO para terminales; las reales solo traen `numero`), valores 42/7 copiados de los demos de lotto-activo |

**Eliminadas: 32 filas** (criterio: rango id 1–32 ∩ created_at 10:06:38/39). Sin otras filas sospechosas: el resto (85) proviene del batch real (12:33:55 triple-caliente oficial + 15:36:58–15:37:06 los 13 juegos).

### Re-auditoría post-limpieza (85 filas, 100 % scraper)

| slug | total | origen |
|------|-------|--------|
| lotto-activo / lotto-activo-rd / lotto-activo-rep-dom / monje-millonario | 8 c/u | batch 2-sep 15:36:58 |
| terminal-activo | 8 | batch 2-sep 15:36:59–15:37:00 |
| trio-activo | 8 | batch 2-sep 15:37:03 |
| triple-caliente | 4 | 3× 12:33:55 (1-sep, API oficial) + 1× 15:37:04 (2-sep 13:00) |
| cazaloton / triple-chance | 7 c/u | batch 2-sep 15:37:04 |
| el-arrejuntado | 2 | batch 2-sep 15:37:05 |
| el-guacharito / guacharo-activo | 8 c/u | batch 2-sep 15:37:05/06 |
| triple-zulia | 1 | batch 2-sep 15:36:59 (12:45) |
| **TOTAL** | **85** | Residuales 10:06: **0** |

### Seeders que generan filas demo en `resultados` (documentación, NO se borran)

- `ResultadoTestSeeder` — ÚNICO seeder que CREA filas en `resultados` (updateOrCreate de un resultado "perro" para slug `animalitos` y "123/456/789 LEO" para `triple-zulia`, fechados ayer). **NO está registrado en `DatabaseSeeder`** (uso manual/de test). Referencia el slug `animalitos`, que NO existe en la BD (el juego real es `lotto-activo`): el bloque se salta silenciosamente por el guard `if ($animalitos)`; el bloque de `triple-zulia` SÍ crearía una fila demo si alguien ejecuta el seeder a mano. Riesgo documentado.
- `TicketsGanadoresDemoSeeder` — SOLO LEE `resultados` (toma 10 con hora para crear tickets demo). No registrado en `DatabaseSeeder`. No contamina `resultados`.
- `ApuestaGanadoraSeeder` — SOLO LEE `Resultado` (para asociar apuestas). No registrado en `DatabaseSeeder`. No contamina `resultados`.
- Conclusión: `php artisan db:seed` (DatabaseSeeder) NO re-contamina `resultados`; el único riesgo es ejecutar `ResultadoTestSeeder` a mano.

## Verificación en vivo + recarga real 12-sep-2026 (tarea 3)

Mecanismo idéntico al batch del 2-sep: `new ScrapeResultsJob($juegoId, '2026-09-12')` → `handle()` por juego, secuencial (10:26 Caracas). Los 13 juegos corrieron SIN excepciones; log `laravel.log` sin `ERROR ScrapeResultsJob`.

| slug | resultados HOY (fecha 2026-09-12) | horas cargadas | notas |
|------|-----------------------------------|----------------|-------|
| lotto-activo | 3 | 08:00, 09:00, 10:00 AM | feed anidado trae los 4 juegos (11 guardados totales en el job) |
| lotto-activo-rd | 2 | 08:30, 09:30 AM | idem |
| lotto-activo-rep-dom | 3 | 08:00, 09:00, 10:00 AM | idem |
| monje-millonario | 3 | 08:05, 09:05, 10:05 AM | idem |
| terminal-activo | 3 | 08:00, 09:00, 10:00 AM | formato plano, solo `numero` |
| trio-activo | 3 | 08:00, 09:00, 10:00 AM | formato plano, solo `triple_a` |
| cazaloton | 2 | 09:00, 10:00 | |
| triple-chance | 2 | 09:00, 10:00 | |
| el-guacharito | 2 | 08:30, 09:30 | |
| guacharo-activo | 3 | 08:00, 09:00, 10:00 | |
| el-arrejuntado | 1 | 10:00 | |
| triple-zulia | 0 | — | "sin resultados" correcto: 1er sorteo del día aún no ocurre (parcial) |
| triple-caliente | 0 | — | idem: primer sorteo 13:00 (parcial) |
| **TOTAL HOY** | **27** | | TOTAL BD: 112 (85 previas + 27) |

Dedupe verificado: re-ejecución de lotto-activo y terminal-activo mantiene conteos (3/3) y total 112 (idempotente).

## TDD Cycle Evidence (tarea 4 — cobertura terminal/trio/monje/RD)

Cobertura previa: `AnimalitosScraperTest` solo cubría el formato ANIDADO (Lotto Activo/RD) y el token de la página `animalitos`. Las rutas `terminal_activo`/`trio_activo` (formato plano + rama de token con `fecha`) y el mapeo canónico de slugs (monje/RD) NO estaban cubiertas. Se capturaron fixtures REALES del 12-sep-2026 y se añadieron 5 tests.

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|-------|-----------|-------|------------|-----|-------|-------------|----------|
| token terminal/trio | `tests/Unit/AnimalitosScraperTest.php` | Unit | ✅ suite 486/484/2 | ✅ 2 errores (fixture inexistente) | ✅ 2/2 (tokens 148 chars reales) | ✅ 2 páginas reales | ➖ None needed |
| parse plano terminal | `tests/Unit/AnimalitosScraperTest.php` | Unit | ✅ idem | ✅ 1 error (fixture inexistente) | ✅ 1/1 | ✅ 3 sorteos + valores exactos (41/08:00 AM/id 5) | ➖ None needed |
| parse plano trio (tripletas) | `tests/Unit/AnimalitosScraperTest.php` | Unit | ✅ idem | ✅ 1 error (fixture inexistente) | ✅ 1/1 | ✅ 3 sorteos + valores exactos (941/08:00 AM/id 4) | ➖ None needed |
| feed anidado real monje/RD | `tests/Unit/AnimalitosScraperTest.php` | Unit | ✅ idem | ✅ 1 error (fixture inexistente) | ✅ 1/1 | ✅ 11 resultados × 4 juegos + paises + monje (Tiburon) | ➖ None needed |

**Test Summary (WU f8)**: +5 tests (491 vs 486 baseline); focused `composer test -- --filter=AnimalitosScraperTest` → 10/10 (69 assertions); suite completa **491/489/2** + pint limpio. Los tests son de cobertura/regresión sobre código EXISTENTE ya probado en vivo (el RED fue por fixtures inexistentes, no por código faltante).

## Work Unit Evidence (WU f8)

| Work unit | Focused test command y resultado | Runtime harness y resultado | Rollback boundary |
|-----------|----------------------------------|-----------------------------|-------------------|
| WU1 limpieza BD | N/A (datos, no código) | Query tinker: 117 filas → evidencia → delete 32 demo → re-auditoría 85 filas / 0 residuales | No aplica a código; datos locales no versionados |
| WU2 recarga real 12-sep | N/A (runtime) | `ScrapeResultsJob` × 13 juegos (10:26 Caracas): 27 resultados reales, 0 errores; dedupe verificado (112 total) | No aplica a código; filas `resultados` de 12-sep |
| WU3 tests+fixtures | `composer test -- --filter=AnimalitosScraperTest` → 10/10 (69 assertions) | Parse de fixtures reales capturados del sitio (12-sep) vía `AnimalitosScraper::fetch` (token real + POST process.php OK) | Eliminar los 5 fixtures `lottoactivo_*` + revertir `AnimalitosScraperTest` |
| WU4 docs | Suite completa 491/489/2 + `vendor/bin/pint --test` → passed | N/A (docs) | Revertir solo la sección familia en `docs/juegos.md` |

## Archivos cambiados (WU f8)

| Archivo | Acción | Qué se hizo |
|---------|--------|-------------|
| `backend/tests/Unit/AnimalitosScraperTest.php` | Modify | +5 tests (token terminal/trio, parse plano terminal, parse plano trio, feed anidado real monje/RD) y siembra de los 6 juegos de la familia en `setUp` |
| `backend/tests/Fixtures/lottoactivo_terminal_activo_page.html` | Create | Página real `https://www.lottoactivo.com/resultados/terminal_activo/2026-09-12/` (29.781 B) |
| `backend/tests/Fixtures/lottoactivo_terminal_activo_response.json` | Create | Respuesta real process.php formato plano (3 sorteos: 41/54/58) |
| `backend/tests/Fixtures/lottoactivo_trio_activo_page.html` | Create | Página real `https://www.lottoactivo.com/resultados/trio_activo/2026-09-12/` (29.757 B) |
| `backend/tests/Fixtures/lottoactivo_trio_activo_response.json` | Create | Respuesta real process.php formato plano (3 sorteos: 941/554/258) |
| `backend/tests/Fixtures/lottoactivo_animalitos_response.json` | Create | Feed real anidado (4 juegos: Lotto Activo/RD Internacional/República Dominicana/Monje, 11 sorteos) |
| `backend/docs/juegos.md` | Modify | Familia lottoactivo → estado "✅ Verificado con datos reales (12-sep)" + nota del feed anidado, formato plano y mapeo canónico de slugs |
| `openspec/changes/integracion-juegos-scrapers/tasks.md` | Modify | Sección WU f8 añadida con tareas `[x]` |
| `openspec/changes/integracion-juegos-scrapers/apply-progress.md` | Modify | Sección Familia Lotto Activo añadida (merge) |

## Desviaciones del diseño (WU f8)

None — implementation matches design. La limpieza de datos (requisito del cliente) es un work unit de DATOS, no de código: no altera seeders, migraciones ni clases; solo se documentó el riesgo de `ResultadoTestSeeder`.

## Problemas encontrados (WU f8)

- **Filas demo en BD local** (32): origen desconocido pero firma inequívoca (created_at 10:06:38/39 + valores repetidos + formato incorrecto en terminales). Eliminadas con evidencia; el mecanismo de scraper no las genera (los jobs escriben con la hora real del sorteo y formato correcto).
- **`ResultadoTestSeeder` referencia slug inexistente `animalitos`**: el bloque se salta silenciosamente; el bloque `triple-zulia` crearía una fila demo si se ejecuta a mano. No registrado en `DatabaseSeeder`; riesgo documentado, seeder NO borrado (regla del WU).
- **Warning pre-existente `ReflectionMethod::setAccessible()` deprecado (PHP 8.5)**: patrón heredado de los tests existentes; se conservó por consistencia (no es un fallo).
- **Cambios ajenos del checkout compartido**: `collections/*.yml`, `panel/.astro/settings.json` (modificados) y `.atl/`, `.codegraph/`, `openspec/config.yaml` (sin seguimiento) quedaron fuera de los commits.

## Workload / PR Boundary (WU f8)

- Modo: chained PR slice (feature-branch-chain, PR 9 de la cadena; base = PR 8 `feat/integracion-juegos-scrapers-f7-tc-oficial`).
- Boundary: estabilización de la familia Lotto Activo (limpieza BD local + recarga real 12-sep + cobertura terminal/trio/monje/RD + docs) con verificación incluida (suite completa 491/489/2 + pint limpio). NO se abrieron PRs.
- Rollback boundary por unidad: ver tabla Work Unit Evidence (WU f8).

## Siguiente paso recomendado

- `sdd-verify` del PR 9 cuando el orquestador lo dispare.
- Catálogo JSON de juegos (WU f9 siguiente, NO incluido en este WU por regla).
- Juego 15 (La Granjita): requiere URL y estructura de la fuente del cliente antes de aplicar.

---

# Sección WU f9 — Catálogo JSON para el front/taquilla (PR 10)

**Rama**: `feat/integracion-juegos-scrapers-f9-catalogo-json` (base: `feat/integracion-juegos-scrapers-f8-lottoactivo`)
**Estado**: ✅ COMPLETADO (f9.1–f9.6)

## Resumen

Entregable para el front/taquilla: `docs/juegos.json` (carpeta `docs/` RAÍZ del repo, junto a
`plugins.md`/`deploy.md`) con los 13 juegos (ids reales 1–13 de la BD local en orden del
`DatabaseSeeder`): id, slug, nombre, tipo, `premio_multiplo`, horarios (`H:i`, ordenados) y las
opciones/animales de cada juego. Se implementó el comando `php artisan juegos:export`
(`JuegosExportCommand`) con la lógica extraída a `App\Services\JuegoCatalogoService` (compartida
por comando y test). La resolución de opciones replica EXACTAMENTE `JuegoController::opciones`
(filas de `juego_opciones` → fallback al plugin vía `JuegoPluginManager`). Salida determinista e
idempotente (2 ejecuciones = mismo md5), pretty-print con acentos UTF-8 sin escapar
(`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT` + newline final).
El archivo `docs/juegos.json` se generó contra la BD local real y se commiteó.

**Contenido del archivo**: 13 juegos, **426 opciones** totales (animalitos 266, tripletas 60,
terminales 100): lotto-activo 38 (tabla, acentos correctos: "Delfín"); triple-zulia/
triple-caliente/triple-chance/el-arrejuntado 12 (tabla de signos); terminal-activo 100 (plugin
Terminales 00–99); trio-activo 12 (plugin Tripletas, "Géminis"); animalitos sin tabla (rd,
rep-dom, monje, cazaloton, el-guacharito, guacharo-activo) 38 c/u (plugin Animalitos).

## TDD Cycle Evidence (WU f9)

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|-------|-----------|-------|------------|-----|-------|-------------|----------|
| f9.1+f9.2 | `tests/Feature/JuegosJsonTest.php` | Feature (RefreshDatabase + DatabaseSeeder) | ✅ baseline 491/489/2 (orquestador) | ✅ 3 fallos (clase inexistente + archivo ausente) | ✅ 3/3 (309 assertions) | ✅ 3 tests: consistencia por slug, esquema/conteos (38/12/100/12), ids seeder | ✅ Pint aplicado y re-verde |
| f9.3+f9.4 | Harness real | Runtime | N/A (nuevo) | — | ✅ `php artisan juegos:export` → `docs/juegos.json` 68.788 B | ✅ `--path` alternativo = mismo contenido; idempotencia (md5 idéntico) | ✅ |

**Test Summary (WU f9)**: +3 tests (494 vs 491 baseline); focused
`composer test -- --filter=JuegosJsonTest` → **3/3 (309 assertions)**; suite completa
**494/492/2**; `vendor/bin/pint --test` → passed. Triangulación: rutas de opciones desde tabla
(lotto-activo/tripletas con tabla), desde plugin (terminales/trio/animalitos sin tabla),
normalización de hora (columna TIME `H:i:s` → `H:i`), orden y consecutividad de ids.

## Work Unit Evidence (WU f9)

| Work unit | Focused test command y resultado | Runtime harness y resultado | Rollback boundary |
|-----------|----------------------------------|-----------------------------|-------------------|
| WU1 servicio+comando | `composer test -- --filter=JuegosJsonTest` → RED 0/3 → GREEN 3/3 (309 assertions) | `php artisan juegos:export` → exit 0, archivo generado; `--path=/tmp/...` idéntico | Eliminar `JuegoCatalogoService.php` + `JuegosExportCommand.php` + `JuegosJsonTest.php` |
| WU2 contrato JSON | Suite completa 494/492/2 + pint limpio | Export contra BD local real (13 juegos ids 1-13) → `docs/juegos.json` 68.788 B; 2ª ejecución md5 idéntico | Eliminar `docs/juegos.json` (se regenera con el comando) |
| WU3 docs+persistencia | Suite completa 494/492/2 | N/A (docs) | Revertir nota en `docs/juegos.md` + secciones tasks/apply-progress |

## Archivos cambiados (WU f9)

| Archivo | Acción | Qué se hizo |
|---------|--------|-------------|
| `backend/app/Services/JuegoCatalogoService.php` | Create | Genera el catálogo (versión + juegos): opciones con semántica de `JuegoController::opciones` (tabla → plugin), horarios normalizados `H:i` y ordenados, `premio_multiplo` desde `config` |
| `backend/app/Console/Commands/JuegosExportCommand.php` | Create | `php artisan juegos:export` (default `base_path('../docs/juegos.json')`, `--path` opcional); JSON pretty-print + newline final |
| `backend/tests/Feature/JuegosJsonTest.php` | Create | 3 tests de consistencia (309 assertions): igualdad por slug con el archivo commiteado (id excluido), esquema mínimo + conteos por tipo (38/12/100/12), ids del archivo 1-13 + orden relativo/consecutividad del generado |
| `docs/juegos.json` (raíz repo) | Create | Entregable front/taquilla: 13 juegos, ids 1-13, 400 opciones, horarios, premio_multiplo |
| `backend/docs/juegos.md` | Modify | Sección "Contrato JSON para el front/taquilla" (regeneración con `php artisan juegos:export`, semántica de opciones, no editar a mano) |
| `openspec/changes/integracion-juegos-scrapers/tasks.md` | Modify | Sección WU f9 añadida con tareas `[x]` |
| `openspec/changes/integracion-juegos-scrapers/apply-progress.md` | Modify | Sección WU f9 añadida (merge) |

## Desviaciones del diseño (WU f9)

1. **El controller NO reutiliza el servicio** (decisión documentada): `JuegoController::opciones`
   devuelve el modelo `JuegoOpcion` completo (id, juego_id, imagen_url, color, metadata, active,
   timestamps) y cambiarlo al mapeo `{numero,label,value}` rompería el contrato API actual de
   panel/taquilla. El servicio se comparte entre comando y test (requisito del WU cumplido).
2. **Conteo de animales del plugin**: el prompt estimaba 37 animales canónicos, pero el plugin
   `Animalitos` tiene **38** (ballena y delfin comparten numero 0; 38 etiquetas). Evidencia:
   `JuegoAnimalitosSeeder` (38 filas), mapa del plugin (38 entradas), BD local (38). El archivo
   y el test usan 38 (semántica exacta del plugin, prioritaria según el WU).
3. **Ids del archivo vs ids del test**: el archivo mantiene los ids reales 1-13 (contrato); el
   test compara por slug excluyendo el id (ver Problemas, punto 1).

## Problemas encontrados (WU f9)

1. **Ids corridos en la BD de tests (evidencia: corrida 27-39)**: `RefreshDatabase` envuelve cada
   test en una transacción que se revierte, PERO el auto-increment de MySQL/InnoDB NO retrocede
   con el rollback (evidencia directa: tras 2 corridas filtradas el contador quedó en 40 con 0
   filas commiteadas). En una suite compartida, los 13 juegos sembrados por `DatabaseSeeder`
   reciben ids desplazados según cuántos juegos hayan creado antes otros tests (1-13, 14-26,
   27-39...). RESUELTO sin romper el contrato: la comparación de consistencia es estable por
   slug y valores (id excluido), el archivo conserva ids 1-13 (contrato front), y el test
   verifica que el generado sigue el MISMO orden relativo del seeder con ids estrictamente
   consecutivos (sin huecos ni juegos extra). El test es independiente del orden de ejecución
   (verificado dentro de la suite completa 494/492/2).
2. **Cambios ajenos del checkout compartido**: `collections/*.yml`, `panel/.astro/settings.json`
   (modificados) y `.atl/`, `.codegraph/`, `openspec/config.yaml` (sin seguimiento) quedaron
   fuera de los commits (NO se tocan ni se commitean).

## Workload / PR Boundary (WU f9)

- Modo: chained PR slice (feature-branch-chain, PR 10 de la cadena; base = PR 9 `feat/integracion-juegos-scrapers-f8-lottoactivo`). NO se abrieron PRs.
- Boundary: catálogo JSON para el front (servicio + comando + contrato `docs/juegos.json` + test de consistencia + docs) con verificación incluida (suite completa 494/492/2 + pint limpio).
- Rollback boundary por unidad: ver tabla Work Unit Evidence (WU f9).

## Siguiente paso recomendado

- `sdd-verify` del PR 10 cuando el orquestador lo dispare.
- Juego 15 (La Granjita): requiere URL y estructura de la fuente del cliente antes de aplicar.
