# Diseño: Motor de Premios (`motor-premios`)

> Fase: **sdd-design** · Rama: `feat/motor-premios` · Base: `origin/main` (d250c20) · Fecha: 2026-09-17
> Artefacto: OpenSpec + Engram (`sdd/motor-premios/design`)
> Autoridad de valores: `openspec/changes/motor-premios/specs/motor-premios/spec.md` (REQ1–REQ16 + tabla
> oficial). Este diseño **implementa** esos valores, no los reinterpreta.
> Alcance: solo backend. `taquilla/` y `panel/` no se tocan; todo cambio de contrato es **aditivo**.

## 1. Enfoque técnico

Motor **config-driven** en un servicio dedicado (`PremiosEngine`) que resuelve el multiplicador desde
`config.premios` (`{base, modalidades, comodines}`) y centraliza normalización de texto, detección de
comodines y redondeo. Los plugins dejan de calcular dinero: exponen **la forma del acierto** (qué apostó
vs qué salió) como clave canónica. `JuegoPluginManager` se mantiene como fachada con la **misma firma**
`calcularPremio(Juego, …)`, por lo que los 6 call sites productivos (`PagoController`, `TicketController`,
`ApuestaService` ×2, seeders demo) no cambian de llamada. Los valores se entregan a la BD existente con
**migraciones de datos idempotentes** alimentadas por un catálogo único en código (`PremiosOficiales`);
los 21 seeders quedan alineados para instalaciones nuevas.

## 2. Decisiones de arquitectura

### D1. Dónde se resuelve el premio (evalúa las 3 opciones del encargo)

| Opción | Tradeoff | Veredicto |
|---|---|---|
| A. Pasar reglas al plugin (`calcularPremio(..., $reglas)`) | Mínima indirección, pero duplica en 3 plugins el redondeo, los comodines y la lectura de config → 3 lugares donde el dinero puede divergir | Rechazada |
| B. Resolver todo en `JuegoPluginManager` | Un solo punto de dinero, pero el manager tendría que interpretar la forma de cada juego (`nombre_animal` vs `triple_a`) → SRP roto y plugins reducidos a validadores | Rechazada |
| C. **Servicio dedicado `PremiosEngine` + plugins adaptadores** | Una sola fuente de verdad del dinero; los plugins conservan el conocimiento de forma; el manager conserva firma → cero cambios en call sites | **Elegida** |

**Choice**: `PremiosEngine` concentra `config.premios`, comodines y redondeo; los plugins implementan
`evaluarAcierto()` (clave canónica) y `modalidadDe()` (para `premio_posible`); el manager delega.
**Alternatives considered**: tabla de reglas en BD (más migración y sin fuente autoritativa en datos);
plugin por juego (21 plugins, imposible de mantener).
**Rationale**: REQ1 exige que ningún plugin hardcodee el multiplicador y REQ8 exige un único redondeo;
la separación "forma del acierto (plugin) / cuánto paga (config)" es la que hace ambas verificables.

### D2. `config.premios` como fuente de verdad, con espejos legacy

**Choice**: `config.premios` es la fuente que lee el motor. `config.premio_multiplo`, `config.modalidades`
y `config.comodines` se conservan como **espejos legacy** (los consumen el export `docs/juegos.json` y los
tests existentes). Los tres se escriben desde un único catálogo en código (`PremiosOficiales`), con un test
que verifica `premio_multiplo === premios.base`.
**Alternatives considered**: reemplazar los campos legacy (rompe el contrato del front y ~10 tests sin
beneficio); guardar `premios` en una tabla nueva (capa innecesaria para 21 filas de JSON).
**Rationale**: la compatibilidad pedida por el encargo se logra con espejos derivados de una sola fuente,
no con dos verdades paralelas mantenidas a mano.

### D3. Normalización de texto y signos

**Choice**: helper compartido `App\Support\Texto::normalizar()` = `Str::ascii(trim($v))` + `mb_strtolower`
(acentos e insensibilidad a mayúsculas), usado en validación **y** liquidación de los 3 plugins. Los signos
se normalizan con un mapa label→sigla dentro de `Tripletas` (`Géminis`/`GEM`, `Escorpio`/`ESC`, …).
**Alternatives considered**: normalizar solo en `calcularPremio` (deja la validación rechazando el label,
N2); `transliterator_transliterate` (extensión no garantizada en prod).
**Rationale**: hoy el feed entrega "Delfin"/"Caiman" y la taquilla envía "Delfín"/"Caimán"; el fix debe
cubrir el mismo universo en validación y pago (H13, N2, N10).

### D4. Entrega de valores a la BD existente

**Choice**: una migración de datos (`backfill_premios_config_juegos`) aplica `premios` + espejos +
`active=false` de `la-ricachona` sobre los 21 juegos, leyendo `PremiosOficiales`; los seeders usan el mismo
catálogo con `updateOrCreate`. Idempotente y reversible (`down` retira `premios` y reactiva ricachona).
**Alternatives considered**: confiar en re-ejecutar seeders en producción (los `firstOrCreate` no
actualizan; varios seeders no están en `DatabaseSeeder` de forma garantizada).
**Rationale**: las migraciones son el mecanismo determinista y auditable de cambio de datos en este repo
(precedente: `2026_09_15_120000_normalize_hora_sorteo_to_24h`).

### D5. Estados de apuesta (REQ13)

**Choice**: agregar `ganadora` al ENUM de `apuestas` (migración). Transiciones:
`pendiente → ganadora` (premio > 0, con `resultado_id`) `→ pagada` (pago manual);
`pendiente → perdida` (premio 0); `pendiente → vencido` (resultado nunca llega, job nuevo).
`PagoController` acepta `ganadora` y también `pendiente` con `resultado_id` (compatibilidad con premios
ya liquidados antes del cambio). La cascada de ticket suma `vencido` a "resuelta" y **no** considera
resuelta a `ganadora` (impaga).
**Alternatives considered**: dejar ganadoras en `pendiente` (es el bug N5 y provoca re-liquidación);
marcar `perdida` por ausencia de resultado (semánticamente falso; `vencido` ya existe en el ENUM).
**Rationale**: separar "ganó y no cobró" de "pendiente de sorteo" es lo que hace idempotente la
liquidación y correcta la caja.

### D6. Deduplicación pre-H22 (REQ14)

**Choice**: (a) migración de reconciliación que agrupa por `(juego_id, DATE(fecha_sorteo), hora normalizada)`
y conserva la fila con más claves en `numeros_ganadores` (desempate: `updated_at` más reciente, luego `id`
mayor), fusionando claves faltantes; (b) guard defensivo en `ScrapeResultsJob`: antes de iterar
`verificarGanadores`, deduplica los resultados del día por la misma clave; (c) `BaseScraper::saveResults`
normaliza la hora y usa `updateOrCreate` (el índice único `resultados_juego_fecha_hora_unique`, aún
vigente, lo respalda).
**Alternatives considered**: confiar solo en el índice único (no limpia filas pre-H22 ya persistidas);
`DELETE` ciego del duplicado (pierde datos si el descartado tiene `premios_detalle`).
**Rationale**: REQ14 pide que la apuesta se evalúe **una sola vez**; se garantiza en la liquidación
(guard) y en los datos (migración), sin depender del orden de llegada.

### D7. Redondeo (REQ8)

**Choice**: único punto de redondeo en `PremiosEngine`: `round($monto * $multiplicador, 2)` (half-up para
positivos). Se persiste en columnas `decimal(12,2)` existentes.
**Rationale**: un solo punto elimina la clase de bugs "cada plugin redondea distinto"; el pago compara
contra ese mismo valor (±0.01, tolerancia ya existente en `PagoController`).

### D8. Fase 2: modelo multi-combinación retrocompatible (REQ11)

**Choice**: `combinacion` JSON evoluciona por discriminante: si existe `lineas[]`, es multi-combinación;
si no, el shape plano actual sigue siendo válido. Se agrega `modalidad` opcional (clave canónica); si falta,
el plugin la deriva con `modalidadDe()`. No hay migración de tablas.
**Rationale**: `combinacion` ya es JSON flexible; un discriminante aditivo no rompe apuestas existentes ni
los endpoints que lo devuelven tal cual.

### D9. `premio_posible` (REQ12)

**Choice**: se calcula **al crear la apuesta** con `PremiosEngine::premioPosible()` usando la modalidad
derivada de `combinacion` (`modalidad × monto`, ambas monedas). `ApuestaService::createApuesta` deja de
llamar `calcularPremio` con resultados vacíos.
**Rationale**: es el único momento en que el sistema conoce monto y modalidad sin ambigüedad; recalcular
"al conocerse el resultado" no aporta (la liquidación ya escribe `premio_ganado`).

### D10. Contrato del catálogo (`docs/juegos.json`)

**Choice**: el export agrega `premios` (`{base, modalidades, comodines}`), `active` y `vendible`
(=`active && requires_scraper` no relevante, `vendible = active`), manteniendo los campos legacy. La
deshabilitación de `la-ricachona` se refleja como `active=false`.
**Rationale**: la spec delta de `catalogo-juegos` pide `premios` y el marcado de no vendible; mantener lo
legacy conserva a la taquilla actual (que igual hardcodea su catálogo, N8 fuera de alcance).

## 3. Contrato `config.premios`

```json
"premios": {
  "base": 600,
  "modalidades": { "<clave-canónica>": 60, "...": 0 },
  "comodines": {
    "<clave>": {
      "tipo": "flag | letra | numero | palabra",
      "premio_multiplo": 40,
      "valor": "A",              // solo tipo letra
      "numero": 99,              // solo tipo numero
      "acumulativo": true,       // solo tipo palabra (+20)
      "nombre": "MEGA"           // descriptivo / espejo legacy
    }
  }
}
```

### 3.1 Vocabulario canónico de modalidades

| Clave canónica | Significado | Nombres del reglamento |
|---|---|---|
| `base` | acierto simple (animalito, triple seco, terminal 2 cifras) | Base |
| `dupleta` / `tripleta` | 2 animalitos 2 sorteos / 3 animalitos | Dupleta / Tripleta |
| `terminal` / `punta` / `uña` | 2 últimas / 2 primeras / última cifra del triple | Terminal, Cola / Punta / Uña |
| `signo_triple` | triple + signo | Zodiacal, Astro, Triple+Signo |
| `signo_terminal` | terminal + signo | Terminal+Zodiacal, Terminal+Signo, Cola+Signo |
| `signo_uña` | uña + signo | Uña+Signo |
| `signo_solo` | signo sin número | Signo 6× |
| `triple_a_b` / `solo_a_b` | ambos triples / uno de los dos | A+B / solo A o B |
| `cruzado` / `cruzado_10` | cruzado Chance (dos tiers) | Cruzado 3.000×/10× |
| `arrimao` / `pegadito` | número exacto 4 / 5 cifras | El Arrimao / El Pegadito |
| `aproximacion` | terminal ±1 | Aproximación |

Los **espejos legacy** (`config.modalidades`) conservan sus claves históricas (`cola`, `zodiacal`,
`triple_c_signo`, `uña`, …) para no romper el export ni los tests; `premios.modalidades` habla el
vocabulario canónico. El seeder/test que los escriba garantiza que ambos representen el mismo valor.

### 3.2 Valores oficiales → claves canónicas (autoritativos: spec)

| Juego | `base` | `premios.modalidades` | `premios.comodines` | Cambio vs hoy |
|---|---|---|---|---|
| lotto-activo, lotto-activo-rd, lotto-activo-rep-dom | 30 | `dupleta:1000` | — | +dupleta (familia, según spec) |
| terminal-activo | 60 | — | — | fix N1 |
| monje-millonario | **50** | — | `patronus-75` (numero 75, 120) · `patronus-palabra` (palabra, +20 acumulativo) | 30→50, +comodines y captura de `patronus` |
| trio-activo | 600 | `terminal:60, punta:60` | — | — |
| triple-zulia | 600 | `terminal:60, signo_triple:6000, signo_terminal:600` | — | +payload ya existía |
| triple-caliente | 600 | `terminal:60, signo_triple:6000, signo_terminal:600` | — | — |
| triple-chance | 600 | `triple_a_b:200000, solo_a_b:150, punta:60, terminal:60, cruzado:3000, cruzado_10:10, signo_triple:6000, signo_terminal:600, signo_solo:6` | — | 100→150, 5.000→6.000 (H23 reglamento) |
| triple-tachira | 500 | `terminal:50, signo_triple:5000` | — | — |
| triple-facil | 700 | `terminal:60, aproximacion:10` | — | — |
| triple-zamorano | 600 | `terminal:60, uña:5, signo_triple:6000, signo_terminal:600, signo_uña:60` | — | — |
| el-arrejuntado | **40** | `triple_a:600, triple_b:600, signo_triple:6000, arrimao:6000, pegadito:60000` | — | 30→40 + modalidades |
| cazaloton | 30 | `dupleta:800, tripleta:200` | — | — |
| loto-chaima | **40** | `tripleta:50` | — | 30→40 + tripleta |
| el-guacharito | 70 | — | `guacharito-99` (numero 99, 150) | +tipo |
| guacharo-activo | 60 | — | `guacharo-75` (numero 75, 120) | +tipo |
| mega-animal-40 | 30 | — | `mega` (flag, 40) | +tipo |
| selva-plus | 80 | — | `comodin-a` (letra A, 160) · `comodin-b` (letra B, 200) | +tipo/valor |
| la-granjita | 30 | — | — | — |
| la-ricachona | — | — | — | `active=false`, sin `premios` |

### 3.3 Interfaces

```php
// App\Plugins\Contracts\JuegoInterface (extendida)
public function validarApuesta(array $data, ?array $opciones = null): bool;
public function evaluarAcierto(array $apuesta, array $resultados): array
    // → ['coincide' => bool, 'clave' => 'base'|'terminal'|'signo_triple'|…, 'meta' => [...]]
public function modalidadDe(array $combinacion): string; // clave canónica estática (premio_posible)

// App\Services\PremiosEngine (nuevo)
public function calcular(Juego $juego, array $apuesta, array $resultados): array; // ['premio_bs','premio_usd']
public function premioPosible(Juego $juego, array $combinacion, float $mBs, float $mUsd): array;
public function reglas(Juego $juego): array; // {base, modalidades, comodines} para /juegos/{id}/reglas
```

`JuegoPluginManager::calcularPremio(Juego, …)` y `getMultiplicador(Juego)` delegan en el engine;
`validarApuesta(Juego, data)` carga `juego_opciones` (fallback `plugin->obtenerOpciones()`) y las pasa al
plugin. `PremiosEngine::calcular`: (1) juego inactivo o sin plugin → 0; (2) `evaluarAcierto` no coincide →
0; (3) multiplicador = `modalidades[clave] ?? base` (fallback transicional `premio_multiplo` solo para
`base`); (4) overlay de comodines (los `flag`/`letra`/`numero` **reemplazan** el multiplicador vigente, el
mayor gana; `palabra` **suma** +20); (5) `round(monto × mult, 2)`.

### 3.4 `combinacion` v2 (Fase 2, retrocompatible)

```json
{
  "modalidad": "dupleta",
  "lineas": [
    {"tipo": "animal", "animal": "Zorro", "sorteo_hora": "2026-09-17 10:00:00"},
    {"tipo": "animal", "animal": "Tigre", "sorteo_hora": "2026-09-17 11:00:00"}
  ]
}
```

Sin `lineas`, el shape actual (`{animal}`, `{tipo, numero, signo}`, `{numero}`) sigue vigente. Reglas:
multi-línea paga solo si **todas** las líneas coinciden (Dupleta respeta el orden de sorteos; Chance A+B
tiene los tiers `triple_a_b`/`solo_a_b`); `apuestas.sorteo_hora` guarda el primer sorteo (compat) y las
líneas llevan su propio `sorteo_hora`; la liquidación multi-sorteo se dispara cuando **todos** los
resultados referenciados existen (paso nuevo en `ScrapeResultsJob`, agrupado por juego+fecha). Contratos
API afectados: `POST /apuestas` y `POST /tickets` (`lines[].combinacion` acepta el shape aditivo),
`GET /apuestas/{id}` y `GET /tickets/ganadores` (devuelven el JSON tal cual). Queda para el front: enviar
`modalidad`+`lineas` y renderizar multi-línea.

## 4. Flujo de datos

```
Scraper ──saveResults (upsert, hora normalizada)──→ resultados
                                   │
ScrapeResultsJob ── dedupe del día (juego+fecha+hora) ──→ verificarGanadores(resultado)
                                   │
                ApuestaService ──→ JuegoPluginManager::calcularPremio(Juego,…)
                                   │            │
                    evaluarAcierto(plugin)   ← forma del acierto (normalizada)
                                   │
                          PremiosEngine ── config.premios (base/modalidades/comodines)
                                   │            └── round(x,2)
                     premio_bs/usd ──→ detalle_apuestas + estado (ganadora|perdida) + ticket
                                   │
                       PagoController (valida contra el engine) → pagada
```

## 5. Dónde vive cada fix (bugs y gaps del alcance)

| Ref | Fix | Archivo(s) |
|---|---|---|
| H13/N10 | `Texto::normalizar()` en validación y `evaluarAcierto` (animalitos, mega incluido) | `App\Support\Texto`, `Plugins/Juegos/Animalitos.php` |
| N1 | `evaluarAcierto` lee `numeros_ganadores.numero` (fallback `terminal`) y compara con padding; base 60 desde config | `Plugins/Juegos/Terminales.php`, `PremiosEngine` |
| N2 | Aceptar label o sigla; regla `combinacion.signo` pasa de `max:3` a `max:20`; normalización en plugin | `Plugins/Juegos/Tripletas.php` (`signosLabels`, `getValidationRules`) |
| N3 | Comparar solo contra `numeros_ganadores[<tipo apostado>]` con `str_pad` a 3 cifras | `Plugins/Juegos/Tripletas.php` |
| N7 | `whereTime('sorteo_hora', hora)` + `whereIn(estado)` + engine | `Http/Controllers/Api/TicketController.php::ganadores` |
| N11 | Validar el monto contra el engine (no plugin directo); aceptar `ganadora` y `pendiente` legacy | `Http/Controllers/Api/PagoController.php` |
| N12 | Pasar opciones reales del juego | `Services/JuegoPluginManager.php::validarApuesta` |
| N4 | `premio_posible` con `premioPosible()` (sin resultados vacíos) | `Services/ApuestaService.php::createApuesta`, `DetalleApuesta` |
| N5 | Estados + job de vencimiento | migración ENUM, `Jobs/MarcarApuestasVencidasJob.php`, `routes/console.php` |
| N6 | Dedupe migración + guard en job | migración `dedupe_resultados…`, `Jobs/ScrapeResultsJob.php` |
| N9 | `str_pad` a 3/2 cifras en comparaciones de triples y terminales | `Tripletas`, `Terminales` |
| H1/H8b/N13 | Comodines `flag`/`letra`/`numero` desde `config.premios.comodines` | `PremiosEngine` |
| H14/PATRONUS | Persistir `patronus` (`special_result==1`) en el mapper y liquidar `patronus-75`/`patronus-palabra` | `Plugins/Scrapers/AnimalitosScraper.php`, `PremiosEngine` |
| H3 | Multiplicadores desde config | `PremiosEngine` + `PremiosOficiales` + migración |
| REQ7 | Guard explícito de venta/liquidación para juego inactivo (hoy `createApuesta` no lo verifica) | `ApuestaService::createApuesta`, `PremiosEngine` |

**Impacto en tests existentes**: `AnimalitosPluginTest` (hoy invoca el plugin directo) pasa a través del
manager/engine; `JuegosJsonTest` (agrega `premios`/`active` y regenera `docs/juegos.json`);
`MegaAnimal40ResultsTest` (igualdad exacta de `comodines` ahora incluye `tipo`);
`TripleChanceResultsTest` (valores del reglamento 150/6.000); `LotoChaimaResultsTest` (40),
`ElArrejuntadoResultsTest` (40), `LaRicachonaResultsTest` (`active=false`, plugin inactivo);
`AnimalitosScraperTest` no rompe (el mapper solo **agrega** `patronus`). El resto de los `*ResultsTest`
asserta campos legacy que se conservan.

## 6. Cambios por archivo

| Archivo | Acción | Descripción |
|---|---|---|
| `backend/app/Support/Texto.php` | Crear | Normalización de acentos/case compartida |
| `backend/app/Support/PremiosOficiales.php` | Crear | Catálogo único de los 21 juegos (base/modalidades/comodines) desde la tabla del spec |
| `backend/app/Services/PremiosEngine.php` | Crear | Reglas, comodines, redondeo, `premio_posible` |
| `backend/app/Jobs/MarcarApuestasVencidasJob.php` | Crear | `pendiente` sin resultado tras gracia (48 h) → `vencido` |
| `backend/app/Plugins/Contracts/JuegoInterface.php` | Modificar | `evaluarAcierto`, `modalidadDe`, firma de `validarApuesta` |
| `backend/app/Plugins/Juegos/{Animalitos,Tripletas,Terminales}.php` | Modificar | Acentos, tipo estricto, signo label/sigla, terminal `numero`, claves canónicas |
| `backend/app/Services/JuegoPluginManager.php` | Modificar | Fachada al engine, opciones en `validarApuesta`, `getMultiplicador` desde config |
| `backend/app/Services/ApuestaService.php` | Modificar | Guard de juego inactivo, `premio_posible` real, estados `ganadora`, dedupe/`whereNull(resultado_id)`, acumular `premio_total` |
| `backend/app/Http/Controllers/Api/PagoController.php` | Modificar | Validar contra engine; aceptar `ganadora`/`pendiente` legacy |
| `backend/app/Http/Controllers/Api/TicketController.php` | Modificar | `ganadores`: filtro de hora, estado y motor |
| `backend/app/Http/Controllers/Api/JuegoController.php` | Modificar | `reglas` incluye `premios` (informativo, aditivo) |
| `backend/app/Plugins/Scrapers/AnimalitosScraper.php` | Modificar | Persistir `patronus` (Monje) |
| `backend/app/Plugins/Scrapers/BaseScraper.php` | Modificar | `saveResults` normaliza hora y usa upsert |
| `backend/app/Jobs/ScrapeResultsJob.php` | Modificar | Dedupe defensivo antes de liquidar; paso multi-sorteo (Fase 2) |
| `backend/app/Services/JuegoCatalogoService.php` | Modificar | Export `premios`, `active`, `vendible` (aditivo) |
| `backend/routes/console.php` | Modificar | Agendar `MarcarApuestasVencidasJob` |
| `backend/database/seeders/*` (21) | Modificar | `premios` + espejos desde `PremiosOficiales`; `la-ricachona` inactiva |
| `docs/juegos.json` | Modificar | Regenerado por `php artisan juegos:export` |
| `backend/database/migrations/*` (3) | Crear | Ver §7 |
| `backend/tests/**` | Crear/Modificar | Ver §8 |

## 7. Migraciones, idempotencia y reversibilidad

| Migración | `up` | `down` |
|---|---|---|
| `2026_09_17_000001_add_ganadora_to_apuestas_estado` | `ALTER … ENUM(…,'ganadora','vencido')` | `UPDATE … SET estado='pendiente' WHERE estado='ganadora'` y restaura ENUM previo (evita el truncado a `''`) |
| `2026_09_17_000002_backfill_premios_config_juegos` | Merge de `premios` + espejos desde `PremiosOficiales`; `la-ricachona active=false` + plugin inactivo | Retira `config.premios` y reactiva ricachona/plugin |
| `2026_09_17_000003_dedupe_resultados_sorteo_duplicado` | Normaliza hora pendiente y fusiona/borra duplicados por `(juego_id, DATE(fecha_sorteo), hora)` conservando la fila más completa | No-op documentado (irreversible: no se reconstruye el duplicado) |

Las tres son **idempotentes** (re-ejecutables sin efecto) y se implementan en PHP (no SQL JSON) para
portabilidad con `lotto_test`. El orden importa: la normalización de hora precede al dedupe. El catálogo
`PremiosOficiales` es la única fuente que alimenta seeders, migración y tests.

## 8. Estrategia de tests

| Capa | Qué se prueba | Enfoque |
|---|---|---|
| Unit | `Texto` (acentos, case), `PremiosEngine` (base, modalidad, comodines flag/letra/numero/palabra, override vs suma, redondeo 1.23456→1.23, inactivo, `premioPosible`) | Datos en memoria, sin BD |
| Unit plugins | `evaluarAcierto`/`modalidadDe` por plugin: acentos, tipo estricto, signo label/sigla, terminal `numero` con padding | `PremiosOficiales` como config de entrada |
| Feature | Regresión por juego (tabla abajo), `verificarGanadores` (estados), `PagoController` (ganadora/legacy), `TicketController::ganadores` (hora), dedupe (dos filas → una evaluación), job de vencimiento, `JuegosJsonTest` | `RefreshDatabase` + seeders |
| Contrato | `docs/juegos.json` == export con `premios`/`active` | Test existente extendido |

**Tabla de regresión por juego (mínimo un caso por juego + comodines):**

| Juego | Caso | Esperado |
|---|---|---|
| lotto-activo | "Delfín" vs "Delfin" | 30× |
| lotto-activo-rd / rep-dom | base | 30× |
| terminal-activo | `numero=37` | 60× |
| monje-millonario | figura 42 / 42+palabra / 75 | 50× / 70× / 120× |
| trio-activo | triple_a | 600× |
| triple-zulia | acierto en `triple_b` con apuesta `triple_a` | 0 (REQ5) |
| triple-caliente | triple_c + signo label | 6.000× |
| triple-chance | C+Signo / solo A/B | 6.000× / 150× |
| triple-tachira | base | 500× |
| triple-facil | base | 700× |
| triple-zamorano | base | 600× |
| el-arrejuntado | animalito | 40× |
| cazaloton | base | 30× |
| loto-chaima | base | 40× |
| el-guacharito | figura 99 / otra | 150× / 70× |
| guacharo-activo | figura 75 / otra | 120× / 60× |
| mega-animal-40 | `comodin=true` / false | 40× / 30× |
| selva-plus | comodín A / B / normal | 160× / 200× / 80× |
| la-granjita | base | 30× |
| la-ricachona | intento de venta/liquidación | rechazado/no liquida |

**Comandos** (desde `backend/`, MySQL `lotto_test`):

```bash
php artisan test --filter='PremiosEngineTest|TextoTest|AnimalitosPluginTest|TerminalesPluginTest|TripletasPluginTest'
php artisan test --filter='MotorPremiosRegresionTest|JuegosJsonTest|ScrapeResultsJobTest'
php artisan test                                   # suite completa (CI)
php artisan juegos:export && git diff --exit-code docs/juegos.json   # contrato regenerado
```

## 9. Threat Matrix

**N/A** — este cambio no introduce routing, shell, subprocesos, automatización VCS/PR, clasificación de
ejecutables ni integración de procesos nuevos. El único proceso afectado es el scheduler interno de
Laravel, ya existente (se agrega un job diario idempotente).

## 10. Riesgos y mitigaciones

| Riesgo | Prob. | Mitigación |
|---|---|---|
| Valores mal migrados → pagar de más/menos | Media | `PremiosOficiales` único + test que compara contra la tabla del spec + migración revisable |
| Ruptura de contrato del front (`panel`/`taquilla`) | Baja | Campos aditivos; `premio_multiplo`/`modalidades`/`comodines` intactos; `ganadora` es un valor más del mismo campo |
| Doble liquidación / sobreescritura | Baja | `whereNull(resultado_id)` + transición de estado + dedupe en job y datos |
| `premio_total_*` del ticket se sobreescribe entre sorteos | Media | Acumular con `increment` por jugada ganadora (test multi-sorteo) |
| Semántica de `special_result` sin confirmar | Media | Se mapea como `patronus` (mismo criterio que el front oficial `id_game==7`); pregunta abierta |
| Migración de dedupe sobre datos sucios | Baja | Idempotente, fusiona claves y conserva `premios_detalle`; `down` documentado |
| `la-ricachona` deja tickets/ventas previas | Baja | `active=false` sin borrar; apuestas previas no se reliquidan (decisión de propuesta) |

## 11. Preguntas abiertas

- [ ] ¿Patronus figura 75 **con** palabra paga 120× o 120+20=140×? (se implementa la suma acumulativa por regla general; confirmar)
- [ ] ¿La Dupleta 1.000× aplica a toda la familia Lotto Activo o solo a `lotto-activo`? (spec autoritativa dice familia; docs solo la listan en `lotto-activo`)
- [ ] ¿La palabra PATRONUS aplica solo sobre figura normal (reglamento) o también sobre la figura 75?
- [ ] Ventana de vencimiento por ausencia de resultado: ¿48 h fijas o configurable por banca/grupo?
- [ ] ¿El front (taquilla/panel) agregará `ganadora` a sus filtros y consumirá `premios`/`active`? (fuera de alcance, contrato listo)

## 12. Mapa REQ → diseño

| REQ | Dónde se resuelve |
|---|---|
| REQ1 premios config-driven | D1, D2, §3, `PremiosEngine` |
| REQ2 acentos | D3, §5 (H13/N10), `Texto` en plugins |
| REQ3 terminales | §5 (N1), `Terminales::evaluarAcierto`, base 60 config |
| REQ4 signos label/sigla | D3, §5 (N2), `Tripletas` |
| REQ5 tipo estricto | §5 (N3), `Tripletas::evaluarAcierto` |
| REQ6 comodines | §3.2, §5 (H1/H8b/N13/H14), `PremiosEngine` + mapper `patronus` |
| REQ7 juegos sin fuente | D4/§3.2 (`la-ricachona`), guard en `createApuesta`, D10 export |
| REQ8 redondeo | D7, `PremiosEngine` |
| REQ9 ganadores por sorteo | §5 (N7), `TicketController::ganadores` |
| REQ10 pago vs motor corregido | §5 (N11), `PagoController` vía manager |
| REQ11 modalidades complejas | D8, §3.4 |
| REQ12 `premio_posible` | D9, `premioPosible` + `modalidadDe` |
| REQ13 estados | D5, migración ENUM + job + transiciones |
| REQ14 dedupe pre-H22 | D6, migración + guard en `ScrapeResultsJob` |
| REQ15 validación con opciones | §5 (N12), `JuegoPluginManager::validarApuesta` |
| REQ16 tests de regresión | §8 |
