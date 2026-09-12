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
| 9 | `cazaloton` | `/lottery/cazaloton` | ✅ |
| 10 | `triple-chance` | `/lottery/triple-chance` | ✅ |
| 11 | `el-arrejuntado` | — (no está en el sitemap) | ❌ no tiene página |
| 12 | `el-guacharito` | `/lottery/guacharito-millonario` | ✅ |
| 13 | `guacharo-activo` | `/lottery/guacharo-activo` | ✅ |
| 14 | `la-granjita` | `/lottery/la-granjita` | ✅ |
| 15 | `la-ricachona` | `/lottery/la-ricachona` (Triple y Terminal) + `/lottery/la-ricachona-animalito` | ✅ (2 páginas) |
| 16 | `loto-chaima` | `/lottery/loto-chaima` | ✅ |
| 17 | `mega-animal-40` | `/lottery/mega-animal-40` | ✅ (integrado en este WU) |
| 18 | `selva-plus` | `/lottery/selva-plus` | ✅ (integrado en este WU) — ⚠️ el proveedor declara datos EQUIVOCADOS, ver hallazgo H8 |
| — | `triple-tachira`, `triple-caracas`, `triple-zamorano`, `triple-uneloton`, `triple-centena`, `triple-dorado`, `triple-facil`, `granjita-plus`, `granja-millonaria`, `granjazo`, `ruleta-activa`, `la-ruca`, `chance-animalitos`, `centena-*` | — | ⏳ pendientes de integración (fuera de alcance) |

**Resumen**: 15 de nuestros 16 juegos tienen página en el proveedor; `terminal-activo` y
`el-arrejuntado` NO (el proveedor tiene terminales propios, pero de otros juegos).

## Nivel 1 — Opciones disponibles (zoológicos / figuras)

Comparación del número de opciones que ofrecemos por juego (tabla `juego_opciones` o plugin por
fallback) vs el zoológico/tabla que declara el proveedor:

| Juego | Nuestras opciones | Opciones del proveedor | ¿Coinciden? |
|---|---|---|---|
| lotto-activo | 38 (tabla `juego_opciones`, canónico) | **38** animalitos (Delfín/Ballena 0 … Culebra 36) | ✅ idéntico |
| lotto-activo-rd | 38 (plugin Animalitos) | **38** animalitos | ✅ |
| lotto-activo-rep-dom | 38 (plugin Animalitos) | **38** animalitos | ✅ |
| monje-millonario | 38 (plugin Animalitos) | **77 figuras** (76 animales + "El Patronus" 75) | ❌ nos faltan 39 figuras |
| trio-activo | 12 signos (plugin Tripletas) | **10 figuras** (dígitos 0–9 de terminales) | ❌ conceptualmente distinto |
| triple-caliente | 12 signos (tabla) | A/B + 12 signos zodiacales | ✅ signos |
| cazaloton | 38 (plugin Animalitos) | (la página no declara zoo; 11 horarios 09:00–19:00) | ⚠️ sin dato público |
| el-guacharito | 38 (plugin Animalitos) | **101 animalitos** | ❌ nos faltan 63 |
| guacharo-activo | 38 (plugin Animalitos) | **77 animalitos** | ❌ nos faltan 39 |
| la-granjita | 38 (plugin Animalitos) | **38** animalitos | ✅ |
| la-ricachona | 12 signos (plugin Tripletas) | animalito: **38**; triples: 12 signos | ~ nuestra versión es la de triples |
| loto-chaima | 57 (tabla propia, 0–55) | **57** figuras (0–55) | ✅ idéntico |
| selva-plus | **103** (tabla propia: 101 figuras 0–99 + 2 comodines) | **38** animalitos (según el proveedor) | ❌ el proveedor declara menos figuras que las reales |
| mega-animal-40 | 38 (plugin Animalitos) | **38** animalitos | ✅ |
| triple-zulia | 12 signos (tabla) | A/B + **Zodiaco del Zulia** (12 signos) | ✅ |
| triple-chance | 12 signos (tabla) | A/B/C + **Signo Zodiacal** (12 signos) | ✅ |

**Conclusiones Nivel 1**:

1. Los juegos de la familia Lotto Activo (lotto-activo, RD, RDominicana) usan el zoológico
   canónico de 38 — coincide con el nuestro.
2. **monje-millonario** (oficialmente "Lotto Activo DOS El Patronus 75"), **guacharo-activo**
   (77) y **guacharito-millonario** (101) usan zoológicos MÁS GRANDES que los 38 canónicos que
   nosotros ofrecemos. El proveedor publica resultados con números > 36 (p. ej. Guácharo 40→Avispa,
   53→Caracol; Guacharito 78→Antílope, 77→Pingüino; Monje 42→Tucán, 60→Camaleón). **Nuestro plugin
   Animalitos no conoce esos números** → si el cliente quiere apostar esos juegos con su zoo real,
   hay que modelar zoológicos propios (patrón Loto Chaima) y revisar el plugin/validación.
3. **trio-activo**: el proveedor lo trata como un juego de TERMINALES (3 cifras 000–999 con
   dígitos representados por 10 figuras), mientras que nosotros lo registramos como tripletas
   (12 signos, 12 horarios). El proveedor declara **3 sorteos diarios**; nosotros tenemos 12
   horarios. Hay que validar con la fuente oficial (lottoactivo.com) cuál es el modelo correcto.
4. **cazaloton** no declara su zoo en la página (solo horarios 09:00–19:00, que coinciden).

## Nivel 2 — Tipo de premiación

**Nuestro modelo**: `premio_multiplo` estático por juego en `config` (30 en casi todos,
20 en terminal-activo). Un solo multiplicador, aplicado por el plugin (`calcularPremio`).

**Modelo del proveedor** (verificado en los textos de cada página):

| Juego | Premio declarado por el proveedor |
|---|---|
| mega-animal-40 | **30x normal; 40x con comodín "MEGA" automático** (sin costo extra) |
| lotto-activo | 30x (animal sencillo); modalidad **Dupleta** (dos animalitos) con premio mayor |
| lotto-activo-rd / rdominicana | 30x Tradicional; **Dupleta 1.000x** (dos animalitos en dos sorteos consecutivos en orden exacto) |
| monje-millonario | Simple 30x; **El Patronus (75) premio especial superior** |
| guacharo-activo | **hasta 60x según modalidad** |
| guacharito-millonario | animalito **70x**; modalidad especial Guacharito (99) **150x** |
| la-granjita | siempre 30x |
| la-ricachona-animalito | 30x |
| loto-chaima | 40x |
| selva-plus | el proveedor declara **30x y 11 sorteos** — la verdad OFICIAL (API lotterly.co) es **80× base** (1→80, 5→400, 10→800, 50→4.000, 100→8.000) + **comodín A "Leoncito" 160×** y **comodín B "Selva Plus" 200×**; **13 sorteos** 08:15–20:15 |
| trio-activo | Triple **600x** (3 cifras exactas); Terminal **60x**; Punta **60x** |
| triple-caliente | A/B **600x** (6.000 Bs por cada 10 Bs); Terminal/Triple-Terminal con otros pagos |
| triple-zulia | A/B **600x**; **Zodiaco hasta 6.000x** (sección de mayor premio) |
| triple-chance | 2 secciones (A y B Millonario), **7 modalidades** con pagos escalonados (10x–600x) |
| cazaloton | (la página no declara premios) |

**Conclusiones Nivel 2**:

1. El proveedor (y detrás, los reglamentos oficiales de cada juego) usa **esquemas de premios
   escalonados por modalidad** (30x/60x/150x/600x/1.000x/6.000x), mientras que nuestro modelo es
   **un solo multiplicador por juego**. Nuestro `premio_multiplo: 30` NO refleja la riqueza de
   estos esquemas.
2. **Comodín MEGA (40x)**: exclusivo de mega-animal-40. No aparece como marcador en las cards del
   proveedor (~11 fechas escaneadas); el premio es automático cuando el sistema lo sortea. Nuestro
   sistema no tiene concepto de comodín → el juego quedó con `premio_multiplo: 30` (documentado,
   no implementado).
3. **Dupleta 1.000x** (familia Lotto Activo) y **El Patronus** (Monje) son modalidades que nuestro
   modelo de apuesta (un animal por combinación) no soporta.
4. Los premios de los TRIPLES (600x, Zodiaco 6.000x) están muy por encima de nuestro 30x: nuestro
   `premio_multiplo` para tripletas NO coincide con el reglamento real de esos juegos (posiblemente
   porque en el sistema el 30x se aplica a un modelo de apuesta distinto, p. ej. "triple seco" vs
   "triple con signo").

## Nivel 3 — Características únicas

1. **Comodín "MEGA"** (mega-animal-40): multiplicador automático 30x→40x sin costo para el jugador.
   Es el rasgo distintivo del juego y el motivo del "40" en su nombre.
2. **Operador/regulador** (según el proveedor):
   - mega-animal-40 → **Big Data Tecnology, C.A.** / Lotería de Cojedes (Reglamento N°
     DIF-RGTO-033-00, 14-nov-2023), sistema certificado por **SENCAMER**; sorteos en Edificio
     Centro Dos Caminos, Piso 6, Caracas.
   - lotto-activo familia → **Corporación BigLot 777, C.A.** / Lotería de Cojedes (RDominicana:
     versión para República Dominicana).
   - monje-millonario → **Juegos Activos, C.A.** / Lotería de Caracas.
   - guacharito-millonario → **Inversiones Unidas Plus C.A.** / Lotería del Oriente (Monagas).
   - la-granjita y la-ricachona → **Global Sport 69 C.A.** y **Operadora Loto Oriente Online 96,
     C.A.** / Lotería Internacional de Margarita (Nueva Esparta).
   - loto-chaima → **Lotería del Oriente** (Monagas).
   - triple-caliente → **Operadora Lotitran 88, C.A.** / Lotería de Cojedes.
   - triple-chance → **Inversiones Loto Real, C.A.** / Lotería de Cojedes.
   - triple-zulia → **Operadora Relámpago 99, C.A.** / Lotería del Zulia.
   - trio-activo → **Corporación Big Lot 777, C.A.** / Lotería de Oriente (Monagas).
3. **Bloques horarios reglamentarios**: mega-animal-40 divide sus 12 sorteos en **Mañana
   (09-11) / Tarde (12-17) / Noche (18-20)**; el sitio permite filtrar por bloque y declara
   historial de 90 días.
4. **Modalidades multi-resultado**: triple-caliente canta A/B + Signo; triple-zulia A/B + Zodiaco;
   triple-chance A y B Millonario + C + Signo; la-ricachona produce 2 resultados por sesión
   (triple + terminal). Nuestro esquema tripletas (A/B/C+signo en `numeros_ganadores`) cubre
   parcialmente estos modelos.
5. **Dupleta** (familia Lotto Activo): apuesta a 2 animalitos en 2 sorteos consecutivos en orden
   exacto (1.000x) — modalidad inexistente en nuestro sistema.
6. **El Patronus (75)** (monje-millonario): figura especial con premio superior — nuestro plugin
   Animalitos no la conoce (solo 0–36).
7. **Sin API pública**: el proveedor es HTML server-rendered; robots.txt prohíbe `/cache/`,
   `/includes/`, `/logs/`, `/scraper/`, `/admin-add-result.php` y `/result.php` (certificados).
   Cortesía: pocas requests + ~2s de espera.
8. El proveedor es un **AGREGADOR**: para decisiones de premios/reglas conviene contrastar con los
   reglamentos oficiales (IOBPAS) de cada operador.

## Hallazgos y decisiones pendientes

| # | Hallazgo | Decisión pendiente |
|---|---|---|
| H1 | **Comodín MEGA 40x** en mega-animal-40: no hay marcador en las cards; nuestro sistema no modela comodines. | ¿Modelar comodines en premios (p. ej. campo `comodin` en `numeros_ganadores` + multiplicador dinámico en `calcularPremio`)? ¿O mantener 30x estático y documentar la diferencia? |
| H2 | Zoológicos mayores al canónico: **monje 77, guácharo 77, guacharito 101** (números > 36 reales en resultados). | ¿Crear `JuegoOpcion` propias (patrón Loto Chaima) y zoológicos propios en los scrapers para estos juegos? Afecta plugin Animalitos (validación 0–36) y catálogo JSON. |
| H3 | `premio_multiplo` 30 en juegos cuyo reglamento paga 60x–6.000x (guácharo, guacharito, triples). | ¿Ajustar `premio_multiplo` por juego o migrar a esquemas de premios por modalidad? Requiere decisión de negocio del cliente. |
| H4 | **Dupleta 1.000x** (Lotto Activo) y **El Patronus** (Monje): modalidades no soportadas por nuestro modelo de apuesta. | ¿Ampliar el plugin Animalitos con modalidades de 2 animalitos / figura especial? (fuera de alcance actual). |
| H5 | **trio-activo**: el proveedor lo describe como terminales de 3 cifras con 3 sorteos diarios; nosotros lo tenemos como tripletas con 12 horarios. | ¿Contrastar con la fuente oficial (lottoactivo.com) y alinear type/horarios? |
| H6 | **cazaloton** sin datos de zoo/premios en su página del proveedor. | ¿Verificar con loteriadehoy.com (nuestra fuente actual) si el zoo es canónico? |
| H7 | **la-ricachona**: el proveedor separa "Triple y Terminal" (`la-ricachona`) y "Animalito" (`la-ricachona-animalito`, :10); nosotros integramos solo la versión triples. | ¿Integrar la modalidad animalito de La Ricachona como juego separado? (candidato ya listado en `docs/plataformas-juegos.md`). |
| H8 | **El proveedor está EQUIVOCADO para selva-plus**: declara 38 animalitos / 30× / 11 sorteos, pero la fuente OFICIAL (sitio selvaplus.com → API lotterly.co, misma plataforma de Loto Chaima) muestra **101 figuras (0–99) + 2 comodines** (A "Leoncito" 160×, B "Selva Plus" 200×), **80× base** y **13 sorteos diarios** 08:15–20:15. El juego lanzó el 2026-09-07 (fechas anteriores → `[]`). | Integrado con los datos oficiales (este WU). Para el futuro: contrastar SIEMPRE los agregadores con la fuente oficial antes de modelar un juego; si la representación de los comodines aparece en `result` (hoy NO observada, 65 sorteos numéricos), capturarla con el parser defensivo ya implementado. |

> Nada de lo anterior se implementa en este WU: el documento es SOLO análisis. La integración de
> juegos adicionales del proveedor queda fuera de alcance (un work unit por juego, decisión del cliente).