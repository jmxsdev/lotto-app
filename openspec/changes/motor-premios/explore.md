# Exploración — Motor de Premios (cambio `motor-premios`)

> Fase: **sdd-explore** · Rama: `feat/motor-premios` · Base: `origin/main` (d250c20)
> Fecha: 2026-09-17 · Artefacto: OpenSpec + Engram (`sdd/motor-premios/explore`)
> Alcance: ANÁLISIS del estado actual del motor + inventario de inconsistencias tipadas.
> NO se modificó código. Fuentes: `docs/motor-premios.md`, `docs/inconsistencias.md` (H1–H22),
> `docs/comparacion-juegos.md`, `docs/seguimiento-verificacion.md`, `backend/docs/juegos.md`,
> `backend/app/Plugins/**`, `backend/app/Services/**`, `backend/app/Jobs/**`,
> `backend/app/Http/Controllers/Api/{Apuesta,Pago,Ticket,Juego}Controller.php`,
> `backend/database/seeders/**`, `backend/database/migrations/**`,
> `taquilla/src/pages/dashboard.astro` (solo lectura, para verificar el contrato del POS).

---

## 1. Estado actual del motor (cómo funciona HOY)

### 1.1 Plugins — multiplicadores hardcodeados

Tres plugins implementan `App\Plugins\Contracts\JuegoInterface` (`backend/app/Plugins/Juegos/`):

| Plugin | Multiplicador | `calcularPremio` (lógica) | Referencias |
|---|---|---|---|
| `Animalitos` | **30** (hardcodeado `$multiplicador = '30'`) | Compara `strtolower($animalApostado) === strtolower($animalGanador)` contra `numeros_ganadores.nombre_animal`; premio = `amount_bs/usd × 30`. **Sin normalización de acentos.** | `Animalitos.php:52,75-96` (comparación en :85) |
| `Tripletas` | **30** (hardcodeado) | Busca el número apostado en **CUALQUIERA** de `triple_a/triple_b/triple_c` (sin exigir que el tipo apostado coincida); para `triple_c` compara `strtoupper(signoApostado)` contra `numeros_ganadores.signo`; premio = `amount × 30` | `Tripletas.php:30,53-91` (coincidencia laxa :65-71) |
| `Terminales` | **20** (hardcodeado) | Lee `numeros_ganadores.terminal` y compara con `(string)$numeroApostado`; premio = `amount × 20` | `Terminales.php:9,29-53` |

**Hallazgo crítico (nuevo, no estaba documentado)**: `Terminales::calcularPremio` busca la clave **`terminal`** en `numeros_ganadores`, pero **ningún scraper guarda esa clave**: `AnimalitosScraper::mapFlatResult` guarda `numeros_ganadores = {numero: int}` para terminales (`AnimalitosScraper.php:177-179`). Resultado: **terminal-activo devuelve premio 0 SIEMPRE** (clave ausente), independiente del multiplicador.

### 1.2 Resolución de plugins — `JuegoPluginManager`

`backend/app/Services/JuegoPluginManager.php`:
- `getPlugin(Juego)`: resuelve por `plugin_juegos.class_namespace` + `active`, con cache por `juego->id` (:14-47).
- `calcularPremio(Juego, apuesta, resultados)` → delega al plugin; sin plugin devuelve `{premio_bs:0, premio_usd:0}` (:69-77).
- `validarApuesta(Juego, data)` → **NO pasa las opciones del juego** al plugin (:59-67); la validación contra el zoo real del juego ocurre SOLO dentro de `ApuestaService::createApuesta` (:391-408), que sí pasa `$opciones`.
- `getMultiplicador()` expone el genérico del plugin (usado por `TicketController::ganadores` para mostrar `multiplicador`).

### 1.3 Liquidación automática — `ScrapeResultsJob` → `verificarGanadores`

Flujo end-to-end (`backend/app/Jobs/ScrapeResultsJob.php` + `backend/app/Services/ApuestaService.php`):

1. `ScrapeResultsJob::handle` (por `juegoId` + `fecha`, `tries=3`, `backoff=300s`): resuelve scraper (`scraper_class` → match de URL → convención por type, :120-148), `execute($fecha)`, `saveResults` (dedupe **juego+fecha+hora**, `BaseScraper.php:179-203`).
2. Relee los resultados del día y por CADA uno llama `ApuestaService::verificarGanadores($resultado)` (`ScrapeResultsJob.php:89-98`).
3. `verificarGanadores` (`ApuestaService.php:966-1049`):
   - Normaliza `resultado->hora_sorteo` de 12h→24h si trae AM/PM (:975-978).
   - Selecciona apuestas: `juego_id` + `estado='pendiente'` + `whereDate(sorteo_hora, fecha_sorteo)` + `whereTime(sorteo_hora, hora_sorteo)` (:980-985).
   - Por apuesta: `plugin->calcularPremio(combinacion, numeros_ganadores)` → setea `apuesta.resultado_id`; actualiza `detalle_apuestas.premio_ganado`/`premio_ganado_usd` (solo si >0, si no `null`) (:1009-1015).
   - Premio > 0 → cuenta ganadora, acumula en ticket (`premio_total_bs/usd`) y marca el ticket `ganador` si estaba `pendiente` (:1017-1046).
   - Premio 0 → apuesta `perdida` (:1029-1032).
   - **Las apuestas GANADORAS no cambian de estado**: quedan `pendiente` con `resultado_id` seteado hasta el pago manual.

### 1.4 Pago manual — `PagoController::store`

`backend/app/Http/Controllers/Api/PagoController.php:20-171`:
- Requiere apuesta `estado='pendiente'` y `resultado_id` seteado (solo pagable tras liquidación) (:67-81).
- **Recalcula** el premio con el plugin (`calcularPremio` privado, :191-215) y valida que el monto enviado coincida (±0.01) (:93-108). **Si el plugin paga mal (acentos / multiplicador genérico), el sistema RECHAZA pagar el premio correcto del reglamento.**
- Crea `Pago` tipo `egreso`, apuesta → `pagada`, actualiza `premio_ganado` con los montos del request, cascada: ticket → `pagada` si todas sus apuestas están resueltas (:111-148).

### 1.5 Premio posible — `TicketController` / `ApuestaService::createApuesta`

- `createApuesta` (`ApuestaService.php:361-478`) calcula `premio_posible` llamando a `calcularPremio(..., [])` con **resultados VACÍOS** (:444-449) → los 3 plugins devuelven **0** → **`premio_posible` SIEMPRE es 0** en apuestas reales.
- Solo los seeders demo (`TicketsGanadoresDemoSeeder.php:160`, `ApuestaGanadoraSeeder.php:113`) calculan `premio_posible` con multiplicador.
- El front (taquilla/panel) **no consume `premio_posible`** (grep sin usos fuera de modelos/migraciones/seeders).

### 1.6 `config.premio_multiplo` — quién lo usa de verdad

- Único consumidor: `JuegoCatalogoService::generar` (`backend/app/Services/JuegoCatalogoService.php:36`) → export `docs/juegos.json` (contrato del front).
- **NO se usa al liquidar** (confirmado: ningún plugin ni `verificarGanadores` ni `PagoController` lee `config['premio_multiplo']`).
- La taquilla POS (`taquilla/src/pages/dashboard.astro`) tiene un catálogo **HARDCODEADO** (`GAMES_CONFIG`, :727-778) con multiplicadores 30/30/20 y **solo 7 juegos** (ids 1–7): los 15 juegos integrados en el ciclo de scrapers (9–22: triple-caliente, cazaloton, triple-chance, el-arrejuntado, el-guacharito, guacharo-activo, la-granjita, la-ricachona, loto-chaima, mega-animal-40, selva-plus, triple-tachira, triple-facil, triple-zamorano) **no se pueden vender desde la taquilla**. La taquilla aún lista `{numero:23, label:'Cobra'}` (:735) — desactualizado vs el backend corregido (Cebra).

### 1.7 Matching de apuestas vs resultados (formatos)

- `apuestas.sorteo_hora`: `timestamp` (datetime) construido por la taquilla (`formatDateWithHorario`, `dashboard.astro:1085-1098` — hora local del POS, +1 día si el horario ya pasó) o por `getNextDrawTime` (`ApuestaService.php:313-333`, `ApuestaController.php:85-105` — **si el sorteo pedido ya pasó, la apuesta se reasigna al PRÓXIMO sorteo** sin avisar).
- `resultados.hora_sorteo`: normalizada a **24h "H:i"** por todos los scrapers (`BaseScraper::normalizeHora`, :163-174) + migración `2026_09_15_120000_normalize_hora_sorteo_to_24h.php` (H22) que convirtió las filas históricas con AM/PM.
- `verificarGanadores` compara `whereTime('sorteo_hora', hora_sorteo)` — coherente tras H22, con la zona de la app `America/Caracas` (`config/app.php:68`).
- **Riesgo residual H22**: las filas históricas DUPLICADAS (mismo sorteo guardado una vez como "01:00 PM" y otra como "13:00" ANTES de la migración) NO se deduplican; `saveResults` solo evita duplicados nuevos → un mismo sorteo puede tener 2 filas → `verificarGanadores` evalúa la apuesta 2 veces (sobreescritura de `premio_ganado`/`resultado_id`, no acumulación).

### 1.8 Qué paga HOY en la práctica

| Familia | Paga (multiplicador plugin) | Debería pagar (fuente oficial) |
|---|---|---|
| Animalitos (lotto-activo, rd, rep-dom, monje, cazaloton, la-granjita, mega) | 30× **pero 0 en animales acentuados** (H13 + variante inversa en mega) | 30× (lotto-activo familia) · 60× (guácharo) · 70× (guacharito) · 80× (selva) · comodines 40×/120×/150×/160×/200× |
| Tripletas (zulia, caliente, chance, táchira, zamorano, trio, fácil, ricachona, arrejuntado) | 30× genérico | 500×–700× el triple; 6.000× zodiacal; 60× terminal/cola; etc. |
| Terminales (terminal-activo) | 20× **pero premio 0 siempre (clave `terminal` inexistente)** | 60× (reglamento; FAQ 70×, H12) |

---

## 2. Inventario de inconsistencias (tipadas con evidencia)

### Leyenda de tipos

- **BUG** — el sistema paga mal o no paga (impacto directo en dinero).
- **GAP** — regla/modalidad oficial no soportada por el modelo/plugins (no se paga porque no se puede jugar o no se liquida).
- **DATOS** — valores/config por verificar con el cliente o con el operador (no hay fuente concluyente aún).

### 2.1 Consolidadas de la documentación

| Ref | Tipo | Inconsistencia | Evidencia | Estado |
|---|---|---|---|---|
| **H13** | **BUG (crítico)** | `Animalitos::calcularPremio` compara `strtolower` **sin normalizar acentos**: el feed entrega "Delfin" y la taquilla envía el LABEL "Delfín" → premio 0 en animales acentuados (Delfín, Ciempiés, Alacrán, Águila, Ratón, Caimán, Tucán, Chigüire, León…). Afecta familia Lotto Activo + Monje. | `Animalitos.php:85` · `AnimalitosScraper.php:159` (nombre_animal del feed) · `dashboard.astro:1191,1272` (envía label con acento) · `JuegoAnimalitosSeeder.php:18-53` (labels con acentos) · `docs/motor-premios.md:27-42` | Pendiente (ciclo motor) |
| **H3** | **BUG** | `premio_multiplo` de `config` estático y **no usado al liquidar**; los plugins pagan el genérico 30/30/20. Juegos cuyo reglamento paga 60×–6.000× pagan 30×. | `JuegoCatalogoService.php:36` (único uso) · plugins `$multiplicador` · `docs/inconsistencias.md:42` · `docs/estrategia-scrapers-premios.md` | Pendiente (ciclo motor) |
| **H1** | **GAP** | Comodín MEGA 40× (mega-animal-40): **capturado en datos** (`numeros_ganadores.comodin` bool, `MegaAnimal40OficialScraper.php:146-147`) pero el plugin Animalitos no lo lee → la liquidación 40× no existe. | `MegaAnimal40OficialScraper.php:27-31,146-147` · `MegaAnimal40Seeder.php:39-44` | Datos ✅ / liquidación pendiente |
| **H8b** | **GAP** | Comodines Selva A "Leoncito" 160× / B "Selva Plus" 200×: **capturados** (`comodin` "A"/"B" + `comodin_nombre`, `SelvaPlusScraper.php:244-256`) pero no liquidados (plugin no los lee). | `SelvaPlusScraper.php:229-269` · `SelvaPlusSeeder.php:26-30` | Datos ✅ / liquidación pendiente |
| **H4 / H9** | **GAP** | Modalidades fuera del modelo: Dupleta (Lotto Activo 1.000×, Cazalotón 800×), Par Millonario 200.000× (Chance/Táchira), El Patronus (Monje, premio sin fuente), Punta/Terminal/Aproximación (Trío/Táchira/Fácil), Terminal+Zodiacal (Táchira/Zulia/Caliente 500×-600×), Cola/Uña (Zamorano). Ninguna es liquidable: ni el modelo de apuesta (`combinacion` 1 línea) ni los plugins las soportan. | `docs/inconsistencias.md:43` · `docs/comparacion-juegos.md:106-114` · configs de seeders (modalidades) | Pendiente |
| **H10 / H10b** | **GAP** | Terminales derivadas de Triple Fácil (`n % 100` ±1, función oficial del front del proveedor): no se modelan ni se derivan; el terminal real (00–99) está en opciones pero no se liquida contra el triple. | `TripleFacilSeeder.php:24-31` · `docs/comparacion-juegos.md:111` | Pendiente |
| **H17** | **GAP/DATOS** | Triple Chance: premios del afiche oficial en config (600×/200.000×/100×/60×/5.000×/6×) no liquidables; discrepancia 100× vs 150× (afiche vs informativa) documentada. Reglamento escaneado (2 PDFs descargados para que el cliente extraiga). | `TripleChanceSeeder.php:38-58` · `docs/comparacion-juegos.md:114` | Pendiente |
| **H18** | **DATOS** | Triple Caliente: reglamento Art. 10 declara 5 sorteos (11:10–19:10); la API opera 3 (13:00/16:30/19:10). Se priorizó la operación real. | `docs/comparacion-juegos.md:221` · `docs/inconsistencias.md:29` | Decidido (confirmar operador) |
| **H19** | **DATOS** | Triple Zamorano: reglamento NOV2025 declara sorteos L-D; la API muestra domingos solo 19:00. Se mantiene la operación real. | `docs/comparacion-juegos.md:222` | Decidido (confirmar operador) |
| **H14** | **GAP/DATOS** | Monje: zoo completo de 77 figuras (resuelto); pendiente el **premio especial de El Patronus (75)** (reglamento 404) y la semántica de `special_result` (flag 1/0 variable, solo Monje). | `MonjeMillonarioSeeder.php` · `docs/inconsistencias.md:41` · `docs/seguimiento-verificacion.md:37` | Zoo ✅ / premio pendiente |
| **H22** | **BUG (resuelto) / riesgo** | Formatos de hora mezclados (12h/24h) en `resultados.hora_sorteo` → corregido (migración + `normalizeHora`). **Riesgo residual**: filas históricas duplicadas (mismo sorteo 12h y 24h) no deduplicadas → doble evaluación; y el matching de apuestas depende de que `sorteo_hora` (datetime, zona Caracas) y `hora_sorteo` (H:i) sean coherentes. | `2026_09_15_120000_normalize_hora_sorteo_to_24h.php` · `ApuestaService.php:975-985` | Resuelto + riesgo |

### 2.2 Nuevas descubiertas en esta exploración (lectura de código)

| Ref | Tipo | Inconsistencia | Evidencia |
|---|---|---|---|
| **N1** | **BUG (crítico)** | **Terminales nunca paga**: `Terminales::calcularPremio` busca `numeros_ganadores['terminal']`, pero los scrapers guardan `numeros_ganadores['numero']` → premio 0 SIEMPRE en terminal-activo. Además el multiplicador del plugin es 20 (reglamento: 60, H12). | `Terminales.php:38-42` · `AnimalitosScraper.php:177-179` |
| **N2** | **BUG** | **Apuestas triple_c (triple + signo) imposibles desde la taquilla**: el POS envía el LABEL del signo ("Escorpio") y `Tripletas::validarApuesta` exige la SIGLA ("ESC") → la apuesta es RECHAZADA (RuntimeException). La modalidad zodiacal (6.000× de Zulia/Caliente/Zamorano/Chance) es inaccesible. Si se enviara la sigla vía API directa, `calcularPremio` sí la compararía bien (`strtoupper`). | `dashboard.astro:1190,1269` (label) · `Tripletas.php:42-48,77-82` |
| **N3** | **BUG** | **Tripletas paga por tipo equivocado**: `calcularPremio` busca el número apostado en CUALQUIERA de `triple_a/b/c` sin exigir que el tipo apostado coincida → una apuesta `triple_a` paga si el número sale en `triple_c` (o viceversa). En juegos con A/B/C como sorteos distintos (Caliente, Zulia, Chance, Zamorano) puede pagar un premio que no corresponde al tipo apostado. | `Tripletas.php:65-71` |
| **N4** | **BUG (dato)** | **`premio_posible` siempre 0**: `createApuesta` calcula con resultados vacíos → el campo queda en 0 para todas las apuestas reales (los únicos con valor son seeders demo). Información incorrecta persistida (hoy sin consumidor en front, pero rompe cualquier futuro "premio posible" y el ticket impreso no muestra premio esperado). | `ApuestaService.php:444-459` |
| **N5** | **GAP** | **Ciclo de vida de estados**: la apuesta ganadora queda `pendiente` (solo el ticket pasa a `ganador`); las apuestas de sorteos cuyo resultado nunca llega quedan `pendiente` para siempre (no hay job que las marque `perdida`/`vencido` por ausencia de resultado). Riesgo de re-liquidación por sobreescritura (idempotente en valor, no en `resultado_id`). | `ApuestaService.php:1009-1032` · `ExpireUnclaimedPrizesJob.php` (solo tickets `ganador`) |
| **N6** | **GAP** | **Doble liquidación por filas duplicadas**: `ScrapeResultsJob` itera TODOS los resultados del día y reevalúa; con 2 filas para el mismo sorteo (H22 pre-migración) la apuesta se evalúa 2× (sobreescritura). | `ScrapeResultsJob.php:89-98` · `BaseScraper.php:179-203` |
| **N7** | **BUG (reporte)** | **`TicketController::ganadores` sin filtro de hora**: filtra apuestas por juego+fecha+estado pero NO por `sorteo_hora` → declara ganador contra CUALQUIER resultado del día (un animal acertado en un sorteo distinto al apostado aparece como ganador). | `TicketController.php:260-274` (falta `whereTime`) |
| **N8** | **GAP (front)** | **Taquilla con catálogo hardcodeado**: `GAMES_CONFIG` tiene 7 juegos, multiplicadores 30/30/20 fijos y "Cobra" en vez de "Cebra"; los 15 juegos nuevos del catálogo no se pueden vender desde el POS. El contrato `docs/juegos.json` no se consume. | `dashboard.astro:727-778` |
| **N9** | **DATOS** | **Padding de números en triples**: la comparación es `===` estricta (`Tripletas.php:67`). Los resultados llegan como string con padding ("013" Caliente, "030" Ricachona, "073"/"049" Fácil) y la apuesta valida `size:3` → consistente en general; pero cualquier scraper que guarde `int` rompería el match. Auditar juego por juego al implementar. | `Tripletas.php:67` · `TripleCalienteOficialScraper.php:20` · `LaRicachonaScraper` (doc :135) |
| **N10** | **DATOS** | **Acentos por juego (variante inversa)**: en mega-animal-40 la opción del plugin es "Aguila" (sin acento, `Animalitos::obtenerOpciones` usa `ucfirst` del mapa sin acentos) y el resultado del sitio oficial es "Águila" (CON acento, `MegaAnimal40OficialScraper.php:21,146`) → premio 0 también en mega para animales acentuados. En juegos lotterly (guácharo/selva/chaima) ambos lados vienen del mapa con acentos → OK. En la-granjita el API devuelve nombres en MAYÚSCULAS sin acentos ("RATON") y las opciones del plugin sin acentos ("Raton") → `strtolower` iguala → OK. | `Animalitos.php:108-120` · `MegaAnimal40OficialScraper.php:146` |
| **N11** | **BUG (operativo)** | **`PagoController` valida contra el plugin roto**: al pagar manualmente, el monto se compara contra `calcularPremio` (genérico 30×/sin acentos) → el cajero NO puede pagar el premio correcto del reglamento (600×) ni el premio de un animal acentuado (0 → "La apuesta no resultó ganadora"). | `PagoController.php:83-108` |
| **N12** | **GAP/DATOS** | **`JuegoPluginManager::validarApuesta` no recibe opciones** (:59-67): cualquier consumidor externo (endpoints, tests) valida contra el mapa interno del plugin (38 canónico) en vez del zoo propio del juego (Monje 77, Selva 103, Guácharo 77, Guacharito 101, Chaima 57). Solo `createApuesta` pasa opciones (validación correcta ahí). | `JuegoPluginManager.php:59-67` · `ApuestaService.php:391-408` |
| **N13** | **GAP** | **Comodines "de figura" (Guacharito 99 150×, Guácharo 75 120×)**: documentados en `config.comodines` con `numero`, pero el plugin Animalitos no distingue figuras especiales → si el resultado es el 99/75 no hay premio extra. | `ElGuacharitoSeeder.php:31-38` · `GuacharoActivoSeeder.php:30-37` |

### 2.3 Totales

- **BUG**: 7 (H13, H3, H22-riesgo, N1, N2, N3, N4, N7, N11) — 9 con el desglose.
- **GAP**: 10 (H1, H8b, H4/H9, H10/H10b, H14-parcial, N5, N6, N8, N12, N13).
- **DATOS**: 5 (H17-parcial, H18, H19, N9, N10) + pendientes de fuente (Patronus, Fácil, Granjita/Ricachona/Chaima/Arrejuntado).

---

## 3. Reglas reales por juego (verdad verificada) vs comportamiento actual

Fuente: `docs/comparacion-juegos.md`, `docs/seguimiento-verificacion.md`, `docs/reglamentos/` (parseables), configs de los seeders. `config.premio_multiplo` = valor oficial documentado (exportado a `docs/juegos.json`); **lo que paga HOY = multiplicador del plugin** (30/30/20) con los bugs N1/H13/N2.

| Juego | Plugin (paga hoy) | Oficial (config/reglamento) | Modalidades reales no soportadas | Brecha |
|---|---|---|---|---|
| `lotto-activo` | Animalitos 30× (0 en acentuados) | 30× (FAQ oficial + mislink Ruleta Royal) | Dupleta 1.000× (informativa, sin respaldo en reglamento) | Acentos (BUG H13) |
| `lotto-activo-rd` | Animalitos 30× | 30× (FAQ) | Dupleta 1.000× | Acentos |
| `lotto-activo-rep-dom` | Animalitos 30× | 30× (FAQ) | Dupleta 1.000× | Acentos |
| `monje-millonario` | Animalitos 30× | 30× simple + **Patronus (75) premio especial SIN fuente** (reglamento 404) | El Patronus; `special_result` sin semántica | Acentos (77 labels con acentos) + GAP Patronus |
| `terminal-activo` | **Terminales 20× → 0 SIEMPRE** (N1) | 60× reglamento (FAQ 70× + 5× aprox — H12) | Aproximación | **BUG N1** + multiplicador mal |
| `trio-activo` | Tripletas 30× | TRIPLE **600×** · TERMINAL **60×** · PUNTA **60×** | Terminal, Punta | Multiplicador + modalidades + N2/N3 |
| `triple-zulia` | Tripletas 30× | TRIPLE A/B/C **600×** · TERMINAL **60×** · ZODIACO **6.000×** · TERMINAL ZODIACO **600×** | Cola, Zodiaco, Terminal-Zodiaco | Multiplicador + signo inalcanzable (N2) + N3 |
| `triple-caliente` | Tripletas 30× | TRIPLE **600×** · TERMINAL **60×** · SIGNO **6.000×** · TERMINAL SIGNO **600×** | Idem Zulia | Multiplicador + N2 + N3 |
| `triple-chance` | Tripletas 30× | TRIPLE A/B **600×** · A+B **200.000×** · SOLO A/B **100×** · TERMINAL **60×** · C+SIGNO **5.000×** · SIGNO **6×** | Par Millonario, Solo A/B, Terminal | Multiplicador + modalidades + N2/N3 |
| `triple-tachira` | Tripletas 30× | A/B **500×** · COLA **50×** · ZODIACAL **5.000×** (+ Par Millonario 200.000×, Terminal+Zodiacal 500×, aprox 10× del reglamento) | Cola, Zodiacal, Par Millonario, Aproximación | Multiplicador + modalidades + N2/N3 |
| `triple-zamorano` | Tripletas 30× | TRIPLE **600×** · COLA **60×** · UÑA **5×** · ASTRO **6.000×** · COLA+SIGNO **600×** · UÑA+SIGNO **60×** | Cola, Uña, Astro, Cola+Signo, Uña+Signo | Multiplicador + modalidades + N2/N3 |
| `triple-facil` | Tripletas 30× | **700×** informativo (sitio oficial no publica cifras) · TERMINAL **60×** · APROX **10×** | Terminal derivada (`n%100`), Aproximación | Multiplicador + GAP H10 + N2/N3 |
| `cazaloton` | Animalitos 30× | 30× (reglamento Art. 22) · DUPLETA **800×** · TRIPLETA **200×** | Dupleta, Tripleta | Modalidades |
| `la-granjita` | Animalitos 30× | Sin fuente (reglamento escaneado, pendiente de extracción) | — | DATOS premiación |
| `la-ricachona` | Tripletas 30× | Sin fuente (reglamento escaneado) | — | DATOS + multiplicador |
| `loto-chaima` | Animalitos 30× | Sin fuente (reglamento NO publicado) | — | DATOS |
| `el-guacharito` | Animalitos 30× | Animalito **70×** · Guacharito (99) **150×** (bundle oficial) | Figura especial 99 | Multiplicador + GAP comodín-figura (N13) |
| `guacharo-activo` | Animalitos 30× | **60×** · Guácharo (75) **120×** (bundle oficial) | Comodín 75 | Multiplicador + GAP comodín-figura (N13) |
| `selva-plus` | Animalitos 30× | Base **80×** · Comodín A **160×** · Comodín B **200×** (API lotterly) | Comodines A/B (letra capturada) | Multiplicador + GAP H8b |
| `mega-animal-40` | Animalitos 30× (0 en acentuados, N10) | **30×** base · MEGA **40×** (sitio oficial) | Comodín MEGA (bool capturado) | Acentos inversos + GAP H1 |
| `el-arrejuntado` | Tripletas 30× | Sin fuente (no publicado) · 6 modalidades en `numeros_ganadores` (animalito, arrimao, pegadito, A, B, C+signo) | 5 de 6 modalidades sin liquidación | DATOS + GAP |

**Conclusión de brechas**: solo los juegos animalitos "sin acento en el animal sorteado" pagan correctamente hoy (30× cuando el oficial es 30×). **Todo juego con multiplicador oficial > 30 paga de menos; todo animal acentuado paga 0; terminal-activo paga 0 siempre; las modalidades con signo no se pueden jugar.**

---

## 4. Hallazgos clave, riesgos y preguntas abiertas

### 4.1 Qué arreglar primero (impacto real en dinero/pagos)

1. **H13 + N10 (acentos)** — dinero NO pagado a clientes en toda la familia Lotto Activo, Monje y Mega Animal 40 (premio 0 en ~10 animales acentuados). Fix de normalización (p. ej. `Str::ascii` + lowercase en ambos lados) + tests con nombres acentuados.
2. **N1 (Terminales nunca paga)** — terminal-activo (Trío Activo terminal) es un producto vendido cuyo premio nunca se liquida. Fix: alinear clave (`terminal` vs `numero`) o derivar el terminal del triple; multiplicador 60.
3. **H3 (multiplicadores)** — los triples pagan 30× cuando el reglamento paga 500×–6.000×: riesgo de pago de menos masivo y de pérdida de confianza. Diseño config-driven (base + modalidad + comodín por juego) es la fase 1 propuesta en `docs/estrategia-scrapers-premios.md`.
4. **N2 (signo inalcanzable)** — la modalidad más rentable (zodiacal 6.000×) no se puede vender ni liquidar; desbloquear el contrato signo (sigla vs label) con normalización en validación y cálculo.
5. **Comodines capturados sin liquidar (H1, H8b, N13)** — los datos YA viajan (`comodin` bool, letra A/B, figura 99/75); falta que `calcularPremio` los aplique.

### 4.2 Decisiones de negocio faltantes (para la reunión)

- **Alcance del ciclo**: ¿premios config-driven por juego (desde `config`) o plugin por juego? ¿Se reescribe `calcularPremio` de los 3 plugins o se introduce un motor de reglas (tabla `premios`/`modalidades`)?
- **Modalidades a soportar** y orden: Dupleta (cross-sorteo), Par Millonario, El Patronus, Punta/Terminal/Cola/Uña, Aproximación, Terminal+Zodiacal, terminales derivadas de Fácil. El modelo de apuesta actual es 1 línea = 1 combinación simple: Dupleta/Par requieren ampliar el modelo (2 combinaciones, 2 sorteos).
- **Valores por confirmar**: Terminal Trío 60× vs 70× (H12); Triple Fácil 700×/60×/10× (informativo, sin reglamento); premiaciones de Granjita/Ricachona/Chaima/Arrejuntado (reglamentos escaneados o inexistentes); premio de El Patronus (reglamento 404); Chance 100× vs 150× (afiche vs informativa).
- **Alcance front**: ¿la taquilla debe consumir `docs/juegos.json` (desbloquea la venta de los 15 juegos nuevos) o el ciclo motor es solo backend? El `GAMES_CONFIG` hardcodeado quedará obsoleto si el motor soporta premios por juego que el POS no conoce.
- **Estados de apuesta**: ¿introducir `ganadora` para distinguir de `pendiente`? ¿job que marque `perdida` las apuestas de sorteos sin resultado?
- **Redondeo**: hoy premio = `amount × multiplicador` en float sin redondeo explícito (casts `decimal:2` truncan al persistir). Definir política (redondeo a 2 decimales, half-up) y moneda por defecto del premio (¿premio en la moneda apostada?).

### 4.3 Preguntas concretas para el cliente

1. ¿Prioridad 1: corregir los bugs de pago (acentos, terminal, multiplicadores) o ampliar modalidades? ¿Ambos en este ciclo?
2. ¿Los premios se configuran por juego (config/BD) y el motor los lee, o se mantienen en plugins?
3. ¿Qué modalidades entran en el alcance del ciclo y cuáles quedan fuera? (Dupleta y Par Millonario requieren rediseño del modelo de apuesta.)
4. Terminal Trío: ¿60× (reglamento) o 70× (+5× aproximación)? ¿Se soporta la aproximación?
5. ¿Tiene el cliente el premio especial de El Patronus (Monje) o confirmación del operador? (no hay reglamento público)
6. Triple Fácil: ¿se confirman los 700×/60×/10× (informativos)? ¿Derivamos el terminal `n%100` del triple?
7. ¿El cliente puede proveer reglamentos/valores de Granjita, Ricachona, Chaima y Arrejuntado (hoy sin fuente)?
8. ¿El ciclo incluye la taquilla (venta de los 15 juegos nuevos + flujo de pago de premio) o es solo backend?
9. ¿Política de redondeo de premios y moneda de pago?
10. ¿Cómo se manejan las apuestas de sorteos sin resultado (hoy quedan `pendiente` para siempre)? ¿Job de expiración por ausencia de resultado?

### 4.4 Riesgos

- Cambiar multiplicadores/modalidades sin confirmar valores con el cliente → pagar de más (pérdida) o de menos (reclamos).
- Refactor de `calcularPremio` sin tests de regresión por juego → romper juegos que hoy funcionan (p. ej. la-granjita sin acentos).
- La liquidación retroactiva es inviable para mega-animal-40 (endpoint oficial solo sirve el día actual) y parcial para el resto (históricos del proveedor).
- Doble liquidación/sobreescritura con filas duplicadas (H22) si no se deduplica antes de tocar el motor.
- El alcance del motor no resuelve la venta: sin tocar `GAMES_CONFIG`, los 15 juegos nuevos siguen sin venderse (GAP N8).
- `PagoController` quedará bloqueando pagos correctos hasta que el motor calcule bien (N11) — considerar ordenar el fix del motor ANTES de tocar pagos.

---

## Notas de ejecución

- Rama `feat/motor-premios` creada desde `origin/main` (d250c20) en el worktree `investigacion-produccion`.
- Solo se creó `openspec/changes/motor-premios/explore.md` (git add -f por `*.md` en .gitignore:44). Sin push.
- Persistencia Engram: `sdd/motor-premios/explore` (type architecture, capture_prompt false).
- No se modificó ningún código. `taquilla/src/pages/dashboard.astro` se leyó únicamente para verificar el contrato del POS.