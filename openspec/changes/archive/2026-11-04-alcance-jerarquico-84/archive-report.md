# Archive Report — `alcance-jerarquico-84`

**Change**: `alcance-jerarquico-84` · Issue #84
**Archived**: 2026-11-04
**Archived to**: `openspec/changes/archive/2026-11-04-alcance-jerarquico-84/`
**Rama del ciclo**: `feat/84d-limites-agencia-regresion` (base del ciclo: `0c4cc38`, archivo de #69)
**HEAD al archivar**: `684f602` (`fix(limites): valida cadena grupo/taquilla para master (remediacion CRITICAL-1)`), árbol limpio
**Estado final**: **PASS WITH WARNINGS** — 0 CRITICAL, 3 WARNING, 46/46 tareas, 13/13 requisitos, 38/38 escenarios.

> Este informe es el registro terminal del ciclo. `apply-progress.md` (Engram obs #766) y `verify-report.md` (Engram obs #767) son snapshots intermedios: describen el estado en el momento en que se escribieron. Aquí se reporta el estado al cierre, reconciliado con los commits, los specs sincronizados y la evidencia aportada por el maintainer.

---

## 1. Alcance cerrado

El change aplica el alcance jerárquico (`super_master → master → banca → grupo → agencia → taquilla`) de forma uniforme y cierra las brechas de fuga cross-tenant y de autorización insuficiente del issue #84. Es un cambio de **comportamiento y seguridad**, no un refactor: el contrato JSON de las APIs no cambia (mismos status y mensajes donde ya existían).

| Bloque | Caso(s) | Comportamiento al cierre |
|---|---|---|
| Usuarios y entidades | 1 + matriz CRUD | `UserPolicy` concentra `view/update/delete/canCreate/canAssign`; rangos 6→1; subárbol + `created_by`; pares y superiores prohibidos; `super_master` intocable; `master` no borra a su creador |
| Apuestas | 2 | `ApuestaPolicy::view/delete` acotan al master a sus bancas administradas (`masterCanAccessBanca`); sin bancas → denegado; `super_master` global |
| Pagos | 3 | `PagoController::store` autoriza con `Gate::denies('view', $apuesta)` dentro del `lockForUpdate` y antes del check de estado; banca/grupo/master cross-tenant → 403 sin mutación; el 422 de tipo no-egreso sigue precediendo |
| Tickets | 4 (matizado) + 5 (regresión) | `index/show/ganadores` con rama `master` vía `masterBancaChainScope`; sin bancas → vacío; ajeno → 403; `agencia` sigue fuera de `/tickets`; anulación sin líneas vivas solo cubierta con test de regresión (sin reimplementar) |
| Límites | 6 + 7 + remediación | `authorizeEscrituraLimite` valida cadena `grupo→banca` / `taquilla→grupo` para `banca` **y** `master` mediante `assertLimiteChainWithinBanca`; `updateLimites` autoriza antes de validar restrictividad (403 precede a 422); batch legacy autoriza por ítem |
| Agencia | matriz CRUD | La agencia edita su propia ficha (`name/rif/email/telefono/direccion/estado/municipio`); `code`/`grupo_id`/`active` → 403; `store/toggle/destroy` bloqueados; gestiona taquillas de su local |
| `users.created_by` | soporte de matriz | FK nullable autorreferente + índice, `nullOnDelete`; `creator()`/`createdUsers()`; asignación server-side en los 4 stores y en seeders; existentes quedan `null` |

**Fuera de alcance por diseño**: #85 (ScopeService/refactor de policies), BE-04 (catálogo de permisos, `permission:`, `Gate::before`), #82, #89 y reportes/descargas de taquilla.

---

## 2. Commits y rama

| WU | Contenido | Commits |
|---|---|---|
| WU-1a | `users.created_by`: migración, modelo, stores, seeders | `c51ad90` feat(usuarios): agrega created_by como metadata de jerarquia (WU-1a) · `cb4cef6` docs(sdd) |
| WU-1b | `UserPolicy` + autorización jerárquica en `UserController` | `8839bc6` feat(usuarios): agrega UserPolicy (WU-1b-1) · `f12151a` feat(usuarios): acota index y creación (WU-1b-2) · `b515f9c` docs(sdd) |
| WU-2 | `ApuestaPolicy` + `PagoController` + `TicketController` (casos 2/3/4) | `4d96f72` feat(apuestas): acota apuestas, pagos y tickets al master (WU-2) · `c3160f5` docs(sdd) |
| WU-3 | Límites + ficha propia de agencia + regresión caso 5 | `5474e9d` feat(limites): acota escritura de limites y ficha de agencia (WU-3) · `3078725` docs(sdd) |
| Cierre | Marcado de tareas (46/46) | `f68e7b4` docs(sdd): cierra tareas de alcance-jerarquico-84 |
| Remediación | CRITICAL-1: cadena coherencia para `master` en límites | `684f602` fix(limites): valida cadena grupo/taquilla para master (remediacion CRITICAL-1) |

Los commits `docs(sdd)` registran el avance de cada work unit. No hubo merge a `main` durante el ciclo: la rama queda lista para merge local (ver §9).

---

## 3. Evidencia de tests y estilo

**Todo lo siguiente fue ejecutado por el maintainer, no por el agente** (regla del ciclo: el agente no corre tests, migraciones ni artisan).

### Suite completa — PRE-remediación

| Evidencia | Resultado |
|---|---|
| `composer test` sobre `f68e7b4` | **1422 tests, 7996 assertions, 2 skipped, OK** |

Esta corrida es **previa** a `684f602` y **no se repitió después del fix**. Es la única evidencia de suite completa del ciclo.

### Evidencia POST-remediación — focalizada

| Evidencia | Resultado |
|---|---|
| `php artisan test --filter=LimitesEscrituraScopeTest` | **13 passed** (8 de banca + 5 de master) |
| `php artisan test --filter='LimitesScopedApiTest\|AgenciaScopeTest'` | **54 passed** (510 assertions) |
| `./vendor/bin/pint --test` | **passed** |

### Evidencia por work unit (reportada durante el ciclo)

| WU | Suites | Resultado |
|---|---|---|
| 1a | `CreatedByTest` | 7 OK |
| 1b | `AlcanceJerarquicoUsuariosTest` | 16 OK (ver SUGGESTION-4: el archivo en HEAD declara 20 métodos) |
| 2 | `ApuestaScopePolicyTest` + `PagoEgresoScopeTest` + `TicketMasterScopeTest` | 17 OK |
| 2 | regresión `SuperBancaScopeTest`/`PagoDevolucion*`/`EliminacionApuestasTest`/`ApuestaPolicyAnulacionTest`/`TicketGanadoresTest`/`TicketEliminacionTest` | 78 OK |
| 3 | `LimitesEscrituraScopeTest` + `AgenciaSelfUpdateTest` + `TicketAnulacionRegresionTest` + regresión | 54 OK |

**Alcance de la evidencia (limitación explícita)**: la confirmación formal de no-regresión fuera de límites requiere una corrida completa posterior a `684f602`, que no se ejecutó. El diff de la remediación está aislado a `JuegoController` (`authorizeEscrituraLimite`/`assertLimiteChainWithinBanca`) y a un archivo de tests; no toca modelos, migraciones, rutas ni servicios compartidos.

Los hashes del `verify-report` (`evidence_revision`, `test_output_hash`, `build_output_hash`) son hashes del **texto de evidencia suministrado**, no de stdout crudo, porque la fase prohíbe re-ejecutar la suite.

---

## 4. Specs sincronizados (fuente de verdad)

| Capability | Acción | Detalle |
|---|---|---|
| `alcance-jerarquico` | **Creada** `openspec/specs/alcance-jerarquico/spec.md` | 6 requisitos, 14 escenarios. Copia mecánica con `cp` del delta (que ya traía formato de spec completa: `# ... Specification` + `**Estado**: draft`); `diff -r` delta→destino vacío. |
| `pagos` | **Actualizada** `openspec/specs/pagos/spec.md` | +1 requisito `Egreso ownership por rol` (5 escenarios). 8 → 9 requisitos; los 8 previos intactos. |
| `alcance-super-banca` | **Actualizada** `openspec/specs/alcance-super-banca/spec.md` | +2 requisitos: `ApuestaPolicy::view/delete acotado para master` y `Alcance master en tickets` (8 escenarios). 5 → 7 requisitos. |
| `jerarquia-agencias` | **Actualizada** `openspec/specs/jerarquia-agencias/spec.md` | +2 requisitos: `Agencia edita su propia ficha` y `Agencia gestiona taquillas de su local` (5 escenarios). 6 → 8 requisitos. |
| `limites` | **Actualizada** `openspec/specs/limites/spec.md` | +2 requisitos: `Alcance de escritura de límites para banca` y `Autorización antes que validación de restrictividad` (6 escenarios). 8 → 10 requisitos. |

**Verificación de la fusión**: los bloques añadidos se compararon contra el delta con `diff` y quedaron **idénticos** en `pagos`, `alcance-super-banca` y `jerarquia-agencias`. En `limites` la única diferencia es una nota añadida al requisito de escritura de banca:

> **Nota (remediación CRITICAL-1, 2026-11-04)**: la misma validación de cadena se comparte con el rol `master` mediante `assertLimiteChainWithinBanca`. Un `master` MUST NOT persistir límites cuyo `grupo_id` o `taquilla_id` cuelguen de una banca que no administra (403), ni siquiera indicando un `banca_id` propio.

Esa nota documenta el comportamiento final verificado: el delta de `limites` se escribió antes de la remediación y solo nombraba a `banca`, mientras que `684f602` extendió la coherencia de cadena a `master`. Sin la nota, la fuente de verdad quedaría desactualizada respecto de lo que realmente shippeó.

**Sin duplicados**: `grep '^### Requirement:'` sobre los 5 specs no devuelve encabezados repetidos, y cada requisito de los deltas aparece exactamente una vez (13/13). Ningún requisito preexistente fue eliminado ni modificado.

---

## 5. Evidencia del movimiento a archivo

- Carpeta completa movida con `git mv` a `openspec/changes/archive/2026-11-04-alcance-jerarquico-84/`; el directorio activo ya no existe.
- Snapshot recursivo pre-movimiento comparado con el árbol archivado mediante `diff -r`: **salida vacía, exit 0**. Ningún artefacto fue alterado ni truncado.
- Contenido archivado: `proposal.md`, `design.md`, `tasks.md`, `verify-report.md`, `apply-progress.md`, `specs/{alcance-jerarquico,alcance-super-banca,jerarquia-agencias,limites,pagos}/spec.md` y este `archive-report.md` (aditivo, excluido de la comparación).
- `tasks.md` archivado: **46/46 marcadas, 0 sin marcar**.
- `openspec/changes/` ya no contiene `alcance-jerarquico-84`.

---

## 6. Warnings residuales

Los tres son **no bloqueantes** y provienen del `verify-report` final (obs #767); ninguno fue remediado después de la verificación.

**WARNING-1 — Alta de usuario por taquilla sin aserción dedicada.**
`AlcanceJerarquicoUsuariosTest` cubre `GET` propio (200), `GET` ajeno (403) y `PUT`/`DELETE` propio (403) para el rol `taquilla`, pero no un intento de alta (`POST /api/v1/users`), que la ruta permite y `UserPolicy::canCreate` deniega. El comportamiento está implementado; es una brecha de profundidad de aserción, no de implementación.

**WARNING-2 — Update/toggle/delete de taquilla ajena por agencia sin aserción dedicada.**
`AgenciaScopeTest` asserta `GET /taquillas/{ajena}` → 403 y el alta en local ajeno → 403, pero no `PUT`/`PATCH toggle`/`DELETE` sobre taquilla ajena. El guard compartido `authorizeTaquillaAccess` se usa en show/update/toggle/destroy, por lo que el comportamiento es consistente por inspección.

**WARNING-3 — Filas `juego_limites` preexistentes incoherentes y `destroyLimite` sin revalidar la cadena.**
`destroyLimite` solo valida `banca_id` para `master`. Una fila creada **antes** de `684f602` con `banca_id = A` y `grupo_id`/`taquilla_id` de la banca B sigue siendo borrable por el master de A: no permite **crear** filas nuevas, pero el borrado puede alterar la resolución efectiva (herencia) del tenant B. Además esas filas siguen visibles/aplicables hasta que se limpien. El vector de escritura está cerrado; el riesgo requiere datos creados por el bug previo y no es detectable solo con tests.

### Follow-ups

1. Agregar los tests de WARNING-1 y WARNING-2 para completar la profundidad de aserción (no bloquea el archivo).
2. Auditar/limpiar filas de `juego_limites` con cadena incoherente y evaluar el chequeo de coherencia en `destroyLimite` (WARNING-3).
3. Ejecutar la suite completa post-`684f602` y conservar el log crudo; reconfirmar el conteo enfocado de `AlcanceJerarquicoUsuariosTest` (SUGGESTION-4: 16 reportados vs 20 métodos en HEAD).
4. SUGGESTION-5: aplicar la coherencia de cadena también a `super_master` y devolver 422 (no 500) cuando el batch legacy omita `banca_id`.
5. SUGGESTION-1/2/3 (carga duplicada en `TicketController::show`, `agencia` en la allow-list de `autorizarScope`, rama de lectura con `whereNull('grupo_id')` + `whereHas('grupo')`) quedan para el refactor de #85.
6. **#85 y BE-04 van después**, sobre esta base: #85 consolida el scoping en un `ScopeService`/policies; BE-04 introduce `permission:`/`Gate::before`. Este change no los anticipa.

---

## 7. Nota de desviación: `tdd: true` vs ejecución del maintainer

`openspec/config.yaml` declara `apply.tdd: true`, pero la regla operativa de este ciclo establece que **el agente no ejecuta tests ni migraciones**: los comandos se dejan escritos y el maintainer los corre localmente (por costo y tiempo de la suite). En consecuencia:

- El ciclo fue **TDD en el orden de autoría** (tests escritos antes del código en cada WU), no en el sentido de RED→GREEN observado por el agente.
- La verificación se hizo con **evidencia aportada por el usuario**, no con stdout capturado por el agente; por eso los hashes del `verify-report` son del texto de evidencia y no de la salida cruda.
- `composer test` se corrió una sola vez al cierre (pre-remediación); la evidencia post-fix es focalizada.

La desviación es de proceso, no de producto, y queda registrada para que no se interprete el ciclo como un Strict TDD verificado por el agente.

---

## 8. Trazabilidad (Engram, proyecto `lotto-app`)

| Artefacto | Observación |
|---|---|
| proposal | #762 (`sdd/alcance-jerarquico-84/proposal`) |
| spec (5 deltas) | #763 (`sdd/alcance-jerarquico-84/spec`) |
| design | #764 (`sdd/alcance-jerarquico-84/design`) |
| tasks | #765 (`sdd/alcance-jerarquico-84/tasks`) |
| apply-progress | #766 (`sdd/alcance-jerarquico-84/apply-progress`) |
| verify-report | #767 (`sdd/alcance-jerarquico-84/verify-report`) |
| archive-report | #768 (`sdd/alcance-jerarquico-84/archive-report`, este documento) |

No hay `reviewGate` para este candidato (RDD no habilitado): no existen topics de transaction/ledger/receipt/gate-context que validar.

---

## 9. Estado del ciclo

`sdd-propose` → `sdd-spec` → `sdd-design` → `sdd-tasks` → `sdd-apply` (WU-1a, WU-1b, WU-2, WU-3 + remediación) → `sdd-verify` (PASS WITH WARNINGS) → `sdd-archive` (este informe). El change está cerrado y `alcance-jerarquico` pasa a ser capability principal.

**Próximo paso**: merge **local** de `feat/84d-limites-agencia-regresion` a `main` (sin push desde esta fase). Los artefactos `openspec/**/*.md` están cubiertos por `*.md` en `.gitignore`, por lo que el cierre requiere `git add -f` del spec nuevo, del change archivado y de este informe.

**Archivo intencional con warnings**: respaldado por un `verify-report` válido (0 blockers, 0 CRITICAL, 13/13 requisitos, 38/38 escenarios), 46/46 tareas completas y la autorización explícita del orquestador para archivar con los 3 WARNING documentados en §6.
