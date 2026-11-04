# Archive Report — `scope-service-85`

**Change**: `scope-service-85` · Issue #85
**Archived**: 2026-11-04
**Archived to**: `openspec/changes/archive/2026-11-04-scope-service-85/`
**Rama del ciclo**: `feat/85-4c-juego-comision` (base del ciclo: `f364895`, `main`)
**HEAD al archivar**: `04b151b` (`docs(sdd): cierra criterios y re-verify de scope-service-85`), árbol limpio
**Estado final**: **PASS WITH WARNINGS** — 0 blockers, 0 CRITICAL, 6/6 requisitos, 7/7 escenarios, 53/53 tareas de fase y 7/7 criterios globales marcados.

> Este informe es el registro terminal del ciclo. `apply-progress.md` (Engram obs #773) y `verify-report.md` (Engram obs #775) son snapshots intermedios: describen el estado en el momento en que se escribieron. Aquí se reporta el estado al cierre, reconciliado con los commits, los specs sincronizados y la evidencia final aportada por el maintainer.

---

## 1. Alcance cerrado

El change extrae el scoping jerárquico del dominio a una capa única, `App\Support\Scope\AlcanceService`, y migra los controllers de `Api/` a delegar en él y en policies por modelo. Es un **refactor de arquitectura sin cambio de comportamiento**: no agrega endpoints, capacidades ni reglas de negocio, y los endpoints migrados preservan status, body y mensajes de denegación exactos.

| Bloque | Contenido al cierre |
|---|---|
| Fundación | `AlcanceService` (`final`) con `applyToApuestas`, `applyToTickets`, `applyToCierres`, `applyToUsuarios`, `applyToEntidades`, `alcanceDeEntidad`, `puedeGestionar`, `puedeOperarTaquilla`, `bancasDeMaster`, `entidadesVisibles`, `idsPorTipo`, `bancasParaComisiones`, `denegar`; constantes de precedencia por contrato |
| Policies | 6 policies nuevas (`Banca`, `Grupo`, `Agencia`, `Taquilla`, `Ticket`, `Cierre`) registradas explícitamente; `ApuestaPolicy` y `UserPolicy` delegando en el servicio |
| Controllers migrados | 12: `Estadistica`, `Reporte`, `Apuesta`, `Ticket`, `Banca`, `Agencia`, `Grupo`, `Taquilla`, `User`, `Comision`, `Cierre`, `Juego` (más `Pago`, que ya heredaba scope vía `ApuestaPolicy`) |
| Invariante de jerarquía | `masterBancaIds()` queda con 1 definición en `User`, 0 usos en `app/Http/Controllers` y un único consumidor externo (`AlcanceService`), verificado por `ArquitecturaAlcanceTest` |
| Denegación | `AlcanceService::denegar()` para decisiones de filas; la autorización de acción puntual conserva su `abort(403)`/`json(...,403)` local con status/body idénticos (REQ-5 enmendado en WU-4c) |
| Docs | `docs/dev/alcance-jerarquico.md` (modelo, precedencias por contexto, reglas, guía de migración, mecánica de denegación y excepciones deliberadas) enlazado desde `docs/dev/arquitectura-backend.md` §3.2 |

**Fuera de alcance por diseño**: corrección de endpoints con alcance faltante (#84, cerrado), unificación de `hasRole()` con `users.role` (#69, cerrado), middleware `CheckRole` muerto (#82), reparenting `taquilla → grupo` / `taquilla → agencia → grupo` (issue propio) y el catálogo de permisos de BE-04.

---

## 2. Commits y rama

Cadena local stacked-to-main; GitHub no se tocó durante el ciclo.

| WU | Contenido | Commits |
|---|---|---|
| Plan | Planificación del change | `44e43d4` docs(sdd): planifica scope-service-85 (#85) |
| 0a-1 | `AlcanceService` para listados base | `7a4c21b` refactor(scope): agrega AlcanceService para listados (WU-0a-1) |
| 0a-2 | Alcance de usuarios y entidades | `657eb09` refactor(scope): agrega alcance de usuarios y entidades (WU-0a-2) · `3720741` docs(sdd): divide WU-0a y difiere visibilidad por entidad |
| 0c | Visibilidad/gestión por entidad | `35d17fc` refactor(scope): agrega visibilidad y gestion por entidad (WU-0c) |
| 0b | 6 policies + registro | `125339f` refactor(scope): agrega policies de alcance (WU-0b) · `8516c73` docs(sdd): registra WU-0c y WU-0b |
| 1a | Estadística + Reporte | `cc25f8e` refactor(scope): migra Estadistica y Reporte al AlcanceService (WU-1a) · `df635a1` docs(sdd) |
| 1b | Apuesta + `ApuestaPolicy` | `076b668` refactor(scope): migra Apuesta y su policy al AlcanceService (WU-1b) · `12e179a` docs(sdd) |
| 1c | Ticket | `6b74f84` refactor(scope): migra TicketController al AlcanceService (WU-1c) · `1b53cea` docs(sdd) |
| 2a | Banca + Agencia | `c2a1ff3` refactor(scope): migra Banca y Agencia al AlcanceService (WU-2a) · `84bcf1d` docs(sdd) |
| 2b | Grupo | `56ca308` refactor(scope): migra GrupoController al AlcanceService (WU-2b) · `068003e` docs(sdd) |
| 2c | Taquilla | `106645b` refactor(scope): migra TaquillaController al AlcanceService (WU-2c) · `bfb0147` fix(scope): restringe gestion de taquilla a la agencia duena · `d8f24af` docs(sdd) |
| 3a | User | `4b9f4b1` refactor(scope): migra UserController al AlcanceService (WU-3a) · `9f48019` docs(sdd) |
| 3b | Comisión | `19a51e6` refactor(scope): usa AlcanceService en ComisionController (WU-3b) · `3719740` docs(sdd) |
| 4a | Cierre | `1fbf50b` refactor(scope): migra CierreController al AlcanceService (WU-4a) · `58ea53d` docs(sdd) |
| 4b | Juego (límites + visibilidad) | `c9b419f` refactor(scope): migra limites y visibilidad de Juego (WU-4b) · `040f503` docs(sdd) |
| 5 | Docs + `ArquitecturaAlcanceTest` + conteos | `e094724` refactor(scope): documenta alcance y agrega test de arquitectura (WU-5) · `61b29bc` docs(sdd): registra WU-5 y ajusta criterio masterBancaIds |
| Estilo | Pint sobre el test de paridad | `08509d9` style(scope): aplica Pint a AlcanceServiceScopeTest |
| 4c | Remediación verify: visibilidad Juego + Comisión | `1547adb` refactor(scope): mueve visibilidad de Juego y Comision al AlcanceService (WU-4c) · `a2a16a8` docs(sdd): enmienda REQ-5 y registra WU-4c · `1d0466f` test(scope): corrige expectativas de super_master con datos sembrados (WU-4c) · `aab0964` test(scope): incluye DEMO01 en expectativa de super_master |
| Cierre | Criterios globales + re-verify | `04b151b` docs(sdd): cierra criterios y re-verify de scope-service-85 |

No hubo merge a `main` durante el ciclo: la rama queda lista para merge local (ver §9).

---

## 3. Evidencia de tests y estilo

**Todo lo siguiente fue ejecutado por el maintainer, no por el agente** (regla del ciclo: el agente no corre tests, migraciones ni artisan).

### Suite completa — PRE-4c

| Evidencia | Resultado |
|---|---|
| `composer test` (`php artisan test --parallel --processes=4`) sobre el árbol previo a WU-4c (`08509d9`) | **1455 tests, 8501 assertions, 2 skipped, OK** |

Esta corrida es la única evidencia de suite completa del ciclo. **No se repitió después de WU-4c** (ver WARNING-1).

### Evidencia POST-4c — focalizada

| Evidencia | Resultado |
|---|---|
| `php artisan test --filter='Juego|Limites|Comision|ArquitecturaAlcanceTest'` (antes de corregir 2 expectativas del test nuevo) | **269 passed, 2 failed** — ambos en `AlcanceJuegoTest` por entidades sembradas omitidas en la expectativa |
| `php artisan test --filter=AlcanceJuegoTest` (tras `1d0466f`/`aab0964`, test-only) | **11 passed (70 assertions)** |
| `./vendor/bin/pint --test` | **passed** (`{"tool":"pint","result":"passed"}`, exit 0) |

Los dos fallos del filtro ampliado fueron expectativas incompletas del test nuevo (faltaban `BT001`, `TT001` y `DEMO01` del seeder); los commits de corrección tocan solo `AlcanceJuegoTest.php`, por lo que el código de producción no cambió después del run de 269.

**Alcance de la evidencia (limitación explícita)**: el cierre formal de no-regresión fuera de Juego/Límites/Comisión/Arquitectura requiere una corrida completa posterior a `aab0964`, que no se ejecutó. El código de producción quedó congelado en `1547adb` y todo lo posterior es test-only o documentación.

Los hashes del `verify-report` (`evidence_revision`, `test_output_hash`, `build_output_hash`) son hashes del **texto de evidencia suministrado**, no de stdout crudo, porque la fase prohíbe re-ejecutar la suite.

---

## 4. Specs sincronizados (fuente de verdad)

| Capability | Acción | Detalle |
|---|---|---|
| `arquitectura-alcance` | **Creada** `openspec/specs/arquitectura-alcance/spec.md` | 6 requisitos, 7 escenarios. El spec principal no existía, así que el delta (que ya traía formato de spec completa: `# arquitectura-alcance Specification` + `**Estado**: draft`) se copió mecánicamente con `cp`; `diff -r` origen→destino **vacío, exit 0**. |

**Verificación de la fusión**:

```text
$ cp "openspec/changes/scope-service-85/specs/arquitectura-alcance/spec.md" "$temp_path"
$ diff -r "openspec/changes/scope-service-85/specs/arquitectura-alcance/spec.md" "$temp_path"
(salida vacía, exit 0)
$ mv "$temp_path" "openspec/specs/arquitectura-alcance/spec.md"
$ diff -r "openspec/changes/scope-service-85/specs/arquitectura-alcance/spec.md" "openspec/specs/arquitectura-alcance/spec.md"
(salida vacía, exit 0)
```

**Sin duplicados**: `grep '^### Requirement:'` sobre el spec devuelve 6 encabezados distintos, y cada requisito aparece exactamente una vez. No se eliminó ni modificó ningún requisito preexistente porque la capability es nueva.

---

## 5. Evidencia del movimiento a archivo

- Carpeta completa movida con `git mv` a `openspec/changes/archive/2026-11-04-scope-service-85/`; el directorio activo ya no existe.
- Snapshot recursivo pre-movimiento (`cp -R` a `mktemp -d`) comparado con el árbol archivado mediante `diff -r`: **salida vacía, exit 0**.
- Contenido archivado: `proposal.md`, `design.md`, `tasks.md`, `verify-report.md`, `apply-progress.md`, `specs/arquitectura-alcance/spec.md` y este `archive-report.md` (aditivo, excluido de la comparación).
- `tasks.md` archivado: **60 checkboxes marcados `[x]`, 0 sin marcar** (53 tareas de fase + 7 criterios de aceptación globales), incluidas las 7 tareas de WU-4c.
- `openspec/changes/` ya no contiene `scope-service-85`.

```text
$ diff -r "$snapshot_root/source" "openspec/changes/archive/2026-11-04-scope-service-85"
(salida vacía, exit 0)
```

---

## 6. Warnings residuales

Los warnings son **no bloqueantes**. Provienen del `verify-report` final (obs #775) y de la reconciliación al cierre; ninguno fue remediado después de la verificación.

**WARNING-1 — Evidencia de ejecución post-4c incompleta.**
La suite completa no se re-ejecutó tras WU-4c (último dato: 1455 tests / 8501 assertions / 2 skipped pre-4c) y el filtro combinado de 269 passed no se repitió en una sola invocación después de corregir las 2 expectativas de `AlcanceJuegoTest`. La evidencia verde final es `AlcanceJuegoTest` 11/70. Riesgo bajo: el código de producción quedó congelado en `1547adb` y las correcciones posteriores son test-only, pero el cierre formal debería repetir `composer test`.

**WARNING-2 — `AlcanceJuegoTest::assertIds` ordena antes de comparar.**
El helper ordena ambos lados antes de comparar, así que fija **pertenencia**, no **secuencia**. La preservación del orden de `entidadesVisibles`/`idsPorTipo` se verificó por inspección (copia literal del builder + `orderBy('id')`), que es sólida pero no queda protegida contra una regresión futura. Se recomienda una aserción de secuencia sin `sort`.

**WARNING-3 — Corto-circuito `super_master` en `JuegoController::entidadDentroDelAlcance` (L934).**
Es un `hasRole` residual que decide visibilidad de una entidad concreta para `super_master` sin consultar la entidad (paridad con ids inexistentes en `batch`). Se clasificó como guard de paridad, no como ladder de filas; para una lectura estricta de REQ-2 conviene moverlo al servicio aceptando un id inexistente.

**WARNING-4 — Hallazgos previos no tocados por 4c (W3/W4 del ciclo anterior).**
Ensanchamiento latente de `BancaController::show`/`GrupoController::show` (el alcance efectivo depende del middleware de ruta; si se relajara, la lectura podría ampliarse) y `puedeOperarTaquilla` sin consumidor real en controllers (solo lo expone `TaquillaPolicy::operar`). No bloquean el archivo.

**Cierre de W2 previo — `entidadesVisibles` implementado y consumido.**
El verify anterior marcaba `entidadesVisibles` como no implementado. Al cierre está implementado en `AlcanceService` (L457-525) y lo consume `JuegoController`; `idsPorTipo` y `bancasParaComisiones` también viven en el servicio. El bookkeeping de `tasks.md` (6 criterios globales sin marcar en el snapshot de verify) quedó cerrado en `04b151b`: **7/7 criterios globales marcados**.

### Follow-ups

1. **BE-04 (siguiente)**: catálogo de permisos (`permission:`/`Gate::before`) sobre esta base. La capa ya expone visibilidad y comisiones sin ladders en controllers, lista para reemplazar el alcance por capabilities puntuales sin volver a tocar controllers.
2. **Reparenting** `taquilla → grupo` / `taquilla → agencia → grupo`: sigue fuera de alcance; las dos variantes conviven (`ENTIDADES` vs `CIERRE_ITEM`) y requieren un issue propio.
3. Re-ejecutar la suite completa post-4c y conservar el log crudo; actualizar el conteo si cambia.
4. Añadir la aserción de secuencia de WARNING-2 y un scan estático con allowlist de `hasRole` en `ArquitecturaAlcanceTest` para que una nueva ladder de filas falle el test.
5. Mover el corto-circuito de `super_master` de WARNING-3 a `AlcanceService` (o documentarlo como guard aceptado) en una WU de limpieza.
6. Evaluar el endurecimiento de `show` de Banca/Grupo y el uso real de `puedeOperarTaquilla` (WARNING-4) si se relaja el middleware de ruta.

---

## 7. Nota de desviación: `tdd: true` vs ejecución del maintainer

`openspec/config.yaml` declara `apply.tdd: true`, pero la regla operativa de este ciclo establece que **el agente no ejecuta tests ni migraciones**: los comandos se dejan escritos y el maintainer los corre localmente (por costo y tiempo de la suite; CI solo corre en `main`). En consecuencia:

- El ciclo fue **TDD en el orden de autoría** (tests escritos antes o junto al código en cada WU), no en el sentido de RED→GREEN observado por el agente.
- La verificación se hizo con **evidencia aportada por el usuario**, no con stdout capturado por el agente; por eso los hashes del `verify-report` son del texto de evidencia y no de la salida cruda.
- `composer test` se corrió completo una sola vez, pre-4c; la evidencia post-4c es focalizada.

La desviación es de proceso, no de producto, y queda registrada para que no se interprete el ciclo como un Strict TDD verificado por el agente.

---

## 8. Trazabilidad (Engram, proyecto `lotto-app`)

| Artefacto | Observación |
|---|---|
| proposal | #769 (`sdd/scope-service-85/proposal`) |
| spec | #770 (`sdd/scope-service-85/spec`) |
| design | #771 (`sdd/scope-service-85/design`) |
| tasks | #772 (`sdd/scope-service-85/tasks`) |
| apply-progress | #773 (`sdd/scope-service-85/apply-progress`) |
| verify-report | #775 (`sdd/scope-service-85/verify-report`) |
| archive-report | #776 (`sdd/scope-service-85/archive-report`, este documento) |

No hay `reviewGate` para este candidato (RDD no habilitado): no existen topics de transaction/ledger/receipt/gate-context que validar.

---

## 9. Estado del ciclo

`sdd-propose` → `sdd-spec` → `sdd-design` → `sdd-tasks` → `sdd-apply` (WU-0a-1 … WU-5 + remediación WU-4c) → `sdd-verify` (PASS WITH WARNINGS; C1 y W1 cerrados) → `sdd-archive` (este informe). El change está cerrado y `arquitectura-alcance` pasa a ser capability principal.

**Próximo paso**: merge **local** de `feat/85-4c-juego-comision` a `main` (sin push desde esta fase) y, después, BE-04 sobre esta base. Los artefactos `openspec/**/*.md` quedan versionados con `git add -f` cuando corresponda; el commit no forma parte de esta fase.

**Archivo intencional con warnings**: respaldado por un `verify-report` válido (0 blockers, 0 CRITICAL, 6/6 requisitos, 7/7 escenarios), 53/53 tareas de fase completas, 7/7 criterios globales marcados y la autorización explícita del orquestador para archivar con los warnings documentados en §6.
