# Propuesta: liquidación de comodines Selva y tripleta multi-sorteo

## Intent

Cerrar el issue #63 con tres cambios de dominio acotados:

1. **Selva Plus**: el comodín A/B es una figura apostable independiente. Hoy el motor compara solo
   `nombre_animal`, y un sorteo con comodín no trae animal; por eso ninguna apuesta gana. La fuente
   oficial devuelve la letra (`result: "A"|"B"`), no un animal, y el front oficial la rotula
   `Comodín A, Leoncito` / `Comodín B, Selva Plus`.
2. **Tripleta Cazalotón / Loto Chaima**: los reglamentos la definen como **tres figuras distintas en tres
   sorteos distintos** del mismo juego y del mismo día. El motor la modela como same-draw con
   `figuras[]`, un contrato que ningún scraper produce. Se corrige a multi-sorteo **todo o nada**.
3. **`premio_posible`**: no se imprime, ningún front lo consume y no interviene en el pago. Se elimina
   del cálculo, de la persistencia y de los tests.

El hallazgo E (formato plano sin `nombre_animal`) **no se toca**: queda documentado como riesgo latente
en los archivos de seguimiento.

## Scope

### In Scope

- Comodín Selva A/B en el **plugin compartido `Animalitos`** (sin carpeta por juego).
- Liquidación de Selva contra la forma real del scraper (`{comodin:"A"|"B", comodin_nombre}`).
- Eliminación de `PremiosEngine::premioPosible`, `JuegoInterface::modalidadDe`, la llamada en
  `ApuestaService`, las columnas `detalle_apuestas.premio_posible(_usd)` y los tests que las asumen.
- Tripleta multi-sorteo todo o nada: 3 figuras distintas, 3 sorteos distintos, mismo juego/día.
- Evidencia de los sorteos ganadores para auditoría y pago (`apuestas.resultado_ids`).
- Ajuste de venta, settlement, pago, ganadores y vencimiento para la tripleta de día.
- Tests de regresión con las formas reales de Selva y tripleta.
- Documentación de seguimiento del formato plano (sin cambio de código).

### Out of Scope

- Pago parcial de tripleta (1 o 2 aciertos). La regla es todo o nada.
- Migración de todas las loterías a un archivo/carpeta por juego. Hoy las tres clases de plugin son
  `Animalitos`, `Tripletas` y `Terminales`; la convención por juego queda como refactor separado.
- Cambios al scraper de Selva, Chaima o Cazalotón: ya persisten la forma correcta.
- Cambios al mapper de formato plano (`AnimalitosScraper::mapFlatResult`).
- Duplicar apuestas (Dupleta), que sigue fuera de alcance.

## Decisiones cerradas

| # | Decisión |
|---|----------|
| 1 | Selva se liquida en el **plugin compartido `Animalitos`**: reconoce la letra A/B y compara la apuesta contra `comodin-a`/`comodin-b`, la letra, el nombre o el label. No se crea una carpeta por juego. |
| 2 | En un sorteo con comodín A/B, **solo** gana la apuesta al comodín; una apuesta a un animal pierde. |
| 3 | La tripleta es **todo o nada**: 3 aciertos en 3 sorteos distintos. No hay pago parcial. |
| 4 | Las 3 figuras deben ser distintas y del mismo juego/día; no se mezclan loterías ni se reutiliza un sorteo. |
| 5 | La tripleta no se ata a un `sorteo_hora` de sorteo: se vende como jugada de día; `sorteo_hora` queda como hora de venta para vencimiento. |
| 6 | La evidencia de los 3 sorteos ganadores se guarda en `apuestas.resultado_ids` (JSON) y se usa para auditoría y para el pago. |
| 7 | `premio_posible` se elimina por completo: método, interfaz, llamada, columnas y tests. |
| 8 | El formato plano no se corrige ahora; solo se documenta como riesgo latente. |
| 9 | La estructura de un archivo/carpeta por juego es un refactor posterior para **todos** los juegos, no solo Selva. |

## Capabilities

### Modified Capabilities

- `motor-premios`: comodín Selva como figura apostable, tripleta multi-sorteo todo o nada, eliminación
  de `premio_posible`.
- `liquidacion-multisorteo` (nueva): settlement por día, evidencia de sorteos ganadores, pago y
  vencimiento de la tripleta.

## Approach

1. **Refactor de premio posible**: borrar el cálculo y la persistencia. Reemplazar la validación de
   modalidad declarada que vivía en `premioPosible` por un guard explícito en `ApuestaService`.
2. **Selva en el plugin compartido**: agregar en `Animalitos::evaluarAcierto` la rama de comodín de
   letra; no crear `SelvaPlus` ni una carpeta por juego.
3. **Tripleta multi-sorteo**: agregar una capacidad `JuegoMultiSorteoInterface` implementada por
   `Animalitos`; el settlement busca apuestas de tripleta pendientes del juego/día, evalúa contra todos
   los resultados y gana solo con 3 coincidencias en 3 sorteos distintos. La última extracción del día
   marca las pendientes como perdidas.
4. **Pago y auditoría**: `apuestas.resultado_ids` guarda los IDs ganadores; `PagoController` recalcula
   con esa evidencia y el snapshot de premios.
5. **Front**: la tripleta se agrega una sola vez (sin expansión por horario) y el comodín se envía con
   su `value` canónico, no con `numero:"NaN"`.
6. **Tests**: reemplazar los contratos irreales por las formas reales de los scrapers.

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1,300–1,700 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |
| Review budget | 800 lines per PR |

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command |
|------|------|-----------|----------------------|
| 1 | Eliminar `premio_posible` (código, columnas, tests, docs) | PR 1 | `php artisan test --filter="PremiosEngineTest\|ModalidadesVentaTest\|ApuestaTest\|VerificarGanadoresTest\|PremioSnapshotTest\|ModalidadesSingleDrawTest"` |
| 2 | Comodín Selva en `Animalitos` + tests reales + payload del front | PR 2 | `php artisan test --filter="AnimalitosPluginTest\|SelvaPlus"` |
| 3 | Contrato multi-sorteo + settlement + evidencia + pago | PR 3 | `php artisan test --filter="TripletaMultiSorteo\|VerificarGanadores\|Pago"` |
| 4 | Front de tripleta de día + docs + seguimiento del formato plano | PR 4 | `npm run build` (taquilla) |

**Decision needed before apply:** No.
