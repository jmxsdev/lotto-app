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