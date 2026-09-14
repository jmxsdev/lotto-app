# Seguimiento de verificación por juego

Estado de la verificación de cada juego contra su **fuente oficial**: horarios, opciones,
comodines, premiación y **reglamento oficial**. Complementa a:
- `docs/fuentes-oficiales.md` → URLs de cada fuente (registro).
- `docs/comparacion-juegos.md` → hallazgos H1–H11 (desajustes oficial vs informativa).

> Actualizado: 2026-09-14 (WU f26 — CACERÍA DE REGLAMENTOS: reglamentos oficiales obtenidos para
> Triple Zulia, Triple Caliente y Triple Zamorano (PDF parseables desde los sitios oficiales),
> Triple Chance (escaneado), La Granjita y La Ricachona (escaneados), familia Lotto Activo
> (PDFs/afiches) y Mega Animal 40 (sitio oficial con premios; reglamento solo referenciado).
> Pendientes por NO PUBLICAR: Monje (PDF 404), El Arrejuntado, El Guacharito, Guácharo Activo,
> Loto Chaima, Selva Plus y Triple Fácil (sus sitios/bundles no publican reglamento).
>
> Evidencia del LOTE 1 (2026-09-10..14): feed oficial `lottoactivo.com/resultados/animalitos/`
> (4 juegos animalitos en un JSON), `/resultados/terminal_activo/`, `/resultados/trio_activo/`,
> API `resultadostriplezulia.com`, páginas `/informacion/<juego>/` (metadata + reglamentos PDF)
> y `/preguntas_frecuentes/` (premios y horarios oficiales).

## Leyenda

- ✅ **Verificado** contra fuente oficial (con evidencia).
- ⚠️ **Con salvedad** (verificado parcialmente, la fuente no publica el dato, o falta confirmar).
- ⏳ **Pendiente** (sin verificación oficial aún).
- ❌ **No existe / no disponible** (p. ej. reglamento no publicado).
- — No aplica (el juego no tiene ese aspecto).

## Matriz por juego

| # | Juego | Horarios | Opciones | Comodines | Premiación | Reglamento | Notas |
|---|-------|:---:|:---:|:---:|:---:|:---:|---|
| 1 | `lotto-activo` | ✅ | ✅ | — | ✅ | ⚠️ | Feed oficial: 12 sorteos 08:00–19:00 (el texto de la web dice 11 desde 09:00 → nota). Zoo canónico 38; **corregido 23 Cobra→Cebra**. FAQ oficial: 30×. Reglamento PDF existe pero es imagen no parseable (**descargado**: `docs/reglamentos/reglamento-lotto-activo.pdf`); Dupleta sin respaldo en reglamento |
| 2 | `triple-zulia` | ✅ | ✅ | — | ✅ | ✅ | API oficial: SOLO 3 sorteos 12:45/16:45/19:05 y A/B/C+12 signos (234 registros). **Reglamento oficial obtenido (WU f26)**: `docs/reglamentos/reglamento-triple-zulia.pdf` (Lotería del Zulia, NOV2025, parseable): TRIPLE A/B/C **600×**, TERMINAL **60×**, ZODIACO DEL ZULIA **6.000×**, TERMINAL ZODIACO **600×** → **corregido 30→600** + modalidades en config |
| 3 | `terminal-activo` | ✅ | ✅ | — | ⚠️ | ⚠️ | Feed: 12 sorteos 08:00–19:00; terminal = **2 últimos dígitos del Trío Activo** (verificado cruzando feeds). Reglamento oficial (Trio_Activo.pdf, mismo md5 que Terminal_Trio.pdf): TERMINAL **60×** → **corregido 20→60**. **Descargado**: `docs/reglamentos/reglamento-terminal-activo.pdf`. FAQ oficial dice 70×+5× aprox → discrepancia H12 |
| 4 | `lotto-activo-rd` | ✅ | ✅ | — | ✅ | ⚠️ | Feed + metadata + FAQ: 12 sorteos cada hora 08:30–19:30. Zoo canónico 38 (23=Cebra). FAQ 30×. Reglamento enlazado por error (es de "Ruleta Royal", confirma 30× y el zoo 23=Cebra) — **descargado**: `docs/reglamentos/reglamento-lotto-activo-rd.pdf` |
| 5 | `lotto-activo-rep-dom` | ✅ | ✅ | — | ✅ | ⚠️ | Feed: **14 sorteos 08:00–21:00** (el texto oficial dice 09:00–21:00 y "trece (14)" → nota). Zoo canónico 38. FAQ 30×. Mismo mislink Ruleta Royal — **descargado**: `docs/reglamentos/reglamento-lotto-activo-rep-dom.pdf` |
| 6 | `monje-millonario` | ✅ | ✅ | — | ⏳ | ❌ | **H2 CONFIRMADO + H14 RESUELTO (WU f25)**: muestreo del feed oficial de **75 días (2026-07-02..09-14, ~900 sorteos)** → rango completo **0–75** y confirmados los 7 nombres que faltaban: 37 Tortuga, 39 Lechuza, 57 Pato, 65 Araña, 67 Avestruz, 68 Jaguar y **75 Patronus**. Zoo propio **COMPLETO: 77 figuras** (76 números + 0 duplicado). `special_result` NO es siempre 1: varía 1/0 (~9/12 por día, horas no fijas) — semántica sin documentar. **Reglamento `Lotto_Activo_2.pdf` CONFIRMADO 404 (WU f26)** + 6 variantes de nombre probadas → premio de Patronus sin fuente oficial (H14) |
| 7 | `trio-activo` | ✅ | ✅ | — | ✅ | ✅ | **H5 DESMENTIDO**: es un juego de **TRIPLE de 3 cifras** (000–999) con modalidades TRIPLE/TERMINAL/PUNTA, NO "terminales 3 cifras"; feed: 12 sorteos 08:00–19:00 (reglamento 2020 dice 3 → stale). Reglamento oficial: TRIPLE **600×** → **corregido 30→600** + modalidades terminal/punta 60×; opciones corregidas a terminal 00–99 (no hay zodiaco) |
| 8 | `triple-caliente` | ✅ | ✅ | — | ✅ | ✅ | API oficial verificada con datos reales (12 signos). **Reglamento oficial obtenido (WU f26)**: `docs/reglamentos/reglamento-triple-caliente.pdf` (Lotería de Cojedes, parseable): TRIPLE A/B/C **600×**, TERMINAL **60×**, SIGNO CALIENTE **6.000×**, TERMINAL SIGNO **600×** → **corregido 30→600** + modalidades en config. **Desajuste H18**: el reglamento declara 5 sorteos 11:10–19:10 pero la API opera 3 (13:00/16:30/19:10) → se prioriza la operación real |
| 9 | `cazaloton` | ✅ | ✅ | — | ✅ | ✅ | **Reglamento oficial verificado** (Reglamento.pdf de cazaloton.com, 17 págs, parseable): 38 figuras (0/00/1–36) ✓, 11 sorteos 09:00–19:00 ✓, CAZALOTÓN 30× ✓ → modalidades oficiales DUPLETA 800× / TRIPLETA 200× registradas en config. Fuente de resultados SE MANTIENE en loteriadehoy (cazaloton.com NO publica resultados; sus enlaces apuntan al agregador) |
| 10 | `triple-chance` | ✅ | ✅ | — | ✅ | ⚠️ | **MIGRADO a fuente oficial** (WU f24): tuchance.com.ve "Chance en línea" → API scalalot (11 horarios 09:00–19:00 ✓, 12 signos ✓). Premios del **afiche oficial** (PDF parseable): TRIPLE A/B 600×, TRIPLE A+B 200.000×, SOLO A o B 100×, TERMINAL 60×, TRIPLE C+SIGNO 5.000×, SIGNO 6× → config 600. Reglamento publicado pero **ESCANEADO (no parseable)** — **descargado (WU f26)**: `docs/reglamentos/reglamento-triple-chance.pdf` (VIGENTE) y `docs/reglamentos/reglamento-triple-chance-2024.pdf`. Informativa desactualizada (3 sorteos vs 11 reales) |
| 11 | `el-arrejuntado` | ✅ | ✅ | — | ⏳ | ❌ | API oficial verificada con datos reales (6 modalidades); premiación pendiente. **Reglamento NO publicado (WU f26)**: sitio es landing SPA (Astro) y el backend es API-only; sin PDF ni ruta de reglamento (revisadas `/reglamento`, `/docs`, `/api/v1/reglamentos`, `/openapi.json`) |
| 12 | `el-guacharito` | ✅ | ✅ | — | ✅ | ❌ | **MIGRADO a fuente oficial** (WU f24): API lotterly (12 horarios :30 ✓). **101 figuras propias** confirmadas (bundle oficial; la informativa tenía razón). Premios oficiales del bundle: animalito 70× + figura especial Guacharito (99) 150× → config 70. **Reglamento NO publicado (WU f26)**: el bundle oficial (`index-EQw1Zdrz.js`) solo tiene footer de copyright; sitio "Operado bajo licencia de la Lotería de Oriente" |
| 13 | `guacharo-activo` | ✅ | ✅ | — | ✅ | ❌ | **MIGRADO a fuente oficial** (WU f24): API lotterly (12 horarios :00 ✓). **77 figuras propias** confirmadas (bundle oficial; la informativa tenía razón). Premios oficiales del bundle: 60× + comodín Guácharo (75) 120× → config 60. **Reglamento NO publicado (WU f26)**: bundle (`index-Dv-KFMIs.js`) sin reglamento; "Operado bajo licencia de la Lotería de Oriente" |
| 14 | `la-granjita` | ✅ | ✅ | — | ⏳ | ⚠️ | API oficial: 12 horarios, 38 opciones coinciden; premiación sin dato oficial. **Reglamento oficial DESCARGADO (WU f26)**: `docs/reglamentos/reglamento-la-granjita.pdf` (enlazado desde lagranjita.com, 17 págs, **escaneado** — el cliente extraerá los textos) |
| 15 | `la-ricachona` | ✅ | ✅ | — | ⏳ | ⚠️ | HTML oficial: 12 horarios `:05`, signos coinciden; premiación sin dato oficial. **Reglamento oficial DESCARGADO (WU f26)**: `docs/reglamentos/reglamento-la-ricachona.pdf` (enlazado desde laricachona.com, 15 págs, **escaneado** — el cliente extraerá los textos) |
| 16 | `loto-chaima` | ✅ | ✅ | — | ⏳ | ❌ | API oficial: 12 horarios, 57 figuras propias coinciden; premiación sin dato oficial. **Reglamento NO publicado (WU f26)**: bundle de lotochaima.com (`index-DKeh2UsF.js`) sin reglamento; "Operado bajo licencia de la Lotería de Oriente" |
| 17 | `mega-animal-40` | ✅ | ✅ | ✅ | ✅ | ⚠️ | **MIGRADO a fuente OFICIAL (WU f27)**: `megaanimal40.com` (POST `/core/process.php` con token de resultados; CONALOT + Big Data Tecnology + Lotería de Cojedes). 12 sorteos 09:00–20:00 ✓, 38 figuras ✓, premio **30× base / 40× con el comodín MEGA** ✓. **Comodín CAPTURADO** en `numeros_ganadores.comodin` (`mega:"2"` = salió MEGA, según el JS oficial del sitio) → **H1 RESUELTO**. Carga real 14-sep: 8 sorteos persistidos (09:00–16:00), comodín NO salió hoy. **Limitación**: el endpoint oficial solo sirve el DÍA ACTUAL (ignora parámetros de fecha, sin histórico funcional) → los históricos del proveedor en BD quedan. El scraper del proveedor (`MegaAnimal40Scraper`) queda como clase durmiente (no borrado). Reglamento **DIF-RGTO-033-00 solo referenciado**, no publicado en PDF → pendiente de la Lotería de Cojedes |
| 18 | `selva-plus` | ✅ | ✅ | ⚠️ | ✅ | ❌ | API oficial: 13 sorteos, 101 figuras + 2 comodines ✓; premiación base 80×/comodines 160×/200× documentada de fuente oficial; **representación de comodines en datos aún no observada** (H8). **Reglamento NO publicado (WU f26)**: bundle de selvaplus.com (`index-BI-rgou6.js`) sin reglamento; footer con logo "Lotería de Oriente" |
| 19 | `triple-tachira` | ✅ | ✅ | — | ✅ | ✅ | **Reglamento oficial verificado** (PDF G-20004065-3): 500×/50×/5.000×, 3 sorteos 13:15/16:45/22:10; solo pendiente confirmar domingos (H9) |
| 20 | `triple-facil` | ✅ | ✅ | — | ⚠️ | ❌ | API oficial: 12 sorteos, 100 opciones terminal; premiación INFORMATIVA (700×/60×/10×) sin reglamento publicado (H10). **WU f26**: confirmado NO publicado — el bundle de triplefacil.com (`index-CnppWFwM.js`) y la ruta `/reglamento` (SPA catch-all) no exponen reglamento; "Operado bajo licencia de la Lotería de Oriente" |
| 21 | `triple-zamorano` | ✅ | ✅ | — | ✅ | ✅ | API oficial: 5 sorteos (domingos solo 19:00); **Reglamento oficial obtenido (WU f26)**: `docs/reglamentos/reglamento-triple-zamorano.pdf` (REGLAMENTO TP ZAMORANO NOV2025, Lotería del Zulia, parseable): TRIPLE **600×**, COLA **60×**, UÑA **5×**, ASTRO **6.000×**, COLA+SIGNO **600×**, UÑA+SIGNO **60×** → **corregido 30→600** + modalidades en config (**H11 RESUELTO**: la informativa 600/60/6.000/600 era correcta, ahora con fuente) |

## Resumen

- **Con reglamento oficial: 9/21** — PDF **parseables**: Triple Táchira, Trío Activo, Cazaloton,
  **Triple Zulia, Triple Caliente, Triple Zamorano** (WU f26) + **mislink Ruleta Royal** (RD/Rep.Dom,
  parseable, confirma 30×). **Escaneados (descargados, pendientes de extracción por el cliente)**:
  Triple Chance (2 PDFs), La Granjita, La Ricachona. **Imagen no parseable**: Lotto Activo.
  **Referenciado sin PDF**: Mega Animal 40 (DIF-RGTO-033-00; sitio oficial confirma 30×/40×).
- **Verificación funcional con fuente oficial (horarios/opciones): 20/21** — los 7 originales (lote 1) + Táchira, Selva, Fácil, Zamorano, Granjita, Ricachona, Chaima, Caliente, Arrejuntado + **lote 2: Cazaloton (reglamento), Triple Chance, Guacharito, Guácharo Activo (API oficial)** + **Mega Animal 40 (WU f27: sitio oficial megaanimal40.com)**. Único pendiente de fuente: nada (todos tienen fuente oficial; el único juego sin resultados publicados es Cazaloton, que mantiene loteriadehoy por decisión documentada).
- **Premiación verificada con fuente oficial: 12/21** — Táchira (reglamento), Trío Activo (reglamento), Terminal Trío (reglamento), Lotto Activo/RD/Rep.Dom (FAQ oficial 30× + mislink Ruleta Royal), Selva (base 80×), **Cazaloton (reglamento 30×), Triple Chance (afiche oficial 600×), Guacharito (bundle 70×/150×), Guácharo Activo (bundle 60×/120×), Triple Zulia, Triple Caliente y Triple Zamorano (reglamentos, 600×)** + Mega Animal 40 (sitio oficial: 30×/40× comodín MEGA; **comodín capturado en datos desde el WU f27**).
- **Pendientes mayores: reglamentos NO publicados (7 juegos) y premiaciones sin fuente oficial (4: Arrejuntado, Granjita, Ricachona, Chaima — escaneados pendientes de extracción; Fácil 700× informativo).**

## Pendientes por tipo

### A. Reglamentos oficiales por obtener

| Juego(s) | Qué falta | Impacto |
|---|---|---|
| `triple-facil`, `el-arrejuntado`, `el-guacharito`, `guacharo-activo`, `loto-chaima`, `selva-plus` | **No publicados** (verificado WU f26 en sitios/bundles oficiales) | Premiación real (Fácil hoy 700× informativo; Arrejuntado 30×; Granjita/Ricachona/Chaima escaneados pendientes de extracción) |
| `monje-millonario` | Reglamento `Lotto_Activo_2.pdf` **404** en el sitio oficial (+6 variantes probadas) | Premio especial de El Patronus (H14) |
| `mega-animal-40` | Reglamento N° **DIF-RGTO-033-00** solo referenciado, no publicado en PDF | Respaldo formal del 30×/40× (el sitio oficial ya lo confirma y el comodín MEGA se captura en datos desde el WU f27) |
| `lotto-activo` y familia | Reglamentos son imagen no parseable o mislink Ruleta Royal | Premios/modaliades (Dupleta 1.000×, etc.) |

### B. Fuentes: estado con las URLs oficiales recibidas (2026-09-14)

| Juego | URL oficial recibida | Hallazgo | Acción |
|---|---|---|---|
| `cazaloton` | `cazaloton.com` | **No publica resultados** — sus enlaces "Resultados" apuntan a loteriadehoy.com | **Se mantiene loteriadehoy** como fuente; reglamento oficial verificado (WU f24) |
| `triple-chance` | `tuchance.com.ve` | Sitio oficial "Chance en línea" expone resultados vía API scalalot + `/reglamentos` (PDF escaneado) + afiche oficial | **MIGRADO al API oficial** (WU f24): `TripleChanceOficialScraper` + premios del afiche en config |
| `el-guacharito` | `elguacharitomillonario.com` | SPA → **API lotterly** (`el-guacharito-millonario`); 101 figuras propias en el bundle | **MIGRADO** (WU f24): `ElGuacharitoOficialScraper` + zoo propio 101 |
| `guacharo-activo` | `guacharoactivo.com.ve` | SPA → **API lotterly** (`guacharo-activo`); 77 figuras propias en el bundle | **MIGRADO** (WU f24): `GuacharoActivoOficialScraper` + zoo propio 77 |

### C. Verificación oficial pendiente de los juegos originales (1–7)

✅ **LOTE 1 COMPLETADO (2026-09-14, WU f22)**. Ver evidencia detallada abajo.
Pendientes que quedaron abiertos del lote:
- **Monje Millonario (6)**: ✅ zoológico COMPLETADO en el WU f25 (muestreo de 75 días del feed
  oficial confirmó los 7 nombres que faltaban; 77 figuras). Queda pendiente el **premio de
  "El Patronus"** (el PDF del reglamento da 404 en el sitio oficial — CONFIRMADO de nuevo en el
  WU f26 con 6 variantes de nombre) y la semántica de `special_result` (H14).
- **Terminal Trío (3)**: discrepancia de premio reglamento 60× vs FAQ oficial 70×+5× aprox (H12).
- **Lotto Activo (1)**: reglamento PDF es imagen no parseable (descargado en WU f26); la
  modalidad Dupleta 1.000× de la informativa no tiene respaldo en el reglamento disponible.
- **Triple Zulia (2)**: ✅ **RESUELTO (WU f26)** — reglamento oficial obtenido (600×/60×/6.000×/600×).

### D. Puntos específicos abiertos

| Ref | Punto | Estado |
|---|---|---|
| H1 | Comodín MEGA 40× (mega-animal-40) | ✅ **RESUELTO (WU f27)**: la fuente OFICIAL megaanimal40.com trae el campo `mega` por sorteo (`"1"` sin comodín / `"2"` Salió MEGA, según el JS oficial del sitio); se captura en `numeros_ganadores.comodin` (bool). Fixture sintético + documentación del campo; el primer comodín real se capturará cuando salga. La LIQUIDACIÓN 40× pertenece al ciclo futuro del motor de premios (aquí solo se captura el dato) |
| H8 | Comodines Selva (A 160× / B 200×) | Reglas documentadas; representación en `result` aún no observada |
| H9 | Domingos de Táchira | Comportamiento no uniforme en la muestra — confirmar |
| H10 | Terminales derivadas de Fácil | Decisión de motor (derivar `n % 100` y modelar apuesta de terminal/aproximación) |
| H11 | Premios Zamorano | 600×/60×/6.000×/600× informativos sin verificar (template del agregador) |
| H12 | **Premio Terminal Trío: reglamento 60× vs FAQ oficial 70× (+5× aproximación)** | **Pendiente de decisión del cliente** — se usó el valor del reglamento (60×, patrón Táchira); falta confirmar cuál paga la operación |
| H13 | **Normalización de acentos en `Animalitos::calcularPremio`** | El feed oficial entrega nombres sin acentos ("Delfin", "Caiman") y las opciones tienen acentos ("Delfín", "Caimán") → `strtolower` no iguala y el premio sale 0. **Afecta a toda la familia Lotto Activo + Monje**; es del MOTOR (ciclo futuro), NO se toca en este WU |
| H14 | **Monje Millonario: nombres sin confirmar + El Patronus + `special_result`** | **ZOO RESUELTO (WU f25)**: muestreo del feed oficial de 75 días (2026-07-02..09-14, ~900 sorteos) confirmó 37 Tortuga, 39 Lechuza, 57 Pato, 65 Araña, 67 Avestruz, 68 Jaguar y **75 Patronus** → zoo completo de 77 figuras. Quedan pendientes: **premio de El Patronus** (reglamento 404) y la **semántica de `special_result`**: NO es siempre 1 — varía 1/0 (~9/12 por día, horas no fijas; solo en Monje; el resto de la familia trae 0) |
| H15 | **Trio Activo: reglamento (3 sorteos, 2020) vs operación real (12 sorteos)** | El feed oficial opera 12 sorteos/día 08:00–19:00; el reglamento PDF declara 3. Se priorizó la operación real (lo que consume el scraper). Confirmar con el operador |
| H16 | **Textos oficiales de horarios desactualizados** | Las páginas `/informacion/` y el FAQ declaran "once (11) sorteos 09:00–19:00" para Lotto Activo/Trío/Terminal y "trece (14)" para Rep. Dominicana, pero el feed opera 12 (08:00–19:00) y 14 (08:00–21:00) respectivamente. El feed (dato operativo) manda |
| H18 | **Triple Caliente: el reglamento declara 5 sorteos pero la operación real es de 3** (WU f26) | El reglamento oficial (Art. 10) declara 5 horarios 11:10/13:10/15:10/17:10/19:10 (domingos solo 19:10); la API oficial (timestamps, 234 respuestas) opera **3 sorteos 13:00/16:30/19:10** (domingos solo 19:10). Se prioriza la **operación real** (lo que consume el scraper), misma política H15/H16. Confirmar con el operador si el reglamento se reformó |
| H19 | **Zamorano: el reglamento NOV2025 declara sorteos todos los días, pero la API muestra domingos solo 19:00** (WU f26) | El reglamento (Art. 10) no exime domingos (5 horarios L-D); la muestra de f20 (87 días) y el reglamento NOV2025 coexistían: se mantiene la operación real (domingos solo 19:00, consistente en toda la muestra). Confirmar con la Operadora 1923 |
| H20 | **Sitios oficiales "nuevos" encontrados en la cacería (WU f26)** | ✅ **RESUELTO (WU f27)**: el scraper de Mega Animal 40 SE MIGRÓ al sitio oficial (`MegaAnimal40OficialScraper` → POST `megaanimal40.com/core/process.php`); `MegaAnimal40Scraper` (proveedor) queda como clase durmiente. Los PDFs de reglamento de triplecaliente/triplezamorano/resultadostriplezulia se aplicaron en f26 |

### F. Evidencia del LOTE 1 (7 juegos originales)

Fuentes muestreadas el 2026-09-10..14 (con `sleep` entre peticiones, User-Agent de navegador):

1. **`lotto-activo` (1)** — Feed oficial `/resultados/animalitos/` (5 días): 12 sorteos/día
   08:00–19:00, números 0–36, zoo canónico. **Hallazgo**: el número 23 es **"Cebra"** en el feed
   (los 4 juegos) y en el reglamento Ruleta Royal (PDF oficial); teníamos "Cobra" → **corregido**
   en el seeder y en el plugin Animalitos. FAQ oficial: "hasta **30 veces** lo apostado" ✓.
   Metadata oficial: "once (11) sorteos 09:00–19:00" (texto desactualizado, H16). Reglamento
   `Lotto_Activo.pdf` (1 página, imagen no parseable).
2. **`triple-zulia` (2)** — API oficial `resultadostriplezulia.com/api/gaming/results/product`
   (product_id 2): 234 registros con SOLO 3 horarios **12:45/16:45/19:05** (74/74/86) y
   A/B/C donde C = triple+signo (12 zodiacales). Coincide exactamente con nuestro seeder
   (3 horarios, 12 signos). La API no publica premios ni hay reglamento → premio 30 pendiente.
3. **`terminal-activo` (3)** — Feed oficial `/resultados/terminal_activo/`: 12 sorteos/día
   08:00–19:00 de un número de **2 cifras**. **Hallazgo**: el terminal es exactamente los
   **2 últimos dígitos del Trío Activo** (0 discrepancias en 4 días × 12 sorteos cruzando ambos
   feeds). El sitio enlaza `Terminal_Trio.pdf`, **byte-idéntico** (md5 `50148e8e…`) al reglamento
   `Trio_Activo.pdf`, que declara la modalidad TERMINAL en **60×** → **corregido 20→60**.
   El FAQ oficial declara 70× + 5× aproximación → discrepancia H12.
4. **`lotto-activo-rd` (4)** — Feed: 12 sorteos cada hora **08:30–19:30**; metadata oficial:
   "doce (12) sorteos... 08:30 AM hasta 7:30 PM"; FAQ: "Sorteos Internacionales 8:30–7:30" ✓.
   Zoo canónico 38 (23=Cebra). FAQ Lotto Activo 30×. El reglamento enlazado
   (`Lotto_Activo_Rd_Ve.pdf`) es en realidad de **"Ruleta Royal"** (mislink del sitio) y confirma
   el zoo 0–36 y el pago de **30× por animal**.
5. **`lotto-activo-rep-dom` (5)** — Feed: **14 sorteos 08:00–21:00** ✓ (coincide con nuestro
   seeder). El texto oficial dice "trece (14) sorteos... 09:00 AM hasta 9:00 PM" (H16). Zoo
   canónico 38 (0 = Ballena y Delfín). FAQ 30×. Reglamento enlazado = mismo mislink Ruleta Royal.
6. **`monje-millonario` (6)** — **H2 CONFIRMADO + H14 RESUELTO (WU f25)**. Feed oficial
   ("Lotto Activo 2 (Monje Millonario)"): 12 sorteos 08:05–19:05 ✓. El **muestreo del histórico
   de 75 días consecutivos (2026-07-02..09-14, ~900 sorteos, 76 números distintos 0–75)**
   confirmó los 7 nombres que faltaban de la muestra corta de f22: **37 Tortuga, 39 Lechuza,
   57 Pato, 65 Araña, 67 Avestruz, 68 Jaguar y 75 Patronus** (la figura especial de la
   informativa; 4 apariciones en la muestra). Zoológico propio **COMPLETO: 77 figuras**
   (76 números 0–75 + el 0 duplicado Delfín/Ballena), sin números pendientes. Hallazgo sobre
   `special_result`: **NO es siempre 1** (la muestra de 13 días de f22 era corta) — es un flag
   por resultado que varía 1/0 (~9/12 por día, horas no fijas) y solo Monje lo trae así (el
   resto de la familia trae 0) → semántica sin documentar, pendiente (H14). El PDF del
   reglamento (`Lotto_Activo_2.pdf`) da **404** → premio de Patronus sin fuente oficial (H14).
7. **`trio-activo` (7)** — **H5 DESMENTIDO**. El feed oficial `/resultados/trio_activo/` devuelve
   **12 sorteos/día 08:00–19:00** de un **TRIPLE de 3 cifras** (p. ej. "491"), no "terminales 3
   cifras ni 3 sorteos". El reglamento oficial `Trio_Activo.pdf` ("TRIOACTIVO EL PATRONUS",
   Lotería de Oriente, Monagas) define las modalidades **TRIPLE (3 cifras 000–999, 600×)**,
   **TERMINAL (2 últimos dígitos, 60×)** y **PUNTA (2 primeros, 60×)**, con **10 figuras de
   dígitos** — **NO hay zodiaco** → las 12 opciones de signos del plugin eran incorrectas.
   Corregido: `premio_multiplo` 30→**600** + `modalidades` {terminal:60, punta:60} + opciones
   de terminal **00–99** (patrón Triple Fácil H10; el triple 000–999 es entrada libre). El FAQ
   oficial confirma "hasta **600 veces**". El reglamento (2020) declara 3 sorteos pero la
   operación real es de 12 (H15).

### G. Evidencia del LOTE 2 (4 juegos de fuente agregador) — 2026-09-14

Fuentes oficiales muestreadas el 14-sep-2026 (URLs recibidas del cliente; con `sleep` entre
peticiones y User-Agent de navegador):

1. **`cazaloton` (9)** — cazaloton.com NO publica resultados (sus enlaces "Resultados" apuntan a
   loteriadehoy.com; verificado en la home). SÍ tiene **reglamento oficial**
   (`/Reglamento.pdf`, 17 páginas, texto parseable con pdftotext): juego tipo Figuras de Animalitos
   de la **Lotería del Mar (Sucre)** operado por Comercializadora PegaRifa C.A.; **38 figuras**
   (0, 00, 1–36 — el canónico del plugin, Delfín 0/Ballena 00) ✓, **11 sorteos diarios 09:00–19:00**
   (mañana/tarde/noche) ✓ y premios: CAZALOTÓN simple **30×** (Art. 22) ✓ (coincide con nuestro
   `premio_multiplo` 30), **DUPLETA 800×** (Art. 23) y **TRIPLETA 200×** (Art. 24) → registradas en
   `config['modalidades']`. FAQ del sitio: "30 veces tu apuesta por cada animalito acertado" ✓.
   **Fuente de resultados se mantiene en loteriadehoy** (decisión documentada; informar al cliente).
2. **`triple-chance` (10)** — tuchance.com.ve ("Chance en línea", WordPress) **expone resultados** vía
   API pública `api.scalalot.com` (`ConsultarResultadoSorteo/Q0hBTkNF/{timestamp}`; Q0hBTkNF =
   base64("CHANCE")) — consumida por el propio front del sitio. Verificado en vivo: **11 horarios
   09:00–19:00** (5 días muestreados) y **12 signos zodiacales** (modalidad ASTRAL) → coinciden con
   nuestro seeder. Cada horario trae 3 modalidades: CHANCE AYB (Triple A+B), CHANCE ASTRAL (Triple C
   + signo) y CHANCE ANIMALITO (2 animalitos, juego aparte). **MIGRADO**: `TripleChanceOficialScraper`
   consume AYB+ASTRAL (1 resultado por horario con A/B/C+signo). Premios del **afiche oficial**
   (PDF "FINAL-OK-AFICHE-CHANCE-PARA-IMPRIMIR...", texto parseable): TRIPLE A/B 600×, TRIPLE A+B
   200.000×, SOLO A o B 100× (la informativa declara 150× — discrepancia documentada), TERMINAL 60×,
   TRIPLE C+SIGNO 5.000×, SIGNO 6× → `config` premio 600. El reglamento (`/wp-content/uploads/.../
   REGLAMENTO-CHANCE-EN-LINEA-VIGENTE.pdf`) **existe pero es un PDF escaneado** (imágenes, no
   parseable con pdftotext). **La informativa está DESACTUALIZADA**: declara "3 sorteos 1:00/4:30/8:00
   PM" cuando la operación real es de 11 (09:00–19:00).
3. **`el-guacharito` (12)** — elguacharitomillonario.com (SPA) → **API oficial lotterly**
   (`/v1/results/el-guacharito-millonario/?exact_date=`), sin auth ni anti-bot; verificado en vivo
   (12 resultados 08:30–19:30 `:30` ✓). Del bundle oficial del sitio (`index-EQw1Zdrz.js`) se extrajo
   el **zoológico propio de 101 figuras** (00 Ballena + 0 Delfin + 01..99 Guacharito; labels SIN
   acentos como viajan en el bundle) — la informativa tenía razón (101). **MIGRADO**:
   `ElGuacharitoOficialScraper` + 101 opciones propias (patrón Loto Chaima). Premios OFICIALES del
   bundle: animalito regular **70×** (1→70, 10→700, 100→7.000) y figura especial **Guacharito (99) =
   150×** ("el número de la casa") → `config` premio 70 + comodines {guacharito-99: 150×}. Sin
   reglamento publicado (❌ no existe en el bundle).
4. **`guacharo-activo` (13)** — guacharoactivo.com.ve (SPA) → **API oficial lotterly**
   (`/v1/results/guacharo-activo/?exact_date=`), verificado en vivo (12 resultados 08:00–19:00 `:00`
   ✓). Del bundle oficial (`index-Dv-KFMIs.js`) se extrajo el **zoológico propio de 77 figuras**
   (00 Ballena + 0 Delfín + 01..75 Guacharo; labels CON acentos como viajan en el bundle) — la
   informativa tenía razón (77). **MIGRADO**: `GuacharoActivoOficialScraper` + 77 opciones propias.
   Premios OFICIALES del bundle: animalito regular **60×** (1→60, 10→600, 100→6.000) y **comodín
   Guácharo (75) = 120×** ("El Guácharo es el comodín especial... ¡duplicas tu premio!") → `config`
   premio 60 + comodines {guacharo-75: 120×}. Sin reglamento publicado (❌ no existe en el bundle).

### H. Evidencia del LOTE 3 — Cacería de reglamentos (WU f26, 2026-09-14)

Cacería del reglamento oficial (o afiche) de todos los juegos sin reglamento verificado.
Método por juego: (1) barrido del sitio oficial (menú/footer/rutas típicas/PDFs), (2) operador o
lotería reguladora, (3) búsqueda web acotada, (4) descarga del artefacto a `docs/reglamentos/`,
(5) extracción y aplicación (TDD), (6) documentación. Cortesía: ~1-2s entre peticiones,
User-Agent de navegador, robots.txt respetado (los sitios no lo prohíben para estas rutas).

1. **Familia Lotto Activo (1, 3, 4, 5, 6)** — lottoactivo.com. Las páginas `/informacion/<juego>/`
   rellenan el enlace "Reglamento" vía POST a `/core/process.php` (payload con `loteria` = slug),
   que devuelve `licencia` (nombre del PDF), `operadora` e IOBPAS oficiales: **Lotto Activo =
   Corporación BigLot 777 C.A / Lotería de Cojedes**; **Monje = Corporación BigLot 777 C.A /
   Lotería de Caracas**; **RD Internacional = Corporación BigLot 777 C.A / Lotería Internacional
   de Margarita**; **Rep. Dominicana = Corporación BigLot 777 C.A / notaría dominicana**;
   **Terminal Trío y Trío Activo = Corporación BigLot 777 C.A / Lotería de Oriente (Monagas)**.
   - `Lotto_Activo.pdf` (1 pág, **imagen no parseable**) → descargado
     `docs/reglamentos/reglamento-lotto-activo.pdf`.
   - `Lotto_Activo_2.pdf` (Monje) → **404**; probadas 6 variantes de nombre (Lotto_Activo2,
     LottoActivo_2, LottoActivo2, lotto_activo_2, Lotto_Activo_II, Lotto_Activo_2(Monje_Millonario))
     → todas 404. **Reglamento de Monje no publicado** (H14 sigue abierto para el premio del Patronus).
   - `Lotto_Activo_Rd_Ve.pdf` = `Lotto_Activo_RD.pdf` (md5 idéntico `2598d97e…`, 4 págs,
     **parseable**): es el reglamento de **"RULETA ROYAL"** (Empresa Apuestas Royal) — el sitio lo
     enlaza por error (mislink) para RD/Rep.Dom — pero confirma el **zoo 0–36 con 23 = Cebra** y el
     **pago de 30× por animal** (Art. NOVENA). Descargados:
     `docs/reglamentos/reglamento-lotto-activo-rd.pdf` y `reglamento-lotto-activo-rep-dom.pdf`.
   - `Terminal_Trio.pdf` = `Trio_Activo.pdf` (md5 `50148e8e…`, parseable, Lotería de Oriente):
     ya verificado en f22 (TRIPLE 600× / TERMINAL 60× / PUNTA 60×) → descargado como
     `docs/reglamentos/reglamento-terminal-activo.pdf`.
   - **Afiches oficiales** de `/descargas/` descargados (7 JPG): animalitos, triple+terminal v1/v2,
     "ganar siempre será divertido", pendón de resultados, ruleta animales y grupos, terminal.
2. **Triple Zulia (2)** — resultadostriplezulia.com (SPA). Del bundle JS oficial
   (`index-CMaMKI7c.js`) se extrajeron las rutas `/images/REGLAMENTO TRIPLE ZULIA NOV2025_.pdf`
   (reglamento, 17 págs, **parseable**) y `/licencia/RUNLOT_TP_ZULIA.pdf` (licencia RUNLOT 2026).
   **Premios Art. 19**: TRIPLE A/B/C **600×**, TERMINAL A/B/C **60×**, ZODIACO DEL ZULIA **6.000×**,
   TERMINAL ZODIACO **600×** (probabilidades 1:1000/1:100/1:12000/1:1200). Horarios Art. 10:
   12:45/16:45/19:05 (domingos solo 19:05) ✓ coinciden con la API. → **CORREGIDO** `premio_multiplo`
   30→**600** + modalidades {cola:60, zodiacal:6000, terminal_zodiacal:600} (patrón Táchira).
3. **Triple Caliente (8)** — triplecaliente.com (SPA). Del bundle (`index-CNUviVGR.js`):
   `/images/Reglamento TRIPLE CALIENTE.pdf` (17 págs, **parseable**, Lotería de Cojedes
   G-20008572-1) + `/licencia/RUNLOT_Triple_Calientes.pdf`. **Premios Art. 19**: TRIPLE A/B/C
   **600×**, TERMINAL A/B/C **60×**, SIGNO CALIENTE **6.000×**, TERMINAL SIGNO **600×**. Horarios
   Art. 10: **5 sorteos 11:10/13:10/15:10/17:10/19:10** (domingos solo 19:10) pero la **API opera 3
   (13:00/16:30/19:10** — timestamps, 234 respuestas) → desajuste **H18**, se prioriza la operación.
   → **CORREGIDO** `premio_multiplo` 30→**600** + modalidades {cola:60, zodiacal:6000,
   terminal_zodiacal:600}.
4. **Triple Zamorano (21)** — triplezamorano.com (SPA). Del bundle (`index-DaG7U4s6.js`):
   `/images/REGLAMENTO TP ZAMORANO NOV2025.pdf` (18 págs, **parseable**, Lotería del Zulia
   G-20007649-6) + `/licencia/RUNLOT_TP_ZAMORANO_28.11.2026.pdf`. **Premios Art. 19**: TRIPLE
   **600×**, COLA **60×**, UÑA **5×**, ASTRO **6.000×**, COLA+SIGNO **600×**, UÑA+SIGNO **60×**.
   Horarios Art. 10: 5 sorteos 10:00/12:00/14:00/16:00/19:00 **todos los días** (la API muestra
   domingos solo 19:00 → **H19**). → **CORREGIDO** `premio_multiplo` 30→**600** + modalidades
   {cola:60, uña:5, zodiacal:6000, cola_signo:600, uña_signo:60}. **H11 RESUELTO**: los valores
   informativos (600/60/6.000/600) eran correctos, ahora con fuente oficial.
5. **Triple Chance (10)** — tuchance.com.ve `/reglamentos/`: 2 PDFs oficiales, ambos **escaneados**
   (sin capa de texto): `REGLAMENTO-CHANCE-EN-LINEA-VIGENTE.pdf` (8 págs) y `Reglamento.pdf` 2024
   (19 págs) → descargados (`reglamento-triple-chance.pdf`, `reglamento-triple-chance-2024.pdf`).
   El cliente extraerá los textos (los premios ya vienen del afiche parseable, WU f24).
6. **La Granjita (14)** — lagranjita.com enlaza `https://cdns2.premierpluss.com/assets_webpages/
   granjita/reglamento_la_granjita.pdf` (17 págs, **escaneado**) → descargado
   `docs/reglamentos/reglamento-la-granjita.pdf`.
7. **La Ricachona (15)** — laricachona.com enlaza `https://laricachona.com/assets/files/
   reglamento_la_ricachona.pdf` (15 págs, **escaneado**) → descargado
   `docs/reglamentos/reglamento-la-ricachona.pdf`.
8. **Mega Animal 40 (17)** — encontrado el **sitio oficial megaanimal40.com** (con logos CONALOT,
   Big Data Tecnology y Lotería de Cojedes): "**12 sorteos diarios 09:00AM–08:00PM**", "**38
   figuras**" y texto oficial de premios: "La jugada tendrá un premio de **treinta (30) veces** lo
   apostado... si el resultado sale con la palabra [MEGA] tu premio se aumenta **(40) veces**". El
   **reglamento N° DIF-RGTO-033-00 (14-nov-2023) solo está referenciado** (RV y el propio sitio);
   no hay PDF accesible (el sitio de la Lotería de Cojedes no resuelve; CONALOT no publica
   reglamentos por juego). **El scraper SE MIGRÓ al sitio oficial en el WU f27** (ver sección I).
9. **El Arrejuntado (11)** — serviciosintegradostriple7.com es una landing SPA (Astro) y el backend
   `backend.serviciosintegradostriple7.com` es API-only: revisadas `/reglamento`, `/docs`,
   `/api/v1/reglamentos/`, `/openapi.json` → todo 404. **No publicado**.
10. **Loterly SPA (12, 13, 16, 18, 20)** — elguacharitomillonario.com, guacharoactivo.com.ve,
    lotochaima.com, selvaplus.com y triplefacil.com: los bundles oficiales NO contienen reglamento
    (solo footer de copyright); las rutas `/reglamento` devuelven el mismo index (SPA catch-all).
    Todos confirman "**Operado bajo licencia de la Lotería de Oriente**" (Selva Plus: logo ldo).
    → **No publicados**.

### I. Evidencia del WU f27 — Migración de Mega Animal 40 al sitio oficial + captura del comodín (2026-09-14)

1. **Endpoint oficial** verificado en vivo (14-sep-2026, sin auth ni anti-bot):
   `POST https://megaanimal40.com/core/process.php` con form-data `option=<token de resultados>`
   → `{"msg":"Datos recopilados","status":true,"datos":[{...,"resultados":[{"date_result","number_animal",
   "animalito","color","time_s","mega"}, ...]}]}`. `resultados[]` = sorteos del DÍA ACTUAL ordenados de
   más reciente a más antiguo; `time_s` en 12h; `number_animal` en 2 dígitos; `animalito` con acentos
   ("Águila"); **`mega`: "1" sin comodín / "2" SALIÓ EL COMODÍN MEGA** (JS oficial del sitio:
   `if (b.mega == "2") { ...muestra la palabra MEGA... }`).
2. **Limitación documentada y probada**: el endpoint IGNORA los parámetros de fecha (probados
   `fecha`/`date`/`dia` → siempre devuelve el día actual; prueba con `date=2026-09-10` → 8 sorteos del
   14-sep) y el sitio no expone histórico funcional (la página `/historial/` usa el mismo token).
   → `MegaAnimal40OficialScraper::execute` filtra por la fecha pedida; una fecha distinta produce `[]`.
   Los históricos previos en BD (del proveedor) quedan tal cual.
3. **Implementación (TDD estricto, RED→GREEN)**: `MegaAnimal40OficialScraper` (extiende BaseScraper,
   POST form-data con el token, parse de `datos[].resultados[]`, `numeros_ganadores =
   {"pais":"VE","numero":N,"nombre_animal":"Águila","comodin":(mega=="2")}`, hora `normalizeHora`,
   filtro por fecha, `findJuegoOrFail`, `saveResults` heredado; respuesta inválida/`status:false` →
   RuntimeException; sin datos → `[]`). Seeder `MegaAnimal40Seeder` → `updateOrCreate` con
   `scraper_url = https://megaanimal40.com/`, `scraper_class = MegaAnimal40OficialScraper` y
   `config = {premio_multiplo: 30, comodines: {mega: {nombre: MEGA, premio_multiplo: 40}}}` (premios
   oficiales de la web). Fixtures: `megaanimal40_oficial.json` (real de hoy) +
   `megaanimal40_comodin.json` (**SINTÉTICO** del campo documentado `mega:"2"`; el capturado real
   llegará con el primer comodín). `--filter=MegaAnimal40` → **31/31 (119 assertions)**.
4. **Contrato JSON enriquecido**: `JuegoCatalogoService` ahora exporta además `comodines` y
   `modalidades` desde `config` cuando existen (campos aditivos y opcionales; null si no) — aplica a
   mega (comodín MEGA), selva-plus (A/B), el-guacharito, guacharo-activo y a las `modalidades` de los
   triples/trío/terminal/cazaloton/fácil. `JuegosJsonTest` 3/3 (618 assertions) + `docs/juegos.json`
   REGENERADO y COMMITEADO (determinista: md5 `8879c514…` ×2).
5. **CARGA REAL (14-sep-2026)**: HOY 8 sorteos persistidos (09:00 Ratón #8 … 16:00 Mono #13; el
   comodín NO salió hoy — todos `comodin=false`); DEDUPE idempotente (2º rescrape: 8 filas, 0
   duplicados); AYER 2026-09-13 → 0 sorteos (endpoint solo sirve hoy, limitación). BD local total
   **281** resultados (273 previos + 8 nuevos netos; filas de mega: 25 = 17 del proveedor + 8 de hoy).

### E. Fuera del catálogo (candidatos — decisión del cliente)

La Ricachona animalitos · Granjita Plus · Terminal La Granjita · Zoológico Activo · Ruleta Activa ·
LottoMax · y el resto mapeado en `docs/plataformas-juegos.md`.
