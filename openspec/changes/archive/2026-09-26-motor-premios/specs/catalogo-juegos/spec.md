# Delta for catalogo-juegos

## ADDED Requirements

### Requirement: Contrato de premiación en el catálogo

El export del catálogo (`docs/juegos.json` vía `JuegoCatalogoService`) SHALL exponer el premio de cada
juego como `premios: {base, modalidades, comodines}` en lugar de un único `premio_multiplo`. Los valores
MUST coincidir con los oficiales de `docs/multiplicadores-juegos.md`.

#### Scenario: Juego exportado con esquema completo

- GIVEN un juego con `config.premios = {base, modalidades, comodines}`
- WHEN se genera el catálogo JSON
- THEN el export incluye `premios` con base, modalidades y comodines

#### Scenario: Juego deshabilitado excluido de la venta

- GIVEN un juego con `active=false` (sin fuente oficial de premios)
- WHEN se genera el catálogo JSON
- THEN el juego se exporta marcado como no vendible
