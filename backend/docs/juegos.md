# Catálogo de juegos (backend)

Lista maestra de juegos del backend. Es la fuente de referencia única: cada juego integrado
debe reflejarse aquí en el MISMO work unit en que se implementa (seeder, scraper, tests),
y los seeders materializan los datos que esta lista documenta (slug, type, fuente).

## Juegos actuales (7)

| # | Nombre | slug | type | Horarios (juego_horarios) | Fuente scraper | Clase scraper | Estado |
|---|--------|------|------|---------------------------|----------------|---------------|--------|
| 1 | Lotto Activo | `lotto-activo` | animalitos | 08:00–19:00 (cada hora `:00`) | `https://www.lottoactivo.com/resultados/animalitos/` | `AnimalitosScraper` | Activo |
| 2 | Triple Zulia | `triple-zulia` | tripletas | 12:45, 16:45, 19:05 | `https://resultadostriplezulia.com/` | `TripletasScraper` | Activo |
| 3 | Terminal Activo | `terminal-activo` | terminales | 08:00–19:00 (cada hora `:00`) | `https://www.lottoactivo.com/resultados/terminal_activo/` | `AnimalitosScraper` (vía URL) | Activo |
| 4 | Lotto Activo RD Internacional | `lotto-activo-rd` | animalitos | 08:30–19:30 (cada hora `:30`) | `https://www.lottoactivo.com/resultados/animalitos/` | `AnimalitosScraper` | Activo |
| 5 | Lotto Activo República Dominicana | `lotto-activo-rep-dom` | animalitos | 08:00–21:00 (cada hora `:00`) | `https://www.lottoactivo.com/resultados/animalitos/` | `AnimalitosScraper` | Activo |
| 6 | Monje Millonario | `monje-millonario` | animalitos | 08:05–19:05 (cada hora `:05`) | `https://www.lottoactivo.com/resultados/animalitos/` | `AnimalitosScraper` | Activo |
| 7 | Trío Activo | `trio-activo` | tripletas | 08:00–19:00 (cada hora `:00`) | `https://www.lottoactivo.com/resultados/trio_activo/` | `AnimalitosScraper` (vía URL) | Activo |

> Nota de resolución de scraper: la clase se resuelve en orden `juegos.scraper_class` →
> match de URL (`lottoactivo.com` / `triplezulia`) → convención `{Studly(type)}Scraper`.
> Los juegos 3 y 7 (type `terminales`/`tripletas`) usan `AnimalitosScraper` porque su fuente
> es lottoactivo; el match de URL prevalece sobre la convención por type.

## Hueco #8

| # | Nombre | slug | type | Horarios | Fuente | Clase scraper | Estado |
|---|--------|------|------|----------|--------|---------------|--------|
| 8 | *(por confirmar con el cliente)* | — | — | — | — | — | — |

La lista salta del 7 al 9: el hueco `#8` se resuelve al integrar el juego 9 (decisión del cliente).

## Juegos integrados (nuevos)

| # | Nombre | slug | type | Horarios (juego_horarios) | Fuente scraper | Clase scraper | Estado |
|---|--------|------|------|---------------------------|----------------|---------------|--------|
| 9 | Triple Caliente | `triple-caliente` | tripletas | 13:00, 16:30, 19:10 | `https://loteriadehoy.com/loteria/triplecaliente/resultados/` | `LoteriaDeHoyScraper` | Verificado con fixture (verificación con datos reales pendiente, cliente) |
| 10 | Cazaloton | `cazaloton` | animalitos | 09:00–19:00 (11 horarios `:00`) | `https://loteriadehoy.com/animalito/cazaloton/resultados/` | `LoteriaDeHoyScraper` | Verificado con fixture (verificación con datos reales pendiente, cliente) |

> `LoteriaDeHoyScraper` es parametrizado: reutiliza el mismo `scraper_class` para los juegos de
> loteriadehoy.com registrando la `scraper_url` de cada juego (se usa su slug/name para fail-fast
> y su URL para fetch). Formato soportado según type: tabla de resultados de triples
> (`table.resultados`) para `tripletas`, y bloques de número + animal + hora (`div.js-con`) para
> `animalitos`. En modo animalitos la página solo renderiza los sorteos ya ocurridos del día, por
> lo que el scraper maneja resultados parciales (los bloques presentes, sin asumir el total).

## Juegos pendientes (10–22)

Pendientes de integración (un work unit por juego, orden de URLs del cliente). Se agregarán
aquí en su mismo work unit:

| # | Nombre | slug | type (fuente) | Notas |
|---|--------|------|---------------|-------|
| 11 | Triple Chance | `triple-chance` | tripletas (API productId) | |
| 12 | El Arrejuntado | `el-arrejuntado` | según URL cliente | |
| 13 | El Guacharito | `el-guacharito` | según URL cliente | |
| 14 | Guacharo Activo | `guacharo-activo` | según URL cliente | |
| 15 | La Granjita | `la-granjita` | según URL cliente | |
| 16 | La Ricachona | `la-ricachona` | según URL cliente | |
| 17 | Loto Chaima | `loto-chaima` | según URL cliente | |
| 18 | Mega Animal 40 | `mega-animal-40` | animalitos (lottoactivo) | |
| 19 | Selva Plus | `selva-plus` | según URL cliente | |
| 20 | Triple Tachira | `triple-tachira` | tripletas (API productId) | |
| 21 | Triple Facil | `triple-facil` | tripletas/terminales | **Condicional** (doble modalidad, decisión del cliente) |
| 22 | Triple Zamorano | `triple-zamorano` | tripletas (API productId) | |

## Estrategia de tests

- Por juego: `JuegoXxxScraperTest` (unit, parse con fixture real) + `JuegoXxxResultsTest`
  (feature, `saveResults` + dedupe). Ejecución aislada: `composer test -- --filter=Xxx`.
- Suite general completa: se ejecuta al completar **10 juegos integrados** (criterio del cliente).
- Regresión del resolutor y fail-fast: `ScraperResolverTest` (scraper_class autoritativo,
  fallback URL → convención, clase inexistente → null, juego no registrado → excepción sin crear filas).

## Reglas de integración

- Cada juego nuevo se registra con su seeder (`Juego` + `JuegoLimite` + `PluginJuego` +
  `JuegoOpcion` + `JuegoHorario`), su clase scraper (solo `fetch` + `parse` + constructor),
  fixture real y tests, y su fila en esta lista — todo en el mismo work unit.
- Ningún scraper crea juegos en caliente: `findJuegoOrFail` lanza si el juego no está registrado.
- Los scrapers nuevos normalizan `hora_sorteo` a `H:i` (America/Caracas) vía `normalizeHora`.