# Tasks: Motor de Premios (`motor-premios`)

> Solo backend · TDD RED→GREEN · Presupuesto review **800 líneas** (default SDD 400).

## Review Workload Forecast

Estimadas (add+del): ~1.800–2.300 → **High**; chained **Yes** (6 slices).

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: High

### Work Units sugeridos

| Unit | Goal | Base | Focused test | Harness | Rollback |
|---|---|---|---|---|---|
| 1 | F1a engine+catálogo+normalización | `feat/motor-premios` | `--filter='TextoTest\|PremiosOficialesTest\|PremiosEngineTest'` | N/A | revertir 3 archivos nuevos |
| 2 | F1b plugins+manager | PR 1 | `--filter='AnimalitosPluginTest\|TerminalesPluginTest\|TripletasPluginTest'` | N/A | revertir plugins+manager |
| 3 | F1c migraciones+seeders+export | PR 2 | `migrate:fresh --seed` + `--filter=JuegosJsonTest` | lotto_test | `migrate:rollback` ×3 |
| 4 | F1d call sites+regresión | PR 3 | `--filter=MotorPremiosRegresionTest` | lotto_test | revertir service+controllers |
| 5 | F2 modalidades single-draw | PR 4 | `--filter=ModalidadesSingleDrawTest` | lotto_test | revertir `selecciones[]` |
| 6 | F3 estados/vencimiento | PR 5 | `--filter='VencimientoApuestasTest\|ConfiguracionVencimientoTest'` | lotto_test | rollback ENUM+job |

## Fase 1 — Núcleo (config-driven, acentos, comodines)

- [x] 1.1 RED `tests/Unit/TextoTest.php` → GREEN `app/Support/Texto.php::normalizar()`. Done: "Delfín"≡"Delfin".
- [x] 1.2 RED `tests/Unit/PremiosOficialesTest.php` → GREEN `app/Support/PremiosOficiales.php` (21 juegos). Done: catálogo == spec.
- [x] 1.3 RED `tests/Unit/PremiosEngineTest.php` → GREEN `app/Services/PremiosEngine.php` (`calcular`,`premioPosible`,`reglas`). Done: 75+palabra=140×; 1.23456→1.23; inactivo→0.
- [x] 1.4 `app/Plugins/Contracts/JuegoInterface.php`: +`evaluarAcierto()`,`modalidadDe()`,`validarApuesta(data,?opciones)`. Done: 3 plugins implementan.
- [x] 1.5 RED `tests/Unit/AnimalitosPluginTest.php` → GREEN `app/Plugins/Juegos/Animalitos.php` (acentos). Done: H13/N10.
- [x] 1.6 RED `tests/Unit/TerminalesPluginTest.php` → GREEN `app/Plugins/Juegos/Terminales.php` (clave `numero`, padding). Done: 37→60× (N1).
- [x] 1.7 RED `tests/Unit/TripletasPluginTest.php` → GREEN `app/Plugins/Juegos/Tripletas.php` (signo label/sigla, tipo estricto). Done: REQ4/REQ5.
- [x] 1.8 RED `tests/Unit/AnimalitosScraperTest.php` → GREEN `app/Plugins/Scrapers/AnimalitosScraper.php` (mapper `patronus`). Done: H14.
- [x] 1.9 RED `tests/Feature/ScrapeResultsJobTest.php` → GREEN `BaseScraper.php` (upsert+hora) + `Jobs/ScrapeResultsJob.php`. Done: N6.
- [x] 1.10 `app/Services/JuegoPluginManager.php`: `calcularPremio`/`getMultiplicador`→engine; `validarApuesta` pasa `juego_opciones`. Done: REQ15.
- [x] 1.11 RED `tests/Unit/ApuestaServiceTest.php` → GREEN `ApuestaService::createApuesta` (guard inactivo, `premio_posible`). Done: REQ7/REQ12.
- [x] 1.12 RED `tests/Feature/ApuestaTest.php` → GREEN `Api/PagoController.php` (motor; acepta `ganadora`+legacy). Done: REQ10.
- [x] 1.13 RED `tests/Feature/TicketGanadoresTest.php` → GREEN `Api/TicketController::ganadores` (`whereTime`+engine). Done: REQ9.
- [x] 1.14 RED `tests/Feature/JuegosJsonTest.php` → GREEN `Api/JuegoController::reglas` + `JuegoCatalogoService` (`premios`,`active`,`vendible`); regenerar `docs/juegos.json`. Done: contrato catálogo. **Export (service + `docs/juegos.json` + test) ✅ en F1c**; `JuegoController::reglas` ✅ en F1d (aditivo).
- [x] 1.15 Migraciones: `000001_add_ganadora_to_apuestas_estado` · `000002_backfill_premios_config_juegos` · `000003_dedupe_resultados_sorteo_duplicado`. Done: `migrate` ×2 sin error (idempotencia) + rollback de las reversibles.
- [x] 1.16 Seeders 21: `config.premios`+espejos; `LaRicachonaSeeder`→`active=false`. Done: `migrate:fresh --seed` verde.
- [x] 1.17 RED `tests/Feature/MotorPremiosRegresionTest.php` (≥1 caso/juego+comodín). Done: verde.

## Fase 2 — Modalidades single-draw (sin tablas)

- [x] 2.1 RED unit `modalidadDe` → GREEN plugins derivan clave canónica (incl. `selecciones[]`). Done: claves §3.1.
- [x] 2.2 RED `tests/Feature/ModalidadesSingleDrawTest.php` (Cruzado, Par A+B, Tripleta, Arrimao, Pegadito, Punta/Terminal/Aprox, Terminal+Zodiacal) → GREEN `PremiosEngine` same-draw. Done: REQ11; Dupleta rechazada.

## Fase 3 — Estados, vencimiento, premio_posible

- [x] 3.1 RED (ganadora→pagada) → GREEN `ApuestaService::verificarGanadores` (`estado='ganadora'`+`resultado_id`, `whereNull`). Done: REQ13/N5.
- [x] 3.2 RED `tests/Unit` → GREEN `app/Services/ConfiguracionService.php` (key `apuestas.vencimiento_sin_resultado`, default 24h). Done: default.
- [x] 3.3 RED `tests/Feature/ConfiguracionVencimientoTest.php` (403) → GREEN `Api/ConfiguracionController.php` + `routes/api.php` (`role:super_master|master`). Done: role-gating.
- [x] 3.4 RED `tests/Feature/VencimientoApuestasTest.php` (catch-up) → GREEN `Jobs/MarcarApuestasVencidasJob.php` (relanza `ScrapeResultsJob`) + `routes/console.php`. Done: REQ13 búsqueda previa.
- [x] 3.5 Acumular `premio_total_*` ticket con `increment`. Done: sin sobreescritura multi-sorteo.
