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
| H11 | `triple-zamorano` | Informativa: 3 sorteos 12/16/19 y premios 600/60/6.000/600 (mismo template que Táchira). Oficial: **5 sorteos 10/12/14/16/19** | Horarios corregidos; premios marcados como informativos sin verificar |
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
| — | `lotto-activo` | Reglamento PDF es imagen no parseable; la modalidad Dupleta 1.000× no tiene respaldo | Pendiente de reglamento legible |

## 3. Gaps de nuestro sistema

| Ref | Área | Inconsistencia | Estado |
|---|---|---|---|
| **H13** | Motor de premios | `Animalitos::calcularPremio` no normaliza acentos: "Delfin" (feed) ≠ "Delfín" (opción) → **premio 0** en animales acentuados | **Anotado en `docs/motor-premios.md`** — pendiente del ciclo del motor (fix propuesto) |
| H1 | Motor/datos | Comodín MEGA 40× sin representación en los datos de la fuente | Sin soporte hasta fuente fiable (política: no simular) |
| H8b | Datos | Comodines de Selva (A 160× / B 200×) documentados; representación en `result` aún no observada | Parser defensivo implementado; liquidación → ciclo del motor |
| H14 | Datos | Monje: 7 figuras sin nombre oficial (37, 39, 57, 65, 67, 68, 75) + semántica de `special_result` sin documentar | **ZOO RESUELTO (WU f25)**: muestreo del histórico oficial de **75 días (2026-07-02..09-14, ~900 sorteos)** confirmó 37 Tortuga, 39 Lechuza, 57 Pato, 65 Araña, 67 Avestruz, 68 Jaguar y **75 Patronus** → **77 figuras completas, sin pendientes**. Queda abierto: **premio de El Patronus** (reglamento 404) y la **semántica de `special_result`** (NO es siempre 1: varía 1/0 ~9/12 por día, horas no fijas, solo en Monje — ver seguimiento-verificacion.md) |
| H3 | Motor | `premio_multiplo` estático vs reglas reales por modalidad | Ciclo del motor |
| H4 / H9 | Motor | Modalidades fuera del modelo: Dupleta 1.000×, Par Millonario 200.000×, El Patronus, Punta/Aproximación | Ciclo del motor |
| H10b | Motor | Terminales derivadas de Fácil (`n%100`) no modeladas | Ciclo del motor |

## 4. Fuentes (notas operativas)

| Tema | Detalle | Acción |
|---|---|---|
| `cazaloton` | **cazaloton.com NO publica resultados**: sus enlaces "Resultados" apuntan a loteriadehoy.com | **Se mantiene loteriadehoy como fuente** ✅ (WU f24); reglamento oficial verificado (38 figs, 30×, dupleta 800×, tripleta 200×) |
| `triple-chance` | URL oficial: `tuchance.com.ve` — "Chance en línea" expone resultados vía API scalalot; `/reglamentos` es PDF escaneado; afiche oficial parseable | ✅ **MIGRADO al API oficial** (WU f24): `TripleChanceOficialScraper` + premios del afiche (600×) en config. **Hallazgo nuevo**: la informativa declara 3 sorteos (1:00/4:30/8:00 PM) pero la operación real es de **11 (09:00–19:00)**; y declara "Triple A o B solo 150×" vs afiche oficial **100×** |
| `el-guacharito` | URL oficial: `elguacharitomillonario.com` → **API lotterly** (`el-guacharito-millonario`) | ✅ **MIGRADO** (WU f24): `ElGuacharitoOficialScraper` + **zoo propio de 101 figuras** (bundle oficial; la informativa tenía razón) + premios 70×/150× en config |
| `guacharo-activo` | URL oficial: `guacharoactivo.com.ve` → **API lotterly** (`guacharo-activo`) | ✅ **MIGRADO** (WU f24): `GuacharoActivoOficialScraper` + **zoo propio de 77 figuras** (bundle oficial; la informativa tenía razón) + premios 60×/120× en config |
