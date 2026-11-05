# Tasks: Terminal como modalidad (absorber `terminal-activo` en Trío Activo)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~950–1,300 autorales (catálogo JSON generado no cuenta) |
| 400-line budget risk | Medium |
| Review budget | 800 líneas autorales por PR |
| Chained PRs recommended | Yes |
| Suggested split | WU1 → WU2 → WU3 (PR 1 → PR 2 → PR 3) |
| Delivery strategy | auto-chain |
| Chain strategy | feature-branch-chain |
| Decision needed before apply | No |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: Medium

### Work Units

| Unit | Goal | PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|----|----------------------|-----------------|-------------------|
| 1 | Fase 1 fronts: quitar pestaña Terminales (backend intacto) | PR 1 (base=tracker) | `cd taquilla && npm run build` · `node scripts/check-pure.mjs` | `pnpm e2e` (si harness+BD disponibles) | `git revert` del WU; publicar `1.2.1` con pestaña restaurada |
| 2 | Fase 2 backend: migración + seeder/plugin/premios/scraper/tests + catálogo 20 + logo + conteos check-pure | PR 2 (base=PR 1) | `php artisan test --filter='JuegosJsonTest\|TerminalesPluginTest\|AnimalitosScraperTest\|ScraperSourceResolverTest\|PremiosOficialesTest\|PremiosEngineTest\|ComisionServiceTest\|ComisionesApiTest\|ComisionCierreTest\|ComisionReporteTest\|DivergenciaRolTest\|MotorPremiosRegresionTest\|ResultadoAparicionesTest\|VerificacionOriginalesTest'` · `composer test` | `php artisan migrate` · `juegos:export` · `cp` copia bundled · `node scripts/check-pure.mjs` | `git revert` WU2; `db:seed --class=TerminalesSeeder`; catálogo 21 desde git |
| 3 | Fase 2 taquilla: limpieza de contrato TS + docs/specs | PR 3 (base=PR 2) | `cd taquilla && npm run build` · `node scripts/check-pure.mjs` | `pnpm e2e` (si disponible) | `git revert` del WU |

> El usuario ejecuta los tests y comparte la salida. El agente NO ejecuta `npm run build`, `composer test`, `php artisan test`, `pnpm e2e` ni `juegos:export`. Si el censo real de WU2 supera 800 líneas autorales, partir en WU2a (runtime+migración+catálogo) y WU2b (tests/fixtures).

## Phase 1: WU1 — fronts + release (backend sin cambios)

- [ ] 1.1 `taquilla/src/pages/dashboard.astro`: `ordenTabs()` (1570) → `['animalitos','tripletas'].filter(...)`; `labels` (1589) sin `terminales`; icono tab (1611) → `activeTab === 'animalitos' ? icono('dices') : icono('target')`. No tocar `TIPOS_VALIDOS`, `derivarFamilia`, catálogo 21 ni ramas legacy de ticket (2514).
- [ ] 1.2 `taquilla/src/pages/resultados.astro`: borrar solo el botón `data-tab="terminales"` (línea 20).
- [ ] 1.3 `panel/src/pages/resultados-loterias.astro`: borrar solo el botón `data-tab="terminales"` (línea 19).
- [ ] 1.4 `taquilla/package.json`: `1.1.0` → `1.2.0`; `CHANGELOG.md` (raíz) entrada `[1.2.0]`: «Retiro de la pestaña Terminales; la jugada terminal se vende como modalidad del Trío Activo».

## Phase 2: WU2 — backend, migración, catálogo 20 y logo

- [ ] 2.1 Crear `backend/database/migrations/2026_11_06_000001_retire_terminal_activo.php` (después de #89): idempotente (`value('id')` null → return), guard `apuestas` (throw), borrado explícito en orden `juego_limites, juego_horarios, juego_opciones, plugin_juegos, resultados, juego_auditoria, juegos`; `down()` no-op documentado.
- [ ] 2.2 `backend/database/seeders/DatabaseSeeder.php` (23): quitar `TerminalesSeeder::class`; eliminar `backend/database/seeders/TerminalesSeeder.php` y `backend/app/Plugins/Juegos/Terminales.php`.
- [ ] 2.3 `backend/app/Support/PremiosOficiales.php`: quitar entrada `'terminal-activo'` (48-54), `ESPEJO_EXTRA` (295-303) + bucle (398-403); docblock «21 juegos»→20.
- [ ] 2.4 `backend/app/Plugins/Scrapers/AnimalitosScraper.php`: `['trio_activo','terminal_activo']`→`['trio_activo']` (101); quitar `'terminal-trio' => 'terminal-activo'` (132); fallback `$gameName` sin «Terminal Activo» (70).
- [ ] 2.5 `backend/app/Services/ScraperSourceResolver.php`: quitar `KEY_TERMINAL_ACTIVO` (31), rama `terminal_activo` de `slugLottoactivo` (178-180), docblock (18).
- [ ] 2.6 Comentarios: `ResultadoController.php` (58) `animalitos/terminales`→`animalitos`; `JuegoMultiSorteoInterface.php` (10) quitar `Terminales`; `TripleFacilSeeder.php` (66) «plugin Terminales»→«opciones numéricas 00-99». `ComisionService.php` SIN cambios.
- [ ] 2.7 Tests backend: eliminar `TerminalesPluginTest.php`; `AnimalitosScraperTest` (2 tests terminal_activo, `Juego::create` en setUp, fixtures `lottoactivo_terminal_activo_{page,response}`); `MotorPremiosRegresionTest` (2 tests); `ResultadoAparicionesTest` (identidad terminales); `VerificacionOriginalesTest` (`test_terminal_activo_premio_60_del_reglamento`).
- [ ] 2.8 `PremiosOficialesTest` 21→20, quitar `terminal-activo` + `test_terminal_activo_base_60` + `test_config_para_terminal_activo_*`; `PremiosEngineTest` fixture→`trio-activo` (241); `JuegosJsonTest` `SLUGS_POR_ID` con hueco en 3, 21→20, quitar asserts terminal-activo.
- [ ] 2.9 `ScraperSourceResolverTest` reescribir «trio y terminal fuentes distintas»→«trio-activo fuente propia»; `ScraperResolverTest` (109) y comisiones (`ComisionServiceTest`, `ComisionesApiTest`, `ComisionCierreTest`, `ComisionReporteTest`, `DivergenciaRolTest`) `type:'terminales'`→`'bingo'`, tests renombrados «otros tipos sin tope»; `topePorTipo` sigue `default=100`.
- [ ] 2.10 Catálogo: `cd backend && php artisan migrate` · `php artisan juegos:export` (20, ids 1,2,4…21) · `cp ../docs/juegos.json ../taquilla/src/data/juegos.json` (nunca editar a mano). Quitar `terminal-activo` de `logos.json`, `manifest.json` y asset `terminal-activo.webp`.
- [ ] 2.11 `taquilla/scripts/check-pure.mjs`: 21→20; quitar `porTipo.terminales`/`porFamilia.terminal`, `terminal-activo` de `familiaEsperada` + assert 100 opciones; `porId`/`porSlug` 20; mover búsqueda `"05"` a `trio-activo`; `conBase` 20→19; comentario `la-ricachona` 21→20. No tocar assert byte-idéntico (889).

## Phase 3: WU3 — taquilla contrato + docs/specs

- [ ] 3.1 `taquilla/src/utils/catalogo.ts`: `TipoJuego` (17) y `TIPOS_VALIDOS` (101) sin `terminales`; `derivarFamilia` sin rama `terminales` (145); comentarios (5,14,102,356). Conservar `FamiliaOpciones 'terminal'`, `claveNumerica`, `DEFS.terminal`.
- [ ] 3.2 `taquilla/src/utils/ticket.ts`: `TipoLineaTicket` (14) sin `terminales`; borrar rama `formatearJugada` (83-85); `recibos.ts` fallback (126) y `historial.astro` (347,353) `'terminales'`→`'tripletas'`.
- [ ] 3.3 `taquilla/src/pages/dashboard.astro`: quitar bloque `familia === 'terminal'` (2348-2349), fallback payload `juegoType === 'terminales'` (2514-2516), `else { listo = true; }` (2005-2006), comentarios 1902/2525-2526. `panel/src/pages/resultados-loterias.astro` comentario 141.
- [ ] 3.4 `taquilla/scripts/check-pure.mjs`: quitar assert `formatearJugada({tipo:'terminales'})` (932); añadir assert de rechazo `tipo:'terminales'` al cargar catálogo. `package.json` `1.2.0`→`2.0.0`; CHANGELOG `[2.0.0]`.
- [ ] 3.5 Docs: `backend/docs/juegos.md` 20 juegos; `docs/dev/{motor-premios,arquitectura-backend,integracion-front-motor-premios,runbook-ops,pendientes-front}.md`; `docs/cliente/{multiplicadores-juegos,premiacion-juegos,fuentes-oficiales,comparacion-juegos,glosario-premios,seguimiento-verificacion,inconsistencias,especificacion-requisitos-software,alcance-proyecto}.md` (21→20, DEC-09 resuelta §11).
- [ ] 3.6 Gate de cierre: `grep -rn "terminal-activo\|type.*terminales" taquilla/src panel/src backend/app backend/database/seeders backend/tests backend/docs docs` → 0 (excluye migraciones históricas y deltas). Deltas `motor-premios`/`comisiones`/`catalogo-juegos` se fusionan al archivar.

## Dependencias y orden

- Rebase del tracker `feat/terminal-como-modalidad` antes de WU2 (#89 pendiente); regenerar catálogo tras el rebase.
- Timestamp `2026_11_06_000001` (no `000003`) para no chocar con `2026_11_05_000001/000002` de #89.
- Catálogo + logo + conteos `check-pure` viajan JUNTOS en WU2 (D13) para mantener cada PR verde; WU3 después de WU2.
- Commits locales convencionales en español, un work unit por commit; `openspec/**/*.md` con `git add -f` (regla `*.md` del `.gitignore`). Sin push hasta que el orquestador fusione a main.

## Test handoff al usuario

Cuando el agente termine una unidad, pedirá al usuario ejecutar el comando de la tabla y compartir la salida. El agente no ejecuta builds, tests ni e2e.
