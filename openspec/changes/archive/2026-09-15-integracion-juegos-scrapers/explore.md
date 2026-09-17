# Exploración — Integración de juegos + scrapers (incremental)

## Contexto y requisito

El cliente quiere integrar una **lista de juegos** al sistema (tabla con: nombre del juego, tipo, horarios de sorteos). Para cada juego se creará un **scraper de resultados**, y el cliente irá pasando las URLs juego por juego, **un juego a la vez**, verificando cada scraper antes de continuar.

Alcance de esta exploración: SOLO `backend/`. El frontend `panel/` lo trabaja otro agente — no se toca.

## Estado actual

### 1. Modelo de juegos

Tabla `juegos` (migración `2026_07_10_035350_create_juegos_table` + ajustes posteriores):

| Campo | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | Nombre visible |
| `slug` | string unique | Identificador canónico |
| `type` | string | Hoy: `animalitos`, `tripletas`, `terminales` |
| `config` | json nullable | `premio_multiplo`, `modalidades_permitidas`, etc. |
| `requires_scraper` | boolean | Indica si el juego scrapea resultados |
| `scraper_url` | string nullable | URL de la fuente (se usa para RESOLVER el scraper) |
| `active` | boolean | |
| `updated_by` | FK users nullable | Auditoría (migración 2026_07_25) |
| timestamps | | |

Tablas relacionadas:

- `plugin_juegos` — `juego_id`, `class_namespace` (plugin `App\Plugins\Juegos\*`), `version`, `active`, `updated_by`. Mapea juego → clase que implementa `JuegoInterface` (validación de apuestas, premios, reglas, opciones, horarios fallback, multiplicador).
- `juego_horarios` — `juego_id`, `hora` (time), `active`, `unique(juego_id, hora)`. Horarios de sorteo por juego.
- `juego_opciones` — `juego_id`, `label`, `value`, `numero`, `imagen_url`, `color`, `metadata`, `active`, `sort_order`. Opciones jugables (38 animales, 12 signos).
- `juego_limites` — `juego_id`, `banca_id`/`grupo_id`/`taquilla_id` (nivel), `moneda` (bs/usd), `limite_minimo`, `limite_maximo`, `porcentaje_pago`, `participacion`, `fraccion`, `limite_tiempo`.
- `juego_auditoria` — bitácora de activar/desactivar/actualizar.
- `resultados` — `juego_id`, `fecha_sorteo` (timestamp), `hora_sorteo` (string, formato variable "10:00 AM" | "H:i"), `numeros_ganadores` (json), `sorteo_id_externo`, `premios_detalle` (json), `unique(juego_id, fecha_sorteo, hora_sorteo)` (migración 2026_07_24).

### 2. Juegos actuales del sistema (fuente: seeders)

| Juego | slug | type | plugin | Scraper (URL) | Horarios | Config |
|---|---|---|---|---|---|---|
| Lotto Activo | `lotto-activo` | animalitos | Animalitos | lottoactivo.com/resultados/animalitos/ | 08:00–19:00 (12) | premio_multiplo 30 |
| Triple Zulia | `triple-zulia` | tripletas | Tripletas | resultadostriplezulia.com (API, productId 2) | 12:45, 16:45, 19:05 | premio_multiplo 30 |
| Terminal Activo | `terminal-activo` | terminales | Terminales | lottoactivo.com/resultados/terminal_activo/ | 08:00–19:00 (12) | premio_multiplo 20 |
| Trío Activo | `trio-activo` | tripletas | Tripletas | lottoactivo.com/resultados/trio_activo/ | 08:00–19:00 (12) | premio_multiplo 30, modalidades [triple_a] |
| Lotto Activo RD Internacional | `lotto-activo-rd` | animalitos | Animalitos | lottoactivo.com/resultados/animalitos/ | 08:30–19:30 (12) | premio_multiplo 30 |
| Lotto Activo República Dominicana | `lotto-activo-rep-dom` | animalitos | Animalitos | lottoactivo.com/resultados/animalitos/ | 08:00–21:00 (14) | premio_multiplo 30 |
| Monje Millonario | `monje-millonario` | animalitos | Animalitos | lottoactivo.com/resultados/animalitos/ | 08:05–19:05 (12) | premio_multiplo 30 |

Todos con límite mínimo por defecto a nivel banca: `moneda=bs`, `limite_minimo=3600` (creado en cada seeder). Histórico de merges de duplicados: `terminal-trio`→`terminal-activo`, `lotto-activo-2-monje-millonario`→`monje-millonario` (migraciones 2026_07_31_000002, 2026_07_30_000009).

**IMPORTANTE**: un juego NO es una migración ni una tabla nueva; es **datos** (seeder) + **código** (plugin opcional + scraper opcional). Las migraciones solo se usaron para fixes de datos (`update_scrapers`, merges).

### 3. Scrapers existentes

- `app/Plugins/Scrapers/BaseScraper.php` — abstracta: Guzzle (timeout 30s, verify=false, cookies, headers de navegador). Template method `execute(?fecha)` → `fetch(fecha)` (abstracto) + `parse(rawData)` (abstracto). Helpers `getHtml`, `postJson`, `postJsonPayload`, `createCrawler` (DOM Crawler), logging `logInfo/Error/Warning`.
- `TripletasScraper` — `baseUrl = https://resultadostriplezulia.com`, `productId = '2'`. POST JSON a `/api/gaming/results/product`; parsea `response[].results` → `triple_a/b/c`, `signo`, `pais=VE`; conversión de timestamp a zona `America/Caracas` (`H:i`). `findOrCreateJuego(['slug' => 'triple-zulia'])`. `saveResults` upsert por `juego_id+fecha+hora`. Su `execute()` filtra resultados a la fecha pedida (descarta históricos).
- `AnimalitosScraper` — `baseUrl = https://www.lottoactivo.com`, slug paramétrico (`animalitos`, `trio_activo`, `terminal_activo`). `fetch`: GET HTML `/resultados/{slug}/{fecha}/`, extrae token CSRF por regex de un `<script>`, POST `form_params` a `/core/process.php` (`option`, `loteria`, `fecha`). `parse`: formato plano (Trío/ Terminal Activo) o anidado (animalitos → varios juegos). `findOrCreateJuego` con **mapeo de slugs canónicos** (monje-millonario, terminal-activo, lotto-activo-rd, lotto-activo-rep-dom) y crea el juego en caliente si no existe. `saveResults` upsert.
- `ScrapeResultsJob` (queueable, tries=3, backoff=300) — el job "genérico" actual: recibe `juegoId` (+fecha), resuelve la clase scraper por **match de cadena en `scraper_url`** (`lottoactivo.com` → AnimalitosScraper, `triplezulia` → TripletasScraper) con fallback por convención `App\Plugins\Scrapers\{Studly(type)}Scraper`. `instantiateScraper` mapea el slug para AnimalitosScraper. Tras guardar, verifica ganadoras con `ApuestaService::verificarGanadores` y registra log en tabla `logs` (`action=scrape_resultados`).
- `FetchResultsJob` (legacy, tries=3, backoff=60) — solo AnimalitosScraper, hardcodeado. Usado por el admin web (`Admin\ResultadoController::scrape` → `dispatchSync`). Duplica lógica de ScrapeResultsJob.
- `ScrapeExchangeRateJob` — NO relacionado (tasas de cambio).

### 4. Schedule (cómo se disparan los scrapers)

- `app/Providers/ScheduleServiceProvider.php` (registrado en `bootstrap/providers.php`): por cada `Juego` con `requires_scraper=true`, y por cada `juego_horarios.hora`, agenda `ScrapeResultsJob($juego->id)` con `dailyAt($horaUtc)` (convierte hora local `America/Caracas` → UTC), `name="scrape_{slug}_{horaLocal}"`, `withoutOverlapping(5)`.
- `routes/console.php`: SOLO `ScrapeExchangeRateJob` (cada 6h) y `ExpireUnclaimedPrizesJob` (01:00). El schedule de juegos NO está aquí — vive en el provider, leído de la BD.
- Disparo manual: `POST /api/v1/resultados/scrape` (`juego_id` + `fecha`) y `/resultados/scrape-all` (roles `super_master|master`) → ejecutan `ScrapeResultsJob->handle()` en línea + verifican ganadoras. Admin web: `POST /resultados/scrape` → `FetchResultsJob::dispatchSync`.

**Consecuencia**: agregar horarios a un juego = insertar filas en `juego_horarios`; el schedule se regenera en cada boot. No hay cron por juego en consola.

### 5. Dónde se registra un juego nuevo (flujo completo actual)

1. Seeder propio (ej. `TripleZuliaSeeder`): `Juego::firstOrCreate(['slug'], [...])` con `type`, `config`, `requires_scraper`, `scraper_url`, `active`; crea `JuegoLimite` default a nivel banca (bs, 3600); `PluginJuego` (`class_namespace`); `JuegoOpcion`(s); `JuegoHorario`(s).
2. Registrar el seeder en `DatabaseSeeder::run()`.
3. Clase plugin en `app/Plugins/Juegos/` implementando `JuegoInterface` — auto-descubierta por `PluginServiceProvider` (escanea el directorio, singleton `plugins`).
4. Si requiere scraper: clase en `app/Plugins/Scrapers/` extendiendo `BaseScraper`, y el resolutor de `ScrapeResultsJob` debe reconocer la URL (match de cadena) o el `type` (convención `{Type}Scraper`).
5. Schedule automático vía `ScheduleServiceProvider` (lee horarios de BD).
6. Tests: fixture + unit test de `parse` + feature test de `saveResults`/dedupe.

### 6. Tests existentes de scraping/juegos

- `tests/Unit/TripletasScraperTest.php` — parse con fixture `tests/Fixtures/triplezulia_response.json` (3 resultados), estructura (`triple_a/b/c`, `signo`, `pais`), formato `\d{3}`/`[A-Z]{3}`, conversión a hora Venezuela, vacíos, JSON inválido. Usa Reflection para métodos privados. **Sin mocks HTTP**: parse se invoca con strings de fixture.
- `tests/Unit/AnimalitosScraperTest.php` — `extractToken` con fixture `animalitos_page.html`, parse con `animalitos_response.json` (6 resultados, Delfín, "10:00 AM", Venezuela), vacíos, JSON inválido.
- `tests/Feature/ScrapeResultsJobTest.php` — RefreshDatabase + DatabaseSeeder; `resolveScraper` por convención (animalitos / triple-zulia); parse+save 3 resultados; dedupe (no duplica); early-return para juego sin scraper.
- `tests/Feature/FetchResultsJobTest.php` — parse+save 6 resultados, dedupe, respuesta vacía, log de éxito.
- `tests/Unit/AnimalitosPluginTest.php` / `tests/Feature/PluginIntegrationTest.php` — lógica del plugin (validar/calcular premio) y auto-descubrimiento `app('plugins')`.
- Fixtures: `animalitos_page.html`, `animalitos_response.json`, `triplezulia_response.json` — muestras reales usadas como mock (NO hay mocking de Guzzle/HTTP).

## Áreas afectadas (para el cambio)

- `backend/database/seeders/*` — juegos actuales dispersos en 7 seeders; candidatos a consolidarse/derivarse de una lista maestra.
- `backend/app/Plugins/Scrapers/` — `ScrapeResultsJob::resolveScraper` (match de URL hardcodeado) y `instantiateScraper`; `AnimalitosScraper::findOrCreateJuego` (crea juegos en caliente).
- `backend/app/Models/Juego.php` + migraciones — posible campo para asociar scraper explícitamente (p. ej. `scraper_class` en `juegos` o en `plugin_juegos`).
- `backend/app/Http/Controllers/Api/JuegoController.php` + `routes/api.php` — hoy NO existe `POST /juegos` (solo index/show/update/toggle + opciones/horarios/reglas). Un alta operativa requeriría endpoint o comando artisan.
- `backend/app/Providers/ScheduleServiceProvider.php` — ya es genérico (lee horarios de BD); probablemente no cambia.
- `backend/docs/plugins.md` — documentación DESACTUALIZADA: dice que el schedule está en `routes/console.php` con `FetchResultsJob` cada 5 min y que Triple Zulia no tiene scraper (ya existe `TripletasScraper`). No usarla como referencia sin verificar.
- `backend/tests/` + `backend/tests/Fixtures/` — patrón a replicar por cada scraper nuevo.

## Opciones

1. **Lista maestra + registro explícito de scrapers (recomendado)**
   - Crear una fuente única de juegos (p. ej. `juegos.md` + seeder maestro que la consuma, o `config/juegos.php`) con: nombre, slug, type, horarios. Los seeders por juego pasan a derivarse de ahí.
   - Reemplazar el match de URL hardcodeado por un registro explícito: columna `scraper_class` en `juegos` (o `plugin_juegos`) que apunte a la clase scraper; `resolveScraper` la usa primero y conserva la convención como fallback.
   - Pros: integración incremental limpia (un juego = una fila + una clase + un fixture), sin editar el job por cada fuente nueva, URLs por juego verificables una a una; sin migración de esquema nueva (columna nullable).
   - Contras: requiere migración ligera (1 columna nullable) y refactor acotado de `resolveScraper`; hay que portar los 7 juegos actuales al registro.
   - Esfuerzo: Medio.

2. **Mantener el patrón actual (seeder por juego + match de URL)**
   - Cada juego nuevo = seeder + plugin + scraper + editar `resolveScraper` con el nuevo dominio.
   - Pros: cero cambios de esquema; patrón ya probado por 7 juegos.
   - Contras: el job crece con cada fuente (match de cadenas); frágil ante dominios nuevos; no da soporte a la "lista de juegos" que pide el cliente (datos dispersos en seeders).
   - Esfuerzo: Bajo por juego, pero deuda acumulada.

3. **API de alta de juegos + generación de scraper**
   - Endpoint `POST /api/v1/juegos` (con horarios, tipo, opciones, límites) + comando `artisan juegos:crear`; el scraper sigue siendo código por juego (no generable de forma fiable).
   - Pros: permite al panel (otro agente) gestionar la lista sin tocar seeders; operativo, no de dev.
   - Contras: el scraper es código inevitablemente (fetch/parse específicos de cada fuente); la API sin scraper registrado dejaría el juego "huérfano" (sin resultados). Mayor superficie de validación.
   - Esfuerzo: Alto.

## Recomendación

Enfoque **1 + 3 combinado y escalonado**, alineado con el ritmo del cliente ("un juego a la vez"):

- **Fase A (datos)**: crear `juegos.md` como lista maestra (nombre, tipo, horarios) y un seeder/registro que materialice la lista en `juegos` + `juego_horarios` (+ opciones/límites/plugin por juego). Los 7 juegos actuales se documentan en `juegos.md` desde ya.
- **Fase B (scrapers)**: columna nullable `scraper_class` en `juegos` (o en `plugin_juegos`) + `ScrapeResultsJob` que resuelva por ese campo (fallback: convención por type, luego URL). Cada juego nuevo aporta: clase `BaseScraper` + fixture + unit/feature tests. Nada de editar el job por fuente.
- **Fase C (operación, si el panel lo requiere)**: endpoint `POST /juegos` + validación de horarios/type, para que el alta de la lista sea operativa. Se decide en propose según alcance del panel.

Regla de seguridad para la integración incremental: los scrapers nuevos NO deben crear juegos en caliente (hoy `AnimalitosScraper::findOrCreateJuego` lo hace); deben exigir que el juego exista en la lista maestra (fail-fast) para que "un juego a la vez" signifique "se agrega a la lista Y luego se le asocia scraper".

## Riesgos

- **Juegos creados en caliente por scrapers**: `AnimalitosScraper` crea juegos si no existen; con fuentes nuevas puede contaminar la lista. Mitigación: fail-fast si el juego no está registrado.
- **`hora_sorteo` con formatos inconsistentes** entre scrapers ("10:00 AM" vs "H:i") y distinta de `juego_horarios.hora` ("H:i") — el schedule compara horas de BD, no resultados; pero la correlación resultados↔horarios no es directa.
- **Docs desactualizadas** (`docs/plugins.md`): referencia con datos viejos; verificar contra código.
- **`FetchResultsJob` legacy duplicado** (admin web): riesgo de divergencia al refactorizar; considerar deprecarlo y apuntar el admin a `ScrapeResultsJob`.
- **Sin infraestructura de mocking HTTP en tests**: los scrapers nuevos deben seguir el patrón fixture (parse con strings); no hay Guzzle Mock Handler ni VCR.
- **Timezone del schedule**: `ScheduleServiceProvider` convierte a UTC con `Carbon`/`America/Caracas`; cambios de horario de un juego requieren re-boot del provider (los jobs se registran en boot).
- **Panel (otro agente) depende de la API**: cualquier contrato nuevo (p. ej. `POST /juegos`, campo `scraper_class` en respuestas) debe acordarse para no romper el frontend.

## Ready for Proposal

Sí. El orquestador debe indicar al usuario: se exploró el backend completo; existe un patrón consolidado (datos en seeders + plugin `JuegoInterface` + scraper `BaseScraper` + schedule por horarios en BD + tests con fixtures). La pieza central faltante es la **lista maestra de juegos** (hoy dispersa en 7 seeders) y un **registro explícito juego→scraper** (hoy resuelto por match de URL hardcodeado). Se recomienda proponer: `juegos.md` como fuente de la lista, columna `scraper_class` nullable, y refactor del resolutor; la integración por juego queda así: fila en la lista + clase scraper + fixture + tests, sin tocar el job.