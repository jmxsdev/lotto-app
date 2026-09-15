# Inconsistencias detectadas — registro consolidado

Todas las inconsistencias encontradas durante la integración y verificación del catálogo, en tres
familias: **(1)** fuente informativa vs fuente oficial, **(2)** contradicciones internas de una misma
fuente oficial, **(3)** gaps de nuestro sistema. Evidencia detallada por juego en
`docs/seguimiento-verificacion.md`; narrativa completa de hallazgos en `docs/comparacion-juegos.md`.

> Actualizado: 2026-09-14.

## 1. Fuente informativa (resultadosvenezuela.com) vs fuente oficial

| Ref | Juego | Inconsistencia | Resolución |
|---|---|---|---|
| H8 | `selva-plus` | Informativa: 38 animalitos / 30× / 11 sorteos. Oficial: **101 figuras + 2 comodines / 80× / 13 sorteos** | Corregido e integrado con datos oficiales |
| H9 | `triple-tachira` | Informativa: A/B 600×, Cola 60×, Zodiacal 6.000×, 3er sorteo 19:20. Reglamento oficial: **500× / 50× / 5.000×, 3er sorteo 22:10** | Corregido con el reglamento (PDF G-20004065-3) |
| H11 | `triple-zamorano` | Informativa: 3 sorteos 12/16/19 y premios 600/60/6.000/600 (mismo template que Táchira). Oficial: **5 sorteos 10/12/14/16/19** | Horarios corregidos (WU f20). **Premios RESUELTOS (WU f26)**: el **reglamento oficial NOV2025** de triplezamorano.com (Lotería del Zulia, PDF parseable → `docs/reglamentos/reglamento-triple-zamorano.pdf`) confirma TRIPLE **600×**, COLA **60×**, UÑA **5×**, ASTRO **6.000×**, COLA+SIGNO **600×**, UÑA+SIGNO **60×** → `premio_multiplo` 30→**600** + modalidades. La informativa 600/60/6.000/600 era CORRECTA (salvo la UÑA 5× que no declaraba); quedó pendiente solo el **comportamiento dominical** (reglamento L-D vs API domingos solo 19:00, H19) |
| H2 | `monje-millonario` | Informativa: 77 figuras (+Patronus 75). Oficial: **77 figuras confirmadas (0–75, con el 0 duplicado Delfín/Ballena)** | **H2 CONFIRMADO (WU f22) + ZOO COMPLETADO (WU f25)**: muestreo de 75 días del feed oficial cerró los 7 huecos (ver H14) |
| H5 | `trio-activo` | Informativa: terminales 3 cifras / 3 sorteos. Reglamento oficial: **triple 000–999 + Terminal + Punta, 12 sorteos** | Corregido (Trío Activo es un TRIPLE) |
| H10 | `triple-facil` | La web muestra "3 resultados" (prev/main/next). Oficial: main = triple; prev/next = **terminales DERIVADAS** (`n%100 ±1`); **no existe producto terminal aparte** | Aclarado y documentado (modelado como 1 juego) |
| H6 | `cazaloton` | Informativa sin datos de zoo/premios | **Resuelto (WU f24)**: el reglamento oficial de cazaloton.com (17 págs parseable) confirma 38 figuras / 30× / 11 horarios + modalidades Dupleta 800× y Tripleta 200× |

## 2. Contradicciones internas de fuentes oficiales

| Ref | Juego | Inconsistencia | Decisión |
|---|---|---|---|
| H12 | `terminal-activo` (Terminal Trío) | Reglamento: **60×**. FAQ oficial: 70× (+5× aproximación) | **Se usa 60× (reglamento)**; pendiente confirmar con el operador |
| H15 | `trio-activo` | Reglamento (2020): 3 sorteos. Operación real: **12 sorteos** | Se prioriza la operación (feed). Confirmar con el operador |
| H16 | `lotto-activo` / familia | Copy oficial: "11 sorteos 09:00–19:00" (y "trece (14)" en RD). Operación: **12 (08:00–19:00)** / **14 (08:00–21:00)** | Se prioriza el feed operativo |
| H18 | `triple-caliente` | Reglamento oficial (Art. 10): **5 sorteos 11:10/13:10/15:10/17:10/19:10** (domingos solo 19:10). API oficial: **3 sorteos 13:00/16:30/19:10** (234 respuestas, timestamps) | Se prioriza la **operación real** (13:00/16:30/19:10, lo que consume el scraper); desajuste documentado, confirmar con el operador (WU f26) |
| H19 | `triple-zamorano` | Reglamento NOV2025: sorteos **todos los días** (5 horarios). API oficial: **domingos solo 19:00** (consistente en 87 días de muestra) | Se mantiene la operación real (domingos solo 19:00); confirmar con la Operadora 1923 (WU f26) |
| H20 | `mega-animal-40` | **Sitio oficial encontrado**: megaanimal40.com (CONALOT + Big Data Tecnology + Lotería de Cojedes) publica premios (30×/40×), horarios (12, 09:00–20:00) y resultados; el scraper actual usa resultadosvenezuela.com (excepción autorizada de f14) | ✅ **RESUELTO (WU f27)**: scraper **MIGRADO al sitio oficial** (`MegaAnimal40OficialScraper` → `POST megaanimal40.com/core/process.php` con token; seeder `updateOrCreate` con `scraper_url`/`scraper_class`/comodín MEGA en config). El scraper del proveedor (`MegaAnimal40Scraper`) queda como clase durmiente (no borrado) |
| — | `lotto-activo` | Reglamento PDF es imagen no parseable; la modalidad Dupleta 1.000× no tiene respaldo | Pendiente de reglamento legible |

## 3. Gaps de nuestro sistema

| Ref | Área | Inconsistencia | Estado |
|---|---|---|---|
| **H13** | Motor de premios | `Animalitos::calcularPremio` no normaliza acentos: "Delfin" (feed) ≠ "Delfín" (opción) → **premio 0** en animales acentuados | **Anotado en `docs/motor-premios.md`** — pendiente del ciclo del motor (fix propuesto) |
| H1 | Motor/datos | Comodín MEGA 40× sin representación en los datos de la fuente | ✅ **RESUELTO (WU f27)**: la fuente oficial megaanimal40.com trae el campo **`mega` por sorteo** (`"1"` sin comodín / `"2"` SALIÓ MEGA — JS oficial del sitio) → se captura en **`numeros_ganadores.comodin`** (bool) y queda documentado en config (`comodines.mega` 40×). Fixture sintético + captura real pendiente del primer comodín; la **liquidación 40×** es del ciclo del motor |
| H8b | Datos | Comodines de Selva (A 160× / B 200×) documentados; representación en los datos **RESUELTA**: viaja como **LETRA `"A"`/`"B"`** en `result` (observado en producción 2026-09-15 09:15 → Comodín A) | ✅ Captura estructurada implementada (`comodin` + `comodin_nombre` en `numeros_ganadores`); liquidación → ciclo del motor |
| H14 | Datos | Monje: 7 figuras sin nombre oficial (37, 39, 57, 65, 67, 68, 75) + semántica de `special_result` sin documentar | **ZOO RESUELTO (WU f25)**: muestreo del histórico oficial de **75 días (2026-07-02..09-14, ~900 sorteos)** confirmó 37 Tortuga, 39 Lechuza, 57 Pato, 65 Araña, 67 Avestruz, 68 Jaguar y **75 Patronus** → **77 figuras completas, sin pendientes**. Queda abierto: **premio de El Patronus** (reglamento 404) y la **semántica de `special_result`** (NO es siempre 1: varía 1/0 ~9/12 por día, horas no fijas, solo en Monje — ver seguimiento-verificacion.md) |
| H3 | Motor | `premio_multiplo` estático vs reglas reales por modalidad | Ciclo del motor |
| H4 / H9 | Motor | Modalidades fuera del modelo: Dupleta 1.000×, Par Millonario 200.000×, El Patronus, Punta/Aproximación | Ciclo del motor |
| H10b | Motor | Terminales derivadas de Fácil (`n%100`) no modeladas | Ciclo del motor |
| **H21** | Scheduler | El `ScheduleServiceProvider` convertía horarios Caracas→UTC y `dailyAt()` los interpretaba de nuevo en la zona de la app (Caracas) → **doble conversión: los jobs de scrape disparaban 4 horas tarde** (en producción no había resultados en la mañana) | ✅ Corregido (registro en hora local) + test de regresión `ScheduleTimeZoneTest` |

## 4. Fuentes (notas operativas)

| Tema | Detalle | Acción |
|---|---|---|
| `cazaloton` | **cazaloton.com NO publica resultados**: sus enlaces "Resultados" apuntan a loteriadehoy.com | **Se mantiene loteriadehoy como fuente** ✅ (WU f24); reglamento oficial verificado (38 figs, 30×, dupleta 800×, tripleta 200×) |
| `triple-chance` | URL oficial: `tuchance.com.ve` — "Chance en línea" expone resultados vía API scalalot; `/reglamentos` es PDF escaneado; afiche oficial parseable | ✅ **MIGRADO al API oficial** (WU f24): `TripleChanceOficialScraper` + premios del afiche (600×) en config. **Hallazgo nuevo**: la informativa declara 3 sorteos (1:00/4:30/8:00 PM) pero la operación real es de **11 (09:00–19:00)**; y declara "Triple A o B solo 150×" vs afiche oficial **100×** |
| `el-guacharito` | URL oficial: `elguacharitomillonario.com` → **API lotterly** (`el-guacharito-millonario`) | ✅ **MIGRADO** (WU f24): `ElGuacharitoOficialScraper` + **zoo propio de 101 figuras** (bundle oficial; la informativa tenía razón) + premios 70×/150× en config |
| `guacharo-activo` | URL oficial: `guacharoactivo.com.ve` → **API lotterly** (`guacharo-activo`) | ✅ **MIGRADO** (WU f24): `GuacharoActivoOficialScraper` + **zoo propio de 77 figuras** (bundle oficial; la informativa tenía razón) + premios 60×/120× en config |
| `mega-animal-40` | URL oficial: **megaanimal40.com** (CONALOT + Big Data Tecnology + Lotería de Cojedes). Endpoint: `POST /core/process.php` con `option=<token>`; **limitación**: solo sirve el DÍA ACTUAL (ignora fechas, sin histórico) | ✅ **MIGRADO** (WU f27): `MegaAnimal40OficialScraper` + seeder `updateOrCreate` con `scraper_url`/`scraper_class` + **comodín MEGA capturado** (`mega:"2"` → `numeros_ganadores.comodin`). Contrato JSON enriquecido (`comodines`/`modalidades`). Los históricos del proveedor en BD quedan (documentado) |
