```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:0b676009eb335e3f9d6c8c7edcb571d20fd578b43cf17a3c2e48b4dee6847fb2
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 13/13
scenarios: 38/38
test_command: php artisan test --filter=LimitesEscrituraScopeTest && php artisan test --filter='LimitesScopedApiTest|AgenciaScopeTest'
test_exit_code: 0
test_output_hash: sha256:45e80f334795097d7eaa7923c75717f1966cbf1d72a38700893e1804c70c0a81
build_command: ./vendor/bin/pint --test
build_exit_code: 0
build_output_hash: sha256:d73ff2070992010277be694a3ba7fb295f9568b4bc610a73a191094818bef8ea
```

# Verification Report — alcance-jerarquico-84 (issue #84)

**Change**: `alcance-jerarquico-84`
**Modo**: Standard (sin runner Strict TDD activo)
**Rama**: `feat/84d-limites-agencia-regresion`
**Commit verificado**: `684f602` (`fix(limites): valida cadena grupo/taquilla para master (remediacion CRITICAL-1)`) — HEAD del worktree, árbol limpio
**Commit base de la verificación anterior**: `f68e7b4` (`docs(sdd): cierra tareas de alcance-jerarquico-84`)
**Fecha**: 2026-11-04
**Evidencia de ejecución**: provista por el usuario; el verificador **no re-ejecutó** tests, migraciones, seeders ni artisan (regla de la fase).

## Veredicto global

**PASS WITH WARNINGS**

La remediación `684f602` cierra **CRITICAL-1**: `authorizeEscrituraLimite` comparte `assertLimiteChainWithinBanca` entre las ramas `master` y `banca`, de modo que un master solo puede persistir límites cuyo `grupo_id`/`taquilla_id` cuelguen de la banca que administra. Los 5 tests nuevos de master cubren escritura propia, grupo ajeno, taquilla ajena, batch legacy y el orden 403→422.

El barrido de variantes de la misma clase (batch legacy, batch en modo scope, otros consumidores del helper) no encontró otra fuga: el modo scope deriva `banca_id` de la propia entidad expandida, y el helper no tiene otros llamadores fuera de `JuegoController`.

Quedan **3 WARNING** (2 de profundidad de aserción y 1 residual de datos/destroy) y **0 CRITICAL**. Los 46/46 tasks están completos y 38/38 escenarios de spec tienen test runtime verde; en 2 escenarios la rama menos usada del THEN no tiene aserción dedicada (WARNING-1/2), sin comportamiento faltante identificado.

## Ciclo de remediación (CRITICAL-1 → CERRADO)

### Defecto original (verificación previa, evidencia `sha256:d85408b...`/`sha256:f956835...`)

La rama `master` de `authorizeEscrituraLimite` solo validaba `banca_id` con `authorizeBancaLimitAccess()` y retornaba. Un master de la banca A podía enviar `grupo_id`/`taquilla_id` de la banca B: la fila quedaba etiquetada `banca_id = A` con entidad ajena, y la resolución efectiva (`ApuestaService`, precedencia taquilla > grupo > banca) filtra por `grupo_id`/`taquilla_id` sin `banca_id`, por lo que el límite podía mostrarse y aplicarse en el tenant ajeno.

### Remediación aplicada — commit `684f602`

| Archivo | Cambio |
|---|---|
| `backend/app/Http/Controllers/Api/JuegoController.php` | Extrae la validación de cadena a `assertLimiteChainWithinBanca(int $bancaId, ?int $grupoId, ?int $taquillaId)` (líneas 909-934) y la invoca en la rama `master` (línea 868) y en la rama `banca` (línea 878). Elimina el bloque duplicado de la rama `banca`. |
| `backend/tests/Feature/LimitesEscrituraScopeTest.php` | Agrega helper `masterUser()` (asigna `master_id` de `BT001`) y 5 tests de master (líneas 174-329). |

Comportamiento del helper: si `grupo_id` está presente, exige `grupo.banca_id === $bancaId`; si `taquilla_id` está presente, exige que la taquilla cuelgue de la banca y, cuando se indicó `grupo_id`, que pertenezca exactamente a ese grupo. Ambos fallos abortan con 403.

### Tests nuevos (5)

| Test | Cubre |
|---|---|
| `test_master_escribe_en_su_propio_grupo_y_taquilla` | Camino feliz: grupo propio (200/201) y grupo+taquilla propios, con `assertDatabaseHas` del `banca_id`/`grupo_id`/`taquilla_id` persistidos |
| `test_master_con_grupo_de_otra_banca_403_sin_persistir` | Cadena incoherente vía `grupo_id` → 403 + `assertDatabaseMissing` |
| `test_master_con_taquilla_de_otra_banca_403_sin_persistir` | Cadena incoherente vía `taquilla_id` sin grupo → 403 + `assertDatabaseMissing` |
| `test_batch_master_rechaza_grupo_de_otra_banca_sin_persistir` | `POST /limites/batch` modo legacy: 403 antes de la transacción; ni siquiera el ítem propio se escribe |
| `test_master_fuera_de_alcance_con_restrictividad_invalida_403_no_422` | Orden: banca ajena + payload que violaría restrictividad → 403, nunca 422 |

Los tests son significativos, no vacuos: `masterUser()` solo recibe el rol `master` (no `super_master`) y `jerarquiaAjena()` crea una banca por factory sin `master_id`, por lo que `masterCanAccessBanca()` es realmente falsa para ella.

### Cierre

**CRITICAL-1: CERRADO.** La rama `master` ya no es más laxa que la rama `banca`; ambas ejecutan la misma validación de cadena, y hay cobertura runtime para los cuatro vectores identificados (PUT con grupo ajeno, PUT con taquilla ajena, batch legacy, orden 403/422).

## Barrido de variantes de la misma clase

| Variante | Estado | Evidencia |
|---|---|---|
| `master` en `PUT /limites/{juego}` con grupo/taquilla ajenos | ✅ Cubierto | `test_master_con_grupo_de_otra_banca_403_sin_persistir`, `test_master_con_taquilla_de_otra_banca_403_sin_persistir` |
| `master` en `batchLimites` **modo legacy** | ✅ Cubierto | El loop autoriza cada ítem con `authorizeEscrituraLimite` (líneas 570-577) → mismo helper; `test_batch_master_rechaza_grupo_de_otra_banca_sin_persistir` |
| `master` en `batchLimites` **modo scope** | ✅ Estructuralmente inmune | En modo scope los ítems no pueden llevar `banca_id`/`grupo_id`/`taquilla_id` (422, líneas 552-559); la cadena se deriva de la entidad expandida en `resolverCadenaObjetivo()` (`JuegoLimiteService` líneas 232-259), y la expansión está acotada por `masterBancaIds()`/`entidadDentroDelAlcance()` (líneas 630-706, 998-1063). Aunque el actor fuera otro, la fila siempre nace coherente. Cobertura existente: `test_scope_bancas_master_ve_solo_sus_bancas`, `test_batch_scope_root_fuera_de_jerarquia_banca_403` |
| Otro endpoint que use el helper | ✅ No existe | `grep -rn "authorizeEscrituraLimite\|assertLimiteChainWithinBanca" app/ tests/ routes/` → solo `JuegoController.php` |
| Escritura de límites fuera de `JuegoController` | ✅ No existe | Únicos writes: `JuegoController::updateLimites` (línea 484) y `JuegoLimiteService::aplicarItemLimite` (línea 163), ambos alcanzables solo vía los dos endpoints autorizados |
| `super_master` con cadena incoherente | ⚠️ Por diseño (SUGGESTION-5) | `authorizeEscrituraLimite` retorna antes de validar la cadena para `super_master` (línea 858-860); no hay escalada de privilegios, pero puede crear filas incoherentes |
| `master` en `destroyLimite` sobre fila incoherente preexistente | ⚠️ Residual (WARNING-3) | `destroyLimite` solo valida `banca_id` (líneas 801-803); solo es alcanzable si la fila ya existía mal formada por el bug previo |

## Evidencia de build & tests (post-remediación)

**Tests focales** — exit code 0:

```text
cd backend
php artisan test --filter=LimitesEscrituraScopeTest
→ Tests: 13 passed (8 banca + 5 master)

cd backend
php artisan test --filter='LimitesScopedApiTest|AgenciaScopeTest'
→ Tests: 54 passed (510 assertions)
```

Verificación de consistencia del conteo: `LimitesEscrituraScopeTest` declara 13 métodos `test_` (8 banca + 5 master), `LimitesScopedApiTest` 31 y `AgenciaScopeTest` 23 → 54. Coincide con la evidencia reportada.

**Build/estilo** — exit code 0:

```text
cd backend
./vendor/bin/pint --test
→ passed (verificado tras la remediación)
```

> **Base de los hashes**: `test_output_hash` = sha256 del bloque de tests de arriba; `build_output_hash` = sha256 del bloque de pint; `evidence_revision` = sha256 de la concatenación de ambos bloques (test + línea en blanco + build). Son hashes del texto de evidencia suministrado, no de stdout crudo: la fase prohíbe re-ejecutar la suite.

**Alcance de la evidencia (importante)**

- La **suite completa** reportada (1422 tests, 7996 assertions, 2 skipped, OK) es **pre-remediación** y corresponde al commit `f68e7b4`. **No se re-ejecutó después de `684f602`**.
- La evidencia **post-fix** es la **focalizada** de arriba (13 + 54 tests) más `pint --test`.
- Justificación del alcance: el diff de remediación está aislado a `authorizeEscrituraLimite`/`assertLimiteChainWithinBanca` en `JuegoController` y a un archivo de tests; no toca modelos, migraciones, rutas ni servicios compartidos. El riesgo de regresión fuera de límites es bajo, pero la confirmación formal requiere una corrida completa que esta fase no ejecuta.

| WU | Suites | Resultado reportado |
|---|---|---|
| 1a | `CreatedByTest` | 7 OK |
| 1b | `AlcanceJerarquicoUsuariosTest` | 16 OK (ver SUGGESTION-4: el archivo en HEAD declara 20 métodos) |
| 2 | `ApuestaScopePolicyTest` + `PagoEgresoScopeTest` + `TicketMasterScopeTest` | 17 OK |
| 2 | regresión `SuperBancaScopeTest`/`PagoDevolucion*`/`EliminacionApuestasTest`/`ApuestaPolicyAnulacionTest`/`TicketGanadoresTest`/`TicketEliminacionTest` | 78 OK |
| 3 | `LimitesEscrituraScopeTest` + `AgenciaSelfUpdateTest` + `TicketAnulacionRegresionTest` | 16 nuevas + regresión (`LimitesScopedApiTest`/`AgenciaScopeTest`) = 54 OK |
| Remed. | `LimitesEscrituraScopeTest` (post-fix) | 13 OK |
| Remed. | `LimitesScopedApiTest` + `AgenciaScopeTest` (post-fix) | 54 OK (510 assertions) |

## Completitud

| Métrica | Valor |
|---|---|
| Tareas totales | 46 |
| Tareas completas | 46 |
| Tareas incompletas | 0 |
| Requisitos (specs deltas) | 13 |
| Escenarios | 38 |
| Escenarios con test runtime verde | 38 |
| Escenarios con aserción parcial de ramas | 2 (WARNING-1/2) |
| Hallazgos CRITICAL | 0 (CRITICAL-1 cerrado por `684f602`) |
| Hallazgos WARNING | 3 |
| Hallazgos SUGGESTION | 5 |

Verificación de tareas: `grep` sobre `tasks.md` → `total=46, checked=46, unchecked=0`.

**Criterio de conteo de escenarios**: un escenario cuenta como completo cuando tiene al menos un test de cobertura que pasó en runtime y ejerce el `THEN` del escenario. Dos escenarios (4 y 32) enumeran varias acciones en el `WHEN`; la acción principal está cubierta por un test verde, pero una rama (alta de usuario por taquilla; update/toggle/delete de taquilla ajena) no tiene aserción dedicada. Se registran como completos con `WARNING` de profundidad de aserción, no como `UNTESTED`: el comportamiento está implementado y el guard compartido es el mismo que el test verde ejercita.

## Matriz requisito → evidencia → estado

### Capability `alcance-jerarquico` (6 requisitos)

| Requisito | Evidencia de implementación | Tests de cobertura | Estado |
|---|---|---|---|
| Matriz CRUD jerárquica uniforme | `app/Policies/UserPolicy.php` (`RANKS`, `canManageTarget`, `isWithinSubtree`); `UserController` index/show/update/destroy; `AppServiceProvider` registra `Gate::policy(User::class, UserPolicy::class)` | `AlcanceJerarquicoUsuariosTest`, `GestionUsuariosTest`, `RoleAuthorizationTest`, `MastersPanelTest` | ✅ CUMPLE (WARNING-1: rama de alta por taquilla sin aserción) |
| Prohibición de pares y superiores | `UserPolicy::canCreate/canAssign`: `rank >= actorRank → false`; `validateRoleBindings` + `authorizeEntityBinding` | `test_banca_no_puede_crear_otra_banca`, `test_grupo_no_puede_crear_otro_grupo`, `test_agencia_no_puede_crear_otra_agencia` | ✅ CUMPLE |
| `users.created_by` (metadata de fila) | Migración `2026_11_04_000001_add_created_by_to_users_table.php` (FK nullable, índice, `nullOnDelete`); `User::creator()/createdUsers()`; `created_by` server-side en los 4 stores; seeders `UsersSeeder`/`E2eSeeder` | `CreatedByTest` (7) | ✅ CUMPLE |
| Super_master intocable y no borra otro super_master | `UserPolicy::delete` retorna `false` si `target->hasRole('super_master')` o self; `update` delega en `canManageTarget` | `test_master_no_puede_editar_super_master_y_password_intacto`, `test_master_no_puede_eliminar_super_master`, `test_super_master_no_puede_eliminar_a_otro_super_master` | ✅ CUMPLE |
| Master no borra a su creador | `canManageTarget`: `(int) $target->id === (int) $actor->created_by → false` | `test_master_no_puede_eliminar_a_su_creador` | ✅ CUMPLE |
| Regresión de anulación (caso 5) | Sin cambios de producción; `TicketAnulacionService` ya consulta líneas vivas y policy por línea | `TicketAnulacionRegresionTest` | ✅ CUMPLE |

### Capability `pagos` (1 requisito)

| Requisito | Evidencia de implementación | Tests de cobertura | Estado |
|---|---|---|---|
| Egreso ownership por rol | `PagoController::store`: 422 de tipo no-egreso antes de cargar; `Gate::denies('view', $apuesta)` dentro del `lockForUpdate` y antes del estado; body 403 `{"message":"No autorizado."}`; `super_master` global vía `ApuestaPolicy` | `PagoEgresoScopeTest` (banca/grupo/master ajenos 403 sin mutación; propia 201; no-egreso 422; doble egreso 422); super global en `PagoEgresoUnicoMutanteTest` | ✅ CUMPLE |

### Capability `alcance-super-banca` (2 requisitos)

| Requisito | Evidencia de implementación | Tests de cobertura | Estado |
|---|---|---|---|
| `ApuestaPolicy::view/delete` acotado para master | `ApuestaPolicy::poseeApuesta()`: super global; master solo `masterCanAccessBanca`; sin bancas → false; apuesta sin cadena → false. `view`/`delete`/`motivoNoAnulable` comparten la regla | `ApuestaScopePolicyTest` (5) | ✅ CUMPLE |
| Alcance master en tickets | `TicketController::index/show/ganadores` usan `masterBancaChainScope()`/`masterCanAccessBanca`; sin bancas → `whereRaw('1=0')`; `agencia` fuera de la ruta `/tickets` | `TicketMasterScopeTest` (5); regresión `TicketGanadoresTest`/`TicketEliminacionTest` | ✅ CUMPLE |

### Capability `jerarquia-agencias` (2 requisitos)

| Requisito | Evidencia de implementación | Tests de cobertura | Estado |
|---|---|---|---|
| Agencia edita su propia ficha | `AgenciaController::update`: rama `agencia` solo si `authorizeAgenciaAccess` (propia); `hasAny(['code','grupo_id','active']) → 403`; valida/persiste solo datos de contacto/fiscales. `store/toggle/destroy` siguen con `authorizeGestion` (agencia → 403) | `AgenciaSelfUpdateTest` (7) | ✅ CUMPLE |
| Agencia gestiona taquillas de su local | `TaquillaController::store/update/toggle/destroy` derivan `agencia_id`/`grupo_id` del local para rol agencia; `authorizeTaquillaAccess` en show/update/toggle/destroy (líneas 217, 229, 303, 317) | `AgenciaScopeTest::test_agencia_crea_taquilla_en_su_local`, `test_agencia_no_crea_taquilla_en_otro_local`, `test_agencia_no_accede_taquilla_de_otro_local`; `TaquillaAgenciaIdTest` | ✅ CUMPLE (WARNING-2: ramas editar/eliminar sin aserción) |

### Capability `limites` (2 requisitos)

| Requisito | Evidencia de implementación | Tests de cobertura | Estado |
|---|---|---|---|
| Alcance de escritura de límites (`banca` en el spec; `master` endurecido por remediación) | `JuegoController::authorizeEscrituraLimite` (líneas 856-902): rama `master` valida `banca_id` + cadena; rama `banca` valida `banca_id` propio + cadena; ambas vía `assertLimiteChainWithinBanca` (líneas 909-934). `batchLimites` legacy autoriza por ítem (líneas 570-577) | `LimitesEscrituraScopeTest`: banca propia 200 (banca/grupo/taquilla), grupo ajeno 403, banca ajena 403, taquilla de otro grupo 403, batch con ítem ajeno 403; **master**: propia 200 (grupo y grupo+taquilla), grupo ajeno 403, taquilla ajena 403, batch legacy ajeno 403 sin persistir | ✅ CUMPLE (banca + master) |
| Autorización antes que restrictividad | `updateLimites` llama `authorizeEscrituraLimite` (líneas 464-469) antes de `validarRestrictividadLimite` (líneas 471-482); `batchLimites` autoriza cada ítem antes de `validarItems`/restrictividad | `test_actor_fuera_de_alcance_con_restrictividad_invalida_403_no_422`, `test_actor_en_alcance_con_restrictividad_invalida_422`, `test_master_fuera_de_alcance_con_restrictividad_invalida_403_no_422` | ✅ CUMPLE (banca/grupo/master) |

## Matriz de escenarios (38)

### `alcance-jerarquico` (14)

| # | Escenario | Evidencia (test) | Resultado |
|---|---|---|---|
| 1 | Rol acotado a su subárbol | `GestionUsuariosTest::test_grupo_no_puede_crear_usuario_para_agencia_de_otro_grupo` (403); `RoleAuthorizationTest::test_grupo_can_only_create_taquillas_in_its_own_grupo` | ✅ COMPLIANT |
| 2 | Banca borra usuario de su subárbol | `AlcanceJerarquicoUsuariosTest::test_banca_puede_eliminar_usuario_de_rango_menor_de_su_banca` (200) | ✅ COMPLIANT |
| 3 | Banca no borra un par | `test_banca_no_puede_eliminar_banca_par_de_otra_banca` (403) | ✅ COMPLIANT |
| 4 | Taquilla solo lectura | `test_taquilla_solo_puede_ver_su_propio_usuario` (GET propio 200 / ajeno 403), `test_taquilla_no_puede_editar_ni_borrar_su_usuario` (PUT/DELETE 403); alta de usuario por taquilla sin aserción dedicada | ✅ COMPLIANT (WARNING-1: rama "crear" sin aserción) |
| 5 | Banca crea banca | `test_banca_no_puede_crear_otra_banca` (403) | ✅ COMPLIANT |
| 6 | Grupo crea grupo | `test_grupo_no_puede_crear_otro_grupo` (403) | ✅ COMPLIANT |
| 7 | Agencia crea agencia | `test_agencia_no_puede_crear_otra_agencia` (403) | ✅ COMPLIANT |
| 8 | Usuario creado registra su creador | `CreatedByTest::test_user_store_registra_created_by_del_actor` + stores banca/grupo/taquilla | ✅ COMPLIANT |
| 9 | Backfill de usuarios existentes | `CreatedByTest::test_usuarios_preexistentes_quedan_sin_created_by` (null) | ✅ COMPLIANT |
| 10 | Master edita contraseña de super_master | `AlcanceJerarquicoUsuariosTest::test_master_no_puede_editar_super_master_y_password_intacto` (403 + hash intacto) | ✅ COMPLIANT |
| 11 | Master borra super_master | `test_master_no_puede_eliminar_super_master` (403) | ✅ COMPLIANT |
| 12 | Super_master borra otro super_master | `test_super_master_no_puede_eliminar_a_otro_super_master` (403) | ✅ COMPLIANT |
| 13 | Master borra a su creador | `test_master_no_puede_eliminar_a_su_creador` (403) | ✅ COMPLIANT |
| 14 | Ticket sin líneas vivas rechazado | `TicketAnulacionRegresionTest` (422, sin devolución ni mutación) | ✅ COMPLIANT |

### `pagos` (5)

| # | Escenario | Evidencia (test) | Resultado |
|---|---|---|---|
| 15 | Banca paga apuesta ajena | `PagoEgresoScopeTest::test_banca_paga_apuesta_ajena_403_sin_mutacion` | ✅ COMPLIANT |
| 16 | Grupo paga apuesta ajena | `test_grupo_paga_apuesta_ajena_403_sin_mutacion` | ✅ COMPLIANT |
| 17 | Master paga apuesta de banca ajena | `test_master_paga_apuesta_ajena_403_sin_mutacion` | ✅ COMPLIANT |
| 18 | Banca paga apuesta propia | `test_banca_paga_apuesta_propia_201` (premio del motor 300) | ✅ COMPLIANT |
| 19 | Super_master global | `PagoEgresoUnicoMutanteTest::test_solo_el_egreso_muta_estado_premio_y_ticket` (super paga apuesta de taquilla arbitraria) | ✅ COMPLIANT |

### `alcance-super-banca` (8)

| # | Escenario | Evidencia (test) | Resultado |
|---|---|---|---|
| 20 | Master ve apuesta de su banca | `ApuestaScopePolicyTest::test_master_ve_y_anula_una_apuesta_de_su_banca` | ✅ COMPLIANT |
| 21 | Master ve apuesta de banca ajena | `test_master_no_ve_ni_anula_apuestas_de_banca_ajena` (403 `No autorizado.`) | ✅ COMPLIANT |
| 22 | Master sin bancas | `test_master_sin_bancas_queda_denegado` | ✅ COMPLIANT |
| 23 | Super_master global | `test_super_master_conserva_alcance_global` | ✅ COMPLIANT |
| 24 | Master lista tickets de sus bancas | `TicketMasterScopeTest::test_master_solo_ve_tickets_de_sus_bancas` | ✅ COMPLIANT |
| 25 | Master consulta ticket ajeno | `test_master_consulta_ticket_ajeno_403` | ✅ COMPLIANT |
| 26 | Master consulta ganadores de sus bancas | `test_master_ganadores_solo_sus_bancas` | ✅ COMPLIANT |
| 27 | Super_master global en tickets | `test_super_master_ve_todos_los_tickets` | ✅ COMPLIANT |

### `jerarquia-agencias` (5)

| # | Escenario | Evidencia (test) | Resultado |
|---|---|---|---|
| 28 | Agencia edita su ficha | `AgenciaSelfUpdateTest::test_agencia_edita_su_propia_ficha` (200, persiste) | ✅ COMPLIANT |
| 29 | Agencia reasigna su grupo | `test_agencia_no_reasigna_grupo_403` | ✅ COMPLIANT |
| 30 | Agencia crea/elimina agencia | `test_agencia_no_crea_alterna_ni_elimina_agencias` (403) | ✅ COMPLIANT |
| 31 | Agencia crea taquilla en su local | `AgenciaScopeTest::test_agencia_crea_taquilla_en_su_local` (201, agencia/grupo derivados) | ✅ COMPLIANT |
| 32 | Agencia gestiona taquilla ajena | `AgenciaScopeTest::test_agencia_no_crea_taquilla_en_otro_local` (crear fuera 403), `test_agencia_no_accede_taquilla_de_otro_local` (GET 403); update/toggle/delete comparten `authorizeTaquillaAccess` (líneas 229/303/317) pero no tienen aserción dedicada | ✅ COMPLIANT (WARNING-2: ramas editar/eliminar sin aserción) |

### `limites` (6)

| # | Escenario | Evidencia (test) | Resultado |
|---|---|---|---|
| 33 | Banca escribe en su propio grupo/taquilla | `LimitesEscrituraScopeTest::test_banca_escribe_en_su_propio_grupo_y_taquilla`, `test_banca_escribe_en_su_propia_banca`; master propio: `test_master_escribe_en_su_propio_grupo_y_taquilla` | ✅ COMPLIANT |
| 34 | Banca escribe con `grupo_id` de otra banca | `test_banca_con_grupo_de_otra_banca_403`, `test_banca_sobre_otra_banca_403`; master: `test_master_con_grupo_de_otra_banca_403_sin_persistir` | ✅ COMPLIANT |
| 35 | Banca escribe con `taquilla_id` de otro grupo | `test_banca_con_taquilla_de_otro_grupo_403` (dos variantes); master: `test_master_con_taquilla_de_otra_banca_403_sin_persistir` | ✅ COMPLIANT |
| 36 | Batch comparte el alcance | `test_batch_rechaza_item_de_otra_banca_sin_persistir` (403 antes de validar tope); master: `test_batch_master_rechaza_grupo_de_otra_banca_sin_persistir` | ✅ COMPLIANT |
| 37 | Actor fuera de alcance con payload restrictivo inválido | `test_actor_fuera_de_alcance_con_restrictividad_invalida_403_no_422`; master: `test_master_fuera_de_alcance_con_restrictividad_invalida_403_no_422` | ✅ COMPLIANT |
| 38 | Actor en alcance con restrictividad inválida | `test_actor_en_alcance_con_restrictividad_invalida_422` | ✅ COMPLIANT |

**Resumen de escenarios**: 38/38 con test runtime verde; 0 FAILING; 0 UNTESTED; 2 con profundidad de aserción parcial (WARNING-1/2, no bloqueantes).

## Cobertura por caso 1–7

| Caso | Descripción | Archivos de test | Estado |
|---|---|---|---|
| 1 | Master edita/borra super y contraseña | `AlcanceJerarquicoUsuariosTest` (6 tests de super/creador/par) | ✅ Cubierto |
| 2 | `ApuestaPolicy::view/delete` global para master | `ApuestaScopePolicyTest` (5) | ✅ Cubierto |
| 3 | `PagoController::store` ownership por rol | `PagoEgresoScopeTest` (7) + `PagoEgresoUnicoMutanteTest` (super global) | ✅ Cubierto |
| 4 | `TicketController` master global | `TicketMasterScopeTest` (5) | ✅ Cubierto |
| 5 | Anulación sin líneas vivas | `TicketAnulacionRegresionTest` (1) | ✅ Cubierto (solo regresión, sin reimplementar) |
| 6 | `authorizeEscrituraLimite` grupo/taquilla (banca + master) | `LimitesEscrituraScopeTest` (8 de banca + 5 de master de alcance/cadena) | ✅ Cubierto (banca y master) |
| 7 | Orden 403→422 | `LimitesEscrituraScopeTest` (2 de orden, uno por rol) | ✅ Cubierto |

## Verificación de contratos (no regresión)

| Contrato | Evidencia | Estado |
|---|---|---|
| 403 con body `{"message":"No autorizado."}` en apuestas/pagos/tickets | `bootstrap/app.php:66-68` normaliza `AuthorizationException`/`UnauthorizedException`; `ApuestaController` y `TicketController` devuelven el body explícito; tests `ApuestaScopePolicyTest`/`PagoEgresoScopeTest`/`TicketMasterScopeTest` lo assertan | ✅ Conservado |
| 422 para tipo no-egreso y doble egreso | `PagoController::store` rechaza `tipo !== 'egreso'` antes de cargar la apuesta; doble egreso 422 por estado; `PagoEgresoScopeTest` | ✅ Conservado |
| 403 antes de 422 en límites | `updateLimites` autoriza antes de restrictividad; `batchLimites` autoriza por ítem antes de `validarItems`; tests de orden para banca y master | ✅ Conservado y ampliado a master |
| `created_by` no mass-assignable desde payload | `UserController::store` construye `$data` explícito y setea `$user->id`; `update` usa `only(['name','email','active'])`; stores de entidades lo setean server-side; `CreatedByTest` y `AlcanceJerarquicoUsuariosTest` lo prueban | ✅ Conservado |
| `super_master` no borrable por nadie | `UserPolicy::delete` corta antes de cualquier rol; tests master/super/self | ✅ Conservado |
| Agencia no cambia `code`/`grupo_id`/`active` ni gestiona agencias ajenas | `AgenciaController::update` guard `hasAny`; `authorizeAgenciaAccess`; `AgenciaSelfUpdateTest` | ✅ Conservado |
| Mensajes/status de `authorizeEscrituraLimite` para banca sin cambios | La rama `banca` conserva el orden y los mensajes (`No tienes acceso a esta banca./este grupo./esta taquilla.`); solo se movió el bloque al helper | ✅ Conservado |

## Coherencia con el diseño

| Decisión | ¿Seguida? | Notas |
|---|---|---|
| `UserPolicy` concentra view/update/delete/canCreate/canAssign | ✅ Sí | Contrato exacto; `authorizeEntityBinding` delega; helpers `resolve*` preservados |
| `created_by` FK nullable, fillable, relaciones, asignación server-side | ✅ Sí | Migración + modelo + 4 stores + seeders; test de payload ignorado |
| Apuestas: master por banca; sin bancas → false; super global; pago usa `view` en el lock antes del estado | ✅ Sí | `poseeApuesta` única fuente; códigos de anulación intactos |
| Tickets: master scoped; agencia fuera | ✅ Sí (con reserva) | Ruta excluye agencia; `autorizarScope` aún lista `agencia` (SUGGESTION-2) |
| Límites: coherencia grupo→banca/taquilla→grupo + alcance; 403 antes de 422 | ✅ Sí (tras remediación) | La coherencia ahora aplica a `master` y `banca` por el mismo helper; el modo scope la garantiza por construcción |
| Agencia: edita contacto/fiscales; `code`/`grupo_id`/`active` inmutables; store/toggle/destroy bloqueados | ✅ Sí | Test dedicado |
| Borrado de usuarios por rango/subárbol/creados | ✅ Sí | Matriz implementada y testeada |

**Desviación de diseño**: ninguna nueva. La remediación es un refactor de extracción (helper compartido) que elimina duplicación y no altera contratos externos.

## Hallazgos

### CRITICAL

**Ninguno.** CRITICAL-1 (`master` inyectando límites en el subárbol de otra banca) queda **cerrado** por `684f602` con código y tests runtime (ver "Ciclo de remediación").

### WARNING

**WARNING-1 — Escenario "Taquilla solo lectura" con aserción de rama incompleta**

- `AlcanceJerarquicoUsuariosTest` cubre `GET` propio (200), `GET` ajeno (403), `PUT`/`DELETE` propio (403) (líneas 349-380), pero no un intento de **alta** (`POST /api/v1/users`) por taquilla, que la ruta permite (`role:...|taquilla`) y `UserPolicy::canCreate` deniega (`! $actor->hasRole('taquilla')`). Tampoco hay test de taquilla contra rutas de entidades (bancas/grupos/agencias/taquillas) — allí el 403 lo impone el middleware de ruta. Comportamiento implementado; el escenario se cuenta como completo por los tests verdes de lectura/edición/borrado, con esta rama registrada como WARNING de profundidad de aserción. **Sigue abierto** (no fue parte de la remediación).

**WARNING-2 — Escenario "Agencia gestiona taquilla ajena" con aserción de rama incompleta**

- `AgenciaScopeTest` asserta `GET /taquillas/{ajena}` → 403 y el alta en local ajeno → 403, pero no `PUT`/`PATCH toggle`/`DELETE` sobre una taquilla ajena. El guard compartido `authorizeTaquillaAccess` se usa en show/update/toggle/destroy (`TaquillaController` líneas 217, 229, 303, 317), por lo que el comportamiento es consistente por inspección, pero el escenario dice "ver, editar o eliminar". **Sigue abierto**.

**WARNING-3 — Filas de límite incoherentes preexistentes y `destroyLimite` sin revalidar la cadena**

- `destroyLimite` (líneas 793-816) solo valida `banca_id` para `master`. Una fila creada **antes** de `684f602` con `banca_id = A` y `grupo_id`/`taquilla_id` de la banca B sigue siendo borrable por el master de A: no es explotable para **crear** filas nuevas, pero el borrado puede alterar la resolución efectiva (herencia) del tenant B. Además esas filas preexistentes siguen visibles/aplicables hasta que se limpien. Recomendación: migrar/limpiar filas incoherentes (o agregar chequeo de cadena en `destroy`). No bloquea: el vector de escritura está cerrado y requiere datos creados por el bug previo.

### SUGGESTION

**SUGGESTION-1 — `TicketController::show` carga el ticket dos veces**

- Líneas 120-121: `$ticket->load([...])` está duplicado. Inocuo, pero conviene eliminarlo.

**SUGGESTION-2 — `autorizarScope` permite `agencia` sin rama de scoping**

- `TicketController::autorizarScope` acepta `agencia` aunque `index/show/ganadores` no tienen rama para ese rol y la ruta `/tickets` no la incluye. Hoy es inalcanzable, pero si una futura ruta habilita agencia, vería tickets globales. Quitar `agencia` de la allow-list o agregar su rama (su local).

**SUGGESTION-3 — Rama de lectura de límites de grupo con `whereNull('grupo_id')` + `whereHas('grupo')`**

- `JuegoController::limites` (líneas ~265-272): la segunda rama exige `grupo_id` nulo y a la vez `whereHas('grupo')`, combinación que no puede cumplirse; la intención parece ser incluir la fila de nivel banca de la banca del grupo. Es preexistente y no forma parte del delta; conviene revisarlo en #85.

**SUGGESTION-4 — Conteo reportado de `AlcanceJerarquicoUsuariosTest` (16) vs métodos en HEAD (20)**

- El archivo declara 20 métodos `test_` (verificado en HEAD `684f602`); la evidencia enfocada reporta 16. La evidencia de esta re-verificación no incluye ese archivo, así que la discrepancia no se pudo reconfirmar. La suite completa pre-remediación (1422) incluye el archivo completo. Reconfirmar el conteo enfocado en la próxima corrida.

**SUGGESTION-5 — `super_master` puede escribir cadenas incoherentes (por diseño global)**

- `authorizeEscrituraLimite` retorna antes de validar la cadena para `super_master` (líneas 858-860). No hay escalada (super_master es global), pero puede dejar filas con `banca_id` y `grupo_id`/`taquilla_id` incoherentes que afecten la resolución efectiva. Aplicar la misma coherencia también a `super_master` es barato y defensivo.
- Relacionado (preexistente, no delta): en `batchLimites` legacy, `limites.*.banca_id` es `nullable`; un `super_master` que omita `banca_id` llega a `resolverCadenaObjetivo()` con `banca_id = 0` y la FK de `juego_limites.banca_id` produciría un 500 en vez de 422.

## Riesgos residuales

- **Datos pre-remediación**: si CRITICAL-1 fue explotado antes del fix, pueden existir filas de `juego_limites` con cadena incoherente (ver WARNING-3). Requiere revisión/limpieza de datos; no es detectable solo con tests.
- **Evidencia**: la suite completa es pre-remediación (`f68e7b4`); la evidencia post-fix es focalizada (13 + 54 tests) y `pint --test`. El cambio está acotado a `JuegoController` y un test, por lo que el riesgo de regresión fuera de límites es bajo, pero no está probado por una corrida completa post-fix.
- **Cobertura**: dos escenarios tienen aserción parcial de ramas (alta de usuario por taquilla; agencia update/toggle/delete de taquilla ajena), sin comportamiento faltante identificado.
- **`created_by`**: nullable; los usuarios preexistentes quedan `null`; el alcance por subárbol sigue funcionando, pero la política "lo que creó" no aplica a datos históricos.
- **Migración**: `down()` reversible por inspección, no ejecutado en esta fase (prohibición de migraciones); el deploy es aditivo.
- **Fuera de alcance por diseño**: #85 (ScopeService/refactor de policies), BE-04 (catálogo de permisos/`Gate::before`/taquilla reports/release download), #82, #89 y reportes de taquilla.

## Follow-ups recomendados

1. Agregar los tests de WARNING-1 y WARNING-2 para completar la profundidad de aserción de esos dos escenarios (no bloquea el archivo).
2. Limpiar/auditar filas de `juego_limites` con cadena incoherente y evaluar el chequeo de coherencia en `destroyLimite` (WARNING-3).
3. Aplicar coherencia de cadena a `super_master` y devolver 422 (no 500) cuando el batch legacy omita `banca_id` (SUGGESTION-5).
4. Limpiar SUGGESTION-1/2 y evaluar SUGGESTION-3 en el refactor de #85.
5. En la próxima corrida, ejecutar la suite completa post-`684f602` y conservar el log crudo para que `test_output_hash` sea stdout real; reconfirmar el conteo enfocado de `AlcanceJerarquicoUsuariosTest` (SUGGESTION-4).

## Veredicto

**PASS WITH WARNINGS** — CRITICAL-1 cerrado con implementación verificada por inspección y 5 tests runtime nuevos; sin hallazgos CRITICAL restantes ni variantes explotables de la misma clase. Quedan 3 WARNING no bloqueantes (2 de cobertura y 1 de datos/destroy residual) y 5 SUGGESTION. El change puede avanzar a `archive`, dejando los follow-ups registrados.
