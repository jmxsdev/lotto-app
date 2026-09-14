# Seguimiento de verificación por juego

Estado de la verificación de cada juego contra su **fuente oficial**: horarios, opciones,
comodines, premiación y **reglamento oficial**. Complementa a:
- `docs/fuentes-oficiales.md` → URLs de cada fuente (registro).
- `docs/comparacion-juegos.md` → hallazgos H1–H11 (desajustes oficial vs informativa).

> Actualizado: 2026-09-14 (WU f22 — LOTE 1: los 7 juegos originales verificados contra fuente oficial).
> La **verificación integral** continúa: faltan los 4 juegos de fuente agregador (9, 10, 12, 13)
> y los pendientes de reglamento de los triples.
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
| 1 | `lotto-activo` | ✅ | ✅ | — | ✅ | ⚠️ | Feed oficial: 12 sorteos 08:00–19:00 (el texto de la web dice 11 desde 09:00 → nota). Zoo canónico 38; **corregido 23 Cobra→Cebra**. FAQ oficial: 30×. Reglamento PDF existe pero es imagen no parseable; Dupleta sin respaldo en reglamento |
| 2 | `triple-zulia` | ✅ | ✅ | — | ⏳ | ❌ | API oficial: SOLO 3 sorteos 12:45/16:45/19:05 y A/B/C+12 signos (234 registros). API no publica premios ni hay reglamento → premio 30 queda pendiente |
| 3 | `terminal-activo` | ✅ | ✅ | — | ⚠️ | ⚠️ | Feed: 12 sorteos 08:00–19:00; terminal = **2 últimos dígitos del Trío Activo** (verificado cruzando feeds). Reglamento oficial (Trio_Activo.pdf, mismo md5 que Terminal_Trio.pdf): TERMINAL **60×** → **corregido 20→60**. FAQ oficial dice 70×+5× aprox → discrepancia H12 |
| 4 | `lotto-activo-rd` | ✅ | ✅ | — | ✅ | ⚠️ | Feed + metadata + FAQ: 12 sorteos cada hora 08:30–19:30. Zoo canónico 38 (23=Cebra). FAQ 30×. Reglamento enlazado por error (es de "Ruleta Royal", confirma 30×) |
| 5 | `lotto-activo-rep-dom` | ✅ | ✅ | — | ✅ | ⚠️ | Feed: **14 sorteos 08:00–21:00** (el texto oficial dice 09:00–21:00 y "trece (14)" → nota). Zoo canónico 38. FAQ 30× |
| 6 | `monje-millonario` | ✅ | ⚠️ | — | ⏳ | ❌ | **H2 CONFIRMADO**: feed oficial muestra 0–74 con zoo propio (49=Pereza, 42=Tucán…). Creadas **70 figuras confirmadas** (patrón Loto Chaima); **7 números sin nombre oficial pendientes: 37, 39, 57, 65, 67, 68, 75** (el 75 sería El Patronus). PDF del reglamento 404; Patronus sin premio oficial |
| 7 | `trio-activo` | ✅ | ✅ | — | ✅ | ✅ | **H5 DESMENTIDO**: es un juego de **TRIPLE de 3 cifras** (000–999) con modalidades TRIPLE/TERMINAL/PUNTA, NO "terminales 3 cifras"; feed: 12 sorteos 08:00–19:00 (reglamento 2020 dice 3 → stale). Reglamento oficial: TRIPLE **600×** → **corregido 30→600** + modalidades terminal/punta 60×; opciones corregidas a terminal 00–99 (no hay zodiaco) |
| 8 | `triple-caliente` | ✅ | ✅ | — | ⏳ | ❌ | API oficial verificada con datos reales (12 signos); premiación pendiente de reglamento |
| 9 | `cazaloton` | ⏳ | ⏳ | — | ⏳ | ❌ | Fuente actual: agregador (loteriadehoy) — migrar a oficial; H6: zoo sin dato en informativa |
| 10 | `triple-chance` | ⏳ | ⏳ | — | ⏳ | ❌ | Fuente actual: agregador (loteriadehoy) — migrar a oficial |
| 11 | `el-arrejuntado` | ✅ | ✅ | — | ⏳ | ❌ | API oficial verificada con datos reales (6 modalidades); premiación pendiente |
| 12 | `el-guacharito` | ⏳ | ⚠️ | — | ⏳ | ❌ | H2: informativa declara 101 figuras vs nuestras 38 — verificar contra oficial; fuente actual agregador |
| 13 | `guacharo-activo` | ⏳ | ⚠️ | — | ⏳ | ❌ | H2: informativa declara 77 figuras vs nuestras 38 — verificar contra oficial; fuente actual agregador |
| 14 | `la-granjita` | ✅ | ✅ | — | ⏳ | ❌ | API oficial: 12 horarios, 38 opciones coinciden; premiación sin dato oficial |
| 15 | `la-ricachona` | ✅ | ✅ | — | ⏳ | ❌ | HTML oficial: 12 horarios `:05`, signos coinciden; premiación sin dato oficial |
| 16 | `loto-chaima` | ✅ | ✅ | — | ⏳ | ❌ | API oficial: 12 horarios, 57 figuras propias coinciden; premiación sin dato oficial |
| 17 | `mega-animal-40` | ⚠️ | ✅ | ⚠️ | ⚠️ | ❌ | Fuente: proveedor (excepción autorizada, sin página oficial). 38 canónico ✓; **comodín MEGA 40× sin representación en los datos** (H1) |
| 18 | `selva-plus` | ✅ | ✅ | ⚠️ | ✅ | ❌ | API oficial: 13 sorteos, 101 figuras + 2 comodines ✓; premiación base 80×/comodines 160×/200× documentada de fuente oficial; **representación de comodines en datos aún no observada** (H8) |
| 19 | `triple-tachira` | ✅ | ✅ | — | ✅ | ✅ | **Reglamento oficial verificado** (PDF G-20004065-3): 500×/50×/5.000×, 3 sorteos 13:15/16:45/22:10; solo pendiente confirmar domingos (H9) |
| 20 | `triple-facil` | ✅ | ✅ | — | ⚠️ | ❌ | API oficial: 12 sorteos, 100 opciones terminal; premiación INFORMATIVA (700×/60×/10×) sin reglamento publicado (H10) |
| 21 | `triple-zamorano` | ✅ | ✅ | — | ⚠️ | ❌ | API oficial: 5 sorteos (domingos solo 19:00); premiación informativa 600×/60×/6.000×/600× **sin verificar** (template sospechoso, H11) → 30× default |

## Resumen

- **Con reglamento oficial verificado: 2/21** (Triple Táchira y Trío Activo — ambos PDF parseables).
- **Verificación funcional con fuente oficial (horarios/opciones): 15/21** — los 7 originales (lote 1) + Táchira, Selva, Fácil, Zamorano, Granjita, Ricachona, Chaima, Caliente, Arrejuntado, Mega* (*Mega solo con el proveedor por excepción).
- **Premiación verificada con fuente oficial: 6/21** — Táchira (reglamento), Trío Activo (reglamento), Terminal Trío (reglamento), Lotto Activo/RD/Rep.Dom (FAQ oficial 30×), Selva (base 80× de la web oficial).
- **Pendientes mayores: los 4 juegos de fuente agregador (9, 10, 12, 13) + reglamentos de los triples (2, 8, 10).**

## Pendientes por tipo

### A. Reglamentos oficiales por obtener (pedir al cliente)

| Juego(s) | Qué falta | Impacto |
|---|---|---|
| `triple-facil`, `triple-zamorano` | Reglamento (no publicado en su web) | Premiación + modalidades reales |
| `triple-caliente`, `triple-zulia`, `triple-chance`, `el-arrejuntado` | Reglamentos | Premiación real (hoy 30× genérico) |
| `lotto-activo` y familia | Reglamentos + verificación integral | Premios/modaliades (Dupleta 1000×, etc.) |

### B. Fuentes no oficiales a migrar (esperando URL oficial del cliente)

`cazaloton`, `triple-chance`, `el-guacharito`, `guacharo-activo` → hoy desde
loteriadehoy.com (agregador). Migrar a página oficial cuando el cliente la proporcione.

### C. Verificación oficial pendiente de los juegos originales (1–7)

✅ **LOTE 1 COMPLETADO (2026-09-14, WU f22)**. Ver evidencia detallada abajo.
Pendientes que quedaron abiertos del lote:
- **Monje Millonario (6)**: 7 números del zoológico sin nombre oficial (37, 39, 57, 65, 67, 68, 75)
  y el premio de "El Patronus" (el PDF del reglamento da 404 en el sitio oficial).
- **Terminal Trío (3)**: discrepancia de premio reglamento 60× vs FAQ oficial 70×+5× aprox (H12).
- **Lotto Activo (1)**: reglamento PDF es imagen no parseable; la modalidad Dupleta 1.000× de la
  informativa no tiene respaldo en el reglamento disponible.
- **Triple Zulia (2)**: sin reglamento oficial (premio 30 genérico pendiente).

### D. Puntos específicos abiertos

| Ref | Punto | Estado |
|---|---|---|
| H1 | Comodín MEGA 40× (mega-animal-40) | Sin representación en los datos de la fuente → sin soporte hasta fuente fiable (política: no simular datos) |
| H8 | Comodines Selva (A 160× / B 200×) | Reglas documentadas; representación en `result` aún no observada |
| H9 | Domingos de Táchira | Comportamiento no uniforme en la muestra — confirmar |
| H10 | Terminales derivadas de Fácil | Decisión de motor (derivar `n % 100` y modelar apuesta de terminal/aproximación) |
| H11 | Premios Zamorano | 600×/60×/6.000×/600× informativos sin verificar (template del agregador) |
| H12 | **Premio Terminal Trío: reglamento 60× vs FAQ oficial 70× (+5× aproximación)** | **Pendiente de decisión del cliente** — se usó el valor del reglamento (60×, patrón Táchira); falta confirmar cuál paga la operación |
| H13 | **Normalización de acentos en `Animalitos::calcularPremio`** | El feed oficial entrega nombres sin acentos ("Delfin", "Caiman") y las opciones tienen acentos ("Delfín", "Caimán") → `strtolower` no iguala y el premio sale 0. **Afecta a toda la familia Lotto Activo + Monje**; es del MOTOR (ciclo futuro), NO se toca en este WU |
| H14 | **Monje Millonario: 7 números sin nombre oficial + El Patronus** | Zoo propio creado con 70 figuras confirmadas; 37/39/57/65/67/68/75 pendientes. El `special_result=1` aparece en TODOS los resultados del feed de Monje (semántica sin documentar) |
| H15 | **Trio Activo: reglamento (3 sorteos, 2020) vs operación real (12 sorteos)** | El feed oficial opera 12 sorteos/día 08:00–19:00; el reglamento PDF declara 3. Se priorizó la operación real (lo que consume el scraper). Confirmar con el operador |
| H16 | **Textos oficiales de horarios desactualizados** | Las páginas `/informacion/` y el FAQ declaran "once (11) sorteos 09:00–19:00" para Lotto Activo/Trío/Terminal y "trece (14)" para Rep. Dominicana, pero el feed opera 12 (08:00–19:00) y 14 (08:00–21:00) respectivamente. El feed (dato operativo) manda |

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
6. **`monje-millonario` (6)** — **H2 CONFIRMADO**. Feed oficial ("Lotto Activo 2 (Monje
   Millonario)"): 12 sorteos 08:05–19:05 ✓ y **números 0–74** con zoo propio (13 días, 66
   resultados, 65 números observados). Zoológico **propio de 70 figuras confirmadas** creado
   (patrón Loto Chaima): canónico 0–36 (23=Cebra) + 32 figuras nuevas (38 Búfalo, 40 Avispa,
   42 Tucán, 49 Pereza, 74 Turpial…). **Pendientes sin nombre oficial: 37, 39, 57, 65, 67, 68,
   75** (el 75 sería "El Patronus" según la informativa, sin confirmar). `special_result=1` en
   todos los resultados del feed (semántica sin documentar). El PDF del reglamento
   (`Lotto_Activo_2.pdf`) da **404** → Patronus sin premio oficial (H14).
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

### E. Fuera del catálogo (candidatos — decisión del cliente)

La Ricachona animalitos · Granjita Plus · Terminal La Granjita · Zoológico Activo · Ruleta Activa ·
LottoMax · y el resto mapeado en `docs/plataformas-juegos.md`.
