# Archive Report — configuracion-juegos

**Fecha de archivo**: 2026-09-28
**Artefacto store**: hybrid (openspec + engram)
**Worktree**: `/home/gzuz/Documentos/lotto-app-worktrees/configuracion-juegos` · Rama `feat/configuracion-juegos-s4` @ `676d422` (contiene la cadena completa S1a→S4; commit del verify-report)
**Veredicto del verify**: **PASS WITH WARNINGS** (0 CRITICAL / 1 WARNING / 4 SUGGESTION)
**Ledger `gentle-ai sdd-attempt`**: estado final `complete` (verificado en runtime: `complete: true`, `decision_required: false`, `next_action: complete`)
**Merge a main**: **PENDIENTE por decisión del usuario** — la cadena de ramas vive en el worktree, NO merged, NO pusheada (regla de la feature-branch-chain; los PR los abre el orchestrator). Este archive no mergea ni hace push (restricción del encargo).

---

## Resumen del cambio

Edición de premios de un juego desde el panel con **snapshot no retroactivo**:

- **Endpoint dedicado** `PUT /api/v1/juegos/{juego}/premios` (roles `super_master|master`): el body ES el objeto `premios` completo; reemplazo atómico de `config.premios` con merge de alto nivel sobre `config` (preserva `scraper`/`modalidades_permitidas`), sincronización de espejos legacy vía `PremiosOficiales::espejosLegacy()` (fuente única; `configPara()` delega) y auditoría `JuegoAuditoria` (`accion=premios`, `before/after`, `updated_by`).
- **Validación del payload**: `base required|integer|min:1`; claves de modalidad válidas en `plugin->obtenerModalidades()` ∪ catálogo oficial (sin bloquear claves canónicas no listadas por el plugin); `comodines` con `tipo in:flag,letra,numero,palabra` y `acumulativo` solo con `tipo=palabra`; `la-ricachona` → 422.
- **Snapshot de premios por apuesta**: columna `detalle_apuestas.premios_snapshot` (JSON nullable, poblada en `createApuesta`); `PremiosEngine` (calcular/premioPosible/multiplicadorPara/multiplicadorConComodines) y `JuegoPluginManager::calcularPremio` aceptan `?array $premios = null` (override de config; null → config actual); `verificarGanadores` con `with('detalles')` y `PagoController::calcularPremio` resuelven contra el snapshot con fallback legacy.
- **Fix P1 del toggle**: el panel envía `{active: bool}` en `PATCH /juegos/{id}/toggle` con error visible (sin catch silencioso); backend valida `active|boolean` (422), persiste y audita.
- **Editor P2 en el panel** (`panel/src/pages/juegos.astro`): base/modalidades/comodines, errores 422 junto al campo, vista de auditoría before/after; `vendible` = espejo de solo lectura de `active` (sin columna, D5).
- **Re-export del catálogo** por comando (`juegos:export --path`), sin side effect HTTP; nota de coordinación de la copia bundled de la taquilla en `docs/motor-premios.md` §9.1.

Slices implementados: **S1a** (5/5) + **S1b** (3/3) + **S2** (6/6) + **S3** (5/5) + **S4** (3/3) = **22/22 tareas completas**.

## Veredicto del verify (estado FINAL al cierre)

Fuente: hechos de estado final del orquestador (prioridad sobre snapshots intermedios) + `verify-report.md` (commit `676d422`, observación Engram #427, envelope `gentle-ai.verify-result/v1` `verdict: pass_with_warnings`). Los números que siguen son los del cierre, no los de snapshots intermedios.

| Métrica | Valor final |
|---------|-------------|
| Verdict | `pass_with_warnings` (envelope validado: `requirements 10/10`, `scenarios 30/30`) |
| Blockers | 0 |
| CRITICAL findings | 0 |
| Suite completa | **1080 passed / 2 skipped / 0 failed** — 1082 tests, 4987 aserciones (`DB_DATABASE=lotto_test_motor php artisan test`, exit 0; 2 skipped preexistentes: `PluginIntegrationTest` y `ScrapeResultsJobTest`, ambos con `markTestSkipped` condicional) |
| Focused | **146/146 passed** — 1160 aserciones (`--filter='JuegoPremiosApiTest\|PremioSnapshotTest\|JuegoToggleTest\|JuegoUpdateTest\|MotorPremiosRegresionTest\|ModalidadesSingleDrawTest\|VerificarGanadoresTest\|JuegosJsonTest\|PremiosEngineTest\|PremiosOficialesTest'`) |
| Pint | `vendor/bin/pint --test` → passed |
| Build panel | `pnpm run build` → 25 páginas, exit 0 |
| Coverage | No disponible (sin tool de cobertura configurada en el repo; no es fallo) |
| Spec compliance | 10 REQ / 30 escenarios mapeados — **30/30 COMPLIANT** (configuracion-premios 8/8 con 23 escenarios; motor-premios delta 2/2 con 7 escenarios). 0 FAILING, 0 UNTESTED |
| TDD compliance | 6/6 checks (RED/GREEN confirmados, triangulación, safety net) |

**Defecto de producción destapado y corregido en S3**: `JuegoController::toggle()` no reactivaba el plugin al reactivar un juego — usaba la relación filtrada `$juego->pluginJuego` (que filtra `where('active', true)`, devolviendo null para plugins inactivos). Fix: `$juego->pluginJuegos()->update(...)` (hasMany sin filtro). Evidencia: RED 6/7 → GREEN 7/7 en `JuegoToggleTest` (commits `5f67077` + `09abcfa`).

### Findings (0 CRITICAL · 1 WARNING · 4 SUGGESTION)

**WARNING (1)** — `panel/` NO tiene runner de tests de UI (hecho conocido y autorizado por el design del cambio: verificación por build + lectura del bundle compilado). La evidencia runtime del panel es estática → **smoke manual del editor obligatorio antes del merge a main**.

**SUGGESTION (4)**:
1. `PagoController::store` usa `detalles->first()` para el snapshot: si una apuesta tuviera varios detalles con snapshots distintos, solo se considera uno (hoy `createApuesta` crea exactamente un detalle por apuesta — invariante vigente); un guard explícito lo haría más robusto.
2. Suite completa ~49,6 min (RefreshDatabase + DatabaseSeeder por test): considerar `ParallelTesting` o RefreshDatabase transaccional en futuros ciclos.
3. `vendible` = espejo de `active` (D5): un tooltip/hint en la tabla general ahorraría confusión de operadores.
4. Cobertura de `motor-premios` en la matriz: los escenarios no cambiados (acentos, terminales, comodines, redondeo, estados) siguen cubiertos por `MotorPremiosRegresionTest` 30/30 y `ModalidadesSingleDrawTest` 21/21 — verificado, sin acción requerida.

## Task Completion Gate

`tasks.md` archivado: **22/22 casillas `[x]`** (1.1–1.5, 2.1–2.3, 3.1–3.6, 4.1–4.5, 5.1–5.3) — sin tareas de implementación sin marcar. La observación Engram #421 (`sdd/configuracion-juegos/tasks`) coincide: 22/22. El verify-report confirma las 22 tareas contra el código real (no solo checkboxes). Gate PASS sin reconciliación excepcional.

## Review Gate

Sin artefactos de review para este candidato (no existen topics `sdd/configuracion-juegos/review/*` en Engram ni carpeta `reviews/` en el change): `reviewGate` estructuralmente ausente (RDD off por defecto, precedente #384) → archive procede bajo política ordinaria.

## Specs sincronizadas a `openspec/specs/`

| Capability | Acción | Detalle |
|------------|--------|---------|
| configuracion-premios | **Creada** (capability NUEVA) | Copia mecánica (`cp` + `diff -r` vacío) desde `openspec/changes/configuracion-juegos/specs/configuracion-premios/spec.md` → `openspec/specs/configuracion-premios/spec.md` (8 requisitos, 23 escenarios) |
| motor-premios | **Actualizada** (spec existente) | Delta `MODIFIED`: 2 requisitos reemplazados por su versión con snapshot — "Premios config-driven por juego" (+2 escenarios: "Liquidación contra snapshot", "Fallback legacy sin snapshot") y "Pago validado contra el motor corregido" (+2 escenarios: "Pago contra snapshot", "Pago con fallback legacy"). Los otros 12 requisitos preservados intactos |

**Decisión de merge documentada**: las anotaciones `(Previously: …)` del delta (bookkeeping del cambio) NO se copiaron al spec principal — los specs principales vigentes no contienen esa anotación (verificado por grep en `openspec/specs/`); el delta completo queda verbatim en la carpeta archivada para el audit trail.

Sin requisitos REMOVED → sin merge destructivo (no aplica aviso de `rules.archive`). `openspec/config.yaml` no está presente en este worktree; no hay reglas de archive que aplicar.

## Movimiento a archive

`openspec/changes/configuracion-juegos/` → `openspec/changes/archive/2026-09-28-configuracion-juegos/` vía `git mv` (8 archivos trackeados). Readback mecánico obligatorio: `diff -r` snapshot-pre-move vs tree archivado → **vacío (sin diferencias)**, única evidencia de byte-identity. `archive-report.md` es aditivo y quedó excluido de la comparación. El directorio `openspec/changes/configuracion-juegos/` ya no existe (verificado tras el move).

## Trazabilidad Engram (observaciones leídas)

| Artefacto | Observación Engram |
|-----------|--------------------|
| proposal | #403 (`sdd/configuracion-juegos/proposal`) |
| spec (delta specs) | #419 (`sdd/configuracion-juegos/spec`) |
| design | #420 (`sdd/configuracion-juegos/design`) |
| tasks | #421 (`sdd/configuracion-juegos/tasks`, 22/22) |
| apply-progress | #422 (`sdd/configuracion-juegos/apply-progress`) |
| verify-report | #427 (`sdd/configuracion-juegos/verify-report`) |
| archive-report (este artefacto) | `sdd/configuracion-juegos/archive-report` |

## Contenido del archive

- proposal.md ✅
- specs/configuracion-premios/spec.md ✅ (deltas verbatim)
- specs/motor-premios/spec.md ✅ (deltas verbatim)
- design.md ✅
- tasks.md ✅ (22/22)
- apply-progress.md ✅ (slices S1a–S4)
- explore.md ✅ (input de propose)
- verify-report.md ✅ (envelope `pass_with_warnings`)
- archive-report.md ✅ (este archivo, aditivo)

## Pendientes post-archive

1. **Merge a main PENDIENTE por decisión del usuario**: cadena `feat/configuracion-juegos` → `-s1a` → `-s1b` → `-s2` → `-s3` → `-s4` (feature-branch-chain, sin push; los PR los abre el orchestrator).
2. **Antes/tras cerrar a main**: smoke manual del editor (abrir `/juegos`, clic en fila, editar base/modalidades/comodines, guardar, ver errores 422 junto al campo y auditoría before/after nueva) — obligatorio por WARNING 1.
3. **Al cerrar a main**: `php artisan juegos:export` y coordinar la copia bundled `docs/juegos.json` → `taquilla/src/data/juegos.json` (nota en `docs/motor-premios.md` §9.1; `taquilla/` no se toca — otro agente).
4. SUGGESTION 1: guard explícito en `PagoController::store` para `detalles->first()` ante futuros multi-detalle.
5. SUGGESTION 2: `ParallelTesting`/RefreshDatabase transaccional para acortar la suite (~50 min).