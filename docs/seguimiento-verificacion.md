# Seguimiento de verificación por juego

Estado de la verificación de cada juego contra su **fuente oficial**: horarios, opciones,
comodines, premiación y **reglamento oficial**. Complementa a:
- `docs/fuentes-oficiales.md` → URLs de cada fuente (registro).
- `docs/comparacion-juegos.md` → hallazgos H1–H11 (desajustes oficial vs informativa).

> Actualizado: 2026-09-12, al cierre de la integración de los 21 juegos.
> La **verificación integral** (próximo WU) irá actualizando esta matriz juego por juego.

## Leyenda

- ✅ **Verificado** contra fuente oficial (con evidencia).
- ⚠️ **Con salvedad** (verificado parcialmente, la fuente no publica el dato, o falta confirmar).
- ⏳ **Pendiente** (sin verificación oficial aún).
- ❌ **No existe / no disponible** (p. ej. reglamento no publicado).
- — No aplica (el juego no tiene ese aspecto).

## Matriz por juego

| # | Juego | Horarios | Opciones | Comodines | Premiación | Reglamento | Notas |
|---|-------|:---:|:---:|:---:|:---:|:---:|---|
| 1 | `lotto-activo` | ⏳ | ⏳ | — | ⏳ | ❌ | Juego original; falta verificación oficial integral (informativa menciona Dupleta 1000×) |
| 2 | `triple-zulia` | ⏳ | ⏳ | — | ⏳ | ❌ | Juego original; API oficial en uso, sin verificación documental |
| 3 | `terminal-activo` | ⏳ | ⏳ | — | ⏳ | ❌ | Juego original; fuente lottoactivo.com |
| 4 | `lotto-activo-rd` | ⏳ | ⏳ | — | ⏳ | ❌ | Juego original |
| 5 | `lotto-activo-rep-dom` | ⏳ | ⏳ | — | ⏳ | ❌ | Juego original |
| 6 | `monje-millonario` | ⏳ | ⚠️ | — | ⏳ | ❌ | H2: la informativa declara 77 figuras (+Patronus 75) vs nuestras 38 — **verificar contra fuente oficial antes de tocar** |
| 7 | `trio-activo` | ⏳ | ⚠️ | — | ⏳ | ❌ | H5: la informativa lo describe como terminales 3 cifras/3 sorteos vs nuestro modelado (tripletas/12 horarios) — contrastar con lottoactivo.com |
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
| 19 | `triple-tachira` | ✅ | ✅ | — | ✅ | ✅ | **Único con reglamento oficial verificado** (PDF G-20004065-3): 500×/50×/5.000×, 3 sorteos 13:15/16:45/22:10; solo pendiente confirmar domingos (H9) |
| 20 | `triple-facil` | ✅ | ✅ | — | ⚠️ | ❌ | API oficial: 12 sorteos, 100 opciones terminal; premiación INFORMATIVA (700×/60×/10×) sin reglamento publicado (H10) |
| 21 | `triple-zamorano` | ✅ | ✅ | — | ⚠️ | ❌ | API oficial: 5 sorteos (domingos solo 19:00); premiación informativa 600×/60×/6.000×/600× **sin verificar** (template sospechoso, H11) → 30× default |

## Resumen

- **Con reglamento oficial verificado: 1/21** (Triple Táchira).
- **Verificación funcional con fuente oficial (horarios/opciones): 10/21** — Táchira, Selva, Fácil, Zamorano, Granjita, Ricachona, Chaima, Caliente, Arrejuntado, Mega* (*Mega solo con el proveedor por excepción).
- **Premiación verificada con fuente oficial: 2/21** — Táchira (reglamento) y Selva (base 80× de la web oficial).
- **Pendientes mayores: los 7 juegos originales (1–7) + los 4 de fuente agregador (9, 10, 12, 13).**

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

- Verificar horarios, opciones y premiación contra sus fuentes oficiales
  (lottoactivo.com / resultadostriplezulia.com / serviciosintegradostriple7.com).
- Puntos H2/H5/H6 (zoológicos 77/101, trio-activo, cazaloton) se resuelven aquí.

### D. Puntos específicos abiertos

| Ref | Punto | Estado |
|---|---|---|
| H1 | Comodín MEGA 40× (mega-animal-40) | Sin representación en los datos de la fuente → sin soporte hasta fuente fiable (política: no simular datos) |
| H8 | Comodines Selva (A 160× / B 200×) | Reglas documentadas; representación en `result` aún no observada |
| H9 | Domingos de Táchira | Comportamiento no uniforme en la muestra — confirmar |
| H10 | Terminales derivadas de Fácil | Decisión de motor (derivar `n % 100` y modelar apuesta de terminal/aproximación) |
| H11 | Premios Zamorano | 600×/60×/6.000×/600× informativos sin verificar (template del agregador) |

### E. Fuera del catálogo (candidatos — decisión del cliente)

La Ricachona animalitos · Granjita Plus · Terminal La Granjita · Zoológico Activo · Ruleta Activa ·
LottoMax · y el resto mapeado en `docs/plataformas-juegos.md`.
