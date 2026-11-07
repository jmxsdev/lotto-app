# Design: Terminal como modalidad (absorber `terminal-activo` en Trío Activo)

## Contexto y enfoque

`terminal` es la modalidad de un juego `tripletas` (RF-01.7). El juego independiente
`terminal-activo` se retira del catálogo (21→20) y el tipo `terminales` desaparece. La
liquidación ya vive en `Tripletas::evaluarAcierto` (`tipo:'terminal'` → `aciertoPosicion`
offset 1, largo 2), sin tocar `PremiosEngine` ni `ComisionService`.

Entrega en dos fases, alineadas con los deltas de `motor-premios`, `comisiones` y
`catalogo-juegos`:

- **Fase 1 (front-first, backend intacto)**: quitar la pestaña Terminales en taquilla y panel.
  Catálogo bundled sigue en 21; `TipoJuego`/`TIPOS_VALIDOS` siguen aceptando `terminales`.
  Taquilla `1.2.0` + CHANGELOG.
- **Fase 2 (retiro backend + limpieza)**: migración, borrado de seeder/plugin/entradas de
  premios/scraper, catálogo 20, limpieza de tipos y ramas legacy, logo, tests y docs.
  Taquilla `2.0.0` + CHANGELOG.

## Decisiones de arquitectura

| # | Decisión | Alternativas rechazadas | Razón |
|---|----------|-------------------------|-------|
| D1 | Retirar primero el front y después el backend | Backend primero; las dos fases en un solo release | No hay telemetría de versiones y la taquilla usa OTA. Un cliente sin actualizar (catálogo 21, pestaña Terminales) vendería contra un juego ya borrado y fallaría. En Fase 1 el backend sigue aceptando terminal; el cliente nuevo deja de ofrecerlo. |
| D2 | El catálogo bundled queda en 21 durante Fase 1 | Regenerar 20 en Fase 1 | El catálogo se exporta de la BD, que sigue con 21; `check-pure.mjs:889` exige copia byte-idéntica a `docs/juegos.json`. Cambiarlo en Fase 1 obligaría a tocar el contrato del front antes de retirar el backend, sin beneficio y con rollback más caro. |
| D3 | Migración `2026_11_06_000001_retire_terminal_activo.php` | `2026_11_05_000003` | #89 ya ocupa `2026_11_05_000001/000002` (audit_events, audit_chain_state). El prefijo del 6 queda estrictamente después y fuera del namespace diario de #89, que aún tiene WUs pendientes; evita colisión de nombre y orden de ejecución. |
| D4 | `down()` irreversible (no-op documentado) | Re-sembrar el juego y sus dependientes en `down()` | Los hijos (límites, horarios, opciones, plugin, resultados, auditoría) solo se reconstruyen duplicando el seeder retirado, y un `plugin_juegos.class_namespace` apuntaría a una clase borrada. El rollback real es `git revert` del WU + `php artisan db:seed`, no un rollback de esquema. |
| D5 | Borrado explícito en orden + guard de ventas | Confiar en los `onDelete('cascade')` de `apuestas`/`resultados` | `apuestas.juego_id` y `resultados.juego_id` son cascade: borrar `juegos` arrastraría ventas en silencio. El orden explícito (`juego_limites`, `juego_horarios`, `juego_opciones`, `plugin_juegos`, `resultados`, `juego_auditoria`, `juegos`) hace visible qué se borra y el guard de `apuestas` aborta si hubiera ventas. |
| D6 | `ComisionService` sin cambios | Quitar `terminales` del `match` de `topePorTipo` | `topePorTipo()` resuelve por `Juego::find($juegoId)->type`: una jugada de terminal se persiste bajo un juego `tripletas`, así que ya liquida 25 %. El `default => 100` debe seguir existiendo para tipos desconocidos reales (`bingo`). |
| D7 | La modalidad terminal se ata al triple vendido (`combinacion.triple` a/b/c) | Pagar contra cualquier slot o contra la clave `numero` del resultado | `Tripletas::aciertoPosicion` resuelve `tipoTripleDe($combinacion['triple'])`; sin `triple` devuelve no-match y no hay default. `slotPosicionalValido` exige que el slot esté en `premios.posicion_triples`. Por eso el terminal de `triple_a` no puede pagar con `triple_b`. |
| D8 | No tocar `Tripletas.php` | Extender el plugin para el juego retirado | El plugin compartido ya implementa la modalidad (`terminal`, `punta`, `uña`) y es el que se resuelve por `plugin_juegos` para `trio-activo` y las tripletas. El retiro solo elimina el plugin `Terminales`, que no interviene en esta ruta. |
| D9 | Catálogo 20 con hueco de id en 3; no renumerar | Renumerar ids o resembrar desde cero para cerrar el hueco | El id es contrato del front y del ticket. `juegos:export` ordena por id: el archivo queda 1,2,4…21. `JuegosJsonTest` documenta el hueco en `SLUGS_POR_ID`; los ids generados en tests siguen siendo un bloque consecutivo. |
| D10 | Quitar `terminales` de `TipoJuego`/`TIPOS_VALIDOS`/`derivarFamilia`; conservar `FamiliaOpciones 'terminal'`, `claveNumerica` y `DEFS.terminal` | Borrar también la familia y las definiciones de modalidad | La familia `terminal` y `DEFS.terminal` describen la modalidad vendible (2 cifras, `requiereTriple`), no el tipo retirado. `keyboard.ts` y `modalidades.ts` las usan; `catalogo.ts` puede conservarlas aunque ningún juego las derive. |
| D11 | El catálogo generado no cuenta para el presupuesto de revisión | Contar las 635 líneas del bloque `terminal-activo` como código autoral | `docs/juegos.json` y su copia bundled son artefactos generados (equivalente a un golden). Se revisan por invariantes: 20 juegos, sin `terminal-activo`, byte-idénticos. El presupuesto de 400/800 líneas se aplica solo a código y tests escritos a mano. |
| D12 | Rollout por forward-fix, no downgrade OTA | Republicar la versión anterior tras un rollback | `electron-updater` no baja versiones. Un rollback de Fase 1 se publica como `1.2.1` con la pestaña restaurada; Fase 2 como `2.0.1`. Una sola fila de release y `latest.yml` validado por `releases:publish`. |
| D13 | El catálogo 20, el logo y los asserts de conteo viajan en WU2 (no en WU3) | Dejar el catálogo en WU3 | Si WU2 regenera `docs/juegos.json` a 20 y WU3 copia el bundled/actualiza `check-pure`, el PR de WU2 queda con `check-pure` rojo. Para mantener cada PR verde, WU2 cierra el círculo catálogo+logo+conteos y WU3 limpia el contrato TS y las ramas legacy. |

## Flujo de datos

```
VENTA / LIQUIDACIÓN (ya existente, sin cambios)
POST /tickets
  { juego_id: <tripletas>, combinacion: {tipo:"terminal", numero:"05", triple:"a"} }
      → JuegoPluginManager (plugin_juegos.clase = Tripletas)
          → validarApuesta: slotPosicionalValido(triple:"a") ∈ premios.posicion_triples
          → evaluarAcierto
              → aciertoPosicion(offset 1, len 2) sobre numeros_ganadores.triple_a
              → coincide ⇒ clave "terminal"
          → PremiosEngine: premios.modalidades.terminal = 60
      → comisión: juego.type = "tripletas" ⇒ topePorTipo = 25

RETIRO (Fase 2)
migración retira terminal-activo
  → juegos = 20 (sin fila 3)
  → juegos:export → docs/juegos.json (20)
  → cp → taquilla/src/data/juegos.json (byte-idéntico)
  → check-pure: 20, sin terminal-activo, porTipo 11/9, porFamilia 11/7/2
  → ScraperSourceResolver deja de emitir la fuente lottoactivo-terminal_activo
```

## Cambios por fase

### Fase 1 — fronts + release (backend sin cambios)

| Archivo | Acción | Cambio exacto |
|---------|--------|---------------|
| `taquilla/src/pages/dashboard.astro` | Modificar | `ordenTabs()` (1570) devuelve `['animalitos','tripletas'].filter(...)`; `labels` (1589) sin `terminales`; icono de tab (1611) queda `activeTab === 'animalitos' ? icono('dices') : icono('target')`. No tocar `TIPOS_VALIDOS`, `derivarFamilia`, el catálogo 21 ni las ramas legacy de ticket (2514) — siguen siendo alcanzables por tickets viejos. |
| `taquilla/src/pages/resultados.astro` | Modificar | Borrar solo el botón `data-tab="terminales"` (línea 20). El JS filtra por `activeTab` y no tiene ramas por tipo. |
| `panel/src/pages/resultados-loterias.astro` | Modificar | Borrar solo el botón `data-tab="terminales"` (línea 19). El comentario de la línea 141 se limpia en Fase 2. |
| `taquilla/package.json` | Modificar | `version: "1.1.0"` → `"1.2.0"`. |
| `CHANGELOG.md` (raíz) | Modificar | Entrada `[1.2.0]`: «Retiro de la pestaña Terminales; la jugada terminal se vende como modalidad del Trío Activo». |

Invariantes de Fase 1: catálogo bundled 21 y byte-idéntico a `docs/juegos.json`; backend con
`terminal-activo` activo; taquillas viejas siguen vendiendo terminal.

### Fase 2 — backend, migración y tests

| Archivo | Acción | Cambio exacto |
|---------|--------|---------------|
| `backend/database/migrations/2026_11_06_000001_retire_terminal_activo.php` | Crear | Borrado idempotente + guard de apuestas (ver abajo). |
| `backend/database/seeders/TerminalesSeeder.php` | Eliminar | — |
| `backend/database/seeders/DatabaseSeeder.php` | Modificar | Quitar `TerminalesSeeder::class` (línea 23). |
| `backend/app/Plugins/Juegos/Terminales.php` | Eliminar | — |
| `backend/app/Support/PremiosOficiales.php` | Modificar | Quitar la entrada `'terminal-activo'` (48-54), la constante `ESPEJO_EXTRA` (295-303) y su bucle (398-403); docblock «21 juegos» → 20. |
| `backend/app/Plugins/Scrapers/AnimalitosScraper.php` | Modificar | `['trio_activo','terminal_activo']` → `['trio_activo']` (101); quitar `'terminal-trio' => 'terminal-activo'` (132); ajustar el fallback `$gameName` que aún nombra «Terminal Activo» (70). |
| `backend/app/Services/ScraperSourceResolver.php` | Modificar | Quitar `KEY_TERMINAL_ACTIVO` (31); quitar la rama `terminal_activo` de `slugLottoactivo` (178-180); docblock (18). |
| `backend/app/Http/Controllers/Api/ResultadoController.php` | Modificar | Comentario 58: `animalitos/terminales` → `animalitos`. |
| `backend/app/Plugins/Contracts/JuegoMultiSorteoInterface.php` | Modificar | Comentario 10: quitar `Terminales` de la lista de plugins single-result. |
| `backend/database/seeders/TripleFacilSeeder.php` | Modificar | Comentario 66: «plugin Terminales» → «opciones numéricas 00-99». |
| `backend/app/Services/ComisionService.php` | Sin cambios | — |
| Migraciones históricas (`2026_07_31_000001`, `2026_07_31_000002`) | Sin cambios | Son historial; el slug se menciona pero no se edita. |

Migración (patrón no obvio: idempotencia + guard antes del cascade):

```php
$id = DB::table('juegos')->where('slug', 'terminal-activo')->value('id');
if ($id === null) {
    return; // idempotente: ya retirado
}
if (DB::table('apuestas')->where('juego_id', $id)->exists()) {
    throw new RuntimeException('terminal-activo tiene apuestas; abortado para no perder ventas.');
}
foreach (['juego_limites', 'juego_horarios', 'juego_opciones', 'plugin_juegos', 'resultados', 'juego_auditoria'] as $tabla) {
    DB::table($tabla)->where('juego_id', $id)->delete();
}
DB::table('juegos')->where('id', $id)->delete();
```

`down()` es no-op documentado (D4). `audit_events` (si #89 ya existe) es append-only y no se toca:
sus filas históricas pueden quedar apuntando a un `entity_id` retirado.

### Fase 2 — catálogo y logo

1. `cd backend && php artisan migrate` (borra la fila y dependientes).
2. `php artisan juegos:export` (20 juegos; ids 1,2,4…21).
3. `cp ../docs/juegos.json ../taquilla/src/data/juegos.json` — nunca editar a mano (cwd: `backend`).
4. Quitar `terminal-activo` de `taquilla/src/data/logos.json`, del objeto correspondiente en
   `taquilla/public/images/juegos/manifest.json` y borrar el asset `terminal-activo.webp`
   (el check de huérfanos de `check-pure` lo exige en el mismo PR que el catálogo 20).
5. `check-pure.mjs`: título y conteo 21→20; quitar `porTipo.terminales` y `porFamilia.terminal`;
   quitar `terminal-activo` de `familiaEsperada` y su assert de 100 opciones; `porId`/`porSlug`
   20; mover la búsqueda `"05"` (y el buffer) de `terminal-activo` a `trio-activo`; `conBase`
   20→19; comentario de `la-ricachona` 21→20. No tocar el assert byte-idéntico (889).

### Fase 2 — taquilla (limpieza del contrato)

| Archivo | Acción | Cambio exacto |
|---------|--------|---------------|
| `taquilla/src/utils/catalogo.ts` | Modificar | `TipoJuego` sin `terminales` (17); `TIPOS_VALIDOS` sin `terminales` (101); `derivarFamilia` sin la rama `terminales` (145); comentarios (5, 14, 102, 356). Conservar `FamiliaOpciones 'terminal'` y `claveNumerica`. |
| `taquilla/src/utils/ticket.ts` | Modificar | `TipoLineaTicket` sin `terminales` (14); borrar la rama de `formatearJugada` (83-85); comentarios (10). |
| `taquilla/src/utils/recibos.ts` | Modificar | Fallback de tipo (126): `'terminales'` → `'tripletas'` (el shape `{numero}` imprime igual: `#05`). |
| `taquilla/src/pages/historial.astro` | Modificar | Mismo fallback (347, 353). |
| `taquilla/src/pages/dashboard.astro` | Modificar | Quitar el bloque `familia === 'terminal'` (2348-2349), el fallback de payload `juegoType === 'terminales'` (2514-2516) y el `else { listo = true; }` de familia terminal (2005-2006); comentarios 1902 y 2525-2526. Conservar `DEFS.terminal` y la modalidad posicional. |
| `panel/src/pages/resultados-loterias.astro` | Modificar | Comentario 141 sin `terminales`. |
| `taquilla/scripts/check-pure.mjs` | Modificar | Quitar el assert de `formatearJugada({tipo:'terminales'})` (932); añadir un assert de rechazo de `tipo:'terminales'` al cargar catálogo (escenario «Tipo no soportado rechazado»). |
| `taquilla/package.json` | Modificar | `1.2.0` → `2.0.0` (ruptura del contrato de tipo). |
| `CHANGELOG.md` | Modificar | Entrada `[2.0.0]`: catálogo 20, sin tipo `terminales`, sin pestaña Terminales. |

### Fase 2 — specs y docs

- Deltas ya escritos en `openspec/changes/terminal-como-modalidad/specs/`; se fusionan al archivar.
- `docs/juegos.json` (generado en WU2) y su copia bundled.
- `backend/docs/juegos.md` (lista maestra del spec `catalogo-juegos`): 20 juegos, sin
  `terminal-activo`, tipos `animalitos|tripletas` y nota de resolución de scraper sin
  `terminal_activo`.
- `docs/dev/motor-premios.md`, `arquitectura-backend.md`, `integracion-front-motor-premios.md`,
  `runbook-ops.md`, `pendientes-front.md`: 21→20 y sin `terminal-activo`.
- `docs/cliente/multiplicadores-juegos.md`, `premiacion-juegos.md`, `fuentes-oficiales.md`,
  `comparacion-juegos.md`, `glosario-premios.md`, `seguimiento-verificacion.md`,
  `inconsistencias.md`, `especificacion-requisitos-software.md`, `alcance-proyecto.md` (§11:
  DEC-09 resuelta, tope 25 % vía `tripletas`).
- Gate de cierre:
  `grep -rn "terminal-activo\|type.*terminales" taquilla/src panel/src backend/app backend/database/seeders backend/tests backend/docs docs` debe devolver 0 (excluye migraciones históricas y deltas).

### Tests backend a modificar

| Archivo | Cambio |
|---------|--------|
| `backend/tests/Unit/TerminalesPluginTest.php` | Eliminar. |
| `backend/tests/Unit/AnimalitosScraperTest.php` | Eliminar los 2 tests de `terminal_activo`, el `Juego::create` de `terminal-activo` en `setUp` y las fixtures `lottoactivo_terminal_activo_{page.html,response.json}`. |
| `backend/tests/Unit/ScraperSourceResolverTest.php` | Reescribir «trio y terminal son fuentes distintas» como «trio-activo es fuente propia distinta de animalitos»; fixtures con `type: 'terminales'` → `'bingo'` (tipos desconocidos). |
| `backend/tests/Unit/ScraperResolverTest.php` | `type: 'terminales'` → `'bingo'` (109). |
| `backend/tests/Unit/PremiosOficialesTest.php` | 21→20; quitar `terminal-activo` de la lista; eliminar `test_terminal_activo_base_60` y `test_config_para_terminal_activo_*`; renombrar el test de cobertura a 20. |
| `backend/tests/Unit/PremiosEngineTest.php` | Fixture `terminal-activo` → `trio-activo` (241); la modalidad `terminal` sobre `triple-zulia` (214) se conserva. |
| `backend/tests/Feature/JuegosJsonTest.php` | `SLUGS_POR_ID` real con hueco en 3; 21→20; quitar asserts de `terminal-activo` (premio, opciones, modalidades); el assert de ids generados sigue siendo consecutivo. |
| `backend/tests/Feature/MotorPremiosRegresionTest.php` | Eliminar los 2 tests de `terminal-activo`. |
| `backend/tests/Feature/ResultadoAparicionesTest.php` | Eliminar el test de identidad por número de terminales; el test de historial vacío usa `lotto-activo`. |
| `backend/tests/Feature/VerificacionOriginalesTest.php` | Eliminar `test_terminal_activo_premio_60_del_reglamento`. |
| `backend/tests/Unit/ComisionServiceTest.php`, `Feature/{ComisionesApiTest,ComisionCierreTest,ComisionReporteTest,DivergenciaRolTest}.php` | Sustituir el fixture `type: 'terminales'` por `'bingo'` y renombrar los tests a «otros tipos sin tope»; el mapa de `topePorTipo` sigue afirmando `default = 100`. |

## Contratos

```ts
// Fase 2: contrato final del catálogo bundled
export type TipoJuego = 'animalitos' | 'tripletas';
const TIPOS_VALIDOS: readonly TipoJuego[] = ['animalitos', 'tripletas'];
// FamiliaOpciones conserva 'terminal': es la modalidad, no el tipo.
```

`cargarCatalogo` lanza `juegos[i]: "tipo" inválido` ante `terminales` (escenario de spec).

## Pruebas (las ejecuta el usuario; el agente no corre suites)

| WU | Comandos |
|----|----------|
| WU1 — Fase 1 fronts | `cd taquilla && npm run build` · `node scripts/check-pure.mjs` · `pnpm e2e` (si el harness y la BD están disponibles) |
| WU2 — backend + migración + catálogo | `cd backend && php artisan migrate` · `php artisan juegos:export` · `cp ../docs/juegos.json ../taquilla/src/data/juegos.json` · `php artisan test --filter='JuegosJsonTest\|TerminalesPluginTest\|AnimalitosScraperTest\|ScraperSourceResolverTest\|PremiosOficialesTest\|PremiosEngineTest\|ComisionServiceTest\|ComisionesApiTest\|ComisionCierreTest\|ComisionReporteTest\|DivergenciaRolTest\|MotorPremiosRegresionTest\|ResultadoAparicionesTest\|VerificacionOriginalesTest'` · `composer test` · `cd ../taquilla && node scripts/check-pure.mjs` |
| WU3 — limpieza taquilla + docs | `cd taquilla && npm run build` · `node scripts/check-pure.mjs` · `pnpm e2e` |

`TerminalesPluginTest` queda en el filtro de WU2 porque se elimina en ese WU; el filtro no falla
si no encuentra esa clase. `ScraperResolverTest` se cubre con `composer test`.

## Threat Matrix

N/A — no hay routing, shell, subprocesos, automatización VCS/PR, clasificación de ejecutables ni
integración de procesos. Es retiro de datos y limpieza de UI/contrato.

## Work units (cadena de PRs)

Rama tracker: `feat/terminal-como-modalidad`. PR1 → tracker; PR2 → rama de PR1; PR3 → rama de PR2
(feature-branch chain).

| WU | Alcance | Líneas autorales estimadas |
|----|---------|----------------------------|
| WU1 | Fase 1: 3 fronts + `package.json` + CHANGELOG | 150–250 |
| WU2 | Fase 2 backend: migración, seeder/plugin/premios/scraper/resolver/comentarios, tests/fixtures, catálogo 20 + copia + logo + asserts de conteo de `check-pure` | 550–750 (el catálogo generado no cuenta) |
| WU3 | Fase 2 taquilla: `TipoJuego`/tipos, `ticket`/`recibos`/`historial`/`dashboard`, assert de tipo inválido, `2.0.0` + CHANGELOG, docs | 200–300 |

Cada WU termina con commit local siguiendo `work-unit-commits` y handoff de pruebas al usuario;
el agente no ejecuta builds, tests ni e2e. Los artefactos SDD (`openspec/**/*.md`) están bajo la
regla `*.md` de `.gitignore`: cada commit debe añadirlos con `git add -f` (o ampliar la excepción
en `.gitignore`) para que `design.md`/`tasks.md` no queden fuera del PR. WU2 es el de mayor riesgo de presupuesto: si el censo
real supera 800 líneas autorales, se parte en WU2a (runtime+migración) y WU2b (tests/fixtures),
manteniendo el catálogo con WU2a.

## Rollout y rollback

- **Fase 1**: publicar `Taquilla-Setup-1.2.0.exe` + `latest.yml` con
  `php artisan releases:publish <exe> --latest-yml=<yml>` (valida versión y sha512; una sola fila).
  Verificar manualmente la taquilla activa (una sola) sin pestaña Terminales. El backend sigue
  aceptando terminal.
- **Ventana de adopción**: confirmar que la taquilla activa corre ≥ `1.2.0` antes de Fase 2. Sin
  telemetría, la confirmación es operativa (una sola taquilla).
- **Fase 2**: desplegar backend (migración + scheduler restart para reconstruir la agenda de
  fuentes) y luego publicar `2.0.0`. Backend-first no rompe a `1.2.0` porque la pestaña ya no
  existe y el resto del catálogo no cambia de ids.
- **Rollback Fase 1**: `git revert` del WU y publicar `1.2.1` con la pestaña restaurada
  (electron-updater no baja versiones).
- **Rollback Fase 2**: `git revert` de WU2/WU3, restaurar `TerminalesSeeder` y ejecutar
  `php artisan db:seed --class=TerminalesSeeder`; regenerar el catálogo 21 desde git. La
  migración no se revierte (D4); en local, `migrate:fresh --seed` sobre el commit anterior.
- **Verificación local de la migración**: `php artisan migrate` (up) y
  `php artisan migrate:rollback --step=1` (solo retira el registro; no restaura datos, por D4).

## Riesgos

| Riesgo | Mitigación |
|--------|------------|
| Drift del catálogo byte-idéntico | Exportar y copiar con `cp`; nunca editar el JSON; `check-pure:889` en CI. |
| Sin telemetría de versiones | Una sola taquilla activa + confirmación manual; Fase 2 solo tras adopción de `1.2.0`. |
| Colisión de timestamp con #89 | Usar `2026_11_06_000001`; no `000003`; rebase del tracker antes de WU2. |
| Taquilla stale tras Fase 2 | Backend retira el juego solo después de la ventana; un cliente `1.1.x` fallaría al vender terminal (mitigado por la pestaña eliminada en `1.2.0`). |
| WIP de main y ramas largas (be04, 84, 85, 89) | Rebase del tracker antes de WU2 y regenerar el catálogo después del rebase; resolver conflictos de JSON solo con `juegos:export`. |
| Cambio de tope de comisión (100→25) | DEC-09 resuelta; `ComisionService` intacto; docs §11 y tests de comisiones actualizados a `bingo` para «otros tipos». |
| Agenda de scrapers congelada | Reiniciar el scheduler tras el deploy para que `sources()` reconstruya sin `terminal_activo`. |
| Hueco de id 3 | Aceptado; `SLUGS_POR_ID` lo documenta; no renumerar. |
| Filas de `audit_events` apuntando al juego retirado | Append-only: no se borran; el `entity_id` huérfano es esperado. |

## Preguntas abiertas

Ninguna bloqueante. La única decisión operativa (inicio de la ventana de adopción) se resuelve al
confirmar la versión de la taquilla activa antes de Fase 2.
