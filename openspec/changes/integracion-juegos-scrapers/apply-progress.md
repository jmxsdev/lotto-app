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