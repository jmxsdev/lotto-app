# Proposal: Integración de juegos + scrapers de resultados (incremental)

## Intent

El cliente quiere incorporar 14 juegos nuevos (números 9–22) al catálogo del backend, cada uno con su scraper de resultados, en un flujo **un juego a la vez** (URL provista por el cliente, scraper verificado antes de pasar al siguiente). Los 7 juegos existentes solo se documentan, no se migran. `panel/` no se toca (otro agente).

## Scope

### In Scope
- `docs/juegos.md` — lista maestra (7 actuales + 14 nuevos): nombre, slug, type, horarios, fuente, estado del scraper.
- Por cada juego nuevo: seeder (`Juego` + `JuegoLimite` + `PluginJuego` + `JuegoOpcion` + `JuegoHorario`) + clase scraper (`BaseScraper`) + fixture + tests.
- Registro explícito juego→scraper (columna `juegos.scraper_class` nullable) + refactor acotado de `ScrapeResultsJob::resolveScraper`.
- Regla fail-fast: los scrapers nuevos NO crean juegos en caliente.

### Out of Scope
- `panel/` (otro agente).
- Migrar los 7 juegos existentes (solo documentarlos en `docs/juegos.md`).
- `FetchResultsJob` legacy (deprecarlo es futuro, no este cambio).
- Endpoint operativo `POST /juegos` (Fase C del explore; se pospone hasta que el panel lo exija).

## Capabilities

### New Capabilities
- `juegos-scrapers`: catálogo maestro de juegos y registro explícito juego→scraper con integración incremental y fail-fast.

### Modified Capabilities
- None.

## Approach

Fase A (datos) + B (scrapers) del explore, escalonado al ritmo "un juego a la vez":

- **Lista maestra**: `docs/juegos.md` como fuente de referencia; los seeders por juego la materializan (se conserva el patrón actual `firstOrCreate(['slug'], [...])`).
- **Registro explícito**: columna `scraper_class` nullable en `juegos` (migración ligera). `resolveScraper` resuelve en orden: `scraper_class` → convención `{Studly(type)}Scraper` → match de URL (fallback legacy hasta portar los 7 actuales). Sin editar el job por fuente nueva.
- **Fail-fast**: nuevo helper `findJuegoOrFail(['slug'=>...])` en `BaseScraper`; los scrapers nuevos lanzan excepción si el juego no está en la lista (nunca `findOrCreateJuego`).
- **Un juego = una fila en la lista + seeder + scraper + fixture + tests**, sin tocar `ScrapeResultsJob`.

## Decisiones abiertas

- **#8 faltante** (la tabla salta del 7 al 9): **pregunta al cliente** — ¿juego omitido/removido? Documentar en `docs/juegos.md` como hueco.
- **Triple Facil (doble modalidad "Tripletas y Terminal")**: `type` es único por juego y el plugin es por type. **Decisión recomendada**: dos juegos `triple-facil` (tripletas) y `triple-facil-terminal` (terminales), cada uno con su type/plugin/horarios, compartiendo la misma fuente de scrape (un scraper que emite ambas modalidades). Marcar como **decisión** y confirmar con el cliente (opción alternativa: un juego con dos `PluginJuego` activos).

## Estrategia de tests

- Unit por juego: `JuegoXxxScraperTest` (parse con fixture, sin mocks HTTP).
- Feature por juego: `JuegoXxxResultsTest` (saveResults + dedupe, RefreshDatabase).
- Ejecución: `composer test -- --filter=JuegoXxxScraperTest`. Suite general (300+) solo al completar 10 juegos cargados.

## Orden de integración

Depende de las URLs del cliente (cada apply necesita su URL). Sugerido: 9→22 en el orden del cliente, un juego por iteración: lista → seeder → scraper → tests → verificación manual → siguiente.

## Affected Areas

| Area | Impact | Descripción |
|------|--------|-------------|
| `backend/docs/juegos.md` | Nuevo | Lista maestra de juegos |
| `backend/database/seeders/*` | Nuevo | 14 seeders (uno por juego nuevo) |
| `backend/database/migrations/*` | Nuevo | `juegos.scraper_class` nullable |
| `backend/app/Jobs/ScrapeResultsJob.php` | Modificado | `resolveScraper` usa `scraper_class` primero |
| `backend/app/Plugins/Scrapers/*` | Nuevo/Mod | Nuevos scrapers + `findJuegoOrFail` en `BaseScraper` |
| `backend/tests/*` + `Fixtures/*` | Nuevo | Tests y fixtures por juego |

## Risks

| Riesgo | Prob. | Mitigación |
|--------|-------|------------|
| Scrapers nuevos crean juegos en caliente | Alta | Fail-fast obligatorio |
| Formato `hora_sorteo` inconsistente entre fuentes | Media | Normalizar en cada `parse` a `H:i` |
| URLs/estructura cambian por fuente | Media | Fixture real + verificación manual por juego |
| Cambio de contrato API (campo `scraper_class` visible) | Baja | No exponer en payload; documentar si el panel lo requiere |

## Rollback Plan

- Cada juego es independiente: revertir = eliminar seeder + clase scraper + fila `juegos` del juego, sin afectar a los demás.
- `scraper_class` nullable y `resolveScraper` con fallback preservan el comportamiento actual si el campo está vacío; revertir la migración (drop column) restaura el resolutor legacy.

## Dependencies

- URLs de cada juego provistas por el cliente (bloquean el apply de cada juego).

## Success Criteria

- [ ] `docs/juegos.md` lista los 7 actuales + 14 nuevos con estado del scraper.
- [ ] 10 juegos nuevos scrapeando con `--filter` verde por juego.
- [ ] Ningún scraper nuevo crea juegos en caliente (fail-fast testeado).
- [ ] `panel/` y sus contratos API intactos.
