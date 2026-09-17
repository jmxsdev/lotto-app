# Propuesta: Motor de Premios (`motor-premios`)

> Fase: sdd-propose · Rama: `feat/motor-premios` · Base: `origin/main` (d250c20)
> Fuente de hallazgos: `openspec/changes/motor-premios/explore.md` (9 BUG / 10 GAP / 5 DATOS)
> Valores oficiales: `docs/multiplicadores-juegos.md` · Registro: `docs/inconsistencias.md` (H1–H24)

## Intent

Corregir el motor de premios para que **liquide exactamente lo que dicta el reglamento oficial de
cada juego**, no multiplicadores genéricos hardcodeados. Hoy los plugins pagan 30×/30×/20× fijos:
juegos con premio oficial de 500×–6.000× pagan de menos, los animales acentuados pagan 0 (H13/N10),
terminal-activo paga 0 siempre (N1), los comodines capturados no se liquidan (H1/H8b/N13) y las
modalidades con signo son inaccesibles (N2). Se sustituye el multiplicador hardcodeado por premios
**config-driven por juego** (base + modalidades + comodines) y se deshabilita todo juego sin fuente
oficial.

## Scope

### In Scope (backend)

**Fase 1 — Núcleo (pagos correctos, sin rediseño del modelo de apuesta):**
- **Bugs de pago críticos**: H13/N10 (normalización de acentos en las 3 familias), N1 (terminal-activo:
  alinear clave `terminal` vs `numero` + multiplicador 60×), N2 (signo: sigla vs label, desbloquear la
  modalidad zodiacal), N3 (Tripletas paga solo si el tipo apostado coincide), N11 (`PagoController`
  valida contra el motor corregido). Revisar N7 (filtro de hora en `ganadores`) y N10/N11.
- **Premios config-driven por juego**: `config.premios` (base + modalidades + comodines) reemplaza el
  `$multiplicador` hardcodeado de `Animalitos`/`Tripletas`/`Terminales`. `calcularPremio` lee los
  valores del juego.
- **Corrección de multiplicadores** con reglamentos cosechados: monje 30→**50×** (+Patronus 75→120×,
  palabra PATRONUS +20× = acumulado 70×), el-arrejuntado 30→**40×** base (+modalidades, ver abajo),
  loto-chaima 30→**40×** (+Tripleta 50×), triple-chance → **reglamento** (solo A/B 150×, C+Signo 6.000×),
  triple-facil → **reglamento** (700×/Terminal 60×/Aprox 10×), terminal-activo → **60× reglamento** (H12 resuelto).
- **Liquidación de comodines** (datos ya capturados): MEGA 40×, Selva A 160×/B 200×, Guacharito
  (99) 150×, Guácharo (75) 120×, Patronus (75) 120×, palabra PATRONUS +20×.
- **Deshabilitar `la-ricachona`** (`active=false`) — único juego sin multiplicador oficial localizado.
- **Tests de regresión por juego** (apuesta acentuada, terminal, signo, comodín, premio base).

**Fase 2 — Modalidades complejas (requieren ampliar el modelo de apuesta):**
- Dupleta (Lotto Activo 1.000×, Cazalotón 800×), Par Millonario 200.000×, Punta/Terminal/Aproximación
  por juego, Terminal+Zodiacal, Tripleta (Cazalotón 200×, Chaima 50×). Rediseño de `combinacion`
  (1 línea → multi-combinación/sorteos).
- Modalidades numéricas exactas del Arrejuntado: **El Pegadito** (5 cifras exactas, 60.000×) y
  **El Arrimao** (4 cifras exactas, 6.000×) — acertar el número exacto del sorteo (son modalidades,
  NO comodines).

**Fase 3 — Ciclo de vida y redondeo (EN ESTE CICLO, no opcional):**
- Estados de apuesta (`ganadora`/`perdida` por ausencia de resultado, N5), doble liquidación por filas
  pre-H22 (N6, dedupe), `premio_posible` real (N4), y política de redondeo: **máximo 2 decimales**
  (moneda Bs./USD según la tasa aplicada).

### Out of Scope

- **`taquilla/` y `panel/`**: sin cambios. El POS (front) crea tickets y recibe ganadores; no se toca
  `GAMES_CONFIG` hardcodeado (N8) ni la venta de los 15 juegos nuevos.
- Liquidación **retroactiva** de apuestas ya resueltas.
- Modalidades de La Ricachona (juego deshabilitado).
- Scrapers: no se añaden fuentes (los datos de comodines ya se capturan).

## Enfoque

Motor de reglas **config-driven** (leer premios desde `config.premios` del juego) en lugar de un
plugin por juego o una tabla de reglas. `calcularPremio` deja de hardcodear el multiplicador y
consulta el esquema `{base, modalidades:{...}, comodines:{...}}` del juego; añade normalización
(`Str::ascii` + `mb_strtolower`) en toda comparación de texto (animales/signos) y usa la clave real
de terminales. `PagoController` y `TicketController` dejan de recalcular contra el plugin roto
(N11/N7). La Fase 2 (Dupleta/Par/Cruzado) se diseña aparte al tocar el modelo de apuesta.

## Capabilities

### New Capabilities
- `motor-premios`: cálculo de premios config-driven (base + modalidades + comodines por juego),
  normalización de acentos, liquidación de terminales y comodines, deshabilitación de juegos sin
  fuente, y reglas de redondeo/estados de apuesta.

### Modified Capabilities
- `catalogo-juegos`: el contrato de premiación pasa de `premio_multiplo` único a `premios{base,
  modalidades, comodines}` — afecta el export `docs/juegos.json` (`JuegoCatalogoService`).
- `integracion-juego-incremental`: el seeder deja de registrar solo `premio_multiplo` y pasa a
  registrar el esquema de premios completo.

## Affected Areas

| Área | Impacto | Descripción |
|------|---------|-------------|
| `backend/app/Plugins/Juegos/{Animalitos,Tripletas,Terminales}.php` | Modified | `calcularPremio` config-driven + normalización de acentos + fix N1/N2/N3 |
| `backend/app/Services/JuegoPluginManager.php` | Modified | Pasar opciones reales del juego a `validarApuesta` (N12) |
| `backend/app/Services/ApuestaService.php` | Modified | `premio_posible` (N4), `verificarGanadores` (estados N5) |
| `backend/app/Http/Controllers/Api/{Pago,Ticket}Controller.php` | Modified | Validar pagos contra el motor corregido (N11), filtro de hora (N7) |
| `backend/app/Jobs/ScrapeResultsJob.php` | Modified | Dedupe de filas duplicadas (N6) |
| `backend/database/seeders/*Seeder.php` | Modified | `config.premios` completo por juego |
| `backend/tests/**` | New | Tests de regresión por juego |
| `docs/premiacion-juegos.md` | New | Documento didáctico de premiación (21 juegos) |

## Riesgos

| Riesgo | Prob. | Mitigación |
|--------|-------|------------|
| Pagar de más/menos por valores sin confirmar | Baja | H23/H12/Patronus/Fácil/Arrejuntado ya decididos con reglamento; solo aplicar valores con fuente oficial |
| Refactor sin regresión rompe juegos que hoy funcionan | Media | Tests por juego antes/después (Fase 1e) |
| Dupleta/Par requieren rediseño del modelo de apuesta | Media | Aislar en Fase 2, no tocar en Fase 1 |
| Doble liquidación por filas pre-H22 | Baja | Dedupe previo (N6) antes de tocar liquidación |
| Deshabilitar la-ricachona deja tickets huérfanos | Baja | `active=false` sin borrar; apuestas previas no se reliquidan |

## Rollback Plan

Los cambios de Fase 1 son revertibles con `git revert` por commit (sin migraciones destructivas:
`active=false` de la-ricachona es reversible a `true`; `config.premios` es JSON editable). Si un
juego rompe, se restaura su multiplicador anterior desde el commit base sin afectar al resto.

## Dependencies

- Reglamentos oficiales cosechados en `docs/reglamentos/` (sin acción pendiente del cliente).
- Decisiones del cliente ya registradas en "Decisiones del cliente (resueltas)" (H23, Trío 60×, Patronus, Fase 3, redondeo).

## Supuestos

1. La regla de soporte es: **sin fuente oficial con valores → `active=false`** (solo `la-ricachona`).
2. Los premios se configuran por juego en `config.premios` (no plugin por juego ni tabla de reglas).
3. El ciclo es **solo backend**; el front sigue con su catálogo actual.
4. Los comodines ya capturados (mega/selva/figuras) son liquidables sin tocar scrapers.
5. Los valores del reglamento oficial priman sobre la informativa y el afiche (H23 y H12 resueltos con reglamento).

## Decisiones del cliente (resueltas)

1. **Triple Chance** → **reglamento**: solo A/B **150×**, C+Signo **6.000×** (no el afiche). H23 resuelto.
2. **Terminal Trío** → **reglamento**: **60×**. H12 resuelto (se usa 60×, no el FAQ 70×).
3. **Monje "palabra PATRONUS"** → acumulado **70×** (figura 50× + palabra +20× cuando sale la palabra);
   Patronus figura 75 = **120×**.
4. **Triple Fácil** → **reglamento**: 700× / Terminal 60× / Aproximación 10×.
5. **El Arrejuntado** → **reglamento** (y lo que solo aparezca en su web oficial, como el Pegadito
   60.000×, se acepta). **El Pegadito**: cada sorteo canta un número de **5 cifras** ("pegadito",
   ej. `01963`) y uno de **4 cifras** ("arrimao", ej. `2091`) además del animalito/Triple A/B/Signo.
   Se apuesta al número exacto: Pegadito (5 cifras) **60.000×**, El Arrimao (4 cifras) **6.000×**.
   (Nota técnica: es una modalidad del sorteo, no un comodín.)
6. **Fase 3 ENTRA en este ciclo**: estados de apuesta, doble liquidación (filas pre-H22, N6),
   `premio_posible` (N4), redondeo — todo en alcance ("así se alargue").
7. **Redondeo/moneda**: premios con **máximo 2 decimales**; moneda Bs./USD según la tasa aplicada.

## Success Criteria

- [ ] `calcularPremio` liquida base + modalidad + comodín según `config.premios` del juego.
- [ ] Animal acentuado (Delfín/Águila/…) paga correctamente (H13/N10 cerrados).
- [ ] `terminal-activo` paga 60× (N1 cerrado).
- [ ] Comodines MEGA/Selva/Guacharito/Guácharo/Patronus liquidan su multiplicador.
- [ ] `la-ricachona` queda `active=false` y no es vendible.
- [ ] Estados de apuesta (ganadora/perdida), dedupe pre-H22 (N6) y `premio_posible` real (N4) implementados.
- [ ] Premios redondeados a máximo 2 decimales (moneda Bs./USD).
- [ ] Suite de regresión por juego en verde (tests nuevos + existentes).
