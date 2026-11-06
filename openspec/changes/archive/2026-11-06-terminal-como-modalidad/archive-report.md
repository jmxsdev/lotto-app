# Archive Report — terminal-como-modalidad

**Change**: `terminal-como-modalidad` (Terminal como modalidad; absorción de `terminal-activo` en Trío Activo)
**Archivado a**: `openspec/changes/archive/2026-11-06-terminal-como-modalidad/`
**Fecha de archivo**: 2026-11-06
**Rama de trabajo**: `feat/terminal-como-modalidad`
**HEAD al archivar**: `c958157` (verificación); implementación cerrada en `23da1d8`
**Base del change**: `main` fusionado en el tracker con `eff26f9`
**Estado final**: **PASS** — 5/5 requerimientos y 23/23 escenarios; sin hallazgos CRITICAL.
**Publicación**: release `2.0.0` **no publicada**; sin push a `origin` al momento del archivo.

## 1. Resumen del cambio

`terminal` deja de ser un tipo de juego y pasa a ser una **modalidad de `tripletas`**: la jugada se
liquida contra las 2 últimas cifras del triple vendido (`combinacion.triple` = `a`|`b`|`c`) a 60×.
El juego independiente `terminal-activo` (Terminal Trío) se retira del backend y del catálogo
(21→20 juegos), y el tipo `terminales` desaparece del contrato del catálogo y de la taquilla.
El tope de comisión de la jugada terminal queda en **25%** por venderse bajo un juego `tripletas`;
`ComisionService` no se modificó (D6).

Entrega en tres work units encadenados (feature-branch chain):

- **WU1 — fronts first (backend intacto)**: pestaña Terminales fuera de taquilla y panel; catálogo
  bundled sigue en 21 y el contrato TS sigue aceptando `terminales` durante la transición.
- **WU2 — retiro backend + catálogo**: migración de retiro, seeder/plugin/`PremiosOficiales`/
  `ESPEJO_EXTRA`/scraper/resolver limpiados, catálogo de 20 juegos byte-idéntico y logo eliminado.
- **WU3 — contrato taquilla + docs/specs**: `TipoJuego`/`TIPOS_VALIDOS`/`derivarFamilia` sin
  `terminales`, ramas legacy de ticket limpiadas, `2.0.0` + CHANGELOG y documentación de cliente/dev.

## 2. Commits y merges del ciclo

| Referencia | Descripción |
|---|---|
| `24b166b` | WU1 — oculta la sección Terminales en taquilla y panel (Fase 1) |
| `3a4da66` | WU2 — retira `terminal-activo` y deja el catálogo en 20 juegos |
| `23da1d8` | WU3 — retira el tipo `terminales` y limpia el contrato legacy |
| `eff26f9` | Merge de `main` en `feat/terminal-como-modalidad` |
| `d57c8f0` | Fix `LimitesScopedApiTest`: deriva el conteo de juegos activos tras el retiro |
| `c958157` | `docs(sdd)`: verificación de `terminal-como-modalidad` (#80) |

## 3. Evidencia de verificación

| Gate | Comando | Resultado | Procedencia |
|---|---|---|---|
| Suite backend completa | `cd backend && composer test:parallel` | **1487 tests, 8385 assertions, 2 skipped, 0 failures** | Ejecutada por el usuario, post-merge |
| e2e taquilla | `cd taquilla && pnpm e2e` | **9/9 passed** | Ejecutada por el usuario, post-merge |
| Contrato front | `cd taquilla && node scripts/check-pure.mjs` | **659 checks, 0 fallos** (WU3); WU1 663/0; WU2 658/0 | Ejecutada por el usuario |
| Build taquilla | `cd taquilla && npm run build` | exit 0 | Ejecutada por el usuario |
| Catálogo | inspección read-only + SHA-256 | 20 juegos, ids `1,2,4…21`, tipos `animalitos|tripletas`, sin `terminal-activo`; bundled == `docs/juegos.json` (`307d2577…`) | Verificador (read-only) |
| Migración de retiro | `2026_11_06_000001_retire_terminal_activo.php` | Aplicada en local (`Ran`); idempotente, guard de `apuestas`, borrados explícitos, `down()` no-op documentado | Usuario + inspección |
| `ComisionService` | `git diff 24b166b^..HEAD -- .../ComisionService.php` | Diff vacío — servicio sin cambios (D6) | Verificador (read-only) |

El archive **no re-ejecutó** tests, builds ni migraciones; la evidencia es la registrada en
`verify-report.md` y ratificada por el estado final entregado por el orquestador.

## 4. Veredicto de verificación

**PASS** (`c958157`): 5/5 requerimientos y 23/23 escenarios compliant; 0 CRITICAL, 0 blockers.
Las dos advertencias del verify eran de entrega/proceso y quedan resueltas o registradas así:

1. *Baseline de specs sin fusionar* → **resuelta en este archive** (ver §5).
2. *Release `2.0.0` no publicada* → **sigue pendiente**; no es un defecto de implementación (ver §8).

Sugerencias no bloqueantes del verify: assert automatizado de pestañas de resultados, fixture de
comisión específica de terminal, nota de resets de presupuesto `sdd-attempt` (registrada en §9) y
reinicio del scheduler post-deploy (registrado en §8).

## 5. Fusión de specs (source of truth)

Deltas fusionados mecánicamente en `openspec/specs/` conservando byte a byte todo requerimiento y
escenario no incluido en el delta.

### `motor-premios` — `openspec/specs/motor-premios/spec.md`

- **MODIFIED**: `Liquidación de terminales` → `Liquidación de la modalidad terminal (tripletas)`.
  La liquidación se ata al triple vendido (`combinacion.triple` → `triple_a|b|c`) con 60× o
  `premios.modalidades.terminal`; se elimina la comparación contra la clave `numero`. 4 escenarios
  (60× sobre el triple vendido, desajuste, otro slot, inexistencia de `terminal-activo`).
- **Nota no normativa aplicada**: se eliminó la fila `Terminal Trío | 60×` de la tabla
  «Valores oficiales (autoritativos)». Se conserva `Trío Activo | 600× | Terminal 60× · Punta 60×`.

### `comisiones` — `openspec/specs/comisiones/spec.md`

- **MODIFIED**: `Independencia entre niveles y tope acumulado`. Regla 4 explicita que la jugada de
  terminal se vende bajo un juego `tripletas` y topa a **25%** (no existe el tipo `terminales`);
  `topePorTipo` sigue `animalitos 16`, `tripletas 25`, `default 100`.
- Escenarios: se añade `Terminal bajo tripletas topa a 25%` y el escenario `otros tipos sin tope`
  pasa a ejemplificarse con un tipo desconocido real (`bingo`, default 100). 11 escenarios.

### `catalogo-juegos` — `openspec/specs/catalogo-juegos/spec.md`

- **MODIFIED**: `Lista maestra de juegos` (el escenario pasa de «7 juegos» a «Los 20 juegos
  documentados»; la lista sigue siendo la referencia de los seeders).
- **ADDED**: `Contrato de tipo de juego` (solo `animalitos|tripletas`, 20 juegos, sin
  `terminal-activo`, `trio-activo` conserva `premios.modalidades.terminal = 60`, tipo no soportado
  rechazado) con 4 escenarios.
- **ADDED**: `Resultados expuestos solo por familia` (taquilla y panel sin pestaña `terminales`) con
  1 escenario.

**Totales fusionados**: 5 requerimientos / 23 escenarios (1+1+3 requerimientos; 4+11+8 escenarios).
Sin requerimientos REMOVED fuera de los reemplazos MODIFIED; ningún requerimiento ajeno al delta fue
alterado.

## 6. Tasks

- `tasks.md` archivado con **21/21 tareas `[x]`** y 0 pendientes (WU1 4/4, WU2 11/11, WU3 6/6).
- No se requirió reconciliación de checkboxes: el artefacto persistido ya reflejaba el estado final.

## 7. Contenido del archivo

- `proposal.md` ✅
- `specs/{motor-premios,comisiones,catalogo-juegos}/spec.md` ✅
- `design.md` ✅
- `tasks.md` ✅
- `apply-progress.md` ✅ (snapshot intermedio; sus estados «pendiente» de WU3 son históricos)
- `verify-report.md` ✅
- `archive-report.md` ✅ (este documento)

Movimiento verificado con `cp -R` a snapshot temporal + `git mv` + `diff -r` **vacío** (sin
diferencias) entre el snapshot y el árbol archivado. `openspec/changes/terminal-como-modalidad/` ya
no existe.

## 8. Riesgos residuales

| Riesgo | Severidad | Estado / mitigación |
|---|---|---|
| Release `2.0.0` no publicada (`latest.yml` + instalador) | Media | Gate de entrega restante; `releases:publish` valida versión y sha512; hasta publicarla, la taquilla en producción puede seguir en `1.2.0` mientras el backend ya retiró el juego |
| Scheduler con agenda congelada | Baja | Reiniciar el scheduler tras el deploy del backend para que `sources()` reconstruya sin `terminal_activo` y confirmar que no queda fuente agendada |
| Cliente stale (`≤ 1.1.x`) vendiendo terminal contra el juego retirado | Media | La pestaña se eliminó en `1.2.0`; el riesgo residual solo existe si queda un cliente anterior. Sin telemetría de versiones; la adopción es confirmación operativa |
| Filas de `audit_events` apuntando al `entity_id` retirado | Baja | `audit_events` es append-only por diseño; los huérfanos son esperados y no se borran |
| Hueco de id 3 en el catálogo | Baja | Aceptado (D9); documentado en `SLUGS_POR_ID` y `docs/juegos.md`; no se renumera (el id es contrato del front y del ticket) |
| Pestañas de resultados verificadas solo de forma estática | Baja | Build + e2e verdes; se recomienda assert automatizado en `check-pure`/e2e |
| Fixture de comisión específica de terminal ausente | Baja | El mecanismo está cubierto por el test de tipo `tripletas`; se recomienda añadir el caso explícito |

## 9. Notas de proceso

- **Resets de presupuesto `sdd-attempt` (WU2 y WU3)**: aprobados por el maintainer porque el censo
  bruto de líneas se infló por el catálogo generado, fixtures eliminados y el merge de `main`
  (`changed_lines=2651 > 800` en WU2), no por desviación funcional. El riesgo autoral quedó dentro
  del split de work units previsto (D11: el catálogo generado no cuenta para el presupuesto de
  revisión).
- **Evidencia de WU1** (build/e2e/`check-pure`) es previa al merge; la evidencia final (suite completa
  1487 tests y e2e 9/9) es post-merge e incluye el fix `d57c8f0`.
- **Incidencias de entorno resueltas**: `backend/vendor` era un symlink al repo principal y Composer
  cargaba artefactos viejos (21 juegos); se reemplazó por un `composer install` real en el worktree.
- **Sin desviaciones funcionales** respecto de `design.md` y los deltas; D8 se cumple por
  compatibilidad (el plugin compartido `Tripletas` ya implementaba la modalidad; el retiro solo
  elimina el plugin `Terminales`).
- Este archive no modificó código de producción, no ejecutó tests/builds/migraciones y no hizo
  commit ni push; los documentos de archivo quedan para el commit del orquestador.

## 10. Cierre

- Ciclo SDD completo: propuesta → specs → diseño → tasks → apply → verify → **archive**.
- Pendiente fuera del ciclo: merge/push de la rama tracker y publicación de la release `2.0.0`.
