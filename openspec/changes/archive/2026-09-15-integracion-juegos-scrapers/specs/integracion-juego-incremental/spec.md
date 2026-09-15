# integracion-juego-incremental Specification

**Estado**: draft

## Purpose

Define el patrón de integración de un juego nuevo: seeder + horarios + scraper (si aplica) + fixtures/tests + registro en `docs/juegos.md`, un juego a la vez, con URL provista por el cliente y verificación antes de pasar al siguiente.

## Requirements

### Requirement: Seeder por juego

El sistema MUST integrar cada juego nuevo con un seeder que registre: nombre, slug, type, `premio_multiplo`, límites default, `scraper_url`, `requires_scraper`, y sus horarios en `juego_horarios`.

#### Scenario: Juego registrado y agendado

- GIVEN el seeder del juego nuevo ejecutado
- WHEN se regenera el schedule (ScheduleServiceProvider)
- THEN el juego queda registrado y sus horarios en `juego_horarios` lo agendan

#### Scenario: Scraper parsea y persiste con dedupe

- GIVEN el scraper del juego y resultados nuevos
- WHEN ejecuta parse + save
- THEN persiste los resultados y no duplica los ya existentes (dedupe)

#### Scenario: hora_sorteo normalizada

- GIVEN un resultado con `hora_sorteo` en formato fuente variable
- WHEN se parsea
- THEN `hora_sorteo` se normaliza a `H:i`

### Requirement: Un juego a la vez con verificación

El sistema MUST integrar y verificar cada juego antes de iniciar el siguiente; la URL de cada fuente la aporta el cliente juego por juego.

#### Scenario: Bloqueo sin URL

- GIVEN un juego nuevo sin URL del cliente
- WHEN se intenta integrar
- THEN la integración queda bloqueada hasta recibir la URL

#### Scenario: Verificación antes del siguiente juego

- GIVEN un juego integrado
- WHEN se ejecutan sus tests y fixtures
- THEN se verifica en verde antes de iniciar el siguiente juego

### Requirement: Ámbito de la integración

El cambio MUST limitarse a `backend/` y `docs/juegos.md`. `panel/` y sus contratos API MUST NOT modificarse.

#### Scenario: panel intacto

- GIVEN el cambio completo
- WHEN se inspeccionan `panel/` y los contratos API
- THEN no sufren cambios

### Requirement: Decisiones condicionales pendientes del cliente

Ciertos juegos dependen de decisiones del cliente que NO bloquean estas specs: el hueco `#8` (la lista salta del 7 al 9) y la doble modalidad de Triple Facil (dos juegos `triple-facil`/`triple-facil-terminal` vs un juego con dos plugins). Cada una se resuelve al llegar a ese juego.

#### Scenario: Hueco #8 documentado

- GIVEN la lista de juegos
- WHEN se integra el juego 9
- THEN el hueco #8 se documenta en `docs/juegos.md` según decisión del cliente

#### Scenario: Modalidad de Triple Facil decidida al integrarlo

- GIVEN llegar al juego Triple Facil
- WHEN el cliente confirma la modalidad
- THEN se integra según la decisión (dos juegos o un juego con dos plugins)
