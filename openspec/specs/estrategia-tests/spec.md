# estrategia-tests Specification

**Estado**: draft

## Purpose

Define la estrategia de tests por juego (naming predecible y filtrable) y el criterio del cliente para correr la suite general.

## Requirements

### Requirement: Tests por juego con naming predecible

El sistema MUST proveer por cada juego un test unitario `JuegoXxxScraperTest` (parse con fixture, sin mocks HTTP) y un test feature `JuegoXxxResultsTest` (saveResults + dedupe), ejecutables de forma aislada.

#### Scenario: Filtro por juego

- GIVEN los tests de un juego `Xxx`
- WHEN se ejecuta `--filter="Xxx"`
- THEN corre SOLO ese juego

#### Scenario: Suite de scraping por dominio

- GIVEN los scrapers integrados
- WHEN se ejecuta la suite de scraping con un filtro de dominio
- THEN corre solo el dominio de scraping (no la suite completa)

### Requirement: Criterio de suite general (10 juegos)

La suite general completa MUST ejecutarse al completar 10 juegos integrados (criterio del cliente), y este criterio SHOULD quedar documentado.

#### Scenario: Criterio documentado

- GIVEN la estrategia de tests
- WHEN se revisa la documentación
- THEN el criterio de 10 juegos para correr la suite general está documentado
