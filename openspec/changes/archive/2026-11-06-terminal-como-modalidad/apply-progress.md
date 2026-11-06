# Apply Progress: terminal-como-modalidad

Rama: `feat/terminal-como-modalidad` (feature-branch chain; PR 1 base = tracker).
Modo de implementación: Standard (no hay runner de tests de front; `apply.tdd: true`
apunta a `composer test`, que es backend y lo ejecuta el usuario).

## Estado por work unit

| WU | Alcance | Estado |
|----|---------|--------|
| WU1 | Fase 1 fronts + release (backend intacto) | **Implementado y verificado** (check-pure 663/0; e2e 9/9) |
| WU2 | Fase 2 backend, migración, catálogo 20, logo | **Implementado y verificado** — migración aplicada; catálogo 20 byte-idéntico; tests backend 220/220 (2593 assertions); check-pure 658/0; reset de budget aprobado por maintainer |
| WU3 | Fase 2 limpieza de contrato TS + specs/docs | **Implementado** — tests pendientes (los ejecuta el usuario) |

## Commit

- Mensaje: `feat(fronts): oculta la sección Terminales en taquilla y panel (WU1)`
- Hash: `24b166b`.

## Archivos modificados (WU1)

| Archivo | Acción | Cambio |
|---------|--------|--------|
| `taquilla/src/pages/dashboard.astro` | Modificado | `ordenTabs()` sin `terminales`; `labels` sin `terminales`; icono de tab `activeTab === 'animalitos' ? icono('dices') : icono('target')` |
| `taquilla/src/pages/resultados.astro` | Modificado | Eliminado el botón `data-tab="terminales"` |
| `panel/src/pages/resultados-loterias.astro` | Modificado | Eliminado el botón `data-tab="terminales"` |
| `taquilla/package.json` | Modificado | `version` 1.1.0 → 1.2.0 |
| `CHANGELOG.md` | Modificado | Nueva sección `[1.2.0] — 2026-11-05` (Keep a Changelog, español) antes de `[1.1.0]`; `[Unreleased]` y `[1.1.0]` intactos |
| `openspec/changes/terminal-como-modalidad/tasks.md` | Modificado | Tareas 1.1–1.4 marcadas `[x]` |

## Invariantes preservados (Fase 1)

- Catálogo sin cambios: `taquilla/src/data/juegos.json` sigue en 21 juegos y
  byte-idéntico a `docs/juegos.json`.
- `taquilla/src/utils/catalogo.ts` intacto: `TipoJuego`/`TIPOS_VALIDOS` siguen
  aceptando `terminales` y `derivarFamilia` conserva la rama.
- Ramas legacy de ticket en `dashboard.astro` intactas: `familia === 'terminal'`
  (2348) y `juegoType === 'terminales'` (2514), alcanzables por tickets viejos.
- Backend sin cambios (seeder, plugin, premios, scraper, migraciones).
- `ticket.ts`, `recibos.ts`, `historial.astro`, `check-pure.mjs`, logos/manifest
  sin tocar.

## Work Unit Evidence (WU1)

| Evidence | Value |
|---|---|
| Focused test command and exact result | `cd taquilla && node scripts/check-pure.mjs` → **663 checks, 0 fallos** (usuario, 2026-11-05). |
| Runtime harness command/scenario and exact result | `cd taquilla && pnpm e2e` → **9/9 passed (54.7s)** tras migrar `lotto_e2e` (`2026_11_03_000001_drop_role_from_users_table` + `2026_11_04_000001_add_created_by_to_users_table`). Escenario: login, venta single/multi, premio, anulación, grilla, cierre, tipo de pago y smoke. |
| Build | `npm run build` ejecutado por el usuario antes de check-pure (prompt 7s); `dist` presente en el e2e. |
| Rollback boundary | `git revert` del commit WU1: restaura las 3 pestañas y la versión 1.1.0; no toca backend, catálogo ni contrato TS. Rollout de rollback: publicar `1.2.1` (electron-updater no baja versiones). |

## Verificación WU1 (completada)

```bash
cd taquilla && npm run build          # OK
cd taquilla && node scripts/check-pure.mjs   # 663 checks, 0 fallos
cd taquilla && pnpm e2e               # 9/9 passed
```

Attempt ledger: `settle` WU1 → `passed`; token `sha256:1a708d…`; commit `24b166b`.

## Desviaciones del diseño

Ninguna. Los cambios coinciden con `design.md` § «Fase 1 — fronts + release» y con
las tareas 1.1–1.4.

## WU2 — Fase 2 backend, migración y catálogo 20

Estado: **implementado y commiteado** en local (sin push). Migración aplicada en la BD local por
el usuario (`Ran`, fila `terminal-activo` = 0, sin apuestas ni resultados de terminal);
`juegos:export` + `cp` produjeron 20 juegos byte-idénticos. **Tests backend pendientes** (los
ejecuta el usuario). WU2a autoró el código; WU2b cerró catálogo, tareas y commit.

Commit: `feat(backend): retira terminal-activo y deja el catálogo en 20 juegos (WU2)`
(hash en el envelope de WU2b).

### Catálogo generado (no editado a mano)

- `docs/juegos.json` y `taquilla/src/data/juegos.json`: **20 juegos**, sin `terminal-activo`,
  sin `type = terminales`, **byte-idénticos**. Ids `1,2,4…21` (hueco en 3, D9).
- `taquilla/src/data/logos.json`, `taquilla/public/images/juegos/manifest.json` y el asset
  `terminal-activo.webp` quedaron sin la entrada/huérfano.
- `taquilla/scripts/check-pure.mjs`: conteos en 20 (`porId`/`porSlug`), `conBase` 19, búsqueda
  `"05"` y buffer sobre `trio-activo`; assert byte-idéntico y assert legacy de
  `formatearJugada({tipo:'terminales'})` intactos (este último es WU3).

### Archivos

| Archivo | Acción | Cambio |
|---------|--------|--------|
| `backend/database/migrations/2026_11_06_000001_retire_terminal_activo.php` | Creado | Borrado idempotente de `terminal-activo` con guard de `apuestas`; deletes explícitos en orden `juego_limites, juego_horarios, juego_opciones, plugin_juegos, resultados, juego_auditoria, juegos`; `down()` no-op documentado (D4). **Aplicada en local** (`Ran`) |
| `backend/database/seeders/DatabaseSeeder.php` | Modificado | Fuera `TerminalesSeeder::class` |
| `backend/database/seeders/TerminalesSeeder.php` | Eliminado | — |
| `backend/app/Plugins/Juegos/Terminales.php` | Eliminado | — |
| `backend/app/Support/PremiosOficiales.php` | Modificado | Fuera `terminal-activo`, `ESPEJO_EXTRA` y su bucle; docblocks 21→20 |
| `backend/app/Plugins/Scrapers/AnimalitosScraper.php` | Modificado | `['trio_activo']`; fuera `terminal-trio` del match; fallback `$gameName` = «Trío Activo» |
| `backend/app/Services/ScraperSourceResolver.php` | Modificado | Fuera `KEY_TERMINAL_ACTIVO`, rama `terminal_activo` y mención en docblock |
| `backend/app/Http/Controllers/Api/ResultadoController.php` | Modificado | Comentario `animalitos/terminales` → `animalitos` |
| `backend/app/Plugins/Contracts/JuegoMultiSorteoInterface.php` | Modificado | Comentario sin `Terminales` |
| `backend/database/seeders/TripleFacilSeeder.php` | Modificado | Comentario «plugin Terminales» → «opciones numéricas 00-99» |
| `backend/tests/Unit/TerminalesPluginTest.php` | Eliminado | — |
| `backend/tests/Fixtures/lottoactivo_terminal_activo_{page.html,response.json}` | Eliminados | — |
| `backend/tests/Unit/JuegoPluginManagerTest.php` | Modificado | `Terminales::class` → `Tripletas::class` (la clase ya no existe) |
| `backend/tests/Unit/AnimalitosScraperTest.php` | Modificado | Fuera `Juego::create` de terminal, 2 tests y fixtures |
| `backend/tests/Unit/ScraperSourceResolverTest.php` | Modificado | «trio-activo es fuente propia distinta de animalitos»; tipos desconocidos `bingo` |
| `backend/tests/Unit/ScraperResolverTest.php` | Modificado | `terminales` → `bingo` |
| `backend/tests/Unit/PremiosOficialesTest.php` | Modificado | 20 juegos; fuera tests de terminal-activo y espejo extra |
| `backend/tests/Unit/PremiosEngineTest.php` | Modificado | Fixture de redondeo → `trio-activo` |
| `backend/tests/Unit/ComisionServiceTest.php` | Modificado | `crearCadena` default `bingo`; `topePorTipo('bingo') = 100`; test «otros tipos sin tope» |
| `backend/tests/Feature/JuegosJsonTest.php` | Modificado | `SLUGS_POR_ID` con hueco en 3; 20 juegos; fuera asserts de terminal-activo |
| `backend/tests/Feature/MotorPremiosRegresionTest.php` | Modificado | Fuera 2 tests de terminal-activo; docblock 20 |
| `backend/tests/Feature/ResultadoAparicionesTest.php` | Modificado | Fuera test de identidad terminales; historial vacío usa `lotto-activo` |
| `backend/tests/Feature/VerificacionOriginalesTest.php` | Modificado | Fuera `test_terminal_activo_premio_60_del_reglamento` |
| `backend/tests/Feature/{ComisionesApiTest,ComisionCierreTest,ComisionReporteTest,DivergenciaRolTest}.php` | Modificados | `type: 'terminales'` → `'bingo'`; `test_put_limite_otros_tipos_sin_tope_hasta_100_201` |
| `backend/tests/Feature/TripleFacilResultsTest.php` | Modificado | Comentario sin «plugin Terminales» |
| `taquilla/src/data/logos.json` | Modificado | Fuera `terminal-activo` |
| `taquilla/public/images/juegos/manifest.json` | Modificado | Fuera objeto `terminal-activo`; nota de `trio-activo` sin la referencia |
| `taquilla/public/images/juegos/terminal-activo.webp` | Eliminado | — |
| `taquilla/scripts/check-pure.mjs` | Modificado | 20 juegos; sin `porTipo.terminales`/`porFamilia.terminal`; `porId`/`porSlug` 20; búsqueda `"05"` y buffer movidos a `trio-activo`; `conBase` 19; comentarios |

Invariantes de WU2: `ComisionService` intacto; assert byte-idéntico (`check-pure`) intacto;
assert legacy `formatearJugada({tipo:'terminales'})` intacto (WU3); sin tests/builds/e2e
ejecutados por el agente.

### Handoff de runtime (ejecutado por el usuario)

```bash
cd backend
php artisan migrate --force      # Ran (migración 2026_11_06_000001)
php artisan juegos:export        # 20 juegos
cp ../docs/juegos.json ../taquilla/src/data/juegos.json
```

### Verificación WU2 (completada)

- `php artisan test --filter='...'` → **220 passed (2593 assertions)**.
- `node scripts/check-pure.mjs` → **658 checks, 0 fallos**.
- Incidencia de entorno: `backend/vendor` era un symlink al repo principal y Composer cargaba
  `PremiosOficiales`/`DatabaseSeeder` viejos (21 juegos). Se reemplazó por un `composer install`
  real en el worktree; `PremiosOficiales::slugs()` = 20.
- `sdd-attempt settle` WU2 → `passed`, pero `changed_lines=2651 > 800`; reset aprobado por
  maintainer (`jmxsdev`) con `--expected-revision sha256:8ad9…`; estado posterior `next_action: begin`.
- Commit WU2: `3a4da66`.

## WU3 — Fase 2 limpieza del contrato TS + docs/specs

Estado: **implementado y commiteado** en local (sin push). **Tests pendientes** (los ejecuta el
usuario). Commit: `feat(taquilla): retira el tipo terminales y limpia el contrato legacy (WU3)`
(hash en el envelope de WU3).

### Contrato final del front

- `TipoJuego = 'animalitos' | 'tripletas'`; `TIPOS_VALIDOS` idem; `derivarFamilia` ya no devuelve
  `terminal`. `cargarCatalogo` rechaza `tipo: 'terminales'` con `juegos[i]: "tipo" inválido`.
- Se conservan `FamiliaOpciones 'terminal'`, `claveNumerica`, `DEFS.terminal` y la modalidad
  posicional (`terminal`/`punta`/`uña` contra el triple vendido).
- `TipoLineaTicket` sin `terminales`; el payload legacy `{numero}` se imprime `#05` por el
  formateo de tripletas (assert de `check-pure` incluido).

### Archivos

| Archivo | Acción | Cambio |
|---------|--------|--------|
| `taquilla/src/utils/catalogo.ts` | Modificado | `TipoJuego`/`TIPOS_VALIDOS`/`derivarFamilia` sin `terminales`; comentarios 20 juegos y modalidad terminal |
| `taquilla/src/utils/ticket.ts` | Modificado | `TipoLineaTicket` sin `terminales`; sin rama `formatearJugada` de terminal; comentarios |
| `taquilla/src/utils/recibos.ts` | Modificado | Fallback de tipo `'terminales'` → `'tripletas'` |
| `taquilla/src/pages/historial.astro` | Modificado | Mismo fallback; comentario del ticket impreso |
| `taquilla/src/pages/dashboard.astro` | Modificado | Sin `familia === 'terminal'`, sin payload `juegoType === 'terminales'`, sin `else { listo = true; }`; comentarios |
| `panel/src/pages/resultados-loterias.astro` | Modificado | Comentario `animalitos/terminales` → `animalitos` |
| `taquilla/scripts/check-pure.mjs` | Modificado | Assert de rechazo de `tipo:'terminales'`; assert legacy sobre `tipo:'tripletas'`; assert byte-idéntico intacto |
| `taquilla/package.json` | Modificado | `1.2.0` → `2.0.0` |
| `CHANGELOG.md` | Modificado | Sección `[2.0.0]`: catálogo 20, sin tipo `terminales`, sin secciones Terminales |
| `backend/docs/juegos.md` | Modificado | 20 juegos, sin `terminal-activo`, tipos `animalitos|tripletas`, hueco de id 3 |
| `docs/dev/motor-premios.md` | Modificado | 20 juegos; fuera plugin y fila de Terminal Activo; nota de modalidad |
| `docs/dev/arquitectura-backend.md` | Modificado | 21→20 en seeders/PremiosOficiales; nota de tipos |
| `docs/dev/integracion-front-motor-premios.md` | Modificado | 21→20; fuera payload/resultado de Terminal Activo; nota de modalidad |
| `docs/dev/runbook-ops.md` | Modificado | Catálogo bundled 21→20 |
| `docs/dev/pendientes-front.md` | Modificado | TQ-06 21→20; DEC-06 sin «terminales faltantes»; DEC-09 cerrada + BE-22; BE-07 sin Terminal |
| `docs/dev/pendientes-taquilla.md` | Modificado | Backlog de terminales cerrado; premios punta/terminal resueltos |
| `docs/cliente/README.md` | Modificado | 21→20 |
| `docs/cliente/multiplicadores-juegos.md` | Modificado | 21→20; fuera fila Terminal Activo; nota de modalidad y hueco #3 |
| `docs/cliente/premiacion-juegos.md` | Modificado | 21→20; fuera sección Juegos de Terminales; nota en Trío Activo |
| `docs/cliente/comparacion-juegos.md` | Modificado | Sin fila `terminal-activo`; resumen 19/20; nota de modalidad |
| `docs/cliente/fuentes-oficiales.md` | Modificado | Sin fila `terminal-activo`; nota histórica de la modalidad |
| `docs/cliente/seguimiento-verificacion.md` | Modificado | Sin fila 3 del juego; conteos /20; nota de modalidad; evidencia histórica rotulada |
| `docs/cliente/inconsistencias.md` | Modificado | H12 apunta a la modalidad `terminal` del Trío Activo |
| `docs/cliente/alcance-proyecto.md` | Modificado | DEC-09 resuelta al 25 % vía `tripletas`; DEC-01 como modalidad; control de cambios 1.2 |
| `openspec/changes/terminal-como-modalidad/tasks.md` | Modificado | 3.1–3.6 marcadas `[x]` |

### Invariantes preservados (WU3)

- `DEFS.terminal`, `FamiliaOpciones 'terminal'`, `claveNumerica` y la validación posicional
  (`triple` obligatorio) intactas: la modalidad terminal sigue vendible.
- Catálogo generado sin cambios en WU3 (20 juegos byte-idénticos; lo cerró WU2).
- Assert byte-idéntico de `check-pure` intacto; `motor-premios`/`comisiones`/`catalogo-juegos`
  deltas sin fusionar (los archiva el paso de archive).

### Work Unit Evidence (WU3)

| Evidence | Value |
|---|---|
| Focused test command and exact result | **Pendiente** (el usuario ejecuta `cd taquilla && node scripts/check-pure.mjs`). Estático: `grep -rn "terminales" taquilla/src panel/src` → 0; `grep` de `terminal-activo` en `docs/` → solo nombres de PDF históricos. |
| Runtime harness command/scenario and exact result | **Pendiente** (`cd taquilla && pnpm e2e`). Escenario a cubrir: venta de terminal del Trío Activo (2 cifras + triple), ticket `Terminal #NN Triple X`, y reimpresión de ticket legacy `{numero}` → `#05`. |
| Build | **Pendiente** (`cd taquilla && npm run build`). |
| Rollback boundary | `git revert` del commit WU3: restaura el tipo `terminales` y las ramas legacy de taquilla, `1.2.0` y los docs previos; no toca backend, migración ni catálogo. |

### Handoff de pruebas (ejecuta el usuario)

```bash
cd /home/gzuz/Documentos/lotto-app-worktrees/terminal-como-modalidad/taquilla
npm run build
node scripts/check-pure.mjs
pnpm e2e
cd ../backend
composer test
```


## Notas de planificación WU3 (histórico)

- Limpieza de contrato TS: `TipoJuego`/`TIPOS_VALIDOS`/`derivarFamilia`, `TipoLineaTicket`,
  ramas legacy de ticket y payload, `2.0.0` + CHANGELOG, docs/specs.
- Tests de cierre: `cd taquilla && npm run build && node scripts/check-pure.mjs && pnpm e2e`;
  después `cd backend && composer test` (suite completa).
- No tocar `DEFS.terminal`, `FamiliaOpciones 'terminal'`, `claveNumerica` ni la modalidad
  posicional: siguen siendo la modalidad vendible.

## Notas para WU2

- **No rebase onto #89**: #89 sigue en su worktree sin mergear; mezclarlo en este
  tracker violaría el aislamiento de ciclos. La migración usa
  `2026_11_06_000001`, posterior a `2026_11_05_000001/000002` de #89, así que no
  hay colisión de timestamp ni de orden.
- WU2 se ejecuta en dos pasos: (a) el agente implementa migración + código +
  tests y deja el árbol listo — **hecho en WU2a**; (b) el usuario corre `php artisan migrate` +
  `juegos:export` — **hecho**; (c) el agente copia el catálogo 20, elimina logo/manifest,
  ajusta `check-pure` y commitea WU2 — **hecho en WU2b**. El usuario ejecuta los tests backend.
