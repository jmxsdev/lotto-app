# catalogo-juegos Specification

**Estado**: draft

## Purpose

Define `docs/juegos.md` como la lista maestra de juegos del backend — la fuente de referencia única — y su actualización en el MISMO work unit que cada juego integrado.

## Requirements

### Requirement: Lista maestra de juegos

El sistema MUST mantener `docs/juegos.md` como fuente de referencia de los juegos. Cada entrada MUST
documentar: nombre, slug, type, horarios, fuente scraper y estado del scraper.

(Previously: el escenario inicial documentaba "7 juegos"; el catálogo final son 20 juegos.)

#### Scenario: Los 20 juegos documentados

- GIVEN el catálogo final tras el cambio
- WHEN se abre `docs/juegos.md`
- THEN los 20 juegos están documentados con nombre, slug, type, horarios, fuente y estado

#### Scenario: Juego nuevo reflejado al integrarlo

- GIVEN un juego nuevo a integrar
- WHEN se completa el work unit de ese juego
- THEN `docs/juegos.md` lo refleja con sus campos en el MISMO work unit

#### Scenario: La lista es la referencia de los seeders

- GIVEN un seeder de un juego y `docs/juegos.md`
- WHEN se comparan slug, type y fuente
- THEN coinciden (la lista es la referencia; el seeder la materializa)

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

### Requirement: Contrato de tipo de juego

El catálogo SHALL soportar SOLO los tipos `animalitos` y `tripletas`. Ningún juego SHALL tener
`type = terminales`; la jugada de terminal es una modalidad de `tripletas`. El catálogo exportado
(`docs/juegos.json` vía `JuegoCatalogoService`) SHALL contener 20 juegos y MUST NOT incluir un juego
`terminal-activo`.

#### Scenario: Catálogo de 20 juegos sin terminales

- GIVEN el catálogo exportado
- WHEN se generan `docs/juegos.json` y su copia bundled
- THEN hay 20 juegos y ningún juego tiene `type = terminales`

#### Scenario: terminal-activo ausente

- GIVEN el catálogo exportado
- WHEN se busca el juego `terminal-activo`
- THEN no existe (su jugada vive como modalidad `terminal` de `trio-activo`)

#### Scenario: trio-activo conserva la modalidad terminal 60×

- GIVEN el juego `trio-activo` en el catálogo
- WHEN se expone su configuración de premios
- THEN incluye `premios.modalidades.terminal = 60`

#### Scenario: Tipo no soportado rechazado

- GIVEN un juego con `type = terminales` en el catálogo JSON
- WHEN la taquilla carga el catálogo con el contrato final (Fase 2)
- THEN lo rechaza por tipo inválido (solo `animalitos|tripletas`)

### Requirement: Resultados expuestos solo por familia

Las páginas de resultados de la taquilla (`resultados.astro`) y del panel
(`resultados-loterias.astro`) SHALL exponer pestañas SOLO para `animalitos` y `tripletas`, y MUST NOT
mostrar una pestaña `terminales`.

#### Scenario: Sin pestaña de terminales

- GIVEN la página de resultados de la taquilla y del panel
- WHEN se renderizan las pestañas de resultados
- THEN solo existen `Animalitos` y `Tripletas` (no `Terminales`)
