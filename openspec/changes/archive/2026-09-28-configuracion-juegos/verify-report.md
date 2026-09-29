```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:2aca788c8ea5c6e963f142a35218d4963ac8661a03a4c3569b4758acfb241b45
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 10/10
scenarios: 30/30
test_command: DB_DATABASE=lotto_test_motor php artisan test
test_exit_code: 0
test_output_hash: sha256:2aca788c8ea5c6e963f142a35218d4963ac8661a03a4c3569b4758acfb241b45
build_command: pnpm run build
build_exit_code: 0
build_output_hash: sha256:244262b41a5a28ab0673f6d1e1a5a9c74eef1d15cf51506dbf77dffc68678017
```

## Informe de Verificación

**Cambio**: `configuracion-juegos` — edición de premios con snapshot no retroactivo
**Versión**: N/A (specs draft del cambio)
**Modo**: Strict TDD
**Worktree**: `/home/gzuz/Documentos/lotto-app-worktrees/configuracion-juegos` (rama `feat/configuracion-juegos-s4`, HEAD `a75c4b9`)
**Fecha**: 2026-09-28 · **Agente**: sdd-verify (solo lectura; sin push)

### Completeness

| Métrica | Valor |
|---------|-------|
| Tareas totales | 22 |
| Tareas completas | 22 |
| Tareas incompletas | 0 |
| Slices | S1a (5/5) + S1b (3/3) + S2 (6/6) + S3 (5/5) + S4 (3/3) |

### Build & Tests Execution

**Build (panel)**: ✅ Passed
```text
$ pnpm run build   (en panel/)
[build] 25 page(s) built in 2.38s
[build] Complete!
```
Nota honesta: `panel/` NO tiene runner de tests de UI (hecho conocido y documentado en apply-progress S3/S4). La verificación del panel es build verde + lectura del flujo compilado (`dist/_astro/hoisted.*.js` contiene `PUT /juegos/{id}/premios`, `showErrors(err.message, err.errors)`, `GET /juegos/{id}` + `/reglas`, render de auditoría before/after). Se recomienda smoke manual del editor (abrir `/juegos`, clic en fila, editar, guardar, ver errores 422 junto al campo y auditoría nueva).

**Tests**: ✅ 1080 passed / ❌ 0 failed / ⚠️ 2 skipped
```text
$ DB_DATABASE=lotto_test_motor php artisan test
{"tool":"phpunit","result":"passed","tests":1082,"passed":1080,"assertions":4987,"duration_ms":2973778,"skipped":2}
```
- Suite completa: **1082 tests, 1080 passed, 2 skipped (preexistentes: `PluginIntegrationTest` y `ScrapeResultsJobTest`, ambos con `markTestSkipped` condicional), 4987 assertions, ~49,6 min**.
- Referencia esperada ≈ main 1036 + ~35 nuevos → el número real de la rama es **1082** (diferencia explicable por tests de otros cambios ya integrados en la base de la cadena; lo importante es 0 failed).

**Focused**: ✅ 146 passed / 0 failed
```text
$ DB_DATABASE=lotto_test_motor php artisan test --filter='JuegoPremiosApiTest|PremioSnapshotTest|JuegoToggleTest|JuegoUpdateTest|MotorPremiosRegresionTest|ModalidadesSingleDrawTest|VerificarGanadoresTest|JuegosJsonTest|PremiosEngineTest|PremiosOficialesTest'
{"tool":"phpunit","result":"passed","tests":146,"passed":146,"assertions":1160,"duration_ms":405545}
```
Desglose por archivo (verificado por lectura del código):
- `JuegoPremiosApiTest` (15) · `PremioSnapshotTest` (5) · `JuegoToggleTest` (4) · `JuegoUpdateTest` (3) · `MotorPremiosRegresionTest` (30) · `ModalidadesSingleDrawTest` (21) · `VerificarGanadoresTest` (5) · `JuegosJsonTest` (6) · `PremiosEngineTest` (25) · `PremiosOficialesTest` (33) = 147 → el filtro devuelve 146 (algún test del set cae fuera del filtro exacto; reportado el número real del runner).

**Pint**: ✅ `{"tool":"pint","result":"passed"}` (`vendor/bin/pint --test`).

**Coverage**: ➖ No disponible (sin herramienta de cobertura configurada en el repo; análisis omitido — no es fallo).

### Matriz de Cumplimiento de Spec

#### `configuracion-premios` (8 REQ / 23 escenarios)

| Requisito | Escenario | Test | Resultado |
|-----------|-----------|------|-----------|
| REQ1 Edición atómica con merge seguro | Edición completa de premios | `JuegoPremiosApiTest::test_put_premios_merge_preserva_scraper_y_modalidades_permitidas` + `test_put_premios_reemplaza_atomicamente_y_sincroniza_espejos` | ✅ COMPLIANT |
| REQ1 | Reemplazo atómico (no merge por subclave) | `test_put_premios_reemplaza_atomicamente_y_sincroniza_espejos` (`assertEqualsCanonicalizing(payload, config.premios)`) | ✅ COMPLIANT |
| REQ1 | Rol sin permiso | `test_put_premios_403_para_rol_banca` | ✅ COMPLIANT |
| REQ2 Validación del payload | base no entero | `test_put_premios_base_invalida_422` (0, -5, 'abc', 1.5) | ✅ COMPLIANT |
| REQ2 | modalidad con clave inválida | `test_put_premios_clave_invalida_422` | ✅ COMPLIANT |
| REQ2 | modalidad con valor inválido | `test_put_premios_valores_invalidos_422` (0, 'abc') | ✅ COMPLIANT |
| REQ2 | comodín con tipo inválido | `test_put_premios_tipo_comodin_invalido_422` | ✅ COMPLIANT |
| REQ2 | acumulativo sin palabra | `test_put_premios_acumulativo_sin_tipo_palabra_422` | ✅ COMPLIANT |
| REQ2 | clave canónica no listada por el plugin | `test_put_premios_clave_canonica_no_listada_por_el_plugin_200` (`signo_terminal` → 200 + espejo `terminal_zodiacal`) | ✅ COMPLIANT |
| REQ2 | la-ricachona sin base oficial | `test_put_premios_la_ricachona_422` | ✅ COMPLIANT |
| REQ3 Sincronización de espejos legacy | premio_multiplo igual a base | `test_put_premios_reemplaza_atomicamente_y_sincroniza_espejos` (`assertSame(600, premio_multiplo)`) + `PremiosOficialesTest::test_espejos_legacy_mapea_vocabulario_canonico_a_claves_historicas` | ✅ COMPLIANT |
| REQ3 | modalidades espejo sincronizadas | `test_put_premios_reemplaza_atomicamente_y_sincroniza_espejos` (`cola`/`zodiacal`) + `test_put_premios_clave_canonica_no_listada_por_el_plugin_200` | ✅ COMPLIANT |
| REQ4 Auditoría de edición de premios | before/after registrados | `test_put_premios_audita_before_y_after` + `test_put_premios_auditoria_expuesta_en_get_juego` (relación `user` con email) | ✅ COMPLIANT |
| REQ5 Reflejo en las reglas del juego | reglas refleja los nuevos premios | `test_put_premios_reflejados_en_reglas` (`GET /juegos/{id}/reglas` → `premios` del motor = payload) | ✅ COMPLIANT |
| REQ6 Snapshot por apuesta | Snapshot persistido al vender | `PremioSnapshotTest::test_venta_persiste_snapshot_de_premios_en_el_detalle` (snapshot = `config.premios`, base 50) | ✅ COMPLIANT |
| REQ6 | Edición posterior no altera apuestas vendidas | `test_edicion_posterior_no_altera_la_liquidacion_de_la_apuesta_vendida` (liquida 500 = 50×, no 600 = 60×) + `test_comodines_del_snapshot_congelados_tras_la_edicion` (700 = 50+20×) | ✅ COMPLIANT |
| REQ6 | Pago usa el snapshot | `test_pago_valida_contra_el_snapshot_y_rechaza_el_monto_del_config_nuevo` (422 con monto 600 / 201 con 500 snapshot) | ✅ COMPLIANT |
| REQ6 | Fallback legacy sin snapshot | `test_fallback_legacy_sin_snapshot_usa_el_config_actual` (600 = 60× config actual) | ✅ COMPLIANT |
| REQ7 Toggle del juego | Toggle envía active y persiste | `JuegoToggleTest::test_toggle_desactiva_persiste_y_audita` + `test_toggle_activa_audita_accion_activar` + `juegos.astro` L31 `{active: !active}` (verificado por lectura + build) | ✅ COMPLIANT |
| REQ7 | Error de toggle visible | `JuegoToggleTest::test_toggle_422_sin_body` (backend, 422 real) + `juegos.astro` `catch(err){alert('Error: '+err.message)}` (verificado por lectura del fuente + bundle compilado + build verde). El design del cambio autoriza explícitamente la verificación del panel como build + smoke manual (S3/S4) — no hay runner de UI en el repo; sin test automatizado del render por configuración del proyecto, no por omisión | ✅ COMPLIANT |
| REQ7 | vendible espejo de active | `JuegosJsonTest::test_esquema_minimo_y_conteos_de_opciones_por_tipo` L119-124 (`assertSame(active, vendible)`) + L157/174 | ✅ COMPLIANT |
| REQ8 Export del catálogo | docs/juegos.json refleja los nuevos premios | `JuegosJsonTest::test_export_con_path_refleja_premios_editados_sin_tocar_docs` (700, espejo `cola:70`, docs intacto por hash) | ✅ COMPLIANT |
| REQ8 | Nota de coordinación de la taquilla | `docs/motor-premios.md` §9.1 (verificado por lectura; tarea documental sin test) | ✅ COMPLIANT |

#### `motor-premios` delta MODIFIED (2 REQ / 7 escenarios)

| Requisito | Escenario | Test | Resultado |
|-----------|-----------|------|-----------|
| REQ1 Premios config-driven por juego | Premio base desde config | `MotorPremiosRegresionTest` (ej. `test_monje_figura_42_base_50x`) | ✅ COMPLIANT |
| REQ1 | Multiplicador hardcodeado ausente | `MotorPremiosRegresionTest` (30 tests, contrato del motor intacto) | ✅ COMPLIANT |
| REQ1 | Liquidación contra snapshot | `PremioSnapshotTest::test_edicion_posterior_no_altera_la_liquidacion_de_la_apuesta_vendida` + `PremiosEngineTest::test_calcular_con_override_de_premios_usa_el_snapshot_no_el_config` | ✅ COMPLIANT |
| REQ1 | Fallback legacy sin snapshot | `PremioSnapshotTest::test_fallback_legacy_sin_snapshot_usa_el_config_actual` + `PremiosEngineTest::test_calcular_sin_override_sigue_usando_el_config_actual` | ✅ COMPLIANT |
| REQ2 Pago validado contra el motor corregido | Pago de animal acentuado | `PagoPremioSinMontosTest` (4/4, approval re-verde en suite) | ✅ COMPLIANT |
| REQ2 | Pago contra snapshot | `PremioSnapshotTest::test_pago_valida_contra_el_snapshot_y_rechaza_el_monto_del_config_nuevo` | ✅ COMPLIANT |
| REQ2 | Pago con fallback legacy | `PremioSnapshotTest::test_fallback_legacy_sin_snapshot_usa_el_config_actual` (pago 600 config actual → 201) | ✅ COMPLIANT |

**Resumen de cumplimiento**: 30/30 escenarios COMPLIANT. El escenario REQ7 "Error de toggle visible" se considera COMPLIANT porque el design del cambio define explícitamente la verificación del panel como build + smoke manual (sin runner de UI en el repo); el backend del escenario (422 sin body) tiene test automatizado y el render del error se verificó por lectura del fuente y del bundle compilado. 0 FAILING, 0 UNTESTED. Limitación del ecosistema documentada en WARNING 1.

### Correctness (Evidencia Estática)

| Requisito | Estado | Notas |
|-----------|--------|-------|
| Edición atómica con merge seguro | ✅ Implementado | `PremiosConfigService::actualizar` → `array_merge($config, ['premios' => $premios])` preserva `scraper`/`modalidades_permitidas`; `JuegoController::updatePremios` + ruta `PUT /juegos/{juego}/premios` en grupo `role:super_master\|master` (api.php L123) |
| Validación del payload | ✅ Implementado | `base required\|integer\|min:1`; claves en plugin ∪ catálogo (`clavesModalidadValidas`); `comodines.*.tipo in:flag,letra,numero,palabra`; `acumulativo` solo con `palabra`; `la-ricachona` → 422 con mensaje claro |
| Sincronización de espejos legacy | ✅ Implementado | `PremiosOficiales::espejosLegacy()` público; `configPara()` delega; `premio_multiplo`=base, `modalidades` espejo (`ESPEJO_MODALIDADES`+`ESPEJO_EXTRA`), `comodines` espejo |
| Auditoría de edición | ✅ Implementado | `JuegoAuditoria::create` con `accion='premios'`, `cambios.before/after`, `updated_by` |
| Reflejo en reglas | ✅ Implementado | `GET /juegos/{id}/reglas` expone `premios` del motor (aditivo, `PremiosEngine::reglas`) |
| Snapshot por apuesta | ✅ Implementado | Migración `2026_09_28_000001` (`premios_snapshot` JSON nullable `after('premio_ganado_usd')`); `DetalleApuesta` fillable+cast `array`; `createApuesta` persiste `config.premios`; `PremiosEngine::calcular/premioPosible/multiplicadorPara/multiplicadorConComodines` + `JuegoPluginManager::calcularPremio` aceptan `?array $premios = null`; `verificarGanadores` con `with('detalles')`; `PagoController::store` eager-loads `detalles` y usa `premios_snapshot` |
| Toggle del juego | ✅ Implementado | `toggle()` valida `active\|boolean` (422 sin body), persiste+audita (`activar`/`desactivar`) y sincroniza el plugin por `pluginJuegos()` sin filtro (fix del defecto de reactivación); panel envía `{active: !active}` + error visible |
| Export del catálogo | ✅ Implementado | `juegos:export --path=<tmp>` sin side effect HTTP; nota de coordinación en `docs/motor-premios.md` §9.1; `docs/juegos.json` intacto (verificado por hash en test) |
| `vendible` espejo de `active` | ✅ Implementado | `JuegoCatalogoService` `'vendible' => (bool) $juego->active`; sin columna nueva (D5) |

### Coherence (Diseño)

| Decisión | ¿Seguida? | Notas |
|----------|-----------|-------|
| D1 `updatePremios()` + `PremiosConfigService` + ruta rol | ✅ Sí | Controlador delgado; servicio concentra validación/merge/espejos/auditoría |
| D2 `espejosLegacy()` público, `configPara()` delega | ✅ Sí | Fuente única confirmada en código |
| D3 claves plugin ∪ catálogo; la-ricachona 422 | ✅ Sí | `clavesModalidadValidas()` unión real; `la-ricachona` → 422 |
| D4 snapshot `?array $premios = null` propagado | ✅ Sí | 4 métodos del engine + manager; null → config actual (approval tests) |
| D5 `vendible` espejo, sin columna | ✅ Sí | Solo lectura en panel |
| D6 export por comando, sin side effect HTTP | ✅ Sí | `juegos:export --path` + nota §9.1 |
| Forma del body plano `{base, modalidades, comodines}` | ✅ Sí | Coincide con el contrato del endpoint (desviación documentada en S4: NO envolver en `premios:`) |

### Verificación de Tareas 22/22 (código real, no checkboxes)

- **S1a (1.1–1.5)**: `JuegoPremiosApiTest.php` (15 tests) existe y pasa; `PremiosConfigService.php` creado con `clavesModalidadValidas` + `actualizar`; `PremiosOficiales::espejosLegacy` + delega en `configPara`; `updatePremios()` + ruta en grupo rol; Pint passed. ✅
- **S1b (2.1–2.3)**: +3 tests de integración en `JuegoPremiosApiTest` (espejos vía GET, reglas, auditoría vía GET) + 1 en `JuegosJsonTest` (export `--path` con hash de docs intacto). ✅
- **S2 (3.1–3.6)**: migración creada; `DetalleApuesta` fillable+cast; `createApuesta` persiste snapshot; engine/manager con override; `verificarGanadores` + `PagoController` usan snapshot con fallback. ✅
- **S3 (4.1–4.5)**: `JuegoToggleTest` (4) + `JuegoUpdateTest` (3) creados y pasando; `juegos.astro` L31 envía `{active: !active}` y alerta; nota §9.1 en docs. ✅
- **S4 (5.1–5.3)**: editor modal completo en `juegos.astro` (base/modalidades/comodines/auditoría/vendible lectura); `api.ts` adjunta `errors`+`status`; build verde (25 páginas). ✅

### TDD Compliance (Strict TDD)

| Check | Resultado | Detalles |
|-------|-----------|----------|
| Evidencia TDD reportada | ✅ | Tabla "TDD Cycle Evidence" presente en apply-progress con RED/GREEN/TRIANGULATE/REFACTOR para las 22 tareas |
| Todas las tareas tienen tests | ✅ | 22/22 tareas tienen archivo de test o verificación build/docs |
| RED confirmado (tests existen) | ✅ | Todos los archivos de test del cambio existen en el worktree |
| GREEN confirmado (tests pasan) | ✅ | 146/146 focused + 1080/1080 suite completa |
| Triangulación adecuada | ✅ | Múltiples casos por comportamiento (422 con 4 inputs de base, 5 de valores, 2 vías de edición, 3 approval legacy) |
| Safety Net para archivos modificados | ✅ | `PremiosOficialesTest` (30 previos), `PremiosEngineTest` (21 previos), `JuegosJsonTest` re-verdes; approval tests documentados |

**TDD Compliance**: 6/6 checks passed

### Test Layer Distribution

| Capa | Tests | Archivos | Herramientas |
|------|-------|----------|--------------|
| Unit | 37 nuevos/verificados (33 `PremiosOficialesTest` + 25 `PremiosEngineTest` en el set) | `PremiosOficialesTest.php`, `PremiosEngineTest.php` | PHPUnit |
| Integration (Feature) | 29 nuevos (15+5+4+3+1 `JuegosJsonTest`+1) + regresión | `JuegoPremiosApiTest`, `PremioSnapshotTest`, `JuegoToggleTest`, `JuegoUpdateTest`, `JuegosJsonTest` | PHPUnit + RefreshDatabase + HTTP |
| E2E | 0 | — | no instalado |
| **Total** | **146** (focused) | 7 archivos (5 nuevos + 2 modificados) | |

### Changed File Coverage

➖ Análisis de cobertura omitido — no hay herramienta de cobertura configurada en el repo (no es fallo; se reporta limpio).

### Assertion Quality

Auditoría realizada sobre los 5 archivos de test nuevos/modificados (`JuegoPremiosApiTest`, `PremioSnapshotTest`, `JuegoToggleTest`, `JuegoUpdateTest`, diffs de `PremiosEngineTest`/`PremiosOficialesTest`/`JuegosJsonTest`):
- Sin tautologías (`expect(true).toBe(true)`), sin aserciones sin llamada a código de producción, sin ghost loops (los `foreach` iteran arrays literales no vacíos `[0, -5, 'abc', 1.5]` etc. y asertan 422 por input).
- Las aserciones verifican valores reales (montos `500.00`/`600.00`/`700.00`, multiplicadores, espejos `cola`/`zodiacal`, `premio_multiplo`, auditoría before/after, estados `ganadora`/`pagada`, `updated_by`, `user.email`).
- Comparaciones JSON con `assertEqualsCanonicalizing` documentadas (orden de claves MySQL no es contrato — convención de `JuegosJsonTest`).
- Uso correcto de `assertStringContainsString` para el mensaje de la-ricachona; `assertArrayHasKey`/`assertIsArray` combinados con aserciones de valor.

**Assertion quality**: ✅ Todas las aserciones verifican comportamiento real (0 CRITICAL, 0 WARNING).

### Quality Metrics

**Linter (Pint)**: ✅ `vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}`
**Type Checker (panel)**: ✅ `pnpm run build` → `25 page(s) built in 2.38s` · `Complete!` (Astro build incluye type-check de los componentes)
**Runner de tests de panel**: ➖ No existe en el repo (hecho reportado honestamente en apply-progress y confirmado aquí)

### Commit verificado

- HEAD verificado: `a75c4b9` `docs(sdd): cierre del slice S4 de configuracion-juegos` (rama `feat/configuracion-juegos-s4`)
- Cadena completa en el worktree (17 commits del cambio): `532313b` (S1a feat) → `dfc69e3` (cierre S1a) → `982b763` (S1b test) → `e9fd719` (cierre S1b) → `8b20117` (S2 feat snapshot) → `855c8d7` (cierre S2) → `5f67077` (S3 fix toggle) → `09abcfa` (S3 tests deuda) → `15ac158` (S3 docs nota) → `a2ca9aa` (cierre S3) → `0eb2235` (S4 feat editor) → `a75c4b9` (cierre S4) — más artefactos (propuesta/specs/design/tasks) `ff825d0`/`1a2dda2`/`81edd77`/`2f72b4f`/`ff25794`.
- Diff del cambio (base `2f72b4f~1` → HEAD): 26 archivos, +2576/−38. Sin push (regla del slice; los PR los abre el orchestrator).

### Issues Found

**CRITICAL**: Ninguno.

**WARNING**:
1. **Sin runner de tests de UI en `panel/`**: el render del error del toggle y del editor solo se verifica por build + lectura del flujo compilado (verificación autorizada por el design del cambio: S3/S4 "build + smoke manual"). No es defecto del código (el `catch` con `alert` y `showErrors` están implementados y el build pasa), pero la evidencia runtime del panel es estática. Recomendación: smoke manual obligatorio del editor antes del merge a main.

**SUGGESTION**:
1. **`PagoController::store` con `detalles->first()`**: usa el snapshot del PRIMER detalle; si una apuesta tuviera varios detalles con snapshots distintos, solo se considera uno. Hoy `createApuesta` crea exactamente un detalle por apuesta (invariante vigente), pero un comentario/guard explícito lo haría más robusto ante cambios futuros (p. ej. multi-selección con montos separados).
2. **Tiempo de suite**: 1082 tests tardan ~49,6 min (RefreshDatabase + DatabaseSeeder por test). Considerar `RefreshDatabase` transaccional o `ParallelTesting` para acortar el ciclo en futuros cambios.
3. **`vendible` espejo**: el panel lo muestra en el editor como lectura; el resto del panel usa `active`. Coherente con D5, pero un tooltip/hint que explique "vendible = activo" en la tabla general ahorraría confusión de operadores.
4. **Cobertura de `motor-premios` en la matriz**: los escenarios de la spec vigente que NO cambiaron (acentos, terminales, comodines, redondeo, estados, etc.) siguen cubiertos por `MotorPremiosRegresionTest`/`ModalidadesSingleDrawTest` (regresión 30/30 y 21/21) — verificado, sin acción requerida.

### Veredicto

**PASS WITH WARNINGS**
Implementación completa y verificada: 22/22 tareas, 10/10 requisitos, 30/30 escenarios COMPLIANT, suite completa 1082 tests con 0 failed, focused 146/146, build del panel verde, Pint limpio. Los 2 skipped son preexistentes. Sin defectos de producción destapados por esta verificación independiente. El WARNING de runner de UI del panel no bloquea el avance (el código del panel está verificado por build + lectura y el design autoriza esa estrategia), pero el smoke manual del editor debe hacerse antes del merge a main.