```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:707f6342b96972344f24031aae4c14b4f0812baad0cc6fd7c75a4d4e7f62bdaf
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 6/6
scenarios: 7/7
test_command: php artisan test --filter=AlcanceJuegoTest
test_exit_code: 0
test_output_hash: sha256:23029ef26fd3e77adad916b0ebfa3d751acda56c602be2cb3ff271e259f26c3c
build_command: ./vendor/bin/pint --test
build_exit_code: 0
build_output_hash: sha256:cd1a94fc2cf6a965b86e1a4809d6c7fb9148b1ee374e1010ed2ac96ff4876ec2
```

## Verification Report

**Change**: scope-service-85 (issue #85)
**Version**: N/A (spec `arquitectura-alcance`, estado draft)
**Mode**: Standard (Strict TDD no activo)
**Rama**: `feat/85-4c-juego-comision`
**Base**: `f364895` (main) · **HEAD**: `aab0964` · **Pre-4c**: `08509d9`
**Remediación verificada**: WU-4c (`1547adb`, `a2a16a8`, `1d0466f`, `aab0964`)

### Resumen ejecutivo

La remediación WU-4c cierra los dos hallazgos del verify anterior. `JuegoController` y `ComisionController` ya no deciden filas por rol: `entidadesVisibles`, `idsPorTipo` y `bancasParaComisiones` viven en `AlcanceService` y los controllers solo delegan. Las 13 denegaciones de acción de `CierreController` quedan fuera de la capa de alcance por diseño, amparadas por REQ-5 enmendado, sin cambiar una sola línea de esas ramas.

El comportamiento observable no cambió: el diff de producción es un movimiento literal de las escaleras (mismo orden de ramas, mismos predicados, mismos mensajes) y los tests de scope existentes siguen sin modificarse. El veredicto es **PASS WITH WARNINGS** por evidencia de ejecución incompleta post-4c (suite completa y filtro combinado no re-ejecutados en una sola invocación tras corregir dos expectativas de test) y por bookkeeping pendiente en `tasks.md`; no se encontró ningún defecto de contrato ni de runtime.

### Remediation 4c — cierre de hallazgos

| Hallazgo previo | Estado | Evidencia |
|---|---|---|
| **C1** — `JuegoController` (`JUEGO_VIS`/`expandirTipoAlcance`) y `ComisionController::bancasEnAlcance` decidían filas por rol; REQ-2 sin cubrir | ✅ **Cerrado** | `git diff 08509d9..HEAD -- backend/app/Http/Controllers/Api/JuegoController.php` elimina 130 líneas de ladder; `expandirTipoAlcance` ahora llama `idsPorTipo` y `entidadesVisiblesPorTipo` es un wrapper de una línea sobre `entidadesVisibles`. `ComisionController::bancasEnAlcance` es una línea sobre `bancasParaComisiones` y el archivo queda con 0 `hasRole`/`hasAnyRole`. REQ-2 enmendado enumera los cuatro mecanismos de filas (`applyTo*`, `entidadesVisibles`, `idsPorTipo`, `bancasParaComisiones`) como propiedad del servicio. |
| **W1** — 13 `abort(403)` de `CierreController` sin migrar a `denegar()` | ✅ **Cerrado por diseño (REQ-5)** | `git diff 08509d9..HEAD -- backend/app/Http/Controllers/Api/CierreController.php` vacío: las 13 ramas están intactas. Son autorización de acción puntual, no decisión de filas: 6 en `assertTaquillaEnAlcance` (validación del filtro `taquilla_id` solicitado, agencia estricta dueña) y 7 en `resolveTaquillaParaCierre` (resolución por rol de la taquilla a cerrar). `tasks.md` 4a queda `[x]` con la nota 6/19 + 13 por diseño. |
| **W2** — `entidadesVisibles` no implementado | ✅ **Cerrado** | `AlcanceService::entidadesVisibles` (L457-525), `idsPorTipo` (L537-618), `bancasParaComisiones` (L629-640); `AlcanceJuegoTest` nuevo con 11 tests / 70 assertions. |

### Completeness

| Metric | Value |
|--------|-------|
| Tareas de fase totales | 52 |
| Tareas de fase completas | 52 |
| Tareas de fase incompletas | 0 |
| Criterios de aceptación globales | 1/7 marcados en `tasks.md`; 6/7 satisfechos por evidencia (el restante depende de la suite completa post-4c) |

Las 7 tareas de la WU-4c están marcadas `[x]` y `tasks.md` 4a pasó de `[ ]` a `[x]`. Quedan 6 checkboxes globales sin marcar (L326-331) aunque su evidencia existe (ver W2 en Issues): es bookkeeping del artefacto, no trabajo pendiente de código.

### Build & Tests Execution

**Build / formato**: ✅ Passed — ejecutado por el verifier en este worktree.
```text
cd backend && ./vendor/bin/pint --test
{"tool":"pint","result":"passed"}
exit 0
```

**Tests**: ✅ 11 passed / 0 failed / 0 skipped en la evidencia post-remediación; 269 passed en el filtro ampliado previo a la corrección de expectativas; 1455 passed en la suite completa pre-4c.
```text
# Post-4c, tras corregir 2 expectativas (commit test-only 1d0466f/aab0964)
cd backend && php artisan test --filter=AlcanceJuegoTest
11 passed (70 assertions)   exit 0

# Post-4c focalizado, antes de la corrección de expectativas
php artisan test --filter='Juego|Limites|Comision|ArquitecturaAlcanceTest'
269 passed, 2 failed (ambos en AlcanceJuegoTest por entidades sembradas omitidas en la expectativa)

# Pre-4c (baseline del change)
php artisan test --parallel --processes=4
1455 tests, 8501 assertions, 2 skipped, OK   exit 0
```

**Coverage**: ➖ No disponible (`coverage_threshold: 0`; no se ejecutó medición).

> **Provenance de hashes**: por contrato de esta fase el verifier no ejecuta tests; `test_output_hash` es el SHA-256 del texto exacto de evidencia post-fix (`11 passed (70 assertions)`) y `build_output_hash` es el SHA-256 de la salida real de Pint capturada aquí. `evidence_revision` = SHA-256 de `aab0964|requirements=6|scenarios=7|test_hash|build_hash`. Los dos fallos del filtro ampliado fueron expectativas incompletas del test nuevo (faltaban `BT001`, `TT001`, `DEMO01` del seeder); los commits de corrección tocan solo `AlcanceJuegoTest.php`, por lo que el código de producción no cambió después del run de 269.

### Spec Compliance Matrix

| Requirement | Scenario | Test / evidencia | Result |
|-------------|----------|------------------|--------|
| REQ-1 — Capa única de alcance | Capa aditiva no altera baseline | `AlcanceServiceScopeTest` (13), `AlcancePoliciesTest` (6), `ArquitecturaAlcanceTest` (5) verdes; `git diff 08509d9..HEAD -- backend/tests` = solo `AlcanceJuegoTest` nuevo (0 tests existentes modificados) | ✅ COMPLIANT |
| REQ-2 — Controllers sin lógica de filas por rol | Controller sin comparaciones de rol para filas | `AlcanceJuegoTest` (11 tests: roles, roots, fail-closed de `entidadesVisibles`/`idsPorTipo`/`bancasParaComisiones`) + grep clasificado de `hasRole` en `JuegoController`/`ComisionController` | ✅ COMPLIANT |
| REQ-3 — Cero cambio observable | Migración de un controller preserva contrato | Diff de producción = movimiento literal (mismas ramas, `orderBy('id')`, mismos mensajes); tests existentes de Juego/Límites/Comisión/Cierre verdes y sin modificar | ✅ COMPLIANT |
| REQ-4 — `masterBancaIds()` único | `masterBancaIds()` único | `ArquitecturaAlcanceTest` (5 tests) + conteos estáticos: 1 definición, 4 usos internos en `User`, 2 en `AlcanceService`, 0 en controllers | ✅ COMPLIANT |
| REQ-5 — Denegación unificada | Denegación de filas con mensaje preservado | `AlcanceJuegoTest::test_ids_por_tipo_fail_closed_con_mensaje_exacto`, `::test_bancas_para_comisiones` (403 + mensaje exacto), `AlcanceServiceScopeTest::test_denegar_lanza_403_con_el_mensaje_exacto` | ✅ COMPLIANT |
| REQ-5 — Denegación unificada | Autorización de acción puntual conserva su contrato | 13 `abort(403)` de `CierreController` sin cambios en 4c (diff vacío) + `CierreCajaTest`/`CuadreCajaReportTest`/`ComisionCierreTest` verdes | ✅ COMPLIANT |
| REQ-6 — Documentación | Docs del modelo de alcance | `docs/dev/alcance-jerarquico.md` (191 líneas): `JUEGO_VIS` mapeado a `entidadesVisibles`, nota de `idsPorTipo`, §6 con REQ-5, §7 sin la excepción de Juego; enlace en `arquitectura-backend.md` §3.2 | ✅ COMPLIANT |

**Compliance summary**: 7/7 escenarios compliant, 0 partial, 0 failing, 0 untested.

#### REQ-2 — evidencia de grep y clasificación

`grep -rn "hasAnyRole\|hasRole" app/Http/Controllers/Api/` → 59 líneas en 9 archivos. En los dos controllers señalados por C1:

**`JuegoController` (17 líneas, ninguna decide filas):**

| Líneas | Clasificación |
|---|---|
| 235, 297 | Guard de entrada de `limites`/`listarLimites` (rol fuera de catálogo → 403 con mensaje propio). Las filas las decide `applyToEntidades`/`entidadesVisibles`. |
| 423, 493 | Guard de entrada de `updateLimites`/`batchLimites` (escritura). |
| 546 | Pre-chequeo de acción del batch: `assertObjetivosDentroDelGrupo` valida que los objetivos expandidos estén en el subárbol del rol `grupo` antes de escribir. |
| 728-742 | Autorización de acción de `destroyLimite` (delete de una fila concreta, agencia/grupo dueños). |
| 758-832 | `authorizeBancaLimitAccess` / `authorizeEscrituraLimite` (autorización de escritura de #84, preservada). |
| 934 | Corto-circuito `super_master` en `entidadDentroDelAlcance`: guard de paridad con ids inexistentes en `batch`; la decisión de entidad sigue delegada en `alcanceDeEntidad`. |

**`ComisionController`**: 0 ocurrencias; `bancasEnAlcance` solo delega en `bancasParaComisiones`.

Los otros 7 archivos con `hasRole` (Cierre, Taquilla, Auth, Agencia, Grupo, User, Ticket, Banca) fueron inspeccionados: guards de ruta/entrada, autorización de acción o derivación de payload. No queda ninguna escalera `rol → columna` para resolver filas en `Api/`.

#### REQ-5 — las 13 excepciones de `CierreController`

`grep -c "abort(403" CierreController` = 13, todas en métodos de acción:

- `assertTaquillaEnAlcance` (6): valida el filtro `taquilla_id` solicitado en `index`/`semanal` con agencia **dueña** estricta (no agencia→grupo). El conjunto de filas ya lo fijó `applyToCierres`.
- `resolveTaquillaParaCierre` (7): resuelve qué taquilla puede cerrarse por rol (taquilla propia, master dueño de la banca, agencia/banca/grupo de la taquilla, y fallback de rol no permitido).

Ambos métodos están **sin cambios** en 4c: el diff `08509d9..HEAD` de `CierreController` está vacío, por lo que status (`403`) y body (`{"message": ...}`) son idénticos a los previos. La única denegación de filas del controller (`authorizeCierreAccess`) y la de `scopeCierresPara` pasan por `AlcanceService::denegar()` / `applyToCierres`.

### Regression checks de la remediación

| Invariante | Resultado |
|---|---|
| `entidadesVisibles` preserva el orden de ramas `JUEGO_VIS` (banca → master → grupo → agencia → super_master) y `orderBy('id')` | ✅ Copia literal del builder: el diff del servicio es +197/−0 y el controller pierde exactamente el mismo bloque. La precedencia multi-rol no cambia. |
| `entidadesVisibles` preserva la intersección de raíz y el fail-closed | ✅ `$raiz` banca/grupo idéntico; rol desconocido → `collect()`. Cubierto por `test_entidades_visibles_respetan_la_interseccion_de_raiz` y `..._super_master_y_fail_closed`. |
| `idsPorTipo` preserva el orden de ramas original (banca/agencia antes que master; grupo/agencia al final) y el `denegar` fail-closed | ✅ Copia literal; cubierto por `test_ids_por_tipo_para_grupo_y_agencia_conserva_el_orden_de_ramas` y `test_ids_por_tipo_fail_closed_con_mensaje_exacto`. |
| `bancasParaComisiones` preserva master → super_master y `null` global | ✅ Copia literal; cubierto por `test_bancas_para_comisiones` (master A, master B, master sin bancas, super, 403 exacto). |
| Sin ladders de filas residuales en `JuegoController`/`ComisionController` | ✅ Las 17 ocurrencias de Juego son guards/action auth; Comision en 0. |
| `masterBancaIds()` / helpers de master siguen centralizados | ✅ 1 definición (`User:181`), 4 usos internos, 2 en `AlcanceService`, 0 en controllers; `ArquitecturaAlcanceTest` verde. |
| Tests existentes sin modificar | ✅ Los commits 4c solo agregan/ajustan `AlcanceJuegoTest` (nuevo); `git diff 08509d9..HEAD -- backend/tests` no toca ningún test previo. |

### Correctness (Static Evidence)

| Requirement | Status | Notes |
|------------|--------|-------|
| REQ-1 | ✅ Implemented | `AlcanceService` `final`; la jerarquía vive en `applyTo*`, `alcanceDeEntidad`, `puedeGestionar`, `puedeOperarTaquilla`, `entidadesVisibles`, `idsPorTipo`, `bancasParaComisiones`. Policies delegan. |
| REQ-2 | ✅ Implemented | `JuegoController` y `ComisionController` solo delegan las decisiones de filas; `hasRole` restante es guard o autorización de acción (tabla arriba). |
| REQ-3 | ✅ Implemented | Movimiento literal; status/body/mensajes preservados. `CierreController` sigue capturando el 403 del servicio para devolver solo `message` con `APP_DEBUG=true`. |
| REQ-4 | ✅ Implemented | Conteos idénticos al verify previo; invariante medida por `ArquitecturaAlcanceTest`. |
| REQ-5 | ✅ Implemented | Capa de alcance: `denegar()`. `CierreController`: 13 `abort(403)` de acción, sin cambios, con status/body idénticos. |
| REQ-6 | ✅ Implemented | Doc actualizada: `JUEGO_VIS` ya no es excepción; `idsPorTipo` documentado como orden de ramas propio; §6 refleja la enmienda REQ-5. |

### Coherence (Design)

| Decision | Followed? | Notes |
|----------|-----------|-------|
| 1. `Builder`→`Builder`; `Model` para entidades | ✅ Yes | Sin DTOs; `entidadesVisibles`/`idsPorTipo` devuelven `Collection`, `bancasParaComisiones` `?array`. |
| 2. 6 policies + registro explícito | ✅ Yes | Sin cambios en 4c. |
| 3. `denegar()` = `abort(403,$msg)` en la capa de alcance | ✅ Yes | `entidadesVisibles` fail-closed sin excepción; `idsPorTipo`/`bancasParaComisiones` usan `denegar()`. |
| 4. Orden por contrato, no `primaryRole()` | ✅ Yes | `entidadesVisibles` reproduce `JUEGO_VIS`; `idsPorTipo` conserva el ladder original, que difiere de `JUEGO_VIS` a propósito. |
| 5. Servicio llama `masterBancaIds()`; `User` la define | ✅ Yes | `bancasDeMaster` → `masterBancaIds` (L649). |
| 6. Paridad observable (IDs/status/body) | ✅ Yes | Diff literal + tests de paridad; `AlcanceJuegoTest` fija filas, roots y mensajes. |
| 7. Excluir `AuthController`; `PagoController` no cambia | ✅ Yes | Fuera del diff de 4c. |
| `entidadesVisibles` en la API del servicio | ✅ Yes (antes ❌) | Implementado en L457 y consumido por `JuegoController`; cierra W2 previo. |
| `JUEGO_VIS` no se reutiliza como `applyToEntidades` | ✅ Yes | Se conserva contrato propio en el servicio (`ENTIDADES` habría cambiado "su banca/grupo" a 403). |
| `CierreController` con agencia estricta | ✅ Yes | `assertTaquillaEnAlcance` intacto; 13 aborts por diseño REQ-5. |
| `UserPolicy` de #84 intacto | ✅ Yes | Sin cambios en 4c. |

### Conteos verificados

| Conteo | Resultado |
|---|---|
| `function masterBancaIds` en `app/` | 1 (`app/Models/User.php:181`) |
| `->masterBancaIds()` en `app/` | 6 líneas: 4 en `User` (196, 213, 230, 244) + 2 en `AlcanceService` (278, 649) |
| `masterBancaIds` en `app/Http/Controllers` | 0 |
| Archivos con `->masterBancaIds()` | 2: `User`, `AlcanceService` |
| Métodos nuevos del servicio (4c) | 3: `entidadesVisibles` (L457), `idsPorTipo` (L537), `bancasParaComisiones` (L629) |
| `hasRole`/`hasAnyRole` en `Api/` | 59 líneas en 9 archivos; 17 en `JuegoController` (todas guard/action), 0 en `ComisionController` |
| `abort(403` en `CierreController` | 13 (6 `assertTaquillaEnAlcance` + 7 `resolveTaquillaParaCierre`), sin cambios |
| Tests nuevos (change) | 5 archivos, incluido `AlcanceJuegoTest` (11 tests / 70 assertions) |
| Tests existentes modificados en 4c | 0 |
| Rutas / migraciones modificadas | 0 |

### Issues Found

**CRITICAL**: None — C1 y W1 previos cerrados.

**WARNING**:
- **W1 — Evidencia de ejecución post-4c incompleta.** La suite completa no se re-ejecutó tras 4c (último dato: 1455/8501/2 pre-4c) y el filtro combinado de 269 passed no se repitió en una sola invocación después de corregir las 2 expectativas de `AlcanceJuegoTest`. La evidencia verde final es `AlcanceJuegoTest` 11/70. Riesgo bajo: el código de producción quedó congelado en `1547adb` y las correcciones posteriores son test-only, pero el cierre formal debería repetir `composer test`.
- **W2 — `tasks.md` con 6 criterios globales sin marcar** (L326-331) pese a que su evidencia existe en esta verificación (salvo la suite completa post-4c). Bookkeeping del artefacto, no defecto de código.
- **W3 — El orden de `entidadesVisibles`/`idsPorTipo` no está asertado por test.** `AlcanceJuegoTest::assertIds` ordena ambos lados antes de comparar, así que fija pertenencia, no secuencia. La preservación del orden se verificó por inspección (copia literal + `orderBy('id')`), que es sólida pero no queda protegida contra una regresión futura.
- **W4 — Corto-circuito `super_master` en `JuegoController::entidadDentroDelAlcance` (L934).** Es un `hasRole` residual que decide visibilidad de una entidad concreta para `super_master` sin consultar la entidad (paridad con ids inexistentes en `batch`). Clasificado como guard de paridad, no como ladder de filas; si se quiere lectura estricta de REQ-2 conviene moverlo al servicio aceptando un id inexistente.
- **W5 — Hallazgos previos no tocados por 4c (sin cambio).** Ensanchamiento latente de `BancaController::show`/`GrupoController::show` (depende del middleware de ruta) y `puedeOperarTaquilla` sin consumidor en controllers. No bloquean el archivo del change.

**SUGGESTION**:
- Re-ejecutar `composer test` completo en la rama y actualizar los 6 checkboxes globales de `tasks.md` en el mismo commit.
- Añadir a `AlcanceJuegoTest` una aserción de secuencia (sin `sort`) para `entidadesVisibles` por rol, y un scan estático en `ArquitecturaAlcanceTest` con allowlist de `hasRole` para que una nueva ladder de filas falle el test.
- Mover el corto-circuito de `super_master` a `AlcanceService` (o documentarlo como guard aceptado en `docs/dev/alcance-jerarquico.md` §7) en una WU de limpieza.
- Mantener `BE-04` como siguiente paso: la capa ya expone visibilidad y comisiones sin ladders en controllers.

### Riesgos residuales y follow-ups

- **Evidencia**: antes de publicar los slices, repetir la suite completa y `pint --test` en el entorno final (CI solo corre en `main`).
- **Cierre de C1**: cerrado por implementación, no por excepción; el spec ahora nombra explícitamente los cuatro mecanismos de filas del servicio.
- **Cierre de W1**: cerrado por diseño con REQ-5 enmendado; las 13 ramas de `CierreController` conservan status/body exactos y no deciden filas.
- **Reparenting** `taquilla → grupo` / `taquilla → agencia → grupo` sigue fuera de alcance; las dos variantes conviven (`ENTIDADES` vs `CIERRE_ITEM`).

### Verdict

**PASS WITH WARNINGS** — REQ-1..6 se cumplen, C1 y W1 quedan cerrados y el comportamiento observable se preserva; la única deuda es de evidencia (suite completa post-4c) y bookkeeping (checkboxes y aserción de orden), no de contrato ni de runtime.
