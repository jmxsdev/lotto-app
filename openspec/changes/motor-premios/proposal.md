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
- **Corrección de multiplicadores** con reglamentos cosechados: monje 30→**50×** (+Patronus 120×,
  palabra +20×), el-arrejuntado 30→**40×** base (+modalidades), loto-chaima 30→**40×** (+Tripleta 50×),
  revisión de `triple-chance` (H23: 150× vs 100× y 6.000× vs 5.000×).
- **Liquidación de comodines** (datos ya capturados): MEGA 40×, Selva A 160×/B 200×, Guacharito
  (99) 150×, Guácharo (75) 120×, Patronus (75) 120×, palabra PATRONUS +20×.
- **Deshabilitar `la-ricachona`** (`active=false`) — único juego sin multiplicador oficial localizado.
- **Tests de regresión por juego** (apuesta acentuada, terminal, signo, comodín, premio base).

**Fase 2 — Modalidades complejas (requieren ampliar el modelo de apuesta):**
- Dupleta (Lotto Activo 1.000×, Cazalotón 800×), Cruzado/Pegadito 60.000×/Arrimao 6.000×
  (Arrejuntado), Par Millonario 200.000×, Punta/Terminal/Aproximación por juego, Terminal+Zodiacal,
  Tripleta (Cazalotón 200×, Chaima 50×). Rediseño de `combinacion` (1 línea → multi-combinación/sorteos).

**Fase 3 — Ciclo de vida y redondeo (lo que quede):**
- Estados de apuesta (`ganadora`/`perdida` por ausencia de resultado, N5), dedupe de filas pre-H22
  (N6), `premio_posible` real (N4), política de redondeo (2 decimales, half-up) y moneda del premio.

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
| Pagar de más/menos por valores sin confirmar | Alta | Solo aplicar valores con fuente oficial; H23/FAQ Trío 60× vs 70× quedan como pregunta abierta |
| Refactor sin regresión rompe juegos que hoy funcionan | Media | Tests por juego antes/después (Fase 1e) |
| Dupleta/Par requieren rediseño del modelo de apuesta | Media | Aislar en Fase 2, no tocar en Fase 1 |
| Doble liquidación por filas pre-H22 | Baja | Dedupe previo (N6) antes de tocar liquidación |
| Deshabilitar la-ricachona deja tickets huérfanos | Baja | `active=false` sin borrar; apuestas previas no se reliquidan |

## Rollback Plan

Los cambios de Fase 1 son revertibles con `git revert` por commit (sin migraciones destructivas:
`active=false` de la-ricachona es reversible a `true`; `config.premios` es JSON editable). Si un
juego rompe, se restaura su multiplicador anterior desde el commit base sin afectar al resto.

## Dependencies

- Reglamentos oficiales cosechados en `docs/reglamentos/` (sin acción del cliente salvo H23/FAQ).
- Confirmación del cliente en las preguntas abiertas (H23, Trío 60× vs 70×, Patronus).

## Supuestos

1. La regla de soporte es: **sin fuente oficial con valores → `active=false`** (solo `la-ricachona`).
2. Los premios se configuran por juego en `config.premios` (no plugin por juego ni tabla de reglas).
3. El ciclo es **solo backend**; el front sigue con su catálogo actual.
4. Los comodines ya capturados (mega/selva/figuras) son liquidables sin tocar scrapers.
5. Los valores del reglamento priman sobre la informativa y el afiche (H23 se confirma).

## Preguntas abiertas al cliente

1. `triple-chance` (H23): ¿se usa el reglamento (150× / C+Signo 6.000×) o el afiche (100× / 5.000×)?
2. Terminal Trío: ¿60× (reglamento) o 70× + 5× aprox (FAQ, H12)? ¿Se soporta la aproximación?
3. Monje "palabra PATRONUS +20×": ¿la apuesta es a la figura 75 o a una opción "PATRONUS" textual?
   Confirmar mecánica exacta (acumulación 50×+20×=70×).
4. `triple-facil`: ¿confirmar 700×/60×/10× (informativos, sin reglamento)? ¿Derivar el terminal
   `n%100` del triple en el motor?
5. `el-arrejuntado`: los valores vienen del sitio oficial (#premios) — ¿hay reglamento PDF para
   respaldar base 40×/Pegadito 60.000×?
6. ¿Los estados de apuesta (`ganadora`/`perdida`/`vencido`) y el `premio_posible` entran en este
   ciclo (Fase 3) o se difieren?
7. ¿Política de redondeo (half-up a 2 decimales) y moneda del premio (la apostada)?

## Success Criteria

- [ ] `calcularPremio` liquida base + modalidad + comodín según `config.premios` del juego.
- [ ] Animal acentuado (Delfín/Águila/…) paga correctamente (H13/N10 cerrados).
- [ ] `terminal-activo` paga 60× (N1 cerrado).
- [ ] Comodines MEGA/Selva/Guacharito/Guácharo/Patronus liquidan su multiplicador.
- [ ] `la-ricachona` queda `active=false` y no es vendible.
- [ ] Suite de regresión por juego en verde (tests nuevos + existentes).
