# Delta for integracion-juego-incremental

## MODIFIED Requirements

### Requirement: Seeder por juego

El sistema MUST integrar cada juego nuevo con un seeder que registre: nombre, slug, type,
`config.premios` (`{base, modalidades, comodines}`) en lugar de un único `premio_multiplo`, límites
default, `scraper_url`, `requires_scraper`, y sus horarios en `juego_horarios`.
(Previously: el seeder registraba un único `premio_multiplo` en vez del esquema de premios completo.)

#### Scenario: Juego registrado y agendado

- GIVEN el seeder del juego nuevo ejecutado
- WHEN se regenera el schedule (ScheduleServiceProvider)
- THEN el juego queda registrado y sus horarios en `juego_horarios` lo agendan

#### Scenario: Seeder registra el esquema de premios

- GIVEN el seeder de un juego con premios oficiales
- WHEN se ejecuta
- THEN `config.premios` queda con base, modalidades y comodines según el reglamento

#### Scenario: Scraper parsea y persiste con dedupe

- GIVEN el scraper del juego y resultados nuevos
- WHEN ejecuta parse + save
- THEN persiste los resultados y no duplica los ya existentes (dedupe)

#### Scenario: hora_sorteo normalizada

- GIVEN un resultado con `hora_sorteo` en formato fuente variable
- WHEN se parsea
- THEN `hora_sorteo` se normaliza a `H:i`
