# Comparación de juegos: ResultadosVenezuela vs nuestro sistema

> Documento de ANÁLISIS (sin integración de juegos adicionales). Compara los juegos de NUESTRO
> catálogo que tienen página en el proveedor agregador **resultadosvenezuela.com** contra lo que
> tenemos registrado en BD/seeders (type, opciones, premio_multiplo, config, horarios).
> Relevamiento hecho el 12-sep-2026 sobre las páginas reales del proveedor (HTML capturado con
> User-Agent de navegador, `sleep ~2s` entre peticiones, respetando `robots.txt` — nunca se
> tocaron `/cache/`, `/includes/`, `/logs/`, `/scraper/`, `/admin-add-result.php`, `/result.php`).

## Mapa de juegos (nuestro catálogo ↔ página del proveedor)

| # | Nuestro juego (slug) | Página en el proveedor | ¿Existe? |
|---|---|---|---|
| 1 | `lotto-activo` | `/lottery/lotto-activo` | ✅ |
| 2 | `triple-zulia` | `/lottery/triple-zulia` | ✅ |
| 3 | `terminal-activo` | — (solo `terminal-trio`, `terminal-la-granjita`, `triple-centena-terminal`, que son OTROS juegos) | ❌ no tiene página |
| 4 | `lotto-activo-rd` | `/lottery/lotto-activo-rd` | ✅ |
| 5 | `lotto-activo-rep-dom` | `/lottery/lotto-activo-rdominicana` | ✅ |
| 6 | `monje-millonario` | `/lottery/monje-millonario` | ✅ |
| 7 | `trio-activo` | `/lottery/trio-activo` | ✅ |
| 8 | `triple-caliente` | `/lottery/triple-caliente` | ✅ |
| 9 | `cazaloton` | `/lottery/cazaloton` | ✅ (WU f24: reglamento oficial verificado; fuente se mantiene en loteriadehoy) |
| 10 | `triple-chance` | `/lottery/triple-chance` | ✅ (WU f24: MIGRADO al API oficial tuchance.com.ve; informativa desactualizada, H17) |
| 11 | `el-arrejuntado` | — (no está en el sitemap) | ❌ no tiene página |
| 12 | `el-guacharito` | `/lottery/guacharito-millonario` | ✅ (WU f24: MIGRADO al API oficial lotterly; 101 figuras CONFIRMADAS) |
| 13 | `guacharo-activo` | `/lottery/guacharo-activo` | ✅ (WU f24: MIGRADO al API oficial lotterly; 77 figuras CONFIRMADAS) |
| 14 | `la-granjita` | `/lottery/la-granjita` | ✅ |
| 15 | `la-ricachona` | `/lottery/la-ricachona` (Triple y Terminal) + `/lottery/la-ricachona-animalito` | ✅ (2 páginas) |
| 16 | `loto-chaima` | `/lottery/loto-chaima` | ✅ |
| 17 | `mega-animal-40` | `/lottery/mega-animal-40` | ✅ (WU f27: **MIGRADO al sitio oficial megaanimal40.com**; el proveedor queda solo como informativo) |
| 18 | `selva-plus` | `/lottery/selva-plus` | ✅ (integrado en este WU) — ⚠️ el proveedor declara datos EQUIVOCADOS, ver hallazgo H8 |
| 19 | `triple-tachira` | `/lottery/triple-tachira` | ✅ (integrado en este WU) — ⚠️ el proveedor declara datos EQUIVOCADOS, ver hallazgo H9 |
| 20 | `triple-facil` | `/lottery/triple-facil` | ✅ (integrado en este WU) — ⚠️ premiación INFORMATIVA (el sitio oficial no publica cifras) y terminales DERIVADAS, ver hallazgo H10 |
| 21 | `triple-zamorano` | `/lottery/triple-zamorano` | ✅ (integrado en este WU) — ⚠️ el proveedor declara 3 sorteos y premios SIN verificar; la API oficial muestra 5 sorteos, ver hallazgo H11 |
| — | `triple-caracas`, `triple-uneloton`, `triple-centena`, `triple-dorado`, `granjita-plus`, `granja-millonaria`, `granjazo`, `ruleta-activa`, `la-ruca`, `chance-animalitos`, `centena-*` | — | ⏳ pendientes de integración (fuera de alcance) |

**Resumen**: 19 de nuestros 21 juegos tienen página en el proveedor; `terminal-activo` y
`el-arrejuntado` NO (el proveedor tiene terminales propios, pero de otros juegos).

## Nivel 1 — Opciones disponibles (zoológicos / figuras)

Comparación del número de opciones que ofrecemos por juego (tabla `juego_opciones` o plugin por
fallback) vs el zoológico/tabla que declara el proveedor:

| Juego | Nuestras opciones | Opciones del proveedor | ¿Coinciden? |
|---|---|---|---|
| lotto-activo | 38 (tabla `juego_opciones`, canónico) | **38** animalitos (Delfín/Ballena 0 … Culebra 36) | ✅ idéntico |
| lotto-activo-rd | 38 (plugin Animalitos) | **38** animalitos | ✅ |
| lotto-activo-rep-dom | 38 (plugin Animalitos) | **38** animalitos | ✅ |
| monje-millonario | **77** figuras propias (tabla propia, patrón Loto Chaima — WU f22+f25) | **77 figuras** (76 animales + "El Patronus" 75) | ✅ zoo propio de 0–75 **COMPLETO CONFIRMADO** con el feed oficial (muestreo de 75 días, WU f25): los 7 nombres que faltaban quedaron confirmados (37 Tortuga, 39 Lechuza, 57 Pato, 65 Araña, 67 Avestruz, 68 Jaguar, 75 Patronus) |
| trio-activo | **100** opciones de terminal 00–99 (tabla propia, patrón Triple Fácil — WU f22) | **10 figuras** (dígitos 0–9) para las modalidades TRIPLE/TERMINAL/PUNTA | ✅ es un juego de TRIPLE de 3 cifras (no terminales); el proveedor lo describía mal (H5 desmentido) |
| triple-caliente | 12 signos (tabla) | A/B + 12 signos zodiacales | ✅ signos |
| cazaloton | 38 (plugin Animalitos) | (la página no declara zoo; 11 horarios 09:00–19:00) | ⚠️ sin dato público |
| el-guacharito | **101** figuras propias (tabla propia, patrón Loto Chaima — WU f24) | **101 animalitos** | ✅ **CONFIRMADO con la fuente oficial** (bundle de elguacharitomillonario.com → API lotterly): 00 Ballena + 0 Delfin + 01..99 Guacharito. La informativa tenía razón; migrado y corregido |
| guacharo-activo | **77** figuras propias (tabla propia, patrón Loto Chaima — WU f24) | **77 animalitos** | ✅ **CONFIRMADO con la fuente oficial** (bundle de guacharoactivo.com.ve → API lotterly): 00 Ballena + 0 Delfín + 01..75 Guacharo. La informativa tenía razón; migrado y corregido |
| la-granjita | 38 (plugin Animalitos) | **38** animalitos | ✅ |
| la-ricachona | 12 signos (plugin Tripletas) | animalito: **38**; triples: 12 signos | ~ nuestra versión es la de triples |
| loto-chaima | 57 (tabla propia, 0–55) | **57** figuras (0–55) | ✅ idéntico |
| selva-plus | **103** (tabla propia: 101 figuras 0–99 + 2 comodines) | **38** animalitos (según el proveedor) | ❌ el proveedor declara menos figuras que las reales |
| mega-animal-40 | 38 (plugin Animalitos) | **38** animalitos | ✅ |
| triple-zulia | 12 signos (tabla) | A/B + **Zodiaco del Zulia** (12 signos) | ✅ |
| triple-chance | 12 signos (tabla) | A/B/C + **Signo Zodiacal** (12 signos) | ✅ signos — fuente MIGRADA al API oficial tuchance.com.ve (WU f24) |
| triple-tachira | 12 signos (tabla propia) | A/B + **12 signos zodiacales** | ✅ signos |
| triple-facil | **100** opciones de TERMINAL (tabla propia: label "00".."99", value "0".."99"; el triple 000-999 es entrada libre, documentado en config) | triple (000-999) + terminales prev/next en la web oficial | ✅ — el proveedor NO usa signos ni figuras: las "opciones" reales son el triple (entrada libre) y el terminal (00-99). **Hallazgo H10**: prev/next son terminales DERIVADAS (2 últimos dígitos ±1), no resultados independientes |
| triple-zamorano | **12** signos (tabla propia, patrón triple-caliente) | A/C + **12 signos zodiacales** (la página del proveedor lista Triple + Astro Zamorano) | ✅ signos — el API oficial expone A + C+signo (la informativa los muestra como 2 triples + signo) |

**Conclusiones Nivel 1**:

1. Los juegos de la familia Lotto Activo (lotto-activo, RD, RDominicana) usan el zoológico
   canónico de 38 — coincide con el nuestro.
2. **monje-millonario** (oficialmente "Lotto Activo DOS El Patronus 75") usa un zoológico MÁS
   GRANDE que los 38 canónicos: **CONFIRMADO con el feed oficial (WU f22 + f25)** — el feed
   publica números 0–75 (p. ej. 42→Tucán, 49→Pereza, 74→Turpial, 75→Patronus). El **muestreo de
   75 días del histórico (2026-07-02..09-14, ~900 sorteos, WU f25)** cerró los 7 huecos que
   quedaban tras la muestra corta de f22: **37 Tortuga, 39 Lechuza, 57 Pato, 65 Araña, 67
   Avestruz, 68 Jaguar y 75 Patronus** (la figura especial de la informativa). La tabla propia
   quedó **COMPLETA: 77 figuras** (76 números 0–75 + 0 duplicado Delfín/Ballena). Sigue pendiente
   solo el premio de El Patronus (reglamento PDF 404) y la semántica de `special_result` (H14).
3. **trio-activo**: el proveedor lo describía como TERMINALES de 3 cifras con 3 sorteos; la verdad
   OFICIAL (feed lottoactivo.com + reglamento `Trio_Activo.pdf`, WU f22) es que es un juego de
   **TRIPLE de 3 cifras** (000–999) con **12 sorteos diarios 08:00–19:00** y modalidades
   TRIPLE (600×), TERMINAL (2 últimos dígitos, 60×) y PUNTA (2 primeros, 60×) — sin zodiaco.
   Corregido: premio 600, opciones terminal 00–99, horarios 08:00–19:00 (el reglamento de 2020
   declara 3 sorteos; la operación real es de 12, H15).
4. **cazaloton** no declara su zoo en la página (solo horarios 09:00–19:00, que coinciden).

## Nivel 2 — Tipo de premiación

**Nuestro modelo**: `premio_multiplo` estático por juego en `config` (30 en casi todos,
20 en terminal-activo). Un solo multiplicador, aplicado por el plugin (`calcularPremio`).

**Modelo del proveedor** (verificado en los textos de cada página):

| Juego | Premio declarado por el proveedor |
|---|---|
| mega-animal-40 | **30x normal; 40x con comodín "MEGA" automático** (sin costo extra) — **WU f27**: confirmado en el sitio oficial megaanimal40.com y el **comodín se captura en datos** (`mega:"2"` → `numeros_ganadores.comodin`) |
| lotto-activo | 30x (animal sencillo); modalidad **Dupleta** (dos animalitos) con premio mayor |
| lotto-activo-rd / rdominicana | 30x Tradicional; **Dupleta 1.000x** (dos animalitos en dos sorteos consecutivos en orden exacto) |
| monje-millonario | Simple 30x; **El Patronus (75) premio especial superior** |
| guacharo-activo | **hasta 60x según modalidad** — CONFIRMADO con el bundle oficial (WU f24): 60× regular, 120× con comodín Guácharo (75) |
| guacharito-millonario | animalito **70x**; modalidad especial Guacharito (99) **150x** — CONFIRMADO con el bundle oficial (WU f24) |
| la-granjita | siempre 30x |
| la-ricachona-animalito | 30x |
| loto-chaima | 40x |
| selva-plus | el proveedor declara **30x y 11 sorteos** — la verdad OFICIAL (API lotterly.co) es **80× base** (1→80, 5→400, 10→800, 50→4.000, 100→8.000) + **comodín A "Leoncito" 160×** y **comodín B "Selva Plus" 200×**; **13 sorteos** 08:15–20:15 |
| trio-activo | Triple **600x** (3 cifras exactas); Terminal **60x**; Punta **60x** |
| triple-caliente | A/B **600x** (6.000 Bs por cada 10 Bs); Terminal/Triple-Terminal con otros pagos |
| triple-zulia | A/B **600x**; **Zodiaco hasta 6.000x** (sección de mayor premio) |
| triple-chance | 2 secciones (A y B Millonario), **7 modalidades** con pagos escalonados (10x–600x) |
| triple-tachira | el proveedor declara A/B **600x**, Cola **60x**, Triple+Zodiacal **6.000x** y 3er sorteo **19:20** — la verdad OFICIAL (reglamento G-20004065-3, Lotería del Táchira, PDF parseable) es **A/B 500x**, Terminal/Cola **50x**, **Triple+Zodiacal 5.000x** y **3 sorteos 13:15/16:45/22:10** (el 3ro es 22:10, no 19:20) |
| triple-facil | el sitio oficial NO publica cifras ni tiene reglamento visible → premiación **INFORMATIVA** (RV `/lottery/triple-facil`): Triple completo **700×**, Terminal **60×**, Aproximación (terminal ±1) **10×**; **12 sorteos diarios 08:00–19:00 confirmados por la API oficial** (el proveedor declara 12). Operador: First Success Online C.A. / Lotería de Oriente (Monagas) |
| triple-zamorano | el proveedor declara **600x** (Triple), **60x** (Cola), **6.000x** (Astro: Triple + Signo), **600x** (Cola + Signo) y **3 sorteos** 12:00/16:00/19:00 — la verdad OFICIAL (API triplezamorano.com, WU f20; **reglamento NOV2025, WU f26**) es **5 sorteos diarios 10:00/12:00/14:00/16:00/19:00** (domingos solo 19:00) y premios del **reglamento oficial** (Lotería del Zulia): TRIPLE **600×**, COLA **60×**, UÑA **5×**, ASTRO **6.000×**, COLA+SIGNO **600×**, UÑA+SIGNO **60×** → `premio_multiplo` **600** (antes 30× default; H11 resuelto: los valores del template resultaron correctos, ahora con fuente). Operador (informativa): Operadora 1923, C.A. / Lotería del Zulia |
| cazaloton | (la página no declara premios) — **reglamento oficial verificado (WU f24)**: CAZALOTÓN **30×** (Art. 22), DUPLETA **800×** (Art. 23), TRIPLETA **200×** (Art. 24). Operador: Comercializadora PegaRifa C.A. / Lotería del Mar (Sucre) |
| triple-chance | la informativa declara 7 modalidades (A/B 600×, A+B 200.000×, "solo 150×"...). **Afiche oficial de tuchance.com.ve (WU f24)**: TRIPLE A/B **600×**, TRIPLE A+B **200.000×**, SOLO A o B **100×** (discrepancia con la informativa 150×), TERMINAL **60×**, TRIPLE C+SIGNO **5.000×**, SIGNO **6×**. Reglamento publicado pero escaneado. **Informativa desactualizada en horarios** (3 sorteos vs 11 reales) |

**Conclusiones Nivel 2**:

1. El proveedor (y detrás, los reglamentos oficiales de cada juego) usa **esquemas de premios
   escalonados por modalidad** (30x/60x/150x/600x/1.000x/6.000x), mientras que nuestro modelo es
   **un solo multiplicador por juego**. Nuestro `premio_multiplo: 30` NO refleja la riqueza de
   estos esquemas.
2. **Comodín MEGA (40x)**: exclusivo de mega-animal-40. No aparece como marcador en las cards del
   proveedor (~11 fechas escaneadas); el premio es automático cuando el sistema lo sortea. **WU f27**:
   la fuente OFICIAL megaanimal40.com trae el campo `mega` por sorteo (`"2"` = salió MEGA, JS oficial
   del sitio) → **capturado en `numeros_ganadores.comodin`** y documentado en `config` (`comodines.mega`
   40×); la liquidación 40× queda para el ciclo del motor (aquí solo se captura el dato).
3. **Dupleta 1.000x** (familia Lotto Activo) y **El Patronus** (Monje) son modalidades que nuestro
   modelo de apuesta (un animal por combinación) no soporta.
4. Los premios de los TRIPLES (600x, Zodiaco 6.000x) están muy por encima de nuestro 30x: nuestro
   `premio_multiplo` para tripletas NO coincide con el reglamento real de esos juegos (posiblemente
   porque en el sistema el 30x se aplica a un modelo de apuesta distinto, p. ej. "triple seco" vs
   "triple con signo").

## Nivel 3 — Características únicas

1. **Comodín "MEGA"** (mega-animal-40): multiplicador automático 30x→40x sin costo para el jugador.
   Es el rasgo distintivo del juego y el motivo del "40" en su nombre. **WU f27**: el sitio oficial
   megaanimal40.com trae el campo `mega` por sorteo (JS: `if (b.mega == "2")` → muestra MEGA) y el
   scraper oficial lo captura en `numeros_ganadores.comodin` (bool).
2. **Operador/regulador** (según el proveedor):
   - mega-animal-40 → **Big Data Tecnology, C.A.** / Lotería de Cojedes (Reglamento N°
     DIF-RGTO-033-00, 14-nov-2023, **solo referenciado — no publicado en PDF**), sistema
     certificado por **SENCAMER**; sorteos en Edificio Centro Dos Caminos, Piso 6, Caracas.
     **WU f26**: sitio oficial encontrado: megaanimal40.com (premios 30×/40×, 12 sorteos
     09:00–20:00).
   - lotto-activo familia → **Corporación BigLot 777, C.A.** / Lotería de Cojedes (RDominicana:
     versión para República Dominicana). **WU f26**: operadora/IOBPAS confirmados por el POST
     `/core/process.php` del sitio oficial (Lotto Activo → Lotería de Cojedes; Monje → Lotería
     de Caracas; RD Internacional → Lotería Internacional de Margarita; Rep. Dom → notaría
     dominicana; Terminal Trío/Trío Activo → Lotería de Oriente). Reglamentos: imagen (Lotto
     Activo), 404 (Monje), mislink Ruleta Royal (RD/Rep.Dom) — todos descargados a
     `docs/reglamentos/`.
   - monje-millonario → **Juegos Activos, C.A.** / Lotería de Caracas.
   - guacharito-millonario → **Inversiones Unidas Plus C.A.** / Lotería del Oriente (Monagas)
     (confirmado en el bundle oficial, WU f26).
   - la-granjita y la-ricachona → **Global Sport 69 C.A.** y **Operadora Loto Oriente Online 96,
     C.A.** / Lotería Internacional de Margarita (Nueva Esparta). **WU f26**: reglamentos
     oficiales enlazados desde sus sitios (escaneados) → `docs/reglamentos/`.
   - loto-chaima → **Lotería del Oriente** (Monagas) (confirmado en el bundle, WU f26).
   - triple-caliente → **Operadora Lotitran 88, C.A.** / Lotería de Cojedes. **WU f26**:
     **reglamento oficial** publicado en triplecaliente.com (`docs/reglamentos/reglamento-triple-caliente.pdf`,
     600×/60×/6.000×/600×; 5 sorteos en reglamento vs 3 en operación — H18).
   - triple-chance → **Inversiones Loto Real, C.A.** / Lotería de Cojedes. **WU f26**: reglamento
     escaneado descargado (`docs/reglamentos/reglamento-triple-chance.pdf`).
   - triple-zulia → **Operadora Relámpago 99, C.A.** / Lotería del Zulia. **WU f26**:
     **reglamento oficial** publicado en resultadostriplezulia.com
     (`docs/reglamentos/reglamento-triple-zulia.pdf`, 600×/60×/6.000×/600×).
   - trio-activo → **Corporación Big Lot 777, C.A.** / Lotería de Oriente (Monagas).
   - triple-tachira → **Lotería del Táchira** (IOBPAS), reglamento **G-20004065-3** disponible en
     el sitio oficial (`https://tripletachira.com/docs/reglamento.pdf`, 15 páginas, texto
     extraído con pdftotext el 12-sep-2026 — parseable).
- triple-facil → **First Success Online C.A.** / Lotería de Oriente (Monagas); sin reglamento
      visible en el sitio oficial (triplefacil.com → SPA lotterly.co) — **confirmado WU f26**
      (bundle + ruta `/reglamento` sin PDF; "Operado bajo licencia de la Lotería de Oriente").
   - triple-zamorano → **Operadora 1923, C.A.** / Lotería del Zulia (IOBPAS; según la informativa —
      el sitio oficial no publica operador/reglamento). **WU f26**: **reglamento oficial NOV2025**
      publicado en triplezamorano.com (`docs/reglamentos/reglamento-triple-zamorano.pdf`,
      600×/60×/5×/6.000×/600×/60×).
3. **Bloques horarios reglamentarios**: mega-animal-40 divide sus 12 sorteos en **Mañana
   (09-11) / Tarde (12-17) / Noche (18-20)**. **WU f27**: el sitio oficial (12 sorteos 09:00–20:00)
   NO expone histórico funcional (el endpoint `/core/process.php` ignora fechas y la página
   `/historial/` usa el mismo token) → el scraper oficial solo sirve el día actual (limitación
   documentada).
4. **Modalidades multi-resultado**: triple-caliente canta A/B + Signo; triple-zulia A/B + Zodiaco;
   triple-chance A y B Millonario + C + Signo; la-ricachona produce 2 resultados por sesión
   (triple + terminal). Nuestro esquema tripletas (A/B/C+signo en `numeros_ganadores`) cubre
   parcialmente estos modelos.
5. **Dupleta** (familia Lotto Activo): apuesta a 2 animalitos en 2 sorteos consecutivos en orden
   exacto (1.000x) — modalidad inexistente en nuestro sistema.
6. **El Patronus (75)** (monje-millonario): figura especial con premio superior — **CONFIRMADA
   con el feed oficial (WU f25)**: el muestreo de 75 días del histórico mostró 4 apariciones del
   75 con el nombre "Patronus" y cerró el zoo en **77 figuras completas** (0–75). Su **premio
   especial** sigue pendiente (PDF del reglamento 404, H14).
7. **Sin API pública**: el proveedor es HTML server-rendered; robots.txt prohíbe `/cache/`,
   `/includes/`, `/logs/`, `/scraper/`, `/admin-add-result.php` y `/result.php` (certificados).
   Cortesía: pocas requests + ~2s de espera.
8. El proveedor es un **AGREGADOR**: para decisiones de premios/reglas conviene contrastar con los
   reglamentos oficiales (IOBPAS) de cada operador.

## Hallazgos y decisiones pendientes

| # | Hallazgo | Decisión pendiente |
|---|---|---|
| H1 | **Comodín MEGA 40x** en mega-animal-40: no hay marcador en las cards del proveedor; nuestro sistema no modela comodines. | ✅ **RESUELTO (WU f27)**: la fuente OFICIAL megaanimal40.com trae el campo **`mega`** por sorteo (`"1"` sin comodín / `"2"` SALIÓ MEGA — JS oficial del sitio) → se captura en **`numeros_ganadores.comodin`** (bool) y `config.comodines.mega` (40×). **Liquidación 40×** (multiplicador dinámico en `calcularPremio`) → **ciclo del motor**. Fixture sintético del campo `mega:"2"` + primer comodín real pendiente de captura. |
| H2 | Zoológicos mayores al canónico: **monje 77, guácharo 77, guacharito 101** (números > 36 reales en resultados). | **Monje Millonario CONFIRMADO con fuente oficial y CORREGIDO (WU f22) + ZOO COMPLETADO (WU f25)**: el feed oficial de lottoactivo.com muestra números 0–75 con zoo propio (49=Pereza, 42=Tucán, 74=Turpial, 75=Patronus). El **muestreo de 75 días (2026-07-02..09-14, ~900 sorteos)** confirmó los 7 nombres que faltaban (37 Tortuga, 39 Lechuza, 57 Pato, 65 Araña, 67 Avestruz, 68 Jaguar, 75 Patronus) → tabla propia **COMPLETA de 77 figuras** (76 números 0–75 + 0 duplicado Delfín/Ballena). **`guácharo-activo` y `el-guacharito` CONFIRMADOS con fuente oficial y CORREGIDOS (WU f24)**: el bundle oficial de guacharoactivo.com.ve muestra **77 figuras** (00 Ballena + 0 Delfín + 01..75 Guacharo) y el de elguacharitomillonario.com **101 figuras** (00 Ballena + 0 Delfin + 01..99 Guacharito) — la informativa tenía razón en ambos. Se crearon las tablas propias (77 y 101 opciones) y se migraron los scrapers al API oficial lotterly. |
| H3 | `premio_multiplo` 30 en juegos cuyo reglamento paga 60x–6.000x (guácharo, guacharito, triples). | ¿Ajustar `premio_multiplo` por juego o migrar a esquemas de premios por modalidad? Requiere decisión de negocio del cliente. (WU f22 actualizó los 2 con reglamento oficial: Trío Activo 600× y Terminal Trío 60×.) |
| H4 | **Dupleta 1.000x** (Lotto Activo) y **El Patronus** (Monje): modalidades no soportadas por nuestro modelo de apuesta. | ¿Ampliar el plugin Animalitos con modalidades de 2 animalitos / figura especial? (fuera de alcance actual). WU f22: la Dupleta solo aparece en la informativa, NO en el reglamento oficial disponible; El Patronus sin reglamento (PDF 404). |
| H5 | **trio-activo**: el proveedor lo describe como terminales de 3 cifras con 3 sorteos diarios; nosotros lo tenemos como tripletas con 12 horarios. | **DESMENTIDO con fuente oficial y CORREGIDO (WU f22)**: el feed oficial devuelve 12 sorteos/día 08:00–19:00 de un **TRIPLE de 3 cifras** (no terminales). El reglamento oficial define TRIPLE/TERMINAL/PUNTA (NO hay zodiaco) → `premio_multiplo` 30→**600** (TRIPLE), `modalidades` {terminal:60, punta:60} y opciones corregidas de 12 signos a **terminal 00–99**. El reglamento de 2020 declara 3 sorteos pero la operación real es de 12 (H15). |
| H6 | **cazaloton** sin datos de zoo/premios en su página del proveedor. | **Resuelto (WU f24)**: el reglamento oficial de cazaloton.com (Reglamento.pdf, 17 págs parseable) confirma 38 figuras / 11 horarios / **30×** simple + modalidades DUPLETA **800×** y TRIPLETA **200×** → registradas en `config`. La fuente de resultados se mantiene en loteriadehoy (cazaloton.com NO publica resultados; sus enlaces apuntan al agregador). Operador: Comercializadora PegaRifa C.A. / Lotería del Mar (Sucre). |
| H7 | **la-ricachona**: el proveedor separa "Triple y Terminal" (`la-ricachona`) y "Animalito" (`la-ricachona-animalito`, :10); nosotros integramos solo la versión triples. | ¿Integrar la modalidad animalito de La Ricachona como juego separado? (candidato ya listado en `docs/plataformas-juegos.md`). |
| H8 | **El proveedor está EQUIVOCADO para selva-plus**: declara 38 animalitos / 30× / 11 sorteos, pero la fuente OFICIAL (sitio selvaplus.com → API lotterly.co, misma plataforma de Loto Chaima) muestra **101 figuras (0–99) + 2 comodines** (A "Leoncito" 160×, B "Selva Plus" 200×), **80× base** y **13 sorteos diarios** 08:15–20:15. El juego lanzó el 2026-09-07 (fechas anteriores → `[]`). | Integrado con los datos oficiales (este WU). Para el futuro: contrastar SIEMPRE los agregadores con la fuente oficial antes de modelar un juego; si la representación de los comodines aparece en `result` (hoy NO observada, 65 sorteos numéricos), capturarla con el parser defensivo ya implementado. |
| H9 | **El proveedor está EQUIVOCADO para triple-tachira** (verificado en el WU f18 contra el sitio oficial tripletachira.com y su reglamento G-20004065-3): la informativa declara A/B **600×**, Cola **60×**, Triple+Zodiacal **6.000×** y **3er sorteo 19:20** + domingos 17:10; la verdad OFICIAL es **A/B 500×**, Terminal/Cola **50×**, **Triple+Zodiacal 5.000×** y **3 sorteos 13:15/16:45/22:10** (1:15/4:45/10:10 PM; el 3ro es **22:10**, no 19:20). El reglamento añade modalidades fuera de nuestro modelo de apuesta: Terminal+Zodiacal 500×, Par Millonario 200.000×, aproximación 10×, Terminal del Par 5.000×/5×. **Comportamiento dominical NO uniforme** en la muestra: 06-sep solo sorteo de 22:10 (829/232/926-PIC), 13-sep ninguno — pendiente de confirmar con más muestras (la informativa dice 17:10). | Integrado con los datos oficiales (este WU): seeder con `premio_multiplo` 500 y `modalidades` {cola: 50, zodiacal: 5000} (valores del reglamento); horarios 13:15/16:45/22:10; el scraper parsea la columna por fecha y salta `--------` (los domingos sin sorteo devuelven `[]`). Para el futuro: confirmar el horario dominical con más muestras y decidir si se modelan las modalidades Par Millonario/Terminal (requieren ampliar el modelo de apuesta). |
| H10 | **"Triple Fácil": los "3 resultados" de la web NO son resultados independientes** (verificado en el WU f19 contra la web oficial triplefacil.com → API lotterly.co): la web muestra por sorteo `prev / main / next`, donde `main` es el TRIPLE (3 cifras) y `prev`/`next` son **terminales DERIVADAS** — los 2 últimos dígitos ±1, calculados matemáticamente en el front (función oficial `r = n % 100`, prev = r-1, next = r+1). NO existe un juego/producto terminal aparte: probados los slugs `triple-facil-terminal`, `terminal-facil`, `triple-facil-terminales`, `terminales-facil` en lotterly → todos **400 "product_slug does not exist"**. Además el sitio oficial NO publica cifras de premios ni reglamento visible → la premiación (700×/60×/10×) es INFORMATIVA (RV `/lottery/triple-facil`). El juego NO tiene signos zodiacales: las "opciones" reales son el triple (000-999, entrada libre) y el terminal (00-99). | Integrado como UN juego (`triple-facil`, type tripletas): el scraper persiste el TRIPLE en `triple_a` (`{"pais":"VE","triple_a":"346"}`); el seeder registra las **100 opciones del terminal real (00-99)** y documenta en `config` las modalidades (terminal 60×, aproximación 10×) + `premio_multiplo` 700 informativo. El motor NO calcula las terminales derivadas ni usa `premio_multiplo` aún (gap conocido). Para el futuro: decidir si el motor debe derivar el terminal (n % 100) del triple para validar apuestas de terminal/aproximación, o si se modela la modalidad de apuesta terminal sobre el triple. |
| H11 | **El proveedor declara 3 sorteos y premios SIN verificar para triple-zamorano** (verificado en el WU f20 contra la API oficial triplezamorano.com, misma casa/plataforma que Triple Caliente): la informativa declara **3 sorteos** 12:00/16:00/19:00 (texto idéntico al de lotoven, claramente STALE) y premios **600×/60×/6.000×/600×**; la verdad OFICIAL es **5 sorteos diarios 10:00/12:00/14:00/16:00/19:00** (87 días de histórico; domingos solo 19:00 — consistente en TODA la muestra, a diferencia del domingo no-uniforme de Táchira H9). **Premios RESUELTOS (WU f26)**: el **reglamento oficial NOV2025** publicado en el propio triplezamorano.com (Lotería del Zulia G-20007649-6, PDF parseable → `docs/reglamentos/reglamento-triple-zamorano.pdf`) confirma TRIPLE **600×**, COLA **60×**, UÑA **5×**, ASTRO **6.000×**, COLA+SIGNO **600×** y UÑA+SIGNO **60×** → `premio_multiplo` 30→**600** + modalidades (la informativa era correcta en 600/60/6.000/600; la UÑA 5× no la declaraba). Queda **H19**: el reglamento declara sorteos L-D pero la API muestra domingos solo 19:00 — confirmar con el operador. Resultados oficiales: SOLO A y C (NO hay B): A = triple de 3 cifras; C = triple de 3 cifras + signo (`452-ARI`). | Integrado con los datos oficiales (este WU): seeder con `premio_multiplo` **600** + `modalidades` {cola:60, uña:5, zodiacal:6000, cola_signo:600, uña_signo:60} y horarios **10:00/12:00/14:00/16:00/19:00**; el scraper parsea A + C+signo y filtra el histórico por fecha. Para el futuro: confirmar el horario dominical (solo 19:00 en la muestra) con el operador (H19). |
| H12 | **Terminal Trío: el reglamento oficial paga 60× pero el FAQ oficial declara 70×** (WU f22). El reglamento `Trio_Activo.pdf` (byte-idéntico al `Terminal_Trio.pdf` enlazado por el sitio, md5 `50148e8e…`) declara la modalidad TERMINAL en **60×**; la informativa (RV) también dice 60×; el **FAQ oficial** dice "**70 veces** lo apostado. También puedes ganar **5 veces** por aproximación". Se usó el valor del reglamento (60×, patrón Táchira) y se corrigió `premio_multiplo` 20→60. | **Decisión de negocio pendiente**: confirmar con el operador si la liquidación del terminal es 60× o 70× (+5× aprox) y actualizar en el ciclo del motor de premios. |
| H13 | **Normalización de acentos en `Animalitos::calcularPremio`** (WU f22, descubierto al verificar Monje). El feed oficial entrega los nombres **sin acentos** ("Delfin", "Caiman", "Aguila", "Raton", "Leon") y las opciones de la tabla canónica tienen acentos ("Delfín", "Caimán", "Águila", "Ratón", "León") → la comparación `strtolower($animalApostado) === strtolower($animalGanador)` NO iguala y el premio sale 0. Afecta a toda la familia Lotto Activo y a Monje (los juegos con tabla; los de plugin usan labels sin acentos y sí coinciden). | Es del **MOTOR** (ciclo futuro, fuera de alcance del WU f22): normalizar acentos (p. ej. `Str::ascii`) en la comparación de `calcularPremio` o al persistir `nombre_animal`. **No se tocó** en este WU por la regla "no cambiar la lógica de premios del motor". |
| H14 | **Monje Millonario: 7 números del zoo sin nombre oficial + El Patronus + `special_result`** (WU f22, **ZOO RESUELTO en WU f25**). El **muestreo del histórico oficial de 75 días (2026-07-02..09-14, ~900 sorteos)** confirmó los 7 nombres que la muestra corta de f22 (13 días/66 resultados) no había visto: **37 Tortuga, 39 Lechuza, 57 Pato, 65 Araña, 67 Avestruz, 68 Jaguar y 75 Patronus** (la figura especial de la informativa, 4 apariciones). El feed marca `special_result` en Monje como **flag 1/0 variable (~9/12 por día, horas no fijas)** — NO es siempre 1 como sugirió la muestra corta; el resto de la familia trae 0. | Zoo **COMPLETO: 77 figuras** (76 números 0–75 + 0 duplicado Delfín/Ballena; sin números pendientes). Queda pendiente: **premio especial de El Patronus** (reglamento `Lotto_Activo_2.pdf` da 404) y la **semántica de `special_result`** (¿flag de sorteo especial? sin documentación oficial; pedir al operador). |
| H15 | **Trío Activo: reglamento 2020 (3 sorteos) vs operación real (12 sorteos)** (WU f22). El reglamento `Trio_Activo.pdf` declara "TRES (3) sorteos diarios"; el feed oficial opera **12 sorteos/día 08:00–19:00** de forma consistente (5 días). | Se priorizó la **operación real** (lo que consume el scraper): horarios 08:00–19:00. Confirmar con el operador si el reglamento se reformó (el propio reglamento permite reformas: "VIGÉSIMO PRIMERO"). |
| H16 | **Textos oficiales de horarios desactualizados** (WU f22). Las páginas `/informacion/<juego>/` y el FAQ declaran "**once (11) sorteos** 09:00–19:00" para Lotto Activo, Trío Activo y Terminal Trío, y "trece (14) sorteos 09:00–21:00" para Rep. Dominicana; el feed real opera **12 (08:00–19:00)** y **14 (08:00–21:00)** respectivamente. | Se priorizó el **feed operativo** (fuente de datos del scraper). No requiere acción de datos; documentado para no "corregir" horarios contra textos desactualizados. |
| H17 | **La informativa está desactualizada para `triple-chance`** (WU f24): la página de RV declara "3 sorteos diarios 1:00/4:30/8:00 PM (domingos 8:00 PM)" y "Triple A o B solo: 150 Bs por 1 Bs"; la fuente OFICIAL (tuchance.com.ve → API scalalot) opera **11 horarios 09:00–19:00** (5 días muestreados) y el **afiche oficial** declara "SOLO el TRIPLE A o el TRIPLE B: **100×**". El reglamento oficial existe pero es un **PDF escaneado** (no parseable); se usó el afiche (PDF texto) como fuente de premios. | **MIGRADO (WU f24)**: `TripleChanceOficialScraper` contra el API oficial (AYB+ASTRAL → A/B/C+signo); config con premios del afiche (600×, 200.000×, 100×, 60×, 5.000×, 6×). La discrepancia 100× vs 150× (afiche vs informativa) queda documentada; si el cliente confirma el reglamento escaneado, contrastar. **WU f26**: los 2 PDFs de `/reglamentos/` (VIGENTE 8 págs + 2024 19 págs) descargados a `docs/reglamentos/` para que el cliente extraiga los textos. |
| H18 | **`triple-caliente`: el reglamento declara 5 sorteos pero la operación real es de 3** (WU f26). El reglamento oficial (Art. 10, Lotería de Cojedes) declara **5 horarios 11:10/13:10/15:10/17:10/19:10** (domingos solo 19:10); la API oficial (timestamps, 234 respuestas) opera **3 sorteos 13:00/16:30/19:10** (domingos solo 19:10). | Se prioriza la **operación real** (13:00/16:30/19:10 — lo que consume el scraper), misma política que H15/H16. Confirmar con el operador si el reglamento se reformó. Los premios SÍ se aplican del reglamento: TRIPLE A/B/C **600×**, TERMINAL **60×**, SIGNO CALIENTE **6.000×**, TERMINAL SIGNO **600×** → `premio_multiplo` 30→**600** + modalidades. |
| H19 | **`triple-zamorano`: el reglamento NOV2025 declara sorteos todos los días, la API muestra domingos solo 19:00** (WU f26). El reglamento (Art. 10) declara 5 horarios **L-D**; la muestra de la API (87 días, WU f20) muestra **domingos solo 19:00** (consistente). | Se mantiene la operación real (domingos solo 19:00). Confirmar con la Operadora 1923 C.A. / Lotería del Zulia. |
| H20 | **`mega-animal-40`: encontrado el sitio oficial megaanimal40.com** (WU f26) — con logos CONALOT, Big Data Tecnology y Lotería de Cojedes; publica premios (**30× animal / 40× comodín MEGA**), horarios (12 sorteos 09:00–20:00), 38 figuras y resultados. El scraper actual usa resultadosvenezuela.com (excepción autorizada del WU f14: "sin página oficial"). El **reglamento N° DIF-RGTO-033-00 (14-nov-2023) solo está referenciado** (RV + actas), no publicado en PDF accesible. | ✅ **RESUELTO (WU f27)**: scraper **MIGRADO al sitio oficial** (`MegaAnimal40OficialScraper` → `POST megaanimal40.com/core/process.php` con `option=<token>`; seeder `updateOrCreate` con `scraper_url`/`scraper_class` + comodín MEGA en config; contrato JSON enriquecido con `comodines`/`modalidades`). El scraper del proveedor queda como clase durmiente. **Limitación**: el endpoint solo sirve el DÍA ACTUAL (ignora fechas, sin histórico) → los históricos del proveedor en BD quedan. Reglamento DIF-RGTO-033-00 sigue sin PDF (pendiente de la Lotería de Cojedes). |

> Nada de lo anterior se implementa en los WU de ANÁLISIS. El **WU f22 (verificación integral,
> lote 1)** sí implementó las correcciones respaldadas con fuente oficial para los 7 juegos
> originales: 23=Cebra (seeder + plugin), zoo propio de Monje (70 figuras, completado a **77 en
> el WU f25** con el muestreo del histórico oficial), Trío Activo
> (premio 600× + opciones terminal + modalidades), Terminal Trío (premio 60×). Los demás
> juegos del proveedor quedan fuera de alcance (un work unit por juego, decisión del cliente).
> El **WU f27** migró Mega Animal 40 a su sitio oficial (`MegaAnimal40OficialScraper`) y capturó
> el comodín MEGA en datos (**H1 y H20 resueltos**); la liquidación 40× es del ciclo del motor.