# Design: Configuración de juegos — edición de premios con snapshot no retroactivo

**Estado**: draft · **Fase**: design · **Fecha**: 2026-09-28 · **Cambio**: `configuracion-juegos`

## Enfoque

`PremiosConfigService` concentra validación, merge, espejos y auditoría; el endpoint queda delgado. El
snapshot se persiste al vender y el motor lo recibe como override opcional. Cubre ambos specs del cambio.

## Decisiones

| # | Decisión | Alternativa descartada | Razón |
|---|---|---|---|
| D1 | `PUT /api/v1/juegos/{juego}/premios` → `JuegoController::updatePremios()` + `PremiosConfigService` (`clavesModalidadValidas()`, `actualizar()`); grupo `role:super_master\|master` (`api.php` L120-123); 200 `$juego->load('pluginJuego')`; auditoría `accion='premios'` con `before/after` y `updated_by`; merge de alto nivel (`array_merge($config, ['premios' => ...])`) preserva `scraper`/`modalidades_permitidas`. | Inline (1.044 líneas); `PUT /juegos/{id}` (reemplazo total) | Patrón Services: aislable, sin regresión. |
| D2 | Espejos: `PremiosOficiales::espejosLegacy($slug, $premios)` público y `configPara()` delega; devuelve `premio_multiplo` (=base), `modalidades` (`ESPEJO_MODALIDADES`+`ESPEJO_EXTRA`) y `comodines`. | Duplicar el mapa; moverlo de clase | Fuente única. |
| D3 | Claves de `premios.modalidades`: `plugin->obtenerModalidades()` (`code`) ∪ `PremiosOficiales::para($slug)['modalidades']`; `la-ricachona` → 422. | Validar solo contra el plugin | Evita premio 0 silencioso. |
| D4 | Snapshot: `detalle_apuestas.premios_snapshot` JSON nullable, poblado en `createApuesta`; `PremiosEngine::calcular()` y `JuegoPluginManager::calcularPremio()` suman `?array $premios = null` (propagado a `multiplicadorPara`/`multiplicadorConComodines`); `null` → `config.premios` actual. | Columna en `apuestas`; config actual (retroactividad) | Parámetro opcional: call sites intactos. |
| D5 | `vendible` = espejo de `active`; sin columna. | Desacoplarlo | REQ6. |
| D6 | Export por comando (`juegos:export --path`), sin side effect HTTP; nota en `docs/motor-premios.md` §9.1 y coordinación de la copia bundled de taquilla (sin tocar su código). | Reescribir `docs/juegos.json` desde el endpoint | `JuegosJsonTest` lo guarda. |

**Schema**: `premios` required|array; `base` required|integer|min:1; `modalidades` array, valores
integer|min:1, clave fuera de la unión → 422; `comodines` array con `tipo` in flag,letra,numero,palabra,
`premio_multiplo` integer|min:1 y `acumulativo` truthy solo con `tipo=palabra` (si no, 422); omitirlos los
reemplaza por `{}` (atómico) y el objeto se normaliza completo.

**Modelo**: migración `2026_09_28_000001_add_premios_snapshot_to_detalle_apuestas_table.php` (`json`
nullable `after('premio_ganado_usd')`; `down()` drop); `DetalleApuesta` fillable + cast `array`; filas
viejas `null` → fallback legacy.

## Flujo

```
Venta   createApuesta → premios_snapshot = config.premios
Editar  PUT .../premios → validar → merge → espejos → auditar
Pagar   premios_snapshot ?? config.premios → PremiosEngine
Export  juegos:export → docs/juegos.json → copia taquilla (coordinación)
```

**Contratos**: `PremiosEngine::calcular/multiplicadorPara` y `JuegoPluginManager::calcularPremio` aceptan
`?array $premios = null`; `PremiosConfigService::clavesModalidadValidas/actualizar(Juego, array, int)`;
`PremiosOficiales::espejosLegacy(string, array)`.

## Archivos

**Crear**: `PremiosConfigService.php`, migración
`2026_09_28_000001_add_premios_snapshot_to_detalle_apuestas_table.php` y tests `JuegoPremiosApiTest`,
`PremioSnapshotTest`, `JuegoToggleTest`, `JuegoUpdateTest` (bajo `backend/`).
**Modificar**: `JuegoController` (`updatePremios`), `routes/api.php`, `PremiosOficiales`, `PremiosEngine`,
`JuegoPluginManager`, `ApuestaService` (venta con snapshot; `verificarGanadores` con `with('detalles')`),
`PagoController` (snapshot del detalle), `DetalleApuesta`, `PremiosEngineTest`,
`panel/src/pages/juegos.astro`, `docs/motor-premios.md`.

## Slices (feature-branch-chain, cada PR < 400 líneas)

| Slice | Contenido | Depende de | Commits |
|---|---|---|---|
| S1a | Servicio + endpoint + ruta + `espejosLegacy` + auditoría; tests unit/endpoint | — (target `feat/configuracion-juegos`) | `feat(juegos): endpoint de premios con merge seguro` |
| S1b | Tests integración: espejos, auditoría, `/reglas`, export `--path` | S1a | `test(juegos): espejos, auditoría y export` |
| S2 | Migración + venta con snapshot + override motor/manager + liquidación/pago + tests | S1a | `feat(premios): snapshot por apuesta` |
| S3 | Fix toggle + tests deuda `toggle()`/`update()` + nota re-export/taquilla | S1b | `fix(panel): toggle con active y error visible`; `test(juegos): update y toggle`; `docs(juegos): nota de re-export` |
| S4 | Editor P2 en `juegos.astro` (base/modalidades/comodines, errores, auditoría) | S1a | `feat(panel): editor de premios` |

Total ~870-1.170 líneas; S1 partido para no exceder 400 por PR.

## Plan TDD (Strict TDD, RED → GREEN → REFACTOR)

Evidencia: `DB_DATABASE=lotto_test_motor php artisan test --filter=<Test>`; suite completa y
`vendor/bin/pint --test` por slice.

- **S1a RED**: servicio (unión plugin∪catálogo, clave inválida, `la-ricachona`); endpoint 200, 403, 422
  (base/valor/tipo/`acumulativo`/clave), canónica sin plugin, reemplazo atómico, auditoría `accion=premios`.
- **S1b RED**: espejos, preserva `scraper`/`modalidades_permitidas`, `before/after`, `/reglas`,
  `juegos:export --path`.
- **S2 RED**: venta persiste snapshot; vender 50× → editar 60× → `verificarGanadores` liquida 10×50; pago
  ±0,01 contra snapshot (422 con monto nuevo); sin snapshot → config actual; unit override vs. default.
- **S3 RED**: toggle 422 sin `active`; persiste y audita; plugin sincronizado; `update()` audita; panel
  envía `{active}` y muestra error visible + `npm run build`.
- **S4 RED**: `npm run build` + smoke manual del editor.

## Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Retroactividad | Snapshot + fallback legacy + auditoría |
| Espejos desincronizados | `espejosLegacy()` única fuente + tests S1b |
| `JuegosJsonTest`/taquilla | Sin side effect HTTP; test `--path`; nota |
| Payloads malformados | Validación estricta D3 |
| Concurrencia edición/venta | Último-write-wins + auditoría |
| Panel sin tests | `npm run build` + smoke manual |

**Rollback por slice**: S1a revertir ruta/servicio (aditivo); S1b tests; S2 `migrate:rollback` + revertir
call sites (nullable → fallback); S3 panel/nota; S4 página. La auditoría `premios` reconstruye
`config.premios`.

## Threat Matrix

N/A — HTTP de aplicación con rol; sin shell, subprocesos, VCS/PR ni procesos.

## Open Questions

Ninguna.
