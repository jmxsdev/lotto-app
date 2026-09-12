# Registro de fuentes oficiales por juego

Objetivo: tener en un solo lugar la **fuente oficial** de cada juego (para scrapers y verificación de
opciones/premios) y su **página informativa** de referencia. En cada WU de juego se verifica la fuente
oficial y se compara contra la informativa para detectar desajustes (patrón validado con Selva Plus).

> Política completa en `docs/estrategia-scrapers-premios.md`:
> scrapers SIEMPRE desde fuentes oficiales; la informativa (resultadosvenezuela.com) solo como consulta.

## Registro

| # | Juego (slug) | Tipo | Fuente oficial (scraper) | Página informativa | Verificación oficial vs informativa |
|---|---|---|---|---|---|
| 1 | `lotto-activo` | animalitos | lottoactivo.com `/resultados/animalitos/` | RV `/lottery/lotto-activo` | ⏳ pendiente |
| 2 | `triple-zulia` | tripletas | resultadostriplezulia.com (API) | RV `/lottery/triple-zulia` | ⏳ pendiente |
| 3 | `terminal-activo` | terminales | lottoactivo.com `/resultados/terminal_activo/` | — (sin página) | ⏳ pendiente |
| 4 | `lotto-activo-rd` | animalitos | lottoactivo.com `/resultados/animalitos/` | RV `/lottery/lotto-activo-rd` | ⏳ pendiente |
| 5 | `lotto-activo-rep-dom` | animalitos | lottoactivo.com `/resultados/animalitos/` | RV `/lottery/lotto-activo-rdominicana` | ⏳ pendiente |
| 6 | `monje-millonario` | animalitos | lottoactivo.com `/resultados/animalitos/` | RV `/lottery/monje-millonario` | ⏳ pendiente (proveedor: 77 figs — verificar) |
| 7 | `trio-activo` | tripletas | lottoactivo.com `/resultados/trio_activo/` | RV `/lottery/trio-activo` | ⏳ pendiente (proveedor: terminales 3 cifras — verificar) |
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

## Notas

- Fuentes marcadas como **agregador** (loteriadehoy.com) deben migrarse a fuente oficial cuando el
  cliente la proporcione; mientras tanto el scraper funciona, pero la fidelidad de reglas/opciones se
  verifica contra su fuente oficial si existe.
- La verificación oficial vs informativa se completa **en cada WU de juego** (opciones, horarios,
  premiación) y los desajustes se registran en `docs/comparacion-juegos.md`.
- Extraoficialmente también sirven como referencia las plataformas multi-juego ya mapeadas en
  `docs/plataformas-juegos.md` (premierpluss, lotterly, resultadosvenezuela, laricachona).
