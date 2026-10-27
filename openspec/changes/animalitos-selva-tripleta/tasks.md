# Tasks: animalitos Selva y tripleta multi-sorteo

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1,300–1,700 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 → PR 2 → PR 3 → PR 4 |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |
| Review budget | 800 lines per PR |
| Decision needed before apply | No |

### Work Units

| Unit | Goal | PR | Focused test command | Rollback boundary |
|------|------|----|----------------------|-------------------|
| 1 | Eliminar `premio_posible` | PR 1 | `php artisan test --filter="PremiosEngineTest\|ModalidadesVentaTest\|ApuestaTest\|VerificarGanadoresTest\|PremioSnapshotTest\|ModalidadesSingleDrawTest"` | Revertir métodos, migración y tests |
| 2 | Comodín Selva en `Animalitos` + tests reales + front comodín | PR 2 | `php artisan test --filter="AnimalitosPluginTest\|SelvaPlus"` | Revertir rama de comodín, seeder y tests |
| 3 | Tripleta backend multi-sorteo | PR 3 | `php artisan test --filter="TripletaMultiSorteo\|VerificarGanadores\|Pago"` | Revertir interfaz, settlement, migración y tests |
| 4 | Front tripleta de día + docs | PR 4 | `npm run build` en `taquilla/` | Revertir dashboard/utils/docs |

> El usuario ejecuta los tests y comparte la salida. El agente no ejecuta `composer test` ni `npm run build`.

## Phase 1: Eliminar premio_posible (PR 1)

- [x] 1.1 `backend/app/Services/PremiosEngine.php`: eliminar `premioPosible()` y `modalidadDe()`; limpiar imports/docblocks.
- [x] 1.2 `backend/app/Plugins/Contracts/JuegoInterface.php`: eliminar `modalidadDe()`.
- [x] 1.3 `backend/app/Plugins/Juegos/Animalitos.php`, `Tripletas.php`, `Terminales.php`: eliminar `modalidadDe()` (en `Tripletas` queda privado porque `validarApuesta` lo usa).
- [x] 1.4 `backend/app/Services/ApuestaService.php`: quitar la llamada a `premioPosible` y los campos `premio_posible(_usd)` del `DetalleApuesta::create`.
- [x] 1.5 `backend/app/Services/ApuestaService.php`: agregar guard de modalidad declarada contra `config.premios.modalidades`.
- [x] 1.6 `backend/app/Models/DetalleApuesta.php`: quitar los dos campos de `$fillable` y `$casts`.
- [x] 1.7 Migración `drop_premio_posible_from_detalle_apuestas`: drop de las dos columnas.
- [x] 1.8 `backend/tests/Unit/PremiosEngineTest.php`: eliminar el stub `modalidadDe`, el param `$modalidadDe` y los tests `premio_posible_*`.
- [x] 1.9 `backend/tests/Feature/ModalidadesVentaTest.php`: el helper deja de assertar `premio_posible`; solo valida 201 y `combinacion` persistida.
- [x] 1.10 `backend/tests/Feature/ModalidadesSingleDrawTest.php`: eliminar `test_dupleta_rechazada_en_premio_posible`.
- [x] 1.11 `backend/tests/Feature/ApuestaTest.php`, `VerificarGanadoresTest.php`, `PremioSnapshotTest.php`: quitar `premio_posible(_usd)`.
- [x] 1.12 Docs: `docs/dev/motor-premios.md`, `docs/dev/integracion-front-motor-premios.md`; buscar y quitar `premio_posible`/`premioPosible`.

## Phase 2: Comodín Selva en el plugin compartido (PR 2)

- [x] 2.1 `backend/app/Plugins/Juegos/Animalitos.php`: `numerosGanadores()` y `comodinesDelResultado()` de `private` a `protected`.
- [x] 2.2 `backend/app/Plugins/Juegos/Animalitos.php`: en `evaluarAcierto()`, detectar `comodin` A/B y delegar a `evaluarComodinLetra()`; comparar contra `comodin-a`/`comodin-b`, letra, `comodin_nombre` y label; animal pierde; comodín contra numérico pierde.
- [x] 2.3 `backend/database/seeders/SelvaPlusSeeder.php`: `PluginJuego::updateOrCreate` con `Animalitos::class` (revertir el piloto `SelvaPlus`).
- [x] 2.4 `backend/tests/Feature/SelvaPlusResultsTest.php`: esperar `Animalitos::class`.
- [x] 2.5 `backend/tests/Unit/AnimalitosPluginTest.php`: casos reales de Selva (comodín A/B, label, animal pierde, comodín contra numérico) y engine 160×/200×.
- [x] 2.6 Front comodín: `dashboard.astro` envía `value` canónico y no `numero:"NaN"`.
- [x] 2.7 Docs: `docs/dev/motor-premios.md` refleja el plugin compartido; no se crea carpeta por juego.

## Phase 3: Tripleta multi-sorteo backend (PR 3)

- [x] 3.1 Crear `backend/app/Plugins/Contracts/JuegoMultiSorteoInterface.php`.
- [x] 3.2 `backend/app/Plugins/Juegos/Animalitos.php`: implementar `evaluarMultiSorteo()` con matching 1:1 por hora de sorteo; validar 3 figuras distintas.
- [x] 3.3 `backend/app/Services/JuegoPluginManager.php`: `soportaMultiSorteo()`, `evaluarMultiSorteo()`, `calcularPremioMultiSorteo()`.
- [x] 3.4 `backend/app/Services/PremiosEngine.php`: `calcularMultiSorteo()`.
- [x] 3.5 Migración `add_resultado_ids_to_apuestas` (JSON nullable).
- [x] 3.6 `backend/app/Models/Apuesta.php`: `resultado_ids` en `$fillable` y `$casts`.
- [x] 3.7 `backend/app/Services/ApuestaService.php`: `esMultiSorteo()`, venta de día (3 sorteos futuros, `sorteo_hora = now()`), `verificarMultiSorteo()`, exclusión del camino single-result.
- [x] 3.8 `backend/app/Http/Controllers/Api/PagoController.php`: pago multi-sorteo con `resultado_ids` y snapshot.
- [x] 3.9 `backend/app/Http/Controllers/Api/TicketController.php`: `ganadores` agrega la pasada multi-sorteo.
- [x] 3.10 `backend/app/Jobs/MarcarApuestasVencidasJob.php`: documentar el caso de último resultado faltante.
- [x] 3.11 Tests: `TripletaMultiSorteoTest` (3 sorteos, 1-2 aciertos, juego distinto, pago con/sin evidencia, venta con <3 sorteos).
- [x] 3.12 `ModalidadesSingleDrawTest`: eliminar los casos de tripleta same-draw.

## Phase 4: Front de tripleta de día + docs (PR 4)

- [x] 4.1 `taquilla/src/utils/modalidades.ts`: `construirTripleta` exige 3 animales distintos.
- [x] 4.2 `taquilla/src/pages/dashboard.astro`: tripleta no exige horario; agrega una sola línea; impresión con las 3 figuras.
- [x] 4.3 `taquilla/src/utils/ticket.ts`: la tripleta se imprime sin hora de sorteo.
- [x] 4.4 Docs: `docs/dev/motor-premios.md`, `docs/dev/integracion-front-motor-premios.md`, `docs/dev/pendientes-front.md` (BE-07 formato plano).
- [x] 4.5 Deltas `motor-premios` y `liquidacion-multisorteo`; merge al archivar.

## Test handoff al usuario

Cuando el agente termine una unidad, pedirá al usuario ejecutar el comando de la tabla y compartir la
salida. No se ejecutan tests desde el agente.
