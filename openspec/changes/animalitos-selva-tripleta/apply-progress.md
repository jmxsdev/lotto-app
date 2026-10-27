# Apply progress: animalitos Selva y tripleta multi-sorteo

## Estado

- [x] PR 1 — Eliminar `premio_posible` (código, columnas, tests, docs)
- [x] PR 2 — Comodín Selva en el plugin compartido `Animalitos` + tests reales + front comodín
- [x] PR 3 — Tripleta multi-sorteo backend (contrato, settlement, evidencia, pago, ganadores)
- [x] PR 4 — Front tripleta de día + docs + seguimiento del formato plano
- [ ] Tests ejecutados por el usuario (pendiente de salida)

## Decisión de estructura

No se creó una carpeta/archivo por juego. Los plugins siguen siendo tres:
`Animalitos.php`, `Tripletas.php` y `Terminales.php`. El comodín Selva A/B se resolvió dentro de
`Animalitos`, que es el plugin compartido de todas las loterías de animalitos. La convención por juego
queda como refactor posterior para todos los juegos, no solo Selva.

## Archivos modificados

### Backend

- `backend/app/Services/PremiosEngine.php`: eliminado `premioPosible()`/`modalidadDe()`; agregado `calcularMultiSorteo()`.
- `backend/app/Plugins/Contracts/JuegoInterface.php`: eliminado `modalidadDe()`.
- `backend/app/Plugins/Contracts/JuegoMultiSorteoInterface.php`: nueva capacidad opcional.
- `backend/app/Plugins/Juegos/Animalitos.php`: comodín de letra A/B en `evaluarAcierto()`; `comodinesDelResultado()`/`numerosGanadores()` protegidos; tripleta same-draw retirada; `evaluarMultiSorteo()` con matching 1:1 por hora.
- `backend/app/Plugins/Juegos/Tripletas.php`: `modalidadDe` privado (solo validación).
- `backend/app/Plugins/Juegos/Terminales.php`: `modalidadDe` eliminado.
- `backend/app/Services/JuegoPluginManager.php`: `soportaMultiSorteo()`, `evaluarMultiSorteo()`, `calcularPremioMultiSorteo()`.
- `backend/app/Services/ApuestaService.php`: eliminado `premioPosible`; guard de modalidad; venta de tripleta de día; `verificarMultiSorteo()`; exclusión de tripletas del camino single-result.
- `backend/app/Http/Controllers/Api/PagoController.php`: pago multi-sorteo con `resultado_ids` y snapshot.
- `backend/app/Http/Controllers/Api/TicketController.php`: `ganadores` agrega la pasada multi-sorteo.
- `backend/app/Http/Requests/ApuestaStoreRequest.php`: `sorteo_hora` acepta ISO/`date`.
- `backend/app/Models/Apuesta.php`: `resultado_ids` fillable + cast.
- `backend/app/Models/DetalleApuesta.php`: eliminados `premio_posible(_usd)`.
- `backend/app/Jobs/MarcarApuestasVencidasJob.php`: documentado el caso tripleta/resultado faltante.
- `backend/database/migrations/2026_10_26_000000_drop_premio_posible_from_detalle_apuestas.php`: drop de columnas.
- `backend/database/migrations/2026_10_26_000001_add_resultado_ids_to_apuestas.php`: JSON de evidencia.
- `backend/database/seeders/SelvaPlusSeeder.php`: enlaza `Animalitos::class` con `updateOrCreate`.

### Front

- `taquilla/src/pages/dashboard.astro`: comodín con `value` canónico (sin `numero:"NaN"`); tripleta de día (sin expansión por horario); impresión con las 3 figuras.
- `taquilla/src/utils/modalidades.ts`: `construirTripleta` exige 3 figuras distintas.

### Tests

- `backend/tests/Unit/AnimalitosPluginTest.php`: casos reales de Selva (comodín A/B, label, animal pierde, comodín contra numérico) y engine 160×/200×; tripleta con figuras repetidas; test irreal de Selva eliminado.
- `backend/tests/Feature/TripletaMultiSorteoTest.php` (nuevo): 3 sorteos, todo o nada, juego distinto, pago con evidencia, pago sin evidencia, venta con <3 sorteos.
- `backend/tests/Unit/PremiosEngineTest.php`: eliminados stub `modalidadDe` y tests `premio_posible`.
- `backend/tests/Feature/ModalidadesSingleDrawTest.php`: retirados los casos de tripleta same-draw y el test `premio_posible`.
- `backend/tests/Feature/ModalidadesVentaTest.php`: helper sin `premio_posible`; fecha fija para tripleta.
- `backend/tests/Feature/ApuestaTest.php`, `VerificarGanadoresTest.php`, `PremioSnapshotTest.php`: sin `premio_posible`.
- `backend/tests/Unit/ApuestaServiceTest.php`: retirados tests de `premio_posible`.
- `backend/tests/Unit/TerminalesPluginTest.php`, `TripletasPluginTest.php`: retirados tests de `modalidadDe`.
- `backend/tests/Feature/SelvaPlusResultsTest.php`: espera `Animalitos::class`.

### Docs / SDD

- `docs/dev/motor-premios.md`: flujo sin `premio_posible`; comodín Selva en plugin compartido; tripleta multi-sorteo.
- `docs/dev/integracion-front-motor-premios.md`: sin `figuras[]`; tripleta de día; modalidad obligatoria.
- `docs/dev/pendientes-front.md`: BE-07 documenta el formato plano sin `nombre_animal` (sin código).
- `openspec/changes/animalitos-selva-tripleta/{proposal,design,tasks}.md` + deltas `motor-premios` y `liquidacion-multisorteo`.

## Comandos para el usuario

Ejecutar en el worktree `Documentos/lotto-app-worktree/issue-63-animalitos-tripleta` (requiere `backend/vendor` y `.env`/`.env.testing`; el worktree es un checkout limpio):

```bash
cd backend
php artisan test --filter='PremiosEngineTest|ModalidadesVentaTest|ApuestaTest|VerificarGanadoresTest|PremioSnapshotTest|ModalidadesSingleDrawTest'
php artisan test --filter='AnimalitosPluginTest|SelvaPlus'
php artisan test --filter='TripletaMultiSorteo|VerificarGanadores|Pago'
```

Y en `taquilla/`: `npm run build`.

No se ejecutaron tests ni builds desde el agente.
