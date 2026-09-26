# Multiplicadores oficiales por juego (21 juegos)

Lista consolidada de premios **oficiales** por juego, la base para el ciclo del motor de premios.
Fuentes: reglamentos oficiales (PDF texto / escaneado), webs y bundles oficiales, y verificación
en vivo documentada en `docs/seguimiento-verificacion.md` / `docs/comparacion-juegos.md`.

> Actualizado: 2026-09-17. Reglamentos nuevos cosechados en `docs/reglamentos/` (ver notas al pie).

## Tabla de multiplicadores

| # | Juego | Tipo | Premio base | Modalidades / Comodines | Fuente | Estado vs nuestro sistema |
|---|-------|------|-------------|--------------------------|--------|---------------------------|
| 1 | `lotto-activo` | animalitos | **30×** | Dupleta 1.000× (2 animalitos, 2 sorteos) | FAQ oficial + reglamento familia | ✅ 30 base ok; Dupleta sin soporte |
| 2 | `triple-zulia` | tripletas | **600×** | Cola 60× · Zodiacal 6.000× · Terminal+Zodiacal 600× | Reglamento oficial (Lotería del Zulia) | ✅ aplicado |
| 3 | `terminal-activo` (Terminal Trío) | terminales | **60×** | — (FAQ oficial dice 70× + 5× aprox → H12) | Reglamento oficial | ✅ aplicado (60×) |
| 4 | `lotto-activo-rd` | animalitos | **30×** | — | FAQ oficial | ✅ aplicado |
| 5 | `lotto-activo-rep-dom` | animalitos | **30×** | — | FAQ oficial | ✅ aplicado |
| 6 | `monje-millonario` | animalitos | **50×** | **Patronus (figura 75) 120×** · palabra PATRONUS +20× (acumula 70× con figura) | **Reglamento oficial "Lotto Activo 2" Art. 22** | 🔄 **por aplicar** (hoy 30×) |
| 7 | `trio-activo` | tripletas | **600×** | Terminal 60× · Punta 60× | Reglamento oficial | ✅ aplicado |
| 8 | `triple-caliente` | tripletas | **600×** | Modalidades del reglamento (Lotería de Cojedes) | Reglamento oficial | ✅ aplicado |
| 9 | `cazaloton` | animalitos | **30×** | Dupleta 800× · Tripleta 200× | Reglamento oficial (Lotería del Mar) | ✅ aplicado |
| 10 | `triple-chance` | tripletas | Triple Fijo **600×** | A+B **200.000×** · solo A/B **150×** · Punta 60× · Terminal 60× · Cruzado 3.000×/10× · C+Signo **6.000×** · Terminal+Signo 600× | **Reglamento oficial** (el afiche decía 100× y 5.000× → H23) | 🔄 revisar (afiche vs reglamento) |
| 11 | `el-arrejuntado` | tripletas | Animalito **40×** | Triple A 600× · Triple B 600× · Triple+Signo 6.000× · El Arrimao 6.000× · El Pegadito **60.000×** | **Sitio oficial (#premios)** | 🔄 **por aplicar** (hoy 30× + modalidades) |
| 12 | `el-guacharito` | animalitos | **70×** | Guacharito (figura 99) **150×** | Bundle oficial (SPA) | ✅ aplicado |
| 13 | `guacharo-activo` | animalitos | **60×** | Guácharo (figura 75) **120×** | **Reglamento oficial (texto)** | ✅ aplicado |
| 14 | `la-granjita` | animalitos | **30×** | — | **Reglamento oficial (texto)** | ✅ aplicado |
| 15 | `la-ricachona` | tripletas | **?** | Terminal · Aproximación (valores ?) | ⚠️ **sin fuente con valores** (reglamento 2022 no los trae; copia escaneada) | ⏳ **PENDIENTE** |
| 16 | `loto-chaima` | animalitos | **40×** | Tripleta Loto Chaima **50×** | **Reglamento oficial (texto)** | 🔄 **por aplicar** (hoy 30×) |
| 17 | `mega-animal-40` | animalitos | **30×** | **Comodín MEGA 40×** | Web oficial | ✅ aplicado (comodín capturado) |
| 18 | `selva-plus` | animalitos | **80×** | Comodín A "Leoncito" **160×** · Comodín B "Selva Plus" **200×** | Web oficial (operación) — el reglamento dice 30× → H24 | ✅ aplicado (comodines capturados) |
| 19 | `triple-tachira` | tripletas | **500×** | Terminal 50× · Zodiacal 5.000× | **Reglamento oficial 2026 (G-20004065-3)** (mirror 2018 dice 600× → doc obsoleto) | ✅ aplicado |
| 20 | `triple-facil` | tripletas | **700×** | Terminal 60× · Aproximación 10× | **Reglamento oficial (texto)** | ✅ aplicado |
| 21 | `triple-zamorano` | tripletas | **600×** | Cola 60× · Uña 5× · Zodiacal 6.000× · Cola+Signo 600× · Uña+Signo 60× | Reglamento oficial (Lotería del Zulia) | ✅ aplicado |

## Cambios a aplicar en el ciclo del motor

1. **Monje**: 30× → **50×** + Patronus 120× + palabra +20× (reglamento oficial nuevo).
2. **El Arrejuntado**: 30× → **40× base** + modalidades (Triple 600×, Triple+Signo 6.000×, Arrimao 6.000×, Pegadito 60.000×) — hoy solo paga 30× genérico.
3. **Loto Chaima**: 30× → **40×** + Tripleta 50×.
4. **Chance**: revisar 150× (reglamento) vs 100× (afiche) y C+Signo 6.000× vs 5.000×.
5. **Comodines a liquidar**: MEGA 40× (mega), Selva A/B 160×/200×, Guacharito 99→150×, Guácharo 75→120×, Patronus 75→120×, palabra PATRONUS +20×.
6. **H13**: normalización de acentos (bug crítico transversal).
7. **`premio_multiplo` config-driven** + modalidades por juego (motor).

## Discrepancias nuevas (registradas)

- **H23**: `triple-chance` — reglamento oficial (150× solo A/B; C+Signo 6.000×) vs afiche oficial (100×; 5.000×).
- **H24**: `selva-plus` — reglamento (30×) vs operación/web oficial (80× + comodines). Se prioriza la operación.
- **Mirror Táchira 2018** (600×) — documento obsoleto; el reglamento vigente 2026 dice 500×.
- **`la-ricachona`**: pendiente de fuente con valores (única brecha de la lista).

## Reglamentos nuevos en `docs/reglamentos/` (cosecha 17-sep)

`reglamento-triple-facil-oficial.pdf` · `reglamento-lotto-activo-2-monje-oficial.pdf` ·
`reglamento-triple-chance-oficial.pdf` · `reglamento-la-ricachona-texto-2022.pdf` ·
`reglamento-la-granjita-texto.pdf` · `reglamento-loto-chaima-texto.pdf` ·
`reglamento-selva-plus-texto.pdf` · `reglamento-guacharo-activo-oficial.pdf`.
