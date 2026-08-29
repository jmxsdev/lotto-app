```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:27e68540ff419c02ae6b9f55c2580fb0c0ed49e98001868ce8a05707d8a9123a
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 23/23
scenarios: 49/49
test_command: COMPOSER_PROCESS_TIMEOUT=900 composer test
test_exit_code: 0
test_output_hash: sha256:3bf284e9549922461ae1cc531a22939253994ec0051489940843b3bff4bbaf7a
build_command: npm run build (panel) && ./vendor/bin/pint --test (backend)
build_exit_code: 0
build_output_hash: sha256:1e97385951b716cd0bb416f2bb21e81b84522cba4b76e9503ed51a8050b96f7f
```

# Verify Report: jerarquia-agencias-locales

**Change**: jerarquia-agencias-locales
**Version**: draft (4 specs: jerarquia-agencias, alcance-super-banca, reportes-agencia, panel-jerarquia)
**Mode**: Standard
**Rama**: feat/jerarquia-agencias-f5 (ciclo completo F0→F5 + correcciones de auditoría + correcciones front create mode + panel masters; 35 commits sobre la tracker)
**Fecha**: 2026-08-29

## Resumen ejecutivo

Verificación INDEPENDIENTE del cambio completo. Las 34/34 tareas de `tasks.md` están marcadas `[x]` y el estado nativo reporta `verify: ready` sin `blockedReasons`. La suite completa `COMPOSER_PROCESS_TIMEOUT=900 composer test` pasa con **338 tests / 336 passed / 2 skipped / 1290 assertions** (coincide con el baseline declarado 338/336/2; los 2 skipped son pre-existentes y ajenos al cambio: `PluginIntegrationTest` y `ScrapeResultsJobTest`). `./vendor/bin/pint --test` pasa limpio. `npm run build` del panel construye **23 páginas** OK. Los 91 tests enfocados de los 10 archivos clave por spec pasan 91/91 con 411 assertions. **49/49 escenarios compliant (48 con test permanente + 1 con evidencia runtime de sonda descartable). 0 CRITICAL, 1 WARNING no bloqueante, 3 SUGGESTION. Verdict: PASS WITH WARNINGS.**

## Completeness

| Métrica | Valor |
|---------|-------|
| Tasks total | 34 |
| Tasks complete | 34 |
| Tasks incomplete | 0 |

Mapeo tareas→specs (apply-progress, sección "Mapeo tareas → specs (cierre del ciclo)"): las 4 capabilities están cubiertas — `jerarquia-agencias` (F0+F1), `alcance-super-banca` (F2), `reportes-agencia` (F3+F5), `panel-jerarquia` (F4+F5). Los work units adicionales (correcciones de auditoría 4/4, correcciones front create mode 3/3, panel masters) también constan con sus tests y evidencia en apply-progress.

## Build & Tests Execution

**Tests**: ✅ 336 passed / ❌ 0 failed / ⚠️ 2 skipped (pre-existentes, sin relación con el cambio)
```text
{"tool":"phpunit","result":"passed","tests":338,"passed":336,"assertions":1290,"duration_ms":130512,"skipped":2}
```

**Pint (backend)**: ✅ passed
```text
{"tool":"pint","result":"passed"}
```

**Build (panel)**: ✅ 23 páginas
```text
[build] 23 page(s) built in 1.12s
[build] Complete!
```

**Tests enfocados por spec (evidencia runtime por archivo, 91/91, 411 assertions)**:

| Test file | Tests | Resultado |
|---|---|---|
| `SuperBancaScopeTest` | 12 | ✅ 12/12 |
| `AgenciaScopeTest` | 23 | ✅ 23/23 |
| `AgenciaMasterScopeTest` | 8 | ✅ 8/8 |
| `TaquillaLocalObligatorioTest` | 4 | ✅ 4/4 |
| `TaquillaGrupoAgenciaConsistenciaTest` | 5 | ✅ 5/5 |
| `CrearEntidadConLimitesTest` | 7 | ✅ 7/7 |
| `MastersPanelTest` | 8 | ✅ 8/8 |
| `ReporteTest` | 7 | ✅ 7/7 |
| `CuadreCajaReportTest` | 14 | ✅ 14/14 |
| `EstadisticaTest` | 3 | ✅ 3/3 |
| **Total** | **91** | **✅ 91/91** |

**Coverage**: ➖ No disponible (el proyecto no configura umbral de cobertura; se verifica por escenarios).

## Spec Compliance Matrix

Total: **23 requirements / 49 scenarios** (contados de los archivos spec reales).

### Spec 1: jerarquia-agencias (6 req / 16 escenarios)

| Requisito | Escenario | Test | Resultado |
|-----------|-----------|------|-----------|
| R1 Entidad agencia (local físico) | Creación de agencia | `AgenciaApiTest::test_super_master_crea_agencia` | ✅ COMPLIANT |
| R1 | Código duplicado | `AgenciaApiTest::test_code_duplicado_422` | ✅ COMPLIANT |
| R1 | Sin configuración propia | `RoleAuthorizationTest::test_agencia_no_configura_limites` + `AgenciaController::store` sin `limites` + panel `agencias/detalle.astro` sin pestaña Límites (grep: 0 referencias) | ✅ COMPLIANT |
| R2 Asociación taquilla y usuario a agencia | Vinculación de taquilla | `AgenciaModelTest::test_agencia_pertenece_a_grupo_y_tiene_taquillas_y_usuarios` + `TaquillaLocalObligatorioTest::test_store_acepta_taquilla_con_agencia_id` | ✅ COMPLIANT |
| R2 | Borrado en cascada del local | `AgenciaDestroyCascadaTest` (5: cascada con historial, local sin taquillas, 403 agencia, 403 banca ajena, reportes con taquillas trashed) + `AgenciaApiTest::test_destroy_soft_delete_en_cascada_taquillas_y_desactiva_usuarios` | ✅ COMPLIANT |
| R2 | Conservación de grupo_id | Migración `000002_add_agencia_id_to_taquillas` solo añade columna (grupo_id intacto); ejercitado por toda la suite de taquillas | ✅ COMPLIANT (estático + runtime) |
| R3 Rol agencia en ambas fuentes | Doble registro | `UsersSeeder` (columna + `assignRole`) + `RolesAndPermissionsSeeder` (rol Spatie con 9 permisos); `AgenciaScopeTest::test_login_agencia_panel_accede_y_expone_agencia_id` ejercita ambas fuentes | ✅ COMPLIANT |
| R3 | Fuente faltante | Sin test permanente en suite. Verificado en RUNTIME con sonda temporal descartable (3/3): columna `agencia` sin rol Spatie → 403 en ruta `role:` (`CheckRole` lee `getRoleNames`); rol Spatie `agencia` con columna `taquilla` → 403 en login X-Panel "Las taquillas deben usar la app de escritorio."; ambas fuentes → login 200. SUGGESTION: promover la sonda a test permanente | ✅ COMPLIANT (runtime probe) |
| R4 Cadena de activación con agencia | Cadena completa activa | `ActivacionEntidadesTest::test_activacion_por_codigo_sigue_funcionando_con_cadena_activa` | ✅ COMPLIANT |
| R4 | Local desactivado | `ActivacionEntidadesTest::test_verify_mac_bloquea_cuando_local_inactivo` + `test_desactivar_local_pausa_sus_taquillas_sin_cascada` + `AgenciaScopeTest::test_login_agencia_local_inactivo_mensaje_cadena` | ✅ COMPLIANT |
| R4 | Grupo inactivo | `ActivacionEntidadesTest::test_verify_mac_bloquea_cuando_grupo_inactivo` + `AgenciaScopeTest::test_login_agencia_grupo_inactivo_mensaje_cadena` | ✅ COMPLIANT |
| R5 Backfill idempotente | Primera ejecución | `AgenciasBackfillTest::test_primera_ejecucion_crea_un_local_por_grupo_y_vincula_taquillas_y_usuarios` | ✅ COMPLIANT |
| R5 | Re-ejecución | `AgenciasBackfillTest::test_reejecucion_no_duplica_agencias` | ✅ COMPLIANT |
| R5 | Ejecución en producción | `AgenciasBackfillTest::test_produccion_exige_force` | ✅ COMPLIANT |
| R6 Actualización de tests agencia≡taquilla | Suite verde | `composer test` → 338/336/2 | ✅ COMPLIANT |
| R6 | Cobertura nueva | `AgenciaScopeTest` (23), `SuperBancaScopeTest` (12), `ActivacionEntidadesTest` extendido | ✅ COMPLIANT |

### Spec 2: alcance-super-banca (5 req / 11 escenarios)

| Requisito | Escenario | Test | Resultado |
|-----------|-----------|------|-----------|
| R1 Asociación master↔banca | Backfill de master | `AgenciasBackfillTest::test_master_id_se_asigna_desde_created_by_solo_si_creador_es_master` | ✅ COMPLIANT |
| R1 | Master sin bancas | `SuperBancaScopeTest::test_master_sin_bancas_no_ve_entidades` + `test_master_sin_bancas_no_accede_a_banca_ajena` + `AgenciaMasterScopeTest::test_master_sin_bancas_ve_cero_agencias` | ✅ COMPLIANT |
| R2 Alcance master en entidades | Listado scoped | `SuperBancaScopeTest::test_master_lista_solo_sus_bancas`, `test_master_lista_solo_sus_grupos_y_taquillas`, `test_master_lista_solo_usuarios_de_sus_bancas` | ✅ COMPLIANT |
| R2 | Super master global | `SuperBancaScopeTest::test_super_master_sigue_viendo_todo` | ✅ COMPLIANT |
| R3 Alcance en reportes y estadísticas | Reporte scoped | `SuperBancaScopeTest::test_master_reportes_ventas_totales_solo_sus_bancas` | ✅ COMPLIANT |
| R3 | Serie temporal scoped | `SuperBancaScopeTest::test_master_estadisticas_rendimiento_solo_sus_bancas` | ✅ COMPLIANT |
| R4 Alcance en apuestas, cierre y límites | Consultas scoped | `SuperBancaScopeTest::test_master_ve_solo_apuestas_de_sus_bancas`, `test_master_ve_solo_cierres_de_sus_bancas`, `test_master_ve_solo_limites_de_sus_bancas` | ✅ COMPLIANT |
| R4 | Sin bancas (vacío, no global) | `SuperBancaScopeTest::test_master_sin_bancas_no_ve_entidades` (lista vacía ⇒ `whereRaw('1=0')` en los ~23 puntos) | ✅ COMPLIANT |
| R5 Login X-Panel admite rol agencia | Agencia admitida | `AgenciaScopeTest::test_login_agencia_panel_accede_y_expone_agencia_id` | ✅ COMPLIANT |
| R5 | Local inactivo | `AgenciaScopeTest::test_login_agencia_local_inactivo_mensaje_cadena` | ✅ COMPLIANT |
| R5 | Taquilla rechazada | `AgenciaScopeTest::test_login_taquilla_panel_rechazado_mensaje_taquilla` | ✅ COMPLIANT |

### Spec 3: reportes-agencia (6 req / 10 escenarios)

| Requisito | Escenario | Test | Resultado |
|-----------|-----------|------|-----------|
| R1 ventasTotales por local | Agrupación por local | `ReporteTest::test_ventas_totales_nivel_agencia_agrupa_por_locales` | ✅ COMPLIANT |
| R1 | Agrupación por máquina | `ReporteTest::test_ventas_totales_nivel_taquilla_agrupa_por_maquinas` | ✅ COMPLIANT |
| R2 Cuadre de caja por local | Cuadre por local | `CuadreCajaReportTest::test_cuadre_nivel_agencia_agrupa_por_locales` + `test_cuadre_nivel_agencia_suma_maquinas_del_mismo_local` + `test_cuadre_usuario_grupo_solo_sus_locales` | ✅ COMPLIANT |
| R2 | Cuadre por máquina | `CuadreCajaReportTest::test_cuadre_nivel_taquilla_agrupa_por_maquinas` | ✅ COMPLIANT |
| R3 Rendimiento por agencia | Rendimiento por local | `ReporteTest::test_rendimiento_nivel_agencia_agrupa_por_locales` | ✅ COMPLIANT |
| R3 | Label de máquina | `ReporteTest::test_rendimiento_nivel_taquilla_usa_clave_taquilla` + `TerminologiaTest::test_rendimiento_nivel_taquilla_usa_clave_taquilla` | ✅ COMPLIANT |
| R4 Semántica de labels | Label de local | `TerminologiaTest::test_rendimiento_nivel_agencia_usa_clave_agencia` + `test_relacion_tickets_usa_clave_agencia_local_y_taquilla_maquina` + `test_vencidos_usa_clave_agencia_local_y_taquilla_maquina` | ✅ COMPLIANT |
| R4 | Label de máquina | `TerminologiaTest` (ídem) + `EstadisticaTest::test_time_series_agencia_solo_su_local` | ✅ COMPLIANT |
| R5 Sin configuración propia del local | Herencia de grupo/banca | `RoleAuthorizationTest::test_agencia_no_configura_limites`; el local no expone límites/vigencia/tiempo (AgenciaController sin rutas de config; panel sin pestaña) | ✅ COMPLIANT |
| R6 Actualización de tests de reportes | Suite de reportes verde | `CuadreCajaReportTest` 14/14 + `ReporteTest` 7/7 + suite completa 338/336/2 | ✅ COMPLIANT |

### Spec 4: panel-jerarquia (6 req / 12 escenarios)

| Requisito | Escenario | Test | Resultado |
|-----------|-----------|------|-----------|
| R1 Renames y labels de navegación | Navegación | Estático: `AdminLayout.astro` "Taquillas"→`/taquillas` (L180), "Agencias"→`/agencias` (L179); build 23 páginas OK (proyecto sin suite e2e) | ✅ COMPLIANT (build + estático) |
| R1 | Labels de rol | Estático: `ROLE_LABELS` agencia→'Agencia', taquilla→'Taquilla'; build OK | ✅ COMPLIANT (build + estático) |
| R2 Sidebar por rol | Sidebar de agencia | Estático: `AdminLayout.astro` L179-180 (agencia ve Taquillas; NO Agencias/Bancas/Grupos/Tasas) + `RoleAuthorizationTest::test_agencia_no_accede_a_bancas_ni_grupos` | ✅ COMPLIANT |
| R2 | Sidebar de master | Estático: `AdminLayout.astro` (master ve Agencias/Taquillas) + `SuperBancaScopeTest` (master solo sus bancas) | ✅ COMPLIANT |
| R3 Login y payload con agencia_id | Agencia aceptada | `AgenciaScopeTest::test_login_agencia_panel_accede_y_expone_agencia_id` + `login.astro` `ROLES_PERMITIDOS` con `agencia` | ✅ COMPLIANT |
| R3 | Payload con agencia_id | `AgenciaScopeTest::test_login_agencia_panel_accede_y_expone_agencia_id` (expone `agencia_id`) + `AuthController::login` devuelve `user` (fillable incluye `agencia_id`) | ✅ COMPLIANT |
| R4 Creación de taquillas por agencia | Creación en su local | `AgenciaScopeTest::test_agencia_crea_taquilla_en_su_local` | ✅ COMPLIANT |
| R4 | Intento en otro local | `AgenciaScopeTest::test_agencia_no_crea_taquilla_en_otro_local` | ✅ COMPLIANT |
| R4 | Usuario creado | `AgenciaScopeTest::test_agencia_crea_usuario_taquilla_en_su_local` (usuario con `agencia_id`) | ✅ COMPLIANT |
| R5 Selectores y formularios | Select de agencia | Estático: `taquillas/detalle.astro` (select Local `required`, sin "Sin local"), `usuarios.astro` (rol agencia + select local); `TaquillaAgenciaIdTest` 3/3; build OK | ✅ COMPLIANT |
| R5 | Niveles en reportes/cuadre | Estático: `ventas.astro`/`cuadre.astro` options "Agencia (Local)"/"Taquilla" + backend `ReporteTest`/`CuadreCajaReportTest` | ✅ COMPLIANT |
| R6 Actualización de tests de terminología | Terminología verde | `TerminologiaTest` 17/17 (dentro de suite 338/336/2) | ✅ COMPLIANT |

**Compliance summary**: 49/49 escenarios compliant (48 con test permanente en suite + 1 con evidencia runtime de sonda de verificación descartable).

## Correctness (Static Evidence)

| Requisito | Estado | Notas |
|-----------|--------|-------|
| Taquilla siempre con local (decisión cliente, fix auditoría 4) | ✅ Implementado | `TaquillaController::store` `agencia_id` `required` (L80); `update` `sometimes\|required` (L220) impide desasignar con null; panel select `required` |
| Consistencia grupo↔local | ✅ Implementado | `validarLocalPerteneceAlGrupo` (TaquillaController L317-327) en store y update → 422; `TaquillaGrupoAgenciaConsistenciaTest` 5/5 |
| Scope master en CRUD de agencias | ✅ Implementado | `AgenciaController` index/authorize* con `masterBancaGroupScope`/`masterCanAccessBanca`; lista vacía ⇒ `whereRaw('1=0')`; `AgenciaMasterScopeTest` 8/8 |
| master_id valida rol master | ✅ Implementado | Regla en `BancaController` store y update → 422; `BancaMasterIdTest` 4/4 |
| Límites en create mode | ✅ Implementado | `JuegoLimiteService` + stores en `DB::transaction`; `CrearEntidadConLimitesTest` 7/7 |
| Pestañas ocultas en create mode | ✅ Implementado | Panel: Usuarios/Locales/Taquillas ocultas sin entidad; build OK |
| Panel masters | ✅ Implementado | Filtro `role=master` (intersecta con alcance, nunca amplía), `User::bancas()`, guard jerárquico 403; `MastersPanelTest` 8/8; página `/masters` (23ª página) |
| Cadena de activación con agencia | ✅ Implementado | `ActivacionEfectivaService::estadoTaquilla` (L70-72) causa `'agencia'` entre flag propio y grupo; `mensajeCadenaInactiva` rama agencia (AuthController L110-126) |
| Doble fuente de rol | ✅ Implementado | Seeder columna + Spatie; `CheckRole` (Spatie) + controllers (columna/hasRole) |
| Backfill idempotente | ✅ Implementado | `AgenciasBackfill` `{--force} {--dry-run}`; tests 5/5 |

## Coherence (Design)

| Decisión | ¿Seguida? | Notas |
|----------|-----------|-------|
| D1 Agencia mínima nullable (passthrough) | ✅ Sí | Sin límites/vigencia/tiempo; `juego_limites` sin agencia_id |
| D2 `bancas.master_id` nullable + backfill desde created_by | ✅ Sí | + fix auditoría: validación rol master en store/update |
| D3 Backfill por comando artisan | ✅ Sí | `php artisan agencias:backfill` |
| D4 Conservar `taquillas.grupo_id` | ✅ Sí | Migración aditiva; joins intactos |
| D5 Rol en ambas fuentes | ✅ Sí | Columna + Spatie (CheckRole + controllers) |
| D6 Config del local posterior | ✅ Sí | Passthrough completo; agencia sin pestaña Límites |
| D7 `rendimientoTaquillas` acepta `nivel` | ✅ Sí | `taquilla` default (clave Taquilla), `agencia` (clave Agencia) |

**Desviaciones documentadas (ninguna rompe spec; todas con tests)**:
1. `AgenciaFactory` code `AG###` en vez de `A###` (evita colisiones con prefijos existentes) — apply-progress F0.
2. `TaquillaController::update` acepta `agencia_id` (aditivo, requerido por spec panel-jerarquia) — apply-progress F4.
3. `JuegoController`→`JuegoLimiteService` (refactor guiado por tests, 77/77 verdes) — apply-progress correcciones front.
4. `AgenciaController::destroy` aplica **cascada** (decisión del cliente tras el WARNING de esta verificación): soft-delete de taquillas + desactivación de usuarios rol taquilla + soft-delete del local, en transacción. Ya NO desvincula con `agencia_id = null` — apply-progress "FIX WARNING DESTROY AGENCIA".
5. Scope master en ESCRITURAS de límites (`destroyLimite`/`authorizeBancaLimitAccess`) — aditivo F2 (evita fuga de escritura).
6. `BancaController::store` auto-asigna `master_id = creador` — aditivo F2 (coherente con backfill).
7. Fix 4 usa `sometimes|required` en update (no rompe PUT parciales de Monedas/Vigencia) — apply-progress correcciones.
8. Sidebar "Masters" solo super_master + guard "master no crea master" — decisión documentada, coherente con alcance-super-banca.
9. La agencia NO acepta límites en su store (passthrough, spec jerarquia-agencias SHALL NOT) — desviación deliberada del enunciado del work unit, alineada con spec/design.

## Issues Found

**CRITICAL**: None.

**WARNING — RESUELTO en apply (work unit "FIX WARNING DESTROY AGENCIA")**:

1. ~~**`AgenciaController::destroy` deja taquillas sin local** (AgenciaController.php:165-166: `taquillas()->update(['agencia_id' => null])`).~~ **RESUELTO por decisión del cliente: BORRADO EN CASCADA.** El cliente autorizó explícitamente la cascada ("si necesita un borrado en cascada pues tendrá que ser así"): `destroy` ahora soft-deletea las taquillas del local (conservando `agencia_id`, nunca null), desactiva los usuarios rol taquilla (`active=false`, conservando `agencia_id`) y soft-deletea el local, todo en `DB::transaction`. Las apuestas/pagos/cierres NO se tocan (historial intacto y trazable). Los reportes que usan taquillas conservan el historial con entidades trashed: `ventasTotales`/`cuadreCaja` por joins directos, y `rendimientoTaquillas`/`relacionTickets`/`vencidos` con `withTrashed()` en los labels. Cubierto por `AgenciaDestroyCascadaTest` (5 tests) + `AgenciaApiTest` actualizado. La spec `jerarquia-agencias` R2 se actualizó de "set null" a "Borrado en cascada del local". Suite tras el fix: 343/341/2 (baseline 338/336/2 + 5 tests, 0 rotos), Pint limpio.

**SUGGESTION**:
1. Promover la sonda de "Fuente faltante" (3 casos: columna sin Spatie → 403 middleware; Spatie sin columna → 403 login; ambas → 200) a test permanente en la suite. La sonda se ejecutó y pasó 3/3 durante esta verificación y se eliminó (repo intacto).
2. ~~Definir el destino de las taquillas sin local tras `AgenciaController::destroy`~~ **RESUELTA**: el destino es la cascada (soft-delete de taquillas + desactivación de usuarios rol taquilla), decisión del cliente. Pendiente para endurecimiento NOT NULL: los usuarios rol agencia cuyo local fue borrado quedan con `agencia_id` apuntando a un local soft-deleted (no pueden ingresar al panel; su registro se conserva) — ver apply-progress "FIX WARNING DESTROY AGENCIA", riesgo documentado.
3. Antes del push: confirmar que `backend/.env.example` (credenciales dev) y `panel/.astro/settings.json` (timestamp Astro) modificados en el working tree NO se incluyan en ningún commit (documentados como pre-existentes en todos los slices).

## Verdict

**PASS WITH WARNINGS** — 34/34 tareas completas, suite 338/336/2 verde, Pint limpio, panel 23 páginas, 49/49 escenarios compliant (48 con test permanente + 1 con evidencia runtime de sonda descartable), 0 CRITICAL, 1 WARNING no bloqueante documentado, 0 desviaciones que rompan spec.

> **Actualización post-verify (apply)**: el WARNING 1 ("destroy deja taquillas sin local") quedó **RESUELTO** con el work unit "FIX WARNING DESTROY AGENCIA" (cascada autorizada por el cliente; ver sección Issues Found y apply-progress). Suite tras el fix: **343/341/2** (baseline 338/336/2 + 5 tests, 0 rotos), Pint limpio. La spec `jerarquia-agencias` R2 y su escenario de borrado se actualizaron a la cascada; el próximo `sdd-verify` puede re-emitir el reporte sin el WARNING.

**Nota de despliegue**: la tarea 6.3 [CLIENTE] está marcada como completada en su parte documental (checklist de despliegue y revisión post-backfill en apply-progress) pero el backfill NO se ha ejecutado en producción (exige `--force`; verificado solo en BD de desarrollo). Es una acción operativa del cliente en el momento del despliegue, no un defecto de implementación.