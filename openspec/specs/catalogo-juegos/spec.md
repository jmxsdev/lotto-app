# catalogo-juegos Specification

**Estado**: draft

## Purpose

Define `docs/juegos.md` como la lista maestra de juegos del backend — la fuente de referencia única — y su actualización en el MISMO work unit que cada juego integrado.

## Requirements

### Requirement: Lista maestra de juegos

El sistema MUST mantener `docs/juegos.md` como fuente de referencia de los juegos. Cada entrada MUST documentar: nombre, slug, type, horarios, fuente scraper y estado del scraper.

#### Scenario: Los 7 juegos actuales documentados desde el inicio

- GIVEN el inicio del cambio
- WHEN se abre `docs/juegos.md`
- THEN los 7 juegos actuales están documentados con nombre, slug, type, horarios, fuente y estado

#### Scenario: Juego nuevo reflejado al integrarlo

- GIVEN un juego nuevo a integrar
- WHEN se completa el work unit de ese juego
- THEN `docs/juegos.md` lo refleja con sus campos en el MISMO work unit

#### Scenario: La lista es la referencia de los seeders

- GIVEN un seeder de un juego y `docs/juegos.md`
- WHEN se comparan slug, type y fuente
- THEN coinciden (la lista es la referencia; el seeder la materializa)
