# Design: animalitos Selva y tripleta multi-sorteo

## Contexto

- Los scrapers ya persisten la forma correcta:
  - Selva: `numeros_ganadores = {pais:"VE", comodin:"A"|"B", comodin_nombre:"Leoncito"|"Selva Plus"}`.
  - Chaima/Cazalotón: una fila por sorteo con `{numero, nombre_animal, pais}`; no existe `figuras[]`.
- El motor (`PremiosEngine`) pide la **forma** al plugin (`evaluarAcierto`) y el **dinero** a
  `config.premios`. La orquestación (juego activo, opciones, límites, horarios) vive en
  `ApuestaService`/`JuegoPluginManager`.
- Hoy hay **tres** plugins en `backend/app/Plugins/Juegos/`: `Animalitos.php`, `Tripletas.php` y
  `Terminales.php`. Todas las loterías de animalitos comparten `Animalitos`; cada juego se ata con
  `plugin_juegos.class_namespace`. No existe una carpeta por juego.

## Decisiones de arquitectura

| # | Decisión | Alternativas | Razón |
|---|----------|--------------|-------|
| D1 | Comodín Selva en el plugin compartido `Animalitos` | Crear `Animalitos\SelvaPlus`; un archivo por cada juego | Evita una estructura inconsistente (solo Selva) y mantiene el fix acotado. |
| D2 | La carpeta/archivo por juego queda como refactor separado | Migrar los 21 juegos en este cambio | Requiere tocar seeders, tests y bindings; no es necesario para #63. |
| D3 | Capacidad nueva `JuegoMultiSorteoInterface` implementada por `Animalitos` | Cambiar la firma de `evaluarAcierto` | No rompe `Tripletas`/`Terminales` ni los call sites de un solo resultado. |
| D4 | Tripleta todo o nada, 3 aciertos en 3 sorteos distintos | Pago parcial 1-2 aciertos | Reglamentos Cazalotón Art. 24 y Chaima QUINTO; el cliente confirmó todo o nada. |
| D5 | Evidencia en `apuestas.resultado_ids` (JSON) + `resultado_id` = último ganador | Tabla pivote `apuesta_resultados` | Menor cambio, suficiente para auditar y pagar; la tabla pivote queda como evolución. |
| D6 | La tripleta se vende como jugada de día: `sorteo_hora` = hora de venta | Mantener la hora del sorteo elegido | El reglamento permite acertar en cualquier sorteo posterior del día. |
| D7 | El último resultado del día marca `perdida` las tripletas pendientes | Esperar 24 h y marcar `vencido` | La apuesta ya fue evaluada contra el día; `vencido` queda para resultados faltantes. |
| D8 | `premio_posible` se elimina (método, columnas, tests) | Mantenerlo y arreglar comodines | No se imprime, no lo consume ningún front y no interviene en el pago. |
| D9 | El formato plano solo se documenta | Guarda defensiva en `mapFlatResult` | El usuario pidió no tocarlo; si cambia el feed, se aplica en su momento. |

## Flujo de Selva (plugin compartido)

```
ScrapeResultsJob/SelvaPlusScraper
  → Resultado {comodin:"A", comodin_nombre:"Leoncito"}
  → ApuestaService::verificarGanadores
      → JuegoPluginManager::calcularPremio
          → PremiosEngine::calcular
              → Animalitos::evaluarAcierto
                  → letra A/B ⇒ evaluarComodinLetra()
                      → apuesta comodin-a / Leoncito / label ⇒ coincide=true, clave=base, meta.comodines=[comodin-a]
                      → apuesta perro                          ⇒ coincide=false
              → multiplicadorConComodines → 160× / 200×
```

`Animalitos::evaluarAcierto`:

- Si el resultado trae `comodin` A/B, compara la apuesta contra `comodin-a`/`comodin-b`, la letra, el
  `comodin_nombre` y el label oficial; nunca contra `nombre_animal`.
- Si no, conserva la comparación por nombre y el resto de señales de comodín.
- `comodinesDelResultado` ya emite `comodin-a`/`comodin-b`; el engine aplica el multiplicador de
  `config.premios`.

## Flujo de tripleta multi-sorteo

```
Venta (TicketController → ApuestaService::createApuesta)
  combinacion = {modalidad:"tripleta", selecciones:[{animal}×3]}
  - 3 figuras distintas
  - modalidad "tripleta" existe en config.premios.modalidades
  - al menos 3 sorteos futuros del día
  - sorteo_hora = ahora (jugada de día)

Settlement (por cada Resultado nuevo del juego/día)
  verificarGanadores(resultado)
    ├─ apuestas single-result (misma hora) → camino actual
    └─ apuestas multi-sorteo (tripleta)
         cargar resultados del juego/día con hora >= venta
         evaluarMultiSorteo(selecciones, resultados)
            match 1:1 selección→sorteo, sin reutilizar la misma hora
         si 3 matches:
            premio = amount × modalidades.tripleta (snapshot)
            estado = ganadora
            resultado_id = último resultado
            resultado_ids = [r1, r2, r3]
            detalle.premio_ganado = premio
         si no y es la última extracción del día:
            estado = perdida
            resultado_id = último resultado
         si no:
            sigue pendiente
```

### Algoritmo de matching

1. Normalizar las 3 selecciones con `Texto::normalizar` y verificar que sean 3 distintas.
2. Para cada resultado elegible (mismo juego, misma fecha, hora ≥ venta), obtener `nombre_animal`.
3. Backtracking simple sobre 3 selecciones:
   - Para cada selección no asignada, buscar un resultado no usado cuyo `nombre_animal` normalizado
     coincida.
   - La identidad del sorteo es `hora_sorteo` (o el ID si no hay hora); un mismo sorteo no se usa dos
     veces.
4. Si las 3 selecciones quedan asignadas a sorteos distintos, devolver
   `{coincide:true, clave:"tripleta", meta:{resultado_ids:[...]}}`.
5. Si no, `{coincide:false, clave:"tripleta", meta:{}}`.
6. El mismo sorteo no puede usarse dos veces; dos figuras iguales ya se rechazaron en la venta.

### Detección de la última extracción

- `JuegoHorario` del juego, `max(hora)` activo, o el máximo `hora_sorteo` de los resultados del día si
  no hay horarios.
- Normalizar `hora_sorteo` a `H:i:s` (algunos feeds usan `hh:mm AM/PM`).
- Cuando el resultado procesado coincide con ese máximo, se cierra el día.

### Venta de día

- El front no expande la tripleta por horarios; agrega una sola línea.
- `ApuestaService` detecta la tripleta y:
  - valida 3 figuras distintas;
  - valida la modalidad en `config.premios.modalidades`;
  - cuenta al menos 3 sorteos futuros (`JuegoHorario` > ahora); si no, rechaza;
  - fija `sorteo_hora = now()` y omite la comprobación de “sorteo pasado”.
- El ticket imprime “Tripleta” y las 3 figuras; no imprime hora de sorteo.

## Cambios de datos

### Migración 1 — `apuestas.resultado_ids`

```php
Schema::table('apuestas', function (Blueprint $table) {
    $table->json('resultado_ids')->nullable()->after('resultado_id');
});
```

`Apuesta`: `resultado_ids` en `$fillable` y `$casts` (`array`).

### Migración 2 — drop `premio_posible`

```php
Schema::table('detalle_apuestas', function (Blueprint $table) {
    $table->dropColumn(['premio_posible', 'premio_posible_usd']);
});
```

`DetalleApuesta` elimina los dos campos de `$fillable` y `$casts`.

## Contrato de plugins

```php
namespace App\Plugins\Contracts;

interface JuegoMultiSorteoInterface
{
    /**
     * @param  array<string, mixed>  $apuesta
     * @param  array<int, array<string, mixed>>  $resultados
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    public function evaluarMultiSorteo(array $apuesta, array $resultados): array;
}
```

`Animalitos implements JuegoMultiSorteoInterface`.

`JuegoPluginManager`: `soportaMultiSorteo`, `evaluarMultiSorteo`, `calcularPremioMultiSorteo`.

`PremiosEngine`: `calcularMultiSorteo` (usa `multiplicadorPara` por `clave`, sin comodines).

## Pago

`PagoController::calcularPremio`:

1. Detectar `combinacion.modalidad === 'tripleta'` (o 3 selecciones).
2. Cargar los resultados de `resultado_ids` del mismo juego, ordenados por `hora_sorteo`.
3. `JuegoPluginManager::evaluarMultiSorteo`.
4. Si `coincide`, `PremiosEngine::calcularMultiSorteo` con el snapshot del detalle.
5. Si no, ceros; el pago se rechaza.

## Ganadores

`TicketController::ganadores` conserva el recorrido por resultado para single-result. Al final agrega
una pasada de apuestas multi-sorteo `ganadora` del día y usa `detalle.premio_ganado` (ya persistido)
para no recalcular ni duplicar.

## Work units (PRs encadenados)

| PR | Alcance | Archivos principales |
|----|---------|----------------------|
| PR 1 | Eliminar `premio_posible` | `PremiosEngine`, `JuegoInterface`, `Animalitos`, `Tripletas`, `Terminales`, `ApuestaService`, `DetalleApuesta`, migración drop, tests/docs |
| PR 2 | Comodín Selva en `Animalitos` | `Animalitos`, `AnimalitosPluginTest`, `SelvaPlusResultsTest`, seeder, front comodín |
| PR 3 | Tripleta backend | `JuegoMultiSorteoInterface`, `Animalitos::evaluarMultiSorteo`, `JuegoPluginManager`, `PremiosEngine`, `ApuestaService`, `PagoController`, `TicketController`, migración `resultado_ids`, `Apuesta`, tests |
| PR 4 | Tripleta front + docs | `dashboard.astro`, `modalidades.ts`, `ticket.ts`, docs, `pendientes-front` (formato plano) |

Cada PR queda por debajo de 800 líneas cambiadas. El PR 1 y el PR 2 son independientes; el PR 3 depende
del 1 y del 2; el PR 4 depende del 3.

## Formato plano — solo seguimiento

Se documenta en `docs/dev/pendientes-front.md` (BE-07) que `AnimalitosScraper::mapFlatResult` guarda
solo `numero` para juegos no-tripletas, sin `nombre_animal`. Hoy solo lo usan Trío/Terminal. No se
agrega guarda defensiva. Si un animalito migra a ese feed, se aplicará en su momento.
