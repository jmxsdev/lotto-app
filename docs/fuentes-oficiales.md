# Registro de fuentes oficiales por juego

Objetivo: tener en un solo lugar la **fuente oficial** de cada juego (para scrapers y verificación de
opciones/premios) y su **página informativa** de referencia. En cada WU de juego se verifica la fuente
oficial y se compara contra la informativa para detectar desajustes (patrón validado con Selva Plus).

> Política completa en `docs/estrategia-scrapers-premios.md`:
> scrapers SIEMPRE desde fuentes oficiales; la informativa (resultadosvenezuela.com) solo como consulta.

## Registro

| # | Juego (slug) | Tipo | Fuente oficial (scraper) | Página informativa | Verificación oficial vs informativa |
|---|---|---|---|---|---|
| 1 | `lotto-activo` | animalitos | lottoactivo.com `/resultados/animalitos/` | RV `/lottery/lotto-activo` | ✅ verificada (WU f22): feed con **12 sorteos 08:00–19:00** y zoo 38; **23 = Cebra** corregido (feed oficial + reglamento Ruleta Royal); FAQ oficial 30×. El texto de la web dice "11 sorteos 09:00–19:00" (desactualizado, H16) |
| 2 | `triple-zulia` | tripletas | resultadostriplezulia.com (API) | RV `/lottery/triple-zulia` | ✅ verificada (WU f22): API con SOLO **3 sorteos 12:45/16:45/19:05** y A/B/C+**12 signos** (234 registros). Sin reglamento oficial → premio 30 pendiente |
| 3 | `terminal-activo` | terminales | lottoactivo.com `/resultados/terminal_activo/` | — (sin página) | ✅ verificada (WU f22): oficialmente "Terminal Trío"; feed 12 sorteos 08:00–19:00; el terminal son los **2 últimos dígitos del Trío Activo**; reglamento oficial (Trio_Activo.pdf, md5 idéntico al enlazado) TERMINAL **60×** → corregido 20→60; FAQ oficial dice 70×+5× aprox (H12) |
| 4 | `lotto-activo-rd` | animalitos | lottoactivo.com `/resultados/animalitos/` | RV `/lottery/lotto-activo-rd` | ✅ verificada (WU f22): 12 sorteos cada hora **08:30–19:30**; zoo 38; FAQ 30×. El reglamento enlazado es de "Ruleta Royal" (mislink) y confirma 30× |
| 5 | `lotto-activo-rep-dom` | animalitos | lottoactivo.com `/resultados/animalitos/` | RV `/lottery/lotto-activo-rdominicana` | ✅ verificada (WU f22): **14 sorteos 08:00–21:00**; zoo 38; FAQ 30×. El texto oficial dice "09:00–21:00 / trece (14)" (desactualizado, H16) |
| 6 | `monje-millonario` | animalitos | lottoactivo.com `/resultados/animalitos/` | RV `/lottery/monje-millonario` | ✅ verificada (WU f22): **H2 CONFIRMADO** — "Lotto Activo 2"; feed 12 sorteos 08:05–19:05 y números **0–74** con zoo propio → creadas **70 figuras confirmadas**; **7 pendientes sin nombre oficial** (37/39/57/65/67/68/75, el 75 sería El Patronus). Reglamento `Lotto_Activo_2.pdf` → **404** |
| 7 | `trio-activo` | tripletas | lottoactivo.com `/resultados/trio_activo/` | RV `/lottery/trio-activo` | ✅ verificada (WU f22): **H5 DESMENTIDO** — es un **TRIPLE de 3 cifras** (000–999), no terminales; 12 sorteos 08:00–19:00 (el reglamento 2020 dice 3, H15); reglamento oficial `Trio_Activo.pdf`: TRIPLE **600×** (corregido 30→600), TERMINAL/PUNTA 60×; opciones corregidas a terminal 00–99 (NO hay zodiaco) |
| 8 | `triple-caliente` | tripletas | triplecaliente.com (API `gaming/results/product`) | RV `/lottery/triple-caliente` | ⏳ pendiente |
| 9 | `cazaloton` | animalitos | loteriadehoy.com (agregador — pendiente oficial) | RV `/lottery/cazaloton` | ⏳ pendiente |
| 10 | `triple-chance` | tripletas | loteriadehoy.com (agregador — pendiente oficial) | RV `/lottery/triple-chance` | ⏳ pendiente |
| 11 | `el-arrejuntado` | tripletas | serviciosintegradostriple7.com (API) | — (sin página) | ⏳ pendiente |
| 12 | `el-guacharito` | animalitos | loteriadehoy.com (agregador — pendiente oficial) | RV `/lottery/guacharito-millonario` | ⏳ pendiente (proveedor: 101 figs — verificar) |
| 13 | `guacharo-activo` | animalitos | loteriadehoy.com (agregador — pendiente oficial) | RV `/lottery/guacharo-activo` | ⏳ pendiente (proveedor: 77 figs — verificar) |
| 14 | `la-granjita` | animalitos | lagranjita.com (API `results.json?productId=1`) | RV `/lottery/la-granjita` | ✅ opciones coinciden (38) |
| 15 | `la-ricachona` | tripletas | laricachona.com (`?date=`) | RV `/lottery/la-ricachona` | ✅ opciones/signos coinciden |
| 16 | `loto-chaima` | animalitos | api.lotterly.co `/v1/results/loto-chaima/` | RV `/lottery/loto-chaima` | ✅ opciones coinciden (57) |
| 17 | `mega-animal-40` | animalitos | resultadosvenezuela.com (excepción autorizada: sin página oficial) | RV `/lottery/mega-animal-40` | ⚠️ comodín MEGA sin representación en datos (pendiente) |
| 18 | `selva-plus` | animalitos | api.lotterly.co `/v1/results/selva-plus/` | RV `/lottery/selva-plus` | ✅ verificada — proveedor ERRÓNEO (38/30×/11 vs oficial 101+2/80×/13) |
| 19 | `triple-tachira` | tripletas | tripletachira.com `pruebah.php?bt=&bt2=` (sitio oficial) | RV `/lottery/triple-tachira` | ✅ verificada (WU f18) — informativa con desajustes: 3er sorteo 19:20 vs **22:10** oficial; premios 600/60/6.000 vs **500/50/5.000** del reglamento G-20004065-3 (ver H9) |
| 20 | `triple-facil` | tripletas | api.lotterly.co `/v1/results/triple-facil/` (sitio oficial triplefacil.com → SPA lotterly) | RV `/lottery/triple-facil` | ✅ verificada (WU f19): **12 sorteos diarios 08:00–19:00 confirmados por la API oficial**; el sitio oficial NO publica cifras ni reglamento → premiación INFORMATIVA (700×/60×/10×). **Hallazgo terminales**: los "3 resultados" de la web (prev/main/next) NO son independientes — main es el triple y prev/next son terminales DERIVADAS (2 últimos dígitos ±1, cálculo del front); probados los slugs `triple-facil-terminal`, `terminal-facil`, etc. en lotterly → **400 "product_slug does not exist"** (no existe producto terminal aparte) |
| 21 | `triple-zamorano` | tripletas | triplezamorano.com (API `gaming/results/product`, `game_product_id` `1` — misma casa/plataforma que Triple Caliente) | RV `/lottery/triple-zamorano` | ✅ verificada (WU f20): **5 sorteos diarios 10:00/12:00/14:00/16:00/19:00 confirmados por la API oficial** (87 días de histórico; domingos solo 19:00); la informativa declara **3 sorteos** 12:00/16:00/19:00 → **desajuste de horario** (H11). Premios informativos 600×/60×/6.000×/600× SIN fuente oficial verificada (los mismos valores que RV declaró para Triple Táchira resultaron EQUIVOCADOS — H9) → `premio_multiplo` 30× default, pendiente del reglamento oficial. Operador (informativa): Operadora 1923, C.A. / Lotería del Zulia |

## Notas

- Fuentes marcadas como **agregador** (loteriadehoy.com) deben migrarse a fuente oficial cuando el
  cliente la proporcione; mientras tanto el scraper funciona, pero la fidelidad de reglas/opciones se
  verifica contra su fuente oficial si existe.
- La verificación oficial vs informativa se completa **en cada WU de juego** (opciones, horarios,
  premiación) y los desajustes se registran en `docs/comparacion-juegos.md`.
- Extraoficialmente también sirven como referencia las plataformas multi-juego ya mapeadas en
  `docs/plataformas-juegos.md` (premierpluss, lotterly, resultadosvenezuela, laricachona).
