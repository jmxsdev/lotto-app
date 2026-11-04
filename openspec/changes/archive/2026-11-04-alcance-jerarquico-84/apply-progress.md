# Apply Progress — alcance-jerarquico-84 (issue #84)

> Consolidado desde la memoria Engram del ciclo (`sdd/alcance-jerarquico-84/apply-progress`, obs #766).
> Regla del ciclo: el agente **no** ejecuta tests, migraciones ni artisan; el maintainer corre los comandos localmente.
> Rutas de código relativas a `backend/`.

## WU-1a — `users.created_by` (COMPLETADO, tests NO ejecutados)

**Rama**: `feat/84a-usuarios-created-by` · **Commit de código**: `c51ad90`

- `database/migrations/2026_11_04_000001_add_created_by_to_users_table.php` (nuevo): FK nullable autorreferente `after('id')`, índice explícito antes de la FK (evita el índice implícito duplicado de MySQL), `nullOnDelete`; `down()` revierte foreign + índice + columna. Sin backfill.
- `app/Models/User.php`: `created_by` en `$fillable`; `creator()` (`belongsTo(User, 'created_by')`) y `createdUsers()` (`hasMany(User, 'created_by')`).
- `app/Http/Controllers/Api/UserController.php` `store()`: `'created_by' => $user->id` asignado server-side.
- `app/Http/Controllers/Api/BancaController.php` `store()`: `'created_by' => $authUser->id`.
- `app/Http/Controllers/Api/GrupoController.php` `store()`: `'created_by' => $user->id`.
- `app/Http/Controllers/Api/TaquillaController.php` `store()`: `'created_by' => $user->id`.
- `database/seeders/UsersSeeder.php`: cadena master←super, bancaUser←master, grupoUser←bancaUser, taquillaUser/demoUser/agenciaUser←grupoUser; super queda `null`.
- `database/seeders/E2eSeeder.php`: `e2e@lotto.com` con `created_by` del usuario rol grupo del mismo grupo (fallback super_master).
- `tests/Feature/CreatedByTest.php` (nuevo, 7 tests): store registra `created_by`; payload `created_by` ignorado; stores banca/grupo/taquilla; `creator()`/`createdUsers()`; preexistentes `null`.

**Evidencia**: `php -l` OK en los 9 archivos. Tests y migraciones no ejecutados por el agente.
**Rollback**: `php artisan migrate:rollback --step=1` revierte la columna; el resto revierte con el merge.

---

## WU-1b — `UserPolicy` + autorización jerárquica en `UserController` (COMPLETADO, tests NO ejecutados)

**Rama**: `feat/84b-user-policy-controller` · **Commits de código**: `8839bc6` (WU-1b-1), `f12151a` (WU-1b-2)

- `app/Policies/UserPolicy.php` (nuevo): contrato `view/update/delete/canCreate/canAssign`; ranks super_master 6, master 5, banca 4, grupo 3, agencia 2, taquilla 1. Helpers privados `roleRank`, `isSuperMaster`, `canManageTarget`, `isWithinSubtree`, `bindingWithinScope`, `bindingValue`, `bancaIdOf`, `grupoIdOf`, `agenciaIdOf`.
- `app/Http/Controllers/Api/UserController.php`: `index` suma `orWhere('created_by', $user->id)` en master/banca/grupo/agencia (taquilla sigue solo self); `show/update/destroy` usan `$this->authorize('view'|'update'|'delete', $user)`; `authorizeUserAccess` eliminado; `authorizeEntityBinding` delega en `canCreate/canAssign`; helpers `resolveBancaId/resolveGrupoId/resolveAgenciaId` preservados; `update` solo invoca `canAssign` si el rol cambia realmente o si hay vínculos nuevos.
- `app/Providers/AppServiceProvider.php`: `Gate::policy(User::class, UserPolicy::class)` explícito.
- `tests/Feature/AlcanceJerarquicoUsuariosTest.php` (nuevo): master edita/borra super 403 + password intacto; super no borra otro super ni a sí mismo; master no borra a su creador ni edita a otro master; banca/grupo/agencia no crean pares; banca/grupo/agencia borran rango menor (200); banca no borra par ni usuario de otra banca; no mueve fuera del subárbol; no autopromoción; taquilla solo GET propio (PUT/DELETE 403); `created_by` del payload ignorado; index incluye creados y excluye ajenos.
- `tests/Feature/GestionUsuariosTest.php`: `test_banca_no_puede_eliminar_usuarios` → `test_banca_puede_eliminar_usuarios_de_rango_menor_de_su_banca` (200); nuevo `test_banca_no_puede_eliminar_a_un_par_de_otra_banca` (403).
- `tests/Feature/RoleAuthorizationTest.php`: borrado 200 para banca/grupo/agencia en su subárbol y 403 master contra otro master.
- `tests/Feature/MastersPanelTest.php`: master ve sus creados, nunca super ni masters pares.

**Matriz implementada**
- `super_master`: view/update/delete global; `delete` false para cualquier super_master (incluido otro y self); `update` no reescribe el rol de un super_master; `canAssign` no permite rol super_master.
- `master`: subárbol (`master_id` vía `banca_id`/cadena) + creados, solo rango menor; prohibido super, masters pares y su propio creador.
- `banca`/`grupo`/`agencia`: subárbol de su entidad + creados, solo rango menor; pares y superiores prohibidos; su creador prohibido.
- `taquilla`: `view` solo self; `update`/`delete`/`create` siempre false.

**Evidencia**: `php -l` OK en los 7 archivos. Sin ejecución de tests por el agente.
**Rollback**: revertir merge de la rama; cambios aislados a `UserPolicy` + `UserController` + `AppServiceProvider` + 4 archivos de tests.

---

## WU-2 — `ApuestaPolicy` + `PagoController` + `TicketController` (casos 2/3/4) (COMPLETADO, tests NO ejecutados)

**Rama**: `feat/84c-apuestas-pagos-tickets` · **Commit de código**: `4d96f72`

- `app/Policies/ApuestaPolicy.php`: `view()` delega en `poseeApuesta()`. Orden de ownership: super_master global → cadena visible (`apuesta->taquilla`; sin taquilla → false) → taquilla/agencia/grupo por su rama → `banca_id` de `taquilla.grupo` (sin grupo/banca → false) → banca compara `banca_id` → master usa `masterCanAccessBanca()` (sin bancas → false). `delete()`/`motivoNoAnulable()` consumen la misma regla sin cambiar precedencia ni códigos.
- `app/Http/Controllers/Api/PagoController.php` `store()`: el check `hasRole('taquilla')` se reemplaza por `$this->authorize('view', $apuesta)` **dentro del lock y antes** del check de estado pagable; cubre taquilla/grupo/banca/master/super. Intactos: 422 de tipos no-egreso, 404, 422 de estado/resultado/premio, tolerancia ±0.01, doble egreso y persistencia.
- `app/Http/Controllers/Api/TicketController.php`: `index()` rama master con `masterBancaChainScope()` (sin bancas → `whereRaw('1=0')`); `show()` master con ticket sin cadena o de banca no administrada → 403; `ganadores()` master acotado en la query de sorteo simple y en la multi-sorteo; `destroy()` sin cambios (caso 5 cubierto por `TicketAnulacionService`); docblock de `autorizarScope()` actualizado.
- `tests/Feature/ApuestaScopePolicyTest.php` (nuevo): master ve/anula apuesta de su banca; banca ajena 403 sin `Pago` ni soft-delete; master sin bancas 403; super global 200; apuesta sin cadena de taquilla deniega.
- `tests/Feature/PagoEgresoScopeTest.php` (nuevo): banca/grupo/master pagando apuesta ajena → 403 sin mutación; master sin bancas → 403; banca propia → 201 con premio del motor (300); tipo no-egreso → 422 aun cross-tenant; doble egreso → 201 + 422 y una sola fila.
- `tests/Feature/TicketMasterScopeTest.php` (nuevo): master solo ve tickets de sus bancas; sin bancas → data vacío; show propio 200 / ajeno 403; super ve ambos; ganadores acotados.
- `tests/Unit/ApuestaPolicyAnulacionTest.php`: `test_ownership_por_rol_dentro_del_arbol` actualizado (master debe administrar la banca) + nuevo test master sin bancas / banca ajena → `MOTIVO_SIN_OWNERSHIP`.
- `tests/Feature/{SuperBancaScopeTest,PagoDevolucionRechazosTest,PagoDevolucionAnulacionTest,EliminacionApuestasTest,TicketGanadoresTest,TicketEliminacionTest}.php`: sin cambios; sus expectativas no dependían del alcance master.

**Evidencia**: `php -l` OK en los 7 archivos. Sin ejecución de tests por el agente.
**Rollback**: revertir merge; sin cambios de esquema.

---

## WU-3 — Límites + ficha propia de agencia + regresión caso 5 (COMPLETADO, tests NO ejecutados)

**Rama**: `feat/84d-limites-agencia-regresion` · **Commit de código**: `5474e9d`

- `app/Http/Controllers/Api/JuegoController.php`:
  - `authorizeEscrituraLimite()`: rama banca valida banca propia + `grupo_id.banca_id == banca del actor` + `taquilla.grupo.banca_id == banca del actor` y, si llega `grupo_id`, `taquilla.grupo_id == grupo_id`; cualquier desvío → `abort(403)`.
  - `updateLimites()`: `authorizeEscrituraLimite` movido **antes** de `validarRestrictividadLimite`; el `validate` de formato inicial queda antes de ambos. Fuera de alcance → 403; en alcance con restrictividad inválida → 422.
  - `batchLimites()`: en modo legacy la autorización por ítem se movió antes de `validarItems` y de la restrictividad; se eliminó la llamada duplicada dentro de la transacción. Modo scope sigue autorizando la raíz en `expandirAlcance`/`assertObjetivosDentroDelGrupo`.
- `app/Http/Controllers/Api/AgenciaController.php` `update()`: si el actor es agencia → `authorizeAgenciaAccess` (ya exige agencia propia), sin `authorizeGestion`; `code`/`grupo_id`/`active` presentes en el payload → 403 (`hasAny` incluye `null`); persiste solo `name/rif/email/telefono/direccion/estado/municipio`; roles no-agencia conservan el flujo completo.
- `tests/Feature/LimitesEscrituraScopeTest.php` (nuevo): banca escribe en su grupo y taquilla (200/201 + persistencia); nivel de su banca; grupo de otra banca → 403; banca ajena → 403; taquilla de otro grupo y de otra banca → 403; batch legacy con ítem ajeno (con porcentaje sobre tope para probar 403 antes de 422) → 403 sin persistir ni el ítem propio; batch con grupo ajeno → 403; fuera de alcance con restrictividad inválida → 403; en alcance con restrictividad inválida → 422.
- `tests/Feature/AgenciaSelfUpdateTest.php` (nuevo): ficha propia 200 y persiste; `code`/`grupo_id`/`active` → 403; agencia ajena → 403; `store`/`toggle`/`destroy` → 403 con el local vivo; index solo su local, show propio 200 / ajeno 403.
- `tests/Feature/TicketAnulacionRegresionTest.php` (nuevo): ticket con líneas soft-deleted → DELETE 422 ("El ticket no tiene jugadas activas para anular."), 0 `Pago` devolución, 0 `Log`, ticket sin soft-delete y totales intactos.
- `tests/Feature/LimitesScopedApiTest.php` y `tests/Feature/AgenciaScopeTest.php`: sin cambios; expectativas vigentes.

**Evidencia**: `php -l` OK en los 5 archivos. Sin ejecución de tests por el agente.
**Rollback**: revertir merge; sin cambios de esquema.

---

## REMEDIACIÓN CRITICAL-1 — `master` con cadena banca/grupo/taquilla coherente (COMPLETADO, tests NO ejecutados)

**Commit**: `684f602` · **Origen**: `verify-report` CRITICAL-1

**Defecto**: `authorizeEscrituraLimite()` rama `master` solo validaba `banca_id` vía `authorizeBancaLimitAccess` y retornaba. Un master podía enviar el `banca_id` de una banca que administra junto con `grupo_id`/`taquilla_id` de **otra** banca; el límite se persistía incoherente y afectaba al tenant ajeno (PUT `/api/v1/limites/{juego}` y POST `/api/v1/limites/batch` modo legacy).

**Fix** (`backend/app/Http/Controllers/Api/JuegoController.php`):
- Nuevo helper privado `assertLimiteChainWithinBanca(int $bancaId, ?int $grupoId, ?int $taquillaId): void`.
  - `grupo_id` (si viene) debe existir con `banca_id == $bancaId`; si no → `abort(403, 'No tienes acceso a este grupo.')`.
  - `taquilla_id` (si viene) debe colgar de un grupo con `banca_id == $bancaId` y, si se indicó `grupo_id`, pertenecer exactamente a ese grupo; si no → `abort(403, 'No tienes acceso a esta taquilla.')`.
- Rama `master`: `authorizeBancaLimitAccess($user, $bancaId)` + `assertLimiteChainWithinBanca(...)`.
- Rama `banca`: conserva el check de banca propia y usa el mismo helper (sin duplicar lógica); mensajes y semántica previos intactos.
- Rama `super_master`: sigue global (return temprano, no pasa por el helper). Rama `grupo`: sin cambios. Orden autorización → restrictividad intacto.

**Tests agregados** (`backend/tests/Feature/LimitesEscrituraScopeTest.php`, no ejecutados por el agente):
- Helper `masterUser()`: `master@lotto.com` + `assignRole('master')` + `Banca BT001.master_id = master`.
- `test_master_escribe_en_su_propio_grupo_y_taquilla` (200/201 + persistencia).
- `test_master_con_grupo_de_otra_banca_403_sin_persistir`.
- `test_master_con_taquilla_de_otra_banca_403_sin_persistir`.
- `test_batch_master_rechaza_grupo_de_otra_banca_sin_persistir` (batch legacy: 403 sin persistir ni el ítem propio).
- `test_master_fuera_de_alcance_con_restrictividad_invalida_403_no_422`.

**Evidencia**: `php -l` OK en ambos archivos. Diff acotado a 2 archivos (`JuegoController.php`, `LimitesEscrituraScopeTest.php`); sin cambios de esquema ni de rutas.
**Rollback**: revertir esos 2 archivos.

---

## Cierre de tareas

`f68e7b4` — `docs(sdd): cierra tareas de alcance-jerarquico-84` (46/46 tareas marcadas).
