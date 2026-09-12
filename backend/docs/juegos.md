# Catálogo de juegos (backend)

Lista maestra de juegos del backend. Es la fuente de referencia única: cada juego integrado
debe reflejarse aquí en el MISMO work unit en que se implementa (seeder, scraper, tests),
y los seeders materializan los datos que esta lista documenta (slug, type, fuente).

## Juegos actuales (7)

| # | Nombre | slug | type | Horarios (juego_horarios) | Fuente scraper | Clase scraper | Estado |
|---|--------|------|------|---------------------------|----------------|---------------|--------|
| 1 | Lotto Activo | `lotto-activo` | animalitos | 08:00–19:00 (cada hora `:00`) | `https://www.lottoactivo.com/resultados/animalitos/` | `AnimalitosScraper` | ✅ Verificado con datos reales (12-sep) |
| 2 | Triple Zulia | `triple-zulia` | tripletas | 12:45, 16:45, 19:05 | `https://resultadostriplezulia.com/` | `TripletasScraper` | ✅ Verificado con datos reales (12-sep) |
| 3 | Terminal Activo | `terminal-activo` | terminales | 08:00–19:00 (cada hora `:00`) | `https://www.lottoactivo.com/resultados/terminal_activo/` | `AnimalitosScraper` (vía URL, formato plano) | ✅ Verificado con datos reales (12-sep) |
| 4 | Lotto Activo RD Internacional | `lotto-activo-rd` | animalitos | 08:30–19:30 (cada hora `:30`) | `https://www.lottoactivo.com/resultados/animalitos/` | `AnimalitosScraper` | ✅ Verificado con datos reales (12-sep) |
| 5 | Lotto Activo República Dominicana | `lotto-activo-rep-dom` | animalitos | 08:00–21:00 (cada hora `:00`) | `https://www.lottoactivo.com/resultados/animalitos/` | `AnimalitosScraper` | ✅ Verificado con datos reales (12-sep) |
| 6 | Monje Millonario | `monje-millonario` | animalitos | 08:05–19:05 (cada hora `:05`) | `https://www.lottoactivo.com/resultados/animalitos/` | `AnimalitosScraper` | ✅ Verificado con datos reales (12-sep) |
| 7 | Trío Activo | `trio-activo` | tripletas | 08:00–19:00 (cada hora `:00`) | `https://www.lottoactivo.com/resultados/trio_activo/` | `AnimalitosScraper` (vía URL, formato plano) | ✅ Verificado con datos reales (12-sep) |

> Nota de resolución de scraper: la clase se resuelve en orden `juegos.scraper_class` →
> match de URL (`lottoactivo.com` / `triplezulia`) → convención `{Studly(type)}Scraper`.
> Los juegos 3 y 7 (type `terminales`/`tripletas`) usan `AnimalitosScraper` porque su fuente
> es lottoactivo; el match de URL prevalece sobre la convención por type.

> Familia lottoactivo — verificación con datos reales (12-sep-2026): los 7 juegos se verificaron
> en vivo contra `lottoactivo.com` (batch `ScrapeResultsJob` por juego, 13 juegos en total, sin
> errores). El feed de `/resultados/animalitos/<fecha>/` es un JSON anidado que incluye LOS CUATRO
> juegos animalitos (Lotto Activo, Lotto Activo RD Internacional, Lotto Activo República Dominicana
> y "Lotto Activo 2 (Monje Millonario)") en una sola respuesta: `AnimalitosScraper` mapea el nombre
> de cada juego a su slug canónico (`lotto-activo-rd-internacional` → `lotto-activo-rd`,
> `lotto-activo-republica-dominicana` → `lotto-activo-rep-dom`,
> `lotto-activo-2-monje-millonario` → `monje-millonario`) y persiste cada uno en su fila con dedupe
> por juego+fecha+hora. `/resultados/terminal_activo/` y `/resultados/trio_activo/` devuelven un
> formato PLANO (`resultado1..resultado4`, `time_s`, `fecha`, `id`) que se mapea a `numero` (terminales)
> o `triple_a` (tripletas); las etiquetas `[TerminalActivoScraper]`/`[TrioActivoScraper]` de los logs
> son dinámicas del mismo `AnimalitosScraper` (nombre derivado del slug), no clases aparte. Los
> fixtures reales `tests/Fixtures/lottoactivo_*` (capturados el 12-sep-2026) cubren ambas rutas y el
> mapeo de slugs en `AnimalitosScraperTest`.

## Hueco #8

| # | Nombre | slug | type | Horarios | Fuente | Clase scraper | Estado |
|---|--------|------|------|----------|--------|---------------|--------|
| 8 | *(por confirmar con el cliente)* | — | — | — | — | — | — |

La lista salta del 7 al 9: el hueco `#8` se resuelve al integrar el juego 9 (decisión del cliente).

## Juegos integrados (nuevos)

| # | Nombre | slug | type | Horarios (juego_horarios) | Fuente scraper | Clase scraper | Estado |
|---|--------|------|------|---------------------------|----------------|---------------|--------|
| 9 | Triple Caliente | `triple-caliente` | tripletas | 13:00, 16:30, 19:10 | `https://triplecaliente.com/api/gaming/results/product` (API oficial) | `TripleCalienteOficialScraper` | ✅ Verificado con datos reales (API oficial, sin anti-bot) |
| 10 | Cazaloton | `cazaloton` | animalitos | 09:00–19:00 (11 horarios `:00`) | `https://loteriadehoy.com/animalito/cazaloton/resultados/` | `LoteriaDeHoyScraper` | Verificado con fixture (verificación con datos reales pendiente, cliente) |
| 11 | Triple Chance | `triple-chance` | tripletas | 09:00–19:00 (11 horarios `:00`) | `https://loteriadehoy.com/loteria/triplechance/resultados/` | `LoteriaDeHoyScraper` | Verificado con fixture (verificación con datos reales pendiente, cliente) |
| 12 | El Arrejuntado | `el-arrejuntado` | tripletas | 10:00, 13:00, 16:00, 19:00, 23:00 (5 horarios) | `https://backend.serviciosintegradostriple7.com/api/v1/products/el-arrejuntao/results/` | `ElArrejuntaoScraper` | Verificado con fixture (verificación con datos reales pendiente, cliente) |
| 13 | El Guacharito Millonario | `el-guacharito` | animalitos | 08:30–19:30 (12 horarios `:30`) | `https://loteriadehoy.com/animalito/elguacharitomillonario/resultados/` | `LoteriaDeHoyScraper` | Verificado con fixture (verificación con datos reales pendiente, cliente) |
| 14 | Guacharo Activo | `guacharo-activo` | animalitos | 08:00–19:00 (12 horarios `:00`) | `https://loteriadehoy.com/animalito/guacharoactivo/resultados/` | `LoteriaDeHoyScraper` | Verificado con fixture (verificación con datos reales pendiente, cliente) |
| 15 | La Granjita | `la-granjita` | animalitos | 08:00–19:00 (12 horarios `:00`) | `https://www.lagranjita.com/api/results.json?productId=1` (API oficial) | `LaGranjitaScraper` | ✅ Verificado con datos reales (12-sep, API oficial sin anti-bot) |

> `LoteriaDeHoyScraper` es parametrizado: reutiliza el mismo `scraper_class` para los juegos de
> loteriadehoy.com registrando la `scraper_url` de cada juego (se usa su slug/name para fail-fast
> y su URL para fetch). Formato soportado según type: tabla de resultados de triples
> (`table.resultados`) para `tripletas`, y bloques de número + animal + hora (`div.js-con`) para
> `animalitos`. En modo animalitos la página solo renderiza los sorteos ya ocurridos del día, por
> lo que el scraper maneja resultados parciales (los bloques presentes, sin asumir el total).
> En modo tripletas la página de algunos juegos (p. ej. Triple Chance) lista los bloques de horario
> del día y solo los ya sorteados traen A/B/C; los horarios futuros aparecen como filas de hora sin
> resultado, que el scraper ignora (no genera resultado vacío ni error).

> `ElArrejuntaoScraper` consume la API JSON de serviciosintegradostriple7.com (endpoint por fecha).
> Cada draw publicado (`is_published=true`) trae 6 modalidades: `animalito`, `el-arrimao`,
> `el-pegadito`, `triple-a`, `triple-b` y `triple-signo`. El juego se registra con type `tripletas`
> (según la tabla del cliente) y cada draw se persiste como UN resultado cuya `numeros_ganadores`
> (array JSON flexible) conserva las 6 modalidades: `triple-a` → `triple_a`, `triple-b` → `triple_b`,
> y `triple-signo` ("259 LEO") se divide en `triple_c` ("259") + `signo` ("LEO") para ser compatible
> con el esquema tripletas que renderiza el panel; `animalito`, `arrimao` y `pegadito` se conservan
> en el mismo array (modalidades adicionales no consumidas por la renderización tripletas en esta
> iteración).

> `TripleCalienteOficialScraper` consume la API oficial de triplecaliente.com (POST
> `/api/gaming/results/product`, body `{"game_product_id":"4"}`, sin auth ni anti-bot). Sustituye a
> `LoteriaDeHoyScraper` para Triple Caliente porque loteriadehoy.com quedó bloqueado por el challenge
> de Cloudflare. La API devuelve el histórico de sorteos (los últimos N), cada uno con 3 `events`
> (ids únicos), `results` A/B/C (C incluye signo, p. ej. `589-ESC`) y `event_timestamp.seconds`
> (epoch). El scraper deriva `fecha_sorteo`/`hora_sorteo` locales en America/Caracas (UTC-4) desde el
> timestamp, mapea A/B/C+signo al esquema tripletas, usa el primer `event` como `sorteo_id_externo`,
> y `execute` filtra el histórico a la fecha solicitada (patrón `TripletasScraper`, misma familia de
> API). El `game_product_id` se lee de `config['scraper']['product_id']` del juego (default `'4'`,
> constante del scraper) — documentado en el docblock de la clase. `LoteriaDeHoyScraper` se conserva
> para los demás juegos de loteriadehoy.com (Cazaloton, Triple Chance, El Guacharito, Guacharo Activo)
> y como respaldo.

> `LaGranjitaScraper` consume la API oficial de lagranjita.com (GET
> `/api/results.json?date=YYYY-MM-DD&productId=1`, sin auth ni anti-bot; soporta
> fechas actuales y pasadas). La respuesta es un objeto cuya clave es el nombre
> del producto (`"LA GRANJITA"`) con un array de sorteos por valor (uno por
> horario del día, 12 en total): el scraper toma el PRIMER valor del objeto sin
> hardcodear la clave. Los sorteos NO ocurridos llegan con `result_id: null`
> (resto de campos null) y se saltan (resultados parciales del día, patrón
> loteriadehoy modo animalitos); `result_id` es único por sorteo y se usa como
> `sorteo_id_externo` (dedupe por juego+fecha+hora en `saveResults`).
> `result_value` es el número del animal y `result_name` su nombre (GALLINA=25,
> RATON=8, MONO=13, LAPA=31...), coincidiendo con el zoológico canónico del
> plugin Animalitos; `lotery_hour` ("08:00 AM") se normaliza a `H:i` con
> `normalizeHora`. El `product_id` se lee de `config['scraper']['product_id']`
> del juego (default `'1'`, constante del scraper). El portal lagranjita.com
> aloja OTROS productos fuera de alcance (documentación): pid=2 ZOOLOGICO
> ACTIVO, pid=3 RULETA ACTIVA, pid=4 LOTTOMAX, pid=5 LOTTO ACTIVO, pid=6 GRANJA
> MILLONARIA, pid=7 JUNGLA MILLONARIA, pid=8 LOTTO REY; y las páginas
> `/granjitaplus` (GRANJITA PLUS) y `/terminalgranjita` (TERMINAL LA GRANJITA).
> El seeder registra la `scraper_url` documental con `?productId=1`; el fetch
> reconstruye la query real (`date` + `productId` de config) ignorando la query
> documental.

## Juegos pendientes (16–22)

Pendientes de integración (un work unit por juego, orden de URLs del cliente). Se agregarán
aquí en su mismo work unit:

| # | Nombre | slug | type (fuente) | Notas |
|---|--------|------|---------------|-------|
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

## Contrato JSON para el front/taquilla (`docs/juegos.json`)

> `docs/juegos.json` (en la RAÍZ del repo, junto a `plugins.md`/`deploy.md`) es el contrato
> para el front/taquilla: los juegos integrados + existentes con su **id real de BD**, slug,
> nombre, tipo (animalitos/tripletas/terminales), `premio_multiplo`, horarios y las
> opciones/animales que permite cada juego. Se regenera con:
>
> ```bash
> php artisan juegos:export          # escribe docs/juegos.json en la raíz del repo
> php artisan juegos:export --path=/ruta/alternativa.json
> ```
>
> La salida es determinista e idempotente (correr dos veces = mismo archivo): orden por id
> ascendente, horarios normalizados a `H:i` y ordenados, pretty-print con acentos UTF-8 sin
> escapar (`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT`).
> La resolución de opciones replica EXACTAMENTE `JuegoController::opciones`: filas de
> `juego_opciones` si existen (lotto-activo 38 animales; triple-zulia/triple-caliente/
> triple-chance/el-arrejuntado 12 signos), si no, fallback al plugin vía
> `JuegoPluginManager` (terminal-activo 100 números 00-99 vía Terminales; trio-activo 12
> signos vía Tripletas; animalitos sin tabla — rd, rep-dom, monje, cazaloton, el-guacharito,
> guacharo-activo — 38 animales canónicos vía Animalitos). NO editar el archivo a mano:
> regenerarlo con el comando. La lógica vive en `App\Services\JuegoCatalogoService`
> (compartida por el comando y el test de consistencia `JuegosJsonTest`).