```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:19e63ee74bdec70a32e85c031557bffd77d2cf50b385e34bd1a74623ebdacab7
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 8/8
scenarios: 15/15
test_command: DB_DATABASE=lotto_test_limites php artisan test
test_exit_code: 0
test_output_hash: sha256:20873783cc0bb57b8ca8f0dd82ad2647f45c4103f429729649e6f1df89c5b50d
build_command: pnpm run build
build_exit_code: 0
build_output_hash: sha256:b154f546c938d7b0ece491137da05721d656adc77eb5f4e81e71018b480978e1
```

## Verification Report

**Change**: `limites-gaps` — cierre de gaps de configuración de límites
**Version**: specs/limites (draft, R1–R8)
**Mode**: Standard (sin Strict TDD activo)
**Worktree**: `/home/gzuz/Documentos/lotto-app-worktrees/limites-gaps` (rama `feat/limites-gaps`, base `c375786` = origin/main)
**BD de tests**: `lotto_test_limites` (privada del ciclo)

### Completeness

| Metric | Value |
|--------|-------|
| Tasks total | 18 |
| Tasks complete | 18 |
| Tasks incomplete | 0 |
| Commits verificados | `3f2dfe6` (WU1) · `2cc6693` (WU2) · `181fb48` (WU3) · `9e5cf27` (WU4) |
| Diff total | 15 archivos, +410/−144 (incluye 4 archivos de tests y 1 componente borrado) |

### Build & Tests Execution

**Build (panel)**: ✅ Passed — 26 páginas, 3.19s, exit 0
```text
$ pnpm run build            # workdir: panel/
23:41:59 ✓ Completed in 114ms.
23:41:59 [build] 26 page(s) built in 3.19s
23:41:59 [build] Complete!
build_output_hash: sha256:b154f546c938d7b0ece491137da05721d656adc77eb5f4e81e71018b480978e1
```

**Tests (backend)**: ✅ 1079 passed / 0 failed / 2 skipped · 6432 assertions · exit 0 · 31.5 min (1887.9s)
```text
$ DB_DATABASE=lotto_test_limites php artisan test    # workdir: backend/
{"tool":"phpunit","result":"passed","tests":1081,"passed":1079,"assertions":6432,"duration_ms":1887905,"skipped":2}
test_output_hash: sha256:20873783cc0bb57b8ca8f0dd82ad2647f45c4103f429729649e6f1df89c5b50d
```
Los 2 skipped son ambientales preexistentes, ajenos al cambio: `ScrapeResultsJobTest:141` ("No hay juegos sin scraper para probar") y `PluginIntegrationTest:28` ("Requeriría configuración de base de datos de prueba").

**Coverage**: ➖ No hay umbral configurado en el proyecto; no aplica.

### Spec Compliance Matrix

| Requisito | Escenario | Test / evidencia | Resultado |
|-----------|-----------|------------------|-----------|
| R1 API sin campos dormidos | Serialización sin campos | `LimitesApiTest::test_get_limites_legacy_no_expone_dormidos` (:325) · `LimitesScopedApiTest::test_limites_entidad_banca_retorna_todos_los_juegos` (:127-128) · `test_limites_entidad_grupo_muestra_origen_heredado` (:163-166) | ✅ COMPLIANT |
| R1 API sin campos dormidos | Payload con campos dormidos ignorado | `LimitesApiTest::test_put_ignora_campos_dormidos` (:294) · `LimitesScopedApiTest::test_batch_ignora_campos_dormidos` (:793) · `JuegoLimiteServiceTest::test_aplicar_item_ignora_campos_dormidos` | ✅ COMPLIANT |
| R2 UI sin columnas dormidas | Tabla sin columnas dormidas | `limites.ts:51-55` (CAMPOS 4 columnas, sin `tipo`) + build 26 páginas + `rg fraccion\|limite_tiempo\|Fracción\|T. Límite panel/src` → 0 | ⚠️ PARTIAL (build+estático; QA visual pendiente) |
| R2 UI sin columnas dormidas | Sin 422 por ítem vacío del checkbox | Rama checkbox eliminada (`limites.ts:97-104` valorInicial, `:203-215` handler); CAMPOS sin `tipo`; guard 422 del backend retenido y testeado (`JuegoLimiteServiceTest::test_aplicar_item_solo_campos_dormidos_lanza_422`) | ⚠️ PARTIAL (build+estático; QA pendiente) |
| R3 Roles de escritura | Solo lectura para grupo/agencia | `limites.astro:96-110` (puedeVer/puedeConfigurar; btnGuardar disabled; `.acciones` oculto; `puedeEditar:false`) + detalle pages ocultan `.panel-actions` (bancas:380-384, grupos:412-416, taquillas:452-456) + build | ⚠️ PARTIAL (build+estático; QA visual pendiente) |
| R3 Roles de escritura | Escritura para roles admin | `canEditLimites` sm\|master\|banca (bancas:191, grupos:199-200, taquillas:185); `puedeEditar: true` y `eliminar` inyectados + build | ⚠️ PARTIAL (build+estático; QA visual pendiente) |
| R3 Roles de escritura | API rechaza escritura de grupo/agencia | `RoleAuthorizationTest::test_grupo_no_configura_limites` (NUEVO, PUT grupo→403) · `test_agencia_no_configura_limites` (preexistente) · rutas `api.php:198-215` | ✅ COMPLIANT |
| R4 Nav /limites por rol | Nav visible para roles admin | `AdminLayout.astro:253-255` (`['super_master','master','banca'].includes(role)`) + build | ⚠️ PARTIAL (build+estático; QA visual pendiente) |
| R4 Nav /limites por rol | Nav oculta para otros roles | Mismo condicional `AdminLayout.astro:254` + build | ⚠️ PARTIAL (build+estático; QA visual pendiente) |
| R5 Limpiar/heredar por fila | Limpiar fila propia | `limites.ts:163-170` (botón solo `valor?.id`) · `:217-243` (confirm→DELETE→`tocadas.delete`+recarga; error modal; 404 recarga) · wiring `eliminar: isCreate ? undefined : (id)=>apiFetch('DELETE','/limites/'+id)` (bancas:405-406, grupos:437-438, taquillas:477-478) · backend `destroyLimite` (`JuegoController.php:679-698`) + DELETE tests existentes `LimitesApiTest:344-393` | ⚠️ PARTIAL (build+estático; QA del flujo visual pendiente) |
| R5 Limpiar/heredar por fila | Celda heredada sin acción | `limites.ts:166` (`boton = ''` sin `valor?.id`) · `:61` `mostrarAcciones` solo `modo==='entidad'` (A6) | ⚠️ PARTIAL (build+estático; QA visual pendiente) |
| R6 agencia_id ignorado | Sin filtro por agencia_id | `LimitesApiTest::test_get_limites_ignora_agencia_id` (:253) · `test_get_limites_agencia_id_inexistente_es_ignorado` (:274) | ✅ COMPLIANT |
| R7 Código muerto eliminado | Código muerto retirado | `rg authorizeLimitesWrite` → 0 en todo el repo · `rg LimitesTable` → 0 · `git show --stat 9e5cf27` (delete `LimitesTable.astro`) | ✅ COMPLIANT |
| R8 Cobertura tests (SHOULD) | Suite actualizada | Suite completa 1079 passed · `CrearEntidadConLimitesTest:77,93-96` sin `fraccion` | ✅ COMPLIANT |
| R8 Cobertura tests (SHOULD) | Cobertura nueva | `JuegoLimiteServiceTest` (NUEVO, 4 tests) · agencia_id (2 tests) · serialización sin dormidos (3 tests) | ✅ COMPLIANT |

**Compliance summary**: 7/15 escenarios completamente compliant por test runtime; 8/15 PARTIAL por ser verificación visual del panel (sin runner; el diseño A5–A9 establece `astro build` + QA manual como mecanismo de verificación del panel). Ningún escenario UNTESTED ni FAILING.

### Correctness (Static Evidence)

| Requisito | Estado | Notas |
|-----------|--------|-------|
| R1 API sin dormidos | ✅ Implementado | `JuegoController.php:175-179` (PUT rules 4 campos), `:414-416` (`only([...])` 4 campos), `:438-449` (batch rules), `:992-1001` (`serializarLimite` 4 campos), `:1006-1017` (`valoresPresentes` 4 campos); `JuegoLimiteService.php:27` (CAMPOS), `:41-44` (validarItems); `JuegoLimite.php:38-45` (`$hidden`, A2). Guard 422 intacto (`JuegoLimiteService.php:123-125`). Grep app/ → solo Model (fillable/casts/$hidden). |
| R2 UI sin columnas dormidas | ✅ Implementado | `limites.ts:51-55` CAMPOS 4 columnas sin `tipo`; `:97-104` sin rama `fraccion`; `:203-215` handler sin checkbox. Grep panel → 0 referencias. |
| R3 Roles de escritura | ✅ Implementado | `limites.astro:96-110` (`puedeVer` 5 roles / `puedeConfigurar` 3 roles); detalle pages: `canEditLimites` sm\|master\|banca + `.panel-actions` oculto si `!canEditLimites`; rutas `api.php:198-215` sin cambios (R3 "sin cambios"). |
| R4 Nav /limites por rol | ✅ Implementado | `AdminLayout.astro:253-255`. |
| R5 Limpiar/heredar por fila | ✅ Implementado | `limites.ts:60-61,131,163-170,217-243`; `eliminar` ausente en create mode (`isCreate ? undefined : …`, A9); DELETE + recarga tras 200 (A7); manejo 403/404 vía `err.status` (`api.ts:21-27`). Semántica null→borra-fila intacta (`JuegoLimiteService.php:117-121`, testeada). |
| R6 agencia_id ignorado | ✅ Implementado | `JuegoController.php:175-179` sin rule `agencia_id`; sin bloque `where('agencia_id',…)` (antes `:224-226`); usos legítimos de taquillas intactos (`:201, :576, :793, :968`). |
| R7 Código muerto | ✅ Implementado | `authorizeLimitesWrite` borrado (`JuegoController.php` sin el método); `LimitesTable.astro` borrado (commit `9e5cf27`). |
| R8 Cobertura tests | ✅ Implementado | 4 archivos de tests modificados + `JuegoLimiteServiceTest.php` nuevo (+152). |

### Coherence (Design)

| Decisión | ¿Seguida? | Notas |
|----------|-----------|-------|
| A1 Retirar dormidos de UI+API (columnas en BD) | ✅ Sí | Validadores/only/CAMPOS/serialización sin los 2 campos; sin migración; `porcentaje_pago`/`participacion` intactos. |
| A2 `JuegoLimite::$hidden` | ✅ Sí | `JuegoLimite.php:38-45`; cubre `limites()`, `updateLimites`, `batchLimites` (respuestas crudas); tests de no-exposición en los 3 endpoints. |
| A3 `valoresPresentes` sin dormidos | ✅ Sí | `JuegoController.php:1006-1017`; test origen heredado con padre sembrado con dormidos. |
| A4 `agencia_id` eliminado del legacy | ✅ Sí | Sin rule ni filtro; comentario actualizado (`:213`); usos taquilla intactos. |
| A5 Acción Limpiar en el componente | ✅ Sí | Opciones `puedeEditar`/`eliminar` (`limites.ts:30-31`); páginas inyectan `apiFetch('DELETE',…)`. |
| A6 Solo modo entidad | ✅ Sí | `mostrarAcciones = modo==='entidad' && puedeEditar && !!eliminar` (`limites.ts:61`); botón solo `valor?.id` (`:166`). |
| A7 Confirmación + recarga | ✅ Sí | `showModal(confirm)` → `eliminar(id)` → 200: `tocadas.delete` + `cargarDatos` + `construirLineas` + `pintar` (`limites.ts:217-243`); error → modal sin recargar; 404 → recarga. |
| A8 Solo lectura UI (grupo/agencia) | ✅ Sí | `limites.astro:96-110` (inputs `disabled`, Guardar/`.acciones` ocultos); detalle pages ocultan `.panel-actions`; taquilla conserva "Sin acceso" (fuera de `puedeVer`). |
| A9 Modo creación sin `eliminar` | ✅ Sí | `eliminar: isCreate ? undefined : (id)=>apiFetch('DELETE','/limites/'+id)` en bancas/grupos/taquillas. |

### Issues Found

**CRITICAL**: None.

**WARNING**: None como defecto. Los 8 escenarios PARTIAL del panel son la verificación visual pendiente (gate humano): el panel no tiene runner y el diseño (A5–A9, tasks WU3/WU4) fija `astro build` + QA manual como mecanismo de verificación. El build pasa y la evidencia estática cubre el comportamiento; falta la confirmación humana.

**SUGGESTION**:
- `collections/Limites/Configurar Limite.yml:24-25` y `collections/Limites/Configurar Limite (Batch).yml:27-28,38-39` aún listan `fraccion`/`limite_tiempo` como ejemplos de payload. Eran la open question del design (fuera de alcance); los payloads siguen funcionando (campos ignorados sin error), pero los ejemplos documentan campos retirados. Actualizarlos en un ciclo futuro o junto con la remediación.

### QA manual pendiente (gates humanos — no hay runner de panel)

1. **WU3 — Limpiar por fila** (detalle pages):
   - (a) En una fila propia (con límite), el botón "Limpiar" aparece; al confirmar, se llama `DELETE /api/v1/limites/{id}` y la celda pasa a "hereda de …" (origen del ancestro).
   - (b) En una celda heredada (sin fila propia) NO aparece el botón.
   - (c) En modo creación (`isCreate`) NO aparece el botón (ni se borra la fila del padre).
   - (d) Un 403 (rol sin permiso) o 404 (fila ya borrada) muestra el modal de error; con 404 la tabla se refresca.
2. **WU4 — Gates por rol**:
   - Rol `grupo` y rol `agencia`: tabla de límites en lectura (inputs `disabled`, sin botón Guardar ni Limpiar) en `/limites` y en las páginas de detalle.
   - Roles `super_master`/`master`/`banca`: Guardar y Limpiar visibles y operativos.
   - Rol `taquilla` y otros: `/limites` muestra "Sin acceso a la configuración de límites."
3. **WU4 — Nav**: `/limites` visible en el sidebar para `super_master`/`master`/`banca`; oculta para `grupo`/`agencia`/`taquilla`.

### Comandos exactos ejecutados

| Comando | Workdir | Exit | Resultado |
|---|---|---|---|
| `DB_DATABASE=lotto_test_limites php artisan test` | `backend/` | 0 | 1081 tests, 1079 passed, 2 skipped, 6432 assertions, 1887905 ms |
| `pnpm run build` | `panel/` | 0 | 26 páginas, 3.19s |
| `rg authorizeLimitesWrite` / `rg LimitesTable` (repo) | repo | 1 (sin matches) | 0 resultados |
| `rg 'fraccion\|limite_tiempo' panel/src` | repo | 1 (sin matches) | 0 resultados |
| `rg 'fraccion\|limite_tiempo' backend/app` | repo | 0 | Solo `JuegoLimite.php` (fillable/casts/$hidden) |

### Verdict

**PASS WITH WARNINGS** — implementación completa y correcta (R1–R8 cubiertos, 0 CRITICAL, 0 defectos WARNING); suite backend completa en verde (1079/1079) y build del panel en verde; los únicos pendientes son los gates humanos de QA del panel (8 escenarios PARTIAL) y una SUGGESTION de docs stale (`collections/Limites/*.yml`).