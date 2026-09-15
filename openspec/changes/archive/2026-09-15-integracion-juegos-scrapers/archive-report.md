# Archive Report — integracion-juegos-scrapers

**Fecha de archivo**: 2026-09-15
**Artefacto store**: hybrid (openspec + engram)
**Rama al archivar**: `main`
**Veredicto del verify**: **GO — PASS WITH WARNINGS** (sin CRITICAL ni blockers)
**Merge a main**: `5bd50d3`
**Deploy a producción**: CI/CD verde

---

## Resumen del cambio

Integración de **21 juegos** (7 originales + 14 nuevos) con scrapers de resultados (mayoría sobre APIs oficiales verificadas en vivo), catálogo JSON determinista para el front (`docs/juegos.json`, 21 juegos con opciones, horarios, premios, comodines y modalidades), verificación de datos contra reglamentos oficiales (3 PDFs parseables aplicados; 11 reglamentos descargados en `docs/reglamentos/`) y documentación completa (`docs/{seguimiento-verificacion,fuentes-oficiales,inconsistencias,motor-premios,comparacion-juegos,estrategia-scrapers-premios,plataformas-juegos}.md`).

Arquitectura resultante: `juegos.scraper_class` nullable (oculta en API) + resolución con fallback `scraper_class → URL → convención` en `ScrapeResultsJob`, fail-fast `findJuegoOrFail` (nunca crea juegos en caliente), `saveResults` hoisteado en `BaseScraper` con dedupe por juego+fecha+hora, seeder por juego registrado en `DatabaseSeeder`, y captura del comodín MEGA en `MegaAnimal40OficialScraper`.

## Veredicto del verify (GO)

Fuente: `verify-report.md` (2026-09-15, observación Engram #199).

| Métrica | Valor |
|---------|-------|
| Verdict | `pass` — GO (PASS WITH WARNINGS) |
| Blockers | 0 |
| CRITICAL findings | 0 |
| Requirements | 10/10 |
| Scenarios | 18/18 compliant |
| Tests | 708 (706 passed / 0 failed / 2 skipped), 3530 assertions |
| Build | `vendor/bin/pint --test` exit 0 |
| Comando de tests | `php -d memory_limit=1536M artisan test` (exit 0) |

Advertencias del verify (operacionales/higiene, no bloqueantes, sin correcciones posteriores):

1. `COMPOSER_PROCESS_TIMEOUT=900 composer test` agota el timeout (~32 min de suite); la suite pasa íntegra como `php artisan test`. Acción sugerida: documentar el comando canónico o subir el timeout.
2. Higiene del checklist en `tasks.md` (casillas "f" y bloque plantilla #18–22) — **resuelta en este archive** (ver Reconciliación abajo).
3. Cobertura de BD local baja para `triple-zulia` (1 fila) y `triple-caliente` (4 filas) vs horarios documentados; scrapers verificados en vivo y con fixtures.

## Evidencia del merge y del deploy

- **Merge**: `5bd50d3` — "Merge branch 'feat/integracion-juegos-scrapers-f27-mega-oficial': integra 21 juegos con scrapers oficiales, catalogo JSON del front y verificacion de datos contra fuentes oficiales" (HEAD de `main` al archivar).
- **Deploy**: pipeline CI/CD en verde para el merge — GitHub Actions run `34929475422` (evento `push` a `main`, 2026-09-15T04:36:20Z, estado `success`), verificado vía `gh run list` al momento del archive. El despliegue a producción se ejecutó por el mismo pipeline (Docker Compose + Caddy sobre VPS).

## Pendientes post-deploy (estado al cierre)

1. **Seeders de juegos en el VPS**: el entrypoint de producción solo ejecuta los seeders en el primer arranque; los 21 seeders de juegos deben correrse en la base de producción (p. ej. `php artisan db:seed` dirigido o el mecanismo equivalente del contenedor) para registrar juegos, límites, plugins, opciones y horarios.
2. **Scheduler de scrapers NO configurado en producción**: falta el cron `schedule:run` o el servicio `schedule:work` para que `ScrapeResultsJob` se ejecute según `juego_horarios`. Sin esto los scrapers no corren en producción.
3. **Decisiones de cliente pendientes (H12/H13/H17/H18/H19/H20)** documentadas en `docs/inconsistencias.md` y **reglamentos escaneados pendientes de extracción** (5 escaneados: triple-chance vigente + 2024, la-granjita, la-ricachona, lotto-activo [imagen]) para aplicar sus premiaciones.
4. **Comodín MEGA**: captura implementada y verificada (fixture sintético + triangulación; el real llegará con el primer comodín); la **liquidación 40× queda pendiente del ciclo futuro del motor de premios** (`docs/motor-premios.md`).

## Reconciliación excepcional de checkboxes (Task Completion Gate)

El `tasks.md` archivado contenía **14 casillas `[ ]`** para trabajo ya completado. Se aplicó la reconciliación excepcional permitida por el gate (el orquestador declaró el estado final del cambio como COMPLETO — "todas las fases [x]" — y el verify-report GO acredita "Tasks incompletas reales: 0" con evidencia por casilla):

- **6 casillas "f" (9f–14f)** — "verificación funcional con URL real pendiente del cliente": superadas por verificaciones reales posteriores (9l, f8.5, f24.8, f27.6) con carga real en BD local y rescrapes idempotentes. Marcadas `[x]` con la referencia que las supera.
- **6 casillas del bloque plantilla (a–f, juegos #18–22)** — plantilla muerta: esos juegos se integraron en WUs propios (f12–f27) con seeder, scraper, fixture, tests RED→GREEN y fila en `backend/docs/juegos.md`. Marcadas `[x]` como plantilla superada.
- **2 casillas de Phase 3 (3.2 pint / 3.3 panel intactos)** — cerradas por el propio verify-report (pint exit 0; `git diff c64bf17..HEAD panel/ collections/` vacío). Marcadas `[x]` con la evidencia.

Resultado: 0 casillas `[ ]` restantes, 173 `[x]`. La razón exacta de cada marcado quedó anotada en la propia línea de `tasks.md`.

## Trazabilidad Engram (observaciones leídas)

| Artefacto | Observación Engram |
|-----------|--------------------|
| explore | #41 (`sdd/integracion-juegos-scrapers/explore`) |
| proposal | #42 (`sdd/integracion-juegos-scrapers/proposal`) |
| spec (delta specs) | #43 (`sdd/integracion-juegos-scrapers/spec`) |
| design | #45 (`sdd/integracion-juegos-scrapers/design`) |
| tasks (snapshot inicial) | #47 (`sdd/integracion-juegos-scrapers/tasks`) |
| verify-report | #199 (`sdd/integracion-juegos-scrapers/verify-report`) |

## Specs sincronizadas a `openspec/specs/`

Los 4 delta specs eran specs completas de capabilities NUEVAS (ninguna existía en `openspec/specs/`). Se copiaron mecánicamente (cp + diff -r vacío) desde `openspec/changes/integracion-juegos-scrapers/specs/`:

| Capability | Acción | Ruta |
|------------|--------|------|
| catalogo-juegos | Creada (spec completa) | `openspec/specs/catalogo-juegos/spec.md` |
| estrategia-tests | Creada (spec completa) | `openspec/specs/estrategia-tests/spec.md` |
| integracion-juego-incremental | Creada (spec completa) | `openspec/specs/integracion-juego-incremental/spec.md` |
| registro-scraper | Creada (spec completa) | `openspec/specs/registro-scraper/spec.md` |

Sin deltas destructivos (sin REMOVED/MODIFIED/RENAMED), por lo que no aplica la regla de aviso de `openspec/config.yaml` (`rules.archive`).

## Contenido del archivo

```
openspec/changes/archive/2026-09-15-integracion-juegos-scrapers/
├── apply-progress.md   (166 KB — progreso por WU f0→f27)
├── design.md           (decisiones D1–D9)
├── explore.md          (exploración inicial)
├── proposal.md         (propuesta del cambio)
├── specs/              (4 delta specs: catalogo-juegos, estrategia-tests, integracion-juego-incremental, registro-scraper)
├── tasks.md            (159 [x] + 14 reconciliadas = 173 [x], 0 [ ])
├── verify-report.md    (GO — PASS WITH WARNINGS)
└── archive-report.md   (este reporte, aditivo)
```

## Integridad mecánica

El move del change folder se realizó con `git mv` y se verificó con `diff -r` (snapshot recursivo pre-move vs carpeta archivada): **salida vacía (byte-idéntico)**. El archive-report es el único archivo aditivo (no existía en el snapshot) y queda excluido de la comparación. Las 4 specs se copiaron con `cp` a archivo temporal + `diff -r` vacío + `mv` (patrón del skill, sin pasar bytes por el modelo).

## Notas de estado final

- `openspec/changes/integracion-juegos-scrapers/` (activo) ya NO existe; el cambio vive solo en `archive/` y en el historial de git.
- El working tree contiene cambios ajenos no commiteados (`collections/*.yml`, `panel/.astro/settings.json`, `.atl/`, `.codegraph/`, `openspec/config.yaml`) que NO forman parte de este archive y NO fueron tocados ni commiteados.