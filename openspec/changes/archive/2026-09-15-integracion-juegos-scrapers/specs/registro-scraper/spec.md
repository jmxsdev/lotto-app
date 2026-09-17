# registro-scraper Specification

**Estado**: draft

## Purpose

Define el registro explícito juego→scraper (`juegos.scraper_class` nullable) y la resolución de scraper con fallback que preserva los 7 juegos actuales, junto con la regla fail-fast que prohíbe crear juegos en caliente.

## Requirements

### Requirement: Columna scraper_class nullable

El sistema MUST agregar la columna `juegos.scraper_class` (nullable) para asociar explícitamente cada juego a su clase scraper, sin exponerla en el payload de la API.

#### Scenario: Campo vacío permitido

- GIVEN un juego existente sin scraper explícito
- WHEN se resuelve su scraper
- THEN `scraper_class` vacío no rompe la resolución (aplica fallback)

### Requirement: Resolución de scraper con fallback

El sistema MUST resolver el scraper de un juego en orden: `scraper_class` (si existe) y luego el patrón actual — convención `{Studly(type)}Scraper` y match de URL. El fallback MUST preservar el comportamiento de los 7 juegos existentes.

#### Scenario: scraper_class explícito resuelve el scraper

- GIVEN un juego con `scraper_class` apuntando a su clase
- WHEN se resuelve el scraper
- THEN se instancia la clase indicada por `scraper_class`

#### Scenario: Sin scraper_class, funciona el fallback actual

- GIVEN un juego de los 7 actuales sin `scraper_class`
- WHEN se resuelve su scraper
- THEN el fallback (convención/match de URL) lo resuelve sin cambios (regresión cubierta)

### Requirement: Fail-fast — nunca crear juegos en caliente

Un scraper MUST NOT crear juegos en caliente. El helper de búsqueda MUST ser `findJuegoOrFail` (falla si el juego no está registrado), reemplazando/neutralizando `AnimalitosScraper::findOrCreateJuego`.

#### Scenario: Juego no registrado → falla clara

- GIVEN un scraper y un slug sin juego registrado
- WHEN el scraper intenta resolver el juego
- THEN falla con un error claro y NO crea ningún juego
