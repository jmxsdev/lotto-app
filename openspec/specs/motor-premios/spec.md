# motor-premios Specification

**Estado**: draft

## Purpose

El motor de premios liquida cada apuesta con el premio oficial del juego leído de `config.premios`
(`{base, modalidades, comodines}`), en lugar de multiplicadores hardcodeados. Cubre premios por juego,
normalización de acentos, terminales, comodines, signos, modalidades complejas, estados de apuesta,
deduplicación pre-H22, redondeo y deshabilitación de juegos sin fuente.

## Valores oficiales (autoritativos)

| Juego | Base | Modalidades | Comodines |
|---|---|---|---|
| Lotto Activo (familia) | 30× | — (Dupleta 1.000× **fuera de alcance**) | — |
| Terminal Trío | 60× | — | — |
| Trío Activo | 600× | Terminal 60× · Punta 60× | — |
| Triple Zulia | 600× | Cola 60× · Zodiacal 6.000× · Terminal+Zodiacal 600× | — |
| Triple Caliente | 600× | Terminal 60× · Signo 6.000× · Terminal+Signo 600× | — |
| Triple Zamorano | 600× | Cola 60× · Uña 5× · Astro 6.000× · Cola+Signo 600× · Uña+Signo 60× | — |
| Triple Táchira | 500× | Terminal/Cola 50× · Zodiacal 5.000× | — |
| Triple Chance | 600× | A+B 200.000× · solo A/B 150× · Punta 60× · Terminal 60× · Cruzado 3.000×/10× · C+Signo 6.000× · Terminal+Signo 600× | — |
| Triple Fácil | 700× | Terminal 60× · Aproximación 10× | — |
| Cazalotón | 30× | Tripleta 200× (Dupleta 800× **fuera de alcance**) | — |
| Monje Millonario | 50× | — | Patronus (75) 120× · palabra PATRONUS +20× acumulativa (normal 70×; **75+palabra = 140×**) |
| El Arrejuntado | 40× | Triple A/B 600× · Triple+Signo 6.000× · Arrimao 6.000× · Pegadito 60.000× | — |
| El Guacharito | 70× | — | Guacharito (99) 150× |
| Guácharo Activo | 60× | — | Guácharo (75) 120× |
| La Granjita | 30× | — | — |
| Loto Chaima | 40× | Tripleta 50× | — |
| Mega Animal 40 | 30× | — | MEGA 40× |
| Selva Plus | 80× | — | Comodín A 160× · B 200× |
| La Ricachona | — | — | `active=false` (sin fuente) |

## Requirements

### Requirement: Premios config-driven por juego

El sistema SHALL liquidar cada apuesta leyendo el premio del esquema `config.premios` del juego
(`base`, `modalidades`, `comodines`). Ningún plugin SHALL hardcodear el multiplicador. Cuando la apuesta
trae un snapshot de premios persistido al vender, el motor SHALL resolver el premio contra ese snapshot
(override de config); en ausencia de snapshot, SHALL usar el `config.premios` actual (fallback legacy).

#### Scenario: Premio base desde config

- GIVEN una apuesta animalito a `monje-millonario` (base 50×) por Bs. 10
- WHEN sale el animalito apostado
- THEN el premio es `10 × 50 = Bs. 500`

#### Scenario: Multiplicador hardcodeado ausente

- GIVEN un plugin que no consulta `config.premios`
- WHEN se liquida una apuesta
- THEN el premio se calcula desde `config.premios` del juego, no desde una constante del plugin

#### Scenario: Liquidación contra snapshot

- GIVEN una apuesta con snapshot (base 50×) y un `config.premios` actual distinto (base 60×)
- WHEN se liquida
- THEN el premio se resuelve con 50× (snapshot), no 60×

#### Scenario: Fallback legacy sin snapshot

- GIVEN una apuesta sin snapshot
- WHEN se liquida
- THEN el premio se resuelve con el `config.premios` actual

### Requirement: Normalización de acentos

El sistema SHALL comparar animales y textos normalizando acentos (`Str::ascii` + minúsculas) en ambos
lados. Una apuesta con acento MUST igualar a un resultado sin acento y viceversa.

#### Scenario: Apuesta acentuada contra resultado sin acento

- GIVEN una apuesta animalito "Delfín" y el resultado "Delfin"
- WHEN se liquida
- THEN la apuesta es ganadora (premio > 0)

### Requirement: Liquidación de terminales

El sistema SHALL liquidar `terminal-activo` comparando contra la clave `numero` que persisten los
scrapers y pagando **60×** (reglamento).

#### Scenario: Terminal paga 60×

- GIVEN una apuesta terminal "37" por Bs. 10 y el resultado con `numero=37`
- WHEN se liquida
- THEN el premio es `10 × 60 = Bs. 600`

### Requirement: Signos por label o sigla

El sistema SHALL aceptar el signo apostado como LABEL ("Escorpio") o SIGLA ("ESC"), normalizando en
validación y cálculo, para desbloquear la modalidad zodiacal.

#### Scenario: Signo enviado como label

- GIVEN una apuesta `triple_c` con signo "Escorpio" (label)
- WHEN se valida y liquida
- THEN la apuesta se acepta y compara contra el signo del resultado

### Requirement: Tripletas paga solo por el tipo apostado

El sistema SHALL liquidar una apuesta de tripletas solo si el número acertado corresponde al tipo
apostado (`triple_a` contra `a`, `triple_b` contra `b`, `triple_c` contra `c`).

#### Scenario: Número en tipo distinto no paga

- GIVEN una apuesta `triple_a` al "452" y el resultado con "452" en `triple_b`
- WHEN se liquida
- THEN la apuesta es perdedora

### Requirement: Liquidación de comodines

El sistema SHALL aplicar el comodín cuando el resultado lo trae: MEGA (`comodin===true` → 40×), Selva
(`comodin` "A" → 160×, "B" → 200×), Guacharito (figura 99 → 150×), Guácharo (figura 75 → 120×), Monje
(Patronus figura 75 → 120×; palabra PATRONUS → +20× **acumulativo sobre el multiplicador vigente**:
figura normal 50+20=70×; **Patronus 75 + palabra = 120+20 = 140×**).

#### Scenario: Comodín MEGA

- GIVEN una apuesta a `mega-animal-40` (base 30×) y el resultado con `comodin=true`
- WHEN sale el animalito apostado
- THEN el premio es 40× (no 30×)

#### Scenario: Palabra PATRONUS acumula

- GIVEN una apuesta a `monje-millonario` figura 42 y el resultado trae la palabra PATRONUS
- WHEN sale la figura 42
- THEN el premio es `10 × 70 = Bs. 700`

#### Scenario: Patronus 75 con palabra acumula 140×

- GIVEN una apuesta a `monje-millonario` a la figura 75 (Patronus) por Bs. 10 y el resultado trae la palabra PATRONUS
- WHEN sale la figura 75
- THEN el premio es `10 × 140 = Bs. 1.400` (120 del Patronus + 20 de la palabra)

### Requirement: Juegos sin fuente deshabilitados

El sistema SHALL mantener `active=false` todo juego sin fuente oficial con multiplicadores; ese juego
MUST NOT liquidarse ni venderse. Hoy aplica a `la-ricachona`.

#### Scenario: Juego deshabilitado no liquida

- GIVEN una apuesta a `la-ricachona` (`active=false`)
- WHEN se intenta liquidar
- THEN no se liquida ni se oferta a la venta

### Requirement: Redondeo a 2 decimales

El sistema SHALL redondear todo premio a máximo 2 decimales en la moneda apostada (Bs./USD según la
tasa aplicada).

#### Scenario: Premio con decimales

- GIVEN un premio calculado en 1.23456
- WHEN se persiste
- THEN el premio se redondea a 2 decimales

### Requirement: Ganadores filtrados por sorteo

`TicketController::ganadores` SHALL filtrar las apuestas ganadoras por `sorteo_hora` (además de
juego/fecha/estado) para no declarar ganador contra un sorteo distinto al apostado.

#### Scenario: Ganador solo del sorteo apostado

- GIVEN una apuesta al sorteo 13:00 y un resultado ganador del sorteo 16:30
- WHEN se consultan los ganadores
- THEN la apuesta NO aparece como ganadora del 16:30

### Requirement: Pago validado contra el motor corregido

`PagoController` SHALL validar el monto contra el motor corregido (config-driven + acentos + terminales
+ comodines), nunca contra un plugin roto. Cuando la apuesta trae snapshot de premios, la validación SHALL
resolverse contra ese snapshot; en ausencia de snapshot, contra el `config.premios` actual (fallback
legacy).

#### Scenario: Pago de animal acentuado

- GIVEN una apuesta ganadora "Delfín" liquidada correctamente
- WHEN el cajero paga el premio del reglamento
- THEN el pago se acepta (coincide con `calcularPremio`)

#### Scenario: Pago contra snapshot

- GIVEN una apuesta ganadora con snapshot (base 50×) y `config.premios` actual distinto
- WHEN el cajero paga
- THEN el monto validado coincide con el snapshot (50×)

#### Scenario: Pago con fallback legacy

- GIVEN una apuesta ganadora sin snapshot
- WHEN el cajero paga
- THEN el monto validado coincide con el `config.premios` actual

### Requirement: Modalidades complejas de un solo sorteo

El sistema SHALL soportar las modalidades complejas de Fase 2 que corresponden a **un solo sorteo** y se
liquidan como apuestas individuales con su propio monto: Tripleta Cazalotón 200× / Loto Chaima 50×,
Cruzado Chance 3.000×/10×, Par Millonario 200.000×, El Arrimao (4 cifras, 6.000×) y El Pegadito (5 cifras,
60.000×) del Arrejuntado, Punta/Terminal/Aproximación por juego y Terminal+Zodiacal Táchira. La **Dupleta
MUST NOT implementarse**: cada jugada es una apuesta independiente con su propio monto y su propio sorteo
(el front ya crea apuestas separadas), de modo que el sistema nunca combina dos sorteos en una sola
apuesta. Las modalidades de 2 o más selecciones del MISMO sorteo (Cruzado Punta A+B, Par Millonario A+B,
Tripleta) SHALL representarse dentro del JSON `combinacion` existente (`selecciones[]`), sin tablas nuevas
y sin liquidación multi-sorteo.

#### Scenario: Dupleta fuera de alcance

- GIVEN un intento de modelar una Dupleta (2 animalitos, 2 sorteos) con un solo monto
- WHEN el sistema valida la apuesta
- THEN la modalidad es rechazada (no soportada) y el front la modela como dos apuestas independientes

#### Scenario: Cruzado con dos selecciones del mismo sorteo

- GIVEN una apuesta Cruzado con Punta A y Punta B del mismo sorteo por Bs. 10
- WHEN se liquida contra el resultado de ese único sorteo
- THEN se representa en `combinacion.selecciones[]` (un solo `sorteo_hora`) y paga el multiplicador del Cruzado

### Requirement: premio_posible calculado

El sistema SHALL calcular `premio_posible` como `monto × multiplicador` de la modalidad apostada según
`config.premios`, al crear la apuesta o al conocerse el resultado; MUST NOT quedar en 0.

#### Scenario: premio_posible no nulo

- GIVEN una apuesta animalito a `monje-millonario` por Bs. 10
- WHEN se crea la apuesta
- THEN `premio_posible` refleja `10 × 50 = Bs. 500`

### Requirement: Estados de apuesta y vencimiento sin resultado

El sistema SHALL transicionar la apuesta ganadora de `pendiente` a `ganadora` (con `resultado_id`) y
luego a `pagada` al pagarse; la perdedora a `perdida`; y SHALL marcar como `vencido` la apuesta cuyo
resultado nunca llega, con una ventana por defecto de **24 horas** configurable. La ventana SHALL ser
editable únicamente por los roles `super_master` y `master`. Antes de vencer una apuesta, el sistema SHALL
garantizar un reintento de búsqueda del resultado (catch-up por juego+fecha reutilizando
`ScrapeResultsJob`); solo las apuestas que siguen sin resultado tras la ventana y el reintento pasan a
`vencido`.

#### Scenario: Transición ganadora → pagada

- GIVEN una apuesta `ganadora` liquidada
- WHEN se paga el premio
- THEN pasa a `pagada`

#### Scenario: Vencimiento tras la ventana configurable

- GIVEN una apuesta `pendiente` sin `resultado_id` cuyo sorteo ocurrió hace más de la ventana configurada y cuyo reintento de búsqueda no encontró resultado
- WHEN se ejecuta el job de vencimiento
- THEN la apuesta pasa a `vencido`

#### Scenario: El reintento de búsqueda evita el vencimiento

- GIVEN una apuesta `pendiente` sin resultado cuyo sorteo ocurrió hace más de la ventana configurada
- WHEN el job relanza la búsqueda por juego+fecha y el resultado aparece
- THEN la apuesta se liquida (ganadora o perdida) y NO pasa a `vencido`

#### Scenario: Solo super_master y master configuran la ventana

- GIVEN un usuario con un rol distinto de `super_master` o `master`
- WHEN intenta modificar la ventana de vencimiento
- THEN la petición es rechazada (403)

### Requirement: Deduplicación de resultados pre-H22

El sistema SHALL deduplicar filas duplicadas del mismo sorteo (pre-migración H22) antes de liquidar,
para evitar la doble evaluación de una apuesta.

#### Scenario: Sorteo duplicado evaluado una vez

- GIVEN dos filas de resultado para el mismo sorteo
- WHEN se liquida
- THEN la apuesta se evalúa una sola vez

### Requirement: Validación con opciones del juego

`JuegoPluginManager::validarApuesta` SHALL pasar las opciones reales del juego (zoo propio) al plugin,
igual que `createApuesta`, para validar contra el zoológico del juego y no contra un mapa canónico.

#### Scenario: Validación contra el zoo propio

- GIVEN un juego con zoológico propio (p. ej. Monje 77 figuras)
- WHEN se valida una apuesta vía manager
- THEN se valida contra las opciones del juego, no contra el canónico

### Requirement: Tests de regresión por juego

El sistema SHALL incluir tests de regresión con al menos un caso por plugin y por comodín, cubriendo
acentos, terminales, signos, comodines (incluido Patronus 75 + palabra = 140×), modalidades de un solo
sorteo y premio base.

#### Scenario: Regresión por juego en verde

- GIVEN el motor corregido
- WHEN se ejecuta la suite de regresión
- THEN los tests por juego (acentos, terminal, signo, comodín, base) pasan
