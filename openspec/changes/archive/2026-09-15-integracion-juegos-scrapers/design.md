# Design: Integración de juegos + scrapers (incremental)

## Technical Approach

Se conserva el patrón actual: juego = datos (seeder) + código (plugin opcional `JuegoInterface` + scraper opcional `BaseScraper`); el schedule sale de `juego_horarios` y `ScheduleServiceProvider` NO cambia. El cambio añade tres piezas de fundación — catálogo (`docs/juegos.md`), registro explícito (`juegos.scraper_class`) y fail-fast (`findJuegoOrFail`) — y luego integra los juegos 9–22, un work unit por juego con URL del cliente. Con `scraper_class` registrado por juego, `ScrapeResultsJob` no se edita por fuente nueva.

## Architecture Decisions

| # | Decisión | Elección y razón (alternativas rechazadas) |
|---|---|---|
| D1 | Resolución en `ScrapeResultsJob::resolveScraper` | Refactor acotado, sin registry propio (rechazado: resolver externo — sobre-ingeniería para 2 clases legacy) |
| D2 | Orden del fallback: `scraper_class` → match URL → convención | El código actual es URL→convención; conservarlo preserva los 7 juegos. El orden del proposal (convención→URL) rompería `trio-activo` (type tripletas + URL lottoactivo → resolvería TripletasScraper). Regresión en ScraperResolverTest |
| D3 | `scraper_class` no-nulo es autoritativo | Clase inexistente → warning + null (el job ya informa "no scraper"), sin fallback silencioso a otra fuente |
| D4 | Fail-fast en `BaseScraper::findJuegoOrFail` | Resuelve slug → name → `RuntimeException` clara ("juego no registrado; ejecuta su seeder"). AnimalitosScraper conserva su mapa canónico antes de delegar; TripletasScraper migra. Ningún scraper crea juegos. Impacto en los 7 actuales: verificado — todos los nombres del feed lottoactivo y `triple-zulia` están registrados, el flujo no se rompe; solo cambia crear→lanzar para nombres desconocidos |
| D5 | `saveResults` hoisteado a `BaseScraper` | Idéntico en ambos legacy; la plantilla nueva queda en fetch+parse+constructor (rechazado: duplicar por clase) |
| D6 | Constructor de scrapers nuevos: `__construct(?Juego $juego = null)` | La clase lee slug y `config['scraper']` del juego; `instantiateScraper` hace `new $class($juego)`. TripletasScraper migra (productId desde config, default '2') |
| D7 | Juego animalitos nuevo en el mismo feed | Seeder registra el name exacto del feed; `findJuegoOrFail` resuelve por name sin tocar el scraper. Línea nueva al mapa canónico solo si el feed usa otro nombre |
| D8 | Triple Facil (condicional, no bloquea) | Recomendado: dos juegos (`triple-facil`, `triple-facil-terminal`) + un `TripleFacilScraper` que emite ambas modalidades y filtra por `config['scraper']['modalidad']`. Confirmar con el cliente al llegar a ese WU |
| D9 | Sin índice en `scraper_class` | Solo se lee por juego ya resuelto (vía juego_id); nunca se consulta por clase |

## Data Flow

```
seeder (WU juego) ─► juegos(+scraper_class) + juego_horarios
ScheduleServiceProvider (boot) ─► ScrapeResultsJob(juegoId)
handle: resolveScraper (scraper_class → URL → {Studly(type)}Scraper)
        instantiateScraper: new $class($juego) ─► execute: fetch → parse → saveResults
parse: findJuegoOrFail (nunca crea) + normalizeHora → "H:i"
```

## File Changes

| Archivo | Acción | Descripción |
|---|---|---|
| `backend/database/migrations/*_add_scraper_class_to_juegos_table.php` | Create | `string('scraper_class')->nullable()`; down: dropColumn |
| `backend/app/Models/Juego.php` | Modify | `scraper_class` en `$fillable` y `$hidden` (JuegoController serializa el modelo completo — no exponer en payload API) |
| `backend/app/Jobs/ScrapeResultsJob.php` | Modify | `resolveScraper` (scraper_class primero) + `instantiateScraper` (rama Animalitos pasa `$juego`; resto `new $class($juego)`) |
| `backend/app/Plugins/Scrapers/BaseScraper.php` | Modify | +`findJuegoOrFail()`, `saveResults()`, `normalizeHora()` |
| `backend/app/Plugins/Scrapers/AnimalitosScraper.php` | Modify | `findOrCreateJuego` → `findJuegoOrFail`; elimina saveResults local |
| `backend/app/Plugins/Scrapers/TripletasScraper.php` | Modify | constructor `?Juego`; fail-fast |
| `backend/database/seeders/XxxSeeder.php` (×14) | Create | `firstOrCreate(['slug'])` + JuegoLimite banca/bs/3600 + PluginJuego (reutiliza clase por type; nueva solo si type nuevo) + JuegoOpcion* + JuegoHorario* (`firstOrCreate(['juego_id','hora'])`) + `scraper_class`; registrar en DatabaseSeeder |
| `backend/app/Plugins/Scrapers/XxxScraper.php` (×N) | Create | solo fetch + parse + constructor |
| `backend/tests/Fixtures/xxx_*.{json,html}` / `tests/Unit/JuegoXxxScraperTest.php` / `tests/Feature/JuegoXxxResultsTest.php` | Create | fixture real por fuente + tests por juego |
| `backend/docs/juegos.md` | Create | catálogo 7+14 con hueco #8; fila por juego en su mismo WU |

## Interfaces / Contracts

```php
// Contrato de scraper nuevo
class XxxScraper extends BaseScraper {
    protected string $baseUrl; protected string $scraperName; protected ?Juego $juego;
    public function __construct(?Juego $juego = null) { parent::__construct(); $this->juego = $juego; }
    protected function fetch(string $fecha): string { /* fuente */ }
    protected function parse(string $rawData): array { /* findJuegoOrFail + normalizeHora */ }
}
// BaseScraper (nuevos miembros)
protected function findJuegoOrFail(array $data): Juego;   // slug → name → throw
protected function normalizeHora(?string $h): ?string;    // "10:00 AM"|"H:i:s" → "H:i" (America/Caracas)
public function saveResults(array $resultados, string $fecha): int; // upsert juego+fecha+hora (dedupe)
```

## Testing Strategy

| Capa | Qué | Cómo |
|---|---|---|
| Unit | Parse por juego con fixture real | `JuegoXxxScraperTest`, Reflection sobre parse (patrón TripletasScraperTest), sin mocks HTTP |
| Feature | Save + dedupe por juego | `JuegoXxxResultsTest`, RefreshDatabase + DatabaseSeeder |
| Regresión | Resolver y fail-fast | `ScraperResolverTest`: scraper_class gana; clase inexistente → null; trio-activo → AnimalitosScraper (orden URL→convención); convención por type; fail-fast: nombre desconocido lanza y no crea filas |
| Suite | Criterio del cliente | `composer test -- --filter=Xxx` por juego (corre unit+feature de ese juego); suite general completa al integrar 10 juegos — documentado en `docs/juegos.md` |

## Threat Matrix

N/A — sin routing, shell, subprocesos, automatización VCS/PR, clasificación de ejecutables ni integración de procesos; solo un job de cola interno.

## Migration / Rollout

Migración ligera nullable con up/down. Orden de despliegue: fundación primero (catálogo → migración → resolver → fail-fast), que preserva los 7 actuales vía fallback; luego un WU por juego en el orden de URLs del cliente; checkpoint de suite general al juego 10. Rollback por juego = eliminar seeder + clase + fixtures; revertir la migración restaura el resolutor legacy. `hora_sorteo`: los 7 legacy conservan su formato actual (fuera de alcance; normalizarlos sin migración de datos duplicaría por string distinto); todo scraper NUEVO normaliza a `H:i`.

## Open Questions

- [#8] Hueco en la lista (salta de 7 a 9) — confirmar con el cliente al integrar el juego 9.
- [Triple Facil] Modalidad doble — D8, confirmar al llegar a ese WU.
- [URLs] Cada WU de juego queda bloqueado hasta recibir la URL del cliente.
