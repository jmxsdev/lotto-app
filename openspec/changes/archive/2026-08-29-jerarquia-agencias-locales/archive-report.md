# Archive Report: jerarquia-agencias-locales

- **Cambio**: jerarquia-agencias-locales
- **Fecha de cierre**: 2026-08-29
- **Rama final**: `feat/jerarquia-agencias-f5` (ciclo completo F0→F5 + 4 work units adicionales del PR 6; sin push aún)
- **Modo de archive**: hybrid (openspec + engram), `artifact_store.mode = both`
- **Veredicto de archive**: SUCCESS — ciclo SDD completo cerrado

## Resumen del cambio

Jerarquía real de 6 niveles (`super_master → master → banca → grupo → agencia → taquilla`), donde **AGENCIA = local físico** (nuevo nivel intermedio entre grupo y taquilla, solo identidad / passthrough: no configura monedas/vigencia/tiempo/límites) y **TAQUILLA = máquina** que genera tickets (entidad actual). El **master (super banca)** queda acotado a sus propias bancas vía `bancas.master_id` en lugar de ser global; el rol **agencia** entra al panel (crea taquillas solo en su local, opera en su scope); el borrado de un local aplica **cascada** (soft-delete de taquillas conservando `agencia_id`, desactivación de usuarios rol taquilla, historial intacto).

Decisiones de negocio cerradas por el cliente: (1) agencia = local, taquilla = máquina; (2) agencia passthrough sin configuración propia; (3) super banca acotada a sus bancas; (4) rol agencia en el panel, rol taquilla solo app de escritorio; (5) una taquilla SIEMPRE tiene local asignado (`agencia_id` obligatorio en API/UI); (6) borrado en cascada del local.

## Estado final de cada artifact

| Artifact | Estado final al cierre | Fuente |
|---|---|---|
| `explore.md` | Exploración completa: 6 niveles, decisiones de negocio del cliente | `openspec/changes/archive/2026-08-29-jerarquia-agencias-locales/explore.md` |
| `proposal.md` | Propuesta con intent, scope (in/out), 4 decisiones cerradas, 4 capabilities nuevas, approach F0→F5, riesgos, rollback y criterios de éxito | `.../proposal.md` |
| `specs/jerarquia-agencias/spec.md` | 6 requirements / 16 escenarios (entidad agencia, asociación taquilla/usuario, rol en ambas fuentes, cadena de activación, backfill idempotente, actualización de tests). R2 actualizada a **borrado en cascada** (decisión cliente) | `.../specs/jerarquia-agencias/spec.md` |
| `specs/alcance-super-banca/spec.md` | 5 requirements / 11 escenarios (master↔banca, alcance master en entidades/reportes/apuestas/cierres/límites, login X-Panel admite agencia) | `.../specs/alcance-super-banca/spec.md` |
| `specs/reportes-agencia/spec.md` | 6 requirements / 10 escenarios (ventas/cuadre/rendimiento por local, semántica de labels Agencia=local/Taquilla=máquina, sin configuración propia, actualización de tests) | `.../specs/reportes-agencia/spec.md` |
| `specs/panel-jerarquia/spec.md` | 6 requirements / 12 escenarios (renames, sidebar por rol, login/payload con agencia_id, creación de taquillas por agencia, selectores, terminología) | `.../specs/panel-jerarquia/spec.md` |
| `design.md` | Enfoque A (tabla mínima nullable), 7 decisiones D1–D7, data flow, file changes, interfaces/contratos, testing strategy, rollout F0→F5 y rollback | `.../design.md` |
| `tasks.md` | **34/34 tareas `[x]`** (0 sin marcar al cierre), forecast de workload con chained PRs (auto-chain, feature-branch-chain) | `.../tasks.md` |
| `apply-progress.md` | Ledger completo de work units (ver sección Work Units): PR 1→PR 6 + correcciones de auditoría + correcciones front create mode + panel masters + fix warning destroy + checklist de despliegue | `.../apply-progress.md` |
| `verify-report.md` | Snapshot intermedio `PASS WITH WARNINGS` (338/336/2, 0 CRITICAL, 1 WARNING, 3 SUGGESTION) — el WARNING 1 quedó **RESUELTO después** por el work unit FIX WARNING DESTROY AGENCIA (ver Final-State Authority) | `.../verify-report.md` |

Total: 10 artifacts archivados (proposal, 4 delta specs, design, tasks, apply-progress, verify-report, explore).

## Final-State Authority (estado al cierre vs. snapshots intermedios)

- **Suite final**: **343 tests / 341 passed / 2 skipped / 1322 assertions**, `./vendor/bin/pint --test` limpio, panel `npm run build` → **23 páginas**. Fuente: apply-progress (FIX WARNING DESTROY AGENCIA, evidencia post-fix) + launch prompt del orchestrator (cuenta más reciente del cambio). Los 2 skipped son pre-existentes y ajenos al cambio (`PluginIntegrationTest`, `ScrapeResultsJobTest`).
- **verify-report (snapshot, escrito antes del fix)**: reporta 338/336/2 con veredicto `PASS WITH WARNINGS` y 1 WARNING: `AgenciaController::destroy` dejaba taquillas con `agencia_id = null`. Ese WARNING se **resolvió después de la verificación** (commit `a010c9c` + `ab9ddbf`): borrado en cascada autorizado por el cliente, cubierto por `AgenciaDestroyCascadaTest` (5 tests) y con la spec R2 actualizada a "Borrado en cascada del local". Según la jerarquía de autoridad final, el estado AL CIERRE es el de la suite post-fix (343/341/2, sin warning pendiente). El snapshot no se re-emitió; su claim de WARNING quedó obsoleto por evidencia posterior (apply-progress + spec actualizada + commits).
- **0 CRITICAL** en todo el ciclo (verify-report: `critical_findings: 0`, `blockers: 0`) — no hubo bloqueo de archive.
- **Contradicciones no resueltas**: ninguna. Todos los números finales coinciden entre launch prompt y apply-progress post-fix.

## Suite final (detalle)

| Check | Resultado al cierre |
|---|---|
| `COMPOSER_PROCESS_TIMEOUT=900 composer test` | 343 tests / 341 passed / 2 skipped / 1322 assertions |
| `./vendor/bin/pint --test` | passed (limpio) |
| `npm run build` (panel) | 23 páginas OK (incluye `/agencias`, `/agencias/detalle`, `/masters`) |
| Tests enfocados por spec (verify, 10 archivos clave) | 91/91 (411 assertions) |
| Escenarios de spec | 49/49 compliant (48 con test permanente + 1 con evidencia runtime de sonda descartable) |
| Requirements | 23/23 (4 specs) |
| Cobertura | No configurada (el proyecto verifica por escenarios) |

## Work units del ledger (apply-progress)

| # | Work unit | Fase / PR | Tareas | Evidencia suite |
|---|---|---|---|---|
| 1 | Fundación de datos | PR 1 (F0), rama `feat/jerarquia-agencias-f0` | 9/9 | 227 tests (9 nuevos) |
| 2 | Alcance agencia | PR 2 (F1), rama `feat/jerarquia-agencias-f1` | 10/10 | 273 tests (42 nuevos) |
| 3 | Super banca (master scope) | PR 3 (F2), rama `feat/jerarquia-agencias-f2` | 4/4 | 285 tests (12 nuevos) |
| 4 | Reportes por local | PR 4 (F3), rama `feat/jerarquia-agencias-f3` | 3/3 | 293 tests (8 nuevos) |
| 5 | Panel | PR 5 (F4), rama `feat/jerarquia-agencias-f4` | 5/5 | 296 tests (3 nuevos) |
| 6 | Endurecimiento (terminología) | PR 6 (F5), rama `feat/jerarquia-agencias-f5` | 3/3 | 302 tests (6 nuevos) |
| 7 | Correcciones de auditoría (4 fixes: master scope en CRUD agencias, consistencia grupo-local, master_id valida rol, taquilla siempre con local) | PR 6 (work unit) | 4/4 | 323 tests (21 nuevos) |
| 8 | Correcciones front create mode (límites persistidos al crear, pestañas ocultas sin entidad, encadenamiento grupo→local→taquilla) | PR 6 (work unit) | 3/3 objetivos | 330 tests (7 nuevos) + refactor `JuegoLimiteService` |
| 9 | Panel masters (página `/masters`, filtro `role=master`, guard jerárquico, desvinculación de bancas) | PR 6 (work unit) | — | 338 tests (8 nuevos) |
| 10 | FIX WARNING DESTROY AGENCIA (borrado en cascada + trazabilidad `withTrashed()` en reportes) | PR 6 (work unit) | — | **343 tests (5 nuevos)** — suite final |

Cadena de ramas: `feat/jerarquia-agencias-f0 → f1 → f2 → f3 → f4 → f5` (feature-branch-chain, `auto-chain`). Sin PRs abiertos al cierre; el push del ciclo lo coordina el orquestador.

## Pendientes operativos (NO parte del archive; requieren acción del cliente en el despliegue)

1. **Backfill de producción** (NO ejecutado; solo verificado en BD de desarrollo):
   - En el VPS, dentro del backend: `php artisan migrate` → `php artisan agencias:backfill --dry-run` (opcional recomendado) → `php artisan agencias:backfill --force` → 2ª ejecución debe reportar 0 creaciones/0 asignaciones (idempotente).
2. **Checklist del cliente post-backfill**:
   - Renombrar los locales provisionales (`{grupo} - Local`, código `{grupo.code}-L01`) en el panel (Agencias → editar) con el nombre real del punto de venta y datos fiscales.
   - Verificar `bancas.master_id` en cada banca (panel, columna Master); el backfill asigna `master_id = created_by` solo si el creador es rol master, de lo contrario asignar manualmente.
   - Verificar el alcance de roles (agencia ve solo su local; master solo sus bancas; taquilla app de escritorio sin cambios).
   - Registrar si algún local necesitará configuración propia (D6 posterior — en esta iteración la agencia es passthrough).

## Riesgos documentados (heredados, no bloqueantes)

- Usuarios rol **agencia** cuyo local fue borrado quedan con `agencia_id` apuntando a un local soft-deleted: no ingresan al panel (403 en login) pero su registro se conserva; destino pendiente para la fase de endurecimiento NOT NULL (apply-progress FIX WARNING DESTROY AGENCIA).
- El alcance jerárquico con `whereHas` excluye taquillas soft-deleted en listados/reportes de banca/grupo/master (solo `super_master` global las ve; datos en BD, auditables). Mejora futura: `withTrashed()` en el alcance.
- `backend/.env.example` (credenciales dev) y `panel/.astro/settings.json` (timestamp Astro) modificados en el working tree, pre-existentes — NO commitear en el push del ciclo.
- `openspec/config.yaml` y `.atl/` están untracked en el working tree; `verify-report.md` y los nuevos `openspec/specs/*` quedan fuera de git por `.gitignore` (`*.md`) — requieren `git add -f` si se desean trackear.

## Verificación mecánica del archive (evidencia)

- **Sync de delta specs** (copia mecánica, nunca Read→Write): 4 dominios creados en `openspec/specs/` (`jerarquia-agencias`, `alcance-super-banca`, `reportes-agencia`, `panel-jerarquia`) vía `cp` + `diff -r` por archivo (vacío) + `mv`. Readback global: `diff -r openspec/changes/jerarquia-agencias-locales/specs openspec/specs` → **vacío** (byte a byte).
- **Move a archive**: snapshot recursivo previo (`cp -R`) → `git mv openspec/changes/jerarquia-agencias-locales openspec/changes/archive/2026-08-29-jerarquia-agencias-locales` (éxito) → source eliminado verificado → readback `diff -r snapshot vs archive` → **vacío**.
- **Task Completion Gate**: 34/34 tareas `[x]` en el `tasks.md` archivado (0 sin marcar); sin necesidad de reconciliación excepcional.
- **Native Review Receipt Gate**: `reviewGate` estructuralmente ausente en el status del launch — archive bajo política ordinaria de repositorio (sin receipt que leer).
- **Gate de CRITICAL**: 0 CRITICAL en verify-report → archive sin bloqueo.
- `archive-report.md` (este archivo) es aditivo y quedó excluido del diff (no existía en el snapshot).

## Trazabilidad

- Fuentes primarias leídas (filesystem, rama `feat/jerarquia-agencias-f5`): `openspec/changes/archive/2026-08-29-jerarquia-agencias-locales/{explore,proposal,design,tasks,apply-progress,verify-report}.md` y las 4 delta specs.
- Topics Engram paralelos (previews leídos vía `mem_context`/`mem_search`): `#15` explore, `#16` proposal, `#17` spec, `#19` design, `#20` tasks, `#21` apply-progress, `#26` apply-progress-correcciones, `#27` apply-progress-correcciones-front, `#29` apply-progress-panel-masters, `#30` verify-report, `#31` apply-progress-fix-destroy.
- Este archive-report: topic Engram `sdd/jerarquia-agencias-locales/archive-report` + `openspec/changes/archive/2026-08-29-jerarquia-agencias-locales/archive-report.md`.

## Ciclo SDD completo

El cambio fue planeado (propose/spec/design/tasks), implementado (apply, 34/34 tareas en 10 work units), verificado (verify, suite final 343/341/2, Pint limpio, panel 23 páginas, 49/49 escenarios) y archivado. Las 4 delta specs quedaron sincronizadas como specs principales (source of truth) en `openspec/specs/`. Listo para el siguiente cambio.